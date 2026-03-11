<?php
require_once(__DIR__ . '/../../config.php');

set_time_limit(0);
ini_set('memory_limit', '-1');

require_login();

// ── Controle de acesso ────────────────────────────────────────────────────────
$context           = context_system::instance();
$is_admin          = is_siteadmin();
$is_moodle_manager = has_capability('local/relatorio_treinamentos:view', $context);

require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');
$is_gestor = local_relatorio_treinamentos_is_gestor($USER);

if (!$is_admin && !$is_moodle_manager && !$is_gestor) {
    http_response_code(403);
    die('Acesso negado.');
}

// ── Parâmetros ────────────────────────────────────────────────────────────────
$formato         = required_param('formato', PARAM_ALPHA);
$col_keys_raw    = optional_param('col_keys', '', PARAM_RAW);
$filters_raw     = optional_param('filters',  '', PARAM_RAW);
$zip_group_field = optional_param('zip_group_field', 'prof_nome_filial', PARAM_ALPHANUMEXT);

$selected_cols  = $col_keys_raw ? json_decode($col_keys_raw, true) : null;
$active_filters = $filters_raw  ? json_decode($filters_raw, true)  : [];
if (!is_array($selected_cols))  $selected_cols  = null;
if (!is_array($active_filters)) $active_filters = [];

require_once(__DIR__ . '/locallib.php');
$cursos_filtro_implicito = local_relatorio_treinamentos_get_nomes_cursos_filtro();
$aplicar_filtro_cursos   = !empty($cursos_filtro_implicito) && !isset($active_filters['nome_curso']);

$estrategia     = get_config('local_relatorio_treinamentos', 'estrategia') ?: 'direct';
$all_columns    = \local_relatorio_treinamentos\helper\columns::get_all();
$col_keys_valid = array_keys($all_columns);

// ── Colunas a exportar ────────────────────────────────────────────────────────
if ($selected_cols) {
    $export_cols = [];
    foreach ($selected_cols as $k) {
        if (isset($all_columns[$k])) { $export_cols[$k] = $all_columns[$k]; }
    }
} else {
    $export_cols = $all_columns;
}

require_once(__DIR__ . '/download_helpers.php');

// ── WHERE clause para view materializada ──────────────────────────────────────
$view_where_sql = '';
$view_params    = [];
if ($estrategia === 'view') {
    require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');
    $view = local_relatorio_treinamentos_get_view_name();

    $where_parts = [];
    $pcount      = 0;

    if (!$is_admin && !$is_moodle_manager && $is_gestor) {
        $where_parts[]          = "gestor = :wgestor";
        $view_params['wgestor'] = fullname($USER);
    }
    foreach ($active_filters as $field => $value) {
        $field = clean_param($field, PARAM_ALPHANUMEXT);
        local_relatorio_treinamentos_build_filter_condition(
            $field, $value, $col_keys_valid, $where_parts, $view_params, $pcount
        );
    }
    if ($aplicar_filtro_cursos) {
        [$in_sql, $in_params] = $DB->get_in_or_equal($cursos_filtro_implicito, SQL_PARAMS_NAMED, 'dcf');
        $where_parts[]  = "nome_curso $in_sql";
        $view_params    = array_merge($view_params, $in_params);
    }
    $view_where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';
}

// ── Dados para estratégias cache / direct ─────────────────────────────────────
$dados = [];
if ($estrategia !== 'view') {
    if ($estrategia === 'cache') {
        $cache = \cache::make('local_relatorio_treinamentos', 'relatorio');
        $dados = $cache->get('dados');
        if ($dados === false) {
            $task = new \local_relatorio_treinamentos\task\atualizar_relatorio();
            $task->execute();
            $dados = $cache->get('dados');
        }
    } else {
        $dados = \local_relatorio_treinamentos\task\atualizar_relatorio::buscar_dados($DB);
    }
    $dados = array_values((array)$dados);

    if (!$is_admin && !$is_moodle_manager && $is_gestor) {
        $gestor_nome = fullname($USER);
        $dados = array_values(array_filter($dados, function($row) use ($gestor_nome) {
            return ($row->gestor ?? '') === $gestor_nome;
        }));
    }
    if (!empty($active_filters)) {
        $dados = array_values(array_filter($dados, function($row) use ($active_filters) {
            return local_relatorio_treinamentos_row_matches_filters($row, $active_filters);
        }));
    }
    if ($aplicar_filtro_cursos) {
        $cursos_set = array_flip($cursos_filtro_implicito);
        $dados = array_values(array_filter($dados, function($row) use ($cursos_set) {
            return isset($cursos_set[trim((string)($row->nome_curso ?? ''))]);
        }));
    }
}

$col_keys_exp  = array_keys($export_cols);
$concluido_idx = array_search('concluido', $col_keys_exp);
$filename_base = 'relatorio_treinamentos_' . date('Ymd');

// ── CSV ───────────────────────────────────────────────────────────────────────
if ($formato === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename_base . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, array_values($export_cols), ';');

    if ($estrategia === 'view') {
        $rs = $DB->get_recordset_sql(
            "SELECT * FROM $view $view_where_sql ORDER BY prof_nome_filial, bas_nome_funcionario, nome_curso",
            $view_params
        );
        foreach ($rs as $row) {
            fputcsv($out, rt_get_row_values($row, $export_cols), ';');
        }
        $rs->close();
    } else {
        foreach ($dados as $row) {
            fputcsv($out, rt_get_row_values($row, $export_cols), ';');
        }
    }
    fclose($out);
    exit;
}

// ── XLSX ──────────────────────────────────────────────────────────────────────
if ($formato === 'xlsx') {
    $tmp_xlsx = sys_get_temp_dir() . '/rt_dl_' . uniqid() . '.xlsx';

    if ($estrategia === 'view') {
        $rs = $DB->get_recordset_sql(
            "SELECT * FROM $view $view_where_sql ORDER BY prof_nome_filial, bas_nome_funcionario, nome_curso",
            $view_params
        );
        rt_xlsx_stream($export_cols, $rs, $tmp_xlsx, 'Relatório', $concluido_idx);
        $rs->close();
    } else {
        rt_xlsx_stream($export_cols, $dados, $tmp_xlsx, 'Relatório', $concluido_idx);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename_base . '.xlsx"');
    header('Content-Length: ' . filesize($tmp_xlsx));
    readfile($tmp_xlsx);
    unlink($tmp_xlsx);
    exit;
}

// ── ZIP (arquivos XLSX por grupo, via Python/pandas) ─────────────────────────
if ($formato === 'zip') {
    require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');

    if (!local_relatorio_treinamentos_get_python_path()) {
        http_response_code(503);
        die('Python não configurado no servidor. Defina "pathtopython" nas configurações do Moodle.');
    }
    $all_zip_fields   = \local_relatorio_treinamentos\helper\columns::get_zip_group_fields();
    $zip_saved        = get_config('local_relatorio_treinamentos', 'agrupamentos_zip');
    $valid_zip_fields = ($zip_saved !== false && $zip_saved !== '')
        ? array_intersect_key($all_zip_fields, array_flip(explode(',', $zip_saved)))
        : $all_zip_fields;
    if (!array_key_exists($zip_group_field, $valid_zip_fields)) {
        die('Campo de agrupamento inválido.');
    }

    $tempdir = sys_get_temp_dir() . '/rt_zip_' . uniqid();
    mkdir($tempdir, 0700, true);

    $bom         = chr(0xEF) . chr(0xBB) . chr(0xBF);
    $header_line = array_values($export_cols);

    $write_group_csv = function(string $csv_path, iterable $rows_source) use ($export_cols, $bom, $header_line): void {
        $fh = fopen($csv_path, 'w');
        fwrite($fh, $bom);
        fputcsv($fh, $header_line, ';');
        foreach ($rows_source as $row) {
            $vals = [];
            foreach (array_keys($export_cols) as $key) {
                $vals[] = (string)($row->$key ?? '');
            }
            fputcsv($fh, $vals, ';');
        }
        fclose($fh);
    };

    if ($estrategia === 'view') {
        $group_rows = $DB->get_records_sql(
            "SELECT DISTINCT COALESCE(NULLIF({$zip_group_field}, ''), 'sem_valor') AS val
               FROM {$view} {$view_where_sql}
           ORDER BY 1",
            $view_params
        );

        foreach ($group_rows as $gr) {
            $gval      = (string)$gr->val;
            $safe_name = rt_safe_filename($gval);
            $csv_path  = $tempdir . '/' . $safe_name . '.csv';

            if ($gval === 'sem_valor') {
                $g_add    = "({$zip_group_field} IS NULL OR {$zip_group_field} = '')";
                $g_params = $view_params;
            } else {
                $g_add    = "{$zip_group_field} = :zipgroupval";
                $g_params = array_merge($view_params, ['zipgroupval' => $gval]);
            }
            $g_where = $view_where_sql
                ? $view_where_sql . " AND {$g_add}"
                : "WHERE {$g_add}";

            $rs = $DB->get_recordset_sql(
                "SELECT * FROM {$view} {$g_where} ORDER BY bas_nome_funcionario, nome_curso",
                $g_params
            );
            $write_group_csv($csv_path, $rs);
            $rs->close();
        }

    } else {
        $grupos = [];
        foreach ($dados as $row) {
            $gval = (string)($row->$zip_group_field ?? '');
            if ($gval === '') $gval = 'sem_valor';
            $grupos[$gval][] = $row;
        }
        ksort($grupos);

        foreach ($grupos as $grupo_val => $linhas) {
            $safe_name = rt_safe_filename($grupo_val);
            $csv_path  = $tempdir . '/' . $safe_name . '.csv';
            $write_group_csv($csv_path, $linhas);
        }
    }

    // Converte todos os CSVs para XLSX em uma única chamada Python
    if (!local_relatorio_treinamentos_csv_dir_to_xlsx($tempdir)) {
        array_map('unlink', glob($tempdir . '/*'));
        rmdir($tempdir);
        http_response_code(500);
        die('Falha ao converter CSVs para XLSX.');
    }

    $zip_name = 'relatorio_treinamentos_' . $zip_group_field . '_' . date('Ymd') . '.zip';
    $zip_file = $tempdir . '/relatorio.zip';
    $zip = new ZipArchive();
    $zip->open($zip_file, ZipArchive::CREATE);
    foreach (glob($tempdir . '/*.xlsx') as $f) {
        $zip->addFile($f, basename($f));
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_name . '"');
    header('Content-Length: ' . filesize($zip_file));
    readfile($zip_file);

    array_map('unlink', glob($tempdir . '/*.xlsx'));
    unlink($zip_file);
    rmdir($tempdir);
    exit;
}

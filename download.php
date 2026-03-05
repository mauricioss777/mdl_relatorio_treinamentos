<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/excellib.class.php');

require_login();

// ── Controle de acesso ────────────────────────────────────────────────────────
$context           = context_system::instance();
$is_admin          = is_siteadmin();
$is_moodle_manager = has_capability('local/relatorio_treinamentos:view', $context);

$cargo = $DB->get_field('user_info_data', 'data', [
    'userid'  => $USER->id,
    'fieldid' => 18,
]);
$manager_codes = \local_relatorio_treinamentos\helper\columns::get_manager_cargo_codes();
$is_gestor     = in_array(trim((string)$cargo), $manager_codes);

if (!$is_admin && !$is_moodle_manager && !$is_gestor) {
    http_response_code(403);
    die('Acesso negado.');
}

// ── Parâmetros ────────────────────────────────────────────────────────────────
$formato         = required_param('formato', PARAM_ALPHA);
$col_keys_raw    = optional_param('col_keys', '', PARAM_RAW);
$filters_raw     = optional_param('filters',  '', PARAM_RAW);
$zip_group_field = optional_param('zip_group_field', 'prof_nome_filial', PARAM_ALPHANUMEXT);

$selected_cols = $col_keys_raw ? json_decode($col_keys_raw, true) : null;
$active_filters = $filters_raw ? json_decode($filters_raw, true) : [];
if (!is_array($selected_cols)) $selected_cols = null;
if (!is_array($active_filters)) $active_filters = [];

// ── Dados: view / cache / consulta direta ────────────────────────────────────
$estrategia  = get_config('local_relatorio_treinamentos', 'estrategia') ?: 'direct';
$all_columns = \local_relatorio_treinamentos\helper\columns::get_all();
$col_keys_valid = array_keys($all_columns);

if ($estrategia === 'view') {
    require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');
    $view = local_relatorio_treinamentos_get_view_name();

    $where_parts = [];
    $sql_params  = [];
    $pcount      = 0;

    if (!$is_admin && !$is_moodle_manager && $is_gestor) {
        $where_parts[] = "gestor = :wgestor";
        $sql_params['wgestor'] = fullname($USER);
    }
    foreach ($active_filters as $field => $value) {
        $value = trim((string)$value);
        $field = clean_param($field, PARAM_ALPHANUMEXT);
        if ($value === '' || !in_array($field, $col_keys_valid)) continue;
        $pname = 'wf' . $pcount++;
        $where_parts[] = "$field = :$pname";
        $sql_params[$pname] = $value;
    }
    $where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';
    $dados = array_values((array)$DB->get_records_sql(
        "SELECT * FROM $view $where_sql ORDER BY prof_nome_filial, bas_nome_funcionario, nome_curso",
        $sql_params
    ));
} else {
    if ($estrategia === 'cache') {
        $cache = \cache::make('local_relatorio_treinamentos', 'relatorio');
        $dados = $cache->get('dados');
        if ($dados === false) {
            $task = new \local_relatorio_treinamentos\task\atualizar_relatorio();
            $task->execute();
            $dados = $cache->get('dados');
        }
    } else {
        ini_set('memory_limit', '4G');
        $dados = \local_relatorio_treinamentos\task\atualizar_relatorio::buscar_dados($DB);
    }
    $dados = array_values((array)$dados);

    if (!$is_admin && !$is_moodle_manager && $is_gestor) {
        $gestor_nome = fullname($USER);
        $dados = array_filter($dados, function($row) use ($gestor_nome) {
            return ($row->gestor ?? '') === $gestor_nome;
        });
    }
    if (!empty($active_filters)) {
        $dados = array_filter($dados, function($row) use ($active_filters) {
            foreach ($active_filters as $field => $value) {
                if ($value === '' || $value === null) continue;
                if (($row->$field ?? '') !== $value) return false;
            }
            return true;
        });
    }
}

// ── Colunas a exportar ────────────────────────────────────────────────────────
if ($selected_cols) {
    $export_cols = [];
    foreach ($selected_cols as $k) {
        if (isset($all_columns[$k])) {
            $export_cols[$k] = $all_columns[$k];
        }
    }
} else {
    $export_cols = $all_columns;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function rt_get_row_values($row, $cols) {
    $vals = [];
    foreach (array_keys($cols) as $key) {
        $v = $row->$key ?? '';
        // Remove HTML-like values (progresso/concluido come as plain text from cache)
        $vals[] = (string)$v;
    }
    return $vals;
}

function rt_safe_filename($name) {
    $name = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $name);
    $name = trim(substr($name, 0, 60));
    return $name ?: 'grupo';
}

function rt_build_xlsx($workbook, $sheet_name, $export_cols, $rows, $fmt_header, $fmt_sim, $fmt_nao) {
    $sheet = $workbook->add_worksheet(mb_substr($sheet_name, 0, 31));
    $col_keys   = array_keys($export_cols);
    $col_labels = array_values($export_cols);
    foreach ($col_labels as $ci => $titulo) {
        $sheet->write_string(0, $ci, (string)$titulo, $fmt_header);
    }
    $concluido_idx = array_search('concluido', $col_keys);
    foreach ($rows as $ri => $linha) {
        foreach ($linha as $ci => $valor) {
            $fmt = ($ci === $concluido_idx)
                ? ($valor === 'Sim' ? $fmt_sim : $fmt_nao)
                : null;
            $sheet->write_string($ri + 1, $ci, (string)$valor, $fmt);
        }
    }
}

$cabecalho_labels = array_values($export_cols);

// ── CSV ───────────────────────────────────────────────────────────────────────
if ($formato === 'csv') {
    $filename = 'relatorio_treinamentos_' . date('Ymd');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
    fputcsv($out, $cabecalho_labels, ';');
    foreach ($dados as $row) {
        fputcsv($out, rt_get_row_values($row, $export_cols), ';');
    }
    fclose($out);
    exit;
}

// ── XLSX ──────────────────────────────────────────────────────────────────────
if ($formato === 'xlsx') {
    $filename = 'relatorio_treinamentos_' . date('Ymd');
    $workbook = new MoodleExcelWorkbook($filename);
    $workbook->send($filename . '.xlsx');
    $fmt_h   = $workbook->add_format(['bold' => 1, 'bg_color' => '#343a40', 'color' => '#ffffff']);
    $fmt_sim = $workbook->add_format(['bg_color' => '#d4edda']);
    $fmt_nao = $workbook->add_format(['bg_color' => '#e2e3e5']);
    $rows = [];
    foreach ($dados as $row) {
        $rows[] = rt_get_row_values($row, $export_cols);
    }
    rt_build_xlsx($workbook, 'Relatório', $export_cols, $rows, $fmt_h, $fmt_sim, $fmt_nao);
    $workbook->close();
    exit;
}

// ── ZIP ───────────────────────────────────────────────────────────────────────
if ($formato === 'zip') {
    if (!in_array($zip_group_field, array_keys(\local_relatorio_treinamentos\helper\columns::get_zip_group_fields()))) {
        die('Campo de agrupamento inválido.');
    }
    if (!class_exists('ZipArchive')) {
        die('ZipArchive não disponível no servidor.');
    }

    // Agrupar dados
    $grupos = [];
    foreach ($dados as $row) {
        $gval = (string)($row->$zip_group_field ?? '');
        if ($gval === '') $gval = 'sem_valor';
        $grupos[$gval][] = rt_get_row_values($row, $export_cols);
    }
    ksort($grupos);

    $tempdir = sys_get_temp_dir() . '/rt_zip_' . uniqid();
    mkdir($tempdir);

    foreach ($grupos as $grupo_val => $linhas) {
        $safe_name  = rt_safe_filename($grupo_val);
        $temp_xlsx  = $tempdir . '/' . $safe_name . '.xlsx';
        $workbook   = new MoodleExcelWorkbook($temp_xlsx);
        $fmt_h   = $workbook->add_format(['bold' => 1, 'bg_color' => '#343a40', 'color' => '#ffffff']);
        $fmt_sim = $workbook->add_format(['bg_color' => '#d4edda']);
        $fmt_nao = $workbook->add_format(['bg_color' => '#e2e3e5']);
        rt_build_xlsx($workbook, mb_substr($grupo_val, 0, 31), $export_cols, $linhas, $fmt_h, $fmt_sim, $fmt_nao);
        $workbook->close();
    }

    // Empacotar em ZIP
    $zip_file = $tempdir . '/relatorio_treinamentos.zip';
    $zip = new ZipArchive();
    $zip->open($zip_file, ZipArchive::CREATE);
    foreach (glob($tempdir . '/*.xlsx') as $f) {
        $zip->addFile($f, basename($f));
    }
    $zip->close();

    $zip_name = 'relatorio_treinamentos_' . $zip_group_field . '_' . date('Ymd') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_name . '"');
    header('Content-Length: ' . filesize($zip_file));
    readfile($zip_file);

    // Limpeza
    array_map('unlink', glob($tempdir . '/*.xlsx'));
    unlink($zip_file);
    rmdir($tempdir);
    exit;
}

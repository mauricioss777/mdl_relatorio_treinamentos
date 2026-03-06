<?php
/**
 * SSE endpoint — gera XLSX ou ZIP com progresso em tempo real.
 *
 * Emite eventos SSE:
 *   data: {"step":N,"total":M,"label":"..."}   — progresso (ZIP)
 *   data: {"done":true,"token":"...","filename":"..."}  — concluído
 *   data: {"error":"mensagem"}                 — erro
 */
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/download_helpers.php');
require_once(__DIR__ . '/locallib.php');

set_time_limit(0);
ini_set('memory_limit', '-1');

require_login();

// ── Acesso ────────────────────────────────────────────────────────────────────
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

$selected_cols  = $col_keys_raw ? json_decode($col_keys_raw, true) : null;
$active_filters = $filters_raw  ? json_decode($filters_raw, true)  : [];
if (!is_array($selected_cols))  $selected_cols  = null;
if (!is_array($active_filters)) $active_filters = [];

require_once(__DIR__ . '/locallib.php');
$cursos_filtro_implicito = local_relatorio_treinamentos_get_nomes_cursos_filtro();
$aplicar_filtro_cursos   = !empty($cursos_filtro_implicito) && !isset($active_filters['nome_curso']);

$estrategia  = get_config('local_relatorio_treinamentos', 'estrategia') ?: 'direct';
$all_columns = \local_relatorio_treinamentos\helper\columns::get_all();

// ── Colunas a exportar ────────────────────────────────────────────────────────
if ($selected_cols) {
    $export_cols = [];
    foreach ($selected_cols as $k) {
        if (isset($all_columns[$k])) { $export_cols[$k] = $all_columns[$k]; }
    }
} else {
    $export_cols = $all_columns;
}

// ── SSE: inicia stream ────────────────────────────────────────────────────────
@ob_end_clean();
header('Content-Type: text/event-stream; charset=UTF-8');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

$sse_flush = function(array $data): void {
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
};

// ── WHERE clause (estratégia view) ───────────────────────────────────────────
$col_keys_valid = array_keys($all_columns);
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
        $value = trim((string)$value);
        $field = clean_param($field, PARAM_ALPHANUMEXT);
        if ($value === '' || !in_array($field, $col_keys_valid)) continue;
        $pname = 'wf' . $pcount++;
        $where_parts[]       = "$field = :$pname";
        $view_params[$pname] = $value;
    }
    if ($aplicar_filtro_cursos) {
        [$in_sql, $in_params] = $DB->get_in_or_equal($cursos_filtro_implicito, SQL_PARAMS_NAMED, 'gcf');
        $where_parts[]  = "nome_curso $in_sql";
        $view_params    = array_merge($view_params, $in_params);
    }
    $view_where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';
}

// ── Dados (cache/direct) ─────────────────────────────────────────────────────
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
            foreach ($active_filters as $field => $value) {
                if ($value === '' || $value === null) continue;
                if (($row->$field ?? '') !== $value) return false;
            }
            return true;
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
$token         = bin2hex(random_bytes(16));
$token_path    = sys_get_temp_dir() . '/rt_tok_' . $token . '.json';

// ═════════════════════════════════════════════════════════════════════════════
// XLSX (via Python/pandas)
// ═════════════════════════════════════════════════════════════════════════════
if ($formato === 'xlsx') {
    if (!local_relatorio_treinamentos_get_python_path()) {
        $sse_flush(['error' => 'Python não configurado no servidor. Defina "pathtopython" nas configurações do Moodle.']);
        exit;
    }

    $sse_flush(['step' => 0, 'total' => 2, 'label' => 'Consultando dados...']);

    $tmp_csv  = sys_get_temp_dir() . '/rt_gen_' . $token . '.csv';
    $out_file = sys_get_temp_dir() . '/rt_gen_' . $token . '.xlsx';
    $bom      = chr(0xEF) . chr(0xBB) . chr(0xBF);

    // Gera CSV temporário
    $fh = fopen($tmp_csv, 'w');
    fwrite($fh, $bom);
    fputcsv($fh, array_values($export_cols), ';');

    if ($estrategia === 'view') {
        $rs = $DB->get_recordset_sql(
            "SELECT * FROM $view $view_where_sql ORDER BY prof_nome_filial, bas_nome_funcionario, nome_curso",
            $view_params
        );
        foreach ($rs as $row) {
            fputcsv($fh, rt_get_row_values($row, $export_cols), ';');
        }
        $rs->close();
    } else {
        foreach ($dados as $row) {
            fputcsv($fh, rt_get_row_values($row, $export_cols), ';');
        }
    }
    fclose($fh);

    $sse_flush(['step' => 1, 'total' => 2, 'label' => 'Convertendo para XLSX...']);

    if (!local_relatorio_treinamentos_csv_to_xlsx($tmp_csv, $out_file)) {
        $sse_flush(['error' => 'Falha ao converter CSV para XLSX. Verifique o Python e as dependências.']);
        exit;
    }

    $filename = $filename_base . '.xlsx';
    file_put_contents($token_path, json_encode([
        'userid'   => $USER->id,
        'file'     => $out_file,
        'filename' => $filename,
        'expires'  => time() + 300,
    ]));

    $sse_flush(['done' => true, 'token' => $token, 'filename' => $filename]);
    exit;
}

// ═════════════════════════════════════════════════════════════════════════════
// ZIP (arquivos XLSX por grupo, via Python/pandas)
// ═════════════════════════════════════════════════════════════════════════════
if ($formato === 'zip') {
    if (!local_relatorio_treinamentos_get_python_path()) {
        $sse_flush(['error' => 'Python não configurado no servidor. Defina "pathtopython" nas configurações do Moodle.']);
        exit;
    }

    $all_zip_fields   = \local_relatorio_treinamentos\helper\columns::get_zip_group_fields();
    $zip_saved        = get_config('local_relatorio_treinamentos', 'agrupamentos_zip');
    $valid_zip_fields = ($zip_saved !== false && $zip_saved !== '')
        ? array_intersect_key($all_zip_fields, array_flip(explode(',', $zip_saved)))
        : $all_zip_fields;

    if (!array_key_exists($zip_group_field, $valid_zip_fields)) {
        $sse_flush(['error' => 'Campo de agrupamento inválido.']);
        exit;
    }

    $tempdir = sys_get_temp_dir() . '/rt_zip_' . $token;
    mkdir($tempdir, 0700, true);

    $bom         = chr(0xEF) . chr(0xBB) . chr(0xBF);
    $header_line = array_values($export_cols);

    /** Grava CSV de grupo em disco (sem conversão — lote será convertido de uma vez). */
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
        $total = count($group_rows);
        $step  = 0;

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

            $step++;
            $sse_flush(['step' => $step, 'total' => $total * 2, 'label' => $gval, 'phase' => 'csv']);
        }
    } else {
        $grupos = [];
        foreach ($dados as $row) {
            $gval = (string)($row->$zip_group_field ?? '');
            if ($gval === '') $gval = 'sem_valor';
            $grupos[$gval][] = $row;
        }
        ksort($grupos);

        $total = count($grupos);
        $step  = 0;

        foreach ($grupos as $grupo_val => $linhas) {
            $safe_name = rt_safe_filename($grupo_val);
            $csv_path  = $tempdir . '/' . $safe_name . '.csv';
            $write_group_csv($csv_path, $linhas);

            $step++;
            $sse_flush(['step' => $step, 'total' => $total * 2, 'label' => $grupo_val, 'phase' => 'csv']);
        }
    }

    // Converte CSVs para XLSX via Python com progresso por arquivo
    $py_script = $CFG->dirroot . '/local/relatorio_treinamentos/cli/csv_to_xlsx.py';
    $py_cmd    = escapeshellarg(local_relatorio_treinamentos_get_python_path())
               . ' ' . escapeshellarg($py_script)
               . ' --dir ' . escapeshellarg($tempdir);
    $proc = proc_open($py_cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    if (!is_resource($proc)) {
        $sse_flush(['error' => 'Falha ao iniciar Python.']);
        array_map('unlink', glob($tempdir . '/*'));
        rmdir($tempdir);
        exit;
    }
    fclose($pipes[0]);
    $conv_step = 0;
    while (!feof($pipes[1])) {
        $line = trim(fgets($pipes[1]));
        if ($line !== '') {
            $conv_step++;
            $label = pathinfo($line, PATHINFO_FILENAME);
            $sse_flush(['step' => $total + $conv_step, 'total' => $total * 2, 'label' => $label, 'phase' => 'xlsx']);
        }
    }
    fclose($pipes[1]);
    if (proc_close($proc) !== 0) {
        $sse_flush(['error' => 'Falha ao converter CSVs para XLSX. Verifique o Python e as dependências.']);
        array_map('unlink', glob($tempdir . '/*'));
        rmdir($tempdir);
        exit;
    }

    // Empacota em ZIP
    $zip_name = 'relatorio_treinamentos_' . $zip_group_field . '_' . date('Ymd') . '.zip';
    $out_file = sys_get_temp_dir() . '/rt_gen_' . $token . '.zip';
    $zip = new ZipArchive();
    $zip->open($out_file, ZipArchive::CREATE);
    foreach (glob($tempdir . '/*.xlsx') as $f) {
        $zip->addFile($f, basename($f));
    }
    $zip->close();
    array_map('unlink', glob($tempdir . '/*.xlsx'));
    rmdir($tempdir);

    file_put_contents($token_path, json_encode([
        'userid'   => $USER->id,
        'file'     => $out_file,
        'filename' => $zip_name,
        'expires'  => time() + 300,
    ]));

    $sse_flush(['done' => true, 'token' => $token, 'filename' => $zip_name]);
    exit;
}

$sse_flush(['error' => 'Formato inválido.']);

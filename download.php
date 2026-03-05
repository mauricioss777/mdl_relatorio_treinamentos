<?php
require_once(__DIR__ . '/../../config.php');

set_time_limit(0);
ini_set('memory_limit', '-1');

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

$selected_cols  = $col_keys_raw ? json_decode($col_keys_raw, true) : null;
$active_filters = $filters_raw  ? json_decode($filters_raw, true)  : [];
if (!is_array($selected_cols))  $selected_cols  = null;
if (!is_array($active_filters)) $active_filters = [];

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

// ═════════════════════════════════════════════════════════════════════════════
// XLSX Streaming Writer — sem PhpSpreadsheet, sem limite de memória
// ═════════════════════════════════════════════════════════════════════════════

/** Converte índice de coluna (1-based) para letra(s) do Excel: 1→A, 27→AA */
function rt_col_letter(int $n): string {
    $s = '';
    while ($n > 0) {
        $n--;
        $s = chr(65 + ($n % 26)) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

/** Escapa valor para XML inline string, removendo caracteres de controle inválidos */
function rt_xlsx_escape(string $v): string {
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $v);
    return htmlspecialchars($v, ENT_XML1, 'UTF-8');
}

/** Retorna os arquivos estáticos do XLSX (exceto sheet1.xml) */
function rt_xlsx_static_files(string $sheet_name): array {
    $sn = htmlspecialchars(mb_substr($sheet_name, 0, 31), ENT_XML1, 'UTF-8');
    return [
        '[Content_Types].xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',

        '_rels/.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',

        'xl/workbook.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . "<sheets><sheet name=\"{$sn}\" sheetId=\"1\" r:id=\"rId1\"/></sheets>"
            . '</workbook>',

        'xl/_rels/workbook.xml.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',

        // Estilos: s=0 normal | s=1 cabeçalho | s=2 Sim (verde) | s=3 Não (cinza)
        'xl/styles.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>'
            . '</fonts>'
            . '<fills count="5">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF343A40"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFD4EDDA"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE2E3E5"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="3" borderId="0" xfId="0" applyFill="1"/>'
            . '<xf numFmtId="0" fontId="0" fillId="4" borderId="0" xfId="0" applyFill="1"/>'
            . '</cellXfs>'
            . '</styleSheet>',
    ];
}

/**
 * Gera um arquivo XLSX por streaming de XML puro — sem PhpSpreadsheet.
 * Suporta arrays e Moodle recordsets (iteráveis de stdClass).
 *
 * @param array      $export_cols    ['col_key' => 'Label', ...]
 * @param iterable   $rows_source    Array ou recordset de stdClass
 * @param string     $save_to        Caminho do arquivo de saída (deve existir após a chamada)
 * @param string     $sheet_name     Nome da aba (máx 31 chars)
 * @param int|false  $concluido_idx  Índice da coluna 'concluido' para estilo condicional
 */
function rt_xlsx_stream(array $export_cols, iterable $rows_source, string $save_to, string $sheet_name = 'Relatório', $concluido_idx = false): void {
    $col_keys   = array_keys($export_cols);
    $col_labels = array_values($export_cols);
    $col_count  = count($col_keys);

    // Pré-computa letras das colunas (A, B, ..., AE, ...)
    $letters = [];
    for ($i = 0; $i < $col_count; $i++) {
        $letters[] = rt_col_letter($i + 1);
    }

    $tmpdir     = sys_get_temp_dir() . '/rtxlsx_' . uniqid();
    mkdir($tmpdir, 0700, true);
    $sheet_file = $tmpdir . '/sheet1.xml';

    // ── Escreve sheet1.xml linha a linha ──────────────────────────────────────
    $fh = fopen($sheet_file, 'w');
    fwrite($fh,
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>'
    );

    // Linha de cabeçalho
    $row_xml = '<row r="1">';
    foreach ($col_labels as $ci => $titulo) {
        $v       = rt_xlsx_escape((string)$titulo);
        $row_xml .= "<c r=\"{$letters[$ci]}1\" t=\"inlineStr\" s=\"1\"><is><t>{$v}</t></is></c>";
    }
    fwrite($fh, $row_xml . '</row>');

    // Linhas de dados
    $ri = 2;
    foreach ($rows_source as $row) {
        $row_xml = "<row r=\"{$ri}\">";
        foreach ($col_keys as $ci => $key) {
            $valor  = (string)($row->$key ?? '');
            $v      = rt_xlsx_escape($valor);
            $letter = $letters[$ci];
            if ($concluido_idx !== false && $ci === $concluido_idx) {
                $s = $valor === 'Sim' ? ' s="2"' : ' s="3"';
            } else {
                $s = '';
            }
            $row_xml .= "<c r=\"{$letter}{$ri}\" t=\"inlineStr\"{$s}><is><t>{$v}</t></is></c>";
        }
        fwrite($fh, $row_xml . '</row>');
        $ri++;
    }

    fwrite($fh, '</sheetData></worksheet>');
    fclose($fh);

    // ── Monta o XLSX (ZIP com os arquivos XML) ────────────────────────────────
    $xlsx_tmp = $tmpdir . '/out.xlsx';
    $zip = new ZipArchive();
    $zip->open($xlsx_tmp, ZipArchive::CREATE);
    foreach (rt_xlsx_static_files($sheet_name) as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->addFile($sheet_file, 'xl/worksheets/sheet1.xml');
    $zip->close();

    rename($xlsx_tmp, $save_to);

    // Limpeza
    @unlink($sheet_file);
    @rmdir($tmpdir);
}

// ── Helper CSV ────────────────────────────────────────────────────────────────
function rt_get_row_values($row, $cols) {
    $vals = [];
    foreach (array_keys($cols) as $key) {
        $vals[] = (string)($row->$key ?? '');
    }
    return $vals;
}

function rt_safe_filename($name) {
    $name = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $name);
    $name = trim(substr($name, 0, 60));
    return $name ?: 'grupo';
}

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
        $value = trim((string)$value);
        $field = clean_param($field, PARAM_ALPHANUMEXT);
        if ($value === '' || !in_array($field, $col_keys_valid)) continue;
        $pname = 'wf' . $pcount++;
        $where_parts[]       = "$field = :$pname";
        $view_params[$pname] = $value;
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
            foreach ($active_filters as $field => $value) {
                if ($value === '' || $value === null) continue;
                if (($row->$field ?? '') !== $value) return false;
            }
            return true;
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

// ── ZIP (arquivos CSV internos) ───────────────────────────────────────────────
if ($formato === 'zip') {
    $all_zip_fields   = \local_relatorio_treinamentos\helper\columns::get_zip_group_fields();
    $zip_saved        = get_config('local_relatorio_treinamentos', 'agrupamentos_zip');
    $valid_zip_fields = ($zip_saved !== false && $zip_saved !== '')
        ? array_intersect_key($all_zip_fields, array_flip(explode(',', $zip_saved)))
        : $all_zip_fields;
    if (!array_key_exists($zip_group_field, $valid_zip_fields)) {
        die('Campo de agrupamento inválido.');
    }
    if (!class_exists('ZipArchive')) {
        die('ZipArchive não disponível no servidor.');
    }

    $tempdir = sys_get_temp_dir() . '/rt_zip_' . uniqid();
    mkdir($tempdir, 0700, true);

    $bom          = chr(0xEF) . chr(0xBB) . chr(0xBF);
    $header_line  = array_values($export_cols);

    /**
     * Escreve um arquivo CSV de grupo em $filepath.
     * $rows_source pode ser array ou recordset.
     */
    $write_group_csv = function(string $filepath, iterable $rows_source) use ($export_cols, $bom, $header_line): void {
        $fh = fopen($filepath, 'w');
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
        // Busca valores distintos do campo de agrupamento na view
        $group_rows = $DB->get_records_sql(
            "SELECT DISTINCT COALESCE(NULLIF({$zip_group_field}, ''), 'sem_valor') AS val
               FROM {$view} {$view_where_sql}
           ORDER BY 1",
            $view_params
        );

        foreach ($group_rows as $gr) {
            $gval      = (string)$gr->val;
            $safe_name = rt_safe_filename($gval);
            $temp_csv  = $tempdir . '/' . $safe_name . '.csv';

            // WHERE específico do grupo
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
            $write_group_csv($temp_csv, $rs);
            $rs->close();
        }

    } else {
        // cache / direct: agrupa em PHP
        $grupos = [];
        foreach ($dados as $row) {
            $gval = (string)($row->$zip_group_field ?? '');
            if ($gval === '') $gval = 'sem_valor';
            $grupos[$gval][] = $row;
        }
        ksort($grupos);

        foreach ($grupos as $grupo_val => $linhas) {
            $safe_name = rt_safe_filename($grupo_val);
            $temp_csv  = $tempdir . '/' . $safe_name . '.csv';
            $write_group_csv($temp_csv, $linhas);
        }
    }

    // Empacota tudo em ZIP
    $zip_name = 'relatorio_treinamentos_' . $zip_group_field . '_' . date('Ymd') . '.zip';
    $zip_file = $tempdir . '/relatorio.zip';
    $zip = new ZipArchive();
    $zip->open($zip_file, ZipArchive::CREATE);
    foreach (glob($tempdir . '/*.csv') as $f) {
        $zip->addFile($f, basename($f));
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_name . '"');
    header('Content-Length: ' . filesize($zip_file));
    readfile($zip_file);

    array_map('unlink', glob($tempdir . '/*.csv'));
    unlink($zip_file);
    rmdir($tempdir);
    exit;
}

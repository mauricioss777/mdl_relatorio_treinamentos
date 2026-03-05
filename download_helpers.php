<?php
/**
 * Funções auxiliares de exportação compartilhadas entre download.php e generate.php.
 * NÃO inclui bootstrap do Moodle — deve ser incluído após require_once config.php.
 */

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
 *
 * @param array      $export_cols    ['col_key' => 'Label', ...]
 * @param iterable   $rows_source    Array ou recordset de stdClass
 * @param string     $save_to        Caminho do arquivo de saída
 * @param string     $sheet_name     Nome da aba (máx 31 chars)
 * @param int|false  $concluido_idx  Índice da coluna 'concluido' para estilo condicional
 */
function rt_xlsx_stream(array $export_cols, iterable $rows_source, string $save_to, string $sheet_name = 'Relatório', $concluido_idx = false): void {
    $col_keys   = array_keys($export_cols);
    $col_labels = array_values($export_cols);
    $col_count  = count($col_keys);

    $letters = [];
    for ($i = 0; $i < $col_count; $i++) {
        $letters[] = rt_col_letter($i + 1);
    }

    $tmpdir     = sys_get_temp_dir() . '/rtxlsx_' . uniqid();
    mkdir($tmpdir, 0700, true);
    $sheet_file = $tmpdir . '/sheet1.xml';

    $fh = fopen($sheet_file, 'w');
    fwrite($fh,
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>'
    );

    $row_xml = '<row r="1">';
    foreach ($col_labels as $ci => $titulo) {
        $v       = rt_xlsx_escape((string)$titulo);
        $row_xml .= "<c r=\"{$letters[$ci]}1\" t=\"inlineStr\" s=\"1\"><is><t>{$v}</t></is></c>";
    }
    fwrite($fh, $row_xml . '</row>');

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

    $xlsx_tmp = $tmpdir . '/out.xlsx';
    $zip = new ZipArchive();
    $zip->open($xlsx_tmp, ZipArchive::CREATE);
    foreach (rt_xlsx_static_files($sheet_name) as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->addFile($sheet_file, 'xl/worksheets/sheet1.xml');
    $zip->close();

    rename($xlsx_tmp, $save_to);

    @unlink($sheet_file);
    @rmdir($tmpdir);
}

/** Extrai valores de uma linha para um array de strings */
function rt_get_row_values($row, $cols) {
    $vals = [];
    foreach (array_keys($cols) as $key) {
        $vals[] = (string)($row->$key ?? '');
    }
    return $vals;
}

/** Sanitiza nome de arquivo para uso em ZIP/FS */
function rt_safe_filename($name) {
    $name = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $name);
    $name = trim(substr($name, 0, 60));
    return $name ?: 'grupo';
}

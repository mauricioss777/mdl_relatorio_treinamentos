<?php
/**
 * CLI: Gerador manual de ZIP com XLSXs agrupados por campo.
 *
 * Uso:
 *   php local/relatorio_treinamentos/cli/gerar_zip.php
 *   php local/relatorio_treinamentos/cli/gerar_zip.php --campo=prof_nome_filial --saida=/tmp/relatorio.zip
 *
 * @package  local_relatorio_treinamentos
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');

ini_set('memory_limit', '-1');
set_time_limit(0);

// ── Parâmetros opcionais (não interativos) ────────────────────────────────────
[$options, $unrecognized] = cli_get_params(
    ['campo' => false, 'saida' => false, 'help' => false],
    ['h' => 'help']
);

if ($options['help']) {
    cli_writeln(<<<EOT
Gerador manual de ZIP (XLSX por grupo) — local_relatorio_treinamentos

Uso:
  php local/relatorio_treinamentos/cli/gerar_zip.php [opções]

Opções:
  --campo=CAMPO   Campo de agrupamento (ex: prof_nome_filial)
  --saida=PATH    Caminho do arquivo ZIP de saída
  -h, --help      Exibe esta ajuda

Sem opções: modo interativo (solicita campo e caminho via prompt).
EOT
    );
    exit(0);
}

// ── Helper: ler linha do STDIN ────────────────────────────────────────────────
function rt_cli_read(string $prompt): string {
    echo $prompt;
    return trim(fgets(STDIN));
}

// ── Campos de agrupamento disponíveis ────────────────────────────────────────
$all_zip_fields = \local_relatorio_treinamentos\helper\columns::get_zip_group_fields();
$zip_saved      = get_config('local_relatorio_treinamentos', 'agrupamentos_zip');
$zip_fields     = ($zip_saved !== false && $zip_saved !== '')
    ? array_intersect_key($all_zip_fields, array_flip(explode(',', $zip_saved)))
    : $all_zip_fields;

if (empty($zip_fields)) {
    cli_error('Nenhum campo de agrupamento configurado. Configure em Administração → Relatório de Treinamentos.');
}

$field_keys    = array_keys($zip_fields);
$field_labels  = array_values($zip_fields);

// ── Escolha do campo (interativo ou via --campo) ──────────────────────────────
$zip_group_field = $options['campo'] ? (string)$options['campo'] : false;

if ($zip_group_field) {
    if (!array_key_exists($zip_group_field, $zip_fields)) {
        cli_error("Campo inválido: '{$zip_group_field}'. Use --help para ver os campos disponíveis.");
    }
} else {
    cli_heading('Gerador de ZIP — Relatório de Treinamentos');
    echo "\n";
    cli_writeln('Campos de agrupamento disponíveis:');
    echo "\n";
    foreach ($field_keys as $idx => $key) {
        printf("  [%2d] %s  (%s)\n", $idx + 1, $field_labels[$idx], $key);
    }
    echo "\n";

    while (true) {
        $input = rt_cli_read('Escolha o número do campo: ');
        $num   = (int)$input - 1;
        if (isset($field_keys[$num])) {
            $zip_group_field = $field_keys[$num];
            break;
        }
        cli_writeln("  Opção inválida. Digite um número entre 1 e " . count($field_keys) . ".");
    }
}

// ── Caminho de saída (interativo ou via --saida) ──────────────────────────────
$default_saida = '/tmp/relatorio_' . $zip_group_field . '_' . date('Ymd_His') . '.zip';

if ($options['saida']) {
    $out_path = (string)$options['saida'];
} else {
    echo "\n";
    $out_path = rt_cli_read("Caminho do arquivo ZIP de saída\n[{$default_saida}]: ");
    if ($out_path === '') {
        $out_path = $default_saida;
    }
}

// Garante que o diretório de saída existe
$out_dir = dirname($out_path);
if (!is_dir($out_dir)) {
    cli_error("Diretório de saída não existe: {$out_dir}");
}

// ── Configuração ──────────────────────────────────────────────────────────────
$python = local_relatorio_treinamentos_get_python_path();
if (!$python) {
    cli_error('Python não configurado. Defina "pathtopython" em mdl_config (Administração → Servidor → Caminhos do sistema).');
}

$estrategia  = get_config('local_relatorio_treinamentos', 'estrategia') ?: 'view';
$export_cols = \local_relatorio_treinamentos\helper\columns::get_all();
$col_keys    = array_keys($export_cols);
$header_row  = array_values($export_cols);
$bom         = chr(0xEF) . chr(0xBB) . chr(0xBF);

echo "\n";
cli_writeln("Campo de agrupamento : " . $zip_fields[$zip_group_field] . " ({$zip_group_field})");
cli_writeln("Estratégia           : {$estrategia}");
cli_writeln("Arquivo de saída     : {$out_path}");
echo "\n";

// ── Helper: escreve grupo como CSV temporário ─────────────────────────────────
function rt_write_csv(string $csv_path, iterable $rows, array $col_keys, array $header_row, string $bom): int {
    $fh    = fopen($csv_path, 'w');
    $count = 0;
    fwrite($fh, $bom);
    fputcsv($fh, $header_row, ';');
    foreach ($rows as $row) {
        $vals = [];
        foreach ($col_keys as $key) {
            $vals[] = (string)($row->$key ?? '');
        }
        fputcsv($fh, $vals, ';');
        $count++;
    }
    fclose($fh);
    return $count;
}

function rt_safe_name(string $name): string {
    $name = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $name);
    return trim(substr($name, 0, 60)) ?: 'grupo';
}

// ── Diretório temporário ──────────────────────────────────────────────────────
$tempdir = sys_get_temp_dir() . '/rt_zip_cli_' . uniqid();
mkdir($tempdir, 0700, true);

// ── Geração dos CSVs ──────────────────────────────────────────────────────────
$total_linhas = 0;

if ($estrategia === 'view') {
    $view = local_relatorio_treinamentos_get_view_name();

    cli_writeln("Buscando grupos distintos na view materializada...");
    $group_rows = $DB->get_records_sql(
        "SELECT DISTINCT COALESCE(NULLIF({$zip_group_field}, ''), 'sem_valor') AS val
           FROM {$view}
         ORDER BY 1"
    );
    $total_grupos = count($group_rows);
    cli_writeln("Grupos encontrados: {$total_grupos}");
    echo "\n";

    $i = 0;
    foreach ($group_rows as $gr) {
        $gval      = (string)$gr->val;
        $safe_name = rt_safe_name($gval);
        $csv_path  = $tempdir . '/' . $safe_name . '.csv';
        $i++;

        // Progresso inline
        $pct   = str_pad(round($i * 100 / $total_grupos), 3) . '%';
        $label = mb_substr($gval, 0, 45);
        echo "\r[{$pct}] ({$i}/{$total_grupos}) {$label}" . str_repeat(' ', 20);

        if ($gval === 'sem_valor') {
            $where  = "WHERE ({$zip_group_field} IS NULL OR {$zip_group_field} = '')";
            $params = [];
        } else {
            $where  = "WHERE {$zip_group_field} = :zipgroupval";
            $params = ['zipgroupval' => $gval];
        }

        $rs = $DB->get_recordset_sql(
            "SELECT * FROM {$view} {$where} ORDER BY bas_nome_funcionario, nome_curso",
            $params
        );
        $n = rt_write_csv($csv_path, $rs, $col_keys, $header_row, $bom);
        $rs->close();
        $total_linhas += $n;
    }
    echo "\n";

} else {
    // Estratégia direct: executa a query completa e agrupa em PHP
    cli_writeln("Executando query completa (isso pode demorar vários minutos)...");
    $dados = \local_relatorio_treinamentos\task\atualizar_relatorio::buscar_dados($DB);
    cli_writeln("Registros carregados: " . count($dados));

    $grupos = [];
    foreach ($dados as $row) {
        $gval = (string)($row->$zip_group_field ?? '');
        if ($gval === '') $gval = 'sem_valor';
        $grupos[$gval][] = $row;
    }
    ksort($grupos);

    $total_grupos = count($grupos);
    cli_writeln("Grupos encontrados: {$total_grupos}");
    echo "\n";

    $i = 0;
    foreach ($grupos as $grupo_val => $linhas) {
        $i++;
        $pct   = str_pad(round($i * 100 / $total_grupos), 3) . '%';
        $label = mb_substr($grupo_val, 0, 45);
        echo "\r[{$pct}] ({$i}/{$total_grupos}) {$label}" . str_repeat(' ', 20);

        $safe_name = rt_safe_name($grupo_val);
        $csv_path  = $tempdir . '/' . $safe_name . '.csv';
        $n = rt_write_csv($csv_path, $linhas, $col_keys, $header_row, $bom);
        $total_linhas += $n;
    }
    echo "\n";
}

// ── Converte todos os CSVs para XLSX em uma única chamada Python ──────────────
echo "\n";
cli_writeln("Convertendo para XLSX...");
if (!local_relatorio_treinamentos_csv_dir_to_xlsx($tempdir)) {
    array_map('unlink', glob($tempdir . '/*'));
    rmdir($tempdir);
    cli_error('Falha ao converter CSVs para XLSX. Verifique Python e dependências.');
}

// ── Empacota ZIP ──────────────────────────────────────────────────────────────
cli_writeln("Empacotando ZIP...");
$zip = new ZipArchive();
$zip->open($out_path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$xlsx_files = glob($tempdir . '/*.xlsx');
foreach ($xlsx_files as $f) {
    $zip->addFile($f, basename($f));
}
$zip->close();

// ── Limpeza ───────────────────────────────────────────────────────────────────
array_map('unlink', $xlsx_files);
rmdir($tempdir);

// ── Resultado ────────────────────────────────────────────────────────────────
$size_kb = round(filesize($out_path) / 1024);
echo "\n";
cli_writeln("✔ ZIP gerado com sucesso!");
cli_writeln("  Arquivo  : {$out_path}");
cli_writeln("  Tamanho  : {$size_kb} KB");
cli_writeln("  Grupos   : {$total_grupos} arquivos XLSX");
cli_writeln("  Registros: {$total_linhas} linhas no total");
echo "\n";

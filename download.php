<?php
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/excellib.class.php');

require_login();
$context = context_system::instance();
require_capability('local/relatorio_treinamentos:view', $context);

$formato = required_param('formato', PARAM_ALPHA);

$cache = \cache::make('local_relatorio_treinamentos', 'relatorio');
$dados = $cache->get('dados');

if ($dados === false) {
    $task = new \local_relatorio_treinamentos\task\atualizar_relatorio();
    $task->execute();
    $dados = $cache->get('dados');
}

$cabecalho = [
    'Cód. Filial', 'Nome Filial', 'Nome Completo', 'Nº Identificação',
    'Data Admissão', 'Nome do Curso', 'Progresso (%)', 'Concluído',
    'Nota', 'Diretor', 'Ger. Distrital', 'Ger. Regional', 'Grupo'
];

$linhas = [];
foreach ((array)$dados as $row) {
    $linhas[] = [
        $row->codigo_filial,
        $row->nome_filial,
        $row->nome_completo,
        $row->numero_identificacao,
        $row->data_admissao,
        $row->nome_curso,
        $row->progresso_percentual,
        $row->concluido,
        $row->nota,
        $row->diretor,
        $row->gerente_distrital,
        $row->gerente_regional,
        $row->nome_grupo,
    ];
}

$filename = 'relatorio_treinamentos_' . date('Ymd');

// ── CSV ──────────────────────────────────────────────────────────────
if ($formato === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8 para Excel
    fputcsv($out, $cabecalho, ';');
    foreach ($linhas as $linha) {
        fputcsv($out, $linha, ';');
    }
    fclose($out);
    exit;
}

// ── XLSX ─────────────────────────────────────────────────────────────
if ($formato === 'xlsx') {
    $workbook = new MoodleExcelWorkbook($filename);
    $workbook->send($filename . '.xlsx');

    $sheet = $workbook->add_worksheet('Relatório');

    // Estilo cabeçalho
    $fmt_header = $workbook->add_format(['bold' => 1, 'bg_color' => '#343a40', 'color' => '#ffffff']);
    $fmt_sim    = $workbook->add_format(['bg_color' => '#d4edda']);
    $fmt_nao    = $workbook->add_format(['bg_color' => '#e2e3e5']);

    foreach ($cabecalho as $col => $titulo) {
        $sheet->write_string(0, $col, $titulo, $fmt_header);
    }

    foreach ($linhas as $row_idx => $linha) {
        $fmt_concluido = ($linha[7] === 'Sim') ? $fmt_sim : $fmt_nao;
        foreach ($linha as $col => $valor) {
            $fmt = ($col === 7) ? $fmt_concluido : null;
            $sheet->write_string($row_idx + 1, $col, (string)$valor, $fmt);
        }
    }

    $workbook->close();
    exit;
}
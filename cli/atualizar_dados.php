#!/usr/bin/env php
<?php
/**
 * CLI: Executa manualmente a atualização dos dados do relatório de treinamentos.
 * O modo de operação é determinado pela configuração do plugin (estratégia).
 *
 * Uso: php local/relatorio_treinamentos/cli/atualizar_dados.php
 */
define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once(__DIR__ . '/../locallib.php');

$estrategia = get_config('local_relatorio_treinamentos', 'estrategia') ?: 'direct';

$modos = [
    'view'   => 'view materializada (REFRESH MATERIALIZED VIEW)',
    'cache'  => 'cache Moodle (atualizar_relatorio task)',
    'direct' => 'consulta direta (sem cache, sem view)',
];

$descricao = $modos[$estrategia] ?? $estrategia;
cli_writeln("Modo configurado: {$estrategia} — {$descricao}");
cli_writeln('');

$inicio = microtime(true);

if ($estrategia === 'view') {
    cli_writeln('Atualizando view materializada...');
    try {
        local_relatorio_treinamentos_refresh_matview($DB);
        $view = local_relatorio_treinamentos_get_view_name();
        $count = $DB->count_records_sql("SELECT COUNT(*) FROM {$view}");
        $elapsed = round(microtime(true) - $inicio, 2);
        cli_writeln("Concluído em {$elapsed}s — {$count} registros na view.");
    } catch (Exception $e) {
        cli_error('Erro ao atualizar view: ' . $e->getMessage());
    }

} elseif ($estrategia === 'cache') {
    cli_writeln('Executando task de atualização do cache...');
    $task = new \local_relatorio_treinamentos\task\atualizar_relatorio();
    $task->execute();
    $elapsed = round(microtime(true) - $inicio, 2);

    $cache = \cache::make('local_relatorio_treinamentos', 'relatorio');
    $dados = $cache->get('dados');
    $count = is_array($dados) ? count($dados) : 0;
    cli_writeln("Concluído em {$elapsed}s — {$count} registros no cache.");

} else {
    // direct: não há nada para pré-calcular; apenas valida a query
    cli_writeln('Modo "direct": nenhuma pré-computação necessária.');
    cli_writeln('Validando consulta SQL...');
    try {
        $dados = \local_relatorio_treinamentos\task\atualizar_relatorio::buscar_dados($DB);
        $count = count($dados);
        $elapsed = round(microtime(true) - $inicio, 2);
        cli_writeln("Consulta OK em {$elapsed}s — {$count} registros retornados.");
    } catch (Exception $e) {
        cli_error('Erro na consulta: ' . $e->getMessage());
    }
}

cli_writeln('');
cli_writeln('Pronto.');

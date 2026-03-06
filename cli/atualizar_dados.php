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

if ($estrategia === 'view' || $estrategia === 'cache') {
    $label = $estrategia === 'view' ? 'view materializada e cache' : 'cache';
    cli_writeln("Atualizando {$label}...");
    $task = new \local_relatorio_treinamentos\task\atualizar_relatorio();
    $task->execute();
    $elapsed = round(microtime(true) - $inicio, 2);

    $cache = \cache::make('local_relatorio_treinamentos', 'relatorio');
    $ultima = $cache->get('ultima_atualizacao');
    $cursos = $cache->get('filter_options');
    $curso_count = is_array($cursos) && isset($cursos['nome_curso']) ? count($cursos['nome_curso']) : 0;
    cli_writeln("Concluído em {$elapsed}s — ultima_atualizacao={$ultima}, {$curso_count} cursos no filtro.");

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

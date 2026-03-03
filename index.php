<?php
require_once(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('local/relatorio_treinamentos:view', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/relatorio_treinamentos/index.php'));
$PAGE->set_title('Relatório de Treinamentos');
$PAGE->set_heading('Relatório de Treinamentos');
$PAGE->set_pagelayout('admin');

// Busca dados do cache
$cache          = \cache::make('local_relatorio_treinamentos', 'relatorio');
$dados          = $cache->get('dados');
$ultima_atualizacao = $cache->get('ultima_atualizacao');

// Se não tem cache ainda, gera na hora (primeira vez)
if ($dados === false) {
    $task  = new \local_relatorio_treinamentos\task\atualizar_relatorio();
    $task->execute();
    $dados          = $cache->get('dados');
    $ultima_atualizacao = $cache->get('ultima_atualizacao');
}

$ultima_str = $ultima_atualizacao
    ? userdate($ultima_atualizacao, get_string('strftimedatetimeshort', 'langconfig'))
    : 'N/A';

echo $OUTPUT->header();
?>

<div class="container-fluid mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <small class="text-muted">Última atualização: <?php echo $ultima_str; ?> &nbsp;|&nbsp; <?php echo count((array)$dados); ?> registros</small>
        <div>
            <a href="download.php?formato=xlsx" class="btn btn-success btn-sm">
                <i class="fa fa-file-excel-o"></i> Download XLSX
            </a>
            <a href="download.php?formato=csv" class="btn btn-secondary btn-sm ml-2">
                <i class="fa fa-file-text-o"></i> Download CSV
            </a>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-bordered table-striped table-hover table-sm" id="relatorio-table">
            <thead class="thead-dark">
                <tr>
                    <th>Cód. Filial</th>
                    <th>Nome Filial</th>
                    <th>Nome Completo</th>
                    <th>Nº Identificação</th>
                    <th>Data Admissão</th>
                    <th>Nome do Curso</th>
                    <th>Progresso (%)</th>
                    <th>Concluído</th>
                    <th>Nota</th>
                    <th>Diretor</th>
                    <th>Ger. Distrital</th>
                    <th>Ger. Regional</th>
                    <th>Grupo</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($dados)): ?>
                    <?php foreach ($dados as $row): ?>
                    <tr>
                        <td><?php echo s($row->codigo_filial); ?></td>
                        <td><?php echo s($row->nome_filial); ?></td>
                        <td><?php echo s($row->nome_completo); ?></td>
                        <td><?php echo s($row->numero_identificacao); ?></td>
                        <td><?php echo s($row->data_admissao); ?></td>
                        <td><?php echo s($row->nome_curso); ?></td>
                        <td>
                            <div class="progress" style="height:18px; min-width:80px;">
                                <div class="progress-bar bg-success" role="progressbar"
                                    style="width: <?php echo (float)$row->progresso_percentual; ?>%">
                                    <?php echo $row->progresso_percentual; ?>%
                                </div>
                            </div>
                        </td>
                        <td>
                            <?php if ($row->concluido === 'Sim'): ?>
                                <span class="badge badge-success">Sim</span>
                            <?php else: ?>
                                <span class="badge badge-secondary">Não</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo s($row->nota); ?></td>
                        <td><?php echo s($row->diretor); ?></td>
                        <td><?php echo s($row->gerente_distrital); ?></td>
                        <td><?php echo s($row->gerente_regional); ?></td>
                        <td><?php echo s($row->nome_grupo); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="13" class="text-center text-muted">Nenhum dado encontrado.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php echo $OUTPUT->footer(); ?>
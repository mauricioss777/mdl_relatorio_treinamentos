<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Cria (se não existir) a categoria e o campo personalizado de curso
 * 'rt_incluir_filtro' usado para controlar quais cursos aparecem no filtro
 * do Relatório de Treinamentos.
 */
function local_relatorio_treinamentos_create_customfield($DB) {
    // Já existe?
    if ($DB->record_exists('customfield_field', ['shortname' => 'rt_incluir_filtro'])) {
        return;
    }

    $context = context_system::instance();

    // Categoria
    $catid = $DB->get_field('customfield_category', 'id', [
        'name'      => 'Relatório de Treinamentos',
        'component' => 'core_course',
        'area'      => 'course',
    ]);
    if (!$catid) {
        $catid = $DB->insert_record('customfield_category', (object)[
            'name'         => 'Relatório de Treinamentos',
            'component'    => 'core_course',
            'area'         => 'course',
            'itemid'       => 0,
            'contextid'    => $context->id,
            'sortorder'    => 1000,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
    }

    // Campo checkbox
    $DB->insert_record('customfield_field', (object)[
        'shortname'         => 'rt_incluir_filtro',
        'name'              => 'Incluir no filtro do relatório',
        'type'              => 'checkbox',
        'description'       => 'Quando marcado, este curso aparece no filtro de curso do Relatório de Treinamentos.',
        'descriptionformat' => FORMAT_HTML,
        'sortorder'         => 1,
        'categoryid'        => $catid,
        'configdata'        => json_encode([
            'required'       => '0',
            'uniquevalues'   => '0',
            'checkbydefault' => '0',
            'locked'         => '0',
            'visibility'     => '2', // visível para todos
        ]),
        'timecreated'  => time(),
        'timemodified' => time(),
    ]);
}

<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_relatorio_treinamentos',
        get_string('pluginname', 'local_relatorio_treinamentos')
    );

    $all_columns   = \local_relatorio_treinamentos\helper\columns::get_all();
    $default_keys  = \local_relatorio_treinamentos\helper\columns::get_default();
    $default_value = array_fill_keys($default_keys, 1);

    $settings->add(new admin_setting_configmulticheckbox(
        'local_relatorio_treinamentos/colunas_visiveis',
        get_string('setting_colunas_visiveis', 'local_relatorio_treinamentos'),
        get_string('setting_colunas_visiveis_desc', 'local_relatorio_treinamentos'),
        $default_value,
        array_map('htmlspecialchars', $all_columns)
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_relatorio_treinamentos/usar_cache',
        'Usar cache da task agendada',
        'Quando habilitado, os dados são lidos do cache gerado pela task de 02h. ' .
        'Quando desabilitado, a consulta SQL é executada diretamente a cada requisição (modo de teste de performance).',
        0
    ));

    $ADMIN->add('localplugins', $settings);
}

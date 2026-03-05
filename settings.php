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

    $settings->add(new admin_setting_configselect(
        'local_relatorio_treinamentos/estrategia',
        'Estratégia de dados',
        'Define como os dados do relatório são obtidos. ' .
        '<b>Consulta direta:</b> executa o SQL a cada requisição (mais lento, sem setup). ' .
        '<b>Cache:</b> usa o cache gerado pela task de 02h (rápido, 4 GB RAM). ' .
        '<b>View materializada:</b> consulta a view pré-computada com índices (mais rápido, recomendado para produção).',
        'view',
        [
            'direct' => 'Consulta direta (SQL a cada requisição)',
            'cache'  => 'Cache da task agendada (array PHP)',
            'view'   => 'View materializada (recomendado)',
        ]
    ));

    $ADMIN->add('localplugins', $settings);
}

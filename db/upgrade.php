<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_relatorio_treinamentos_upgrade($oldversion) {
    global $CFG, $DB;

    if ($oldversion < 2026030403) {
        require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');
        local_relatorio_treinamentos_create_customfield($DB);
        upgrade_plugin_savepoint(true, 2026030403, 'local', 'relatorio_treinamentos');
    }

    if ($oldversion < 2026030404) {
        require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');
        local_relatorio_treinamentos_setup_matview($DB);
        // Migra configuração usar_cache → estrategia
        $usar_cache = get_config('local_relatorio_treinamentos', 'usar_cache');
        if ($usar_cache !== false && !get_config('local_relatorio_treinamentos', 'estrategia')) {
            set_config('estrategia', $usar_cache ? 'cache' : 'view', 'local_relatorio_treinamentos');
        }
        unset_config('usar_cache', 'local_relatorio_treinamentos');
        upgrade_plugin_savepoint(true, 2026030404, 'local', 'relatorio_treinamentos');
    }

    return true;
}

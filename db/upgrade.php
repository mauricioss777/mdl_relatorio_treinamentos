<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_relatorio_treinamentos_upgrade($oldversion) {
    global $CFG, $DB;

    if ($oldversion < 2026030403) {
        require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');
        local_relatorio_treinamentos_create_customfield($DB);
        upgrade_plugin_savepoint(true, 2026030403, 'local', 'relatorio_treinamentos');
    }

    return true;
}

<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_relatorio_treinamentos_install() {
    global $CFG, $DB;
    require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');
    local_relatorio_treinamentos_create_customfield($DB);
    return true;
}

<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/relatorio_treinamentos:view' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_relatorio_treinamentos\task\atualizar_relatorio',
        'blocking'  => 0,
        'minute'    => '0',
        'hour'      => '2',     // Roda todo dia às 02:00
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
        'disabled'  => 0,
    ],
];
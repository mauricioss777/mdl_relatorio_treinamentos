<?php
/**
 * Entrega arquivo pré-gerado por token (gerado em generate.php).
 * Valida userid, verifica expiração, envia o arquivo e remove após entrega.
 */
require_once(__DIR__ . '/../../config.php');

require_login();

$token = required_param('token', PARAM_ALPHANUMEXT);
$token = preg_replace('/[^a-f0-9]/', '', strtolower($token));

if (strlen($token) !== 32) {
    http_response_code(400);
    die('Token inválido.');
}

$token_path = sys_get_temp_dir() . '/rt_tok_' . $token . '.json';

if (!file_exists($token_path)) {
    http_response_code(404);
    die('Token inválido ou expirado.');
}

$data = json_decode(file_get_contents($token_path), true);

if (!$data || (int)($data['userid'] ?? 0) !== (int)$USER->id) {
    http_response_code(403);
    die('Acesso negado.');
}

if (time() > (int)($data['expires'] ?? 0)) {
    @unlink($token_path);
    @unlink($data['file'] ?? '');
    http_response_code(410);
    die('Token expirado. Gere o arquivo novamente.');
}

$file     = $data['file'] ?? '';
$filename = basename($data['filename'] ?? 'arquivo');

if (!$file || !file_exists($file)) {
    @unlink($token_path);
    http_response_code(404);
    die('Arquivo não encontrado.');
}

// Determina Content-Type pela extensão
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$content_types = [
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'zip'  => 'application/zip',
    'csv'  => 'text/csv; charset=utf-8',
];
$ct = $content_types[$ext] ?? 'application/octet-stream';

// Consome token antes de enviar (impede double-download)
unlink($token_path);

header('Content-Type: ' . $ct);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: no-cache, no-store');

readfile($file);
unlink($file);
exit;

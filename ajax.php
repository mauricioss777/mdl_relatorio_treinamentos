<?php
/**
 * Endpoint Server-Side DataTables para o Relatório de Treinamentos.
 *
 * Recebe parâmetros padrão do DataTables (draw, start, length, search, order)
 * mais `filters` (JSON com filtros customizados) e retorna JSON paginado.
 * Os dados nunca saem do servidor em volume — apenas a página solicitada.
 */
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

require_login();
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

// ── Controle de acesso (idêntico ao index.php) ────────────────────────────────
$context           = context_system::instance();
$is_admin          = is_siteadmin();
$is_moodle_manager = has_capability('local/relatorio_treinamentos:view', $context);

$cargo = $DB->get_field('user_info_data', 'data', [
    'userid'  => $USER->id,
    'fieldid' => 18,
]);
$manager_codes = \local_relatorio_treinamentos\helper\columns::get_manager_cargo_codes();
$is_gestor     = in_array(trim((string)$cargo), $manager_codes);

if (!$is_admin && !$is_moodle_manager && !$is_gestor) {
    echo json_encode(['error' => 'noaccess']);
    die();
}

// ── Parâmetros do DataTables ──────────────────────────────────────────────────
$draw       = required_param('draw', PARAM_INT);
$start      = optional_param('start', 0, PARAM_INT);
$length     = optional_param('length', 25, PARAM_INT);
// DataTables envia arrays multidimensionais (search[value], order[0][column], etc.)
// optional_param_array() do Moodle não suporta arrays aninhados — lemos $_POST diretamente.
$search_val = trim(clean_param($_POST['search']['value'] ?? '', PARAM_TEXT));
$order_raw  = [];
if (isset($_POST['order'][0])) {
    $order_raw = [[
        'column' => clean_param($_POST['order'][0]['column'] ?? 0, PARAM_INT),
        'dir'    => clean_param($_POST['order'][0]['dir']    ?? 'asc', PARAM_ALPHA),
    ]];
}

// ── Filtros customizados ──────────────────────────────────────────────────────
$filters_json = optional_param('filters', '{}', PARAM_RAW);
$filters      = json_decode($filters_json, true);
if (!is_array($filters)) {
    $filters = [];
}

// ── Carregar dados: cache ou consulta direta ──────────────────────────────────
$usar_cache = (bool)get_config('local_relatorio_treinamentos', 'usar_cache');
if ($usar_cache) {
    $cache = \cache::make('local_relatorio_treinamentos', 'relatorio');
    $dados = $cache->get('dados');
    if ($dados === false) {
        $task = new \local_relatorio_treinamentos\task\atualizar_relatorio();
        $task->execute();
        $dados = $cache->get('dados');
    }
} else {
    ini_set('memory_limit', '4G');
    $dados = \local_relatorio_treinamentos\task\atualizar_relatorio::buscar_dados($DB);
}
$dados = array_values((array)$dados);

// ── Filtro de acesso para gestores ────────────────────────────────────────────
if (!$is_admin && !$is_moodle_manager && $is_gestor) {
    $gestor_nome = fullname($USER);
    $dados = array_values(array_filter($dados, function($row) use ($gestor_nome) {
        return ($row->gestor ?? '') === $gestor_nome;
    }));
}

$records_total = count($dados);

// ── Filtros customizados ──────────────────────────────────────────────────────
foreach ($filters as $field => $value) {
    $value = trim((string)$value);
    if ($value === '') {
        continue;
    }
    $field = clean_param($field, PARAM_ALPHANUMEXT);
    $dados = array_values(array_filter($dados, function($row) use ($field, $value) {
        return (string)($row->$field ?? '') === $value;
    }));
}

// ── Busca global ──────────────────────────────────────────────────────────────
if ($search_val !== '') {
    $needle = mb_strtolower($search_val);
    $dados  = array_values(array_filter($dados, function($row) use ($needle) {
        foreach ((array)$row as $val) {
            if (mb_strpos(mb_strtolower((string)$val), $needle) !== false) {
                return true;
            }
        }
        return false;
    }));
}

$records_filtered = count($dados);

// ── Ordenação ─────────────────────────────────────────────────────────────────
$column_keys = array_keys(\local_relatorio_treinamentos\helper\columns::get_all());

if (!empty($order_raw)) {
    $col_idx = (int)($order_raw[0]['column'] ?? 0);
    $dir     = (($order_raw[0]['dir'] ?? 'asc') === 'desc') ? -1 : 1;
    $col_key = $column_keys[$col_idx] ?? $column_keys[0];

    usort($dados, function($a, $b) use ($col_key, $dir) {
        $va = (string)($a->$col_key ?? '');
        $vb = (string)($b->$col_key ?? '');
        return $dir * strnatcasecmp($va, $vb);
    });
}

// ── Paginação ─────────────────────────────────────────────────────────────────
if ($length > 0) {
    $page = array_slice($dados, $start, $length);
} else {
    $page = array_slice($dados, $start);
}

// ── Montar array de dados para o DataTables ───────────────────────────────────
$data = [];
foreach ($page as $row) {
    $rowdata = [];
    foreach ($column_keys as $key) {
        $val = $row->$key ?? '';
        if ($key === 'progresso_percentual') {
            $pct       = number_format((float)$val, 2);
            $rowdata[] = '<div class="progress rt-progress" title="' . $pct . '%">'
                       . '<div class="progress-bar bg-success" role="progressbar" style="width:' . $pct . '%"></div>'
                       . '</div><small>' . $pct . '%</small>';
        } elseif ($key === 'concluido') {
            $rowdata[] = ($val === 'Sim')
                ? '<span class="badge badge-success">Sim</span>'
                : '<span class="badge badge-secondary">Não</span>';
        } else {
            $rowdata[] = s((string)$val);
        }
    }
    $data[] = $rowdata;
}

// ── Resposta ──────────────────────────────────────────────────────────────────
echo json_encode([
    'draw'            => $draw,
    'recordsTotal'    => $records_total,
    'recordsFiltered' => $records_filtered,
    'data'            => $data,
], JSON_UNESCAPED_UNICODE);

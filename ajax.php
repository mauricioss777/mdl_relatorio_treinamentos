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

require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');
$is_gestor = local_relatorio_treinamentos_is_gestor($USER);

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

// ── Colunas disponíveis (whitelist para SQL injection prevention) ─────────────
$all_columns  = \local_relatorio_treinamentos\helper\columns::get_all();
$column_keys  = array_keys($all_columns);

// Campos cujo valor enviado pelo filtro corresponde a outra coluna na view
// (ex: bas_nome_funcionario exibe o nome mas envia userid como valor)
$filter_field_aliases = ['bas_nome_funcionario' => 'userid'];
$estrategia   = get_config('local_relatorio_treinamentos', 'estrategia') ?: 'direct';

// ── Cursos com flag rt_incluir_filtro (filtro implícito de visualização) ──────
// Quando o usuário não seleciona nenhum curso no filtro, a visualização mostra
// apenas os cursos marcados com rt_incluir_filtro=1. Os dados na view/cache
// permanecem completos (sem filtro de cursos) para que o export funcione.
require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');
$cursos_filtro_implicito = local_relatorio_treinamentos_get_nomes_cursos_filtro();
$aplicar_filtro_cursos   = !empty($cursos_filtro_implicito) && !isset($filters['nome_curso']);

// ══════════════════════════════════════════════════════════════════════════════
// ESTRATÉGIA: view materializada — paginação SQL real (mais eficiente)
// ══════════════════════════════════════════════════════════════════════════════
if ($estrategia === 'view') {
    require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');
    $view = local_relatorio_treinamentos_get_view_name();

    // ── WHERE: acesso + filtros + busca global ────────────────────────────────
    $where_parts = [];
    $sql_params  = [];
    $pcount      = 0;

    if (!$is_admin && !$is_moodle_manager && $is_gestor) {
        $where_parts[] = "gestor = :wgestor";
        $sql_params['wgestor'] = fullname($USER);
    }

    $_allowed = array_merge($column_keys, array_values($filter_field_aliases));
    foreach ($filters as $field => $value) {
        $field        = clean_param($field, PARAM_ALPHANUMEXT);
        $actual_field = $filter_field_aliases[$field] ?? $field;
        local_relatorio_treinamentos_build_filter_condition(
            $actual_field, $value, $_allowed, $where_parts, $sql_params, $pcount
        );
    }

    if ($search_val !== '') {
        $search_fields  = ['bas_nome_funcionario', 'bas_email', 'nome_curso', 'prof_nome_filial', 'prof_codigo_filial'];
        $search_parts   = [];
        foreach ($search_fields as $sf) {
            $pname = 'ws_' . $sf;
            $search_parts[]   = $DB->sql_like($sf, ":$pname", false);
            $sql_params[$pname] = '%' . $DB->sql_like_escape($search_val) . '%';
        }
        $where_parts[] = '(' . implode(' OR ', $search_parts) . ')';
    }

    if ($aplicar_filtro_cursos) {
        [$in_sql, $in_params] = $DB->get_in_or_equal($cursos_filtro_implicito, SQL_PARAMS_NAMED, 'wcf');
        $where_parts[] = "nome_curso $in_sql";
        $sql_params    = array_merge($sql_params, $in_params);
    }

    $where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

    // ── Contagens ─────────────────────────────────────────────────────────────
    $total_where_parts = [];
    $total_params      = [];
    if (!$is_admin && !$is_moodle_manager && $is_gestor) {
        $total_where_parts[] = 'gestor = :agestor';
        $total_params['agestor'] = fullname($USER);
    }
    if ($aplicar_filtro_cursos) {
        [$in_sql_t, $in_params_t] = $DB->get_in_or_equal($cursos_filtro_implicito, SQL_PARAMS_NAMED, 'wcft');
        $total_where_parts[] = "nome_curso $in_sql_t";
        $total_params        = array_merge($total_params, $in_params_t);
    }
    $total_where_sql  = $total_where_parts ? 'WHERE ' . implode(' AND ', $total_where_parts) : '';
    $records_total    = (int)$DB->count_records_sql("SELECT COUNT(*) FROM $view $total_where_sql", $total_params);
    $records_filtered = (int)$DB->count_records_sql("SELECT COUNT(*) FROM $view $where_sql", $sql_params);

    // ── Ordenação (whitelist) ─────────────────────────────────────────────────
    $col_idx  = (int)($order_raw[0]['column'] ?? 0);
    $sort_key = $column_keys[$col_idx] ?? $column_keys[0];
    $sort_dir = (($order_raw[0]['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';

    // ── Dados paginados (apenas a página atual sai do DB) ─────────────────────
    $rows = $DB->get_records_sql(
        "SELECT * FROM $view $where_sql ORDER BY $sort_key $sort_dir",
        $sql_params,
        $start,
        max(1, $length)
    );

    $data = [];
    foreach ($rows as $row) {
        $rowdata = [];
        foreach ($column_keys as $key) {
            $val = $row->$key ?? '';
            if ($key === 'progresso_percentual') {
                $pct          = number_format((float)$val, 2);
                $rowdata[$key] = '<div class="progress rt-progress" title="' . $pct . '%">'
                               . '<div class="progress-bar bg-success" role="progressbar" style="width:' . $pct . '%"></div>'
                               . '</div><small>' . $pct . '%</small>';
            } elseif ($key === 'concluido') {
                $rowdata[$key] = ($val === 'Sim')
                    ? '<span class="badge badge-success">Sim</span>'
                    : '<span class="badge badge-secondary">Não</span>';
            } else {
                $rowdata[$key] = s((string)$val);
            }
        }
        $data[] = $rowdata;
    }

    echo json_encode([
        'draw'            => $draw,
        'recordsTotal'    => $records_total,
        'recordsFiltered' => $records_filtered,
        'data'            => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// ESTRATÉGIA: cache ou consulta direta — mantém lógica PHP original
// ══════════════════════════════════════════════════════════════════════════════
if ($estrategia === 'cache') {
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

// Filtro implícito: mostrar apenas cursos com rt_incluir_filtro quando não
// há filtro ativo de nome_curso — os dados no cache/view continuam completos.
if ($aplicar_filtro_cursos) {
    $cursos_set = array_flip($cursos_filtro_implicito);
    $dados = array_values(array_filter($dados, function($row) use ($cursos_set) {
        return isset($cursos_set[trim((string)($row->nome_curso ?? ''))]);
    }));
}

$records_total = count($dados);

// ── Filtros customizados ──────────────────────────────────────────────────────
$_allowed_cache = array_merge($column_keys, array_values($filter_field_aliases));
foreach ($filters as $field => $value) {
    $field        = clean_param($field, PARAM_ALPHANUMEXT);
    $actual_field = $filter_field_aliases[$field] ?? $field;
    if (!in_array($actual_field, $_allowed_cache)) continue;
    $dados = array_values(array_filter($dados, function($row) use ($actual_field, $value) {
        return local_relatorio_treinamentos_row_matches_filters($row, [$actual_field => $value]);
    }));
}

// ── Busca global ──────────────────────────────────────────────────────────────
if ($search_val !== '') {
    $needle = mb_strtolower($search_val);
    $dados  = array_values(array_filter($dados, function($row) use ($needle) {
        foreach ((array)$row as $val) {
            if (mb_strpos(mb_strtolower((string)$val), $needle) !== false) return true;
        }
        return false;
    }));
}

$records_filtered = count($dados);

// ── Ordenação ─────────────────────────────────────────────────────────────────
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
$page = ($length > 0) ? array_slice($dados, $start, $length) : array_slice($dados, $start);

// ── Montar array de dados para o DataTables ───────────────────────────────────
$data = [];
foreach ($page as $row) {
    $rowdata = [];
    foreach ($column_keys as $key) {
        $val = $row->$key ?? '';
        if ($key === 'progresso_percentual') {
            $pct           = number_format((float)$val, 2);
            $rowdata[$key] = '<div class="progress rt-progress" title="' . $pct . '%">'
                           . '<div class="progress-bar bg-success" role="progressbar" style="width:' . $pct . '%"></div>'
                           . '</div><small>' . $pct . '%</small>';
        } elseif ($key === 'concluido') {
            $rowdata[$key] = ($val === 'Sim')
                ? '<span class="badge badge-success">Sim</span>'
                : '<span class="badge badge-secondary">Não</span>';
        } else {
            $rowdata[$key] = s((string)$val);
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

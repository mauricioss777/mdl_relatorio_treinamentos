<?php
require_once(__DIR__ . '/../../config.php');

require_login();

// ── Controle de acesso ────────────────────────────────────────────────────────
$context           = context_system::instance();
$is_admin          = is_siteadmin();
$is_moodle_manager = has_capability('local/relatorio_treinamentos:view', $context);

require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');
$is_gestor = local_relatorio_treinamentos_is_gestor($USER);

if (!$is_admin && !$is_moodle_manager && !$is_gestor) {
    throw new moodle_exception('noaccess', 'local_relatorio_treinamentos');
}

// ── Filtros visíveis (definido cedo pois é usado no bloco de estratégia) ─────
$all_filter_fields = \local_relatorio_treinamentos\helper\columns::get_filter_fields();
if ($is_gestor && !$is_admin && !$is_moodle_manager) {
    $filtros_saved = get_config('local_relatorio_treinamentos', 'filtros_visiveis_gestor');
    if ($filtros_saved === false || $filtros_saved === '') {
        $filtros_saved = get_config('local_relatorio_treinamentos', 'filtros_visiveis');
    }
    // Gestores podem usar qualquer coluna como filtro, não apenas os 7 campos padrão
    $filter_base = \local_relatorio_treinamentos\helper\columns::get_all();
} else {
    $filtros_saved = get_config('local_relatorio_treinamentos', 'filtros_visiveis');
    $filter_base = $all_filter_fields;
}
$filter_fields = ($filtros_saved !== false && $filtros_saved !== '')
    ? array_intersect_key($filter_base, array_flip(explode(',', $filtros_saved)))
    : $all_filter_fields;

// ── Page setup ────────────────────────────────────────────────────────────────
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/relatorio_treinamentos/index.php'));
$PAGE->set_title('Relatório de Treinamentos');
$PAGE->set_heading('Relatório de Treinamentos');
$PAGE->set_pagelayout('admin');

// ── Metadados: cache/view (lê cache), direct (computa na hora) ───────────────
$estrategia = get_config('local_relatorio_treinamentos', 'estrategia') ?: 'direct';
if ($estrategia === 'direct') {
    $ultima_atualizacao = null;
    ini_set('memory_limit', '4G');
    $dados_temp  = \local_relatorio_treinamentos\task\atualizar_relatorio::buscar_dados($DB);
    $filter_keys = array_keys(\local_relatorio_treinamentos\helper\columns::get_filter_fields());
    $filter_options = array_fill_keys($filter_keys, []);
    foreach ($dados_temp as $row) {
        foreach ($filter_keys as $field) {
            $v = (string)($row->$field ?? '');
            if ($v !== '') { $filter_options[$field][$v] = $v; }
        }
    }
    foreach ($filter_options as &$vals) { asort($vals); }
    unset($vals, $dados_temp);
    $cursos_filtro = \local_relatorio_treinamentos\task\atualizar_relatorio::get_cursos_no_filtro($DB);
    if (!empty($cursos_filtro)) { $filter_options['nome_curso'] = $cursos_filtro; }
} else {
    // 'cache' e 'view': task pré-computa filter_options e grava no cache
    $cache              = \cache::make('local_relatorio_treinamentos', 'relatorio');
    $ultima_atualizacao = $cache->get('ultima_atualizacao');
    $filter_options     = $cache->get('filter_options') ?: [];

    // View sem cache (task ainda não rodou): computa filter_options diretamente da view
    if (empty($filter_options) && $estrategia === 'view') {
        require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');
        $view = local_relatorio_treinamentos_get_view_name();
        $fkeys = array_keys($filter_fields); // usa campos configurados pelo admin
        $filter_options = array_fill_keys($fkeys, []);
        foreach ($fkeys as $field) {
            if ($field === 'nome_curso') continue;
            $rows = $DB->get_records_sql(
                "SELECT DISTINCT $field AS val FROM $view WHERE $field IS NOT NULL AND $field <> '' ORDER BY $field"
            );
            foreach ($rows as $row) {
                $v = (string)$row->val;
                if ($v !== '') { $filter_options[$field][$v] = $v; }
            }
        }
        $filter_options['nome_curso'] = \local_relatorio_treinamentos\task\atualizar_relatorio::get_cursos_no_filtro($DB);
        $cache->set('filter_options', $filter_options);
        $cache->set('ultima_atualizacao', time());
        $ultima_atualizacao = time();
    }
}

$ultima_str = $ultima_atualizacao
    ? userdate($ultima_atualizacao, get_string('strftimedatetimeshort', 'langconfig'))
    : 'N/A';

// ── Populate filter_options para campos extras de gestor (sempre frescos, sem cache) ─
// Campos não-padrão configurados para gestores nunca vêm do cache (que é global),
// pois as opções devem ser restritas aos colaboradores do próprio gestor.
if ($estrategia === 'view' && $is_gestor && !$is_admin && !$is_moodle_manager) {
    require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');
    $view = local_relatorio_treinamentos_get_view_name();
    $standard_fields = array_keys(\local_relatorio_treinamentos\helper\columns::get_filter_fields());
    $ef_params = ['ef_gestor' => fullname($USER)];
    foreach (array_keys($filter_fields) as $ef) {
        // Campos padrão já estão no cache; nome_curso tem tratamento próprio
        if (in_array($ef, $standard_fields) || $ef === 'nome_curso') continue;
        // Sempre sobrescreve (ignora cache) para garantir escopo correto do gestor
        $rows = $DB->get_records_sql(
            "SELECT DISTINCT $ef AS val FROM $view
              WHERE $ef IS NOT NULL AND $ef <> '' AND gestor = :ef_gestor
              ORDER BY $ef LIMIT 2000",
            $ef_params
        );
        $filter_options[$ef] = [];
        foreach ($rows as $row) {
            $v = (string)$row->val;
            if ($v !== '') $filter_options[$ef][$v] = $v;
        }
    }
}

// ── Definições de colunas ─────────────────────────────────────────────────────
$all_columns      = \local_relatorio_treinamentos\helper\columns::get_all();
$column_groups    = \local_relatorio_treinamentos\helper\columns::get_groups();
// Agrupamentos ZIP: usa setting do admin; se não configurado, usa todos disponíveis
$all_zip_fields   = \local_relatorio_treinamentos\helper\columns::get_zip_group_fields();
$zip_saved        = get_config('local_relatorio_treinamentos', 'agrupamentos_zip');
$zip_group_fields = ($zip_saved !== false && $zip_saved !== '')
    ? array_intersect_key($all_zip_fields, array_flip(explode(',', $zip_saved)))
    : $all_zip_fields;

// ── Colunas padrão ────────────────────────────────────────────────────────────
$settings_val = get_config('local_relatorio_treinamentos', 'colunas_visiveis');
if ($settings_val && is_array($settings_val)) {
    $default_visible = array_keys(array_filter($settings_val));
} elseif ($settings_val && is_string($settings_val)) {
    $default_visible = array_filter(explode(',', $settings_val));
} else {
    $default_visible = \local_relatorio_treinamentos\helper\columns::get_default();
}

// ── Saída HTML ────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap4.min.css">
<style>
/* ── Floating column selector ── */
#rt-col-toggle {
    position: fixed; left: 0; top: 50%; transform: translateY(-50%);
    z-index: 10000; writing-mode: vertical-rl; text-orientation: mixed;
    background: #343a40; color: #fff; padding: 12px 6px; cursor: pointer;
    border-radius: 0 4px 4px 0; font-size: 12px; font-weight: bold;
    letter-spacing: 1px; user-select: none; box-shadow: 2px 0 5px rgba(0,0,0,.3);
}
#rt-col-toggle:hover { background: #495057; }
#rt-col-panel {
    display: none; position: fixed; left: 0; top: 0; bottom: 0;
    width: 280px; z-index: 9999; background: #fff;
    box-shadow: 3px 0 10px rgba(0,0,0,.25); overflow-y: auto;
}
#rt-col-panel.open { display: block; }
#rt-col-panel-header {
    position: sticky; top: 0; background: #343a40; color: #fff;
    padding: 10px 14px; display: flex; justify-content: space-between;
    align-items: center; z-index: 1;
}
#rt-col-panel-body { padding: 8px 14px 20px; }
.rt-col-group-title {
    font-weight: bold; font-size: 11px; text-transform: uppercase;
    color: #6c757d; margin-top: 12px; margin-bottom: 4px;
    border-bottom: 1px solid #dee2e6; padding-bottom: 2px;
}
.rt-col-panel-actions { display: flex; gap: 6px; margin: 8px 14px 4px; }
.rt-col-panel-actions button { font-size: 11px; }
/* ── Progress ── */
.rt-progress { height: 10px; min-width: 60px; display: inline-block; width: 60px; vertical-align: middle; }
/* ── Filter panel ── */
#rt-filter-panel .card-body { background: #f8f9fa; }
/* ── DataTables processing overlay ── */
#rt-table_processing {
    background: rgba(255,255,255,.95);
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 12px 24px;
    font-weight: bold;
    color: #343a40;
    text-align: center;
}
/* oculta os dots animados — só mostra o texto "Carregando..." */
#rt-table_processing > div { display: none; }
/* ── DataTables: remove ícones de ordenação duplicados (conflito com tema Moodle) ── */
table.dataTable thead > tr > th.sorting::before,
table.dataTable thead > tr > th.sorting::after,
table.dataTable thead > tr > th.sorting_asc::before,
table.dataTable thead > tr > th.sorting_asc::after,
table.dataTable thead > tr > th.sorting_desc::before,
table.dataTable thead > tr > th.sorting_desc::after {
    content: '' !important;
}
/* ── Loading Overlay ── */
#rt-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 9999;
}
#rt-overlay-box {
    position: absolute;
    top: 50%; left: 50%;
    transform: translate(-50%, -50%);
    background: #fff;
    border-radius: 8px;
    padding: 28px 40px;
    text-align: center;
    box-shadow: 0 6px 24px rgba(0,0,0,0.3);
    min-width: 180px;
}
#rt-overlay-box .spinner-border { width: 2.4rem; height: 2.4rem; }
#rt-overlay-box p { margin: 14px 0 0; font-size: 0.95rem; color: #444; }
#rt-overlay-progress { margin-top: 16px; min-width: 280px; }
#rt-overlay-progress .progress { height: 18px; border-radius: 4px; }
#rt-overlay-progress .progress-bar { font-size: 0.8rem; line-height: 18px; transition: width 0.3s ease; }
#rt-overlay-progress small { display: block; margin-top: 5px; font-size: 0.82rem; color: #666; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
#rt-progress-phase { display: block; margin-top: 10px; font-size: 0.92rem; font-weight: 600; color: #333; }
</style>

<div class="container-fluid mt-3">

<!-- ── Loading Overlay ── -->
<div id="rt-overlay">
    <div id="rt-overlay-box">
        <div class="spinner-border text-primary" role="status">
            <span class="sr-only">Carregando...</span>
        </div>
        <p id="rt-overlay-msg">Carregando...</p>
        <div id="rt-overlay-progress" style="display:none">
            <strong id="rt-progress-phase"></strong>
            <div class="progress mt-1">
                <div id="rt-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated"
                     role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
            </div>
            <small id="rt-progress-counter"></small>
            <small id="rt-progress-label"></small>
            <small id="rt-progress-elapsed"></small>
        </div>
    </div>
</div>

    <!-- Barra de status -->
    <div class="d-flex justify-content-between align-items-center mb-2">
        <small class="text-muted">
            Última atualização: <strong><?php echo $ultima_str; ?></strong>
            <span id="rt-total-badge"></span>
            <?php if ($is_gestor && !$is_admin && !$is_moodle_manager): ?>
                &nbsp;|&nbsp; <span class="badge badge-info">Visualização: seus colaboradores</span>
            <?php endif; ?>
        </small>
        <div>
            <button class="btn btn-sm btn-outline-secondary" type="button"
                    data-bs-toggle="collapse" data-bs-target="#rt-download-panel">
                <i class="fa fa-download"></i> Downloads
            </button>
            <button class="btn btn-sm btn-outline-secondary ml-1" type="button"
                    data-bs-toggle="collapse" data-bs-target="#rt-filter-panel">
                <i class="fa fa-filter"></i> Filtros
            </button>
        </div>
    </div>

    <!-- ── Painel de Downloads ── -->
    <div class="collapse mb-3" id="rt-download-panel">
        <div class="card"><div class="card-body py-3">
            <form id="rt-download-form" method="post" action="download.php">
                <input type="hidden" name="col_keys" id="rt-input-col-keys">
                <input type="hidden" name="filters"  id="rt-input-filters">
                <input type="hidden" name="formato"  id="rt-input-formato">
                <strong>Tabela filtrada:</strong>
                <button type="button" id="rt-btn-xlsx" class="btn btn-success btn-sm ml-2" onclick="rtStartSSEDownload('xlsx')">
                    <i class="fa fa-file-excel-o"></i> XLSX
                </button>
                <button type="button" class="btn btn-secondary btn-sm ml-1" onclick="rtSubmitDownload('csv')">
                    <i class="fa fa-file-text-o"></i> CSV
                </button>
            </form>
            <hr class="my-2">
            <form id="rt-zip-form" method="post" action="download.php" target="_blank">
                <input type="hidden" name="col_keys" id="rt-zip-col-keys">
                <input type="hidden" name="filters"  id="rt-zip-filters">
                <input type="hidden" name="formato"  value="zip">
                <div class="d-flex align-items-center flex-wrap" style="gap:8px">
                    <strong>Download ZIP agrupado por:</strong>
                    <select name="zip_group_field" class="form-control form-control-sm" style="width:220px">
                        <?php foreach ($zip_group_fields as $zkey => $zlabel): ?>
                            <option value="<?php echo s($zkey); ?>"><?php echo s($zlabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-primary btn-sm" onclick="var s=document.querySelector('#rt-zip-form [name=zip_group_field]');rtStartSSEDownload('zip',{zip_group_field:s.value,zip_group_label:s.options[s.selectedIndex].text})">
                        <i class="fa fa-file-archive-o"></i> Download ZIP
                    </button>
                </div>
            </form>
        </div></div>
    </div>

    <!-- ── Painel de Filtros ── -->
    <div class="collapse mb-3" id="rt-filter-panel">
        <div class="card">
            <div class="card-header py-2"><strong><i class="fa fa-filter"></i> Filtros</strong></div>
            <div class="card-body">
                <div class="row">
                    <?php foreach ($filter_fields as $field => $label): ?>
                    <div class="col-12 col-md-6 col-lg-4 col-xl-3 mb-2">
                        <label class="mb-0" style="font-size:12px; font-weight:600"><?php echo s($label); ?></label>
                        <select id="rt-filter-<?php echo $field; ?>"
                                class="form-control form-control-sm rt-filter-select"
                                data-filter-field="<?php echo $field; ?>">
                            <option value="">— Todos —</option>
                            <?php foreach ($filter_options[$field] ?? [] as $opt): ?>
                                <option value="<?php echo s($opt); ?>"><?php echo s($opt); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="d-flex gap-2 mt-1" style="gap:8px">
                    <button class="btn btn-primary btn-sm" id="rt-apply-filters">
                        <i class="fa fa-check"></i> Aplicar filtros
                    </button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="rtClearFilters()">
                        <i class="fa fa-times"></i> Limpar filtros
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Tabela ── -->
    <div class="table-responsive">
        <table id="rt-table" class="table table-bordered table-striped table-hover table-sm">
            <thead class="thead-dark">
                <tr>
                    <?php foreach ($all_columns as $key => $label): ?>
                        <th data-col-key="<?php echo $key; ?>"><?php echo s($label); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>

</div>

<!-- ── Floating column selector ── -->
<div id="rt-col-toggle">&#9776; Colunas</div>
<div id="rt-col-panel">
    <div id="rt-col-panel-header">
        <span>Colunas visíveis</span>
        <button id="rt-col-close" style="background:none;border:none;color:#fff;font-size:18px;cursor:pointer">&times;</button>
    </div>
    <div class="rt-col-panel-actions">
        <button class="btn btn-outline-secondary btn-sm" id="rt-col-select-all" title="Marcar todos">
            <i class="fa fa-check-square-o"></i>
        </button>
        <button class="btn btn-outline-secondary btn-sm" id="rt-col-select-default" title="Padrão">
            <i class="fa fa-sliders"></i>
        </button>
        <button class="btn btn-outline-secondary btn-sm" id="rt-col-unselect-all" title="Desmarcar">
            <i class="fa fa-square-o"></i>
        </button>
    </div>
    <div id="rt-col-panel-body">
        <?php foreach ($column_groups as $group_name => $group_keys): ?>
        <div class="rt-col-group-title"><?php echo s($group_name); ?></div>
        <?php foreach ($group_keys as $key): ?>
            <?php if (!isset($all_columns[$key])) continue; ?>
            <div>
                <label style="font-weight:normal; font-size:13px; cursor:pointer">
                    <input type="checkbox" class="rt-col-toggle-cb" data-col-key="<?php echo $key; ?>">
                    <?php echo s($all_columns[$key]); ?>
                </label>
            </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
</div>

<?php
// ── JavaScript: injetado via AMD para garantir que require() esteja disponível ──
$column_keys_json    = json_encode(array_keys($all_columns));
$default_visible_json = json_encode(array_values($default_visible));

$js = <<<JSCODE
// Carrega DataTables como globals (não AMD) para evitar conflito com o
// RequireJS do Moodle, que intercepta módulos como 'datatables.net' e
// serve arquivos errados via /lib/requirejs.php.
require(['jquery'], function(\$) {
    window.jQuery = \$;

    // Oculta temporariamente define/require do escopo global para forçar
    // o DataTables a carregar no modo global em vez de AMD.
    var _define  = window.define;
    var _require = window.require;
    window.define  = undefined;
    window.require = undefined;

    function loadScript(url, cb) {
        var s = document.createElement('script');
        s.src = url;
        s.onload = cb;
        document.head.appendChild(s);
    }

    loadScript('https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js', function() {
        loadScript('https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min.js', function() {
            // Restaura AMD após DataTables já registrado como global ($().DataTable)
            window.define  = _define;
            window.require = _require;
            initRT(\$);
        });
    });

function initRT(\$) {
    var columnKeys     = {$column_keys_json};
    var defaultVisible = {$default_visible_json};
    var ajaxUrl        = M.cfg.wwwroot + '/local/relatorio_treinamentos/ajax.php';
    var LS_KEY         = 'rt_visible_cols_v1';
    var activeFilters  = {};

    var columnsDef = columnKeys.map(function() {
        return { orderable: true, searchable: false, defaultContent: '' };
    });

    var table = \$('#rt-table').DataTable({
        processing:  true,
        serverSide:  true,
        scrollX:     true,
        pageLength:  25,
        lengthMenu:  [[10, 25, 50, 100], [10, 25, 50, 100]],
        columns:     columnsDef,
        dom: '<"row"<"col-sm-6"l><"col-sm-6"f>>rt<"row"<"col-sm-6"i><"col-sm-6"p>>',
        language: {
            lengthMenu:   'Mostrar _MENU_ registros',
            zeroRecords:  'Nenhum registro encontrado',
            info:         'Mostrando _START_ a _END_ de _TOTAL_ registros',
            infoEmpty:    'Mostrando 0 a 0 de 0 registros',
            infoFiltered: '(filtrado de _MAX_ registros)',
            search:       'Buscar:',
            processing:   'Carregando...',
            paginate: { first: 'Primeiro', last: 'Último', next: 'Próximo', previous: 'Anterior' }
        },
        ajax: {
            url:  ajaxUrl,
            type: 'POST',
            data: function(d) {
                d.sesskey = M.cfg.sesskey;
                d.filters = JSON.stringify(activeFilters);
                return d;
            },
            error: function(xhr, err) {
                alert('Erro ao carregar dados: ' + err);
            }
        },
        drawCallback: function() {
            var total = this.api().page.info().recordsTotal;
            \$('#rt-total-badge').html(
                '&nbsp;|&nbsp; <strong>' + total.toLocaleString('pt-BR') + '</strong> registros'
            );
        }
    });

    // ── Visibilidade de colunas ───────────────────────────────────────────────
    var savedCols = null;
    try { savedCols = JSON.parse(localStorage.getItem(LS_KEY)); } catch(e) {}
    var visibleCols = savedCols || defaultVisible.slice();

    function applyColVisibility(keys) {
        var last = columnKeys.length - 1;
        columnKeys.forEach(function(key, idx) {
            // false = não recalcular/redesenhar a cada coluna; true só no último
            table.column(idx).visible(keys.indexOf(key) !== -1, idx === last);
        });
        document.querySelectorAll('.rt-col-toggle-cb').forEach(function(cb) {
            cb.checked = keys.indexOf(cb.dataset.colKey) !== -1;
        });
    }
    function saveAndApply(keys) {
        visibleCols = keys;
        try { localStorage.setItem(LS_KEY, JSON.stringify(keys)); } catch(e) {}
        rtShowOverlay('Atualizando colunas...');
        setTimeout(function() {
            applyColVisibility(keys);
            rtHideOverlay();
        }, 0);
    }
    applyColVisibility(visibleCols);

    document.querySelectorAll('.rt-col-toggle-cb').forEach(function(cb) {
        cb.addEventListener('change', function() {
            var key = this.dataset.colKey;
            if (this.checked) { if (visibleCols.indexOf(key) === -1) visibleCols.push(key); }
            else { visibleCols = visibleCols.filter(function(k) { return k !== key; }); }
            saveAndApply(visibleCols);
        });
    });

    document.getElementById('rt-col-select-all').addEventListener('click', function() { saveAndApply(columnKeys.slice()); });
    document.getElementById('rt-col-select-default').addEventListener('click', function() { saveAndApply(defaultVisible.slice()); });
    document.getElementById('rt-col-unselect-all').addEventListener('click', function() { saveAndApply([]); });

    var colToggleBtn = document.getElementById('rt-col-toggle');
    var colPanel     = document.getElementById('rt-col-panel');
    colToggleBtn.addEventListener('click', function() {
        colPanel.classList.add('open');
        colToggleBtn.style.display = 'none';
    });
    document.getElementById('rt-col-close').addEventListener('click', function() {
        colPanel.classList.remove('open');
        colToggleBtn.style.display = '';
    });

    // ── Filtros customizados ──────────────────────────────────────────────────
    document.getElementById('rt-apply-filters').addEventListener('click', function() {
        activeFilters = {};
        document.querySelectorAll('.rt-filter-select').forEach(function(sel) {
            if (sel.value) { activeFilters[sel.dataset.filterField] = sel.value; }
        });
        table.ajax.reload();
    });
    window.rtClearFilters = function() {
        activeFilters = {};
        document.querySelectorAll('.rt-filter-select').forEach(function(s) { s.value = ''; });
        table.ajax.reload();
    };

    // ── Downloads ─────────────────────────────────────────────────────────────
    /** CSV: envio direto via form submit com overlay de feedback */
    window.rtSubmitDownload = function(formato) {
        document.getElementById('rt-input-col-keys').value = JSON.stringify(visibleCols);
        document.getElementById('rt-input-filters').value  = JSON.stringify(activeFilters);
        document.getElementById('rt-input-formato').value  = formato;
        rtShowOverlay('Gerando CSV...');
        document.getElementById('rt-download-form').submit();
        setTimeout(rtHideOverlay, 4000);
    };

    /** XLSX / ZIP: geração com SSE + barra de progresso + auto-download */
    window.rtStartSSEDownload = function(formato, extraParams) {
        var params = new URLSearchParams({
            formato:  formato,
            col_keys: JSON.stringify(visibleCols),
            filters:  JSON.stringify(activeFilters),
        });
        var groupLabel = '';
        var startTime = Date.now();
        if (extraParams) {
            Object.keys(extraParams).forEach(function(k) {
                if (k === 'zip_group_label') { groupLabel = extraParams[k]; }
                else { params.set(k, extraParams[k]); }
            });
        }
        var isZip = (formato === 'zip');
        rtShowOverlay(isZip ? 'Gerando ZIP...' : 'Gerando XLSX...', isZip);

        var url = M.cfg.wwwroot + '/local/relatorio_treinamentos/generate.php?' + params.toString();
        var es  = new EventSource(url);

        es.onmessage = function(evt) {
            var data;
            try { data = JSON.parse(evt.data); } catch(e) { return; }

            if (data.error) {
                es.close();
                rtHideOverlay();
                alert('Erro ao gerar arquivo: ' + data.error);
                return;
            }
            if (data.done) {
                es.close();
                rtHideOverlay();
                var a = document.createElement('a');
                a.href = M.cfg.wwwroot + '/local/relatorio_treinamentos/serve.php?token=' + data.token;
                a.download = data.filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                return;
            }
            if (data.total) {
                var itemLabel = groupLabel ? groupLabel + ': ' + (data.label || '') : (data.label || '');
                var secs = Math.floor((Date.now() - startTime) / 1000);
                var elapsed = Math.floor(secs / 60) + ':' + ('0' + (secs % 60)).slice(-2);
                rtShowProgress(data.step, data.total, itemLabel, data.phase || '', elapsed);
            }
        };
        es.onerror = function() {
            es.close();
            rtHideOverlay();
            alert('Erro de conexão ao gerar arquivo. Tente novamente.');
        };
    };

    // ── Loading Overlay ──────────────────────────────────────────────────────
    var rtOverlay = document.getElementById('rt-overlay');
    window.rtShowOverlay = function(msg, withProgress) {
        document.getElementById('rt-overlay-msg').textContent = msg || 'Carregando...';
        document.getElementById('rt-overlay-progress').style.display = withProgress ? '' : 'none';
        if (withProgress) {
            var bar = document.getElementById('rt-progress-bar');
            bar.style.width = '0%';
            bar.textContent = '0%';
            document.getElementById('rt-progress-phase').textContent = '';
            document.getElementById('rt-progress-counter').textContent = '';
            document.getElementById('rt-progress-label').textContent = '';
            document.getElementById('rt-progress-elapsed').textContent = '';
        }
        rtOverlay.style.display = 'block';
    };
    window.rtHideOverlay = function() {
        rtOverlay.style.display = 'none';
    };
    window.rtShowProgress = function(step, total, label, phase, elapsed) {
        var pct = total > 0 ? Math.round(step * 100 / total) : 0;
        var bar = document.getElementById('rt-progress-bar');
        bar.style.width = pct + '%';
        bar.setAttribute('aria-valuenow', pct);
        bar.textContent = pct + '%';
        if (phase === 'csv') {
            document.getElementById('rt-progress-phase').textContent = 'Extraindo dados';
        } else if (phase === 'xlsx') {
            document.getElementById('rt-progress-phase').textContent = 'Gerando planilhas';
        }
        document.getElementById('rt-progress-counter').textContent = step + ' / ' + total;
        document.getElementById('rt-overlay-msg').textContent = '';
        document.getElementById('rt-progress-label').textContent = label;
        if (elapsed !== undefined) {
            document.getElementById('rt-progress-elapsed').textContent = 'Tempo: ' + elapsed;
        }
    };
    table.on('processing', function(e, settings, processing) {
        if (processing) { rtShowOverlay('Carregando...', false); }
        else            { rtHideOverlay(); }
    });

} // initRT
}); // require jquery
JSCODE;

$PAGE->requires->js_amd_inline($js);

echo $OUTPUT->footer();

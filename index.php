<?php
require_once(__DIR__ . '/../../config.php');

require_login();

// ── Controle de acesso ────────────────────────────────────────────────────────
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
    throw new moodle_exception('noaccess', 'local_relatorio_treinamentos');
}

// ── Page setup ────────────────────────────────────────────────────────────────
$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/relatorio_treinamentos/index.php'));
$PAGE->set_title('Relatório de Treinamentos');
$PAGE->set_heading('Relatório de Treinamentos');
$PAGE->set_pagelayout('admin');

// ── Metadados do cache (leve — sem carregar os 236k registros) ────────────────
$cache              = \cache::make('local_relatorio_treinamentos', 'relatorio');
$ultima_atualizacao = $cache->get('ultima_atualizacao');
$filter_options     = $cache->get('filter_options') ?: [];

$ultima_str = $ultima_atualizacao
    ? userdate($ultima_atualizacao, get_string('strftimedatetimeshort', 'langconfig'))
    : 'N/A';

// ── Definições de colunas ─────────────────────────────────────────────────────
$all_columns      = \local_relatorio_treinamentos\helper\columns::get_all();
$column_groups    = \local_relatorio_treinamentos\helper\columns::get_groups();
$filter_fields    = \local_relatorio_treinamentos\helper\columns::get_filter_fields();
$zip_group_fields = \local_relatorio_treinamentos\helper\columns::get_zip_group_fields();

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
    display: none; position: fixed; left: 26px; top: 0; bottom: 0;
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
    background: rgba(255,255,255,.9);
    border: 1px solid #dee2e6;
    border-radius: 4px;
    padding: 12px 20px;
    font-weight: bold;
    color: #343a40;
}
</style>

<div class="container-fluid mt-3">

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
                    data-toggle="collapse" data-target="#rt-download-panel">
                <i class="fa fa-download"></i> Downloads
            </button>
            <button class="btn btn-sm btn-outline-secondary ml-1" type="button"
                    data-toggle="collapse" data-target="#rt-filter-panel">
                <i class="fa fa-filter"></i> Filtros
            </button>
        </div>
    </div>

    <!-- ── Painel de Downloads ── -->
    <div class="collapse mb-3" id="rt-download-panel">
        <div class="card"><div class="card-body py-3">
            <form id="rt-download-form" method="post" action="download.php" target="_blank">
                <input type="hidden" name="col_keys" id="rt-input-col-keys">
                <input type="hidden" name="filters"  id="rt-input-filters">
                <input type="hidden" name="formato"  id="rt-input-formato">
                <strong>Tabela filtrada:</strong>
                <button type="button" class="btn btn-success btn-sm ml-2" onclick="rtSubmitDownload('xlsx')">
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
                    <button type="button" class="btn btn-primary btn-sm" onclick="rtFillZipForm(); document.getElementById('rt-zip-form').submit();">
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
                <button class="btn btn-outline-secondary btn-sm mt-1" onclick="rtClearFilters()">
                    <i class="fa fa-times"></i> Limpar filtros
                </button>
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
        <button class="btn btn-outline-secondary btn-sm" id="rt-col-select-all">Marcar todos</button>
        <button class="btn btn-outline-secondary btn-sm" id="rt-col-select-default">Padrão</button>
        <button class="btn btn-outline-secondary btn-sm" id="rt-col-unselect-all">Desmarcar</button>
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
// Registra DataTables como módulos nomeados no RequireJS para evitar
// o erro "Mismatched anonymous define()" ao carregar UMD libs externas.
require.config({
    paths: {
        'datatables-core': 'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min',
        'datatables-bs4':  'https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap4.min'
    },
    shim: {
        'datatables-bs4': { deps: ['datatables-core'] }
    }
});

require(['jquery', 'datatables-core', 'datatables-bs4'], function(\$) {
    window.jQuery = \$;
    initRT(\$);

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
        columnKeys.forEach(function(key, idx) {
            table.column(idx).visible(keys.indexOf(key) !== -1);
        });
        document.querySelectorAll('.rt-col-toggle-cb').forEach(function(cb) {
            cb.checked = keys.indexOf(cb.dataset.colKey) !== -1;
        });
    }
    function saveAndApply(keys) {
        visibleCols = keys;
        try { localStorage.setItem(LS_KEY, JSON.stringify(keys)); } catch(e) {}
        applyColVisibility(keys);
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
    document.getElementById('rt-col-toggle').addEventListener('click', function() {
        document.getElementById('rt-col-panel').classList.toggle('open');
    });
    document.getElementById('rt-col-close').addEventListener('click', function() {
        document.getElementById('rt-col-panel').classList.remove('open');
    });

    // ── Filtros customizados ──────────────────────────────────────────────────
    document.querySelectorAll('.rt-filter-select').forEach(function(sel) {
        sel.addEventListener('change', function() {
            var f = this.dataset.filterField;
            if (this.value) { activeFilters[f] = this.value; }
            else { delete activeFilters[f]; }
            table.ajax.reload();
        });
    });
    window.rtClearFilters = function() {
        activeFilters = {};
        document.querySelectorAll('.rt-filter-select').forEach(function(s) { s.value = ''; });
        table.ajax.reload();
    };

    // ── Downloads ─────────────────────────────────────────────────────────────
    window.rtSubmitDownload = function(formato) {
        document.getElementById('rt-input-col-keys').value = JSON.stringify(visibleCols);
        document.getElementById('rt-input-filters').value  = JSON.stringify(activeFilters);
        document.getElementById('rt-input-formato').value  = formato;
        document.getElementById('rt-download-form').submit();
    };
    window.rtFillZipForm = function() {
        document.getElementById('rt-zip-col-keys').value = JSON.stringify(visibleCols);
        document.getElementById('rt-zip-filters').value  = JSON.stringify(activeFilters);
    };

} // initRT
}); // require jquery/datatables
JSCODE;

$PAGE->requires->js_amd_inline($js);

echo $OUTPUT->footer();

<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    // ── Classe multiselect para admin settings ────────────────────────────────
    /**
     * Admin setting que renderiza <select multiple> em vez de checkboxes.
     * Armazena como string separada por vírgulas (compatível com configmulticheckbox).
     */
    if (!class_exists('local_rt_admin_multiselect')) {
    class local_rt_admin_multiselect extends admin_setting_configmulticheckbox {

        public function output_html($data, $query = '') {
            // $data vem de get_setting() como ['chave' => 1, ...] ou null
            $selected = is_array($data) ? array_keys(array_filter($data)) : [];
            $fullname  = $this->get_full_name();
            $id        = 'id_' . preg_replace('/[^a-z0-9_]/', '_', strtolower($fullname));
            $rows      = min(max(count($this->choices), 6), 14);

            $html  = '<select multiple id="' . s($id) . '" name="' . s($fullname) . '[]" ';
            $html .= 'class="form-control" size="' . $rows . '" style="min-width:300px;max-width:100%">';
            foreach ($this->choices as $key => $label) {
                $sel   = in_array($key, $selected) ? ' selected' : '';
                $html .= '<option value="' . s($key) . '"' . $sel . '>' . s($label) . '</option>';
            }
            $html .= '</select>';
            $html .= '<small class="form-text text-muted mt-1">'
                   . 'Segure <kbd>Ctrl</kbd> (Windows/Linux) ou <kbd>Cmd</kbd> (Mac) para selecionar múltiplos itens.'
                   . '</small>';

            return format_admin_setting($this, $this->visiblename, $html,
                $this->description, $id, '', null, $query);
        }

        public function write_setting($data) {
            if (!is_array($data)) {
                return '';
            }
            $result = [];
            foreach ($data as $key => $value) {
                if (is_int($key)) {
                    // Vem do <select multiple> — array numérico de valores
                    if ($value !== '' && array_key_exists($value, $this->choices)) {
                        $result[] = $value;
                    }
                } else {
                    // Retrocompatibilidade com configmulticheckbox — array associativo
                    if (!empty($value) && array_key_exists($key, $this->choices)) {
                        $result[] = $key;
                    }
                }
            }
            return $this->config_write($this->name, implode(',', $result))
                ? ''
                : get_string('errorsetting', 'admin');
        }
    }

    } // end if (!class_exists)

    if (!class_exists('local_rt_admin_dual_column')) {
    class local_rt_admin_dual_column extends admin_setting {
        private $choices;

        public function __construct($name, $visiblename, $description, $defaultsetting, $choices) {
            $this->choices = $choices;
            $default_csv = is_array($defaultsetting)
                ? implode(',', array_keys(array_filter($defaultsetting)))
                : (string)$defaultsetting;
            parent::__construct($name, $visiblename, $description, $default_csv);
        }

        public function get_setting() {
            $result = $this->config_read($this->name);
            return is_null($result) ? $this->defaultsetting : $result;
        }

        public function write_setting($data) {
            if (!is_array($data)) return get_string('errorsetting', 'admin');
            $result = [];
            foreach ($data as $value) {
                if ($value !== '' && array_key_exists($value, $this->choices)) {
                    $result[] = $value;
                }
            }
            if (count($result) < 2) return 'Selecione pelo menos 2 colunas para o relatório.';
            return $this->config_write($this->name, implode(',', $result))
                ? '' : get_string('errorsetting', 'admin');
        }

        public function output_html($data, $query = '') {
            $selected_keys = [];
            if ($data !== null && $data !== '') {
                foreach (explode(',', $data) as $k) {
                    $k = trim($k);
                    if ($k !== '' && isset($this->choices[$k])) $selected_keys[] = $k;
                }
            }
            $selected_set = array_flip($selected_keys);

            $fullname = $this->get_full_name();
            $safe_id  = 'rt_dc_' . preg_replace('/[^a-z0-9]/', '_', strtolower($this->name));
            $left_id  = $safe_id . '_left';
            $right_id = $safe_id . '_right';
            $size     = min(max(count($this->choices), 8), 18);

            $left_html = '';
            foreach ($this->choices as $key => $label) {
                if (!isset($selected_set[$key])) {
                    $left_html .= '<option value="' . s($key) . '">' . s($label) . '</option>' . "\n";
                }
            }
            $right_html = '';
            foreach ($selected_keys as $key) {
                $right_html .= '<option value="' . s($key) . '">' . s($this->choices[$key]) . '</option>' . "\n";
            }

            $lid  = s($left_id);
            $rid  = s($right_id);
            $fn   = s($fullname);
            $lj   = json_encode($left_id);
            $rj   = json_encode($right_id);

            $html = <<<HTML
<div style="display:flex;gap:6px;align-items:flex-start;flex-wrap:wrap">
  <div>
    <div style="font-size:11px;font-weight:600;color:#6c757d;margin-bottom:3px">Disponíveis</div>
    <select multiple id="{$lid}" class="form-control rt-dc-left" style="min-width:200px;max-width:260px" size="{$size}">
{$left_html}    </select>
  </div>
  <div style="display:flex;flex-direction:column;justify-content:center;gap:4px;padding-top:18px">
    <button type="button" class="btn btn-sm btn-outline-primary" onclick="rtDcAdd('{$left_id}','{$right_id}')" title="Adicionar ao relatório">&rsaquo;</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="rtDcRemove('{$left_id}','{$right_id}')" title="Remover do relatório">&lsaquo;</button>
  </div>
  <div>
    <div style="font-size:11px;font-weight:600;color:#6c757d;margin-bottom:3px">No relatório <small class="text-muted">(em ordem)</small></div>
    <select multiple name="{$fn}[]" id="{$rid}" class="form-control rt-dc-right" style="min-width:200px;max-width:260px" size="{$size}">
{$right_html}    </select>
  </div>
  <div style="display:flex;flex-direction:column;justify-content:center;gap:4px;padding-top:18px">
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="rtDcUp('{$right_id}')" title="Mover para cima">&#9650;</button>
    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="rtDcDown('{$right_id}')" title="Mover para baixo">&#9660;</button>
  </div>
</div>
<small class="form-text text-muted mt-1">M&iacute;nimo 2 colunas na lista do relat&oacute;rio. A ordem define a ordem padr&atilde;o das colunas.</small>
HTML;

            static $js_done = false;
            if (!$js_done) {
                $js_done = true;
                $html .= <<<'JSCODE'
<script>
if (!window.rtDcAdd) {
    window.rtDcAdd = function(lid, rid) {
        var l = document.getElementById(lid), r = document.getElementById(rid);
        Array.from(l.selectedOptions).forEach(function(o) { r.appendChild(o.cloneNode(true)); o.remove(); });
        Array.from(r.options).forEach(function(o) { o.selected = true; });
    };
    window.rtDcRemove = function(lid, rid) {
        var l = document.getElementById(lid), r = document.getElementById(rid);
        Array.from(r.selectedOptions).forEach(function(o) { l.appendChild(o.cloneNode(true)); o.remove(); });
        Array.from(r.options).forEach(function(o) { o.selected = true; });
    };
    window.rtDcUp = function(rid) {
        var r = document.getElementById(rid), opts = Array.from(r.options);
        for (var i = 1; i < opts.length; i++) {
            if (opts[i].selected && !opts[i-1].selected) r.insertBefore(opts[i], opts[i-1]);
        }
        Array.from(r.options).forEach(function(o) { o.selected = true; });
    };
    window.rtDcDown = function(rid) {
        var r = document.getElementById(rid), opts = Array.from(r.options);
        for (var i = opts.length - 2; i >= 0; i--) {
            if (opts[i].selected && !opts[i+1].selected) r.insertBefore(opts[i+1], opts[i]);
        }
        Array.from(r.options).forEach(function(o) { o.selected = true; });
    };
    document.addEventListener('DOMContentLoaded', function() {
        var form = document.getElementById('adminsettings');
        if (!form) return;
        form.addEventListener('submit', function() {
            document.querySelectorAll('.rt-dc-right').forEach(function(s) {
                Array.from(s.options).forEach(function(o) { o.selected = true; });
            });
        });
    });
}
</script>
JSCODE;
            }

            return format_admin_setting($this, $this->visiblename, $html,
                $this->description, $right_id, '', null, $query);
        }
    }
    } // end if (!class_exists)

    // ── Página de settings ────────────────────────────────────────────────────
    $settings = new admin_settingpage(
        'local_relatorio_treinamentos',
        get_string('pluginname', 'local_relatorio_treinamentos')
    );

    $all_columns   = \local_relatorio_treinamentos\helper\columns::get_all();
    $default_cols  = \local_relatorio_treinamentos\helper\columns::get_default();
    $default_value = array_fill_keys($default_cols, 1);

    // ── Aviso: Python não configurado ────────────────────────────────────────
    if (!get_config('core', 'pathtopython') || !is_executable(get_config('core', 'pathtopython'))) {
        $settings->add(new admin_setting_heading(
            'local_relatorio_treinamentos/python_warning',
            '',
            html_writer::tag('div',
                get_string('setting_python_warning', 'local_relatorio_treinamentos'),
                ['class' => 'alert alert-warning']
            )
        ));
    }

    // ── 1. Colunas visíveis por padrão ────────────────────────────────────────
    $settings->add(new local_rt_admin_dual_column(
        'local_relatorio_treinamentos/colunas_visiveis',
        get_string('setting_colunas_visiveis', 'local_relatorio_treinamentos'),
        get_string('setting_colunas_visiveis_desc', 'local_relatorio_treinamentos'),
        $default_value,
        array_map('htmlspecialchars_decode', $all_columns)
    ));

    // ── 2. Estratégia de dados ────────────────────────────────────────────────
    $settings->add(new admin_setting_configselect(
        'local_relatorio_treinamentos/estrategia',
        'Estratégia de dados',
        'Define como os dados do relatório são obtidos. '
        . '<b>Consulta direta:</b> executa o SQL a cada requisição (mais lento, sem setup). '
        . '<b>Cache:</b> usa o cache gerado pela task de 02h (rápido, 4 GB RAM). '
        . '<b>View materializada:</b> consulta a view pré-computada com índices (mais rápido, recomendado).',
        'view',
        [
            'direct' => 'Consulta direta (SQL a cada requisição)',
            'cache'  => 'Cache da task agendada (array PHP)',
            'view'   => 'View materializada (recomendado)',
        ]
    ));

    // ── 3. Filtros disponíveis no relatório ───────────────────────────────────
    // Filtros: default = campos pré-definidos, opções = todas as 57 colunas
    $default_filter_keys = array_keys(\local_relatorio_treinamentos\helper\columns::get_filter_fields());
    $default_filter_val  = array_fill_keys($default_filter_keys, 1);

    $settings->add(new local_rt_admin_multiselect(
        'local_relatorio_treinamentos/filtros_visiveis',
        get_string('setting_filtros_visiveis', 'local_relatorio_treinamentos'),
        get_string('setting_filtros_visiveis_desc', 'local_relatorio_treinamentos'),
        $default_filter_val,
        array_map('htmlspecialchars_decode', $all_columns)
    ));

    // ── 4. Agrupamentos disponíveis para download ZIP ─────────────────────────
    // Agrupamentos ZIP: default = campos pré-definidos, opções = todas as 57 colunas
    $default_zip_keys = array_keys(\local_relatorio_treinamentos\helper\columns::get_zip_group_fields());
    $default_zip_val  = array_fill_keys($default_zip_keys, 1);

    $settings->add(new local_rt_admin_multiselect(
        'local_relatorio_treinamentos/agrupamentos_zip',
        get_string('setting_agrupamentos_zip', 'local_relatorio_treinamentos'),
        get_string('setting_agrupamentos_zip_desc', 'local_relatorio_treinamentos'),
        $default_zip_val,
        array_map('htmlspecialchars_decode', $all_columns)
    ));


    // ── 5. Configurações de Gestores ─────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'local_relatorio_treinamentos/gestor_heading',
        'Configurações de Gestores',
        'Define como o sistema identifica usuários gestores e quais filtros eles podem usar.'
    ));

    // Campo de perfil de usuário que identifica gestores
    $user_profile_fields_raw = $DB->get_records('user_info_field', [], 'sortorder', 'id, shortname, name');
    $campo_options = ['0' => '(usar padrão: código de cargo, fieldid=18)'];
    foreach ($user_profile_fields_raw as $upf) {
        $campo_options[$upf->shortname] = $upf->name . ' [' . $upf->shortname . ']';
    }

    $settings->add(new admin_setting_configselect(
        'local_relatorio_treinamentos/gestor_campo_perfil',
        'Campo de perfil que identifica gestores',
        'Selecione qual campo de perfil de usuário determina se o usuário é um gestor. '
        . 'Se não selecionado, usa o comportamento padrão (campo de código de cargo, fieldid=18).',
        '0',
        $campo_options
    ));

    // Valores que o campo deve ter para o usuário ser considerado gestor
    $settings->add(new admin_setting_configtext(
        'local_relatorio_treinamentos/gestor_campo_valores',
        'Valores que identificam gestores',
        'Informe os valores separados por vírgula que o campo selecionado deve ter para que o usuário seja considerado um gestor. '
        . 'Exemplo: <code>011,045,050,062</code>. Espaços em torno das vírgulas são ignorados.',
        '011,045,050,062',
        PARAM_TEXT,
        40
    ));

    // Filtros disponíveis para gestores
    $default_filter_gestor_keys = array_keys(\local_relatorio_treinamentos\helper\columns::get_filter_fields());
    $default_filter_gestor_val  = array_fill_keys($default_filter_gestor_keys, 1);

    $settings->add(new local_rt_admin_multiselect(
        'local_relatorio_treinamentos/filtros_visiveis_gestor',
        get_string('setting_filtros_visiveis_gestor', 'local_relatorio_treinamentos'),
        get_string('setting_filtros_visiveis_gestor_desc', 'local_relatorio_treinamentos'),
        $default_filter_gestor_val,
        array_map('htmlspecialchars_decode', $all_columns)
    ));

    // ── 6. Templates XLSX ─────────────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'local_relatorio_treinamentos/templates_heading',
        'Download por Template XLSX',
        'Faça upload de arquivos XLSX com marcadores <code>{nome_coluna}</code> que serão preenchidos com os dados do relatório.'
    ));

    $settings->add(new admin_setting_configstoredfile(
        'local_relatorio_treinamentos/templates_xlsx',
        'Templates XLSX',
        'Arquivos template XLSX. Cada célula com <code>{nome_coluna}</code> marca o início de uma coluna de dados.',
        'templates',
        0,
        ['maxfiles' => -1, 'accepted_types' => ['.xlsx']]
    ));

    $ADMIN->add('localplugins', $settings);
}

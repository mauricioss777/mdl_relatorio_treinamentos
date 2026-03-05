<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    // ── Classe multiselect para admin settings ────────────────────────────────
    /**
     * Admin setting que renderiza <select multiple> em vez de checkboxes.
     * Armazena como string separada por vírgulas (compatível com configmulticheckbox).
     */
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

    // ── Página de settings ────────────────────────────────────────────────────
    $settings = new admin_settingpage(
        'local_relatorio_treinamentos',
        get_string('pluginname', 'local_relatorio_treinamentos')
    );

    $all_columns   = \local_relatorio_treinamentos\helper\columns::get_all();
    $default_cols  = \local_relatorio_treinamentos\helper\columns::get_default();
    $default_value = array_fill_keys($default_cols, 1);

    // ── 1. Colunas visíveis por padrão ────────────────────────────────────────
    $settings->add(new local_rt_admin_multiselect(
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
    $all_filter_fields   = \local_relatorio_treinamentos\helper\columns::get_filter_fields();
    $default_filter_val  = array_fill_keys(array_keys($all_filter_fields), 1);

    $settings->add(new local_rt_admin_multiselect(
        'local_relatorio_treinamentos/filtros_visiveis',
        get_string('setting_filtros_visiveis', 'local_relatorio_treinamentos'),
        get_string('setting_filtros_visiveis_desc', 'local_relatorio_treinamentos'),
        $default_filter_val,
        array_map('htmlspecialchars_decode', $all_filter_fields)
    ));

    // ── 4. Agrupamentos disponíveis para download ZIP ─────────────────────────
    $all_zip_fields    = \local_relatorio_treinamentos\helper\columns::get_zip_group_fields();
    $default_zip_val   = array_fill_keys(array_keys($all_zip_fields), 1);

    $settings->add(new local_rt_admin_multiselect(
        'local_relatorio_treinamentos/agrupamentos_zip',
        get_string('setting_agrupamentos_zip', 'local_relatorio_treinamentos'),
        get_string('setting_agrupamentos_zip_desc', 'local_relatorio_treinamentos'),
        $default_zip_val,
        array_map('htmlspecialchars_decode', $all_zip_fields)
    ));

    $ADMIN->add('localplugins', $settings);
}

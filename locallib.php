<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Cria (se não existir) a categoria e o campo personalizado de curso
 * 'rt_incluir_filtro' usado para controlar quais cursos aparecem no filtro
 * do Relatório de Treinamentos.
 */
function local_relatorio_treinamentos_create_customfield($DB) {
    // Já existe?
    if ($DB->record_exists('customfield_field', ['shortname' => 'rt_incluir_filtro'])) {
        return;
    }

    $context = context_system::instance();

    // Categoria
    $catid = $DB->get_field('customfield_category', 'id', [
        'name'      => 'Relatório de Treinamentos',
        'component' => 'core_course',
        'area'      => 'course',
    ]);
    if (!$catid) {
        $catid = $DB->insert_record('customfield_category', (object)[
            'name'         => 'Relatório de Treinamentos',
            'component'    => 'core_course',
            'area'         => 'course',
            'itemid'       => 0,
            'contextid'    => $context->id,
            'sortorder'    => 1000,
            'timecreated'  => time(),
            'timemodified' => time(),
        ]);
    }

    // Campo checkbox
    $DB->insert_record('customfield_field', (object)[
        'shortname'         => 'rt_incluir_filtro',
        'name'              => 'Incluir no filtro do relatório',
        'type'              => 'checkbox',
        'description'       => 'Quando marcado, este curso aparece no filtro de curso do Relatório de Treinamentos.',
        'descriptionformat' => FORMAT_HTML,
        'sortorder'         => 1,
        'categoryid'        => $catid,
        'configdata'        => json_encode([
            'required'       => '0',
            'uniquevalues'   => '0',
            'checkbydefault' => '0',
            'locked'         => '0',
            'visibility'     => '2', // visível para todos
        ]),
        'timecreated'  => time(),
        'timemodified' => time(),
    ]);
}

/**
 * Retorna o nome completo (com prefixo) da view materializada.
 */
function local_relatorio_treinamentos_get_view_name() {
    global $CFG;
    return $CFG->prefix . 'local_rt_relatorio';
}

/**
 * Cria a view materializada e todos os índices necessários.
 * Idempotente: não faz nada se a view já existir.
 */
function local_relatorio_treinamentos_setup_matview($DB) {
    $view = local_relatorio_treinamentos_get_view_name();

    // Verifica se já existe
    $exists = $DB->count_records_sql(
        "SELECT COUNT(*) FROM pg_matviews WHERE matviewname = ?",
        [$view]
    );
    if ($exists) {
        return;
    }

    // Obtém o SQL de relatório e cria a view
    $select_sql = local_relatorio_treinamentos_get_report_sql();
    // Remove ORDER BY final para a view (definição não garante ordem de armazenamento)
    $select_sql = preg_replace('/\s+ORDER BY prof_nome_filial.*$/s', '', $select_sql);
    $DB->execute("CREATE MATERIALIZED VIEW $view AS $select_sql WITH DATA");

    // ── Índices ────────────────────────────────────────────────────────────
    // Unique: necessário para REFRESH CONCURRENTLY no futuro
    $DB->execute("CREATE UNIQUE INDEX ON $view (row_key)");
    // Campos de filtro
    $DB->execute("CREATE INDEX ON $view (prof_nome_filial)");
    $DB->execute("CREATE INDEX ON $view (nome_curso)");
    $DB->execute("CREATE INDEX ON $view (concluido)");
    $DB->execute("CREATE INDEX ON $view (gestor)");
    $DB->execute("CREATE INDEX ON $view (prof_diretor)");
    $DB->execute("CREATE INDEX ON $view (prof_gerente_regional)");
    $DB->execute("CREATE INDEX ON $view (prof_gerente_distrital)");
    // Ordenação padrão
    $DB->execute("CREATE INDEX ON $view (bas_nome_funcionario)");
    $DB->execute("CREATE INDEX ON $view (userid)");
}

/**
 * Atualiza os dados da view materializada.
 */
function local_relatorio_treinamentos_refresh_matview($DB) {
    $view = local_relatorio_treinamentos_get_view_name();
    $DB->execute("REFRESH MATERIALIZED VIEW $view");
}


/**
 * Retorna o SELECT SQL completo do relatório (com notação {tablename} do Moodle).
 * Usa NOWDOC para evitar problemas com aspas simples no SQL.
 */
function local_relatorio_treinamentos_get_report_sql() {
    return <<<'RTSQL'
        SELECT
            CONCAT(u.id::text, '_', c.id::text)            AS row_key,
            u.id                                            AS userid,
            u.username                                      AS bas_usuario,
            CONCAT(u.firstname, ' ', u.lastname)            AS bas_nome_funcionario,
            u.email                                         AS bas_email,
            u.city                                          AS bas_cidade,
            u.idnumber                                      AS opc_numero_identificacao,
            u.institution                                   AS opc_instituicao,
            u.department                                    AS opc_departamento,
            info_cpf.data                                   AS dp_cpf,
            info_cidade.data                                AS dp_cidade_colaborador,
            info_uf.data                                    AS dp_uf,
            to_timestamp(NULLIF(NULLIF(info_nascimento.data, ''), '0')::bigint) AS dp_data_nascimento,
            info_sexo.data                                  AS dp_sexo,
            info_sid.data                                   AS dp_sid,
            info_tipo.data                                  AS prof_tipo,
            info_tipo_desc.data                             AS prof_descricao_tipo,
            info_empresa.data                               AS prof_numero_empresa,
            info_empresa_nome.data                          AS prof_nome_empresa,
            info_filial.data                                AS prof_codigo_filial,
            info_filial_desc.data                           AS prof_nome_filial,
            info_distrito.data                              AS prof_numero_distrito,
            info_distrito_nome.data                         AS prof_nome_distrito,
            info_bandeira.data                              AS prof_bandeira,
            info_local.data                                 AS prof_numero_local,
            info_local_nome.data                            AS prof_nome_local,
            to_timestamp(NULLIF(NULLIF(info_admissao.data, ''), '0')::bigint) AS prof_data_admissao,
            to_timestamp(NULLIF(NULLIF(info_demissao.data, ''), '0')::bigint) AS prof_data_demissao,
            info_cargo.data                                 AS prof_codigo_cargo,
            info_cargo_nome.data                            AS prof_cargo,
            info_grau.data                                  AS prof_codigo_grau_instrucao,
            info_grau_nome.data                             AS prof_grau_instrucao,
            info_hierarquia.data                            AS prof_hierarquia,
            info_posicao.data                               AS prof_posicao,
            info_diretor.data                               AS prof_diretor,
            info_cargo_diretor.data                         AS prof_cargo_diretor,
            info_diretor_hierarquia.data                    AS prof_hierarquia_diretor,
            info_diretor_posicao.data                       AS prof_posicao_diretor,
            info_gerente_regional.data                      AS prof_gerente_regional,
            info_cargo_regional.data                        AS prof_cargo_regional,
            info_regional_hierarquia.data                   AS prof_hierarquia_regional,
            info_posicao_regional.data                      AS prof_posicao_regional,
            info_gerente_distrital.data                     AS prof_gerente_distrital,
            info_cargo_distrital.data                       AS prof_cargo_distrital,
            info_hierarquia_distrital.data                  AS prof_hierarquia_distrital,
            info_posicao_distrito.data                      AS prof_posicao_distrital,
            info_gestor.data                                AS gestor,
            info_gestor_ai.data                             AS gestor_ai,
            info_gestor_farmaceutico.data                   AS gestor_farmaceutico,
            info_gestor_farmaceutico_ai.data                AS gestor_farmaceutico_ai,
            info_situacao.data                              AS prof_codigo_situacao,
            info_situacao_desc.data                         AS prof_descricao_situacao,
            to_timestamp(NULLIF(NULLIF(info_situacao_inicio.data, ''), '0')::bigint) AS prof_data_inicio_situacao,
            to_timestamp(NULLIF(NULLIF(info_situacao_fim.data, ''), '0')::bigint)    AS prof_data_fim_situacao,
            info_insc_admissao.data                         AS insc_data_admissao_inscricao,
            info_insc_demissao.data                         AS insc_data_demissao_inscricao,
            TRIM(c.fullname)                                AS nome_curso,
            ROUND(
                COALESCE((
                    SELECT COUNT(cmc2.id) * 100.0 / NULLIF(COUNT(cm2.id), 0)
                    FROM {course_modules} cm2
                    LEFT JOIN {course_modules_completion} cmc2
                        ON cmc2.coursemoduleid = cm2.id
                        AND cmc2.userid = u.id
                        AND cmc2.completionstate >= 1
                    WHERE cm2.course = c.id
                      AND cm2.completion > 0
                      AND cm2.deletioninprogress = 0
                ), 0)::numeric
            , 2)                                            AS progresso_percentual,
            CASE WHEN cc.timecompleted IS NOT NULL THEN 'Sim' ELSE 'Não' END AS concluido,
            ROUND(gg.finalgrade::numeric, 2)                AS nota,
            (SELECT STRING_AGG(g2.name, ', ' ORDER BY g2.name)
             FROM {groups_members} gm2
             JOIN {groups} g2 ON g2.id = gm2.groupid AND g2.courseid = c.id
             WHERE gm2.userid = u.id)                       AS nome_grupo
        FROM {user} u
        JOIN (
            SELECT DISTINCT ON (ue2.userid, e2.courseid)
                ue2.userid, e2.courseid
            FROM {user_enrolments} ue2
            JOIN {enrol} e2 ON e2.id = ue2.enrolid
            WHERE ue2.status = 0
              AND ue2.timestart <= EXTRACT(EPOCH FROM NOW())::INTEGER
              AND (ue2.timeend = 0 OR ue2.timeend >= EXTRACT(EPOCH FROM NOW())::INTEGER)
            ORDER BY ue2.userid, e2.courseid, ue2.id
        ) enrol_dedup ON enrol_dedup.userid = u.id
        JOIN {course} c               ON c.id = enrol_dedup.courseid
        LEFT JOIN {course_completions} cc ON cc.userid = u.id AND cc.course = c.id
        LEFT JOIN {grade_items} gi    ON gi.courseid = c.id AND gi.itemtype = 'course'
        LEFT JOIN {grade_grades} gg   ON gg.itemid = gi.id AND gg.userid = u.id
        LEFT JOIN {user_info_data} info_cpf              ON info_cpf.userid = u.id              AND info_cpf.fieldid = 31
        LEFT JOIN {user_info_data} info_cidade           ON info_cidade.userid = u.id           AND info_cidade.fieldid = 8
        LEFT JOIN {user_info_data} info_uf               ON info_uf.userid = u.id               AND info_uf.fieldid = 9
        LEFT JOIN {user_info_data} info_nascimento       ON info_nascimento.userid = u.id       AND info_nascimento.fieldid = 15
        LEFT JOIN {user_info_data} info_sexo             ON info_sexo.userid = u.id             AND info_sexo.fieldid = 16
        LEFT JOIN {user_info_data} info_sid              ON info_sid.userid = u.id              AND info_sid.fieldid = 59
        LEFT JOIN {user_info_data} info_tipo             ON info_tipo.userid = u.id             AND info_tipo.fieldid = 12
        LEFT JOIN {user_info_data} info_tipo_desc        ON info_tipo_desc.userid = u.id        AND info_tipo_desc.fieldid = 13
        LEFT JOIN {user_info_data} info_empresa          ON info_empresa.userid = u.id          AND info_empresa.fieldid = 6
        LEFT JOIN {user_info_data} info_empresa_nome     ON info_empresa_nome.userid = u.id     AND info_empresa_nome.fieldid = 7
        LEFT JOIN {user_info_data} info_filial           ON info_filial.userid = u.id           AND info_filial.fieldid = 10
        LEFT JOIN {user_info_data} info_filial_desc      ON info_filial_desc.userid = u.id      AND info_filial_desc.fieldid = 11
        LEFT JOIN {user_info_data} info_distrito         ON info_distrito.userid = u.id         AND info_distrito.fieldid = 22
        LEFT JOIN {user_info_data} info_distrito_nome    ON info_distrito_nome.userid = u.id    AND info_distrito_nome.fieldid = 23
        LEFT JOIN {user_info_data} info_bandeira         ON info_bandeira.userid = u.id         AND info_bandeira.fieldid = 17
        LEFT JOIN {user_info_data} info_local            ON info_local.userid = u.id            AND info_local.fieldid = 39
        LEFT JOIN {user_info_data} info_local_nome       ON info_local_nome.userid = u.id       AND info_local_nome.fieldid = 40
        LEFT JOIN {user_info_data} info_admissao         ON info_admissao.userid = u.id         AND info_admissao.fieldid = 14
        LEFT JOIN {user_info_data} info_demissao         ON info_demissao.userid = u.id         AND info_demissao.fieldid = 51
        LEFT JOIN {user_info_data} info_cargo            ON info_cargo.userid = u.id            AND info_cargo.fieldid = 18
        LEFT JOIN {user_info_data} info_cargo_nome       ON info_cargo_nome.userid = u.id       AND info_cargo_nome.fieldid = 19
        LEFT JOIN {user_info_data} info_grau             ON info_grau.userid = u.id             AND info_grau.fieldid = 20
        LEFT JOIN {user_info_data} info_grau_nome        ON info_grau_nome.userid = u.id        AND info_grau_nome.fieldid = 21
        LEFT JOIN {user_info_data} info_hierarquia       ON info_hierarquia.userid = u.id       AND info_hierarquia.fieldid = 24
        LEFT JOIN {user_info_data} info_posicao          ON info_posicao.userid = u.id          AND info_posicao.fieldid = 25
        LEFT JOIN {user_info_data} info_diretor          ON info_diretor.userid = u.id          AND info_diretor.fieldid = 26
        LEFT JOIN {user_info_data} info_cargo_diretor    ON info_cargo_diretor.userid = u.id    AND info_cargo_diretor.fieldid = 28
        LEFT JOIN {user_info_data} info_diretor_hierarquia ON info_diretor_hierarquia.userid = u.id AND info_diretor_hierarquia.fieldid = 29
        LEFT JOIN {user_info_data} info_diretor_posicao  ON info_diretor_posicao.userid = u.id  AND info_diretor_posicao.fieldid = 30
        LEFT JOIN {user_info_data} info_gerente_regional    ON info_gerente_regional.userid = u.id    AND info_gerente_regional.fieldid = 27
        LEFT JOIN {user_info_data} info_cargo_regional      ON info_cargo_regional.userid = u.id      AND info_cargo_regional.fieldid = 32
        LEFT JOIN {user_info_data} info_regional_hierarquia ON info_regional_hierarquia.userid = u.id AND info_regional_hierarquia.fieldid = 33
        LEFT JOIN {user_info_data} info_posicao_regional    ON info_posicao_regional.userid = u.id    AND info_posicao_regional.fieldid = 34
        LEFT JOIN {user_info_data} info_gerente_distrital   ON info_gerente_distrital.userid = u.id   AND info_gerente_distrital.fieldid = 35
        LEFT JOIN {user_info_data} info_cargo_distrital     ON info_cargo_distrital.userid = u.id     AND info_cargo_distrital.fieldid = 36
        LEFT JOIN {user_info_data} info_hierarquia_distrital ON info_hierarquia_distrital.userid = u.id AND info_hierarquia_distrital.fieldid = 37
        LEFT JOIN {user_info_data} info_posicao_distrito    ON info_posicao_distrito.userid = u.id    AND info_posicao_distrito.fieldid = 38
        LEFT JOIN {user_info_data} info_gestor              ON info_gestor.userid = u.id              AND info_gestor.fieldid = 41
        LEFT JOIN {user_info_data} info_gestor_ai           ON info_gestor_ai.userid = u.id           AND info_gestor_ai.fieldid = 42
        LEFT JOIN {user_info_data} info_gestor_farmaceutico ON info_gestor_farmaceutico.userid = u.id AND info_gestor_farmaceutico.fieldid = 43
        LEFT JOIN {user_info_data} info_gestor_farmaceutico_ai ON info_gestor_farmaceutico_ai.userid = u.id AND info_gestor_farmaceutico_ai.fieldid = 44
        LEFT JOIN {user_info_data} info_situacao            ON info_situacao.userid = u.id            AND info_situacao.fieldid = 45
        LEFT JOIN {user_info_data} info_situacao_desc       ON info_situacao_desc.userid = u.id       AND info_situacao_desc.fieldid = 46
        LEFT JOIN {user_info_data} info_situacao_inicio     ON info_situacao_inicio.userid = u.id     AND info_situacao_inicio.fieldid = 47
        LEFT JOIN {user_info_data} info_situacao_fim        ON info_situacao_fim.userid = u.id        AND info_situacao_fim.fieldid = 48
        LEFT JOIN {user_info_data} info_insc_admissao       ON info_insc_admissao.userid = u.id       AND info_insc_admissao.fieldid = 53
        LEFT JOIN {user_info_data} info_insc_demissao       ON info_insc_demissao.userid = u.id       AND info_insc_demissao.fieldid = 54
        WHERE u.deleted   = 0
          AND u.suspended = 0
          AND c.visible   = 1
        ORDER BY prof_nome_filial, bas_nome_funcionario, nome_curso
    RTSQL;
}

/**
 * Retorna o caminho do executável Python configurado em mdl_config (pathtopython).
 * Valida que o arquivo existe e é executável.
 *
 * @return string|false  Caminho absoluto do Python ou false se não configurado/inválido.
 */
function local_relatorio_treinamentos_get_python_path() {
    $path = get_config('core', 'pathtopython');
    if (!$path || !is_executable($path)) {
        return false;
    }
    return $path;
}

/**
 * Converte um CSV (sep=';', UTF-8 BOM) para XLSX usando Python/pandas.
 * Remove o CSV de entrada após a conversão.
 *
 * @param string $csv_path   Caminho do CSV de entrada (será deletado).
 * @param string $xlsx_path  Caminho de saída do XLSX.
 * @return bool  true em sucesso, false em falha.
 */
function local_relatorio_treinamentos_csv_to_xlsx(string $csv_path, string $xlsx_path): bool {
    $python = local_relatorio_treinamentos_get_python_path();
    $script = __DIR__ . '/cli/csv_to_xlsx.py';

    if (!$python || !file_exists($script)) {
        return false;
    }

    $cmd    = escapeshellarg($python) . ' ' . escapeshellarg($script) . ' '
            . escapeshellarg($csv_path) . ' ' . escapeshellarg($xlsx_path) . ' 2>&1';
    $output  = [];
    $retcode = 0;
    exec($cmd, $output, $retcode);

    @unlink($csv_path);

    return $retcode === 0 && file_exists($xlsx_path);
}

/**
 * Converte todos os arquivos CSV de um diretório para XLSX usando Python/pandas.
 * Chama Python UMA única vez para todo o lote — muito mais eficiente que
 * chamar local_relatorio_treinamentos_csv_to_xlsx() por arquivo.
 * Remove os CSVs após a conversão.
 *
 * @param string $dir  Diretório com *.csv a converter.
 * @return bool  true se o comando retornou 0, false caso contrário.
 */
function local_relatorio_treinamentos_csv_dir_to_xlsx(string $dir): bool {
    $python = local_relatorio_treinamentos_get_python_path();
    $script = __DIR__ . '/cli/csv_to_xlsx.py';

    if (!$python || !file_exists($script)) {
        return false;
    }

    $cmd     = escapeshellarg($python) . ' ' . escapeshellarg($script)
             . ' --dir ' . escapeshellarg($dir) . ' 2>&1';
    $output  = [];
    $retcode = 0;
    exec($cmd, $output, $retcode);

    return $retcode === 0;
}

/**
 * Retorna array com os fullnames dos cursos que têm a flag rt_incluir_filtro=1.
 * Usado para aplicar filtro implícito na visualização do relatório.
 *
 * @return string[]  Lista de fullnames de cursos com a flag ativa.
 */
function local_relatorio_treinamentos_get_nomes_cursos_filtro() {
    global $DB;
    $sql = "SELECT DISTINCT c.fullname
            FROM {course} c
            JOIN {customfield_data} d  ON d.instanceid = c.id
            JOIN {customfield_field} f ON f.id = d.fieldid
            WHERE f.shortname = 'rt_incluir_filtro'
              AND d.value = '1'
              AND c.visible = 1
            ORDER BY c.fullname";
    $rows = $DB->get_records_sql($sql);
    return array_values(array_filter(array_map(function($r) {
        return trim($r->fullname);
    }, $rows)));
}

/**
 * Verifica se um usuário é considerado "gestor" com base nas configurações do plugin.
 *
 * Lê as settings gestor_campo_perfil (shortname do campo de perfil) e
 * gestor_campo_valores (lista de valores separados por vírgula).
 * Se não configurado, usa o comportamento legado (fieldid=18, códigos hardcoded).
 *
 * @param stdClass $user  Objeto do usuário (precisa ter ->id).
 * @return bool
 */
function local_relatorio_treinamentos_is_gestor($user) {
    global $DB;

    $campo_shortname = get_config('local_relatorio_treinamentos', 'gestor_campo_perfil');
    $campo_valores   = get_config('local_relatorio_treinamentos', 'gestor_campo_valores');

    // Fallback legado se não configurado
    if (empty($campo_shortname) || $campo_shortname === '0') {
        $cargo = $DB->get_field('user_info_data', 'data', [
            'userid'  => $user->id,
            'fieldid' => 18,
        ]);
        $manager_codes = \local_relatorio_treinamentos\helper\columns::get_manager_cargo_codes();
        return in_array(trim((string)$cargo), $manager_codes);
    }

    // Busca o fieldid pelo shortname configurado
    $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => $campo_shortname]);
    if (!$fieldid) {
        return false;
    }

    $valor = $DB->get_field('user_info_data', 'data', [
        'userid'  => $user->id,
        'fieldid' => $fieldid,
    ]);

    $valores_permitidos = array_map('trim', explode(',', (string)$campo_valores));
    $valores_permitidos = array_filter($valores_permitidos);

    return in_array(trim((string)$valor), $valores_permitidos);
}

/**
 * Monta condição SQL para um campo com valor único ou múltiplos (OR/IN).
 */
function local_relatorio_treinamentos_build_filter_condition(
    string $field, $value, array $allowed_fields,
    array &$where_parts, array &$params, int &$pcount
): void {
    global $DB;
    if (!in_array($field, $allowed_fields)) return;
    $values = is_array($value) ? array_values($value) : [trim((string)$value)];
    $values = array_values(array_filter(array_map('strval', $values), fn($v) => trim($v) !== ''));
    if (empty($values)) return;
    if (count($values) === 1) {
        $pname           = 'wf' . $pcount++;
        $where_parts[]   = "$field = :$pname";
        $params[$pname]  = $values[0];
    } else {
        [$in_sql, $in_params] = $DB->get_in_or_equal($values, SQL_PARAMS_NAMED, 'wf' . $pcount);
        $pcount      += count($values);
        $where_parts[] = "$field $in_sql";
        $params        = array_merge($params, $in_params);
    }
}

/**
 * Verifica se uma linha corresponde a todos os filtros (OR por campo, AND entre campos).
 */
function local_relatorio_treinamentos_row_matches_filters(object $row, array $active_filters): bool {
    foreach ($active_filters as $field => $value) {
        $values = is_array($value) ? $value : [trim((string)$value)];
        $values = array_filter(array_map('strval', $values), fn($v) => trim($v) !== '');
        if (empty($values)) continue;
        if (!in_array((string)($row->$field ?? ''), array_values($values))) return false;
    }
    return true;
}

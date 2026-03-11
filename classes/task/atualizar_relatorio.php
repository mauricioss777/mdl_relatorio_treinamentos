<?php
namespace local_relatorio_treinamentos\task;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');

class atualizar_relatorio extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('taskname', 'local_relatorio_treinamentos');
    }

    public function execute() {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');

        $estrategia = get_config('local_relatorio_treinamentos', 'estrategia') ?: 'direct';

        if ($estrategia === 'direct') {
            mtrace('Estratégia: consulta direta — task não precisa fazer nada.');
            return;
        }

        $cache       = \cache::make('local_relatorio_treinamentos', 'relatorio');
        // Usa campos de filtro configurados pelo admin (ou os defaults se não configurado)
        $filtros_saved  = get_config('local_relatorio_treinamentos', 'filtros_visiveis');
        $all_cols       = \local_relatorio_treinamentos\helper\columns::get_all();
        // Always include default filter fields + any extra configured fields
        $base_filter_keys = array_keys(\local_relatorio_treinamentos\helper\columns::get_filter_fields());
        $extra_keys = $filtros_saved
            ? array_keys(array_intersect_key($all_cols, array_flip(explode(',', $filtros_saved))))
            : [];
        $filter_keys = array_unique(array_merge($base_filter_keys, $extra_keys));

        if ($estrategia === 'cache') {
            ini_set('memory_limit', '4G');
            $dados = self::buscar_dados($DB);

            $filter_options = array_fill_keys($filter_keys, []);
            foreach ($dados as $row) {
                foreach ($filter_keys as $field) {
                    $v = (string)($row->$field ?? '');
                    if ($v !== '') { $filter_options[$field][$v] = $v; }
                }
            }
            foreach ($filter_options as &$vals) { asort($vals); }
            unset($vals);

            $cursos_filtro = self::get_cursos_no_filtro($DB);
            if (!empty($cursos_filtro)) {
                $filter_options['nome_curso'] = $cursos_filtro;
            }

            $cache->set('dados', $dados);
            $cache->set('filter_options', $filter_options);
            $cache->set('ultima_atualizacao', time());
            mtrace('Cache atualizado: ' . count($dados) . ' registros.');

        } elseif ($estrategia === 'view') {
            // Atualiza a view no PostgreSQL (sem carregar dados no PHP)
            mtrace('Atualizando view materializada...');
            local_relatorio_treinamentos_refresh_matview($DB);
            mtrace('View atualizada.');

            // Computa filter_options via queries DISTINCT na view (rápido com índices)
            $view = local_relatorio_treinamentos_get_view_name();
            $filter_options = array_fill_keys($filter_keys, []);
            foreach ($filter_keys as $field) {
                if ($field === 'nome_curso') continue;
                $rows = $DB->get_records_sql(
                    "SELECT DISTINCT $field AS val FROM $view WHERE $field IS NOT NULL AND $field <> '' ORDER BY $field"
                );
                foreach ($rows as $row) {
                    $v = $row->val;
                    if ($v !== '') { $filter_options[$field][$v] = $v; }
                }
            }
            $filter_options['nome_curso'] = self::get_cursos_no_filtro($DB);

            $cache->set('filter_options', $filter_options);
            $cache->set('ultima_atualizacao', time());
            // Não armazena 'dados' — a view é a fonte de dados para o modo view
            mtrace('filter_options atualizados da view materializada.');
        }
    }

    /**
     * Retorna array [fullname => fullname] dos cursos com rt_incluir_filtro=1,
     * usados como opções do filtro de curso no relatório.
     */
    public static function get_cursos_no_filtro($DB) {
        $sql = "SELECT DISTINCT c.fullname AS nome_curso
                FROM {course} c
                JOIN {customfield_data} d  ON d.instanceid = c.id
                JOIN {customfield_field} f ON f.id = d.fieldid
                WHERE f.shortname = 'rt_incluir_filtro'
                  AND d.value = '1'
                  AND c.visible = 1
                ORDER BY c.fullname";
        $rows = $DB->get_records_sql($sql);
        $result = [];
        foreach ($rows as $row) {
            $v = trim($row->nome_curso);
            if ($v !== '') { $result[$v] = $v; }
        }
        return $result;
    }

    public static function buscar_dados($DB) {
        global $CFG;
        require_once($CFG->dirroot . '/local/relatorio_treinamentos/locallib.php');
        $sql = local_relatorio_treinamentos_get_report_sql();
        return $DB->get_records_sql($sql);
    }

    /** @deprecated use buscar_dados_UNUSED_PLACEHOLDER */
    private static function _sql_placeholder() {
        $sql = "
            SELECT
                -- Chave única por combinação usuário+curso
                CONCAT(u.id::text, '_', c.id::text)            AS row_key,

                -- Dados base do usuário
                u.id                                            AS userid,
                u.username                                      AS bas_usuario,
                CONCAT(u.firstname, ' ', u.lastname)            AS bas_nome_funcionario,
                u.email                                         AS bas_email,
                u.city                                          AS bas_cidade,
                u.idnumber                                      AS opc_numero_identificacao,
                u.institution                                   AS opc_instituicao,
                u.department                                    AS opc_departamento,

                -- Dados pessoais (perfil customizado)
                info_cpf.data                                   AS dp_cpf,
                info_cidade.data                                AS dp_cidade_colaborador,
                info_uf.data                                    AS dp_uf,
                to_timestamp(NULLIF(NULLIF(info_nascimento.data, ''), '0')::bigint) AS dp_data_nascimento,
                info_sexo.data                                  AS dp_sexo,
                info_sid.data                                   AS dp_sid,

                -- Tipo
                info_tipo.data                                  AS prof_tipo,
                info_tipo_desc.data                             AS prof_descricao_tipo,

                -- Empresa / Filial
                info_empresa.data                               AS prof_numero_empresa,
                info_empresa_nome.data                          AS prof_nome_empresa,
                info_filial.data                                AS prof_codigo_filial,
                info_filial_desc.data                           AS prof_nome_filial,

                -- Distrito / Local
                info_distrito.data                              AS prof_numero_distrito,
                info_distrito_nome.data                         AS prof_nome_distrito,
                info_bandeira.data                              AS prof_bandeira,
                info_local.data                                 AS prof_numero_local,
                info_local_nome.data                            AS prof_nome_local,

                -- RH
                to_timestamp(NULLIF(NULLIF(info_admissao.data, ''), '0')::bigint) AS prof_data_admissao,
                to_timestamp(NULLIF(NULLIF(info_demissao.data, ''), '0')::bigint) AS prof_data_demissao,
                info_cargo.data                                 AS prof_codigo_cargo,
                info_cargo_nome.data                            AS prof_cargo,
                info_grau.data                                  AS prof_codigo_grau_instrucao,
                info_grau_nome.data                             AS prof_grau_instrucao,

                -- Hierarquia colaborador
                info_hierarquia.data                            AS prof_hierarquia,
                info_posicao.data                               AS prof_posicao,

                -- Diretor
                info_diretor.data                               AS prof_diretor,
                info_cargo_diretor.data                         AS prof_cargo_diretor,
                info_diretor_hierarquia.data                    AS prof_hierarquia_diretor,
                info_diretor_posicao.data                       AS prof_posicao_diretor,

                -- Gerente Regional
                info_gerente_regional.data                      AS prof_gerente_regional,
                info_cargo_regional.data                        AS prof_cargo_regional,
                info_regional_hierarquia.data                   AS prof_hierarquia_regional,
                info_posicao_regional.data                      AS prof_posicao_regional,

                -- Gerente Distrital
                info_gerente_distrital.data                     AS prof_gerente_distrital,
                info_cargo_distrital.data                       AS prof_cargo_distrital,
                info_hierarquia_distrital.data                  AS prof_hierarquia_distrital,
                info_posicao_distrito.data                      AS prof_posicao_distrital,

                -- Gestores
                info_gestor.data                                AS gestor,
                info_gestor_ai.data                             AS gestor_ai,
                info_gestor_farmaceutico.data                   AS gestor_farmaceutico,
                info_gestor_farmaceutico_ai.data                AS gestor_farmaceutico_ai,

                -- Situação RH
                info_situacao.data                              AS prof_codigo_situacao,
                info_situacao_desc.data                         AS prof_descricao_situacao,
                to_timestamp(NULLIF(NULLIF(info_situacao_inicio.data, ''), '0')::bigint) AS prof_data_inicio_situacao,
                to_timestamp(NULLIF(NULLIF(info_situacao_fim.data, ''), '0')::bigint)    AS prof_data_fim_situacao,

                -- Inscrição
                info_insc_admissao.data                                     AS insc_data_admissao_inscricao,
                info_insc_demissao.data                                     AS insc_data_demissao_inscricao,

                -- Dados do curso
                c.fullname                                      AS nome_curso,
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
                 WHERE gm2.userid = u.id)                           AS nome_grupo

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
            JOIN {course} c                  ON c.id = enrol_dedup.courseid
            LEFT JOIN {course_completions} cc
                ON cc.userid = u.id AND cc.course = c.id
            LEFT JOIN {grade_items} gi
                ON gi.courseid = c.id AND gi.itemtype = 'course'
            LEFT JOIN {grade_grades} gg
                ON gg.itemid = gi.id AND gg.userid = u.id

            -- Dados pessoais
            LEFT JOIN {user_info_data} info_cpf             ON info_cpf.userid = u.id             AND info_cpf.fieldid = 31
            LEFT JOIN {user_info_data} info_cidade          ON info_cidade.userid = u.id          AND info_cidade.fieldid = 8
            LEFT JOIN {user_info_data} info_uf              ON info_uf.userid = u.id              AND info_uf.fieldid = 9
            LEFT JOIN {user_info_data} info_nascimento      ON info_nascimento.userid = u.id      AND info_nascimento.fieldid = 15
            LEFT JOIN {user_info_data} info_sexo            ON info_sexo.userid = u.id            AND info_sexo.fieldid = 16
            LEFT JOIN {user_info_data} info_sid             ON info_sid.userid = u.id             AND info_sid.fieldid = 59
            -- Tipo
            LEFT JOIN {user_info_data} info_tipo            ON info_tipo.userid = u.id            AND info_tipo.fieldid = 12
            LEFT JOIN {user_info_data} info_tipo_desc       ON info_tipo_desc.userid = u.id       AND info_tipo_desc.fieldid = 13
            -- Empresa / Filial
            LEFT JOIN {user_info_data} info_empresa         ON info_empresa.userid = u.id         AND info_empresa.fieldid = 6
            LEFT JOIN {user_info_data} info_empresa_nome    ON info_empresa_nome.userid = u.id    AND info_empresa_nome.fieldid = 7
            LEFT JOIN {user_info_data} info_filial          ON info_filial.userid = u.id          AND info_filial.fieldid = 10
            LEFT JOIN {user_info_data} info_filial_desc     ON info_filial_desc.userid = u.id     AND info_filial_desc.fieldid = 11
            -- Distrito / Local
            LEFT JOIN {user_info_data} info_distrito        ON info_distrito.userid = u.id        AND info_distrito.fieldid = 22
            LEFT JOIN {user_info_data} info_distrito_nome   ON info_distrito_nome.userid = u.id   AND info_distrito_nome.fieldid = 23
            LEFT JOIN {user_info_data} info_bandeira        ON info_bandeira.userid = u.id        AND info_bandeira.fieldid = 17
            LEFT JOIN {user_info_data} info_local           ON info_local.userid = u.id           AND info_local.fieldid = 39
            LEFT JOIN {user_info_data} info_local_nome      ON info_local_nome.userid = u.id      AND info_local_nome.fieldid = 40
            -- RH
            LEFT JOIN {user_info_data} info_admissao        ON info_admissao.userid = u.id        AND info_admissao.fieldid = 14
            LEFT JOIN {user_info_data} info_demissao        ON info_demissao.userid = u.id        AND info_demissao.fieldid = 51
            LEFT JOIN {user_info_data} info_cargo           ON info_cargo.userid = u.id           AND info_cargo.fieldid = 18
            LEFT JOIN {user_info_data} info_cargo_nome      ON info_cargo_nome.userid = u.id      AND info_cargo_nome.fieldid = 19
            LEFT JOIN {user_info_data} info_grau            ON info_grau.userid = u.id            AND info_grau.fieldid = 20
            LEFT JOIN {user_info_data} info_grau_nome       ON info_grau_nome.userid = u.id       AND info_grau_nome.fieldid = 21
            -- Hierarquia
            LEFT JOIN {user_info_data} info_hierarquia      ON info_hierarquia.userid = u.id      AND info_hierarquia.fieldid = 24
            LEFT JOIN {user_info_data} info_posicao         ON info_posicao.userid = u.id         AND info_posicao.fieldid = 25
            -- Diretor
            LEFT JOIN {user_info_data} info_diretor         ON info_diretor.userid = u.id         AND info_diretor.fieldid = 26
            LEFT JOIN {user_info_data} info_cargo_diretor   ON info_cargo_diretor.userid = u.id   AND info_cargo_diretor.fieldid = 28
            LEFT JOIN {user_info_data} info_diretor_hierarquia ON info_diretor_hierarquia.userid = u.id AND info_diretor_hierarquia.fieldid = 29
            LEFT JOIN {user_info_data} info_diretor_posicao ON info_diretor_posicao.userid = u.id AND info_diretor_posicao.fieldid = 30
            -- Gerente Regional
            LEFT JOIN {user_info_data} info_gerente_regional   ON info_gerente_regional.userid = u.id   AND info_gerente_regional.fieldid = 27
            LEFT JOIN {user_info_data} info_cargo_regional     ON info_cargo_regional.userid = u.id     AND info_cargo_regional.fieldid = 32
            LEFT JOIN {user_info_data} info_regional_hierarquia ON info_regional_hierarquia.userid = u.id AND info_regional_hierarquia.fieldid = 33
            LEFT JOIN {user_info_data} info_posicao_regional   ON info_posicao_regional.userid = u.id   AND info_posicao_regional.fieldid = 34
            -- Gerente Distrital
            LEFT JOIN {user_info_data} info_gerente_distrital  ON info_gerente_distrital.userid = u.id  AND info_gerente_distrital.fieldid = 35
            LEFT JOIN {user_info_data} info_cargo_distrital    ON info_cargo_distrital.userid = u.id    AND info_cargo_distrital.fieldid = 36
            LEFT JOIN {user_info_data} info_hierarquia_distrital ON info_hierarquia_distrital.userid = u.id AND info_hierarquia_distrital.fieldid = 37
            LEFT JOIN {user_info_data} info_posicao_distrito   ON info_posicao_distrito.userid = u.id   AND info_posicao_distrito.fieldid = 38
            -- Gestores
            LEFT JOIN {user_info_data} info_gestor             ON info_gestor.userid = u.id             AND info_gestor.fieldid = 41
            LEFT JOIN {user_info_data} info_gestor_ai          ON info_gestor_ai.userid = u.id          AND info_gestor_ai.fieldid = 42
            LEFT JOIN {user_info_data} info_gestor_farmaceutico ON info_gestor_farmaceutico.userid = u.id AND info_gestor_farmaceutico.fieldid = 43
            LEFT JOIN {user_info_data} info_gestor_farmaceutico_ai ON info_gestor_farmaceutico_ai.userid = u.id AND info_gestor_farmaceutico_ai.fieldid = 44
            -- Situação RH
            LEFT JOIN {user_info_data} info_situacao           ON info_situacao.userid = u.id           AND info_situacao.fieldid = 45
            LEFT JOIN {user_info_data} info_situacao_desc      ON info_situacao_desc.userid = u.id      AND info_situacao_desc.fieldid = 46
            LEFT JOIN {user_info_data} info_situacao_inicio    ON info_situacao_inicio.userid = u.id    AND info_situacao_inicio.fieldid = 47
            LEFT JOIN {user_info_data} info_situacao_fim       ON info_situacao_fim.userid = u.id       AND info_situacao_fim.fieldid = 48
            -- Inscrição
            LEFT JOIN {user_info_data} info_insc_admissao      ON info_insc_admissao.userid = u.id      AND info_insc_admissao.fieldid = 53
            LEFT JOIN {user_info_data} info_insc_demissao      ON info_insc_demissao.userid = u.id      AND info_insc_demissao.fieldid = 54

            WHERE u.deleted   = 0
              AND u.suspended = 0
              AND c.visible   = 1

            ORDER BY prof_nome_filial, bas_nome_funcionario, nome_curso
        ";

        return "UNUSED";
    }
}

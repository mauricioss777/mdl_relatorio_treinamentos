<?php
namespace local_relatorio_treinamentos\task;

defined('MOODLE_INTERNAL') || die();

class atualizar_relatorio extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('taskname', 'local_relatorio_treinamentos');
    }

    public function execute() {
        global $DB;

        $dados = self::buscar_dados($DB);

        $cache = \cache::make('local_relatorio_treinamentos', 'relatorio');
        $cache->set('dados', $dados);
        $cache->set('ultima_atualizacao', time());

        mtrace('Relatório de treinamentos atualizado: ' . count($dados) . ' registros.');
    }

    public static function buscar_dados($DB) {
	    $sql = "
			SELECT
			    u.id                                            AS userid,
			    uif_filial.data                                 AS codigo_filial,
			    uif_nome_filial.data                             AS nome_filial,
			    CONCAT(u.firstname, ' ', u.lastname)            AS nome_completo,
			    u.idnumber                                      AS numero_identificacao,
			    uif_admissao.data                               AS data_admissao,
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
			    uif_diretor.data                                AS diretor,
			    uif_ger_distrital.data                          AS gerente_distrital,
			    uif_ger_regional.data                           AS gerente_regional,
			    g.name                                          AS nome_grupo

			FROM {user} u
			JOIN {user_enrolments} ue        ON ue.userid = u.id
			JOIN {enrol} e                   ON e.id = ue.enrolid
			JOIN {course} c                  ON c.id = e.courseid
			LEFT JOIN {course_completions} cc
			    ON cc.userid = u.id AND cc.course = c.id
			LEFT JOIN {grade_items} gi
			    ON gi.courseid = c.id AND gi.itemtype = 'course'
			LEFT JOIN {grade_grades} gg
			    ON gg.itemid = gi.id AND gg.userid = u.id
			LEFT JOIN {groups_members} gm    ON gm.userid = u.id
			LEFT JOIN {groups} g             ON g.id = gm.groupid AND g.courseid = c.id

			LEFT JOIN {user_info_data} uif_filial
			    ON uif_filial.userid = u.id
			    AND uif_filial.fieldid = (SELECT id FROM {user_info_field} WHERE shortname = 'codigo_filial' LIMIT 1)
			LEFT JOIN {user_info_data} uif_nome_filial
			    ON uif_nome_filial.userid = u.id
			    AND uif_nome_filial.fieldid = (SELECT id FROM {user_info_field} WHERE shortname = 'nome_filial' LIMIT 1)
			LEFT JOIN {user_info_data} uif_admissao
			    ON uif_admissao.userid = u.id
			    AND uif_admissao.fieldid = (SELECT id FROM {user_info_field} WHERE shortname = 'data_admissao' LIMIT 1)
			LEFT JOIN {user_info_data} uif_diretor
			    ON uif_diretor.userid = u.id
			    AND uif_diretor.fieldid = (SELECT id FROM {user_info_field} WHERE shortname = 'diretor' LIMIT 1)
			LEFT JOIN {user_info_data} uif_ger_distrital
			    ON uif_ger_distrital.userid = u.id
			    AND uif_ger_distrital.fieldid = (SELECT id FROM {user_info_field} WHERE shortname = 'gerente_distrital' LIMIT 1)
			LEFT JOIN {user_info_data} uif_ger_regional
			    ON uif_ger_regional.userid = u.id
			    AND uif_ger_regional.fieldid = (SELECT id FROM {user_info_field} WHERE shortname = 'gerente_regional' LIMIT 1)

			WHERE u.deleted  = 0
			  AND u.suspended = 0
			  AND c.visible   = 1
			  AND ue.status   = 0
			  AND ue.timestart <= EXTRACT(EPOCH FROM NOW())::INTEGER
			  AND (ue.timeend = 0 OR ue.timeend >= EXTRACT(EPOCH FROM NOW())::INTEGER)

			ORDER BY nome_filial, nome_completo, nome_curso
		";

	    return $DB->get_records_sql($sql);
    }
}

SELECT distinct u.username                                             AS bas_usuario,
                CONCAT(u.firstname, ' ', u.lastname)                   AS bas_nome_funcionario,
                u.email                                                AS bas_email,
                u.city                                                 AS bas_cidade,
                u.idnumber                                             AS opc_numero_identificacao,
                u.institution                                          AS opc_instituicao,
                u.department                                           AS opc_departamento,
                info_cpf.data                                          AS dp_cpf,
                info_cidade.data                                       AS dp_cidade_colaborador,
                info_uf.data                                           AS dp_uf,
                to_timestamp(NULLIF(info_nascimento.data, '')::bigint) AS dp_data_nascimento,
                info_sexo.data                                         AS dp_sexo,
                info_tipo.data                                         AS prof_tipo,
                info_tipo_desc.data                                    AS prof_descricao_tipo,
                info_empresa.data                                      AS prof_numero_empresa,
                info_empresa_nome.data                                 AS prof_nome_empresa,
                info_filial.data                                       AS prof_codigo_filial,
                info_filial_desc.data                                  AS prof_nome_filial,
                info_distrito.data                                     AS prof_numero_distrito,
                info_distrito_nome.data                                AS prof_nome_distrito,
                info_bandeira.data                                     AS prof_bandeira,
                info_local.data                                        AS prof_numero_local,
                info_local_nome.data                                   AS prof_nome_local,
                to_timestamp(NULLIF(info_admissao.data, '')::bigint)   AS prof_data_admissao,
                to_timestamp(NULLIF(info_demissao.data, '')::bigint)   AS prof_data_demissao,
                info_demissao.data                                     AS prof_data_demissao,
                info_cargo.data                                        AS prof_codigo_cargo,
                info_cargo_nome.data                                   AS prof_cargo,
                info_grau.data                                         AS prof_codigo_grau_instrucao,
                info_grau_nome.data                                    AS prof_grau_instrucao,
                info_hierarquia.data                                   AS prof_hierarquia,
                info_posicao.data                                      AS prof_posicao,
                info_diretor.data                                      AS prof_diretor,
                info_cargo_diretor.data                                AS prof_cargo_diretor,
                info_diretor_hierarquia.data                           AS prof_hierarquia_diretor,
                info_diretor_posicao.data                              AS prof_posicao_diretor,
                info_gerente_regional.data                             AS prof_gerente_regional,
                info_cargo_regional.data                               AS prof_cargo_regional,
                info_regional_hierarquia.data                          AS prof_hierarquia_regional,
                info_posicao_regional.data                             AS prof_posicao_regional,
                info_gerente_distrital.data                            AS prof_gerente_distrital,
                info_cargo_distrital.data                              AS prof_cargo_distrital,
                info_hierarquia_distrital.data                         AS prof_hierarquia_distrital,
                info_posicao_distrito.data                             AS prof_posicao_distrito,
                info_gestor.data                                       AS gestor,
                info_gestor_ai.data                                    AS gestor_ai,
                info_gestor_farmaceutico.data                          AS gestor_farmaceutico,
                info_gestor_farmaceutico_ai.data                       AS gestor_farmaceutico_ai,
                info_situacao.data                                     AS prof_codigo_situacao,
                info_situacao_descricao.data                           AS prof_descricao_situacao,
                info_situacao_data_inicio.data                         AS prof_data_inicio_situacao,
                info_situacao_data_fim.data                            AS prof_data_fim_situacao,
                info_insc_data_admissao.data                           AS insc_data_admissao_inscricao,
                info_insc_data_demissao.data                           AS insc_data_demissao_inscricao
  FROM mdl_user AS u
       LEFT JOIN mdl_user_info_data info_cpf on (u.id = info_cpf.userid and info_cpf.fieldid = 31)
       LEFT JOIN mdl_user_info_data info_cidade on (u.id = info_cidade.userid and info_cidade.fieldid = 8)
       LEFT JOIN mdl_user_info_data info_uf on (u.id = info_uf.userid and info_uf.fieldid = 9)
       LEFT JOIN mdl_user_info_data info_nascimento on (u.id = info_nascimento.userid and info_nascimento.fieldid = 15)
       LEFT JOIN mdl_user_info_data info_sexo on (u.id = info_sexo.userid and info_sexo.fieldid = 16)
       LEFT JOIN mdl_user_info_data info_tipo on (u.id = info_tipo.userid and info_tipo.fieldid = 12)
       LEFT JOIN mdl_user_info_data info_tipo_desc on (u.id = info_tipo_desc.userid and info_tipo_desc.fieldid = 13)
       LEFT JOIN mdl_user_info_data info_empresa on (u.id = info_empresa.userid and info_empresa.fieldid = 6)
       LEFT JOIN mdl_user_info_data info_empresa_nome
                 on (u.id = info_empresa_nome.userid and info_empresa_nome.fieldid = 7)
       LEFT JOIN mdl_user_info_data info_filial on (u.id = info_filial.userid and info_filial.fieldid = 10)
       LEFT JOIN mdl_user_info_data info_filial_desc
                 on (u.id = info_filial_desc.userid and info_filial_desc.fieldid = 11)
       LEFT JOIN mdl_user_info_data info_distrito on (u.id = info_distrito.userid and info_distrito.fieldid = 22)
       LEFT JOIN mdl_user_info_data info_distrito_nome
                 on (u.id = info_distrito_nome.userid and info_distrito_nome.fieldid = 23)
       LEFT JOIN mdl_user_info_data info_bandeira on (u.id = info_bandeira.userid and info_bandeira.fieldid = 17)
       LEFT JOIN mdl_user_info_data info_local on (u.id = info_local.userid and info_local.fieldid = 39)
       LEFT JOIN mdl_user_info_data info_local_nome on (u.id = info_local_nome.userid and info_local_nome.fieldid = 40)
       LEFT JOIN mdl_user_info_data info_admissao on (u.id = info_admissao.userid and info_admissao.fieldid = 14)
       LEFT JOIN mdl_user_info_data info_demissao on (u.id = info_demissao.userid and info_demissao.fieldid = 51)
       LEFT JOIN mdl_user_info_data info_cargo on (u.id = info_cargo.userid and info_cargo.fieldid = 18)
       LEFT JOIN mdl_user_info_data info_cargo_nome on (u.id = info_cargo_nome.userid and info_cargo_nome.fieldid = 19)
       LEFT JOIN mdl_user_info_data info_grau on (u.id = info_grau.userid and info_grau.fieldid = 20)
       LEFT JOIN mdl_user_info_data info_grau_nome on (u.id = info_grau_nome.userid and info_grau_nome.fieldid = 21)
       LEFT JOIN mdl_user_info_data info_hierarquia on (u.id = info_hierarquia.userid and info_hierarquia.fieldid = 24)
       LEFT JOIN mdl_user_info_data info_posicao on (u.id = info_posicao.userid and info_posicao.fieldid = 25)
       LEFT JOIN mdl_user_info_data info_diretor on (u.id = info_diretor.userid and info_diretor.fieldid = 26)
       LEFT JOIN mdl_user_info_data info_cargo_diretor
                 on (u.id = info_cargo_diretor.userid and info_cargo_diretor.fieldid = 28)
       LEFT JOIN mdl_user_info_data info_diretor_hierarquia
                 on (u.id = info_diretor_hierarquia.userid and info_diretor_hierarquia.fieldid = 29)
       LEFT JOIN mdl_user_info_data info_diretor_posicao
                 on (u.id = info_diretor_posicao.userid and info_diretor_posicao.fieldid = 30)
       LEFT JOIN mdl_user_info_data info_gerente_regional
                 on (u.id = info_gerente_regional.userid and info_gerente_regional.fieldid = 27)
       LEFT JOIN mdl_user_info_data info_cargo_regional
                 on (u.id = info_cargo_regional.userid and info_cargo_regional.fieldid = 32)
       LEFT JOIN mdl_user_info_data info_regional_hierarquia
                 on (u.id = info_regional_hierarquia.userid and info_regional_hierarquia.fieldid = 33)
       LEFT JOIN mdl_user_info_data info_posicao_regional
                 on (u.id = info_posicao_regional.userid and info_posicao_regional.fieldid = 34)
       LEFT JOIN mdl_user_info_data info_gerente_distrital
                 on (u.id = info_gerente_distrital.userid and info_gerente_distrital.fieldid = 35)
       LEFT JOIN mdl_user_info_data info_cargo_distrital
                 on (u.id = info_cargo_distrital.userid and info_cargo_distrital.fieldid = 36)
       LEFT JOIN mdl_user_info_data info_hierarquia_distrital
                 on (u.id = info_hierarquia_distrital.userid and info_hierarquia_distrital.fieldid = 37)
       LEFT JOIN mdl_user_info_data info_posicao_distrito
                 on (u.id = info_posicao_distrito.userid and info_posicao_distrito.fieldid = 38)
       LEFT JOIN mdl_user_info_data info_gestor on (u.id = info_gestor.userid and info_gestor.fieldid = 41)
       LEFT JOIN mdl_user_info_data info_gestor_ai on (u.id = info_gestor_ai.userid and info_gestor_ai.fieldid = 42)
       LEFT JOIN mdl_user_info_data info_gestor_farmaceutico
                 on (u.id = info_gestor_farmaceutico.userid and info_gestor_farmaceutico.fieldid = 43)
       LEFT JOIN mdl_user_info_data info_gestor_farmaceutico_ai
                 on (u.id = info_gestor_farmaceutico_ai.userid and info_gestor_farmaceutico_ai.fieldid = 44)
       LEFT JOIN mdl_user_info_data info_situacao on (u.id = info_situacao.userid and info_situacao.fieldid = 45)
       LEFT JOIN mdl_user_info_data info_situacao_descricao
                 on (u.id = info_situacao_descricao.userid and info_situacao_descricao.fieldid = 46)
       LEFT JOIN mdl_user_info_data info_situacao_data_inicio
                 on (u.id = info_situacao_data_inicio.userid and info_situacao_data_inicio.fieldid = 47)
       LEFT JOIN mdl_user_info_data info_situacao_data_fim
                 on (u.id = info_situacao_data_fim.userid and info_situacao_data_fim.fieldid = 48)
       LEFT JOIN mdl_user_info_data info_insc_data_admissao
                 on (u.id = info_insc_data_admissao.userid and info_insc_data_admissao.fieldid = 53)
       LEFT JOIN mdl_user_info_data info_insc_data_demissao
                 on (u.id = info_insc_data_demissao.userid and info_insc_data_demissao.fieldid = 54)
 where u.suspended = 0 and
       u.deleted = 0 and
       info_filial.data = '1235';


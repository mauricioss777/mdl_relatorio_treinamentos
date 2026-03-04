<?php
namespace local_relatorio_treinamentos\helper;
defined('MOODLE_INTERNAL') || die();

class columns {

    public static function get_all(): array {
        return [
            // Dados do Moodle
            'bas_usuario'                  => 'Usuário',
            'bas_nome_funcionario'         => 'Nome Completo',
            'bas_email'                    => 'E-mail',
            'bas_cidade'                   => 'Cidade (Moodle)',
            'opc_numero_identificacao'     => 'Nº Identificação',
            'opc_instituicao'              => 'Instituição',
            'opc_departamento'             => 'Departamento',
            // Dados pessoais
            'dp_cpf'                       => 'CPF',
            'dp_cidade_colaborador'        => 'Cidade Colaborador',
            'dp_uf'                        => 'UF',
            'dp_data_nascimento'           => 'Data Nascimento',
            'dp_sexo'                      => 'Sexo',
            'dp_sid'                       => 'Cód. Senior',
            // Tipo
            'prof_tipo'                    => 'Tipo (Cód.)',
            'prof_descricao_tipo'          => 'Tipo',
            // Empresa / Filial
            'prof_numero_empresa'          => 'Nº Empresa',
            'prof_nome_empresa'            => 'Nome Empresa',
            'prof_codigo_filial'           => 'Cód. Filial',
            'prof_nome_filial'             => 'Nome Filial',
            // Distrito / Local
            'prof_numero_distrito'         => 'Nº Distrito',
            'prof_nome_distrito'           => 'Nome Distrito',
            'prof_bandeira'                => 'Bandeira',
            'prof_numero_local'            => 'Nº Local',
            'prof_nome_local'              => 'Nome Local',
            // RH
            'prof_data_admissao'           => 'Data Admissão',
            'prof_data_demissao'           => 'Data Demissão',
            'prof_codigo_cargo'            => 'Cód. Cargo',
            'prof_cargo'                   => 'Cargo',
            'prof_codigo_grau_instrucao'   => 'Cód. Grau Instrução',
            'prof_grau_instrucao'          => 'Grau Instrução',
            // Hierarquia
            'prof_hierarquia'              => 'Hierarquia',
            'prof_posicao'                 => 'Posição',
            // Diretor
            'prof_diretor'                 => 'Diretor',
            'prof_cargo_diretor'           => 'Cargo Diretor',
            'prof_hierarquia_diretor'      => 'Hierarquia Diretor',
            'prof_posicao_diretor'         => 'Posição Diretor',
            // Ger. Regional
            'prof_gerente_regional'        => 'Ger. Regional',
            'prof_cargo_regional'          => 'Cargo Ger. Regional',
            'prof_hierarquia_regional'     => 'Hierarquia Ger. Regional',
            'prof_posicao_regional'        => 'Posição Ger. Regional',
            // Ger. Distrital
            'prof_gerente_distrital'       => 'Ger. Distrital',
            'prof_cargo_distrital'         => 'Cargo Ger. Distrital',
            'prof_hierarquia_distrital'    => 'Hierarquia Ger. Distrital',
            'prof_posicao_distrital'       => 'Posição Ger. Distrital',
            // Gestores
            'gestor'                       => 'Gestor',
            'gestor_ai'                    => 'Gestor A.I.',
            'gestor_farmaceutico'          => 'Gestor Farmacêutico',
            'gestor_farmaceutico_ai'       => 'Gestor Farmacêutico A.I.',
            // Situação RH
            'prof_codigo_situacao'         => 'Cód. Situação RH',
            'prof_descricao_situacao'      => 'Situação RH',
            'prof_data_inicio_situacao'    => 'Início Situação RH',
            'prof_data_fim_situacao'       => 'Fim Situação RH',
            // Inscrição
            'insc_data_admissao_inscricao' => 'Data Admissão Inscrição',
            'insc_data_demissao_inscricao' => 'Data Demissão Inscrição',
            // Curso
            'nome_curso'                   => 'Nome do Curso',
            'progresso_percentual'         => 'Progresso (%)',
            'concluido'                    => 'Concluído',
            'nota'                         => 'Nota',
            'nome_grupo'                   => 'Grupo',
        ];
    }

    public static function get_groups(): array {
        return [
            'Dados do Moodle'  => ['bas_usuario', 'bas_nome_funcionario', 'bas_email', 'bas_cidade',
                                   'opc_numero_identificacao', 'opc_instituicao', 'opc_departamento'],
            'Dados Pessoais'   => ['dp_cpf', 'dp_cidade_colaborador', 'dp_uf', 'dp_data_nascimento',
                                   'dp_sexo', 'dp_sid'],
            'Tipo'             => ['prof_tipo', 'prof_descricao_tipo'],
            'Empresa / Filial' => ['prof_numero_empresa', 'prof_nome_empresa',
                                   'prof_codigo_filial', 'prof_nome_filial'],
            'Distrito / Local' => ['prof_numero_distrito', 'prof_nome_distrito', 'prof_bandeira',
                                   'prof_numero_local', 'prof_nome_local'],
            'RH'               => ['prof_data_admissao', 'prof_data_demissao', 'prof_codigo_cargo',
                                   'prof_cargo', 'prof_codigo_grau_instrucao', 'prof_grau_instrucao'],
            'Hierarquia'       => ['prof_hierarquia', 'prof_posicao'],
            'Diretor'          => ['prof_diretor', 'prof_cargo_diretor',
                                   'prof_hierarquia_diretor', 'prof_posicao_diretor'],
            'Ger. Regional'    => ['prof_gerente_regional', 'prof_cargo_regional',
                                   'prof_hierarquia_regional', 'prof_posicao_regional'],
            'Ger. Distrital'   => ['prof_gerente_distrital', 'prof_cargo_distrital',
                                   'prof_hierarquia_distrital', 'prof_posicao_distrital'],
            'Gestores'         => ['gestor', 'gestor_ai', 'gestor_farmaceutico', 'gestor_farmaceutico_ai'],
            'Situação RH'      => ['prof_codigo_situacao', 'prof_descricao_situacao',
                                   'prof_data_inicio_situacao', 'prof_data_fim_situacao'],
            'Inscrição'        => ['insc_data_admissao_inscricao', 'insc_data_demissao_inscricao'],
            'Curso'            => ['nome_curso', 'progresso_percentual', 'concluido', 'nota', 'nome_grupo'],
        ];
    }

    public static function get_default(): array {
        return [
            'bas_nome_funcionario', 'prof_codigo_filial', 'prof_nome_filial',
            'prof_codigo_cargo', 'prof_cargo', 'prof_diretor',
            'prof_gerente_regional', 'prof_gerente_distrital', 'gestor',
            'nome_curso', 'progresso_percentual', 'concluido', 'nota',
        ];
    }

    public static function get_filter_fields(): array {
        return [
            'prof_nome_filial'       => 'Filial',
            'nome_curso'             => 'Curso',
            'concluido'              => 'Concluído',
            'prof_diretor'           => 'Diretor',
            'prof_gerente_regional'  => 'Ger. Regional',
            'prof_gerente_distrital' => 'Ger. Distrital',
            'gestor'                 => 'Gestor',
        ];
    }

    public static function get_zip_group_fields(): array {
        return [
            'prof_codigo_filial'     => 'Código da Filial',
            'prof_nome_filial'       => 'Nome da Filial',
            'prof_nome_distrito'     => 'Distrito',
            'prof_bandeira'          => 'Bandeira',
            'prof_diretor'           => 'Diretor',
            'prof_gerente_regional'  => 'Gerente Regional',
            'prof_gerente_distrital' => 'Gerente Distrital',
            'gestor'                 => 'Nome do Gestor',
        ];
    }

    public static function get_manager_cargo_codes(): array {
        return ['011', '045', '050', '062'];
    }
}

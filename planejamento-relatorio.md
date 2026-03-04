# Planejamento — Melhorias local_relatorio_treinamentos

**Data:** 2026-03-04  
**Branch de trabalho:** `feature/relatorio-melhorias` (no repo do plugin)

---

## Ordem de execução

### Tarefa 0 — Criar branch
```
cd custom-config/moodle/public/local/relatorio_treinamentos
git checkout -b feature/relatorio-melhorias
```

---

### Tarefa 2 — Nova query expandida *(base para tudo)*

Abandonar a query atual (subquery por shortname, incompleta) e adotar o padrão do
`consulta.sql` (JOINs diretos por fieldid), acrescida dos campos de curso.

**Campos de usuário** (todos os 51 campos de perfil via fieldid fixo):

| Grupo | Campos |
|---|---|
| Base Moodle | `username`, `nome_completo`, `email`, `city`, `idnumber`, `institution`, `department` |
| Dados pessoais | `dp_cpf` (31), `dp_cidade_colaborador` (8), `dp_uf` (9), `dp_data_nascimento` (15), `dp_sexo` (16), `dp_sid` (59) |
| Empresa/filial | `prof_tipo` (12), `prof_desc_tipo` (13), `prof_numero_empresa` (6), `prof_nome_empresa` (7), `prof_codigo_filial` (10), `prof_nome_filial` (11) |
| Distrito/local | `prof_numero_distrito` (22), `prof_nome_distrito` (23), `prof_bandeira` (17), `prof_numero_local` (39), `prof_nome_local` (40) |
| RH | `prof_data_admissao` (14), `prof_data_demissao` (51), `prof_codigo_cargo` (18), `prof_cargo` (19), `prof_codigo_grau_instrucao` (20), `prof_grau_instrucao` (21) |
| Hierarquia | `prof_hierarquia` (24), `prof_posicao` (25), `prof_diretor` (26), `prof_cargo_diretor` (28), `prof_hierarquia_diretor` (29), `prof_posicao_diretor` (30) |
| Ger. Regional | `prof_gerente_regional` (27), `prof_cargo_regional` (32), `prof_hierarquia_regional` (33), `prof_posicao_regional` (34) |
| Ger. Distrital | `prof_gerente_distrital` (35), `prof_cargo_distrital` (36), `prof_hierarquia_distrital` (37), `prof_posicao_distrital` (38) |
| Gestores | `gestor` (41), `gestor_ai` (42), `gestor_farmaceutico` (43), `gestor_farmaceutico_ai` (44) |
| Situação RH | `prof_codigo_situacao` (45), `prof_descricao_situacao` (46), `prof_data_inicio_situacao` (47), `prof_data_fim_situacao` (48) |
| Inscrição | `insc_data_admissao_inscricao` (53), `insc_data_demissao_inscricao` (54) |
| **Curso** | `nome_curso`, `progresso_percentual`, `concluido`, `nota`, `nome_grupo` |

**Correções técnicas:**
- `DISTINCT` para evitar duplicatas de grupos
- `EXTRACT(EPOCH FROM NOW())::INTEGER` (PostgreSQL)
- `::numeric` nos casts de nota e progresso
- Campos de data usam `to_timestamp(NULLIF(...)::bigint)` igual ao `consulta.sql`

**Teste:** script PHP temporário no servidor retornando 3 linhas para validar todos os campos.

**Commit:** `feat: expandir query com todos os campos de usuário e de curso`

---

### Tarefa 1 — Paginação com DataTables

- Inicializar DataTables na tabela via `$PAGE->requires->js_init_code()`
- Configurações: paginação (25/50/100/tudo), busca global, ordenação por coluna

**Commit:** `feat: adicionar paginação com DataTables`

---

### Tarefa 8 — Controle de acesso (admin vs gestor vs sem acesso)

**Fluxo:**
1. `require_login()` sempre obrigatório
2. `is_siteadmin()` → mostra todos os dados do cache
3. Senão: busca `prof_codigo_cargo` (fieldid=18) do usuário logado na DB
4. Se código for `011`, `045`, `050` ou `062` → filtra registros onde
   `gestor == CONCAT(firstname, ' ', lastname)` do usuário logado
5. Senão → `throw new moodle_exception('noaccess')` (acesso negado)

O filtro é aplicado **sobre o array do cache** (não altera o cache),
preservando dados completos para admins.

**Ajuste em `db/access.php`:** abrir a capability para archetype `user`
(lógica de negócio tratada no código).

**Commit:** `feat: controle de acesso admin/gestor com filtro por colaborador`

---

### Tarefa 3 — Menu flutuante de seleção de colunas

**UI:** `<div>` fixo à esquerda da tela (`position: fixed`), com botão toggle e
lista de checkboxes, uma por coluna.

**Comportamento:**
- Checkboxes controlam `display: none/''` nas colunas da tabela via JavaScript
- Estado inicial carregado dos **settings** (Tarefa 4) via variável PHP injetada no JS
- Estado salvo no `localStorage` do browser (sobrepõe o default após primeira visita)

**Commit:** `feat: menu flutuante de seleção de colunas`

---

### Tarefa 4 — Settings para colunas padrão

**Novo arquivo:** `settings.php`

**Configuração:** `admin_setting_configmulticheckbox` com todas as colunas listadas.
O valor salvo define quais colunas ficam visíveis por padrão (sem estado no localStorage).

**Commit:** `feat: settings para configurar colunas visíveis por padrão`

---

### Tarefa 5 — Painel de filtros

**UI:** painel colapsável acima da tabela com campos de filtro para colunas mais relevantes:
`prof_nome_filial`, `nome_curso`, `concluido`, `prof_diretor`,
`prof_gerente_regional`, `prof_gerente_distrital`

**Implementação:** filtros aplicados via DataTables API (`.column().search()`).
Estado dos filtros mantido em variáveis JS para transmissão ao download.

**Commit:** `feat: painel de filtros sobre os dados do relatório`

---

### Tarefa 6 — Downloads XLSX/CSV respeitando filtros e colunas

**Mecanismo:** botões de download ficam dentro de um `<form>` hidden.
Antes do submit, JavaScript preenche campos ocultos com:
- Lista de colunas selecionadas (JSON)
- Valores dos filtros ativos (JSON)

`download.php` aplica os filtros no array do cache, seleciona as colunas
escolhidas e exporta.

**Commit:** `feat: downloads respeitam filtros e colunas selecionadas`

---

### Tarefa 7 — Download ZIP agrupado por campo

**UI:** bloco de download com `<select>` para escolher campo de agrupamento + botão "Download ZIP".

**Opções do select de agrupamento:**
- Código da Filial (`prof_codigo_filial`)
- Nome da Filial (`prof_nome_filial`)
- Distrito (`prof_nome_distrito`)
- Bandeira (`prof_bandeira`)
- Diretor (`prof_diretor`)
- Gerente Regional (`prof_gerente_regional`)
- Gerente Distrital (`prof_gerente_distrital`)
- **Nome do Gestor (`gestor`)**

**Implementação em `download.php`** (parâmetro `formato=zip`):
1. Recebe campo de agrupamento + filtros + colunas via POST
2. Agrupa os dados do cache pelo campo escolhido
3. Para cada grupo: cria arquivo XLSX usando `MoodleExcelWorkbook`
4. Empacota todos com `ZipArchive`
5. Faz download do ZIP (`Content-Type: application/zip`)

**Commit:** `feat: download ZIP com arquivos agrupados por campo`

---

## Arquivos novos/modificados

| Arquivo | Status |
|---|---|
| `classes/task/atualizar_relatorio.php` | Modificado (nova query) |
| `index.php` | Reescrito (DataTables, menu, filtros, controle de acesso) |
| `download.php` | Expandido (colunas, filtros, ZIP) |
| `settings.php` | Novo |
| `db/access.php` | Modificado (abrir capability) |
| `lang/en/local_relatorio_treinamentos.php` | Expandido (novas strings) |
| `version.php` | Bump para 1.1.0 |

---

## Checklist de testes

- [ ] Query retorna dados corretos (script PHP, 3 linhas)
- [ ] DataTables pagina, ordena e busca corretamente
- [ ] Admin vê todos os dados
- [ ] Gestor vê apenas seus colaboradores (filtro por campo `gestor`)
- [ ] Usuário sem cargo de gestor recebe erro de acesso
- [ ] Menu de colunas oculta/mostra colunas na tabela
- [ ] Settings salva e estado padrão é aplicado na abertura
- [ ] Filtros funcionam e DataTables filtra corretamente
- [ ] Download XLSX/CSV respeita filtros e colunas
- [ ] Download ZIP cria um arquivo por grupo e empacota corretamente

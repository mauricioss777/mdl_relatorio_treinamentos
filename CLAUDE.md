# CLAUDE.md — local_relatorio_treinamentos

Plugin Moodle de relatório gerencial de treinamentos das Farmácias São João.

## Localização no servidor

- **Servidor:** `mss@192.168.200.5`
- **Projeto:** `/var/docker/moodle-saojoao-dev`
- **Plugin:** `custom-config/moodle/public/local/relatorio_treinamentos/`
- **Repositório:** `git@github.com:mauricioss777/mdl_relatorio_treinamentos.git`
- **Branch ativa:** `feature/relatorio-melhorias`
- **Moodle:** 5.1 (PostgreSQL 17)
- **URL dev:** `https://dna-dev.farmaciassaojoao.com.br`
- **Usuário de teste:** `claudecode` / `Cl@ude-C0de`

## Estrutura de arquivos

```
local/relatorio_treinamentos/
├── version.php                           # v1.1.0 (2026030404)
├── index.php                             # UI: DataTables SSR, menu colunas, filtros, downloads
├── ajax.php                              # Endpoint Server-Side DataTables
├── download.php                          # Exportação XLSX, CSV e ZIP agrupado
├── settings.php                          # Admin: colunas visíveis + estratégia
├── locallib.php                          # local_relatorio_treinamentos_create_customfield()
├── planejamento-relatorio.md             # Planejamento de melhorias (referência histórica)
├── classes/
│   ├── helper/columns.php                # Definição centralizada de todas as colunas
│   └── task/atualizar_relatorio.php      # buscar_dados() + get_cursos_no_filtro()
│       atualizar_relatorio.php.original  # backup da versão anterior (não commitar)
│       consulta.sql                      # SQL de referência (não commitar)
└── db/
    ├── access.php
    ├── tasks.php                         # PLURAL obrigatório (task.php singular = ignorado)
    ├── task.php                          # arquivo obsoleto — pode ser removido
    ├── caches.php
    ├── install.php                       # Cria customfield rt_incluir_filtro
    └── upgrade.php
```

## Arquitetura

236k+ registros — Server-Side DataTables com 3 estratégias (config `estrategia`):

| Estratégia | Como funciona | Performance |
|---|---|---|
| `view` (padrão) | LIMIT/OFFSET direto na view materializada PostgreSQL | ~0,6s |
| `cache` | Array PHP do cache gerado pela task (02:00) | rápido, mas ~4 GB RAM |
| `direct` | `buscar_dados()` a cada requisição | 28-40s, não recomendado |

**Fluxo view materializada:**
1. Task (02:00) → `REFRESH MATERIALIZED VIEW mdl_relatorio_treinamentos_mv`
2. `ajax.php` → SQL LIMIT/OFFSET direto na view + filtros aplicados via WHERE
3. `download.php` → lê view diretamente, aplica filtros, exporta

## Query — 57 colunas

- Chave: `CONCAT(u.id::text, '_', c.id::text) AS row_key`
- Deduplicação: `DISTINCT ON (ue2.userid, e2.courseid)` na subquery de matrículas
- PostgreSQL: `EXTRACT(EPOCH FROM NOW())::INTEGER`, `::numeric`, `to_timestamp(NULLIF(...)::bigint)`
- **NOWDOC obrigatório** para SQL com aspas simples (`<<<'RTSQL'`)

### Campos de perfil do Senior (fieldids)

| Grupo | fieldids |
|---|---|
| Dados pessoais | cpf(31), cidade(8), uf(9), nascimento(15), sexo(16), sid(59) |
| Empresa/Filial | tipo(12/13), empresa(6/7), filial(10/11) |
| Distrito/Local | distrito(22/23), bandeira(17), local(39/40) |
| RH | admissao(14), demissao(51), cargo(18/19), grau(20/21) |
| Hierarquia | hierarquia(24), posicao(25) |
| Diretor | diretor(26), cargo_dir(28), hier_dir(29), pos_dir(30) |
| Ger. Regional | ger_reg(27), cargo_reg(32), hier_reg(33), pos_reg(34) |
| Ger. Distrital | ger_dis(35), cargo_dis(36), hier_dis(37), pos_dis(38) |
| Gestores | gestor(41), gestor_ai(42), gestor_farm(43), gestor_farm_ai(44) |
| Situação RH | cod_sit(45), desc_sit(46), ini_sit(47), fim_sit(48) |
| Inscrição | adm_ins(53), dem_ins(54) |

## Controle de acesso

- **Admin Moodle** (`is_siteadmin()`): vê todos os dados
- **Manager Moodle** (`has_capability('local/relatorio_treinamentos:view')`): vê todos
- **Gestor** (fieldid=18 ∈ ['011','045','050','062']): vê apenas linhas onde `gestor == fullname($USER)`
- **Outros:** `moodle_exception('noaccess')`

Filtro aplicado **sobre o resultado da query** — não altera a view materializada.

## Armadilhas conhecidas

- `db/tasks.php` deve ser **plural** — `task.php` singular é ignorado pelo Moodle
- **PHP NOWDOC** (`<<<'RTSQL'`) obrigatório para SQL com aspas simples (PostgreSQL rejeita `\'`)
- **RequireJS vs DataTables CDN**: ocultar `window.define`/`window.require` antes de carregar DataTables; restaurar depois → evita `Mismatched anonymous define()`
- **JS em Moodle**: usar `$PAGE->requires->js_amd_inline()` — inline `<script>` antes de `$OUTPUT->footer()` não tem acesso ao `require()`
- **Bootstrap 5** no Moodle 5.x: `data-bs-toggle` / `data-bs-target` (não `data-toggle`)
- **Processing overlay**: NÃO usar `display: flex !important` — sobrescreve `display: none` inline do DataTables
- **PHP memory**: `ini_set('memory_limit', '4G')` na task; `PHP_MEMORY_LIMIT=-1` no `docker/.env` para web
- **`REFRESH MATERIALIZED VIEW CONCURRENTLY`** requer UNIQUE INDEX e não pode rodar em transação

## Comandos úteis no servidor

```bash
# Rodar scheduled task manualmente
ssh mss@192.168.200.5 "cd /var/docker/moodle-saojoao-dev/docker && docker compose exec moodle php /var/www/html/admin/tool/task/cli/schedule_task.php --execute='\local_relatorio_treinamentos\task\atualizar_relatorio'"

# Purge de cache + corrigir permissões
ssh mss@192.168.200.5 "cd /var/docker/moodle-saojoao-dev/docker && docker compose exec moodle php /var/www/html/admin/cli/purge_caches.php && chown -R www-data:www-data /var/moodledata/"

# Commit no plugin e push
ssh mss@192.168.200.5 bash << 'EOF'
cd /var/docker/moodle-saojoao-dev/custom-config/moodle/public/local/relatorio_treinamentos
git add -p
git commit -m "mensagem"
git push origin feature/relatorio-melhorias
EOF
```

## Cache

- `\cache::make('local_relatorio_treinamentos', 'relatorio')`
- Modo: `MODE_APPLICATION`
- Chaves: `dados`, `filter_options`, `ultima_atualizacao`

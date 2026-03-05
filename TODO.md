# TODO — local_relatorio_treinamentos

## [PENDENTE] Download XLSX para dataset completo (sem filtros)

**Data:** 2026-03-05
**Branch:** feature/relatorio-melhorias

### Problema

O download XLSX do relatório completo (~236k linhas × 57 colunas) é
inviável em tempo real: a geração demora mais de 10 minutos sem concluir.

O download CSV do mesmo dataset é rápido (streaming direto em `php://output`).

### O que foi implementado até aqui

- Um writer XLSX por streaming de XML puro (`rt_xlsx_stream()` em `download.php`)
  foi criado para substituir o `MoodleExcelWorkbook` (que sempre envia para
  `php://output`, inviabilizando o ZIP). O writer elimina o uso de
  PhpSpreadsheet, mas a geração do ZIP com muitos grupos ainda é lenta
  porque o ZipArchive precisa comprimir cada arquivo individualmente.

- **Solução de contorno atual:** o botão XLSX só aparece quando há filtros
  ativos (dataset menor → geração rápida). O ZIP usa CSV internamente.

### Soluções planejadas (a implementar)

#### Opção A — Streaming XLSX via XML bruto (já parcialmente implementado)

`rt_xlsx_stream()` já escreve o XML linha a linha. O gargalo restante é
a compressão pelo ZipArchive no ZIP. Para o XLSX simples (sem ZIP),
investigar se o tempo é aceitável com dataset grande filtrado vs. completo.

**Próximos passos:**
- Medir tempo de `rt_xlsx_stream()` para diferentes tamanhos de dataset
- Verificar se a compressão do ZipArchive é o gargalo ou a escrita XML

#### Opção B — Pré-geração após atualização da view (recomendada para full)

Após a task das 02:00 (`atualizar_relatorio`) executar o `REFRESH MATERIALIZED VIEW`,
gerar imediatamente os arquivos completos e salvar em disco:

```
$CFG->dataroot . '/local_relatorio_treinamentos/full_YYYYMMDD.csv'
$CFG->dataroot . '/local_relatorio_treinamentos/full_YYYYMMDD.xlsx'
```

Em `download.php`: se não há filtros ativos e o arquivo pré-gerado existe e é
do mesmo dia da view → servir com `readfile()` sem gerar nada.

**Novo setting:** `pregenerar_arquivos` (checkbox, default: off).

**Arquivos a modificar:**
- `classes/task/atualizar_relatorio.php` — adicionar geração pós-refresh
- `download.php` — verificar e servir arquivo pré-gerado
- `settings.php` — novo checkbox
- `locallib.php` — helper `local_relatorio_treinamentos_get_pregenerated_path()`

#### Combinação recomendada

- B para XLSX completo (sem filtros) — via pré-geração
- A (já implementado) para XLSX filtrado — via streaming em tempo real

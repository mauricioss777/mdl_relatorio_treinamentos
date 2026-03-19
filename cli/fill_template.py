#!/usr/bin/env python3
"""
fill_template.py — Preenche template XLSX preservando slicers/filtros/formatações.
Manipula ZIP/XML diretamente sem usar openpyxl para salvar, preservando todos os
recursos Excel (segmentações, AutoFilter, tabelas, conexões, queryTables, etc.).

Princípio: SÓ modificar o que é absolutamente necessário para inserir dados.
Não remover nenhum arquivo, atributo ou relationship do ZIP original.

Sintaxe no template: célula com valor exato {nome_coluna}
O script localiza a linha marcadora, substitui pelos dados, descarta linhas pré-existentes
abaixo da linha marcadora (dados do query table), e atualiza refs de tabela.

Uso: fill_template.py <template.xlsx> <output.xlsx> <dados.csv>
"""
import sys, re, csv, zipfile, io, copy
import xml.etree.ElementTree as ET

XNS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'

# ── Helpers de endereço ───────────────────────────────────────────────────────

def col_to_num(col):
    n = 0
    for c in col.upper():
        n = n * 26 + ord(c) - ord('A') + 1
    return n

def cell_addr(col_letter, row):
    return f'{col_letter}{row}'

def split_ref(ref):
    m = re.match(r'^([A-Z]+)(\d+)$', str(ref).upper().strip())
    if not m:
        return None, None
    return m.group(1), int(m.group(2))

# ── Namespace registration ────────────────────────────────────────────────────

_COMMON_NS = {
    '':      XNS,
    'r':     'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
    'mc':    'http://schemas.openxmlformats.org/markup-compatibility/2006',
    'x14ac': 'http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac',
    'xr':    'http://schemas.microsoft.com/office/spreadsheetml/2014/revision',
    'xr2':   'http://schemas.microsoft.com/office/spreadsheetml/2015/revision2',
    'xr3':   'http://schemas.microsoft.com/office/spreadsheetml/2016/revision3',
    'xml':   'http://www.w3.org/XML/1998/namespace',
}
for _pfx, _uri in _COMMON_NS.items():
    try:
        ET.register_namespace(_pfx, _uri)
    except Exception:
        pass

def register_all_ns(xml_bytes):
    try:
        for event, elem in ET.iterparse(io.BytesIO(xml_bytes), events=['start-ns']):
            pfx, uri = elem
            try:
                ET.register_namespace(pfx, uri)
            except Exception:
                pass
    except ET.ParseError:
        pass

# ── Shared strings ────────────────────────────────────────────────────────────

def read_shared_strings(zf):
    try:
        xml = zf.read('xl/sharedStrings.xml')
    except KeyError:
        return []
    root = ET.fromstring(xml)
    result = []
    for si in root.findall(f'{{{XNS}}}si'):
        t_elem = si.find(f'{{{XNS}}}t')
        if t_elem is not None:
            result.append(t_elem.text or '')
        else:
            parts = []
            for run in si.findall(f'{{{XNS}}}r'):
                tt = run.find(f'{{{XNS}}}t')
                if tt is not None:
                    parts.append(tt.text or '')
            result.append(''.join(parts))
    return result

def get_cell_str_value(c_elem, shared_strings):
    t = c_elem.get('t', '')
    if t == 's':
        v = c_elem.find(f'{{{XNS}}}v')
        if v is not None and v.text is not None:
            idx = int(v.text)
            return shared_strings[idx] if idx < len(shared_strings) else ''
    elif t == 'inlineStr':
        is_e = c_elem.find(f'{{{XNS}}}is')
        if is_e is not None:
            t_e = is_e.find(f'{{{XNS}}}t')
            return t_e.text or '' if t_e is not None else ''
    elif t in ('str', 'b', 'e'):
        v = c_elem.find(f'{{{XNS}}}v')
        return v.text or '' if v is not None else ''
    return ''

# ── Cell creation ─────────────────────────────────────────────────────────────

def make_cell(col_letter, row, value):
    c = ET.Element(f'{{{XNS}}}c')
    c.set('r', cell_addr(col_letter, row))

    if value is None or str(value) == '':
        return c

    sval = str(value)

    # Detectar número
    try:
        float(sval)
        v_e = ET.SubElement(c, f'{{{XNS}}}v')
        v_e.text = sval
        return c
    except ValueError:
        pass

    # String inline
    c.set('t', 'inlineStr')
    is_e = ET.SubElement(c, f'{{{XNS}}}is')
    t_e = ET.SubElement(is_e, f'{{{XNS}}}t')
    t_e.text = sval
    if sval != sval.strip():
        t_e.set('{http://www.w3.org/XML/1998/namespace}space', 'preserve')
    return c

# ── Rels parsing ──────────────────────────────────────────────────────────────

def get_sheet_table_paths(zf, sheet_path):
    """Retorna lista de paths de tabelas referenciadas pela planilha."""
    parts = sheet_path.rsplit('/', 1)
    if len(parts) != 2:
        return []
    rels_path = f'{parts[0]}/_rels/{parts[1]}.rels'
    try:
        xml = zf.read(rels_path)
    except KeyError:
        return []

    REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships'
    TABLE_TYPE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/table'
    root = ET.fromstring(xml)
    tables = []
    for rel in root.findall(f'{{{REL_NS}}}Relationship'):
        if rel.get('Type') == TABLE_TYPE:
            target = rel.get('Target', '')
            # Resolver path relativo
            base_parts = sheet_path.split('/')[:-1]
            resolved = base_parts + target.split('/')
            normalized = []
            for p in resolved:
                if p == '..':
                    if normalized:
                        normalized.pop()
                elif p and p != '.':
                    normalized.append(p)
            tables.append('/'.join(normalized))
    return tables

# ── XML serialization ─────────────────────────────────────────────────────────

def get_ns_declarations(xml_bytes):
    """Extrai todas as declarações de namespace do XML (prefix -> uri)."""
    ns_map = {}
    try:
        for event, elem in ET.iterparse(io.BytesIO(xml_bytes), events=['start-ns']):
            pfx, uri = elem
            if pfx not in ns_map:
                ns_map[pfx] = uri
    except ET.ParseError:
        pass
    return ns_map

def serialize_xml(root, original_xml_bytes=None):
    """Serializa root para bytes UTF-8. Se original_xml_bytes fornecido,
    garante que todas as declarações de namespace do original estejam presentes
    (necessário para preservar prefixos como xr2/xr3 usados em mc:Ignorable)."""
    xml_str = ET.tostring(root, encoding='unicode')
    declaration = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>\r\n'

    if original_xml_bytes is not None:
        # Encontrar namespaces declarados no original mas ausentes no output
        orig_ns = get_ns_declarations(original_xml_bytes)
        out_ns = get_ns_declarations((declaration + xml_str).encode('utf-8'))
        missing = [(pfx, uri) for pfx, uri in orig_ns.items()
                   if pfx not in out_ns and pfx != '']
        if missing:
            inject = ' '.join(f'xmlns:{pfx}="{uri}"' for pfx, uri in missing)
            # Injetar após o nome do elemento raiz (antes do primeiro atributo ou >)
            m = re.search(r'(<\w[\w:]*)', xml_str)
            if m:
                pos = m.end()
                xml_str = xml_str[:pos] + ' ' + inject + xml_str[pos:]

    return (declaration + xml_str).encode('utf-8')

# ── Worksheet modification ────────────────────────────────────────────────────

def fill_sheet_xml(xml_bytes, markers, marker_row, data_rows):
    """
    Substitui a linha marcadora e remove linhas pré-existentes abaixo dela.
    Linhas ACIMA do marker_row são preservadas intactas.
    Linhas ABAIXO do marker_row são DESCARTADAS (eram dados do query table).
    As N linhas de dados são escritas a partir de marker_row.

    dimension e autoFilter: preserva colunas originais, só atualiza linha final.
    """
    register_all_ns(xml_bytes)
    root = ET.fromstring(xml_bytes)
    sheetData = root.find(f'{{{XNS}}}sheetData')

    if sheetData is None or not markers or marker_row is None:
        return xml_bytes

    n_data = len(data_rows)

    # Coletar linhas antes do marker
    rows_before = []
    template_row_elem = None
    for row_e in sheetData.findall(f'{{{XNS}}}row'):
        rnum = int(row_e.get('r', 0))
        if rnum < marker_row:
            rows_before.append(row_e)
        elif rnum == marker_row:
            template_row_elem = row_e
        # Linhas após marker_row são descartadas (dados pré-existentes do query table)

    # Limpar sheetData
    for child in list(sheetData):
        sheetData.remove(child)

    # Reinserir linhas antes do marker
    for row_e in rows_before:
        sheetData.append(row_e)

    # Inserir linhas de dados
    for i, dr in enumerate(data_rows):
        target_row = marker_row + i

        if i == 0 and template_row_elem is not None:
            # Primeira linha de dados: usa template como base (estilos, altura, etc.)
            new_row = copy.deepcopy(template_row_elem)
        else:
            new_row = ET.Element(f'{{{XNS}}}row')
            new_row.set('r', str(target_row))
            if template_row_elem is not None:
                for attr in ['ht', 'customHeight', 'spans']:
                    v = template_row_elem.get(attr)
                    if v:
                        new_row.set(attr, v)

        new_row.set('r', str(target_row))

        if i == 0:
            # Substituir células marcadoras, manter demais células da linha template
            for c_e in list(new_row.findall(f'{{{XNS}}}c')):
                col_l, _ = split_ref(c_e.get('r', ''))
                if col_l in markers:
                    new_row.remove(c_e)
                    value = dr.get(markers[col_l], '')
                    new_row.append(make_cell(col_l, target_row, value))
                elif col_l:
                    c_e.set('r', cell_addr(col_l, target_row))
        else:
            # Linhas subsequentes: apenas células de dados
            for c_e in list(new_row.findall(f'{{{XNS}}}c')):
                new_row.remove(c_e)
            for col_l in sorted(markers.keys(), key=col_to_num):
                value = dr.get(markers[col_l], '')
                new_row.append(make_cell(col_l, target_row, value))

        sheetData.append(new_row)

    # Atualizar dimension — preservar colunas originais, só mudar linha final
    dim = root.find(f'{{{XNS}}}dimension')
    if dim is not None:
        old_ref = dim.get('ref', '')
        if ':' in old_ref:
            start_part, end_part = old_ref.split(':', 1)
            end_col, _ = split_ref(end_part)
            if end_col:
                last_data_row = marker_row + n_data - 1
                dim.set('ref', f'{start_part}:{cell_addr(end_col, last_data_row)}')

    # Atualizar autoFilter do worksheet se existir — preservar colunas originais
    af = root.find(f'{{{XNS}}}autoFilter')
    if af is not None:
        old_ref = af.get('ref', '')
        if ':' in old_ref:
            start_part, end_part = old_ref.split(':', 1)
            end_col, _ = split_ref(end_part)
            if end_col:
                last_data_row = marker_row + n_data - 1
                af.set('ref', f'{start_part}:{cell_addr(end_col, last_data_row)}')

    return serialize_xml(root, xml_bytes)

# ── Table modification ────────────────────────────────────────────────────────

def update_table_xml(xml_bytes, marker_row, n_data):
    """
    Atualiza APENAS o número de linhas no ref da tabela e no autoFilter.
    Preserva todos os atributos (tableType, queryTableFieldId, etc.).
    """
    register_all_ns(xml_bytes)
    root = ET.fromstring(xml_bytes)

    old_ref = root.get('ref', '')
    if ':' not in old_ref:
        return xml_bytes

    start_part, end_part = old_ref.split(':', 1)
    end_col, _ = split_ref(end_part)
    if not end_col:
        return xml_bytes

    new_end_row = marker_row + n_data - 1
    new_ref = f'{start_part}:{cell_addr(end_col, new_end_row)}'
    root.set('ref', new_ref)

    # Atualizar autoFilter da tabela se existir
    af = root.find(f'{{{XNS}}}autoFilter')
    if af is not None:
        af.set('ref', new_ref)

    return serialize_xml(root, xml_bytes)

# ── Main pipeline ─────────────────────────────────────────────────────────────

def fill_template(template_path, output_path, csv_path):
    # Ler CSV
    data_rows = []
    with open(csv_path, encoding='utf-8-sig', newline='') as f:
        reader = csv.DictReader(f, delimiter=';')
        for row in reader:
            data_rows.append(dict(row))

    if not data_rows:
        import shutil
        shutil.copy2(template_path, output_path)
        print('Nenhum dado — template copiado sem alteração.')
        return

    col_names = set(data_rows[0].keys())

    with zipfile.ZipFile(template_path, 'r') as zf_in:
        shared_strings = read_shared_strings(zf_in)

        # Descobrir planilhas
        sheet_paths = sorted(
            n for n in zf_in.namelist()
            if re.match(r'^xl/worksheets/sheet\d+\.xml$', n)
        )

        # Encontrar marcadores em cada planilha
        sheet_markers = {}
        sheet_marker_rows = {}

        for sp in sheet_paths:
            xml_bytes = zf_in.read(sp)
            register_all_ns(xml_bytes)
            root = ET.fromstring(xml_bytes)
            sheetData = root.find(f'{{{XNS}}}sheetData')
            if sheetData is None:
                continue

            markers = {}
            marker_row = None

            for row_e in sheetData.findall(f'{{{XNS}}}row'):
                for c_e in row_e.findall(f'{{{XNS}}}c'):
                    val = get_cell_str_value(c_e, shared_strings)
                    if not val:
                        continue
                    m = re.match(r'^\{(\w+)\}$', val.strip())
                    if m and m.group(1) in col_names:
                        col_l, rnum = split_ref(c_e.get('r', ''))
                        if col_l:
                            markers[col_l] = m.group(1)
                            if marker_row is None:
                                marker_row = rnum

            if markers:
                sheet_markers[sp] = markers
                sheet_marker_rows[sp] = marker_row

        # Tabelas afetadas
        affected_tables = {}  # table_path -> (marker_row, n_data)
        for sp in sheet_markers:
            for tp in get_sheet_table_paths(zf_in, sp):
                affected_tables[tp] = (sheet_marker_rows[sp], len(data_rows))

        n_data = len(data_rows)

        # Escrever ZIP de saída — copiar tudo, só modificar sheet e table XMLs
        with zipfile.ZipFile(output_path, 'w', zipfile.ZIP_DEFLATED) as zf_out:
            for item in zf_in.namelist():
                item_bytes = zf_in.read(item)

                if item in sheet_markers:
                    markers = sheet_markers[item]
                    marker_row = sheet_marker_rows[item]
                    print(f'  Planilha {item}: {len(markers)} colunas, {n_data} linhas (marker row={marker_row})')
                    item_bytes = fill_sheet_xml(item_bytes, markers, marker_row, data_rows)

                elif item in affected_tables:
                    mrow, nd = affected_tables[item]
                    print(f'  Tabela {item}: ref → row {mrow} + {nd} linhas')
                    item_bytes = update_table_xml(item_bytes, mrow, nd)

                # Tudo mais: copiar byte a byte sem modificação

                zf_out.writestr(item, item_bytes)

    print(f'OK: {n_data} linhas escritas em {output_path}')


if __name__ == '__main__':
    if len(sys.argv) != 4:
        print(f'Uso: {sys.argv[0]} <template.xlsx> <output.xlsx> <dados.csv>', file=sys.stderr)
        sys.exit(1)
    fill_template(sys.argv[1], sys.argv[2], sys.argv[3])

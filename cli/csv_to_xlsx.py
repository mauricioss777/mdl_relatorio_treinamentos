#!/usr/bin/env python3
"""
Converte um arquivo CSV (separador ';', UTF-8 BOM) para XLSX.
Uso: python3 csv_to_xlsx.py <csv_input> <xlsx_output>
"""
import sys
import pandas as pd

if len(sys.argv) < 3:
    print("Uso: csv_to_xlsx.py <csv_input> <xlsx_output>", file=sys.stderr)
    sys.exit(1)

csv_in  = sys.argv[1]
xlsx_out = sys.argv[2]

df = pd.read_csv(csv_in, sep=';', encoding='utf-8-sig', dtype=str)
df = df.fillna('')

with pd.ExcelWriter(xlsx_out, engine='openpyxl') as writer:
    df.to_excel(writer, index=False, sheet_name='Relatório')

#!/usr/bin/env python3
"""
Converte CSV(s) para XLSX.

Modos:
  Arquivo único:  python3 csv_to_xlsx.py <input.csv> <output.xlsx>
  Diretório:      python3 csv_to_xlsx.py --dir <diretório>
                  Converte todos os *.csv do diretório para *.xlsx (remove os CSVs).
"""
import sys
import os
import glob
import pandas as pd


def convert(csv_in: str, xlsx_out: str) -> None:
    df = pd.read_csv(csv_in, sep=';', encoding='utf-8-sig', dtype=str).fillna('')
    with pd.ExcelWriter(xlsx_out, engine='openpyxl') as writer:
        df.to_excel(writer, index=False, sheet_name='Relatório')


if len(sys.argv) < 2:
    print(__doc__, file=sys.stderr)
    sys.exit(1)

if sys.argv[1] == '--dir':
    if len(sys.argv) < 3:
        print("Uso: csv_to_xlsx.py --dir <diretório>", file=sys.stderr)
        sys.exit(1)
    directory = sys.argv[2]
    csv_files = sorted(glob.glob(os.path.join(directory, '*.csv')))
    if not csv_files:
        print(f"Nenhum CSV encontrado em: {directory}", file=sys.stderr)
        sys.exit(0)
    for csv_path in csv_files:
        xlsx_path = os.path.splitext(csv_path)[0] + '.xlsx'
        convert(csv_path, xlsx_path)
        os.remove(csv_path)
else:
    if len(sys.argv) < 3:
        print("Uso: csv_to_xlsx.py <input.csv> <output.xlsx>", file=sys.stderr)
        sys.exit(1)
    convert(sys.argv[1], sys.argv[2])

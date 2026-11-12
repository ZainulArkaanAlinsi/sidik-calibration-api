"""Hitung jumlah baris per tabel di dalam berkas cadangan mysqldump (extended insert).
Hanya mencetak NAMA TABEL dan JUMLAH BARIS. Tidak mencetak isi baris.

    python -I docs/skrip/hitung-baris-cadangan.py <berkas.sql> [tabel1,tabel2,...]

Tanpa daftar tabel, semua tabel dicetak. Dipakai runbook impor awal data acuan
(`docs/runbook-impor-acuan-produksi.md` §4 langkah 1) untuk mengadu jumlah baris DI DALAM
berkas cadangan dengan sensus produksi. Ini bukti bahwa berkasnya memuat data, BUKAN bukti
bahwa berkasnya bisa dipulihkan."""
import re
import sys
from collections import Counter

path = sys.argv[1]
penting = set(sys.argv[2].split(',')) if len(sys.argv) > 2 else None

cocok = re.compile(r'^INSERT INTO `([^`]+)` (?:\([^)]*\) )?VALUES ')
jumlah = Counter()
create = []

with open(path, 'r', encoding='utf-8', errors='replace', newline='') as f:
    for baris in f:
        if baris.startswith('CREATE TABLE `'):
            create.append(baris.split('`')[1])
            continue
        m = cocok.match(baris)
        if not m:
            continue
        tabel = m.group(1)
        i = m.end()
        n = len(baris)
        kedalaman = 0
        dalam_str = False
        while i < n:
            c = baris[i]
            if dalam_str:
                if c == '\\':
                    i += 2
                    continue
                if c == "'":
                    # '' di dalam string = kutip literal
                    if i + 1 < n and baris[i + 1] == "'":
                        i += 2
                        continue
                    dalam_str = False
            else:
                if c == "'":
                    dalam_str = True
                elif c == '(':
                    if kedalaman == 0:
                        jumlah[tabel] += 1
                    kedalaman += 1
                elif c == ')':
                    kedalaman -= 1
            i += 1

print('jumlah CREATE TABLE:', len(create))
print('tabel ber-INSERT   :', len(jumlah))
for t in sorted(create):
    if penting is None or t in penting:
        print(f'  {t}: {jumlah.get(t, 0)}')

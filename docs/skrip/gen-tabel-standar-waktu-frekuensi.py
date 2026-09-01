"""Generate database/data/tabel-standar-{putaran,waktu}.json dari workbook master.

Dijalankan sekali; keluarannya ikut commit. JANGAN diketik tangan.
"""
import openpyxl, json, math, os, datetime

def num(c): return float(c.value) if isinstance(c.value, (int, float)) else None
def bulat(x, n=10): return None if x is None else round(x, n)

os.chdir(os.path.dirname(os.path.abspath(__file__)))
# Berkas hasil ditulis ke tujuan yang DIPAKAI, bukan di sebelah skrip ini.
# `os.chdir` di atas ada supaya workbook master kebaca dari folder ini; kalau
# jalur tulisnya ikut relatif ke situ, skrip ini "berhasil" sambil meninggalkan
# berkas yang di-commit tidak berubah — dan yang menjalankannya tidak tahu.
AKAR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


# ---------------------------------------------------------------- PUTARAN (rpm)
wb = openpyxl.load_workbook('tachometer.xlsm', data_only=True)
S, Dr = wb['SERTIFIKAT KALIBRATOR'], wb['Drift Std Kalibrator']

titik_cert = []
for r in range(9, 22):
    nom = num(S[f'C{r}'])
    if nom is None: continue
    titik_cert.append({
        'nominal': bulat(nom),
        'uut': bulat(num(S[f'D{r}'])),
        'koreksi': bulat(num(S[f'E{r}'])),
        'u95': bulat(num(S[f'F{r}'])),
    })

# Drift: rentang koreksi lintas SEMUA snapshot, per titik. Kolom K master cuma
# terisi di 5 dari 13 baris berdata; di sini dihitung untuk SETIAP baris.
snapshot = []
for c in ('D', 'E', 'F', 'G', 'H', 'I', 'J'):
    tgl = Dr[f'{c}10'].value
    if isinstance(tgl, datetime.datetime):
        snapshot.append({'kolom': c, 'tanggal': tgl.date().isoformat(),
                         'lab': Dr[f'{c}9'].value})
drift_titik = []
for r in range(13, 35):
    nom = num(Dr[f'C{r}'])
    if nom is None: continue
    vals = {c: num(Dr[f'{c}{r}']) for c in ('D','E','F','G','H','I','J')}
    ada = [v for v in vals.values() if v is not None]
    if len(ada) < 2: continue
    drift_titik.append({
        'nominal': bulat(nom),
        'koreksi': {c: bulat(v) for c, v in vals.items() if v is not None},
        'rentang': bulat(abs(min(ada) - max(ada))),
        'dihitung_master': num(Dr[f'K{r}']) is not None,
    })

putaran = {
    '_sumber': 'Master Olda Tachometer.xlsm / Master Olda Centrifuge.xlsm (sheet '
               'SERTIFIKAT KALIBRATOR & Drift Std Kalibrator) — kedua workbook '
               'memuat tabel yang IDENTIK untuk keping standar yang sama.',
    '_digenerate_oleh': 'docs/skrip/gen-tabel-standar-waktu-frekuensi.py',
    'standar': {
        'nama': S['C1'].value, 'merk': S['C2'].value,
        'resolusi': S['C3'].value, 'seri': S['C4'].value,
        'tanggal_kalibrasi': S['C5'].value.date().isoformat(),
        'traceability': 'LK-305-IDN',
    },
    'sertifikat': titik_cert,
    'drift': {
        'snapshot': snapshot,
        'hari_master': int(num(Dr['L8'])),
        'titik': drift_titik,
        'rentang_maks_master': bulat(max(t['rentang'] for t in drift_titik if t['dihitung_master'])),
        'rentang_maks_lengkap': bulat(max(t['rentang'] for t in drift_titik)),
    },
}

# ---------------------------------------------------------------- WAKTU (detik)
wb2 = openpyxl.load_workbook('timer.xlsm', data_only=True)
S2, Dr2, HR = wb2['SERTIFIKAT KALIBRATOR'], wb2['Drift Stopwatch'], wb2['Human Reaction']

titik2 = []
for r in range(9, 22):
    nom = num(S2[f'C{r}'])
    if nom is None: continue
    titik2.append({'nominal_detik': bulat(nom), 'koreksi_ms': bulat(num(S2[f'E{r}'])),
                   'u95_detik': bulat(num(S2[f'F{r}']))})

snap2 = []
for c in ('F', 'G', 'H', 'I', 'J'):
    tgl = Dr2[f'{c}9'].value
    if isinstance(tgl, datetime.datetime):
        snap2.append({'kolom': c, 'tanggal': tgl.date().isoformat(), 'lab': Dr2[f'{c}8'].value})
drift2 = []
for r in range(12, 26):
    j, m, s = num(Dr2[f'B{r}']), num(Dr2[f'C{r}']), num(Dr2[f'D{r}'])
    if j is None and m is None and s is None: continue
    nom = (j or 0) * 3600 + (m or 0) * 60 + (s or 0)
    vals = {c: num(Dr2[f'{c}{r}']) for c in ('F','G','H','I','J')}
    ada = [v for v in vals.values() if v is not None]
    if len(ada) < 2: continue
    drift2.append({'nominal_detik': bulat(nom),
                   'koreksi_ms': {c: bulat(v) for c, v in vals.items() if v is not None},
                   'rentang_ms': bulat(abs(min(ada) - max(ada))),
                   'dihitung_master': num(Dr2[f'K{r}']) is not None})

operator = []
for r in (19, 20, 21, 22):
    baris_std = 2 * (r - 19) + 8
    beda = [num(HR[f'{c}{baris_std}']) - num(HR[f'{c}{baris_std+1}']) for c in 'DEFGHIJKLM']
    rata = sum(beda) / len(beda)
    sd = math.sqrt(sum((x - rata) ** 2 for x in beda) / (len(beda) - 1))
    operator.append({'inisial': HR[f'C{r}'].value, 'nominal_detik': bulat(num(HR[f'B{baris_std}'])),
                     'beda': [bulat(b) for b in beda], 'rata_rata': bulat(rata), 'stdev': bulat(sd)})

waktu = {
    '_sumber': 'Master Olda Timer dan Stopwatch.xlsm (sheet SERTIFIKAT KALIBRATOR, '
               'Drift Stopwatch, Human Reaction).',
    '_digenerate_oleh': 'docs/skrip/gen-tabel-standar-waktu-frekuensi.py',
    'standar': {'nama': S2['C1'].value, 'merk': S2['C2'].value, 'resolusi': S2['C3'].value,
                'seri': S2['C4'].value, 'tanggal_kalibrasi': S2['C5'].value.date().isoformat()},
    'sertifikat': titik2,
    'drift': {'snapshot': snap2, 'hari_master': int(num(Dr2['L7'])), 'titik': drift2,
              'rentang_maks_master_ms': bulat(max(t['rentang_ms'] for t in drift2 if t['dihitung_master'])),
              'rentang_maks_lengkap_ms': bulat(max(t['rentang_ms'] for t in drift2))},
    'human_reaction': {
        'operator': operator,
        'rata_maks_master': bulat(max(o['rata_rata'] for o in operator[2:])),
        'rata_maks_lengkap': bulat(max(o['rata_rata'] for o in operator)),
        'stdev_maks': bulat(max(o['stdev'] for o in operator)),
    },
}

for nama, isi in (('putaran', putaran), ('waktu', waktu)):
    p = os.path.join(AKAR, 'database', 'data', f'tabel-standar-{nama}.json')
    with open(p, 'w', encoding='utf-8') as f:
        json.dump(isi, f, ensure_ascii=False, indent=2)
        f.write('\n')
    print(f"  {p}: {len(json.dumps(isi))} byte")

print("\nRINGKASAN:")
print(f"  putaran: {len(titik_cert)} titik sertifikat, {len(drift_titik)} titik drift "
      f"(master hitung {sum(t['dihitung_master'] for t in drift_titik)})")
print(f"    rentang maks master={putaran['drift']['rentang_maks_master']} lengkap={putaran['drift']['rentang_maks_lengkap']}")
print(f"  waktu:   {len(titik2)} titik sertifikat, {len(drift2)} titik drift "
      f"(master hitung {sum(t['dihitung_master'] for t in drift2)}), {len(operator)} operator")
print(f"    rentang maks master={waktu['drift']['rentang_maks_master_ms']} lengkap={waktu['drift']['rentang_maks_lengkap_ms']}")
print(f"    HRTB master={waktu['human_reaction']['rata_maks_master']} lengkap={waktu['human_reaction']['rata_maks_lengkap']}")

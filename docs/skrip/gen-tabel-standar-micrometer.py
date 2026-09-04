"""Generate database/data/tabel-standar-micrometer.json dari empat workbook master.

Dijalankan sekali; keluarannya ikut commit. JANGAN diketik tangan.

Keempat master (0-25, 25-50, 50-75, 75-100 mm) memuat tabel balok ukur dan
rumus yang IDENTIK — skrip ini mengadu keempatnya dan menolak menulis kalau
ada yang menyimpang, supaya perbedaan diam-diam tidak lolos jadi data.

Pakai:
    python3 gen-tabel-standar-micrometer.py <dir-berisi-mikro-*.xlsm>
"""
import openpyxl, json, os, sys

def num(c):
    return float(c.value) if isinstance(c.value, (int, float)) else None

def bulat(x, n=10):
    return None if x is None else round(x, n)

# Berkas hasil ditulis ke tujuan yang DIPAKAI, bukan di sebelah skrip ini.
AKAR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SUMBER = sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(os.path.abspath(__file__))

VARIAN = [
    ('025',   'A', '0-25 mm',    0.0,  25.0),
    ('2550',  'B', '25-50 mm',  25.0,  50.0),
    ('5075',  'C', '50-75 mm',  50.0,  75.0),
    ('75100', 'D', '75-100 mm', 75.0, 100.0),
]

def baca(kode):
    return openpyxl.load_workbook(f'{SUMBER}/mikro-{kode}.xlsm', data_only=True)

# ------------------------------------------------- balok ukur (Nominal_mm)
# Standar_GB!Q10:R132. Baris ber-Q kosong SENGAJA dilewati: di master nilai R-nya
# terbaca 0 karena rumusnya menunjuk sel kosong, dan angka 0 itu bukan panjang
# balok — dia artefak. Ikut disalin, dia jadi balok ukur bernilai nol.
def tabel_balok(wb):
    S = wb['Standar_GB']
    out = {}
    for r in range(10, 133):
        nom, nilai = num(S[f'Q{r}']), num(S[f'R{r}'])
        if nom is None or nilai is None:
            continue
        out[repr(bulat(nom))] = bulat(nilai)
    return out

wb0 = baca('025')
balok = tabel_balok(wb0)

# Keempat master WAJIB sepakat — kalau tidak, itu temuan yang harus dibaca
# manusia, bukan diselesaikan skrip dengan memilih salah satu.
for kode, *_ in VARIAN[1:]:
    lain = tabel_balok(baca(kode))
    if lain != balok:
        beda = {k for k in set(balok) | set(lain) if balok.get(k) != lain.get(k)}
        raise SystemExit(f'BEDA tabel balok ukur di {kode}: {sorted(beda)}')

# ------------------------------------------------- pita CMC (DATABASE!R5:T8)
DB = wb0['DATABASE']
cmc = []
for i, (_, huruf, label, bawah, atas) in enumerate(VARIAN):
    r = 5 + i
    cmc.append({
        'kode': huruf,
        'label': DB[f'S{r}'].value,
        'kapasitas_min_mm': bawah,
        'kapasitas_maks_mm': atas,
        'u95_um': bulat(num(DB[f'T{r}'])),
    })

standar = {
    'nama': DB['S13'].value,
    'merk_tipe': DB['T13'].value,
    'seri': str(DB['U13'].value),
    'traceability': DB['V13'].value,
    'tanggal_kalibrasi': DB['W13'].value.date().isoformat(),
}

hasil = {
    '_sumber': 'Master_Olah_Data_Micrometer_{025,2550,5075,75100}mm.xlsm '
               '(sheet Standar_GB & DATABASE) — keempat workbook memuat tabel '
               'balok ukur yang IDENTIK, sudah diadu oleh skrip generator ini.',
    '_digenerate_oleh': 'docs/skrip/gen-tabel-standar-micrometer.py',
    'standar': standar,
    'balok_ukur': balok,
    # PERHITUNGAN!H23 — bertingkat, dibaca dari atas, yang pertama cocok menang.
    # Ambang 76,2 di master ditulis terpisah padahal sudah tertutup "<=100";
    # cabang itu mati dan sengaja tidak disalin.
    'ketidakpastian_balok_um': {
        'aturan': [
            {'maks': 10.0, 'u': 0.12},
            {'maks': 21.0, 'u': 0.14},
            {'maks': 50.0, 'u': 0.25},
            {'maks': 100.0, 'u': 0.26},
        ],
        'persis': {'101.6': 0.26, '200': 0.73},
        'lainnya': 0.0,
    },
    'cmc': cmc,
    # Tetapan budget yang di master hidup sebagai angka telanjang di rumus.
    'konstanta': {
        'delta_alpha_per_c': 1e-05,
        'wringing_um': round((5 * (0.05 ** 2)) ** 0.5, 12),
        'geometri_um': 0.5,
        'drift_a_um': 0.02,
        'drift_b_um_per_mm': 0.00025,
        'suhu_acuan_c': 20.0,
        'vi_type_b': 200,
    },
}

tujuan = f'{AKAR}/database/data/tabel-standar-micrometer.json'
with open(tujuan, 'w', encoding='utf-8') as fh:
    json.dump(hasil, fh, indent=2, ensure_ascii=False)
    fh.write('\n')
print(f'ditulis: {tujuan}  ({len(balok)} balok ukur, {len(cmc)} pita CMC)')

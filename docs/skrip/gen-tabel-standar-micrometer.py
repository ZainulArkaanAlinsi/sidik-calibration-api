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

# Nominal balok ukur PRA-CETAK tiap varian, disalin dari kertas lembar kerja
# SIDIK-FM-CAL-0522.{A,B,C,D}_Rev.1 yang turun 4 Sep 2026.
#
# Deretnya BUKAN aritmetika, dan itu bukan salah cetak: nominal ketiga varian B
# (31,0) dan C (51,0) melanggar pola +2,55 yang diikuti A dan D, tapi keduanya
# cocok dengan total nominal yang benar-benar dihitung master (30,99997 dan
# 51,00025). Tumpukan balok yang tersedia yang menentukan, bukan deret.
# Diadu titik demi titik di bawah — 44 titik, dan skrip menolak menulis kalau
# ada satu pun yang meleset.
VARIAN = [
    ('025',   'A', '0-25 mm',    0.0,  25.0,
     [0.0, 2.5, 5.1, 7.7, 10.3, 12.9, 15.0, 17.6, 20.2, 22.8, 25.0]),
    ('2550',  'B', '25-50 mm',  25.0,  50.0,
     [25.0, 27.5, 31.0, 32.7, 35.3, 37.9, 40.0, 42.6, 45.2, 47.8, 50.0]),
    ('5075',  'C', '50-75 mm',  50.0,  75.0,
     [50.0, 52.5, 51.0, 57.7, 60.3, 62.9, 65.0, 67.6, 70.2, 72.8, 75.0]),
    ('75100', 'D', '75-100 mm', 75.0, 100.0,
     [75.0, 77.5, 80.1, 82.7, 85.3, 87.9, 90.0, 92.6, 95.2, 97.8, 100.0]),
]

# Toleransi adu nominal kertas vs total nominal master. Longgar 0,06 mm karena
# yang di kertas nominal CETAK (satu desimal) sementara master menjumlahkan
# nilai bersertifikat tiap keping.
TOLERANSI_NOMINAL_MM = 0.06

BARIS_TITIK = list(range(31, 62, 3))

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
for i, (kode, huruf, label, bawah, atas, nominal) in enumerate(VARIAN):
    r = 5 + i
    wb = baca(kode)
    P = wb['PERHITUNGAN']

    # Tumpukan balok ukur tiap titik, dari master. Teknisi TIDAK mengetiknya —
    # kertas cuma mencetak nominal totalnya, dan tumpukan yang membentuknya
    # ditentukan Instruksi Kerja. Sampai LIMA keping, bukan tiga.
    titik = []
    for n, baris in zip(nominal, BARIS_TITIK):
        tumpukan = [num(P[f'{c}{baris + d}']) for d in range(3) for c in 'CDE']
        tumpukan = [bulat(x) for x in tumpukan if x is not None and x != 0]
        total = num(P[f'H{baris}'])
        if total is not None and abs(total - n) > TOLERANSI_NOMINAL_MM:
            raise SystemExit(
                f'BEDA nominal varian {huruf} titik {n}: kertas {n} vs master {total}')
        titik.append({'nominal_cetak_mm': n, 'tumpukan_mm': tumpukan})

    balok_pra = [num(P[f'{c}22']) for c in 'HIJKLM']

    cmc.append({
        'kode': huruf,
        'label': DB[f'S{r}'].value,
        # Nomor formulir lembar kerjanya, per varian. Kertasnya turun 4 Sep 2026.
        'kode_dokumen': f'SIDIK-FM-CAL-0522.{huruf}_Rev.1',
        'judul_rentang': label,
        'kapasitas_min_mm': bawah,
        'kapasitas_maks_mm': atas,
        'u95_um': bulat(num(DB[f'T{r}'])),
        'balok_pra_evaluasi_mm': [bulat(x) for x in balok_pra if x is not None],
        'titik': titik,
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

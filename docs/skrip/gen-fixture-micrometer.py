"""Generate tests/Fixtures/micrometer-master.json dari empat workbook master.

Menyalin MASUKAN dan HASIL master apa adanya, supaya `MicrometerMasterTest`
mengadu mesin hitung PHP ke angka lab — bukan ke angka yang kita ketik sendiri.

Dijalankan sekali; keluarannya ikut commit. JANGAN diketik tangan.

Pakai:
    python3 gen-fixture-micrometer.py <dir-berisi-mikro-*.xlsm>
"""
import openpyxl, json, os, sys

def num(c):
    return float(c.value) if isinstance(c.value, (int, float)) else None

AKAR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SUMBER = sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(os.path.abspath(__file__))

VARIAN = {'025': '0-25 mm', '2550': '25-50 mm', '5075': '50-75 mm', '75100': '75-100 mm'}

# Sebelas titik, tiga baris per titik: satu baris per keping balok ukur yang
# di-wringing. Geometri lembarnya, bukan selera — sertifikat master membaca
# baris 31, 34, 37, ... 61 dan mengabaikan dua baris di antaranya.
BARIS_TITIK = list(range(31, 62, 3))

hasil = {
    '_sumber': 'Master_Olah_Data_Micrometer_{025,2550,5075,75100}mm.xlsm — masukan & hasil '
               'master, disalin apa adanya. Dipakai MicrometerMasterTest.',
    '_digenerate_oleh': 'docs/skrip/gen-fixture-micrometer.py',
    'workbook': {},
}

for kode, label in VARIAN.items():
    wb = openpyxl.load_workbook(f'{SUMBER}/mikro-{kode}.xlsm', data_only=True)
    P, U, DB = wb['PERHITUNGAN'], wb['PERHITUNGAN U95%'], wb['DATABASE']

    pra = [num(P[f'{c}25']) for c in 'CDEFGHIJKLM']
    balok = [num(P[f'{c}22']) for c in 'HIJKLM']

    titik = []
    for i, r in enumerate(BARIS_TITIK, start=1):
        nominal = [num(P[f'{c}{r + d}']) for d in range(3) for c in 'CDE']
        nominal = [x for x in nominal if x is not None]
        pembacaan = [num(P[f'{c}{r + d}']) for d in range(3) for c in 'IJKLM']
        pembacaan = [x for x in pembacaan if x is not None]
        if not nominal and not pembacaan:
            continue
        titik.append({
            'titik_ke': i,
            'baris_master': r,
            'nominal_mm': nominal,
            'pembacaan_mm': pembacaan,
            'total_nominal_master_mm': num(P[f'H{r}']),
            'rata_rata_master_mm': num(P[f'N{r}']),
            'koreksi_master_mm': num(P[f'AD{r}']),
        })

    hasil['workbook'][kode] = {
        'label': label,
        'masukan': {
            'kapasitas_mm': num(P['G8']),
            'resolusi_mm': num(P['G9']),
            'suhu_balok_c': num(P['O31']),
            'suhu_uut_c': num(P['P31']),
            'pra_evaluasi_mm': [x for x in pra if x is not None],
            'balok_pra_evaluasi_mm': [x for x in balok if x is not None],
            # `DATABASE!X11` master berisi `=NOW()`, jadi ini bukan tanggal
            # kalibrasi — ini kapan berkasnya terakhir dihitung ulang. Disimpan
            # supaya test bisa memberi umur drift yang SAMA dan membuktikan
            # rumusnya reproduksi, bukan supaya ditiru di produksi.
            'saat_master_dihitung': DB['X11'].value.isoformat(sep=' '),
            'tanggal_kalibrasi_standar': DB['W13'].value.date().isoformat(),
        },
        'titik': titik,
        'master': {
            'k_repeatability': num(U['K5']), 'k_resolusi': num(U['K6']),
            'k_balok': num(U['K7']), 'k_suhu': num(U['K8']),
            'k_muai': num(U['K9']), 'k_drift': num(U['K10']),
            'k_wringing': num(U['K11']), 'k_geometri': num(U['K12']),
            'k_selisih_suhu': num(U['K13']),
            'ci_suhu': num(U['V8']), 'ci_muai': num(U['V9']),
            'panjang_ci_master_mm': num(P['F61']),
            'uc': num(U['X15']), 'veff': num(U['AC15']),
            'k': num(U['X16']), 'u95_um': num(U['X17']),
            # Teks `"cek range"` di 0-25 mm sengaja ikut tersimpan sebagai null:
            # itu justru temuannya — lookup CMC yang gagal lalu diabaikan MAX().
            'cmc_um': num(U['X18']),
        },
    }

tujuan = f'{AKAR}/tests/Fixtures/micrometer-master.json'
os.makedirs(os.path.dirname(tujuan), exist_ok=True)
with open(tujuan, 'w', encoding='utf-8') as fh:
    json.dump(hasil, fh, indent=2, ensure_ascii=False)
    fh.write('\n')
print(f'ditulis: {tujuan}  ({len(hasil["workbook"])} workbook)')

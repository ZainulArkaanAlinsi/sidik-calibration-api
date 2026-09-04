"""Generate database/data/sesi-master-micrometer.json dari empat workbook master.

Menyalin SESI CONTOH lab apa adanya — identitas alat, pelanggan, kondisi
lingkungan, tumpukan balok ukur, dan pembacaan tiap titik — supaya
`MicrometerSeeder` menanam sesi yang angkanya bisa diadu balik ke workbook.

Dijalankan sekali; keluarannya ikut commit. JANGAN diketik tangan.

Pakai:
    python3 gen-sesi-micrometer.py <dir-berisi-mikro-*.xlsm>
"""
import openpyxl, json, os, sys

def num(c):
    return float(c.value) if isinstance(c.value, (int, float)) else None

def teks(c):
    return None if c.value is None else str(c.value).strip()

def tgl(c):
    return None if c.value is None else c.value.date().isoformat()

AKAR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
SUMBER = sys.argv[1] if len(sys.argv) > 1 else os.path.dirname(os.path.abspath(__file__))

VARIAN = {'025': '0-25 mm', '2550': '25-50 mm', '5075': '50-75 mm', '75100': '75-100 mm'}
BARIS_TITIK = list(range(31, 62, 3))

hasil = {
    '_catatan': 'Sesi contoh dari empat workbook master Micrometer. Yang DITANAM '
                'MicrometerSeeder cuma `2550` — lihat `_dipakai_seeder`. Tiga sisanya '
                'disimpan sebagai pembanding; `025` khususnya CACAT (satuan tersetel '
                '`inch` sementara angkanya milimeter) dan sengaja TIDAK ditanam.',
    '_digenerate_oleh': 'docs/skrip/gen-sesi-micrometer.py',
    '_dipakai_seeder': '2550',
    'sesi': {},
}

for kode, label in VARIAN.items():
    wb = openpyxl.load_workbook(f'{SUMBER}/mikro-{kode}.xlsm', data_only=True)
    P, I = wb['PERHITUNGAN'], wb['INPUT DATA']

    titik = []
    for i, r in enumerate(BARIS_TITIK, start=1):
        nominal = [num(P[f'{c}{r + d}']) for d in range(3) for c in 'CDE']
        nominal = [x for x in nominal if x is not None]
        pembacaan = [num(P[f'{c}{r + d}']) for d in range(3) for c in 'IJKLM']
        pembacaan = [x for x in pembacaan if x is not None]
        if not nominal and not pembacaan:
            continue
        titik.append({'titik_ke': i, 'nominal_mm': nominal, 'pembacaan_mm': pembacaan})

    pra = [num(P[f'{c}25']) for c in 'CDEFGHIJKLM']
    balok = [num(P[f'{c}22']) for c in 'HIJKLM']

    hasil['sesi'][kode] = {
        'label': label,
        '_sesi': {
            'nama_alat': teks(P['E3']),
            'merk': teks(P['E4']),
            'model': teks(P['E5']),
            'serial': teks(P['E6']),
            'rentang': teks(P['E7']),
            # Satuan ALAT — bukan satuan simpan. `MicrometerMentah` menyimpan
            # semuanya dalam mm; ini yang tercetak di sertifikat.
            'satuan_alat': teks(P['F7']),
            'kapasitas_mm': num(P['G8']),
            'resolusi_mm': num(P['G9']),
            'pelanggan': teks(P['R3']),
            'alamat': teks(P['R5']),
            'tanggal_terima': tgl(P['R8']),
            'tanggal': tgl(P['R9']),
            'nomor_sertifikat': teks(I['R5']),
            'nomor_order': teks(I['R6']),
            'suhu_awal': num(P['E14']),
            'suhu_akhir': num(P['F14']),
            'rh_awal': num(P['E15']),
            'rh_akhir': num(P['F15']),
            'thermohygro': teks(P['E16']),
            'suhu_balok_c': num(P['O31']),
            'suhu_uut_c': num(P['P31']),
            'kerataan_muka': teks(I['W25']),
            'kesejajaran_muka': teks(I['W30']),
        },
        'pra_evaluasi_mm': [x for x in pra if x is not None],
        'balok_pra_evaluasi_mm': [x for x in balok if x is not None],
        'titik': titik,
    }

tujuan = f'{AKAR}/database/data/sesi-master-micrometer.json'
with open(tujuan, 'w', encoding='utf-8') as fh:
    json.dump(hasil, fh, indent=2, ensure_ascii=False)
    fh.write('\n')
print(f'ditulis: {tujuan}  ({len(hasil["sesi"])} sesi)')

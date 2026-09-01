"""Generate tests/Fixtures/waktu-frekuensi-master.json dari ketiga workbook.

Isinya: pembacaan mentah tiap titik + nilai HARAPAN tiap kolom turunan dan tiap
komponen budget, disalin dari sel workbook (data_only) — bukan dari keluaran PHP.
"""
import openpyxl, json, os

def num(c): return float(c.value) if isinstance(c.value,(int,float)) else None
os.chdir(os.path.dirname(os.path.abspath(__file__)))
# Berkas hasil ditulis ke tujuan yang DIPAKAI, bukan di sebelah skrip ini.
# `os.chdir` di atas ada supaya workbook master kebaca dari folder ini; kalau
# jalur tulisnya ikut relatif ke situ, skrip ini "berhasil" sambil meninggalkan
# berkas yang di-commit tidak berubah — dan yang menjalankannya tidak tahu.
AKAR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


KOLOM = ('G','I','K')

def rpm(path, nama, baris_ukur, blok):
    wb = openpyxl.load_workbook(path, data_only=True)
    P, WU = wb['PERHITUNGAN'], wb['PERHITUNGAN U95%']
    titik, budget = [], []
    ke = 0
    for r in baris_ukur:
        for c in KOLOM:
            ke += 1
            sp = num(P[f'{c}{r+1}'])
            baca = [num(P[f'{c}{r+3+i}']) for i in range(5)]
            if sp is None or any(b is None for b in baca):
                continue
            titik.append({
                'titik_ke': ke, 'baris': r, 'kolom': c,
                'set_point': sp, 'pembacaan': baca,
                'harap': {
                    'rata_rata': num(P[f'{c}{r+8}']),
                    'nominal_standar': num(P[f'{c}{r+9}']),
                    'koreksi_standar': num(P[f'{c}{r+10}']),
                    'nilai_terkoreksi': num(P[f'{c}{r+11}']),
                    'koreksi': num(P[f'{c}{r+12}']),
                    'standar_deviasi': num(P[f'{c}{r+13}']),
                },
            })
    for b in blok:
        r0 = b['baris']
        budget.append({
            'baris': r0, 'baris_ukur': b['baris_ukur'], 'rusak': b.get('rusak', False),
            'catatan': b.get('catatan'),
            'harap': {
                'u_sertifikat': num(WU[f'N{r0}']),
                'ui_sertifikat': num(WU[f'U{r0}']),
                'ui_resolusi_standar': num(WU[f'U{r0+1}']),
                'ui_resolusi_uut': num(WU[f'U{r0+2}']),
                'ui_drift': num(WU[f'U{r0+3}']),
                'ui_pengulangan': num(WU[f'U{r0+4}']),
                'uc': num(WU[f'AC{r0+6}']),
                'veff': num(WU[f'AC{r0+7}']),
                'k': num(WU[f'AC{r0+8}']),
                'U': num(WU[f'AC{r0+9}']),
                'cmc': num(WU[f'AC{r0+10}']),
                'u95_sertifikat': num(WU[f'AC{r0+11}']),
            },
            'resolusi_standar': (num(WU[f'N{r0+1}']) or 0) * 2,
            'resolusi_uut': (num(WU[f'N{r0+2}']) or 0) * 2,
        })
    return {'sumber': path, 'nama': nama, 'titik': titik, 'blok': budget}

def waktu():
    wb = openpyxl.load_workbook('timer.xlsm', data_only=True)
    P, WU, S = wb['PERHITUNGAN'], wb['PERHITUNGAN U95%'], wb['SERTIFIKAT KALIBRATOR']
    KOLS = ('C','D','F','G')       # jam, menit, detik, ms  (standar)
    KOLU = ('H','I','J','K')       # jam, menit, detik, ms  (UUT)
    titik = []
    for ke, r in enumerate([20,34,48,62,76,90,104,118,132,146], start=1):
        sp = num(P[f'G{r+1}'])
        std, uut = [], []
        for i in range(3):
            s = [num(P[f'{c}{r+4+i}']) for c in KOLS]
            u = [num(P[f'{c}{r+4+i}']) for c in KOLU]
            if any(x is None for x in s+u): break
            std.append(s[0]*3600000 + s[1]*60000 + s[2]*1000 + s[3])
            uut.append(u[0]*3600000 + u[1]*60000 + u[2]*1000 + u[3])
        if len(std) < 3: continue
        kosong = all(x == 0 for x in std) and all(x == 0 for x in uut)
        # Bagian jam/menit/detik penunjukan STANDAR (tanpa milidetik), rata-rata
        # ulangan. Master menyimpan STD CORRECTED sebagai kolom milidetik SAJA,
        # jadi tanpa offset ini nilai total kita nggak punya pembanding.
        off = []
        for i in range(3):
            c = [num(P[f'{x}{r+4+i}']) for x in KOLS]
            off.append(c[0]*3600000 + c[1]*60000 + c[2]*1000)
        offset = sum(off)/len(off)
        titik.append({
            'titik_ke': ke, 'baris': r,
            'set_point_menit': sp,
            'set_point_detik': (sp or 0) * 60,
            'standar_ms': std, 'uut_ms': uut,
            'titik_hantu': kosong,
            'offset_jms_standar_ms': offset,
            'harap': {
                'nominal_standar_detik': num(P[f'G{r+8}']),
                'koreksi_standar_ms': num(P[f'G{r+9}']),
                'std_terkoreksi_ms': num(P[f'G{r+10}']),
                'stdev_maks_ms': num(P[f'L{r+11}']),
                'koreksi_ms': num(P[f'G{r+12}']),
            },
        })
    budget = {
        'catatan': 'Cuma Set Point 1 yang menghitung di master; SP2-5 semuanya #REF!.',
        'resolusi_uut_detik': num(P['E9']),
        'harap': {
            'u_sertifikat': num(S['F9']),
            'ui_sertifikat': num(WU['U8']),
            'ui_resolusi': num(WU['U9']),
            'ui_drift': num(WU['U10']),
            'ui_pengulangan': num(WU['U11']),
            'ui_hrtb': num(WU['U12']),
            'ui_hrtsd': num(WU['U13']),
            'uc': num(WU['AC15']), 'veff': num(WU['AC16']),
            'k': num(WU['AC17']), 'U': num(WU['AC18']),
            'cmc': num(WU['AC19']), 'u95_sertifikat': num(WU['AC20']),
        },
    }
    return {'sumber': 'Master Olda Timer dan Stopwatch.xlsm', 'titik': titik, 'budget_set_point_1': budget}

fixture = {
    '_catatan': 'Digenerate dari ketiga workbook master ber-password (docs/skrip/'
                'gen-fixture-waktu-frekuensi.py). Angka HARAPAN disalin dari sel '
                'workbook data_only, bukan dari keluaran PHP.',
    'tachometer': rpm('tachometer.xlsm', 'Infrared Tachometer', [21,36,51,66,81,96], [
        {'baris':9,'baris_ukur':21},{'baris':40,'baris_ukur':36},{'baris':60,'baris_ukur':51},
        {'baris':79,'baris_ukur':66},
        {'baris':98,'baris_ukur':81,'rusak':True,'catatan':
         'Rusak di tiga tempat: pita sertifikat menunjuk F15 (1,6) padahal titik '
         'tertingginya 15000 rpm yang bernaung di F18 (3,1); u_drift menunjuk '
         "'[1]Drift Std Kalibrator'!K54 — sel KOSONG di workbook Centrifuge, ter-cache 0; "
         'dan pengulangannya menunjuk PERHITUNGAN!G113:L113 yang kosong, juga 0.'},
        {'baris':117,'baris_ukur':96},
    ]),
    'centrifuge': rpm('centrifuge.xlsm', 'Centrifuge', [21,36,51,66,81], [
        {'baris':9,'baris_ukur':21,'rusak':True,'catatan':
         'Komponen pengulangan menunjuk PERHITUNGAN!G34 — SATU sel — sementara '
         'sepuluh blok lain di kedua workbook memakai MAX(...) sebaris penuh. '
         'Workbook Tachometer memakai MAX(G34:L34) di blok yang sama persis.'},
        {'baris':40,'baris_ukur':36},{'baris':60,'baris_ukur':51},
        {'baris':79,'baris_ukur':66},{'baris':98,'baris_ukur':81},
    ]),
    'timer': waktu(),
}

p = os.path.join(AKAR, 'tests', 'Fixtures', 'waktu-frekuensi-master.json')
with open(p,'w',encoding='utf-8') as f:
    json.dump(fixture,f,ensure_ascii=False,indent=2); f.write('\n')

print(f"{p} ditulis")
for k in ('tachometer','centrifuge'):
    d=fixture[k]; print(f"  {k}: {len(d['titik'])} titik, {len(d['blok'])} blok")
t=fixture['timer']
print(f"  timer: {len(t['titik'])} titik ({sum(x['titik_hantu'] for x in t['titik'])} titik hantu), 1 blok budget")

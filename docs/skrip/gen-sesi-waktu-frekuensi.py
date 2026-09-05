"""Generate database/data/sesi-master-waktu-frekuensi.json — sesi contoh ketiga master."""
import openpyxl, json, os, datetime
def val(c):
    v = c.value
    return v.date().isoformat() if isinstance(v, datetime.datetime) else v
def num(c): return float(c.value) if isinstance(c.value,(int,float)) else None
os.chdir(os.path.dirname(os.path.abspath(__file__)))
# Berkas hasil ditulis ke tujuan yang DIPAKAI, bukan di sebelah skrip ini.
# `os.chdir` di atas ada supaya workbook master kebaca dari folder ini; kalau
# jalur tulisnya ikut relatif ke situ, skrip ini "berhasil" sambil meninggalkan
# berkas yang di-commit tidak berubah — dan yang menjalankannya tidak tahu.
AKAR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))


def meta(I, peta):
    return {k: val(I[sel]) for k, sel in peta.items()}

PETA_RPM = dict(nama_alat='E10', merk='E11', model='E12', serial='E13',
                rentang='E14', kapasitas='E15', resolusi='E16', satuan='I14',
                pelanggan='O10', alamat='O12', tanggal_terima='O15', tanggal='O16',
                lokasi_nama='O20', nomor_sertifikat='Q5', nomor_order='Q6',
                suhu_awal='E21', suhu_akhir='F21', rh_awal='E22', rh_akhir='F22')

def rpm(path, baris_ukur):
    wb = openpyxl.load_workbook(path, data_only=True)
    I, P = wb['INPUT DATA'], wb['PERHITUNGAN']
    m = meta(I, PETA_RPM)
    titik, ke = [], 0
    for r in baris_ukur:
        for c in ('G','I','K'):
            ke += 1
            sp = num(P[f'{c}{r+1}'])
            baca = [num(P[f'{c}{r+3+i}']) for i in range(5)]
            if sp is None or any(b is None for b in baca): continue
            titik.append({'titik_ke': ke, 'set_point': sp, 'pembacaan': baca})
    return {'_sesi': m, 'titik': titik}

def timer():
    wb = openpyxl.load_workbook('timer.xlsm', data_only=True)
    I, P = wb['INPUT DATA'], wb['PERHITUNGAN']
    m = meta(I, PETA_RPM)
    titik, ke = [], 0
    for r in [20,34,48,62,76,90,104,118,132,146]:
        ke += 1
        sp = num(P[f'G{r+1}'])
        std, uut = [], []
        for i in range(3):
            s = [num(P[f'{c}{r+4+i}']) for c in ('C','D','F','G')]
            u = [num(P[f'{c}{r+4+i}']) for c in ('H','I','J','K')]
            if any(x is None for x in s+u): break
            std.append(s[0]*3600000+s[1]*60000+s[2]*1000+s[3])
            uut.append(u[0]*3600000+u[1]*60000+u[2]*1000+u[3])
        if len(std) < 3: continue
        # titik hantu (semua nol) TIDAK ikut di-seed — dia memang bukan titik
        if all(x==0 for x in std) and all(x==0 for x in uut): continue
        titik.append({'titik_ke': len(titik)+1, 'set_point_menit': sp,
                      'set_point_detik': sp*60, 'standar_ms': std, 'uut_ms': uut})
    return {'_sesi': m, 'titik': titik}

out = {
    '_catatan': 'Sesi contoh ketiga workbook master kelompok Waktu dan Frekuensi. '
                'Digenerate docs/skrip/gen-sesi-waktu-frekuensi.py — jangan diketik tangan. '
                'Titik hantu master (set point kosong yang tetap melahirkan koreksi) TIDAK ikut.',
    'tachometer': rpm('tachometer.xlsm', [21,36,51,66,81,96]),
    'centrifuge': rpm('centrifuge.xlsm', [21,36,51,66,81]),
    'timer': timer(),
}
with open(os.path.join(AKAR, 'database', 'data', 'sesi-master-waktu-frekuensi.json'),
          'w', encoding='utf-8') as f:
    json.dump(out,f,ensure_ascii=False,indent=2); f.write('\n')
for k in ('tachometer','centrifuge','timer'):
    d=out[k]; print(f"  {k}: {len(d['titik'])} titik | alat={d['_sesi']['nama_alat']!r} "
                    f"sn={d['_sesi']['serial']!r} pelanggan={d['_sesi']['pelanggan']!r} "
                    f"res={d['_sesi']['resolusi']} satuan={d['_sesi']['satuan']!r}")

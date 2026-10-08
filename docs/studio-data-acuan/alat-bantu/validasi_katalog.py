"""Validasi katalog Studio Data Acuan terhadap repo yang sekarang.

    python3 -I validasi_katalog.py <akar-repo-api> [<folder-katalog>]

Memeriksa dua hal, dan keluar dengan kode 1 kalau salah satunya gagal:
1. CAKUPAN — setiap sel data di tiap JSON acuan diwakili tepat oleh lembar di skema
   (angka di skema.json `cakupan`, dihitung ketat per jalur daun).
2. DRIFT — sha256 JSON acuan di repo masih sama dengan saat katalog dibangun. Kalau
   berbeda, katalog basi: bangun ulang dengan bangun_katalog.py + tulis_kartu_prompt.py.
"""
import hashlib, json, os, sys

akar = sys.argv[1]
kat = sys.argv[2] if len(sys.argv) > 2 else os.path.join(akar, 'docs/studio-data-acuan/katalog')
gagal = 0
for kode in sorted(os.listdir(os.path.join(kat, 'paket'))):
    d = json.load(open(os.path.join(kat, 'paket', kode, 'skema.json'), encoding='utf-8'))
    for jf, c in d.get('cakupan', {}).items():
        if c['tercakup'] != c['daun']:
            gagal += 1
            print(f'CAKUPAN  {kode}: {jf} {c["tercakup"]}/{c["daun"]} — contoh hilang {c.get("contoh_hilang")}')
    for jf, sha in d.get('sha256_json', {}).items():
        p = os.path.join(akar, 'database/data', jf)
        if not os.path.exists(p):
            gagal += 1; print(f'HILANG   {kode}: database/data/{jf} tidak ada'); continue
        kini = hashlib.sha256(open(p, 'rb').read()).hexdigest()
        if kini != sha:
            gagal += 1; print(f'DRIFT    {kode}: {jf} berubah sejak katalog dibangun')
    n = sum(c['daun'] for c in d.get('cakupan', {}).values())
    print(f'ok       {kode:22} {d["jenis"]:15} sel data {n or "-"}')
print('\nHASIL:', 'LULUS' if not gagal else f'{gagal} masalah')
sys.exit(1 if gagal else 0)

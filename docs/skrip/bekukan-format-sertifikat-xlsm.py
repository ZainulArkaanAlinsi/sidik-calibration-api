#!/usr/bin/env python3
"""Bekukan number_format PER SEL lembar SERTIFIKAT master .xlsm ke fixture test.

    python -I docs/skrip/bekukan-format-sertifikat-xlsm.py <akar-repo> <folder-master>... [--cek]

Untuk tiap peta sel yang punya `format_sertifikat`, setiap sel di setiap rentang
`entri[].sel` dibaca dari .xlsm asli (varian dari nama berkas lewat `varian.pola`
peta) dan ditulis ke SATU berkas `tests/Fixtures/peta-sel-xlsm/format-sertifikat.json` (bukan
subdirektori: `StudioPetaSelXlsmTest` membaca `peta-sel-xlsm/*/*.json` sebagai paket):

    {"paket": {"<paket>": {"varian": {"<varian|->": {"berkas": ..., "sha256": ..., "sel": {"SERTIFIKAT!B25": "0.0", "SERTIFIKAT!B26": null}}}}}}

`null` = sel bagian merge (bukan sel kiri-atas) — tidak pernah tercetak sendiri.
Yang dibekukan HANYA alamat sel dan kode number_format: tidak ada nilai sel,
tidak ada data pelanggan. Fixture ini yang dipakai
`StudioFormatSertifikatTest::test_entri_peta_sama_dengan_format_tiap_sel_master`
supaya rentang yang digabung tidak bisa menyembunyikan baris yang formatnya beda
(temuan F-1/F-3 paritas format, 22 Okt 2026).

`format` entri peta TIDAK dibaca di sini — hanya rentangnya. Berkas dicari di
folder yang diberikan menurut nama di manifest (`peta-sel/manifest-xlsm.json`);
bila ada beberapa salinan, yang sha256-nya sama dengan manifest dipilih.

Master dibaca di tempat dan didekripsi ke MEMORI saja (sandi dari HKCU
`SIDIK_MASTER_SANDI`, tidak pernah dicetak). `--cek` = jangan tulis, hanya
laporkan beda fixture lama vs bacaan sekarang (exit 1 bila beda).
"""
import hashlib, io, json, os, re, sys, winreg

import msoffcrypto, openpyxl
from openpyxl.cell.cell import MergedCell

argv = [a for a in sys.argv[1:] if a != '--cek']
CEK = '--cek' in sys.argv
REPO, FOLDER = argv[0], argv[1:]
PETA = os.path.join(REPO, 'database', 'data', 'peta-sel')
KELUAR = os.path.join(REPO, 'tests', 'Fixtures', 'peta-sel-xlsm', 'format-sertifikat.json')


def sandi():
    with winreg.OpenKey(winreg.HKEY_CURRENT_USER, 'Environment') as k:
        return winreg.QueryValueEx(k, 'SIDIK_MASTER_SANDI')[0]


def buka(data):
    buf = io.BytesIO(data)
    if data[:4] == b'\xd0\xcf\x11\xe0':
        out = io.BytesIO()
        f = msoffcrypto.OfficeFile(buf)
        f.load_key(password=sandi())
        f.decrypt(out)
        buf = out
    buf.seek(0)
    return openpyxl.load_workbook(buf, data_only=False)


def kol(h):
    n = 0
    for c in h:
        n = n * 26 + ord(c) - 64
    return n


def huruf(n):
    s = ''
    while n:
        n, r = divmod(n - 1, 26)
        s = chr(65 + r) + s
    return s


def urai(a):
    i = a.rindex('!')
    lb, s = a[:i], a[i + 1:]
    m = re.match(r"^([A-Z]+)(\d+)(?::([A-Z]+)(\d+))?$", s)
    k1, b1 = kol(m[1]), int(m[2])
    k2, b2 = (kol(m[3]), int(m[4])) if m[3] else (k1, b1)
    return lb, k1, b1, k2, b2


def normal_nama(n):
    n = re.sub(r"\.(xlsm|xlsx)$", "", n, flags=re.I)
    return re.sub(r"[^a-z0-9.]+", " ", n.lower()).strip()


def manifest():
    m = json.load(open(os.path.join(PETA, 'manifest-xlsm.json'), encoding='utf-8'))
    return m['berkas']


def cari(nama, sha_manifest):
    calon = [os.path.join(d, nama) for d in FOLDER if os.path.isfile(os.path.join(d, nama))]
    pilih = None
    for p in calon:
        data = open(p, 'rb').read()
        sha = hashlib.sha256(data).hexdigest()
        if pilih is None or sha == sha_manifest:
            pilih = (p, data, sha)
        if sha == sha_manifest:
            break
    return pilih


laporan = []
beda_total = 0
HASIL = {}
LAMA = json.load(open(KELUAR, encoding='utf-8')) if os.path.isfile(KELUAR) else {}
for b in manifest():
    paket = b['paket']
    jalur = os.path.join(PETA, paket + '.json')
    if not os.path.isfile(jalur):
        continue
    peta = json.load(open(jalur, encoding='utf-8'))
    entri = (peta.get('format_sertifikat') or {}).get('entri')
    if not entri:
        continue
    var = None
    if 'varian' in peta:
        cocok = [k for k, rx in peta['varian']['pola'].items() if re.search(rx, normal_nama(b['berkas']))]
        if len(cocok) != 1:
            laporan.append({'paket': paket, 'berkas': b['berkas'], 'lewati': 'varian tidak dikenali'})
            continue
        var = cocok[0]
    ketemu = cari(b['berkas'], b['sha256'])
    if ketemu is None:
        laporan.append({'paket': paket, 'berkas': b['berkas'], 'lewati': 'berkas tidak ditemukan'})
        continue
    _, data, sha = ketemu
    wb = buka(data)
    lembar = (peta.get('xlsm') or {}).get('lembar', {})
    sel = {}
    for f in entri:
        if 'varian' in f and var not in f['varian']:
            continue
        for rentang in f['sel']:
            lb, k1, b1, k2, b2 = urai(rentang)
            ws = wb[lembar.get(lb, lb)]
            for r in range(b1, b2 + 1):
                for c in range(k1, k2 + 1):
                    x = ws.cell(row=r, column=c)
                    sel[f"{lb}!{huruf(c)}{r}"] = None if isinstance(x, MergedCell) else x.number_format
    kunci = var or '-'
    baru = {'berkas': b['berkas'], 'sha256': sha, 'sha256_sama_manifest': sha == b['sha256'],
            'sel': dict(sorted(sel.items(), key=lambda kv: (kv[0].split('!')[0], int(re.sub(r'\D', '', kv[0].split('!')[1])), kol(re.sub(r'\d', '', kv[0].split('!')[1])))))}
    if CEK:
        if LAMA.get('paket', {}).get(paket, {}).get('varian', {}).get(kunci, {}).get('sel') != baru['sel']:
            beda_total += 1
            laporan.append({'paket': paket, 'varian': kunci, 'beda': True})
        continue
    HASIL.setdefault(paket, {'varian': {}})['varian'][kunci] = baru
    laporan.append({'paket': paket, 'varian': kunci, 'sel': len(sel), 'sha256_sama_manifest': sha == b['sha256']})

if not CEK:
    keluar = {
        'catatan': ('Dibekukan oleh docs/skrip/bekukan-format-sertifikat-xlsm.py dari .xlsm asli: alamat sel + '
                    'kode number_format saja (null = sel bagian merge). Tanpa nilai sel, tanpa data pelanggan.'),
        'paket': {p: {'varian': dict(sorted(v['varian'].items()))} for p, v in sorted(HASIL.items())},
    }
    with open(KELUAR, 'w', encoding='utf-8', newline='\n') as h:
        json.dump(keluar, h, ensure_ascii=False, indent=1)
        h.write('\n')

print(json.dumps(laporan, ensure_ascii=False, indent=1))
sys.exit(1 if CEK and beda_total else 0)

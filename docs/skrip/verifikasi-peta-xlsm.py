#!/usr/bin/env python3
"""Verifikasi peta sel (database/data/peta-sel/<paket>.json) terhadap master .xlsm ASLI.

    python -I docs/skrip/verifikasi-peta-xlsm.py <akar-repo> <paket> <berkas.xlsm>...

Butuh `msoffcrypto-tool` + `openpyxl`. Master dibaca DI TEMPAT dan didekripsi ke
MEMORI saja (BytesIO); tidak ada salinan, terdekripsi atau tidak, yang ditulis
ke disk. Sandi dibaca dari variabel lingkungan USER Windows `SIDIK_MASTER_SANDI`
(HKCU Environment) — tidak pernah dicetak, dicatat, atau ditulis. Berkas master
TIDAK boleh disalin ke repo (data pelanggan).

Untuk tiap berkas: varian dari nama, lembar wajib (nama asli lewat bagian `xlsm`
peta), jangkar, ekstraksi semua butir `ekstrak` (alamat CSV peta diterjemahkan
lewat `xlsm.geser`) dibanding JSON repo, dan `cek`. Keluaran JSON ke stdout:
`jangkar_gagal` (+ di mana teksnya sekarang), `beda` (jalur yang nilainya lain),
`masalah`, sha256 berkas. Nilai dibaca dari cache Excel (`data_only`).
"""
import datetime, hashlib, io, json, re, sys, winreg

import msoffcrypto, openpyxl

REPO, PETA = sys.argv[1], sys.argv[2]
BERKAS = sys.argv[3:]


def sandi():
    with winreg.OpenKey(winreg.HKEY_CURRENT_USER, 'Environment') as k:
        return winreg.QueryValueEx(k, 'SIDIK_MASTER_SANDI')[0]


def buka(path):
    data = open(path, 'rb').read()
    sha = hashlib.sha256(data).hexdigest()
    buf = io.BytesIO(data)
    if data[:4] == b'\xd0\xcf\x11\xe0':
        out = io.BytesIO()
        f = msoffcrypto.OfficeFile(buf)
        f.load_key(password=sandi())
        f.decrypt(out)
        buf = out
    buf.seek(0)
    return openpyxl.load_workbook(buf, data_only=True), sha


def kol(h):
    n = 0
    for c in h:
        n = n * 26 + ord(c) - 64
    return n


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


def normal_teks(s):
    return re.sub(r"\s+", " ", str(s)).strip()


ALIH = {'lembar': {}, 'geser': {}}


def atur_alih(peta, var):
    x = peta.get('xlsm') or {}
    ALIH['lembar'] = dict(x.get('lembar', {}))
    g = {}
    for a in x.get('geser', []):
        if 'varian' in a and var not in a['varian']:
            continue
        g.setdefault(a['lembar'], []).append((a['dari'], a['geser']))
    ALIH['geser'] = {k: sorted(v) for k, v in g.items()}


def asli(lb):
    return ALIH['lembar'].get(lb, lb)


def nilai(wb, lb, k, b):
    g = 0
    for dari, gs in ALIH['geser'].get(lb, []):
        if b >= dari:
            g = gs
    if asli(lb) not in wb.sheetnames:
        raise KeyError(asli(lb))
    return wb[asli(lb)].cell(row=b + g, column=k).value


def angka(v):
    if isinstance(v, bool):
        return None
    if isinstance(v, (int, float)):
        return float(v)
    return None


def kosong(v):
    return v is None or (isinstance(v, str) and v.strip() == '')


def teks(v):
    if isinstance(v, (int, float)) and not isinstance(v, bool) and float(v).is_integer():
        return str(int(v))
    if isinstance(v, datetime.datetime):
        return v.isoformat()
    return None if v is None else str(v).strip()


def tanggal(v):
    if isinstance(v, datetime.datetime):
        return v.date().isoformat()
    if isinstance(v, datetime.date):
        return v.isoformat()
    m = re.match(r"^(\d{4}-\d{2}-\d{2})", str(v or ''))
    return m[1] if m else None


def bulat(x, n):
    return x if n is None or x is None else round(x, n)


def jalur_tulis(isi, jalur, v):
    seg = jalur.split('.')
    ref = isi
    for n, s in enumerate(seg):
        m = re.match(r"^(.+?)(?:\[(?:kode=([^\]]+)|(\d+))\])?$", s)
        key, kode, idx = m[1], m[2], m[3]
        akhir = n == len(seg) - 1
        if kode is None and idx is None:
            if akhir:
                ref[key] = v
                return
            ref = ref.setdefault(key, {})
            continue
        lst = ref[key]
        i = int(idx) if idx is not None else next(j for j, e in enumerate(lst) if str(e.get('kode')) == kode)
        if akhir:
            lst[i] = v
            return
        ref = lst[i]


def jalur_ambil(isi, jalur):
    ref = isi
    for s in jalur.split('.'):
        m = re.match(r"^(.+?)(?:\[(?:kode=([^\]]+)|(\d+))\])?$", s)
        ref = ref[m[1]]
        if m[2] is not None:
            ref = next(e for e in ref if str(e.get('kode')) == m[2])
        elif m[3] is not None:
            ref = ref[int(m[3])]
    return ref


def untuk_varian(butir, var):
    return [b for b in butir if 'varian' not in b or (var is not None and var in b['varian'])]


def transformasi(x, e):
    """nol_di_bawah lalu angka_penting (generator Enclosure)."""
    if x is None:
        return None
    if e.get('nol_di_bawah') and abs(x) < e['nol_di_bawah']:
        return 0.0
    return float(f"{x:.{e['angka_penting']}g}") if e.get('angka_penting') else x


def kunci_teks(x):
    return str(int(x)) if float(x).is_integer() else str(x)


def gabungan(wb, spec, tambah, asal):
    """Sel kosong di dalam rentang merge -> nilai sel kiri-atasnya (seperti generator tekanan)."""
    lb, k, b0, _, _ = spec
    b = b0 + tambah
    for dari, gs in ALIH['geser'].get(lb, []):
        if b0 + tambah >= dari:
            b = b0 + tambah + gs
    for r in wb[asli(lb)].merged_cells.ranges:
        if r.min_col <= k <= r.max_col and r.min_row <= b <= r.max_row and (k, b) != (r.min_col, r.min_row):
            return wb[asli(lb)].cell(row=r.min_row, column=r.min_col).value
    return asal


def ekstrak_satu(wb, e, geser, masalah):
    t, bl = e['tipe'], e.get('bulat')
    if t in ('teks', 'angka', 'bilangan_bulat', 'tanggal'):
        lb, k, b, _, _ = urai(e['sel'])
        v = nilai(wb, lb, k, b + geser)
        alamat = f"{lb}!{e['sel'].split('!')[-1]}+{geser}"
        if t == 'teks':
            if isinstance(v, str) and v.startswith('#'):
                masalah.append(f"{alamat} galat {v}")
            return teks(v)
        if t == 'tanggal':
            return tanggal(v)
        x = angka(v)
        if x is None:
            masalah.append(f"{alamat} bukan angka: {v!r}")
            return None
        if t == 'bilangan_bulat':
            return int(x)
        if e.get('bagi'):
            x = x / e['bagi']
        return transformasi(bulat(x, bl), e)
    if t == 'daftar_kunci':
        out = []
        for r in e['rentang']:
            lb, k1, b1, _, b2 = urai(r)
            for b in range(b1 + geser, b2 + geser + 1):
                x = angka(nilai(wb, lb, k1, b))
                if x is not None and kunci_teks(x) not in out:
                    out.append(kunci_teks(x))
        return out
    if t == 'daftar_angka':
        lb, k1, b1, k2, b2 = urai(e['rentang'])
        out = []
        for b in range(b1 + geser, b2 + geser + 1):
            for k in range(k1, k2 + 1):
                x = angka(nilai(wb, lb, k, b))
                if x is not None and not (e.get('buang_nol') and x == 0.0):
                    if e.get('bagi'):
                        x = x / e['bagi']
                    out.append(bulat(x, bl))
        return out
    if t == 'peta_angka':
        lk, kk, b1, _, b2 = urai(e['kunci'])
        ln, kn, _, _, _ = urai(e['nilai'])
        out = {}
        for b in range(b1 + geser, b2 + geser + 1):
            a, v = angka(nilai(wb, lk, kk, b)), angka(nilai(wb, ln, kn, b))
            if not e.get('lewati_galat'):
                for x, kol in ((a, kk), (v, kn)):
                    mentah = nilai(wb, lk if kol == kk else ln, kol, b)
                    if x is None and not kosong(mentah):
                        masalah.append(f"{e['jalur']} baris {b}: {mentah!r} bukan angka")
            if a is None or v is None:
                continue
            kunci = kunci_teks(a) if e.get('kunci_teks') else json.dumps(bulat(a, bl))
            out[kunci] = transformasi(bulat(v, bl), e)
        return out
    if t == 'daftar_objek':
        kolom = {}
        for nama, sp in e['kolom'].items():
            if isinstance(sp, dict) and 'nilai_tetap' in sp:
                kolom[nama] = ('tetap', sp['nilai_tetap'])
                continue
            r = sp['rentang'] if isinstance(sp, dict) else sp
            kolom[nama] = ('sel', urai(r), (sp.get('tipe', 'angka') if isinstance(sp, dict) else 'angka'), isinstance(sp, dict) and sp.get('boleh_kosong', False),
                           isinstance(sp, dict) and sp.get('gabungan', False),
                           isinstance(sp, dict) and sp.get('bukan_angka_jadi_null', False))
        kunci = kolom[e['kunci']][1]
        n = kunci[4] - kunci[2]
        lewati = e.get('lewati_kolom', [e['kunci']])
        bukan = e.get('lewati_jika') == 'bukan_angka'
        out = []
        for i in range(n + 1):
            def v_of(spec):
                lb, k, b0, _, _ = spec[1]
                return nilai(wb, lb, k, b0 + geser + i)
            skip = False
            for l in lewati:
                v = v_of(kolom[l])
                if (angka(v) is None) if bukan else kosong(v):
                    skip = True
            if skip:
                continue
            baris = {}
            for nama, spec in kolom.items():
                if spec[0] == 'tetap':
                    baris[nama] = spec[1]
                    continue
                v = v_of(spec)
                if spec[4] and kosong(v):
                    v = gabungan(wb, spec[1], geser + i, v)
                if (spec[3] and kosong(v)) or (spec[5] and angka(v) is None):
                    baris[nama] = None
                elif spec[2] == 'teks':
                    baris[nama] = teks(v)
                elif spec[2] == 'tanggal':
                    baris[nama] = tanggal(v)
                elif spec[2] == 'bilangan_bulat':
                    x = angka(v)
                    if x is None or not x.is_integer():
                        masalah.append(f"{e['jalur']} baris {i}: {v!r} bukan bilangan bulat")
                    baris[nama] = None if x is None else int(x)
                else:
                    x = angka(v)
                    if x is None:
                        masalah.append(f"{e['jalur']} baris {i}: {v!r} bukan angka")
                    baris[nama] = bulat(x, bl)
            out.append(baris)
        if e.get('urut'):
            out.sort(key=lambda r: r.get(e['urut']) or 0)
        return out
    raise ValueError(t)


def periksa(wb, nama, peta, dasar):
    masalah, var = [], None
    if 'varian' in peta:
        cocok = [k for k, rx in peta['varian']['pola'].items() if re.search(rx, normal_nama(nama))]
        var = cocok[0] if len(cocok) == 1 else None
        if var is None:
            return {'varian': None, 'masalah': ['varian tidak dikenali'], 'jangkar_gagal': [], 'beda': []}
    atur_alih(peta, var)
    lembar = [l if isinstance(l, str) else l['nama'] for l in peta['lembar_wajib'] if isinstance(l, str) or var in l.get('varian', [])]
    hilang = [l for l in lembar if asli(l) not in wb.sheetnames]
    jg = []
    for j in untuk_varian(peta['jangkar'], var):
        lb, k, b, _, _ = urai(j['sel'])
        if lb in hilang:
            continue
        ada = normal_teks(teks(nilai(wb, lb, k, b)) or '')
        if ada != normal_teks(j['teks']):
            # cari di mana teks itu sekarang
            ws, pindah = wb[asli(lb)], []
            for row in ws.iter_rows():
                for c in row:
                    if c.value is not None and normal_teks(teks(c.value) or '') == normal_teks(j['teks']):
                        pindah.append(c.coordinate)
            jg.append({'sel': j['sel'], 'harap': j['teks'], 'ada': ada, 'ditemukan_di': pindah[:3]})
    isi = json.loads(json.dumps(dasar))
    beda = []
    if not hilang:
        for e in untuk_varian(peta['ekstrak'], var):
            ul = e.get('ulang')
            for i in range(ul or 1):
                geser = i * e.get('langkah_baris', 0) if ul else 0
                jalur = e['jalur'].replace('{varian}', var or '').replace('{i}', str(i))
                try:
                    v = ekstrak_satu(wb, e, geser, masalah)
                except KeyError as ex:
                    masalah.append(f"lembar {ex} hilang")
                    continue
                try:
                    lama = jalur_ambil(dasar, jalur)
                except Exception:
                    lama = '<tidak ada>'
                if v != lama:
                    beda.append({'jalur': jalur, 'json': lama if not isinstance(lama, (list, dict)) else '…', 'xlsm': v if not isinstance(v, (list, dict)) else '…'})
        for c in untuk_varian(peta.get('cek', []), var):
            ul = c.get('ulang')
            for i in range(ul or 1):
                geser = i * c.get('langkah_baris', 0) if ul else 0
                lb, k, b, _, _ = urai(c['sel'])
                x = angka(nilai(wb, lb, k, b + geser))
                jalur = c['banding'].replace('{varian}', var or '').replace('{i}', str(i))
                if x is None:
                    if not c.get('boleh_kosong'):
                        masalah.append(f"cek {c['sel']}+{geser} kosong")
                    continue
                try:
                    ban = float(jalur_ambil(dasar, jalur))
                except Exception:
                    masalah.append(f"cek {jalur} tidak ada")
                    continue
                if abs(x - ban) > c['toleransi']:
                    masalah.append(f"cek {c['sel']}+{geser} = {x} vs {jalur} = {ban}")
    # Format SERTIFIKAT (fase 3): tiap sel ANGKA di rentang harus ber-number_format itu.
    fmt = []
    for f in untuk_varian((peta.get('format_sertifikat') or {}).get('entri', []), var):
        n = 0
        for rentang in f['sel']:
            lb, k1, b1, k2, b2 = urai(rentang)
            if lb not in wb.sheetnames:
                fmt.append({'kolom': f['kolom'], 'sel': rentang, 'masalah': 'lembar tidak ada'})
                continue
            for b in range(b1, b2 + 1):
                for k in range(k1, k2 + 1):
                    c = wb[lb].cell(row=b, column=k)
                    if angka(c.value) is None:
                        continue
                    n += 1
                    if c.number_format != f['format']:
                        fmt.append({'kolom': f['kolom'], 'sel': c.coordinate, 'harap': f['format'], 'ada': c.number_format})
        if n == 0:
            fmt.append({'kolom': f['kolom'], 'sel': f['sel'], 'masalah': 'tidak ada sel angka'})
    return {'varian': var, 'lembar_hilang': hilang, 'jangkar_gagal': jg, 'beda': beda, 'masalah': masalah[:20], 'format_beda': fmt}


peta = json.load(open(f"{REPO}/database/data/peta-sel/{PETA}.json", encoding='utf-8'))
dasar = json.load(open(f"{REPO}/database/data/{peta['sumber_json']}", encoding='utf-8'))
lap = []
for p in BERKAS:
    try:
        wb, sha = buka(p)
    except Exception as ex:
        lap.append({'berkas': p.split('/')[-1].split('\\')[-1], 'galat': type(ex).__name__})
        continue
    nama = p.replace('\\', '/').split('/')[-1]
    r = periksa(wb, nama, peta, dasar)
    r.update({'berkas': nama, 'sha256': sha, 'lembar_xlsm': wb.sheetnames})
    lap.append(r)
print(json.dumps(lap, ensure_ascii=False, indent=1, default=str))

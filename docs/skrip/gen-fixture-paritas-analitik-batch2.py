"""Bangun tests/Fixtures/paritas/{refractometer,turbidimeter,chlorine-meter}.json dari workbook master.

Bahan uji PARITAS aplikasi-lawan-Excel batch alat ke-2 (`tests/Feature/Paritas/`):
MASUKAN yang diketik di workbook dan NILAI tiap tahap hitungan Excel, masing-masing
dengan alamat sel sumbernya (`SHEET!SEL`), disalin apa adanya dari nilai-cache
workbook. Keluarannya BUKAN keluaran PHP dan tidak boleh disunting dengan tangan.
Berdampingan dengan `gen-fixture-paritas-micrometer-ph.py` (PR #299); berkas itu
tidak diubah, helper-nya sengaja disalin supaya kedua generator berdiri sendiri.

## Aturan keras

- Sandi workbook HANYA dari variabel lingkungan `SIDIK_MASTER_SANDI`, dibaca ke
  memori. Tidak ada salinan terbuka-kunci di disk; sandi tidak dicetak, tidak
  ditulis ke fixture, dan tidak dimasukkan ke pesan galat (hanya tipe galat).
- Fixture TIDAK memuat nama/alamat pelanggan, nomor sertifikat, nomor order,
  maupun nama teknisi: skrip hanya membaca sel angka yang disebut di bawah.
- Angka acuan = nilai-cache sel. Dua pengecualian, keduanya ditandai di fixture
  (`sumber`) dan dipakai HANYA untuk membuktikan penyebab satu selisih:
  (a) "skenario aplikasi" = rumus sel dihitung ulang dengan satu masukan diganti
  nilai yang dipakai aplikasi; (b) rekonstruksi rantai dari sel dasar untuk
  memastikan pembacaan kami atas rumus benar.
- Sebelum menulis, skrip menghitung ulang rantai `PERHITUNGAN U95%` dari sel dasarnya
  (komponen -> uc -> v_eff -> k -> U -> max(U; CMC)) dan membandingkannya ke
  nilai-cache; selisih relatif terbesar dicatat di fixture
  (`rekonstruksi.selisih_relatif_maks_terhadap_cache`). Di atas 1e-11 skrip
  berhenti: berarti pembacaannya (bukan workbook) yang salah.

## Pakai

    py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-analitik-batch2.py <folder-Downloads>

`<folder-Downloads>` berisi `EXEL CALIBRATION SIDIK\\` dengan `Master Olah Data_Refractometer.xlsm`,
`Master Olah Data_Turbidimeter.xlsm`, `Master Olah Data_Chlorine Meter.xlsm`.
Dependensi: msoffcrypto-tool, openpyxl, scipy.
"""
import hashlib
import io
import json
import math
import os
import re
import sys
import warnings
from decimal import ROUND_HALF_UP, Decimal

warnings.filterwarnings('ignore')

AKAR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
KELUAR = os.path.join(AKAR, 'tests', 'Fixtures', 'paritas')

TOL_REKONSTRUKSI = 1e-11

BERKAS = {
    'refractometer': 'EXEL CALIBRATION SIDIK/Master Olah Data_Refractometer.xlsm',
    'turbidimeter': 'EXEL CALIBRATION SIDIK/Master Olah Data_Turbidimeter.xlsm',
    'chlorine_meter': 'EXEL CALIBRATION SIDIK/Master Olah Data_Chlorine Meter.xlsm',
}

I, P, U, DB, S, T = 'INPUT DATA', 'PERHITUNGAN', 'PERHITUNGAN U95%', 'DATABASE', 'SERTIFIKAT', 'Tab Konversi Temperatur'


class SandiTidakAda(Exception):
    pass


def buka(path):
    """(sha256 berkas asli, wb rumus, wb nilai-cache). Dekripsi hanya di memori."""
    import msoffcrypto
    import openpyxl

    raw = open(path, 'rb').read()
    sha = hashlib.sha256(raw).hexdigest()
    ofc = msoffcrypto.OfficeFile(io.BytesIO(raw))
    data = raw
    if ofc.is_encrypted():
        sandi = os.environ.get('SIDIK_MASTER_SANDI')
        if not sandi:
            raise SandiTidakAda('SIDIK_MASTER_SANDI')
        out = io.BytesIO()
        try:
            ofc.load_key(password=sandi)
            ofc.decrypt(out)
        finally:
            sandi = None
        data = out.getvalue()
    return (
        sha,
        openpyxl.load_workbook(io.BytesIO(data), data_only=False),
        openpyxl.load_workbook(io.BytesIO(data), data_only=True),
    )


def angka(x):
    return float(x) if isinstance(x, (int, float)) and not isinstance(x, bool) else None


class Sumber:
    """Pembaca sel yang mencatat alamatnya. Tiap nilai keluar sebagai {sel, nilai}."""

    def __init__(self, wf, wv):
        self.wf, self.wv = wf, wv

    def nilai(self, sheet, sel):
        return self.wv[sheet][sel].value

    def n(self, sheet, sel, wajib=True):
        v = angka(self.nilai(sheet, sel))
        if v is None and wajib:
            raise ValueError(f'{sheet}!{sel} bukan angka: {self.nilai(sheet, sel)!r}')
        return v

    def sel(self, sheet, sel, wajib=True):
        return {'sel': f'{sheet}!{sel}', 'nilai': self.n(sheet, sel, wajib)}

    def teks(self, sheet, sel):
        v = self.nilai(sheet, sel)
        return {'sel': f'{sheet}!{sel}', 'nilai': v if isinstance(v, str) else None}

    def deret(self, sheet, sels):
        return {'sel': [f'{sheet}!{s}' for s in sels], 'nilai': [self.n(sheet, s) for s in sels]}

    def format(self, sheet, sel):
        return self.wf[sheet][sel].number_format

    def rumus(self, sheet, sel):
        v = self.wf[sheet][sel].value
        return v if isinstance(v, str) else None


def teks_excel(nilai, fmt):
    """Teks yang ditampilkan Excel untuk `nilai` berformat `fmt` (0.000 / 0 / ...).

    Dibulatkan dulu ke 15 angka penting (presisi tampilan Excel), baru ke
    desimal format, setengah menjauhi nol. Tanda minus dipertahankan walau
    hasilnya nol (perilaku Excel). Ini EMULASI, bukan teks yang dibaca dari
    render Excel (lihat catatan P-5 di docs/paritas-micrometer-ph.md).
    """
    m = re.fullmatch(r'0(?:\.(0+))?', fmt)
    if not m:
        raise ValueError(f'format sel tak dikenal: {fmt}')
    desimal = len(m.group(1) or '')
    d = Decimal(f'{abs(nilai):.14e}')
    q = d.quantize(Decimal(1).scaleb(-desimal), rounding=ROUND_HALF_UP)
    return ('-' if nilai < 0 else '') + f'{q:.{desimal}f}'


def cetak(s, sheet, sel):
    fmt = s.format(sheet, sel)
    v = s.n(sheet, sel)
    teks = teks_excel(v, fmt)
    return {
        'sel': f'{sheet}!{sel}', 'nilai': v, 'format': fmt, 'teks': teks,
        # Nilai negatif yang membulat ke nol: Excel menampilkan `-0.00`.
        'nol_bertanda_minus': bool(v < 0 and not any(c in '123456789' for c in teks)),
    }


def tinv(p, df):
    """`TINV(p, df)` Excel: dua-sisi, derajat bebas dipotong ke bilangan bulat."""
    from scipy import stats

    return float(stats.t.ppf(1 - p / 2, math.floor(df)))


def rel(a, b):
    return abs(a - b) / max(abs(b), 1e-300) if b != 0 else abs(a - b)


def rantai(ui_ci_vi):
    """uc, v_eff, k (TINV), U dari daftar (ui, ci, vi) — rumus sel `PERHITUNGAN U95%`."""
    aa = [(ui * ci) ** 2 for ui, ci, _ in ui_ci_vi]
    uc = math.sqrt(sum(aa))
    veff = uc ** 4 / sum(a ** 2 / vi for a, (_, _, vi) in zip(aa, ui_ci_vi))
    k = tinv(0.05, veff)
    return {'uc': uc, 'veff': veff, 'k': k, 'u_hitung': uc * k}


KOL_REFRAKTO = dict(u='N', pembagi='Q', vi='S', ui='U', ci='X', uici='Z', kuadrat='AC', pangkat4='AG', agg='AC')
KOL_MERATA = dict(u='M', pembagi='P', vi='R', ui='T', ci='W', uici='Y', kuadrat='AB', pangkat4='AF', agg='AB')


def blok_budget(s, kol, baris_awal, nama_komponen, kecuali_ui=()):
    """Satu blok `PERHITUNGAN U95%` (satu titik): komponen + agregat, sel demi sel.

    Posisi baris agregat (relatif baris komponen pertama, n komponen):
    jumlah n, uc n+1, v_eff n+2, k n+3, U n+4, CMC n+5, U sertifikat n+6.
    """
    n = len(nama_komponen)
    komp = {}
    for j, nama in enumerate(nama_komponen):
        r = baris_awal + j
        komp[nama] = {
            'u_mentah': s.sel(U, f"{kol['u']}{r}"), 'pembagi': s.sel(U, f"{kol['pembagi']}{r}"),
            'vi': s.sel(U, f"{kol['vi']}{r}"), 'ui': s.sel(U, f"{kol['ui']}{r}"),
            'ci': s.sel(U, f"{kol['ci']}{r}"), 'uici': s.sel(U, f"{kol['uici']}{r}"),
            'uici_kuadrat': s.sel(U, f"{kol['kuadrat']}{r}"), 'uici4_per_vi': s.sel(U, f"{kol['pangkat4']}{r}"),
        }
    a = kol['agg']
    agregat = {
        'jumlah_uici_kuadrat': s.sel(U, f'{a}{baris_awal + n}'),
        'sigma_uici4_per_vi': s.sel(U, f"{kol['pangkat4']}{baris_awal + n}"),
        'uc': s.sel(U, f'{a}{baris_awal + n + 1}'),
        'veff': s.sel(U, f'{a}{baris_awal + n + 2}'),
        'k': s.sel(U, f'{a}{baris_awal + n + 3}'),
        'u_hitung': s.sel(U, f'{a}{baris_awal + n + 4}'),
        'cmc': s.sel(U, f'{a}{baris_awal + n + 5}'),
        'u_cetak': s.sel(U, f'{a}{baris_awal + n + 6}'),
    }
    # rekonstruksi dari komponen -> agregat; dibandingkan ke cache
    rk = rantai([(komp[x]['ui']['nilai'], komp[x]['ci']['nilai'], komp[x]['vi']['nilai']) for x in nama_komponen])
    selisih = max(
        rel(rk['uc'], agregat['uc']['nilai']), rel(rk['veff'], agregat['veff']['nilai']),
        rel(rk['k'], agregat['k']['nilai']), rel(rk['u_hitung'], agregat['u_hitung']['nilai']),
        rel(max(rk['u_hitung'], agregat['cmc']['nilai']), agregat['u_cetak']['nilai']),
        # ui = U / pembagi; komponen pengulangan memakai SQRT(n) (sel menulis n = 5 atau SQRT(5) langsung).
        max(min(rel(komp[x]['ui']['nilai'], komp[x]['u_mentah']['nilai'] / komp[x]['pembagi']['nilai']),
                rel(komp[x]['ui']['nilai'], komp[x]['u_mentah']['nilai'] / math.sqrt(komp[x]['pembagi']['nilai'])))
            for x in nama_komponen if komp[x]["pembagi"]["nilai"] and x not in kecuali_ui),
    )
    return komp, agregat, selisih


def skenario(komp, agregat, nama_komponen, ganti, sumber):
    """Rumus sel `PERHITUNGAN U95%` dihitung ulang dengan komponen tertentu diganti.

    `ganti`: {nama_komponen: {'ui': ..., 'ci': ...}} (kunci yang diisi saja). Komponen lain
    memakai nilai-cache. Dipakai HANYA untuk membuktikan penyebab satu selisih.
    """
    daftar = []
    for x in nama_komponen:
        ui = ganti.get(x, {}).get('ui', komp[x]['ui']['nilai'])
        ci = ganti.get(x, {}).get('ci', komp[x]['ci']['nilai'])
        daftar.append((ui, ci, komp[x]['vi']['nilai']))
    r = rantai(daftar)
    r['u_cetak'] = max(r['u_hitung'], agregat['cmc']['nilai'])
    r['ui_diganti'] = {x: g for x, g in ganti.items()}
    r['sumber'] = sumber
    return r


def kondisi(s, awal, akhir, perh, kolom, th_sel, delta_sel=None, sert=None):
    """Blok KONDISI LINGKUNGAN satu besaran (suhu atau RH), seperti sel PERHITUNGAN.

    kolom: huruf kolom (awal, akhir, average, indexed, koreksi, delta, u95_th, u95_sert, c).
    """
    r = perh['baris']
    out = {
        'masukan_diketik': {'awal': s.sel(I, awal), 'akhir': s.sel(I, akhir)},
        'excel_awal': s.sel(P, f"{kolom['awal']}{r}"),
        'excel_akhir': s.sel(P, f"{kolom['akhir']}{r}"),
        'average': s.sel(P, f"{kolom['average']}{r}"),
        'indexed_value': s.sel(P, f"{kolom['indexed']}{r}"),
        'koreksi': s.sel(P, f"{kolom['koreksi']}{r}"),
        'delta': s.sel(P, f"{kolom['delta']}{r}"),
        'u95_std_th': s.sel(P, f"{kolom['u95_th']}{r}"),
        'u95_sertifikat': s.sel(P, f"{kolom['u95_sert']}{r}"),
        'th_dipakai': s.teks(P, th_sel),
    }
    if sert:
        out['nilai_cetak'] = s.sel(S, sert['nilai'])
        out['u95_cetak'] = s.sel(S, sert['u95'])
    return out


# ----------------------------------------------------------------------------
# Refractometer (n20D)
# ----------------------------------------------------------------------------
KOMP_REFRAKTO = ['ketidakpastian_standar', 'resolusi_alat', 'perbedaan_suhu', 'ketidakpastian_temperature', 'pengulangan_pembacaan']


def refraktometer(path):
    sha, wf, wv = buka(path)
    s = Sumber(wf, wv)
    titik_def = [
        {'nama': '1.33659 n20D', 'kol': ('G', 'H'), 'std': 'G', 'sert': 18, 'blok': 9, 'koreksi_row': 46, 'avg_kol': ('G', 'H'), 'cmc': 'S5', 'db_u': 'V15'},
        {'nama': '1.39986 n20D', 'kol': ('I', 'J'), 'std': 'I', 'sert': 19, 'blok': 24, 'koreksi_row': 47, 'avg_kol': ('I', 'J'), 'cmc': 'S6', 'db_u': 'V16'},
    ]
    maks = 0.0
    titik = []
    for t in titik_def:
        kb, ks = t['kol']
        komp, agg, sel_rk = blok_budget(s, KOL_REFRAKTO, t['blok'], KOMP_REFRAKTO)
        maks = max(maks, sel_rk)
        # Selisih dengan rumus sel: komponen 3 (perbedaan suhu) memakai sel TETAP N11/N26 -> 'Tab Konversi'!H24.
        titik.append({
            'nama': t['nama'],
            'masukan': {
                'titik_ukur': s.sel(I, f"{kb}32"),
                'pembacaan_sesudah': s.deret(I, [f'{kb}{r}' for r in range(43, 48)]),
                'suhu_sesudah_c': s.deret(I, [f'{ks}{r}' for r in range(43, 48)]),
                'pembacaan_sebelum': s.deret(I, [f'{kb}{r}' for r in range(34, 39)]),
                'suhu_sebelum_c': s.deret(I, [f'{ks}{r}' for r in range(34, 39)]),
            },
            'standar': {
                'nilai_standar': s.sel(P, f"{t['std']}22"),
                'rata_rata_teramati': s.sel(P, f"{kb}40"),
                'suhu_larutan_rata_c': s.sel(P, f"{ks}40"),
                'stdev_sampel': s.sel(P, f"{kb}41"),
                'rata_rata_terkoreksi_20c': s.sel(P, f"J{t['koreksi_row']}"),
                'rumus_terkoreksi': {'sel': f"{P}!J{t['koreksi_row']}", 'rumus': s.rumus(P, f"J{t['koreksi_row']}")},
            },
            'komponen': komp,
            'agregat': agg,
            'u95_standar_database': s.sel(DB, t['db_u']),
            'cmc_database': s.sel(DB, t['cmc']),
            'cetak': {
                'standar': cetak(s, S, f"C{t['sert']}"), 'uut': cetak(s, S, f"I{t['sert']}"),
                'koreksi': cetak(s, S, f"O{t['sert']}"), 'u95': cetak(s, S, f"R{t['sert']}"),
            },
        })

    # Tabel konversi suhu (113 baris). Aplikasi memakai model garis lurus; tiap baris diadu.
    baris = [r for r in range(4, 117) if angka(s.nilai(T, f'B{r}')) is not None]
    tabel = {
        'rentang_sel': f'{T}!B{baris[0]}:H{baris[-1]}',
        'suhu_c': [s.n(T, f'B{r}') for r in baris],
        'n20d_teramati': [s.n(T, f'F{r}') for r in baris],
        'n20d_terkoreksi': [s.n(T, f'G{r}') for r in baris],
        'n20d_deviasi': [s.n(T, f'H{r}') for r in baris],
        'brix_teramati': [s.n(T, f'C{r}') for r in baris],
        'brix_terkoreksi': [s.n(T, f'D{r}') for r in baris],
        'brix_deviasi': [s.n(T, f'E{r}') for r in baris],
        'rumus_n20d': s.rumus(T, 'G4'), 'rumus_brix': s.rumus(T, 'D4'),
    }

    # Skenario: suhu ruang sesi lain (23,0 C). Excel tetap memakai sel tetap H24 (N11), aplikasi mencari
    # baris menurut suhu ruang. Deviasi yang akan dipakai aplikasi = H pada baris B = 23,0.
    r23 = next(r for r in baris if abs(s.n(T, f'B{r}') - 23.0) < 1e-9)
    dev_23 = s.sel(T, f'H{r23}')
    for t, ti in zip(titik_def, titik):
        ganti = {'perbedaan_suhu': {'ui': dev_23['nilai'] / ti['komponen']['perbedaan_suhu']['pembagi']['nilai']}}
        ti['dengan_suhu_ruang_23c'] = skenario(
            ti['komponen'], ti['agregat'], KOMP_REFRAKTO, ganti,
            f"rumus sel PERHITUNGAN U95%: komponen perbedaan_suhu (U{t['blok'] + 2}) := {T}!H{r23} / pembagi; komponen lain dari cache",
        )

    kond = {
        'th': s.teks(I, 'Z10'),
        'suhu': kondisi(s, 'E21', 'F21', {'baris': 14},
                        dict(awal='E', akhir='F', average='G', indexed='H', koreksi='N', delta='M', u95_th='O', u95_sert='Q'), 'E16',
                        sert={'nilai': 'T11', 'u95': 'W11'}),
        'kelembaban': kondisi(s, 'E22', 'F22', {'baris': 15},
                              dict(awal='E', akhir='F', average='G', indexed='H', koreksi='N', delta='M', u95_th='O', u95_sert='Q'), 'E16',
                              sert={'nilai': 'T12', 'u95': 'W12'}),
        'rumus_u95_th_rh': {'sel': f'{P}!O15', 'rumus': s.rumus(P, 'O15')},
        'format_cetak': {'suhu': s.format(S, 'T11'), 'rh': s.format(S, 'T12')},
    }

    hasil = {
        'label': 'Refractometer (n20D)',
        'berkas': os.path.basename(path),
        'sha256_12': sha[:12],
        'masukan': {
            'resolusi_alat': s.sel(I, 'E16'),
            'satuan': s.teks(I, 'Z9'),
            'suhu_ruang_c': {'awal': s.sel(I, 'E21'), 'akhir': s.sel(I, 'F21'), 'rata_rata': s.sel(I, 'H21')},
        },
        'konstanta': {
            'u95_termometer_c': s.sel(U, 'V4'), 'u95_sensor_c': s.sel(U, 'AC4'), 'u_temperature': s.sel(U, 'AJ3'),
            'u95_termometer_database_c': s.sel(DB, 'V17'), 'u95_sensor_database_c': s.sel(DB, 'V18'),
            'deviasi_suhu_sel_tetap': {**s.sel(U, 'N11'), 'rumus': s.rumus(U, 'N11'),
                                       'baris_tetap_suhu_c': s.sel(T, 'B24'), 'baris_tetap_deviasi': s.sel(T, 'H24')},
            'koef_n20d_per_c': {'sel': f'{T}!G4', 'rumus': s.rumus(T, 'G4'), 'nilai': 0.00045},
            'koef_brix_per_c': {'sel': f'{T}!D4', 'rumus': s.rumus(T, 'D4'), 'nilai': 0.07},
            'pembagi_standar_refracto': s.sel(U, 'Q9'),
        },
        'titik': titik,
        'tabel_konversi_suhu': tabel,
        'cetak_k': cetak(s, S, 'U21'),
        'kondisi_lingkungan': kond,
        'rekonstruksi': {'selisih_relatif_maks_terhadap_cache': maks,
                         'catatan': 'komponen -> uc -> veff -> k (t 0,975; floor(veff)) -> U -> max(U, CMC) dihitung ulang dari sel komponen'},
        'tanggal_terbit': {'sel': f'{S}!B37', 'rumus': s.rumus(S, 'B37')},
    }
    if maks > TOL_REKONSTRUKSI:
        raise SystemExit(f'refractometer: rekonstruksi menyimpang dari cache ({maks:.3e}); berhenti.')
    print(f'refractometer: ok, rekonstruksi {maks:.2e}, {len(baris)} baris tabel konversi')
    return hasil


# ----------------------------------------------------------------------------
# Turbidimeter
# ----------------------------------------------------------------------------
KOMP_TURBID = ['ketidakpastian_standar', 'resolusi_alat', 'ketidakpastian_temperature', 'pengulangan_pembacaan']


def turbidimeter(path):
    sha, wf, wv = buka(path)
    s = Sumber(wf, wv)
    defs = [
        {'nama': '1 NTU', 'kb': 'G', 'ks': 'H', 'blok': 11, 'sert': 22, 'res': 'E17', 'db_u': 'W13', 'cmc': 'T5'},
        {'nama': '100 NTU', 'kb': 'I', 'ks': 'J', 'blok': 25, 'sert': 23, 'res': 'F17', 'db_u': 'W14', 'cmc': 'T6'},
        {'nama': '1000 NTU', 'kb': 'K', 'ks': 'L', 'blok': 39, 'sert': 24, 'res': 'G17', 'db_u': 'W15', 'cmc': 'T7'},
    ]
    maks = 0.0
    titik = []
    for t in defs:
        kb, ks = t['kb'], t['ks']
        komp, agg, sel_rk = blok_budget(s, KOL_MERATA, t['blok'], KOMP_TURBID)
        maks = max(maks, sel_rk)
        titik.append({
            'nama': t['nama'],
            'masukan': {
                'titik_ukur': s.sel(I, f'{kb}35'),
                'resolusi_titik': s.sel(I, t['res']),
                'pembacaan_sesudah': s.deret(I, [f'{kb}{r}' for r in range(46, 51)]),
                'suhu_sesudah_c': s.deret(I, [f'{ks}{r}' for r in range(46, 51)]),
                'pembacaan_sebelum': s.deret(I, [f'{kb}{r}' for r in range(37, 42)]),
            },
            'standar': {
                'nilai_standar': s.sel(P, f'{kb}35'),
                'rata_rata_uut': s.sel(P, f'{kb}42'),
                'stdev_sampel': s.sel(P, f'{kb}44'),
                'koreksi_lembar_uut_minus_standar': s.sel(P, f'{kb}43'),
            },
            'sebelum_adjustment': {
                'rata_rata': s.sel(P, f'{kb}29'),
                'koreksi_lembar': s.sel(P, f'{kb}30'),
                'stdev_sampel': s.sel(P, f'{kb}31'),
            },
            'komponen': komp,
            'agregat': agg,
            'u95_standar_database': s.sel(DB, t['db_u']),
            'cmc_database': s.sel(DB, t['cmc']),
            'cetak': {
                'standar': cetak(s, S, f"C{t['sert']}"), 'uut': cetak(s, S, f"J{t['sert']}"),
                'koreksi': cetak(s, S, f"P{t['sert']}"), 'u95': cetak(s, S, f"S{t['sert']}"),
            },
        })

    kolom_env = dict(awal='E', akhir='F', average='G', indexed='H', koreksi='N', delta='M', u95_th='O', u95_sert='Q')
    kond = {
        'th': s.teks(I, 'Z15'),
        'suhu': kondisi(s, 'E22', 'F22', {'baris': 14}, kolom_env, 'E16', sert={'nilai': 'U11', 'u95': 'X11'}),
        'kelembaban': kondisi(s, 'E23', 'F23', {'baris': 15}, kolom_env, 'E16', sert={'nilai': 'U12', 'u95': 'X12'}),
        'rumus_awal_rh': {'sel': f'{P}!E15', 'rumus': s.rumus(P, 'E15')},
        'rumus_u95_th_rh': {'sel': f'{P}!O15', 'rumus': s.rumus(P, 'O15')},
        'format_cetak': {'suhu': s.format(S, 'U11'), 'rh': s.format(S, 'U12')},
    }

    hasil = {
        'label': 'Turbidimeter (NTU)',
        'berkas': os.path.basename(path),
        'sha256_12': sha[:12],
        'masukan': {'resolusi_titik': [s.sel(I, 'E17'), s.sel(I, 'F17'), s.sel(I, 'G17')]},
        'konstanta': {
            'u95_termometer_c': s.sel(DB, 'W18'), 'u95_sensor_c': s.sel(DB, 'W19'), 'u_temperature': s.sel(U, 'AE4'),
            'pembagi_komponen_suhu': s.sel(U, 'P13'),
        },
        'titik': titik,
        'cetak_k': cetak(s, S, 'U27'),
        'kondisi_lingkungan': kond,
        'rekonstruksi': {'selisih_relatif_maks_terhadap_cache': maks,
                         'catatan': 'komponen -> uc -> veff -> k (t 0,975; floor(veff)) -> U -> max(U, CMC) dihitung ulang dari sel komponen'},
        'tanggal_terbit': {'sertifikat': {'sel': f'{S}!B40', 'rumus': s.rumus(S, 'B40')},
                           'input_data': {'sel': f'{I}!D55', 'rumus': s.rumus(I, 'D55')}},
        'baris_kosong_dicetak': {'sel': f'{S}!C25:S26', 'catatan': 'titik ke-4/5 kosong tetap tercetak 0 / #REF! / 0,3714'},
    }
    if maks > TOL_REKONSTRUKSI:
        raise SystemExit(f'turbidimeter: rekonstruksi menyimpang dari cache ({maks:.3e}); berhenti.')
    print(f'turbidimeter: ok, rekonstruksi {maks:.2e}')
    return hasil


# ----------------------------------------------------------------------------
# Chlorine Meter
# ----------------------------------------------------------------------------
KOMP_KLOR = ['ketidakpastian_standar', 'resolusi_alat', 'ketidakpastian_temperature', 'faktor_pengenceran', 'pengulangan_pembacaan']


def klorin(path):
    sha, wf, wv = buka(path)
    s = Sumber(wf, wv)
    defs = [
        {'nama': '1.74 mg/L', 'kb': 'G', 'ks': 'H', 'blok': 22, 'sert': 18, 'db_u': 'W13', 'cmc': 'T5', 'stdev_kol': 'G'},
        {'nama': '1.83 mg/L', 'kb': 'I', 'ks': 'J', 'blok': 37, 'sert': 19, 'db_u': 'W14', 'cmc': 'T6', 'stdev_kol': 'I'},
    ]
    maks = 0.0
    titik = []
    for t in defs:
        kb, ks = t['kb'], t['ks']
        # `T41` (titik 1,83) adalah ANGKA TEMPELAN 0,0245 (bukan rumus `M41/SQRT(P41)`): kecualikan dari
        # pemeriksaan ui = u/sqrt(n) dan catat selisihnya apa adanya (konflik, bukan salah baca).
        tempelan = s.rumus(U, f"T{t['blok'] + 4}") is None
        komp, agg, sel_rk = blok_budget(s, KOL_MERATA, t['blok'], KOMP_KLOR,
                                        kecuali_ui=('pengulangan_pembacaan',) if tempelan else ())
        maks = max(maks, sel_rk)
        rumus_typea = s.rumus(U, f"T{t['blok'] + 4}")
        entri = {
            'nama': t['nama'],
            'masukan': {
                'titik_ukur': s.sel(I, f'{kb}32'),
                'pembacaan_sesudah': s.deret(I, [f'{kb}{r}' for r in range(43, 48)]),
                'suhu_sesudah_c': s.deret(I, [f'{ks}{r}' for r in range(43, 48)]),
                'pembacaan_sebelum': s.deret(I, [f'{kb}{r}' for r in range(34, 39)]),
            },
            'standar': {
                'nilai_standar': s.sel(P, f'{kb}35'),
                'rata_rata_uut': s.sel(P, f'{kb}42'),
                'stdev_sampel': s.sel(P, f'{kb}44'),
                'koreksi_lembar': s.sel(P, f'{kb}43'),
                'rumus_koreksi_lembar': {'sel': f'{P}!{kb}43', 'rumus': s.rumus(P, f'{kb}43')},
                'suhu_larutan_rata_c': s.sel(P, f'{ks}42'),
            },
            'komponen': komp,
            'agregat': agg,
            'rumus_ui_pengulangan': {'sel': f"{U}!T{t['blok'] + 4}", 'rumus': rumus_typea},
            'u95_standar_database': s.sel(DB, t['db_u']),
            'cmc_database': s.sel(DB, t['cmc']),
            'cetak': {
                'standar': cetak(s, S, f"C{t['sert']}"), 'uut': cetak(s, S, f"J{t['sert']}"),
                'koreksi': cetak(s, S, f"P{t['sert']}"), 'u95': cetak(s, S, f"S{t['sert']}"),
                'remark': s.teks(S, f"W{t['sert']}"),
            },
        }
        # Skenario: Type A dari pembacaan nyata (stdev PERHITUNGAN!G44/I44 -> ui = stdev/sqrt(5)).
        stdev = s.n(P, f"{t['stdev_kol']}44")
        ui_nyata = stdev / math.sqrt(komp['pengulangan_pembacaan']['pembagi']['nilai'] ** 2)
        entri['dengan_type_a_dari_pembacaan'] = skenario(
            komp, agg, KOMP_KLOR, {'pengulangan_pembacaan': {'ui': ui_nyata}},
            f"rumus sel PERHITUNGAN U95%: komponen pengulangan (T{t['blok'] + 4}) := {P}!{t['stdev_kol']}44 / SQRT(n=5); komponen lain dari cache",
        )
        entri['dengan_type_a_dari_pembacaan']['ui_pengulangan'] = ui_nyata
        titik.append(entri)

    kolom_env = dict(awal='E', akhir='F', average='G', indexed='H', koreksi='I', delta='K', u95_th='M', u95_sert='O')
    kond = {
        'th': s.teks(P, 'E16'),
        'suhu': kondisi(s, 'E21', 'F21', {'baris': 14}, kolom_env, 'E16', sert={'nilai': 'U11', 'u95': 'X11'}),
        'kelembaban': kondisi(s, 'E22', 'F22', {'baris': 15}, kolom_env, 'E16', sert={'nilai': 'U12', 'u95': 'X12'}),
        'rumus_u95_th_rh': {'sel': f'{P}!M15', 'rumus': s.rumus(P, 'M15')},
        'format_cetak': {'suhu': s.format(S, 'U11'), 'rh': s.format(S, 'U12')},
    }

    hasil = {
        'label': 'Chlorine Meter (mg/L)',
        'berkas': os.path.basename(path),
        'sha256_12': sha[:12],
        'masukan': {'resolusi_alat': s.sel(I, 'E16')},
        'konstanta': {
            'u95_termometer_c': s.sel(DB, 'W18'), 'u95_sensor_c': s.sel(DB, 'W19'), 'u_temperature': s.sel(U, 'AE4'),
            'u95_micropipette_ml': s.sel(DB, 'W20'), 'u95_labu_ukur_ml': s.sel(DB, 'W21'),
            'uc_pengenceran_l': s.sel(U, 'M25'), 'ci_pengenceran_titik_1': s.sel(U, 'W25'), 'ci_pengenceran_titik_2': s.sel(U, 'W40'),
            'rumus_ci_pengenceran_titik_1': {'sel': f'{U}!W25', 'rumus': s.rumus(U, 'W25')},
            'rumus_ci_pengenceran_titik_2': {'sel': f'{U}!W40', 'rumus': s.rumus(U, 'W40')},
        },
        'titik': titik,
        'cetak_k': cetak(s, S, 'U21'),
        'titik_ke_3_kosong': {
            'standar': s.sel(S, 'C20'), 'uut': s.sel(S, 'J20'), 'koreksi': s.sel(S, 'P20'),
            'u95': s.sel(S, 'S20'), 'k': s.sel(S, 'U21'),
        },
        'kondisi_lingkungan': kond,
        'rekonstruksi': {'selisih_relatif_maks_terhadap_cache': maks,
                         'catatan': 'komponen -> uc -> veff -> k (t 0,975; floor(veff)) -> U -> max(U, CMC) dihitung ulang dari sel komponen'},
        'tanggal_terbit': {'sertifikat': {'sel': f'{S}!B38', 'rumus': s.rumus(S, 'B38')},
                           'input_data': {'sel': f'{I}!D52', 'rumus': s.rumus(I, 'D52')}},
    }
    if maks > TOL_REKONSTRUKSI:
        raise SystemExit(f'chlorine: rekonstruksi menyimpang dari cache ({maks:.3e}); berhenti.')
    print(f'chlorine_meter: ok, rekonstruksi {maks:.2e}')
    return hasil


def tulis(nama, kunci, isi):
    kepala = {
        '_digenerate_oleh': 'docs/skrip/gen-fixture-paritas-analitik-batch2.py',
        '_catatan': 'Disalin dari nilai-cache workbook master (alamat sel di tiap nilai). Tanpa nama/alamat pelanggan, '
                    'nomor sertifikat, nomor order, atau nama teknisi. Jangan disunting dengan tangan.',
    }
    with open(os.path.join(KELUAR, nama), 'w', encoding='utf-8', newline='\n') as f:
        json.dump({**kepala, kunci: isi}, f, ensure_ascii=False, indent=2)
        f.write('\n')


def main():
    if len(sys.argv) < 2:
        raise SystemExit(__doc__)
    sumber = sys.argv[1]
    os.makedirs(KELUAR, exist_ok=True)
    try:
        ref = refraktometer(os.path.join(sumber, BERKAS['refractometer']))
        tur = turbidimeter(os.path.join(sumber, BERKAS['turbidimeter']))
        klo = klorin(os.path.join(sumber, BERKAS['chlorine_meter']))
    except SandiTidakAda as e:
        raise SystemExit(f'BERHENTI: variabel lingkungan {e} tidak ada.')

    tulis('refractometer.json', 'workbook', ref)
    tulis('turbidimeter.json', 'workbook', tur)
    tulis('chlorine-meter.json', 'workbook', klo)
    print('selesai:', KELUAR)


if __name__ == '__main__':
    main()

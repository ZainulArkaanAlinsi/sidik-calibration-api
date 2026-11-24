"""Bangun tests/Fixtures/paritas/{jangka-sorong,conductivity-meter}.json dari workbook master.

Bahan uji PARITAS aplikasi-lawan-Excel (`tests/Feature/Paritas/`) untuk Jangka
Sorong (SIDIK-FM-CAL-0527 Rev.2) dan Conductivity Meter (SIDIK-FM-CAL-0510
Rev.5): MASUKAN yang diketik di workbook dan NILAI tiap tahap hitungan Excel,
masing-masing dengan alamat sel sumbernya (`SHEET!SEL`), disalin apa adanya dari
nilai-cache workbook. Keluarannya BUKAN keluaran PHP, dan tidak boleh disunting
dengan tangan.

Pembantu baca-sel (`buka`, `Sumber`, `teks_excel`, ...) diimpor dari
`gen-fixture-paritas-micrometer-ph.py` (generator PR #299, tidak diubah) supaya
satu cara membaca workbook yang dirawat.

## Aturan keras (sama dengan generator Micrometer/pH)

- Sandi workbook HANYA dari variabel lingkungan `SIDIK_MASTER_SANDI`, dibaca ke
  memori. Tidak ada salinan terbuka-kunci di disk; sandi tidak dicetak, tidak
  ditulis ke fixture, dan tidak dimasukkan ke pesan galat (hanya tipe galat).
- Fixture TIDAK memuat nama/alamat pelanggan, nomor sertifikat, nomor order, atau
  nama teknisi: skrip hanya membaca sel angka/teks teknis yang disebut di bawah.
- `DATABASE!X11 =NOW()` (kedua workbook): nilai-cache U95 yang bergantung drift
  hanya sah pada X11 yang tersimpan di berkas itu. Jangka Sorong memakai X11
  tersimpan (`drift.x11_cache`); aplikasi diberi tanggal sesi = X11 itu.
- Sebelum menulis, skrip menghitung ulang rantai budget dari sel dasarnya dan
  membandingkannya ke nilai-cache; selisih relatif terbesar dicetak dan dicatat
  di fixture (`rekonstruksi.selisih_relatif_maks`). Di atas 1e-11 skrip berhenti:
  berarti pembacaannya (bukan workbook) yang salah.

## Pakai

    py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-jangka-sorong-conductivity.py <folder-Downloads>

`<folder-Downloads>` berisi `EXEL CALIBRATION SIDIK\\Master Olah Data Caliper 2026
(std caliper checker+gb).xlsm` dan `EXEL CALIBRATION SIDIK\\Master Olah
Data_Conductivity.xlsm`. Dependensi: msoffcrypto-tool, openpyxl, scipy.
"""
import importlib.util
import json
import math
import os
import re
import sys
from datetime import datetime

AKAR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
KELUAR = os.path.join(AKAR, 'tests', 'Fixtures', 'paritas')

_spec = importlib.util.spec_from_file_location(
    'gen_paritas_dasar',
    os.path.join(os.path.dirname(os.path.abspath(__file__)), 'gen-fixture-paritas-micrometer-ph.py'),
)
D = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(D)

TOL_REKONSTRUKSI = 1e-11

JS_BERKAS = 'EXEL CALIBRATION SIDIK/Master Olah Data Caliper 2026 (std caliper checker+gb).xlsm'
CD_BERKAS = 'EXEL CALIBRATION SIDIK/Master Olah Data_Conductivity.xlsm'

I, P, U, DB, S, S2 = 'INPUT DATA', 'PERHITUNGAN', 'PERHITUNGAN U95%', 'DATABASE', 'SERTIFIKAT', 'SERTIFIKAT (2)'


def tinv(p, df):
    return D.tinv(p, df)


def rel(a, b):
    return D.rel(a, b)


def deret_angka(src, sheet, sels):
    """Nilai angka per sel; sel kosong/teks DILEWATI, NOL TETAP (titik nol rahang tertutup sah)."""
    pasangan = [(s, D.angka(src.nilai(sheet, s))) for s in sels]
    pasangan = [(s, v) for s, v in pasangan if v is not None]
    return {'sel': [f'{sheet}!{s}' for s, _ in pasangan], 'nilai': [v for _, v in pasangan]}


def kolom_cetak(src, sheet, sel):
    fmt = src.format(sheet, sel)
    v = src.n(sheet, sel, wajib=False)
    teks = D.teks_excel(v, fmt) if re.fullmatch(r'0(?:\.0+)?', fmt) else None
    k = {'sel': f'{sheet}!{sel}', 'nilai': v, 'format': fmt, 'teks': teks}
    return D.catat_nol(k) if teks is not None else k


def rumus_atau_none(src, sheet, sel):
    v = src.wf[sheet][sel].value
    return v if isinstance(v, str) and v.startswith('=') else None


# ============================================================================
# Jangka Sorong
# ============================================================================
KOL_BACAAN_INPUT = ['F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'P']  # X1..X5' (INPUT DATA)
KOL_EVALUASI = ['C', 'D', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M']  # X1..X10 (INPUT DATA baris 31/36)
KOL_BACAAN_HITUNG = ['I', 'J', 'K', 'L', 'M', 'N', 'O', 'P', 'Q', 'S']  # PERHITUNGAN

# (kunci komponen aplikasi, baris `PERHITUNGAN U95%`) menurut URUTAN SHEET
KOMP_OUTSIDE = [('pengulangan', 5), ('resolusi_uut', 6), ('ketidakpastian_standar', 7), ('suhu_ruang', 8),
                ('koefisien_muai', 9), ('drift_standar', 10), ('wringing', 11), ('geometri', 12),
                ('efek_mekanik', 13), ('selisih_suhu', 14)]
KOMP_INSIDE = [('pengulangan', 24), ('resolusi_uut', 25), ('ketidakpastian_standar', 26), ('suhu_ruang', 27),
               ('selisih_suhu', 28), ('koefisien_muai', 29), ('drift_standar', 30), ('wringing', 31),
               ('geometri', 32), ('efek_mekanik', 33)]
KOMP_DEPTH = [('pengulangan', 43), ('resolusi_uut', 44), ('ketidakpastian_standar', 45), ('suhu_ruang', 46),
              ('selisih_suhu', 47), ('koefisien_muai', 48), ('drift_standar', 49), ('wringing', 50),
              ('geometri', 51), ('efek_mekanik', 52), ('meja_granit', 53)]

# grup -> (komponen, baris agregat: jumlah AA, sigma AC, uc, veff, k, U, U sertifikat)
AGREGAT_JS = {
    'outside': (KOMP_OUTSIDE, dict(jumlah=15, uc=16, veff=17, k=18, u=19, sert=21)),
    'inside': (KOMP_INSIDE, dict(jumlah=34, uc=35, veff=36, k=37, u=38, sert=40)),
    'depth': (KOMP_DEPTH, dict(jumlah=54, uc=55, veff=56, k=57, u=58, sert=60)),
}
# grup -> (baris awal INPUT DATA, baris awal PERHITUNGAN, jumlah titik, baris awal cetak SERTIFIKAT)
BLOK_TITIK = {
    'outside': (42, 37, 10, 25),
    'inside': (77, 72, 10, 41),
    'depth': (111, 106, 5, 57),
}


def js_komponen(s, daftar):
    out = {}
    for kunci, r in daftar:
        out[kunci] = {
            'baris': r,
            'u_mentah': s.sel(U, f'K{r}'), 'pembagi': s.sel(U, f'N{r}'), 'vi': s.sel(U, f'Q{r}'),
            'ui': s.sel(U, f'T{r}'), 'ci': s.sel(U, f'V{r}'), 'uici': s.sel(U, f'X{r}'),
            'uici_kuadrat': s.sel(U, f'AA{r}'), 'uici4_per_vi': s.sel(U, f'AC{r}'),
        }
    return out


def js_agregat(s, a):
    return {
        'jumlah_uici_kuadrat': s.sel(U, f'AA{a["jumlah"]}'),
        'sigma_uici4_per_vi': s.sel(U, f'AC{a["jumlah"]}'),
        'uc': s.sel(U, f'AA{a["uc"]}'), 'veff': s.sel(U, f'AA{a["veff"]}'), 'k': s.sel(U, f'AA{a["k"]}'),
        'u_hitung': s.sel(U, f'AA{a["u"]}'), 'u_sertifikat': s.sel(U, f'AA{a["sert"]}'),
    }


def js_rantai(komp, agg, ganti_ui=None):
    """Hitung ulang uc -> veff -> k -> U dari ui/ci/vi cache (opsional: `ganti_ui` menimpa ui tertentu)."""
    aa = {}
    for n, c in komp.items():
        ui = c['ui']['nilai']
        if ganti_ui and n in ganti_ui:
            ui = ganti_ui[n]
        aa[n] = (ui * c['ci']['nilai']) ** 2
    uc = math.sqrt(sum(aa.values()))
    sigma = sum(aa[n] ** 2 / komp[n]['vi']['nilai'] for n in aa)
    veff = uc ** 4 / sigma
    k = tinv(0.05, veff)
    return {'uc': uc, 'veff': veff, 'k': k, 'u_hitung': k * uc}


def jangka_sorong(path):
    sha, wf, wv = D.buka(path)
    s = D.Sumber(wf, wv)

    # ---------------- masukan ----------------
    pra_out = deret_angka(s, I, [f'{c}31' for c in KOL_EVALUASI])
    pra_in = deret_angka(s, I, [f'{c}36' for c in KOL_EVALUASI])

    masukan = {
        'satuan_kode': s.sel(I, 'G14'),
        'kapasitas_ketik': s.sel(I, 'E15'),
        'resolusi_ketik': s.sel(I, 'E16'),
        'suhu_awal_c': s.sel(I, 'E21'),
        'suhu_akhir_c': s.sel(I, 'F21'),
        'pra_evaluasi_outside': pra_out,
        'pra_evaluasi_inside': pra_in,
        'kesejajaran': [
            {'posisi': s.nilai(I, f'C{r}'), 'nominal': s.sel(I, f'D{r}'), 'pembacaan': s.sel(I, f'F{r}')}
            for r in (130, 131, 132)
        ],
        'kerataan_baik_dicentang': s.nilai(I, 'Y22'),
    }
    for grup, (ri, rh, n, rc) in BLOK_TITIK.items():
        titik = []
        for i in range(n):
            r = ri + 3 * i
            nominal = deret_angka(s, I, [f'C{r + d}' for d in range(3)])
            pembacaan = deret_angka(s, I, [f'{c}{r}' for c in KOL_BACAAN_INPUT])
            titik.append({'titik_ke': i + 1, 'nominal_mm': nominal, 'pembacaan_mm': pembacaan})
        masukan[grup] = titik

    # ---------------- nilai Excel per tahap ----------------
    excel = {
        'kapasitas_mm': s.sel(P, 'G8'),
        'resolusi_mm': s.sel(P, 'G9'),
        'suhu_ruang_rata_c': s.sel(P, 'G14'),
        'theta_c': {'outside': s.sel(P, 'X37'), 'inside': s.sel(P, 'X72'), 'depth': s.sel(P, 'X106')},
        'l_maks_outside_mm': s.sel(P, 'C67'),
        'keping_maks': {'outside': s.sel(P, 'B67'), 'inside': s.sel(P, 'B102'), 'depth': s.sel(P, 'B121')},
        'c_maks_depth_mm': s.sel(P, 'C121'),
        'stdev_evaluasi': {'outside': s.sel(P, 'N25'), 'inside': s.sel(P, 'N31')},
    }
    cetak = {}
    for grup, (ri, rh, n, rc) in BLOK_TITIK.items():
        titik, baris_cetak = [], []
        for i in range(n):
            r = rh + 3 * i
            t = {
                'titik_ke': i + 1,
                'total_nominal_mm': s.sel(P, f'H{r}'),
                'rata_rata_mm': s.sel(P, f'U{r}'),
                'standar_terkoreksi_mm': s.sel(P, f'AH{r}'),
                'stdev_mm': s.sel(P, f'AI{r}'),
                'koreksi_mm': s.sel(P, f'AJ{r}'),
            }
            if grup == 'depth':
                # Bacaan Depth di PERHITUNGAN datang dari BERKAS LUAR `[3]INPUT DATA`;
                # nilai-cache-nya disimpan sebagai pembacaan "milik Excel".
                t['pembacaan_cache_excel_mm'] = deret_angka(s, P, [f'{c}{r}' for c in KOL_BACAAN_HITUNG])
            titik.append(t)
            rr = rc + i
            baris_cetak.append({
                'titik_ke': i + 1,
                'standar': kolom_cetak(s, S, f'D{rr}'),
                'uut': kolom_cetak(s, S, f'I{rr}'),
                'koreksi': kolom_cetak(s, S, f'L{rr}'),
                'rumus': {c: rumus_atau_none(s, S, f'{c}{rr}') for c in 'DIL'},
                'rumus_lembar_2': {c: rumus_atau_none(s, S2, f'{c}{rr}') for c in 'DIL'},
            })
        excel[grup] = titik
        cetak[grup] = baris_cetak

    komp_out = js_komponen(s, KOMP_OUTSIDE)
    komp_in = js_komponen(s, KOMP_INSIDE)
    komp_dep = js_komponen(s, KOMP_DEPTH)
    excel['komponen'] = {'outside': komp_out, 'inside': komp_in, 'depth': komp_dep}
    excel['agregat'] = {g: js_agregat(s, a) for g, (_, a) in AGREGAT_JS.items()}

    cetak['u95'] = {'outside': kolom_cetak(s, S, 'J35'), 'inside': kolom_cetak(s, S, 'J51'), 'depth': kolom_cetak(s, S, 'J62')}
    cetak['k'] = {'outside': kolom_cetak(s, S, 'O36'), 'inside': kolom_cetak(s, S, 'O52'), 'depth': kolom_cetak(s, S, 'O63')}
    cetak['kesejajaran'] = [
        {'nominal': kolom_cetak(s, S, f'F{r}'), 'pembacaan': kolom_cetak(s, S, f'J{r}'), 'koreksi': kolom_cetak(s, S, f'M{r}')}
        for r in (18, 19, 20)
    ]
    cetak['kerataan_teks'] = {'sel': f'{S}!F65', 'nilai': s.nilai(S, 'F65'), 'rumus': rumus_atau_none(s, S, 'F65')}
    cetak['lantai_cmc_di_sheet'] = {
        'sel': [f'{U}!AA20', f'{U}!AA39', f'{U}!AA59'],
        'nilai': [s.nilai(U, 'AA20'), s.nilai(U, 'AA39'), s.nilai(U, 'AA59')],
        'rumus_sertifikat': [rumus_atau_none(s, U, 'AA21'), rumus_atau_none(s, U, 'AA40'), rumus_atau_none(s, U, 'AA60')],
    }
    cetak['rumus_inch_l28'] = {'sel': f'{S}!L28', 'rumus': rumus_atau_none(s, S, 'L28')}
    excel['cetak'] = cetak

    # ---------------- konstanta (sumber sel) ----------------
    tabel_gb = {}
    for r in range(10, 133):
        a, b, c = D.angka(s.nilai('Standar_GB', f'Q{r}')), D.angka(s.nilai('Standar_GB', f'R{r}')), D.angka(s.nilai('Standar_GB', f'S{r}'))
        if a is not None and b is not None:
            tabel_gb[round(a, 6)] = {'sel': f'Standar_GB!Q{r}:S{r}', 'nilai_terkoreksi_mm': b, 'u95_mm': c}

    def tabel_cc(r0, r1):
        return [{'nominal_mm': s.sel('Std_CaliperCek', f'C{r}'), 'nilai_terkoreksi_mm': s.sel('Std_CaliperCek', f'F{r}'),
                 'u95_um': s.sel('Std_CaliperCek', f'J{r}')} for r in range(r0, r1 + 1)]

    def ambil_konstanta(sheet, sel, pola):
        f = rumus_atau_none(s, sheet, sel) or ''
        m = re.search(pola, f)
        if not m:
            raise ValueError(f'rumus {sheet}!{sel} tak cocok pola {pola}: {f}')
        return float(m.group(1))

    konstanta = {
        'cmc_um': s.sel(DB, 'T5'),
        'faktor_ke_mm': {'mm': s.sel(DB, 'T23'), 'inch': s.sel(DB, 'T24')},
        'alpha_standar_per_c': s.sel(P, 'Y37'),
        'alpha_uut_per_c': s.sel(P, 'Z37'),
        'u_alpha_caliper_checker_per_c': s.sel(P, 'Z24'),
        'u_alpha_balok_ukur_per_c': s.sel(P, 'Z28'),
        'pembagi_muai': s.sel(U, 'N9'),
        'drift_a_um': {'sel': f'{U}!K10 (rumus)', 'nilai': ambil_konstanta(U, 'K10', r'\(([\d.]+)\+')},
        'drift_b_um_per_mm': {'sel': f'{U}!K10 (rumus)', 'nilai': ambil_konstanta(U, 'K10', r'\+([\d.]+)\*PERHITUNGAN')},
        'drift_pembagi_umur_rumus': {'sel': f'{U}!K10 (rumus)', 'nilai': ambil_konstanta(U, 'K10', r'/([\d.]+)\)$'),
                                     'rumus': rumus_atau_none(s, U, 'K10')},
        'wringing_um_per_keping': {'sel': f'{U}!K11 (rumus)', 'nilai': ambil_konstanta(U, 'K11', r'\(([\d.]+)\^2\)')},
        'geometri_mm': s.sel(U, 'K12'),
        'efek_mekanik_mm': s.sel(U, 'K13'),
        'meja_granit_mm': s.sel(U, 'K53'),
        'vi_type_b': s.sel(U, 'Q7'),
        'vi_resolusi': s.sel(U, 'Q6'),
        'pengulangan_vi': s.sel(U, 'Q5'),
        'depth_ci_muai': s.sel(U, 'V48'),
        'depth_ci_suhu': s.sel(U, 'V46'),
        'tanggal_kalibrasi_caliper_checker': {'sel': f'{DB}!W14', 'nilai': s.nilai(DB, 'W14').isoformat()},
        'tanggal_kalibrasi_balok_ukur': {'sel': f'{DB}!W13', 'nilai': s.nilai(DB, 'W13').isoformat()},
        'u95_caliper_checker_um': s.sel('Std_CaliperCek', 'J19'),
        'tabel_caliper_checker_outside': tabel_cc(10, 19),
        'tabel_caliper_checker_inside': tabel_cc(23, 32),
        'tabel_balok_ukur': {str(k): v for k, v in sorted(tabel_gb.items())},
    }

    # ---------------- drift (NOW()) dan rekonstruksi ----------------
    x11 = s.nilai(DB, 'X11')
    w14 = s.nilai(DB, 'W14')
    l_maks = excel['l_maks_outside_mm']['nilai']
    umur_cache = (x11 - w14).total_seconds() / 86400
    drift_a = konstanta['drift_a_um']['nilai']
    drift_b = konstanta['drift_b_um_per_mm']['nilai']
    drift_excel_mm = (drift_a + drift_b * l_maks) / 1000 * (umur_cache / 12)
    k10 = s.n(U, 'K10')
    if rel(drift_excel_mm, k10) > TOL_REKONSTRUKSI:
        raise SystemExit(f'jangka sorong: drift K10 rekonstruksi menyimpang ({rel(drift_excel_mm, k10):.3e}); berhenti.')

    maks_rel = 0.0
    for grup, (daftar, a) in AGREGAT_JS.items():
        komp = excel['komponen'][grup]
        r = js_rantai(komp, excel['agregat'][grup])
        ag = excel['agregat'][grup]
        for nilai, sel in ((r['uc'], ag['uc']), (r['veff'], ag['veff']), (r['k'], ag['k']), (r['u_hitung'], ag['u_hitung'])):
            maks_rel = max(maks_rel, rel(nilai, sel['nilai']))
    if maks_rel > TOL_REKONSTRUKSI:
        raise SystemExit(f'jangka sorong: rekonstruksi menyimpang dari cache ({maks_rel:.3e} > {TOL_REKONSTRUKSI}); berhenti.')

    # Skenario "drift milik aplikasi": umur dari tanggal sesi (= X11 dipotong ke detik, `getTimestamp()`),
    # dibagi 365 (bukan 12). Hanya Outside & Inside (Depth tanpa faktor umur, tidak berubah).
    x11_detik = x11.replace(microsecond=0)
    umur_app = (x11_detik - w14).total_seconds() / 86400
    drift_app_mm = (drift_a + drift_b * l_maks) / 1000 * (umur_app / 365.0)
    dengan_drift_app = {'tanggal_sesi': x11_detik.isoformat(sep=' '), 'umur_hari': umur_app, 'drift_mm': drift_app_mm}
    for grup in ('outside', 'inside'):
        komp = excel['komponen'][grup]
        pembagi = komp['drift_standar']['pembagi']['nilai']
        ui_baru = drift_app_mm / pembagi
        r = js_rantai(komp, excel['agregat'][grup], ganti_ui={'drift_standar': ui_baru})
        r['ui_drift'] = ui_baru
        r['u_sertifikat'] = r['u_hitung']  # kapasitas 600 mm: tanpa lantai CMC (`pitaCmc` null), sama dengan `AA21`
        dengan_drift_app[grup] = r

    hasil = {
        'label': 'Jangka Sorong (Caliper 2026; Outside 10 titik, Inside 10 titik, Depth 5 titik; 600 mm, resolusi 0,05)',
        'berkas': os.path.basename(path),
        'sha256_12': sha[:12],
        'masukan': masukan,
        'excel': excel,
        'konstanta': konstanta,
        'drift': {
            'x11_cache': {'sel': f'{DB}!X11', 'nilai': x11.isoformat(sep=' ')},
            'w14_caliper_checker': {'sel': f'{DB}!W14', 'nilai': w14.isoformat(sep=' ')},
            'rumus': '(0.02+0.00025*PERHITUNGAN!C67)*1/1000*((DATABASE!X11-DATABASE!W14)/12)  [PERHITUNGAN U95%!K10 -> K30; mm]',
            'umur_hari_x11_tersimpan': umur_cache,
        },
        'dengan_drift_aplikasi': dengan_drift_app,
        'rekonstruksi': {
            'selisih_relatif_maks_terhadap_cache': maks_rel,
            'catatan': 'uc, veff, k, U ketiga budget dihitung ulang dari ui/ci/vi cache (TINV = t(0,975; floor(veff)) scipy); '
                       'drift K10 dihitung ulang dari rumus sel pada X11 tersimpan',
            'drift_selisih_relatif_terhadap_cache': rel(drift_excel_mm, k10),
        },
    }
    print(f'jangka sorong: ok, rekonstruksi {maks_rel:.2e}, drift {rel(drift_excel_mm, k10):.2e}, '
          f'U outside {excel["agregat"]["outside"]["u_hitung"]["nilai"]:.12f} mm (cache X11), '
          f'{dengan_drift_app["outside"]["u_hitung"]:.12f} mm (drift aplikasi)')
    return hasil


# ============================================================================
# Conductivity Meter
# ============================================================================
TITIK_CD = [
    # nama, kode titik app, kolom (pembacaan, suhu) di INPUT DATA/PERHITUNGAN, sel titik nominal INPUT DATA,
    # baris blok U95 (titik), komponen (standar, resolusi, temperature, pengulangan), agregat (jumlah, uc, veff, k, U, CMC, sert)
    dict(nama='25 µS/cm', kode='25', kol=('F', 'G'), nominal='F37', blok=7, komp=(9, 10, 11, 12),
         agg=dict(jumlah=13, uc=14, veff=15, k=16, u=17, cmc=18, sert=19), cetak=35, sel_resolusi='E17'),
    dict(nama='1412 µS/cm', kode='1412', kol=('H', 'I'), nominal='H37', blok=21, komp=(23, 24, 25, 26),
         agg=dict(jumlah=27, uc=28, veff=29, k=30, u=31, cmc=32, sert=33), cetak=36, sel_resolusi='G17'),
    dict(nama='111 mS/cm', kode='111', kol=('L', 'M'), nominal='L37', blok=35, komp=(37, 38, 39, 40),
         agg=dict(jumlah=41, uc=42, veff=43, k=44, u=45, cmc=46, sert=47), cetak=41, sel_resolusi='I17'),
]
KOMP_CD = ['ketidakpastian_standar', 'resolusi_alat', 'ketidakpastian_temperature', 'pengulangan_pembacaan']
# titik -> kolom `PERHITUNGAN` (rata-rata/koreksi/stdev sesudah adjustment) dan sel standar (F39/H39/L39)
KOL_HITUNG_CD = {'25': ('F', 'G'), '1412': ('H', 'I'), '111': ('L', 'M')}


def conductivity(path):
    sha, wf, wv = D.buka(path)
    s = D.Sumber(wf, wv)
    titik_out = []
    maks_rel = 0.0

    for t in TITIK_CD:
        kb, ks = t['kol']
        b = t['blok']
        komp = {}
        for nama, r in zip(KOMP_CD, t['komp']):
            komp[nama] = {
                'baris': r,
                'u_mentah': s.sel(U, f'M{r}'), 'pembagi': s.sel(U, f'P{r}'), 'vi': s.sel(U, f'R{r}'),
                'ui': s.sel(U, f'T{r}'), 'ci': s.sel(U, f'W{r}'), 'uici': s.sel(U, f'Y{r}'),
                'uici_kuadrat': s.sel(U, f'AB{r}'), 'uici4_per_vi': s.sel(U, f'AF{r}'),
            }
        a = t['agg']
        agregat = {
            'jumlah_uici_kuadrat': s.sel(U, f'AB{a["jumlah"]}'), 'sigma_uici4_per_vi': s.sel(U, f'AF{a["jumlah"]}'),
            'uc': s.sel(U, f'AB{a["uc"]}'), 'veff': s.sel(U, f'AB{a["veff"]}'), 'k': s.sel(U, f'AB{a["k"]}'),
            'u_hitung': s.sel(U, f'AB{a["u"]}'), 'cmc': s.sel(U, f'AB{a["cmc"]}'), 'u_sertifikat': s.sel(U, f'AB{a["sert"]}'),
        }
        # rekonstruksi: komponen -> uc -> veff -> k -> U -> max(U, CMC)
        aa = {n: (komp[n]['ui']['nilai'] * komp[n]['ci']['nilai']) ** 2 for n in KOMP_CD}
        uc = math.sqrt(sum(aa.values()))
        veff = uc ** 4 / sum(aa[n] ** 2 / komp[n]['vi']['nilai'] for n in KOMP_CD)
        k = tinv(0.05, veff)
        u = uc * k
        for x, y in ((uc, agregat['uc']), (veff, agregat['veff']), (k, agregat['k']), (u, agregat['u_hitung']),
                     (max(u, agregat['cmc']['nilai']), agregat['u_sertifikat'])):
            maks_rel = max(maks_rel, rel(x, y['nilai']))

        hk, hs = KOL_HITUNG_CD[t['kode']]
        baris_in = range(49, 54)  # After Adjustment, 5 pengulangan
        baris_sebelum = range(39, 44)
        c = t['cetak']
        titik_out.append({
            'nama': t['nama'],
            'kode': t['kode'],
            'masukan': {
                'titik_nominal': s.sel(I, t['nominal']),
                'resolusi': s.sel(I, t['sel_resolusi']),
                'pembacaan_sesudah': deret_angka(s, I, [f'{kb}{r}' for r in baris_in]),
                'suhu_sesudah_c': deret_angka(s, I, [f'{ks}{r}' for r in baris_in]),
                'pembacaan_sebelum': deret_angka(s, I, [f'{kb}{r}' for r in baris_sebelum]),
                'suhu_sebelum_c': deret_angka(s, I, [f'{ks}{r}' for r in baris_sebelum]),
            },
            'standar': {
                'nilai_standar_sebelum': s.sel(P, f'{hk}26'),
                'nilai_standar_sesudah': s.sel(P, f'{hk}39'),
                'suhu_larutan_rata_c': s.sel(P, f'{ks}46'),
                'rata_rata_uut': s.sel(P, f'{hk}46'),
                'stdev_sampel': s.sel(P, f'{hk}48'),
                'koreksi_lembar_standar_minus_rata': s.sel(P, f'{hk}47'),
            },
            'komponen': komp,
            'agregat': agregat,
            'cetak_style2': {
                'standar': kolom_cetak(s, 'SERTIFIKAT STYLE 2', f'C{c}'),
                'uut': kolom_cetak(s, 'SERTIFIKAT STYLE 2', f'J{c}'),
                'koreksi': kolom_cetak(s, 'SERTIFIKAT STYLE 2', f'P{c}'),
                'u95': kolom_cetak(s, 'SERTIFIKAT STYLE 2', f'R{c}'),
            },
        })

    if maks_rel > TOL_REKONSTRUKSI:
        raise SystemExit(f'conductivity: rekonstruksi menyimpang dari cache ({maks_rel:.3e}); berhenti.')

    # Polinomial nilai standar (konstanta tertulis di rumus sel).
    rumus_l26 = rumus_atau_none(s, P, 'L26') or ''
    m = re.fullmatch(r'=\(\(([\d.]+)\*M33\^2\)\+\(([\d.]+)\*M33\)\+([\d.]+)\)', rumus_l26)
    if not m:
        raise ValueError(f'rumus PERHITUNGAN!L26 tak dikenal: {rumus_l26}')
    poli_111 = {'sel': f'{P}!L26', 'rumus': rumus_l26, 'a': float(m.group(1)), 'b': float(m.group(2)), 'c': float(m.group(3))}
    rumus_j26 = rumus_atau_none(s, P, 'J26') or ''
    mj = re.fullmatch(r'=\(\(([\d.]+)\*K33\^2\)\+\(([\d.]+)\*K33\)\+([\d.]+)\)/1000', rumus_j26)
    if not mj:
        raise ValueError(f'rumus PERHITUNGAN!J26 tak dikenal: {rumus_j26}')
    poli_1412_mili = {'sel': f'{P}!J26', 'rumus': rumus_j26, 'a': float(mj.group(1)), 'b': float(mj.group(2)), 'c': float(mj.group(3)),
                      'cache': s.sel(P, 'J26'), 'k33_cache': s.sel(P, 'K33'),
                      'catatan': 'varian 1,412 mS/cm: polinomial suhu pada K33 (kosong -> T=0 -> 0,738)'}

    rumus_pen = {
        'M12': rumus_atau_none(s, U, 'M12'), 'P12': s.nilai(U, 'P12'), 'R12': s.nilai(U, 'R12'),
        'M26': rumus_atau_none(s, U, 'M26'), 'M40': rumus_atau_none(s, U, 'M40'),
    }

    konstanta = {
        'u_termometer_c': s.sel(DB, 'V17'), 'u_sensor_c': s.sel(DB, 'V18'), 'u_temperature_gabungan': s.sel(U, 'Z5'),
        'u95_larutan': {'25': s.sel(DB, 'V13'), '1412': s.sel(DB, 'V14'), '111_ketik': s.sel(DB, 'V15')},
        'u95_larutan_111_dipakai_ms': s.sel(U, 'M37'),
        'cmc': {'25': s.sel(DB, 'S5'), '1412': s.sel(DB, 'S6'), '1412_mili': s.sel(DB, 'S7'), '111': s.sel(DB, 'S8')},
        'nominal_larutan_ketik': {'25': s.sel(P, 'F26'), '1412': s.sel(P, 'H26'), '111_poli': poli_111},
        'poli_1412_mili': poli_1412_mili,
        'x11_cache': {'sel': f'{DB}!X11', 'nilai': s.nilai(DB, 'X11').isoformat(sep=' ')},
        'satuan_resolusi': {'25': s.nilai(I, 'F17'), '1412': s.nilai(I, 'H17'), '111': s.nilai(I, 'J17')},
        'pengulangan_terkunci': rumus_pen,
        'master_workbook_label_slot': {'E16': s.nilai(I, 'E16'), 'G16': s.nilai(I, 'G16'), 'I16': s.nilai(I, 'I16')},
    }

    # kondisi lingkungan (tidak diadu di tes ini; dicatat sebagai bukti sel untuk dokumen)
    lingkungan = {
        'suhu_rata': s.sel(P, 'G16'), 'koreksi_suhu': s.sel(P, 'I16'), 'delta_suhu': s.sel(P, 'M16'),
        'u95_suhu': s.sel(P, 'Q16'), 'rh_rata': s.sel(P, 'G17'), 'koreksi_rh': s.sel(P, 'I17'), 'u95_rh': s.sel(P, 'Q17'),
    }

    # Tanggal terbit & style sertifikat (bukti sel untuk dokumen, bukan tes angka)
    tanggal = {
        'input_d57': {'sel': f'{I}!D57', 'rumus': rumus_atau_none(s, I, 'D57')},
        'style1_b55': {'sel': 'SERTIFIKAT STYLE 1!B55', 'rumus': rumus_atau_none(s, 'SERTIFIKAT STYLE 1', 'B55')},
        'style2_b55': {'sel': 'SERTIFIKAT STYLE 2!B55', 'rumus': rumus_atau_none(s, 'SERTIFIKAT STYLE 2', 'B55')},
    }
    k_cetak = {
        'blok_mikro': kolom_cetak(s, 'SERTIFIKAT STYLE 2', 'U37'),
        'blok_mili': kolom_cetak(s, 'SERTIFIKAT STYLE 2', 'U42'),
    }
    koreksi_buntu = {
        'J34': {'sel': f'{P}!J34', 'rumus': rumus_atau_none(s, P, 'J34'), 'cache': s.nilai(P, 'J34')},
        'J47': {'sel': f'{P}!J47', 'rumus': rumus_atau_none(s, P, 'J47'), 'cache': s.nilai(P, 'J47')},
    }

    hasil = {
        'label': 'Conductivity Meter (sesi contoh master: 25 µS/cm, 1412 µS/cm, 111 mS/cm; 5 pengulangan)',
        'berkas': os.path.basename(path),
        'sha256_12': sha[:12],
        'titik': titik_out,
        'konstanta': konstanta,
        'lingkungan': lingkungan,
        'tanggal_terbit': tanggal,
        'k_cetak': k_cetak,
        'koreksi_sel_buntu': koreksi_buntu,
        'rekonstruksi': {
            'selisih_relatif_maks_terhadap_cache': maks_rel,
            'catatan': 'komponen -> uc -> veff -> k (t 0,975; floor(veff)) -> U -> max(U, CMC) dihitung ulang dari sel komponen',
        },
    }
    print(f'conductivity: ok, rekonstruksi {maks_rel:.2e}')
    return hasil


def main():
    if len(sys.argv) < 2:
        raise SystemExit(__doc__)
    sumber = sys.argv[1]
    os.makedirs(KELUAR, exist_ok=True)

    try:
        js = jangka_sorong(os.path.join(sumber, JS_BERKAS))
        cd = conductivity(os.path.join(sumber, CD_BERKAS))
    except D.SandiTidakAda as e:
        raise SystemExit(f'BERHENTI: variabel lingkungan {e} tidak ada.')

    kepala = {
        '_digenerate_oleh': 'docs/skrip/gen-fixture-paritas-jangka-sorong-conductivity.py',
        '_catatan': 'Disalin dari nilai-cache workbook master (alamat sel di tiap nilai). Tanpa nama/alamat pelanggan, '
                    'nomor sertifikat, nomor order, atau nama teknisi. Jangan disunting dengan tangan.',
    }
    with open(os.path.join(KELUAR, 'jangka-sorong.json'), 'w', encoding='utf-8', newline='\n') as f:
        json.dump({**kepala, 'workbook': js}, f, ensure_ascii=False, indent=2)
        f.write('\n')
    with open(os.path.join(KELUAR, 'conductivity-meter.json'), 'w', encoding='utf-8', newline='\n') as f:
        json.dump({**kepala, 'workbook': cd}, f, ensure_ascii=False, indent=2)
        f.write('\n')
    print('selesai:', KELUAR)


if __name__ == '__main__':
    main()

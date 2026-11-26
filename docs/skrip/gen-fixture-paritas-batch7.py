"""Bangun tests/Fixtures/paritas/{hydrometer,...}.json dari workbook master (batch paritas ke-7).

Bahan uji PARITAS aplikasi-lawan-Excel (`tests/Feature/Paritas/`): MASUKAN yang
diketik di workbook dan NILAI tiap tahap hitungan Excel, masing-masing dengan
alamat sel sumbernya (`SHEET!SEL`), disalin apa adanya dari nilai-cache workbook.
Keluarannya BUKAN keluaran PHP dan tidak boleh disunting dengan tangan.
Berdampingan dengan `gen-fixture-paritas-analitik-batch2.py`; berkas itu tidak
diubah, helper-nya sengaja disalin supaya generator ini berdiri sendiri.

## Aturan keras

- Sandi workbook HANYA dari variabel lingkungan `SIDIK_MASTER_SANDI`, dibaca ke
  memori. Tidak ada salinan terbuka-kunci di disk; sandi tidak dicetak, tidak
  ditulis ke fixture, dan tidak dimasukkan ke pesan galat (hanya tipe galat).
- Fixture TIDAK memuat nama/alamat pelanggan, nomor sertifikat, nomor order,
  maupun nama teknisi: skrip hanya membaca sel angka (dan satu sel satuan) yang
  disebut di bawah.
- Angka acuan = nilai-cache sel. Dua pengecualian, keduanya ditandai di fixture
  (`sumber`) dan dipakai HANYA untuk membuktikan penyebab satu selisih:
  (a) "skenario" = rumus sel dihitung ulang dengan satu masukan diganti nilai
  yang dipakai aplikasi; (b) rekonstruksi rantai dari sel dasar untuk memastikan
  pembacaan kami atas rumus benar.
- Sebelum menulis, skrip menghitung ulang rantai `NILAI U95%` dari sel
  komponennya (ui -> uc -> v_eff -> k -> U -> max(U; CMC)) dan membandingkannya ke
  nilai-cache; selisih relatif terbesar dicatat di fixture
  (`rekonstruksi.selisih_relatif_maks_terhadap_cache`). Di atas 1e-11 skrip
  berhenti: berarti pembacaannya (bukan workbook) yang salah. Pengecualian: yang
  memakai k (k, U, U cetak) dibatasi 1e-9 karena TINV Excel iteratif dan scipy
  `t.ppf` menyimpang ~1e-11 relatif; angkanya dicatat terpisah
  (`rekonstruksi.selisih_relatif_maks_k_terhadap_cache`). Rantai densitas
  Cuckow (massa -> densitas per ulangan) TIDAK dihitung ulang di sini; nilainya
  dibaca dari cache.

## Pakai

    py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-batch7.py <folder-Downloads>

`<folder-Downloads>` berisi `EXEL CALIBRATION SIDIK\\` dengan
`Hydrometer 0.600 - 0.650 gmL.xlsx` dan `Hydrometer 1.800 - 2.000 gmL.xlsx`.
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
# Batas rekonstruksi yang melibatkan k = TINV (lihat `blok_skala`); sama dengan TOL_K_REL di trait paritas.
TOL_REKONSTRUKSI_K = 1e-9

P, U, S = 'PERHITUNGAN', 'NILAI U95%', 'SERTIFIKAT'


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
    """uc, v_eff, k (TINV), U dari daftar (ui, ci, vi) — rumus sel `NILAI U95%`."""
    aa = [(ui * ci) ** 2 for ui, ci, _ in ui_ci_vi]
    uc = math.sqrt(sum(aa))
    veff = uc ** 4 / sum(a ** 2 / vi for a, (_, _, vi) in zip(aa, ui_ci_vi))
    k = tinv(0.05, veff)
    return {'uc': uc, 'veff': veff, 'k': k, 'u_hitung': uc * k}


# ---------------------------------------------------------------------------
# Hydrometer
# ---------------------------------------------------------------------------

# Kolom titik di PERHITUNGAN (titik 1..3 = skala 1..3).
KOLOM_TITIK = ['G', 'H', 'I']

# Sebelas komponen budget, urutan baris 63..73 (skala 1) di `NILAI U95%`. Nama =
# `sumber` komponen di `HydrometerCalculator::budget()`.
KOMPONEN = [
    'Hydrometer Indication',
    'Resolution of Hydrometer',
    'Liquid Temperature',
    'Air Temperature',
    'Density of Liquid Reference',
    'Density of Air',
    'Mass of Hydrometer in the air',
    'Mass of Hydrometer in Liquid Ref',
    'Stem Diameter',
    'Local Gravity',
    'Surface Tension of liquid reference',
]

# Baris pertama blok komponen tiap skala (offset 34 baris antar skala).
BARIS_KOMPONEN = [63, 97, 131]
# Baris agregat = baris komponen pertama + (11 komponen) .. : jumlah 74, uc 75,
# v_eff 76, k 77, U 78, CMC ketikan 79 (kolom L), U cetak 80 (kolom L), +34 per skala.
BARIS_AGREGAT = [74, 108, 142]
# Sel turunan koefisien sensitivitas per skala. Baris `r0` = "massa di udara";
# stem = r0+2, massa di cairan = r0+3. Skala 1: 55, skala 2: 90, skala 3: 124.
BARIS_TURUNAN = [55, 90, 124]

# Peta sel yang BEDA antar varian workbook (selebihnya sama persis).
VARIAN = {
    'ringan': {
        'berkas': 'EXEL CALIBRATION SIDIK/Hydrometer 0.600 - 0.650 gmL.xlsx',
        'konstanta': {'rho_udara': 'J78', 'm_air': 'J80', 'rho_air_titik1': 'J82', 'alpha': 'J83',
                      'f_temp': 'J84', 'f_press': 'J85', 'kappa': 'J86', 'err_densitas': 'J87',
                      'phi': 'J89', 'gravitasi': 'J90', 'densitas_sinker': 'J91',
                      'err_timbang': 'AF84', 'densitas_beban_standar': 'AF85',
                      'pid_yx': 'M74', 'pid_yl': 'M75', 'tekanan_acuan': 'AD29'},
        'densitas_ulang': [106, 107, 108], 'densitas_rata': 109, 'densitas_sd': 110,
    },
    'berat': {
        'berkas': 'EXEL CALIBRATION SIDIK/Hydrometer 1.800 - 2.000 gmL.xlsx',
        'konstanta': {'rho_udara': 'J80', 'm_air': 'J82', 'rho_air_titik1': 'J84', 'alpha': 'J85',
                      'f_temp': 'J86', 'f_press': 'J87', 'kappa': 'J88', 'err_densitas': 'J89',
                      'phi': 'J91', 'gravitasi': 'J92',
                      'err_timbang': 'AE86', 'densitas_beban_standar': 'AE87',
                      'pid_yx': 'M76', 'pid_yl': 'M77', 'tekanan_acuan': 'AC29'},
        'densitas_ulang': [101, 102, 103], 'densitas_rata': 104, 'densitas_sd': 105,
    },
}


def blok_skala(s, skala):
    """Satu blok `NILAI U95%` (satu skala = satu titik): 11 komponen + agregat, sel demi sel.

    Kolom komponen: F u mentah, G pembagi, H vi, I ui, J ci, K ui*ci, L (ui*ci)^2, M L^2/vi.
    Agregat (kolom K, kecuali CMC dan U cetak di kolom L).
    """
    r1 = BARIS_KOMPONEN[skala]
    komp = {}
    for j, nama in enumerate(KOMPONEN):
        r = r1 + j
        komp[nama] = {
            'u_mentah': s.sel(U, f'F{r}'), 'pembagi': s.sel(U, f'G{r}'), 'vi': s.sel(U, f'H{r}'),
            'ui': s.sel(U, f'I{r}'), 'ci': s.sel(U, f'J{r}'), 'uici': s.sel(U, f'K{r}'),
            'uici_kuadrat': s.sel(U, f'L{r}'), 'uici4_per_vi': s.sel(U, f'M{r}'),
        }
    a = BARIS_AGREGAT[skala]
    agregat = {
        'jumlah_uici': s.sel(U, f'K{a}'),
        'jumlah_uici_kuadrat': s.sel(U, f'L{a}'),
        'sigma_uici4_per_vi': s.sel(U, f'M{a}'),
        'uc': s.sel(U, f'K{a + 1}'),
        'veff': s.sel(U, f'K{a + 2}'),
        'k': s.sel(U, f'K{a + 3}'),
        'u_hitung': s.sel(U, f'K{a + 4}'),
        'cmc_ketikan': s.sel(U, f'L{a + 5}', wajib=False),
        'u_cetak': s.sel(U, f'L{a + 6}'),
    }

    # Koefisien sensitivitas dibaca di sel turunannya (kolom C/F/I baris r0..). Komponen
    # mengutip sel itu di kolom J; hanya satu kutipan yang meleset (stem skala 3).
    r0 = BARIS_TURUNAN[skala]
    turunan = {
        'Liquid Temperature': f'I{r0}', 'Air Temperature': f'I{r0 + 2}',
        'Mass of Hydrometer in the air': f'C{r0}', 'Mass of Hydrometer in Liquid Ref': f'C{r0 + 3}',
        'Stem Diameter': f'C{r0 + 2}', 'Local Gravity': f'F{r0}',
        'Surface Tension of liquid reference': f'F{r0 + 2}',
    }
    ci_turunan = {}
    for nama, sel in turunan.items():
        d = s.sel(U, sel)
        d['selisih_relatif_terhadap_ci_komponen'] = rel(komp[nama]['ci']['nilai'], d['nilai'])
        ci_turunan[nama] = d

    # Penyebut `(M_air - M_liq + E44)^-2` milik skala ini (kolom O) dan milik skala 1 (`O55`).
    # Aplikasi memakai `O55` untuk koefisien tegangan permukaan di semua skala. Master ringan
    # melakukan hal yang sama (F92/F126 ikut skala 1); master berat memakai penyebut skalanya
    # sendiri. Jadi dideteksi dari angkanya: kalau F skala ini berbanding lurus dengan O skala
    # ini terhadap skala 1, master memakai penyebut skala sendiri.
    # SKENARIO (bukan cache): koefisien master berpenyebut skala sendiri diganti penyebut skala 1
    # = F x O55 / O{skala}. Hanya untuk mengunci selisih B7-2; di master yang sudah memakai O55
    # nilainya sama dengan F.
    o_skala = s.sel(U, f'O{r0}')
    o_skala1 = s.sel(U, 'O55')
    tegangan = ci_turunan['Surface Tension of liquid reference']
    f_skala1 = s.sel(U, f'F{BARIS_TURUNAN[0] + 2}')['nilai']
    memakai_skala_sendiri = skala > 0 and rel(
        tegangan['nilai'] / f_skala1, o_skala['nilai'] / o_skala1['nilai']) < 1e-9
    tegangan['penyebut_skala'] = o_skala
    tegangan['penyebut_skala1'] = o_skala1
    tegangan['master_memakai_penyebut_skala_sendiri'] = bool(memakai_skala_sendiri)
    if memakai_skala_sendiri:
        tegangan['skenario_ci_penyebut_skala1'] = {
            'nilai': tegangan['nilai'] * o_skala1['nilai'] / o_skala['nilai'],
            'sumber': f'NILAI U95%!F{r0 + 2} x O55 / O{r0} (dihitung ulang; bukan nilai-cache)',
        }
    else:
        tegangan['skenario_ci_penyebut_skala1'] = {
            'nilai': tegangan['nilai'],
            'sumber': f'NILAI U95%!F{r0 + 2} (master sudah memakai O55; sama dengan nilai-cache)',
        }

    # Rekonstruksi komponen -> agregat; dibandingkan ke cache.
    rk = rantai([(komp[x]['ui']['nilai'], komp[x]['ci']['nilai'], komp[x]['vi']['nilai']) for x in KOMPONEN])
    typed = agregat['cmc_ketikan']['nilai'] or 0.0
    # Dua kelompok: aritmetika murni (uc, veff, ui) harus nyaris persis; yang memakai `k`
    # (k, U, U cetak) dibatasi presisi TINV Excel yang iteratif, bukan IEEE (scipy t.ppf
    # menyimpang ~1,2e-11 relatif dari TINV Excel pada df yang sama). Trait paritas
    # memakai TOL_K 1e-9 untuk alasan yang sama.
    selisih = max(
        rel(rk['uc'], agregat['uc']['nilai']), rel(rk['veff'], agregat['veff']['nilai']),
        # ui = F / G untuk kesebelas komponen (suhu: F = ragam, G = 2).
        max(rel(komp[x]['ui']['nilai'], komp[x]['u_mentah']['nilai'] / komp[x]['pembagi']['nilai'])
            for x in KOMPONEN if komp[x]['pembagi']['nilai'] and komp[x]['u_mentah']['nilai']),
    )
    selisih_k = max(
        rel(rk['k'], agregat['k']['nilai']), rel(rk['u_hitung'], agregat['u_hitung']['nilai']),
        rel(max(rk['u_hitung'], typed), agregat['u_cetak']['nilai']),
    )
    return komp, agregat, ci_turunan, rk, (selisih, selisih_k)


def skenario_stem(komp, agregat, ci_stem, sumber):
    """Rantai skala dihitung ulang dengan ci Stem Diameter diganti `ci_stem`. Hanya untuk
    membuktikan penyebab selisih B7-3 (rujukan `J139 = C57`); BUKAN nilai-cache."""
    daftar = []
    for x in KOMPONEN:
        ci = ci_stem if x == 'Stem Diameter' else komp[x]['ci']['nilai']
        daftar.append((komp[x]['ui']['nilai'], ci, komp[x]['vi']['nilai']))
    r = rantai(daftar)
    r['u_cetak'] = max(r['u_hitung'], agregat['cmc_ketikan']['nilai'] or 0.0)
    r['ci_stem_diganti'] = ci_stem
    r['sumber'] = sumber
    return r


def hydrometer_varian(sumber_dir, nama):
    v = VARIAN[nama]
    sha, wf, wv = buka(os.path.join(sumber_dir, v['berkas']))
    s = Sumber(wf, wv)
    c = v['konstanta']
    maks_selisih = 0.0
    maks_selisih_k = 0.0

    masukan = {
        'resolusi': s.sel(P, 'E9'),
        'suhu_acuan_faktor': s.sel(P, 'E10'),
        'beban_tambahan_Sl': s.sel(P, 'E24'),
        'massa_udara_Ma': s.sel(P, 'E25'),
        'tegangan_permukaan': s.sel(P, 'E26'),
        'satuan_tegangan': s.teks(P, 'F26'),
        'suhu_acuan_alat_tr': s.sel(P, 'E27'),
        'diameter_stem': s.deret(P, ['N26', 'O26', 'P26']),
        'suhu_awal': s.sel(P, 'E15'), 'suhu_akhir': s.sel(P, 'F15'),
        'kelembaban_awal': s.sel(P, 'E16'), 'kelembaban_akhir': s.sel(P, 'F16'),
        'tekanan_awal': s.sel(P, 'E18'), 'tekanan_akhir': s.sel(P, 'F18'),
    }

    praolah = {
        'suhu_ruang': s.sel(P, 'G15'),
        'rho_udara': s.sel(P, c['rho_udara']),
        'f_press': s.sel(P, c['f_press']),
        'f_temp': s.sel(P, c['f_temp']),
        'm_air': s.sel(P, c['m_air']),
        'rho_air_titik1': s.sel(P, c['rho_air_titik1']),
        'pid_yx': s.sel(P, c['pid_yx']),
        'pid_yl': s.sel(P, c['pid_yl']),
        'tegangan_hydrometer': s.sel(P, 'G26'),
        'diameter_rata': s.sel(P, 'Q26'),
        'diameter_N27': s.sel(P, 'N27'),
        'diameter_P27': s.sel(P, 'P27'),
        'suhu_maks_titik': s.sel(P, 'L52'), 'suhu_min_titik': s.sel(P, 'M52'),
        'st_maks': s.sel(P, 'L53'), 'st_min': s.sel(P, 'M53'),
        'rho_air_maks': s.sel(P, 'L59'),
        'u_massa_aquadest_E39': s.sel(U, 'E39'),
        'penyebut_e44': s.sel(U, 'E44'),
        'penyebut_o55': s.sel(U, 'O55'),
    }

    konstanta = {
        'alpha': s.sel(P, c['alpha']), 'kappa': s.sel(P, c['kappa']),
        'err_densitas': s.sel(P, c['err_densitas']), 'phi': s.sel(P, c['phi']),
        'gravitasi_cm_s2': s.sel(P, c['gravitasi']),
        'err_timbang': s.sel(P, c['err_timbang']),
        'densitas_beban_standar': s.sel(P, c['densitas_beban_standar']),
        'tekanan_acuan_hpa': s.sel(P, c['tekanan_acuan']),
    }
    if 'densitas_sinker' in c:
        konstanta['densitas_sinker'] = s.sel(P, c['densitas_sinker'])

    titik = []
    for i, kol in enumerate(KOLOM_TITIK):
        komp, agregat, ci_turunan, rk, (selisih, selisih_k) = blok_skala(s, i)
        maks_selisih = max(maks_selisih, selisih)
        maks_selisih_k = max(maks_selisih_k, selisih_k)
        item = {
            'titik_ke': i + 1,
            'titik_ukur': s.sel(P, f'{kol}33'),
            'massa': s.deret(P, [f'{kol}{r}' for r in (34, 35, 36)]),
            'suhu': s.deret(P, [f'{kol}{r}' for r in (49, 50, 51)]),
            'massa_terkoreksi_per_ulangan': s.deret(P, [f'{kol}{r}' for r in (41, 42, 43)]),
            'massa_terkoreksi_rata': s.sel(P, f'{kol}44'),
            'suhu_rata': s.sel(P, f'{kol}52'),
            'tegangan_permukaan_titik': s.sel(P, f'{kol}53'),
            'rho_air_titik': s.sel(P, f'{kol}59'),
            'densitas_per_ulangan': s.deret(P, [f'{kol}{r}' for r in v['densitas_ulang']]),
            'densitas_rata': s.sel(P, f"{kol}{v['densitas_rata']}"),
            'simpangan_baku': s.sel(P, f"{kol}{v['densitas_sd']}"),
            'komponen': komp,
            'agregat': agregat,
            'ci_turunan': ci_turunan,
        }
        if i == 2:
            # J139 = C57 (sel skala 1) alih-alih C126: bukti penyebab tunggal B7-3.
            item['skenario_ci_stem_C126'] = skenario_stem(
                komp, agregat, ci_turunan['Stem Diameter']['nilai'],
                'rantai NILAI U95% skala 3 dihitung ulang dengan ci Stem Diameter = NILAI U95%!C126',
            )
        titik.append(item)

    cetak_sert = []
    for i in range(3):
        r = 17 + i
        cetak_sert.append({
            'nominal': cetak(s, S, f'E{r}'), 'aktual': cetak(s, S, f'J{r}'),
            'koreksi': cetak(s, S, f'O{r}'), 'u95': cetak(s, S, f'S{r}'),
        })

    return {
        'berkas': os.path.basename(v['berkas']),
        'sha256_12': sha[:12],
        'rekonstruksi': {
            'selisih_relatif_maks_terhadap_cache': maks_selisih,
            'selisih_relatif_maks_k_terhadap_cache': maks_selisih_k,
            'catatan_k': 'k, U, dan U cetak memakai TINV; scipy t.ppf menyimpang ~1e-11 dari TINV Excel (iteratif).',
        },
        'masukan': masukan,
        'praolah': praolah,
        'konstanta_workbook': konstanta,
        'titik': titik,
        'sertifikat': {
            'titik': cetak_sert,
            'k': cetak(s, S, 'U23'),
            'resolusi': cetak(s, S, 'I10'),
        },
        'maks_selisih': maks_selisih,
        'maks_selisih_k': maks_selisih_k,
    }


def hydrometer(sumber_dir):
    hasil = {}
    for nama in VARIAN:
        h = hydrometer_varian(sumber_dir, nama)
        maks = h.pop('maks_selisih')
        maks_k = h.pop('maks_selisih_k')
        if maks > TOL_REKONSTRUKSI:
            raise SystemExit(f'BERHENTI: rekonstruksi hydrometer/{nama} menyimpang {maks:.2e} > {TOL_REKONSTRUKSI:.0e}.')
        if maks_k > TOL_REKONSTRUKSI_K:
            raise SystemExit(f'BERHENTI: rekonstruksi k hydrometer/{nama} menyimpang {maks_k:.2e} > {TOL_REKONSTRUKSI_K:.0e}.')
        print(f'hydrometer/{nama}: ok, rekonstruksi {maks:.2e}, k {maks_k:.2e}')
        hasil[nama] = h
    return hasil


def tulis(nama, kunci, isi):
    kepala = {
        '_digenerate_oleh': 'docs/skrip/gen-fixture-paritas-batch7.py',
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
        hyd = hydrometer(sumber)
    except SandiTidakAda as e:
        raise SystemExit(f'BERHENTI: variabel lingkungan {e} tidak ada.')

    tulis('hydrometer.json', 'workbook', hyd)
    print('selesai:', KELUAR)


if __name__ == '__main__':
    main()

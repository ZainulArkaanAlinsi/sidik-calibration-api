"""Bangun tests/Fixtures/paritas/<alat>.json batch ke-6 dari workbook master.

Bahan uji PARITAS aplikasi-lawan-Excel batch alat ke-6 (`tests/Feature/Paritas/`):
MASUKAN yang diketik di workbook dan NILAI tiap tahap hitungan Excel, masing-masing
dengan alamat sel sumbernya (`SHEET!SEL`), disalin apa adanya dari nilai-cache
workbook. Keluarannya BUKAN keluaran PHP dan tidak boleh disunting dengan tangan.
Berdampingan dengan `gen-fixture-paritas-batch5.py` dan generator batch sebelumnya;
berkas itu tidak diubah, helper-nya sengaja disalin supaya tiap generator berdiri sendiri.

## Aturan keras

- Sandi workbook HANYA dari variabel lingkungan `SIDIK_MASTER_SANDI`, dibaca ke
  memori. Tidak ada salinan terbuka-kunci di disk; sandi tidak dicetak, tidak
  ditulis ke fixture, dan tidak dimasukkan ke pesan galat (hanya tipe galat).
- Fixture TIDAK memuat nama/alamat pelanggan, nomor seri alat, nomor sertifikat,
  nomor order, maupun nama teknisi: skrip hanya membaca sel angka/tanggal yang
  disebut di bawah.
- Angka acuan = nilai-cache sel. Pengecualian (rekonstruksi rantai dari sel dasar,
  untuk memastikan pembacaan kami atas rumus benar) ditandai di fixture (`rekonstruksi`).
- Sebelum menulis, skrip menghitung ulang rantai `PERHITUNGAN U95%` dari sel dasarnya
  (komponen -> uc -> v_eff -> k -> U) dan membandingkannya ke nilai-cache; selisih
  relatif terbesar dicatat di fixture (`rekonstruksi.*`). Di atas batas skrip
  berhenti: berarti pembacaannya (bukan workbook) yang salah.

## Pakai

    py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-batch6.py <folder-Downloads> [alat ...]

`<folder-Downloads>` berisi `EXEL CALIBRATION SIDIK\\` dengan workbook master tiap alat
(lihat `BERKAS`). Tanpa nama alat = semua alat yang terdaftar di `GENERATOR`.
Dependensi: msoffcrypto-tool, openpyxl, scipy.
"""
import datetime
import hashlib
import io
import json
import math
import os
import re
import statistics
import sys
import warnings
from decimal import ROUND_HALF_UP, Decimal

warnings.filterwarnings('ignore')

AKAR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
KELUAR = os.path.join(AKAR, 'tests', 'Fixtures', 'paritas')

TOL_REKONSTRUKSI = 1e-11
# `TINV` Excel mendekati kuantil t; selisihnya terhadap kuantil eksak ~1e-11 relatif di derajat
# bebas ratusan ribu, dan menular ke U = k*uc. Sama dengan TOL_K_REL di MenegakkanParitas.
TOL_REKONSTRUKSI_K = 1e-9

BERKAS = {
    'height_gauge': 'EXEL CALIBRATION SIDIK/Master olda Height Gauge 600 mm 2026.xlsm',
}

I, P, U, DB, S, STD = 'INPUT DATA', 'PERHITUNGAN', 'PERHITUNGAN U95%', 'DATABASE', 'SERTIFIKAT', 'Std_CaliperCek'


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
            raise ValueError(f'{sheet}!{sel} bukan angka: {type(self.nilai(sheet, sel)).__name__}')
        return v

    def sel(self, sheet, sel, wajib=True):
        return {'sel': f'{sheet}!{sel}', 'nilai': self.n(sheet, sel, wajib)}

    def teks(self, sheet, sel):
        v = self.nilai(sheet, sel)
        if not isinstance(v, str):
            raise ValueError(f'{sheet}!{sel} bukan teks: {type(v).__name__}')
        return {'sel': f'{sheet}!{sel}', 'nilai': v}

    def tanggal(self, sheet, sel):
        """Sel tanggal/waktu -> ISO 8601 (pecahan detik dipertahankan)."""
        v = self.nilai(sheet, sel)
        if not isinstance(v, (datetime.datetime, datetime.date)):
            raise ValueError(f'{sheet}!{sel} bukan tanggal: {type(v).__name__}')
        return {'sel': f'{sheet}!{sel}', 'nilai': v.isoformat(), '_dt': v}

    def deret(self, sheet, sels):
        return {'sel': [f'{sheet}!{s}' for s in sels], 'nilai': [self.n(sheet, s) for s in sels]}

    def format(self, sheet, sel):
        return self.wf[sheet][sel].number_format

    def rumus(self, sheet, sel):
        v = self.wf[sheet][sel].value
        return v if isinstance(v, str) else None

    def baris_tersembunyi(self, sheet, baris):
        """Baris yang disembunyikan di lembar (tidak tercetak di sertifikat)."""
        return bool(self.wf[sheet].row_dimensions[baris].hidden)


def teks_excel(nilai, fmt):
    """Teks yang ditampilkan Excel untuk `nilai` berformat `fmt` (0.000 / 0 / ...).

    Dibulatkan dulu ke 15 angka penting (presisi tampilan Excel), baru ke
    desimal format, setengah menjauhi nol. Tanda minus dipertahankan walau
    hasilnya nol (perilaku Excel). Ini EMULASI, bukan teks yang dibaca dari
    render Excel. Format `General` sengaja DITOLAK: teks cetaknya bergantung pada
    lebar kolom dan tidak bisa ditiru dari nilai-cache.
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


def galat_bila_lebih(nama, nilai, batas):
    if nilai > batas:
        raise SystemExit(f'BERHENTI: rekonstruksi {nama} meleset {nilai:.3e} > {batas:.0e} — pembacaan skrip yang salah.')
    return nilai


def stdev(xs):
    return statistics.stdev(xs)


# ----------------------------------------------------------------------------
# Height Gauge (mm) — sepuluh titik, kapasitas 600 mm, resolusi 0,01 mm
# ----------------------------------------------------------------------------
# Urutan = baris 5..13 `PERHITUNGAN U95%`. Tanpa baris CMC: AA19 master kosong
# (Height Gauge di luar lampiran akreditasi), jadi U cetak = U hitung / T23.
KOMP_HG = [
    'pengulangan', 'resolusi_uut', 'ketidakpastian_standar', 'suhu_ruang', 'koefisien_muai',
    'drift_standar', 'geometri', 'meja_granit', 'selisih_suhu',
]
KOL_HG = dict(u='K', pembagi='N', vi='Q', ui='T', ci='V', uici='X', kuadrat='AA', pangkat4='AC')
N_TITIK_HG = 10
BARIS_INPUT_HG = 43        # `INPUT DATA!C43`, tiap titik 3 baris
BARIS_PERH_HG = 35         # `PERHITUNGAN!B35`, tiap titik 3 baris
KOL_EVAL = ['C', 'D', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M']   # kolom E sengaja kosong di master
KOL_PARAL = ['C', 'D', 'F']


def height_gauge(path):
    sha, wf, wv = buka(path)
    s = Sumber(wf, wv)

    # ---- INPUT DATA: hanya sel angka (tanpa identitas pelanggan, nomor seri, nomor, teknisi) ----
    titik_in = []
    for i in range(N_TITIK_HG):
        r = BARIS_INPUT_HG + 3 * i
        titik_in.append({
            'titik_ke': i + 1,
            'nominal_mm': s.sel(I, f'C{r}'),
            'pembacaan_mm': s.deret(I, [f'{c}{r}' for c in 'FGH']),
        })
    masukan = {
        'rentang_ukur_mm': s.sel(I, 'E14'),
        'kapasitas_mm': s.sel(I, 'E15'),
        'resolusi_mm': s.sel(I, 'E16'),
        'kode_satuan': s.sel(I, 'G14'),
        'satuan': s.teks(I, 'Y13'),
        'suhu_ruang_awal_c': s.sel(I, 'E21'),
        'suhu_ruang_akhir_c': s.sel(I, 'F21'),
        'suhu_ruang_rata_c': s.sel(I, 'H21'),
        'paralelisme_mm': s.deret(I, [f'{c}31' for c in KOL_PARAL]),
        'evaluasi_mm': s.deret(I, [f'{c}38' for c in KOL_EVAL]),
        'titik': titik_in,
    }

    # ---- PERHITUNGAN: per titik ----
    titik = []
    rk_titik = []
    for i in range(N_TITIK_HG):
        r = BARIS_PERH_HG + 3 * i
        pembacaan = s.deret(P, [f'{c}{r}' for c in 'IJK'])
        nominal = s.sel(P, f'C{r}')
        terkoreksi = s.sel(P, f'F{r}')
        total = s.sel(P, f'H{r}')
        standar = s.sel(P, f'Y{r}')
        rata = s.sel(P, f'L{r}')
        sd = s.sel(P, f'Z{r}')
        koreksi = s.sel(P, f'AA{r}')
        masuk = titik_in[i]['pembacaan_mm']['nilai']
        rk = {
            'pembacaan_x_T23': max(rel(a, b) for a, b in zip(pembacaan['nilai'], masuk)),
            'rata': rel(statistics.fmean(pembacaan['nilai']), rata['nilai']),
            'sd': rel(stdev(pembacaan['nilai']), sd['nilai']) if sd['nilai'] != 0 else abs(stdev(pembacaan['nilai'])),
            'koreksi': rel(standar['nilai'] - rata['nilai'], koreksi['nilai']),
            'total': rel(terkoreksi['nilai'], total['nilai']),
            'nominal_dari_input': rel(nominal['nilai'], titik_in[i]['nominal_mm']['nilai']),
        }
        butir = {
            'titik_ke': i + 1,
            'jumlah_nominal': s.sel(P, f'B{r}'),
            'nominal_mm': nominal,
            'standar_terkoreksi_sertifikat_mm': terkoreksi,
            'total_nominal_mm': total,
            'pembacaan_mm': pembacaan,
            'rata_rata_mm': rata,
            'standar_terkoreksi_mm': standar,
            'simpangan_baku_mm': sd,
            'koreksi_mm': koreksi,
            # Apakah ada sel masukan suku termal (M..T) yang terisi di baris titik ini.
            'suku_termal_terisi': {
                'sel': f'{P}!M{r}:T{r}',
                'nilai': any(s.wf[P][f'{c}{r}'].value is not None for c in 'MNOPQRST'),
            },
        }
        if i == 0:
            # Suku termal hanya ada di BARIS PERTAMA master (M35:T35); baris 38.. memakai Y = H.
            suku = {n: s.sel(P, f'{n}{r}') for n in ['M', 'N', 'O', 'P', 'Q', 'R', 'S', 'T']}
            lain = {n: s.n(P, f'{n}{r}', wajib=False) or 0.0 for n in ['U', 'V', 'W', 'X']}
            butir['suku_termal'] = {
                'suhu_standar_c_M35': suku['M'],
                'suhu_uut_c_N35': suku['N'],
                'theta_c_O35': suku['O'],
                'alpha_standar_per_c_P35': suku['P'],
                'alpha_uut_per_c_Q35': suku['Q'],
                'delta_alpha_per_c_R35': suku['R'],
                'alpha_rata_per_c_S35': suku['S'],
                'delta_theta_c_T35': suku['T'],
                'koreksi_lain_UVWX': lain,
            }
            y_rekon = total['nilai'] + total['nilai'] * (
                suku['S']['nilai'] * suku['T']['nilai'] + suku['O']['nilai'] * suku['R']['nilai']
            ) - sum(lain.values())
            rk['standar_Y35_dari_H'] = rel(y_rekon, standar['nilai'])
            rk['alpha_rata_S35'] = rel((suku['P']['nilai'] + suku['Q']['nilai']) / 2, suku['S']['nilai'])
            rk['delta_alpha_R35'] = abs(abs(suku['Q']['nilai'] - suku['P']['nilai']) - suku['R']['nilai'])
            rk['delta_theta_T35'] = abs(abs(suku['M']['nilai'] - suku['N']['nilai']) - suku['T']['nilai'])
        else:
            rk['standar_Y_sama_H'] = rel(total['nilai'], standar['nilai'])
        titik.append(butir)
        rk_titik.append(rk)
    selisih_titik = max(max(rk.values()) for rk in rk_titik)

    # ---- sel dasar: konstanta, suhu, evaluasi, paralelisme, panjang sensitivitas ----
    evaluasi = s.deret(P, [f'{c}30' for c in KOL_EVAL])
    paralelisme = {
        'baca_mm': s.deret(P, [f'{c}23' for c in KOL_PARAL]),
        'maks_I23': s.sel(P, 'I23'),
        'min_I24': s.sel(P, 'I24'),
        'stdev_J24': s.sel(P, 'J24'),
        'vonis_K25': s.teks(P, 'K25'),
        # Teks rumus vonis: batas 0,01 mm hanya tertulis di dalam rumus ini, bukan di sel sendiri.
        'vonis_K25_rumus': {'sel': f'{P}!K25', 'nilai': s.rumus(P, 'K25')},
    }
    nominal_semua = [t['nominal_mm']['nilai'] for t in titik]
    sd_semua = [t['simpangan_baku_mm']['nilai'] for t in titik]
    suku0 = titik[0]['suku_termal']
    dasar = {
        'kapasitas_perhitungan_mm': s.sel(P, 'G8'),
        'resolusi_perhitungan_mm': s.sel(P, 'G9'),
        'suhu_awal_c': s.sel(P, 'E14'),
        'suhu_akhir_c': s.sel(P, 'F14'),
        'suhu_rata_c': s.sel(P, 'G14'),
        'alpha_per_c_R24': s.sel(P, 'R24'),
        'delta_alpha_per_c_S24': s.sel(P, 'S24'),
        'evaluasi_mm': evaluasi,
        'pengulangan_stdev_N30': s.sel(P, 'N30'),
        'panjang_sensitivitas_mm_C65': s.sel(P, 'C65'),
        'simpangan_baku_maks_mm_AB65': s.sel(P, 'AB65'),
        'paralelisme': paralelisme,
        'pembagi_satuan_T23': s.sel(DB, 'T23'),
        'caliper_checker_u95_um_J19': s.sel(STD, 'J19'),
        'caliper_checker_nominal_mm': s.deret(STD, [f'C{r}' for r in range(10, 20)]),
        'caliper_checker_terkoreksi_mm': s.deret(STD, [f'F{r}' for r in range(10, 20)]),
        'caliper_checker_koreksi_um': s.deret(STD, [f'I{r}' for r in range(10, 20)]),
        'caliper_checker_u95_um': s.deret(STD, [f'J{r}' for r in range(10, 20)]),
    }
    rata_suhu_o35 = statistics.fmean([suku0['suhu_standar_c_M35']['nilai'], suku0['suhu_uut_c_N35']['nilai']]) - 20.0
    rek_dasar = {
        'evaluasi_x_T23': max(rel(a, b * dasar['pembagi_satuan_T23']['nilai'])
                              for a, b in zip(evaluasi['nilai'], masukan['evaluasi_mm']['nilai'])),
        'pengulangan_N30': rel(stdev(evaluasi['nilai']), dasar['pengulangan_stdev_N30']['nilai']),
        'panjang_C65': abs(max(nominal_semua) - dasar['panjang_sensitivitas_mm_C65']['nilai']),
        'sd_maks_AB65': rel(max(sd_semua), dasar['simpangan_baku_maks_mm_AB65']['nilai']),
        'suhu_rata_G14': rel(statistics.fmean([dasar['suhu_awal_c']['nilai'], dasar['suhu_akhir_c']['nilai']]),
                             dasar['suhu_rata_c']['nilai']),
        'theta_O35': abs(rata_suhu_o35 - suku0['theta_c_O35']['nilai']),
        'delta_alpha_S24': abs(2 * dasar['alpha_per_c_R24']['nilai'] - dasar['delta_alpha_per_c_S24']['nilai']),
        'paralelisme_maks': abs(max(paralelisme['baca_mm']['nilai']) - paralelisme['maks_I23']['nilai']),
        'paralelisme_min': abs(min(paralelisme['baca_mm']['nilai']) - paralelisme['min_I24']['nilai']),
        'paralelisme_stdev_J24': rel(stdev([paralelisme['maks_I23']['nilai'], paralelisme['min_I24']['nilai']]),
                                     paralelisme['stdev_J24']['nilai']),
        'caliper_checker_terkoreksi': max(
            abs(n + c / 1000 - t) for n, c, t in zip(
                dasar['caliper_checker_nominal_mm']['nilai'], dasar['caliper_checker_koreksi_um']['nilai'],
                dasar['caliper_checker_terkoreksi_mm']['nilai'])),
        'caliper_checker_terkoreksi_dipakai': max(
            abs(t['standar_terkoreksi_sertifikat_mm']['nilai'] - dasar['caliper_checker_terkoreksi_mm']['nilai'][i])
            for i, t in enumerate(titik)),
    }
    for n in range(10):
        if abs(nominal_semua[n] - dasar['caliper_checker_nominal_mm']['nilai'][n]) > 0:
            raise SystemExit('BERHENTI: nominal titik tidak sama dengan nominal Caliper Checker.')

    # ---- drift: X11 = NOW() (cache), W13 = tanggal kalibrasi Caliper Checker ----
    x11 = s.tanggal(DB, 'X11')
    w13 = s.tanggal(DB, 'W13')
    umur_hari = (x11['_dt'] - w13['_dt']).total_seconds() / 86400
    k10 = s.sel(U, 'K10')
    c65 = dasar['panjang_sensitivitas_mm_C65']['nilai']
    k10_rekon = (0.02 + 0.00025 * c65) * 1 / 1000 * (umur_hari / 12)
    drift = {
        'x11_now_cache': {k: v for k, v in x11.items() if k != '_dt'},
        'w13_tanggal_kalibrasi_standar': {k: v for k, v in w13.items() if k != '_dt'},
        'umur_hari_dari_cache': umur_hari,
        'k10_cache': k10,
        'k10_rumus': s.rumus(U, 'K10'),
        'pembagi_master': 12,
        'catatan': 'X11 = NOW() sehingga cache tumbuh tiap workbook dihitung ulang; tes mengunci tanggal sesi ke cache ini. '
                   'Master membagi umur dengan 12 (K10), aplikasi dengan 365 (selisih B6-1 di docs/paritas-batch6.md).',
    }

    # ---- budget PERHITUNGAN U95% ----
    komp = {}
    for j, nama in enumerate(KOMP_HG):
        r = 5 + j
        komp[nama] = {
            'u_mentah': s.sel(U, f"{KOL_HG['u']}{r}"), 'pembagi': s.sel(U, f"{KOL_HG['pembagi']}{r}"),
            'vi': s.sel(U, f"{KOL_HG['vi']}{r}"), 'ui': s.sel(U, f"{KOL_HG['ui']}{r}"),
            'ci': s.sel(U, f"{KOL_HG['ci']}{r}"), 'uici': s.sel(U, f"{KOL_HG['uici']}{r}"),
            'uici_kuadrat': s.sel(U, f"{KOL_HG['kuadrat']}{r}"),
            'uici4_per_vi': s.sel(U, f"{KOL_HG['pangkat4']}{r}"),
        }
    agregat = {
        'jumlah_uici_kuadrat': s.sel(U, 'AA14'),
        'sigma_uici4_per_vi': s.sel(U, 'AC14'),
        'uc': s.sel(U, 'AA15'),
        'veff': s.sel(U, 'AA16'),
        'k': s.sel(U, 'AA17'),
        'u_hitung': s.sel(U, 'AA18'),
        'u_cetak': s.sel(U, 'AA20'),
    }
    rk_b = rantai([(komp[x]['ui']['nilai'], komp[x]['ci']['nilai'], komp[x]['vi']['nilai']) for x in KOMP_HG])
    selisih_uc_veff = max(rel(rk_b['uc'], agregat['uc']['nilai']), rel(rk_b['veff'], agregat['veff']['nilai']))
    selisih_k_u = max(
        rel(rk_b['k'], agregat['k']['nilai']), rel(rk_b['u_hitung'], agregat['u_hitung']['nilai']),
        rel(rk_b['u_hitung'] / dasar['pembagi_satuan_T23']['nilai'], agregat['u_cetak']['nilai']),
    )
    selisih_ui = max(rel(komp[x]['ui']['nilai'], komp[x]['u_mentah']['nilai'] / komp[x]['pembagi']['nilai'])
                     for x in KOMP_HG if komp[x]['pembagi']['nilai'])
    rek_lain = {
        'pengulangan_K5': rel(komp['pengulangan']['u_mentah']['nilai'], dasar['pengulangan_stdev_N30']['nilai']),
        'pengulangan_Q5': abs(komp['pengulangan']['vi']['nilai'] - (len(evaluasi['nilai']) - 1)),
        'resolusi_K6': rel(komp['resolusi_uut']['u_mentah']['nilai'], 0.5 * dasar['resolusi_perhitungan_mm']['nilai']),
        'standar_K7': rel(komp['ketidakpastian_standar']['u_mentah']['nilai'],
                          dasar['caliper_checker_u95_um_J19']['nilai'] / 1000),
        'suhu_K8': rel(komp['suhu_ruang']['u_mentah']['nilai'], suku0['theta_c_O35']['nilai']),
        'muai_K9': rel(komp['koefisien_muai']['u_mentah']['nilai'], dasar['delta_alpha_per_c_S24']['nilai']),
        'ci_suhu_V8': rel(komp['suhu_ruang']['ci']['nilai'], c65 * komp['koefisien_muai']['u_mentah']['nilai']),
        'ci_muai_V9': rel(komp['koefisien_muai']['ci']['nilai'], c65 * komp['suhu_ruang']['u_mentah']['nilai']),
        'ci_selisih_suhu_V13': rel(komp['selisih_suhu']['ci']['nilai'], komp['suhu_ruang']['ci']['nilai']),
        'selisih_suhu_K13': abs(komp['selisih_suhu']['u_mentah']['nilai'] - suku0['delta_theta_c_T35']['nilai']),
        'geometri_K11': rel(komp['geometri']['u_mentah']['nilai'], 0.5 / 1000),
        'meja_granit_K12': rel(komp['meja_granit']['u_mentah']['nilai'], 4.4 / 1000),
        'drift_K10_dibagi_12': rel(k10_rekon, k10['nilai']),
    }
    galat_bila_lebih('uc/veff', selisih_uc_veff, TOL_REKONSTRUKSI)
    galat_bila_lebih('k/U/cetak', selisih_k_u, TOL_REKONSTRUKSI_K)
    galat_bila_lebih('ui = u/pembagi', selisih_ui, TOL_REKONSTRUKSI)
    galat_bila_lebih('titik', selisih_titik, TOL_REKONSTRUKSI)
    for nama, v in rek_dasar.items():
        galat_bila_lebih(f'dasar.{nama}', v, TOL_REKONSTRUKSI)
    for nama, v in rek_lain.items():
        # drift: X11 dibaca pada resolusi datetime openpyxl (mikrodetik) -> sedikit longgar.
        galat_bila_lebih(nama, v, TOL_REKONSTRUKSI_K if nama.startswith('drift') else TOL_REKONSTRUKSI)

    # ---- angka CETAK (SERTIFIKAT) ----
    cetak_titik = []
    for i in range(N_TITIK_HG):
        r = 24 + i
        cetak_titik.append({
            'titik_ke': i + 1,
            # Baris tersembunyi di SERTIFIKAT tidak tercetak (master: hanya baris 24, 29, 33).
            'tersembunyi': {'sel': f'{S}!{r}', 'nilai': s.baris_tersembunyi(S, r)},
            'standar': cetak(s, S, f'D{r}'),
            'uut': cetak(s, S, f'I{r}'),
            'koreksi': cetak(s, S, f'L{r}'),
        })
    cetak_blok = {
        'satuan': s.teks(S, 'F11'),
        'titik': cetak_titik,
        'u95': cetak(s, S, 'J34'),
        # O35 berformat `General`: teksnya bergantung lebar kolom, tidak bisa ditiru dari
        # nilai-cache. Sengaja TANPA `teks` (lihat MENUNGGU di docs/paritas-batch6.md).
        'k': {**s.sel(S, 'O35'), 'format': s.format(S, 'O35'), 'teks': None},
        'vonis_paralelisme': s.teks(S, 'H38'),
    }

    hasil = {
        'sumber': {
            'berkas': os.path.basename(path),
            'sha256': sha,
            'dibuka': 'didekripsi di memori dengan sandi dari pemilik (SIDIK_MASTER_SANDI); tidak ada salinan terbuka-kunci',
        },
        'masukan': masukan,
        'excel': {
            'titik': titik,
            'dasar': dasar,
            'drift': drift,
            'budget': {'komponen': komp, 'agregat': agregat},
            'cetak': cetak_blok,
        },
        'rekonstruksi': {
            'selisih_relatif_maks_terhadap_cache': max(
                selisih_uc_veff, selisih_k_u, selisih_ui, selisih_titik, *rek_dasar.values(), *rek_lain.values(),
            ),
            'uc_veff': selisih_uc_veff,
            'k_u_cetak': selisih_k_u,
            'komponen_ui': selisih_ui,
            'titik': selisih_titik,
            'sel_dasar': {**rek_dasar, **rek_lain},
            'catatan': 'komponen -> uc -> veff -> k (TINV: t 0,975, floor(veff)) -> U dihitung ulang dari sel komponen; '
                       'rata/sd/koreksi/total titik dan sel dasar K5..K13, V8, V9, V13, O35, S24, C65, N30, I23..J24 dihitung ulang '
                       'dari sel hulunya. Drift K10 direkonstruksi dengan pembagi 12 (rumus master).',
        },
    }
    print(f'height_gauge: ok, {N_TITIK_HG} titik, {len(KOMP_HG)} komponen, '
          f'rekonstruksi maks {hasil["rekonstruksi"]["selisih_relatif_maks_terhadap_cache"]:.2e}')
    return hasil


GENERATOR = {
    'height_gauge': ('height-gauge.json', height_gauge),
}


def tulis(nama, kunci, isi):
    kepala = {
        '_digenerate_oleh': 'docs/skrip/gen-fixture-paritas-batch6.py',
        '_catatan': 'Disalin dari nilai-cache workbook master (alamat sel di tiap nilai). Tanpa nama/alamat pelanggan, '
                    'nomor seri, nomor sertifikat, nomor order, atau nama teknisi. Jangan disunting dengan tangan.',
    }
    with open(os.path.join(KELUAR, nama), 'w', encoding='utf-8', newline='\n') as f:
        json.dump({**kepala, kunci: isi}, f, ensure_ascii=False, indent=2)
        f.write('\n')


def main():
    if len(sys.argv) < 2:
        raise SystemExit(__doc__)
    sumber = sys.argv[1]
    pilihan = sys.argv[2:] or list(GENERATOR)
    os.makedirs(KELUAR, exist_ok=True)
    try:
        for kunci in pilihan:
            berkas, fungsi = GENERATOR[kunci]
            tulis(berkas, 'workbook', fungsi(os.path.join(sumber, BERKAS[kunci])))
    except SandiTidakAda as e:
        raise SystemExit(f'BERHENTI: variabel lingkungan {e} tidak ada.')
    print('selesai:', KELUAR)


if __name__ == '__main__':
    main()

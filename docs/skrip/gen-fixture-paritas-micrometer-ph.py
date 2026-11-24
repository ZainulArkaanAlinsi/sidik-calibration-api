"""Bangun tests/Fixtures/paritas/{micrometer,ph-meter}.json dari workbook master.

Bahan uji PARITAS aplikasi-lawan-Excel (`tests/Feature/Paritas/`): MASUKAN yang
diketik di workbook dan NILAI tiap tahap hitungan Excel, masing-masing dengan
alamat sel sumbernya (`SHEET!SEL`), disalin apa adanya dari nilai-cache workbook.
Keluarannya BUKAN keluaran PHP, dan tidak boleh disunting dengan tangan.

## Aturan keras

- Sandi workbook HANYA dari variabel lingkungan `SIDIK_MASTER_SANDI`, dibaca ke
  memori. Tidak ada salinan terbuka-kunci di disk; sandi tidak dicetak, tidak
  ditulis ke fixture, dan tidak dimasukkan ke pesan galat (hanya tipe galat).
- Fixture TIDAK memuat nama/alamat pelanggan, nomor sertifikat, nomor order,
  maupun nama teknisi: skrip hanya membaca sel angka yang disebut di bawah.
- Nilai-cache `PERHITUNGAN U95%!K10` (drift standar) memakai `DATABASE!X11 =NOW()`,
  jadi tumbuh tiap berkas dibuka. Acuan sah untuk U95 hanya (a) nilai-cache pada
  saat X11 tersimpan itu (`drift.x11_cache`), atau (b) hitung ulang dari rumus
  sel dengan tanggal tetap (`tanggal_tetap`, dihitung di sini dengan Python).
  Cache lain TIDAK boleh dipakai sebagai angka acuan.
- Sebelum menulis, skrip menghitung ulang rantai `PERHITUNGAN U95%` dari sel
  dasarnya dan membandingkannya ke nilai-cache; selisih relatif terbesar
  dicetak dan dicatat di fixture (`rekonstruksi.selisih_relatif_maks`). Di atas
  1e-11 skrip berhenti: berarti pembacaannya (bukan workbook) yang salah.

## Pakai

    py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-micrometer-ph.py <folder-Downloads>

`<folder-Downloads>` berisi `EXEL CALIBRATION SIDIK\\` (micrometer 0-25/25-50/
50-75, pH for trial, pH IMTE-WQ-129) dan `Master Olah Data_Micrometer
75-100mm.xlsm` di akarnya. Dependensi: msoffcrypto-tool, openpyxl, scipy.
"""
import hashlib
import io
import json
import math
import os
import re
import sys
from datetime import datetime
from decimal import ROUND_HALF_UP, Decimal

AKAR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
KELUAR = os.path.join(AKAR, 'tests', 'Fixtures', 'paritas')

TOL_REKONSTRUKSI = 1e-11
# Tanggal tetap (arbitrer, dipilih SEKALI, bukan agar tes lulus): setelah
# tanggal kalibrasi balok ukur standar `DATABASE!W13` (2024-01-24) di keempat
# workbook, jadi umur drift positif.
TANGGAL_TETAP = '2026-06-30'

MIKRO = {
    '025': ('EXEL CALIBRATION SIDIK/Master Olah Data_Micrometer 0-25mm.xlsm', '0-25 mm'),
    '2550': ('EXEL CALIBRATION SIDIK/Master Olah Data_Micrometer 25-50mm.xlsm', '25-50 mm'),
    '5075': ('EXEL CALIBRATION SIDIK/Master Olah Data_Micrometer 50-75mm.xlsm', '50-75 mm'),
    '75100': ('Master Olah Data_Micrometer 75-100mm.xlsm', '75-100 mm'),
}
PH = {
    'trial': ('EXEL CALIBRATION SIDIK/Master Olah Data_pH for trial.xlsm', 'pH for trial (termometer Yokogawa CA 150)'),
    'imte_wq_129': ('EXEL CALIBRATION SIDIK/pH meter_IMTE-WQ-129.xlsm', 'pH IMTE-WQ-129 (termometer Constant/SH 10)'),
}


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

    def deret(self, sheet, sels, buang_kosong=True):
        pasangan = [(s, angka(self.nilai(sheet, s))) for s in sels]
        if buang_kosong:
            pasangan = [(s, v) for s, v in pasangan if v is not None and v != 0]
        return {'sel': [f'{sheet}!{s}' for s, _ in pasangan], 'nilai': [v for _, v in pasangan]}

    def format(self, sheet, sel):
        return self.wf[sheet][sel].number_format

    def rumus(self, sheet, sel):
        v = self.wf[sheet][sel].value
        return v if isinstance(v, str) else None


def teks_excel(nilai, fmt):
    """Teks yang ditampilkan Excel untuk `nilai` berformat `fmt` (0.000 / 0 / General).

    Dibulatkan dulu ke 15 angka penting (presisi tampilan Excel), baru ke
    desimal format, setengah menjauhi nol. Tanda minus dipertahankan walau
    hasilnya nol (perilaku Excel).
    """
    if nilai is None:
        return None
    m = re.fullmatch(r'0(?:\.(0+))?', fmt)
    if not m:
        raise ValueError(f'format sel tak dikenal: {fmt}')
    desimal = len(m.group(1) or '')
    d = Decimal(f'{abs(nilai):.14e}')
    q = d.quantize(Decimal(1).scaleb(-desimal), rounding=ROUND_HALF_UP)
    teks = f'{q:.{desimal}f}'
    return ('-' if nilai < 0 else '') + teks


def catat_nol(kolom):
    """Tandai sel bernilai negatif yang membulat ke nol (`-0.000`).

    `teks` di fixture ini EMULASI Python atas tampilan Excel, bukan teks yang
    dibaca dari render Excel. Untuk nilai negatif yang membulat ke nol emulasi
    memberi `-0.000`, sedangkan repo mencatat PDF master micrometer tanpa minus
    (`MicrometerProfile::tandaNolDicetak`, G0-b P4). Dua bukti saling
    bertentangan -> tes membandingkan teks TANPA tanda untuk baris ini dan
    menyebutnya MENUNGGU di docs/paritas-micrometer-ph.md.
    """
    teks = kolom.get('teks')
    kolom['nol_bertanda_minus'] = bool(
        teks is not None and kolom['nilai'] is not None and kolom['nilai'] < 0
        and not any(c in '123456789' for c in teks)
    )
    return kolom


def polinomial(rumus, kol_suhu):
    """a, b, c dari `=(a*H42^2)-(b*H42)+c` (konstanta tertulis di rumus sel, bukan di sel data)."""
    m = re.fullmatch(r'=\(([\d.]+)\*' + kol_suhu + r'42\^2\)-\(([\d.]+)\*' + kol_suhu + r'42\)\+([\d.]+)', rumus or '')
    if not m:
        raise ValueError(f'rumus polinomial tak dikenal: {rumus}')
    return {'a': float(m.group(1)), 'b': -float(m.group(2)), 'c': float(m.group(3))}


def tinv(p, df):
    """`TINV(p, df)` Excel: dua-sisi, derajat bebas dipotong ke bilangan bulat."""
    from scipy import stats

    return float(stats.t.ppf(1 - p / 2, math.floor(df)))


def rel(a, b):
    return abs(a - b) / max(abs(b), 1e-300) if b != 0 else abs(a - b)


# ----------------------------------------------------------------------------
# Micrometer
# ----------------------------------------------------------------------------
BARIS_TITIK_INPUT = list(range(37, 68, 3))  # INPUT DATA: 11 titik, 3 baris per titik
BARIS_TITIK_HITUNG = list(range(31, 62, 3))  # PERHITUNGAN: idem
BARIS_SERTIFIKAT = list(range(18, 29))  # SERTIFIKAT: D18:D28 dst.

KOMPONEN = [  # (baris di PERHITUNGAN U95%, kunci `sumber` aplikasi)
    (5, 'pengulangan'), (6, 'resolusi_uut'), (7, 'ketidakpastian_standar'),
    (8, 'suhu_ruang'), (9, 'koefisien_muai'), (10, 'drift_standar'),
    (11, 'wringing'), (12, 'geometri'), (13, 'selisih_suhu'),
]


def mikro(kode, path, label):
    sha, wf, wv = buka(path)
    s = Sumber(wf, wv)
    I, P, U, DB, S, GB = 'INPUT DATA', 'PERHITUNGAN', 'PERHITUNGAN U95%', 'DATABASE', 'SERTIFIKAT', 'Standar_GB'

    satuan_idx = int(s.n(I, 'H14'))
    faktor = {1: s.n(DB, 'T23'), 2: s.n(DB, 'T24'), 3: s.n(DB, 'T25')}[satuan_idx]

    # Tabel nilai terkoreksi keping (`Nominal_mm = Standar_GB!$Q$10:$R$132`).
    tabel = {}
    for r in range(10, 133):
        a, b = angka(s.nilai(GB, f'Q{r}')), angka(s.nilai(GB, f'R{r}'))
        if a is not None and b is not None:
            tabel[round(a, 6)] = b

    # Sel Δα berbeda per pita (V22 / V23 / U22): ikuti rumus `K9`.
    rumus_k9 = s.rumus(U, 'K9')
    m = re.fullmatch(r'=PERHITUNGAN!([A-Z]+\d+)', rumus_k9 or '')
    if not m:
        raise ValueError(f'{kode}: K9 bukan acuan PERHITUNGAN!<sel>: {rumus_k9}')
    sel_alpha = m.group(1)

    # --- masukan (INPUT DATA), hanya sel angka ---
    titik_in = []
    for i, r in enumerate(BARIS_TITIK_INPUT, start=1):
        keping = s.deret(I, [f'{c}{r + d}' for d in range(3) for c in 'DE'])
        pembacaan = s.deret(I, [f'{c}{r + d}' for d in range(3) for c in 'GHIJK'])
        if not keping['nilai'] and not pembacaan['nilai']:
            continue
        titik_in.append({'titik_ke': i, 'keping_mm': keping, 'pembacaan_ketik': pembacaan})

    masukan = {
        'satuan': {**s.sel(I, 'H14'), 'teks': {1: 'mm', 2: 'inch', 3: 'µm'}[satuan_idx]},
        'kapasitas_ketik': s.sel(I, 'F15'),
        'resolusi_ketik': s.sel(I, 'F16'),
        'suhu_awal_c': s.sel(I, 'F21'),
        'suhu_akhir_c': s.sel(I, 'G21'),
        'pra_evaluasi_ketik': s.deret(I, ['D31', 'E31'] + [f'{c}31' for c in 'GHIJKLMN'], buang_kosong=False),
        'balok_pra_evaluasi_mm': s.deret(I, [f'{c}29' for c in 'IJKLMN']),
        'titik': titik_in,
    }

    # --- nilai Excel per tahap ---
    titik_x = []
    for i, (rh, ri) in enumerate(zip(BARIS_TITIK_HITUNG, BARIS_TITIK_INPUT), start=1):
        if angka(s.nilai(P, f'AB{rh}')) is None and angka(s.nilai(P, f'H{rh}')) is None:
            continue
        titik_x.append({
            'titik_ke': i,
            'total_nominal_mm': s.sel(P, f'H{rh}'),
            'rata_rata_mm': s.sel(P, f'N{rh}', wajib=False),
            'standar_terkoreksi_mm': s.sel(P, f'AB{rh}'),
            'koreksi_mm': s.sel(P, f'AD{rh}', wajib=False),
            'stdev_tampilan_mm': s.sel(P, f'AC{rh}', wajib=False),
        })

    komponen = {}
    for baris, sumber in KOMPONEN:
        komponen[sumber] = {
            'u_mentah': s.sel(U, f'K{baris}'),
            'pembagi': s.sel(U, f'N{baris}'),
            'vi': s.sel(U, f'Q{baris}'),
            'ui': s.sel(U, f'T{baris}'),
            'ci': s.sel(U, f'V{baris}'),
            'uici': s.sel(U, f'X{baris}'),
            'uici_kuadrat': s.sel(U, f'AA{baris}'),
            'uici4_per_vi': s.sel(U, f'AC{baris}'),
        }

    cetak_titik = []
    for i, r in enumerate(BARIS_SERTIFIKAT, start=1):
        if i > len(BARIS_TITIK_HITUNG) or angka(s.nilai(S, f'D{r}')) is None:
            continue
        def kol(huruf):
            fmt = s.format(S, f'{huruf}{r}')
            v = s.n(S, f'{huruf}{r}', wajib=False)
            return catat_nol({'sel': f'{S}!{huruf}{r}', 'nilai': v, 'format': fmt, 'teks': teks_excel(v, fmt)})
        cetak_titik.append({'titik_ke': i, 'standar': kol('D'), 'uut': kol('I'), 'koreksi': kol('L')})

    def kol_tunggal(sel):
        fmt = s.format(S, sel)
        v = s.n(S, sel)
        return {'sel': f'{S}!{sel}', 'nilai': v, 'format': fmt, 'teks': teks_excel(v, fmt)}

    excel = {
        'kapasitas_mm': s.sel(P, 'G8'),
        'resolusi_mm': s.sel(P, 'G9'),
        'pra_evaluasi_mm': s.deret(P, [f'{c}25' for c in 'CDEFGHIJKLM'], buang_kosong=False),
        'stdev_pra_evaluasi_mm': s.sel(P, 'N25'),
        'u_balok_pra_evaluasi_um': s.deret(P, [f'{c}23' for c in 'HIJKLM'], buang_kosong=False),
        'u_standar_balok_um': s.sel(P, 'O25'),
        'delta_suhu_c': s.sel(P, 'Q31'),
        'panjang_f61_mm': s.sel(P, 'F61'),
        'delta_alpha': s.sel(P, sel_alpha),
        'titik': titik_x,
        'komponen': komponen,
        'agregat': {
            'uc_kuadrat': s.sel(U, 'AA14'),
            'sigma_uici4_per_vi': s.sel(U, 'AC14'),
            'uc_um': s.sel(U, 'X15'),
            'veff': s.sel(U, 'AC15'),
            'k': s.sel(U, 'X16'),
            'u95_um': s.sel(U, 'X17'),
            'cmc_um': s.sel(U, 'X18', wajib=False),
            'u95_sertifikat_mm': s.sel(U, 'X19', wajib=False),
        },
        'cetak': {
            'titik': cetak_titik,
            'u95': kol_tunggal('J29'),
            'k': kol_tunggal('P30'),
        },
        'cmc_pita_um': {
            'sel': [f'{DB}!T{r}' for r in range(5, 9)],
            'nilai': [s.n(DB, f'T{r}') for r in range(5, 9)],
        },
    }

    # --- rekonstruksi rantai U95 dari sel dasar (bukti pembacaan benar) ---
    nilai_k = {sb: komponen[sb]['u_mentah']['nilai'] for _, sb in KOMPONEN}
    x11 = s.nilai(DB, 'X11')
    w13 = s.nilai(DB, 'W13')
    l21 = s.n(P, 'L21')

    f61 = excel['panjang_f61_mm']['nilai']
    delta_alpha = komponen['koefisien_muai']['u_mentah']['nilai']  # K9 = Δα
    delta_suhu = komponen['suhu_ruang']['u_mentah']['nilai']  # K8 = Q31
    # Panjang yang dipakai APLIKASI untuk ci termal: total nominal TERBESAR sesi
    # (MicrometerCalculator::budget, penyimpangan no. 3), bukan `F61` master
    # (keping pertama tumpukan titik terakhir). Sama persis di tiga pita
    # berkeping tunggal; beda di 0-25 mm (6,00016 lawan 25,00027).
    panjang_app = max(t['total_nominal_mm']['nilai'] for t in titik_x)

    def rantai(umur_hari, panjang):
        kk = dict(nilai_k)
        kk['drift_standar'] = (0.02 + 0.00025 * l21) * (umur_hari / 365)
        ui, ci, vi = {}, {}, {}
        for _, sb in KOMPONEN:
            ui[sb] = kk[sb] / komponen[sb]['pembagi']['nilai']
            ci[sb] = komponen[sb]['ci']['nilai']
            vi[sb] = komponen[sb]['vi']['nilai']
        # ci termal menurut rumus sel: V8 = K9*F61, V9 = F61*K8, V13 = V8
        ci['suhu_ruang'] = delta_alpha * panjang
        ci['koefisien_muai'] = panjang * delta_suhu
        ci['selisih_suhu'] = ci['suhu_ruang']
        aa = {sb: (ui[sb] * ci[sb]) ** 2 for sb in ui}
        uc2 = sum(aa.values())
        uc = math.sqrt(uc2)
        pembagi_veff = sum(aa[sb] ** 2 / vi[sb] for sb in aa)
        veff = uc ** 4 / pembagi_veff
        k = tinv(0.05, veff)
        return {
            'panjang_mm': panjang, 'k10_um': kk['drift_standar'],
            'ci_suhu_ruang': ci['suhu_ruang'], 'ci_koefisien_muai': ci['koefisien_muai'],
            'uc_um': uc, 'veff': veff, 'k': k, 'u95_um': k * uc,
        }

    umur_cache = (x11 - w13).total_seconds() / 86400
    rek = rantai(umur_cache, f61)
    selisih = max(
        rel(rek['k10_um'], komponen['drift_standar']['u_mentah']['nilai']),
        rel(rek['uc_um'], excel['agregat']['uc_um']['nilai']),
        rel(rek['veff'], excel['agregat']['veff']['nilai']),
        rel(rek['k'], excel['agregat']['k']['nilai']),
        rel(rek['u95_um'], excel['agregat']['u95_um']['nilai']),
    )
    if selisih > TOL_REKONSTRUKSI:
        raise SystemExit(f'{kode}: rekonstruksi menyimpang dari cache ({selisih:.3e} > {TOL_REKONSTRUKSI}); berhenti.')

    umur_tetap = (datetime.fromisoformat(TANGGAL_TETAP) - w13).total_seconds() / 86400
    tetap = rantai(umur_tetap, f61)
    cache_app = rantai(umur_cache, panjang_app)
    tetap_app = rantai(umur_tetap, panjang_app)

    cmc_um = excel['agregat']['cmc_um']['nilai']
    if cmc_um:
        for skenario in (tetap, tetap_app):
            u_sert_mm = max(skenario['u95_um'], cmc_um) / 1000
            skenario['u95_sertifikat_mm'] = u_sert_mm
            skenario['u95_cetak'] = teks_excel(u_sert_mm, excel['cetak']['u95']['format'])

    # --- tumpukan keping alternatif titik 3 pita 50-75 (konflik K-1, cetakan job) ---
    konflik = None
    if kode == '5075':
        alternatif = [50.0, 1.2, 2.5, 1.9]
        konflik = {
            'butir': 'K-1 (konflik-excel-kertas.md): titik ke-3 pita 50-75 mm, 51,0 (kertas 0522.C, master, aplikasi) lawan 55,6 (cetakan Excel job, bukan sel workbook master)',
            'status': 'menunggu keputusan Manajer Teknis',
            'master': {
                'keping_mm': [1.5, 6.0, 1.6, 40.0, 1.9],
                'total_nominal_mm': excel['titik'][2]['total_nominal_mm'],
                'sel_masukan': masukan['titik'][2]['keping_mm']['sel'],
            },
            'cetakan_job': {
                'keping_mm': alternatif,
                'total_nominal_mm': {
                    'sel': 'Σ VLOOKUP(keping, Standar_GB!Q10:R132)',
                    'nilai': sum(tabel[round(x, 6)] for x in alternatif),
                },
                'sumber_keping': 'peta\\konflik-excel-kertas.md K-1 butir 3 (teks PDF cetakan job, dibaca T1); tidak ada sel di workbook master',
            },
        }

    hasil = {
        'label': label,
        'berkas': os.path.basename(path),
        'sha256_12': sha[:12],
        'masukan': masukan,
        'excel': excel,
        'drift': {
            'x11_cache': {'sel': f'{DB}!X11', 'nilai': x11.isoformat(sep=' ')},
            'w13_kalibrasi_balok': {'sel': f'{DB}!W13', 'nilai': w13.isoformat(sep=' ')},
            'rumus': '(0.02+0.00025*PERHITUNGAN!L21)*((DATABASE!X11-DATABASE!W13)/365)  [PERHITUNGAN U95%!K10, µm]',
        },
        'tanggal_tetap': {
            'tanggal': TANGGAL_TETAP,
            'sumber': 'hitung ulang Python dari rumus sel PERHITUNGAN U95%!K10..X17 dengan DATABASE!X11 := tanggal ini; '
                      'komponen lain dari nilai-cache (tidak bergantung X11); TINV = t(0,975; floor(veff)) scipy',
            **tetap,
            'dengan_panjang_aplikasi': tetap_app,
        },
        'rekonstruksi': {
            'selisih_relatif_maks_terhadap_cache': selisih,
            'catatan': 'rantai K5..X17 dihitung ulang dari sel dasar pada X11 tersimpan, dibandingkan ke nilai-cache',
            'dengan_panjang_aplikasi_pada_x11_cache': {
                'sumber': 'rumus sel V8/V9/V13 dengan F61 := total nominal terbesar sesi (penyimpangan sengaja no. 3 aplikasi); '
                          'komponen lain dari cache',
                **cache_app,
            },
        },
    }
    if konflik:
        hasil['konflik_k1'] = konflik
    print(f'micrometer {kode}: ok, rekonstruksi {selisih:.2e}, U95 cache {excel["agregat"]["u95_um"]["nilai"]:.12f} um, '
          f'U95 pada {TANGGAL_TETAP} {tetap["u95_um"]:.12f} um')
    return hasil


# ----------------------------------------------------------------------------
# pH meter
# ----------------------------------------------------------------------------
# kolom (pembacaan, suhu) di INPUT DATA / PERHITUNGAN, blok baris budget, kolom cetak
TITIK_PH = [
    {'nama': 'pH 4', 'kol': ('G', 'H'), 'blok': 11, 'cetak': 21, 'standar_kol': 'G', 'db': 13, 'cmc': 'S5'},
    {'nama': 'pH 7', 'kol': ('I', 'J'), 'blok': 26, 'cetak': 22, 'standar_kol': 'I', 'db': 14, 'cmc': 'S6'},
    {'nama': 'pH 10', 'kol': ('K', 'L'), 'blok': 41, 'cetak': 23, 'standar_kol': 'K', 'db': 15, 'cmc': 'S7'},
]
KOMPONEN_PH = ['sertifikat_buffer', 'daya_baca', 'temperature', 'perbedaan_suhu', 'pengulangan']


def ph(kode, path, label):
    sha, wf, wv = buka(path)
    s = Sumber(wf, wv)
    I, P, U, DB, S = 'INPUT DATA', 'PERHITUNGAN', 'PERHITUNGAN U95%', 'DATABASE', 'SERTIFIKAT'

    titik = []
    max_rel = 0.0
    for t in TITIK_PH:
        kb, ks = t['kol']
        masuk = {
            'pembacaan_sesudah': s.deret(I, [f'{kb}{r}' for r in range(42, 47)], buang_kosong=False),
            'suhu_larutan_sesudah_c': s.deret(I, [f'{ks}{r}' for r in range(42, 47)], buang_kosong=False),
            'label_titik_lembar': s.sel(I, f'{kb}31'),
        }
        b0 = t['blok']
        komp = {}
        for j, nama in enumerate(KOMPONEN_PH):
            r = b0 + j
            komp[nama] = {
                'u_mentah': s.sel(U, f'M{r}'), 'pembagi': s.sel(U, f'P{r}'), 'vi': s.sel(U, f'R{r}'),
                'ui': s.sel(U, f'T{r}'), 'ci': s.sel(U, f'W{r}'), 'uici': s.sel(U, f'Y{r}'),
                'uici_kuadrat': s.sel(U, f'AB{r}'), 'uici4_per_vi': s.sel(U, f'AF{r}'),
            }
        a0 = b0 + 6  # baris AB: jumlah, uc, veff, k, U, CMC, final = b0+5 .. b0+11
        agregat = {
            'jumlah_uici_kuadrat': s.sel(U, f'AB{b0 + 5}'),
            'sigma_uici4_per_vi': s.sel(U, f'AF{b0 + 5}'),
            'uc': s.sel(U, f'AB{b0 + 6}'),
            'veff': s.sel(U, f'AB{b0 + 7}'),
            'k': s.sel(U, f'AB{b0 + 8}'),
            'u_hitung': s.sel(U, f'AB{b0 + 9}'),
            'cmc': s.sel(U, f'AB{b0 + 10}'),
            'u_cetak': s.sel(U, f'AB{b0 + 11}'),
        }

        # rekonstruksi: komponen -> uc -> veff -> k -> U -> max(U, CMC)
        aa = [(komp[n]['ui']['nilai'] * komp[n]['ci']['nilai']) ** 2 for n in KOMPONEN_PH]
        uc = math.sqrt(sum(aa))
        veff = uc ** 4 / sum(a ** 2 / komp[n]['vi']['nilai'] for a, n in zip(aa, KOMPONEN_PH))
        k = tinv(0.05, veff)
        u = uc * k
        ucetak = max(u, agregat['cmc']['nilai'])
        for a, b in [(uc, agregat['uc']['nilai']), (veff, agregat['veff']['nilai']), (k, agregat['k']['nilai']),
                     (u, agregat['u_hitung']['nilai']), (ucetak, agregat['u_cetak']['nilai'])]:
            max_rel = max(max_rel, rel(a, b))

        def kol(huruf):
            sel = f'{huruf}{t["cetak"]}'
            fmt = s.format(S, sel)
            v = s.n(S, sel)
            return catat_nol({'sel': f'{S}!{sel}', 'nilai': v, 'format': fmt, 'teks': teks_excel(v, fmt)})

        koef = polinomial(s.rumus(P, f'{t["standar_kol"]}35'), ks)

        titik.append({
            'nama': t['nama'],
            'masukan': masuk,
            'standar': {
                'suhu_larutan_rata_c': s.sel(P, f'{ks}42'),
                'nilai_standar': s.sel(P, f'{t["standar_kol"]}35'),
                'rata_rata_uut': s.sel(P, f'{kb}42'),
                'stdev_sampel': s.sel(P, f'{kb}44'),
                'koreksi_lembar_uut_minus_standar': s.sel(P, f'{kb}43'),
            },
            'polinomial_buffer': {'sel': f'{P}!{t["standar_kol"]}35', 'rumus': s.rumus(P, f'{t["standar_kol"]}35'), **koef},
            'u95_sertifikat_buffer': s.sel(DB, f'V{t["db"]}'),
            'cmc': s.sel(DB, t['cmc']),
            'komponen': komp,
            'agregat': agregat,
            'cetak': {'standar': kol('C'), 'uut': kol('J'), 'koreksi': kol('P'), 'u95': kol('S')},
        })

    if max_rel > TOL_REKONSTRUKSI:
        raise SystemExit(f'ph {kode}: rekonstruksi menyimpang dari cache ({max_rel:.3e}); berhenti.')

    # Skenario "UTemperature milik aplikasi": aplikasi menyimpan SATU konstanta
    # (Yokogawa, workbook `for trial`: AE4 = sqrt(0,36^2 + 0,03^2)) untuk semua
    # sesi. Komponen `temperature` lembar ini diganti dengan itu; sisanya tetap.
    u_temp_app = 0.36124783736376886  # PERHITUNGAN U95%!AE4 workbook `for trial` (diverifikasi di bawah)
    if kode == 'trial':
        assert abs(u_temp_app - s.n(U, 'AE4')) < 1e-15, 'AE4 workbook trial bukan 0,36124783736376886'
    for t in titik:
        komp = {n: dict(v) for n, v in t['komponen'].items()}
        ui_baru = u_temp_app / komp['temperature']['pembagi']['nilai']
        aa = []
        for n in KOMPONEN_PH:
            ui = ui_baru if n == 'temperature' else komp[n]['ui']['nilai']
            aa.append((ui * komp[n]['ci']['nilai']) ** 2)
        uc = math.sqrt(sum(aa))
        veff = uc ** 4 / sum(a ** 2 / komp[n]['vi']['nilai'] for a, n in zip(aa, KOMPONEN_PH))
        k = tinv(0.05, veff)
        t['dengan_utemperature_aplikasi'] = {
            'u_temperature': u_temp_app, 'ui_temperature': ui_baru, 'uc': uc, 'veff': veff, 'k': k,
            'u_hitung': uc * k, 'u_cetak': max(uc * k, t['agregat']['cmc']['nilai']),
            'sumber': 'rumus sel PERHITUNGAN U95%: komponen temperature (M13/T13) := AE4 workbook trial / pembagi; komponen lain dari cache',
        }

    fk = s.format(S, 'U24')
    hasil = {
        'label': label,
        'berkas': os.path.basename(path),
        'sha256_12': sha[:12],
        'masukan': {
            'resolusi_alat': s.sel(I, 'E16'),
            'suhu_ruang_c': {'awal': s.sel(I, 'E21'), 'akhir': s.sel(I, 'F21')},
            'u95_termometer_c': s.sel(DB, 'V16'),
            'u95_sensor_c': s.sel(DB, 'V17'),
            'u_temperature': s.sel(U, 'AE4'),
        },
        'titik': titik,
        'cetak_k': {'sel': f'{S}!U24', 'nilai': s.n(S, 'U24'), 'format': fk, 'teks': teks_excel(s.n(S, 'U24'), fk),
                    'catatan': 'Excel mencetak SATU k (milik blok pH 7, PERHITUNGAN U95%!AB34)'},
        'drift_waktu': {'x11_cache': {'sel': f'{DB}!X11', 'nilai': s.nilai(DB, 'X11').isoformat(sep=' ')},
                        'catatan': 'NOW() hanya memengaruhi status standar; tidak masuk budget pH'},
        'rekonstruksi': {'selisih_relatif_maks_terhadap_cache': max_rel,
                         'catatan': 'komponen -> uc -> veff -> k (t 0,975; floor(veff)) -> U -> max(U, CMC) dihitung ulang dari sel komponen'},
    }
    print(f'ph {kode}: ok, rekonstruksi {max_rel:.2e}')
    return hasil


def main():
    if len(sys.argv) < 2:
        raise SystemExit(__doc__)
    sumber = sys.argv[1]
    os.makedirs(KELUAR, exist_ok=True)

    try:
        mik = {k: mikro(k, os.path.join(sumber, p), lbl) for k, (p, lbl) in MIKRO.items()}
        pp = {k: ph(k, os.path.join(sumber, p), lbl) for k, (p, lbl) in PH.items()}
    except SandiTidakAda as e:
        raise SystemExit(f'BERHENTI: variabel lingkungan {e} tidak ada.')

    kepala = {
        '_digenerate_oleh': 'docs/skrip/gen-fixture-paritas-micrometer-ph.py',
        '_catatan': 'Disalin dari nilai-cache workbook master (alamat sel di tiap nilai). Tanpa nama/alamat pelanggan, '
                    'nomor sertifikat, nomor order, atau nama teknisi. Jangan disunting dengan tangan.',
    }
    with open(os.path.join(KELUAR, 'micrometer.json'), 'w', encoding='utf-8', newline='\n') as f:
        json.dump({**kepala, 'varian': mik}, f, ensure_ascii=False, indent=2)
        f.write('\n')
    with open(os.path.join(KELUAR, 'ph-meter.json'), 'w', encoding='utf-8', newline='\n') as f:
        json.dump({**kepala, 'workbook': pp}, f, ensure_ascii=False, indent=2)
        f.write('\n')
    print('selesai:', KELUAR)


if __name__ == '__main__':
    main()

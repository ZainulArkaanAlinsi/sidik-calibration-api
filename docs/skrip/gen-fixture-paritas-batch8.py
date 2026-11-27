"""Bangun tests/Fixtures/paritas/{utm,load-cell,sieve}.json dari workbook master (batch paritas ke-8).

Bahan uji PARITAS aplikasi-lawan-Excel (`tests/Feature/Paritas/`): MASUKAN yang
diketik di workbook dan NILAI tiap tahap hitungan Excel, masing-masing dengan
alamat sel sumbernya (`SHEET!SEL`), disalin apa adanya dari nilai-cache workbook.
Keluarannya BUKAN keluaran PHP dan tidak boleh disunting dengan tangan.
Berdampingan dengan `gen-fixture-paritas-batch6.py`/`-batch7.py`; berkas itu tidak
diubah, helper-nya sengaja disalin supaya generator ini berdiri sendiri.

## Aturan keras

- Sandi workbook HANYA dari variabel lingkungan `SIDIK_MASTER_SANDI`, dibaca ke
  memori. Tidak ada salinan terbuka-kunci di disk; sandi tidak dicetak, tidak
  ditulis ke fixture, dan tidak dimasukkan ke pesan galat (hanya tipe galat).
- Fixture TIDAK memuat nama/alamat pelanggan, nomor sertifikat, nomor order,
  maupun nama teknisi: skrip hanya membaca sel angka (dan beberapa sel teks
  teknis: satuan, arah beban, nama standar acuan, rumus sel) yang disebut di bawah.
- Angka acuan = nilai-cache sel. Satu-satunya angka yang BUKAN cache adalah hasil
  rekonstruksi rantai dari sel dasar (`rekonstruksi`), dipakai untuk memastikan
  pembacaan kami atas rumus benar.
- Sebelum menulis, skrip menghitung ulang tiap tahap dari sel hulunya (konversi
  satuan -> rata/sd/RSD/RRPE -> Y/Z/AA -> komponen budget -> uc -> v_eff -> k -> U ->
  max(U; CMC) -> angka sertifikat) dan membandingkannya ke nilai-cache. Selisih
  relatif (lantai penyebut 0,1 supaya derau 1e-16 di sel simpangan baku tidak
  dihitung) di atas 1e-11 membuat skrip berhenti: berarti pembacaannya (bukan
  workbook) yang salah. Yang memakai k (k, U, U cetak) dibatasi 1e-9 karena TINV
  Excel iteratif dan scipy `t.ppf` menyimpang ~1e-11 relatif.

## Pakai

    py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-batch8.py <folder-Downloads> [utm] [load_cell] [sieve]

`<folder-Downloads>` berisi `EXEL CALIBRATION SIDIK\\` dengan
`Master Olah Data_Gaya_UTM.xlsm`, `Master Olah Data_Gaya_Load Cell.xlsm`, dan
`Master Olah Data_Sieve Mesh.xlsm`. Target `sieve` hanya membaca sel angka dan teks
teknis (tidak membaca nama/alamat pelanggan di `INPUT DATA!Q10:Q12`, nomor sertifikat, atau nomor order).
Dependensi: msoffcrypto-tool, openpyxl, scipy.
"""
import hashlib
import io
import json
import math
import os
import statistics
import sys
import warnings

warnings.filterwarnings('ignore')

AKAR = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
KELUAR = os.path.join(AKAR, 'tests', 'Fixtures', 'paritas')

TOL_REKONSTRUKSI = 1e-11
# Sama dengan TOL_K_REL di MenegakkanParitas: TINV Excel iteratif, scipy eksak.
TOL_REKONSTRUKSI_K = 1e-9

BERKAS = {
    'utm': 'EXEL CALIBRATION SIDIK/Master Olah Data_Gaya_UTM.xlsm',
    'load_cell': 'EXEL CALIBRATION SIDIK/Master Olah Data_Gaya_Load Cell.xlsm',
    'sieve': 'EXEL CALIBRATION SIDIK/Master Olah Data_Sieve Mesh.xlsm',
}
KELUARAN = {'utm': 'utm.json', 'load_cell': 'load-cell.json', 'sieve': 'sieve.json'}

I, FC, U, DB, S, MIS = 'INPUT DATA', 'PERHITUNGAN FC', 'PERHITUNGAN U95%', 'DATABASE', 'SERTIFIKAT', 'Misalignment'

# Kolom 12 pembacaan: 4 posisi x 3 ulangan. Kolom E, O sengaja kosong di master.
KOL_BACAAN = ['C', 'D', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N', 'P']
BARIS_INPUT_PERTAMA = 37    # `INPUT DATA!B37`: baris titik pertama (nol)
BARIS_FC_PERTAMA = 30       # `PERHITUNGAN FC!B30`
BARIS_SERT_PERTAMA = 21     # `SERTIFIKAT!E21`
BARIS_FC_ZERO_ERROR = 44
# Urutan = baris 9..16 `PERHITUNGAN U95%` = urutan `GayaCalculator::komponenBudget()`.
KOMPONEN = [
    'sertifikat_kalibrator', 'daya_baca_uut', 'daya_baca_standar', 'temperature',
    'drift_standar', 'pengulangan', 'zero_error', 'misalignment',
]
BARIS_KOMPONEN = 9
FAKTOR_SATUAN_SEL = {'kN': 'S20', 'N': 'S21', 'lbf': 'S22', 'kgf': 'S23', 'tnf': 'S24'}
# UTM mencetak Z (sesudah koreksi termal) di semua satuan; Load Cell mencetak Y di cabang kN.
KOLOM_NILAI_STANDAR = {'utm': 'Z', 'load_cell': 'Y'}


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

    def sel(self, sheet, sel):
        return {'sel': f'{sheet}!{sel}', 'nilai': self.n(sheet, sel)}

    def teks(self, sheet, sel):
        v = self.nilai(sheet, sel)
        if not isinstance(v, str):
            raise ValueError(f'{sheet}!{sel} bukan teks: {type(v).__name__}')
        return {'sel': f'{sheet}!{sel}', 'nilai': v}

    def deret(self, sheet, sels):
        return {'sel': [f'{sheet}!{x}' for x in sels], 'nilai': [self.n(sheet, x) for x in sels]}

    def rumus(self, sheet, sel):
        v = self.wf[sheet][sel].value
        return v if isinstance(v, str) and v.startswith('=') else None

    def opsi(self, sheet, sel):
        """Sel yang boleh berupa angka, teks (`-`, `#VALUE!`, `cek range`), atau kosong.

        `rumus` = True bila sel berisi rumus, False bila KONSTANTA yang diketik di master.
        """
        v = self.nilai(sheet, sel)
        return {
            'sel': f'{sheet}!{sel}',
            'nilai': angka(v),
            'teks': v if isinstance(v, str) else None,
            'rumus': self.rumus(sheet, sel) is not None,
        }


def tinv(p, df):
    """`TINV(p, df)` Excel: dua-sisi, derajat bebas dipotong ke bilangan bulat."""
    from scipy import stats

    return float(stats.t.ppf(1 - p / 2, math.floor(df)))


def rel(a, b):
    return abs(a - b) / max(abs(b), 1e-300) if b != 0 else abs(a - b)


def ukur(a, b):
    """Selisih relatif dengan lantai penyebut 0,1: di bawah itu jadi batas mutlak 1e-12.

    Simpangan baku bacaan identik di Excel bukan 0 persis (derau 1e-16) sedangkan
    Python memberi 0; selisih relatif murni akan menjerit tanpa arti.
    """
    return abs(a - b) / max(abs(b), 0.1)


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


def jumlah_titik(s):
    n = 0
    while s.n(I, f'B{BARIS_INPUT_PERTAMA + n}', wajib=False) is not None:
        n += 1
    return n


def gaya(path, jenis):
    sha, wf, wv = buka(path)
    s = Sumber(wf, wv)
    kolom_standar = KOLOM_NILAI_STANDAR[jenis]

    satuan = s.teks(I, 'G15')
    faktor = s.sel(DB, FAKTOR_SATUAN_SEL[satuan['nilai']])
    f = faktor['nilai']
    n = jumlah_titik(s)
    if n < 2:
        raise SystemExit('BERHENTI: kurang dari dua titik terbaca di INPUT DATA.')

    # ---- INPUT DATA: hanya sel angka dan teks teknis (tanpa identitas pelanggan/nomor/teknisi) ----
    titik_in = []
    for i in range(n):
        r = BARIS_INPUT_PERTAMA + i
        titik_in.append({
            'titik_ke': i + 1,
            'nominal': s.sel(I, f'B{r}'),
            'bacaan': s.deret(I, [f'{c}{r}' for c in KOL_BACAAN]),
        })
    masukan = {
        'satuan_G15': satuan,
        'tipe_beban_Z22': s.teks(I, 'Z22'),
        'standar_dipakai_Z16': s.teks(I, 'Z16'),
        'kode_standar_Q23': s.sel(I, 'Q23'),
        'rentang_ukur_E14': s.sel(I, 'E14'),
        'kapasitas_E15': s.sel(I, 'E15'),
        'resolusi_E16': s.sel(I, 'E16'),
        'suhu_sertifikat_standar_H6': s.sel(I, 'H6'),
        'suhu_aktual_standar_K7': s.sel(I, 'K7'),
        'suhu_ruang_awal_E21': s.sel(I, 'E21'),
        'suhu_ruang_akhir_F21': s.sel(I, 'F21'),
        'suhu_ruang_rata_H21': s.sel(I, 'H21'),
        'preload_zero': s.deret(I, ['C30', 'D30', 'F30']),
        'preload_max': s.deret(I, ['C31', 'D31', 'F31']),
        'misalignment_mm': s.deret(MIS, ['C3', 'C4', 'C5', 'C6']),
        'titik': titik_in,
    }

    # ---- PERHITUNGAN FC dan SERTIFIKAT per titik ----
    suhu_s, suhu_a = masukan['suhu_sertifikat_standar_H6']['nilai'], masukan['suhu_aktual_standar_K7']['nilai']
    titik = []
    rk = {'konversi': 0.0, 'rata_sd_rsd': 0.0, 'rantai_yza': 0.0, 'rrpe': 0.0, 'sertifikat': 0.0}
    for i in range(n):
        rf, rs = BARIS_FC_PERTAMA + i, BARIS_SERT_PERTAMA + i
        masuk = titik_in[i]
        bacaan_kn = s.deret(FC, [f'{c}{rf}' for c in KOL_BACAAN])
        B = s.sel(FC, f'B{rf}')
        R, Sd = s.sel(FC, f'R{rf}'), s.sel(FC, f'S{rf}')
        T, V, W = s.opsi(FC, f'T{rf}'), s.opsi(FC, f'V{rf}'), s.opsi(FC, f'W{rf}')
        Y, Z, AA, AB = s.sel(FC, f'Y{rf}'), s.sel(FC, f'Z{rf}'), s.sel(FC, f'AA{rf}'), s.opsi(FC, f'AB{rf}')
        E, L, Q, Vs = s.sel(S, f'E{rs}'), s.sel(S, f'L{rs}'), s.sel(S, f'Q{rs}'), s.opsi(S, f'V{rs}')

        rk['konversi'] = max(
            rk['konversi'], ukur(masuk['nominal']['nilai'] * f, B['nilai']),
            *(ukur(a * f, b) for a, b in zip(masuk['bacaan']['nilai'], bacaan_kn['nilai'])),
        )
        rata = statistics.fmean(bacaan_kn['nilai'])
        sd = statistics.stdev(bacaan_kn['nilai'])
        rk['rata_sd_rsd'] = max(rk['rata_sd_rsd'], ukur(rata, R['nilai']), ukur(sd, Sd['nilai']))
        if T['rumus'] and T['nilai'] is not None:
            rk['rata_sd_rsd'] = max(rk['rata_sd_rsd'], ukur(Sd['nilai'] / R['nilai'] * 100, T['nilai']))
        rk['rantai_yza'] = max(
            rk['rantai_yza'],
            ukur(R['nilai'] + W['nilai'], Y['nilai']) if W['nilai'] is not None else 0.0,
            ukur(Y['nilai'] * (1 + 0.00027 * (suhu_s - suhu_a)), Z['nilai']),
            ukur(Y['nilai'] - B['nilai'], AA['nilai']),
        )
        if AB['rumus'] and AB['nilai'] is not None:
            rrpe = (max(bacaan_kn['nilai']) - min(bacaan_kn['nilai'])) / B['nilai'] * 100
            rk['rrpe'] = max(rk['rrpe'], ukur(rrpe, AB['nilai']))
        nilai_standar = {'Y': Y, 'Z': Z}[kolom_standar]['nilai']
        rk['sertifikat'] = max(
            rk['sertifikat'], ukur(B['nilai'] / f, L['nilai']), ukur(nilai_standar / f, E['nilai']),
            ukur(E['nilai'] - L['nilai'], Q['nilai']),
            ukur(AB['nilai'], Vs['nilai']) if Vs['nilai'] is not None and AB['nilai'] is not None else 0.0,
        )
        titik.append({
            'titik_ke': i + 1,
            'bacaan_kn': bacaan_kn,
            'B_nominal_kn': B, 'R_rata_kn': R, 'S_stdev_kn': Sd, 'T_rsd_persen': T,
            'V_set_point_kn': V, 'W_koreksi_standar_kn': W,
            'Y_terkoreksi_kn': Y, 'Z_terkoreksi_termal_kn': Z, 'AA_correction_kn': AA, 'AB_rrpe_persen': AB,
            'sertifikat': {'E_standar': E, 'L_uut': L, 'Q_koreksi': Q, 'V_rrpe': Vs},
        })
    for nama, v in rk.items():
        galat_bila_lebih(f'titik.{nama}', v, TOL_REKONSTRUKSI)

    # ---- sel dasar: kapasitas, resolusi, RSD maks, zero error, misalignment ----
    sel_zero = [f'{c}{BARIS_FC_ZERO_ERROR}' for c in KOL_BACAAN]
    zero_pair = s.deret(FC, sel_zero)
    nol = titik[0]['bacaan_kn']['nilai']
    pasangan = []
    for g in range(4):
        a, b, c = nol[3 * g], nol[3 * g + 1], nol[3 * g + 2]
        pasangan += [a - b, a - c, b - c]
    rsd_semua = [t['T_rsd_persen']['nilai'] for t in titik if t['T_rsd_persen']['nilai'] is not None]
    mis = masukan['misalignment_mm']['nilai']
    dasar = {
        'kapasitas_kn_G8': s.sel(FC, 'G8'),
        'resolusi_kn_G9': s.sel(FC, 'G9'),
        'rsd_maks_T44': s.sel(FC, 'T44'),
        'zero_error_pasangan_C44_P44': zero_pair,
        'zero_error_maks_C45': s.sel(FC, 'C45'),
        'beban_uji_maks_C46': s.sel(FC, 'C46'),
        'zero_error_rumus_C44': s.rumus(FC, 'C44'),
        'zero_error_rumus_C45': s.rumus(FC, 'C45'),
        'misalignment_stdev_D3': s.sel(MIS, 'D3'),
        'misalignment_rata_C7': s.sel(MIS, 'C7'),
        'faktor_satuan': faktor,
        'resolusi_standar_kn_K6': s.sel(U, 'K6'),
        'kapasitas_standar_teks_Z4': s.teks(U, 'Z4'),
        'tabel_drift': tabel_drift(wf, wv),
        'standar_dipakai_U_Z3': s.teks(U, 'Z3'),
    }
    cap_std = float(dasar['kapasitas_standar_teks_Z4']['nilai'])
    rek_dasar = {
        'kapasitas_G8': ukur(masukan['kapasitas_E15']['nilai'] * f, dasar['kapasitas_kn_G8']['nilai']),
        'resolusi_G9': ukur(masukan['resolusi_E16']['nilai'] * f, dasar['resolusi_kn_G9']['nilai']),
        'rsd_maks_T44': ukur(max(rsd_semua), dasar['rsd_maks_T44']['nilai']),
        'zero_error_pasangan': max(ukur(a, b) for a, b in zip(pasangan, zero_pair['nilai'])),
        'zero_error_maks_C45': ukur(max(zero_pair['nilai']), dasar['zero_error_maks_C45']['nilai']),
        'beban_uji_maks_C46': ukur(max(t['B_nominal_kn']['nilai'] for t in titik), dasar['beban_uji_maks_C46']['nilai']),
        'misalignment_D3': ukur(statistics.stdev(mis), dasar['misalignment_stdev_D3']['nilai']),
        'misalignment_C7': ukur(statistics.fmean(mis), dasar['misalignment_rata_C7']['nilai']),
    }
    for nama, v in rek_dasar.items():
        galat_bila_lebih(f'dasar.{nama}', v, TOL_REKONSTRUKSI)

    # ---- budget PERHITUNGAN U95% ----
    komp = {}
    for j, nama in enumerate(KOMPONEN):
        r = BARIS_KOMPONEN + j
        komp[nama] = {
            'U_mentah_N': s.sel(U, f'N{r}'), 'divisor_Q': s.sel(U, f'Q{r}'), 'vi_S': s.sel(U, f'S{r}'),
            'ui_U': s.sel(U, f'U{r}'), 'ci_X': s.sel(U, f'X{r}'), 'uici_Z': s.sel(U, f'Z{r}'),
            'uici_kuadrat_AC': s.sel(U, f'AC{r}'), 'uici4_per_vi_AG': s.sel(U, f'AG{r}'),
        }
    u_k4, u_k5, u_k6 = s.sel(U, 'K4'), s.sel(U, 'K5'), s.sel(U, 'K6')
    kn = lambda x: komp[x]['U_mentah_N']['nilai']  # noqa: E731
    rek_budget = {
        'N10_resolusi_uut': rel(u_k5['nilai'] / u_k4['nilai'] * 100 / 2, kn('daya_baca_uut')),
        'N11_resolusi_standar': rel(u_k6['nilai'] / cap_std * 100 / 2, kn('daya_baca_standar')),
        'N14_pengulangan': rel(dasar['rsd_maks_T44']['nilai'] / math.sqrt(12), kn('pengulangan')),
        'N15_zero_error': ukur(dasar['zero_error_maks_C45']['nilai'] / dasar['beban_uji_maks_C46']['nilai'] * 100,
                               kn('zero_error')),
        'N16_misalignment': rel(dasar['misalignment_stdev_D3']['nilai'] / dasar['misalignment_rata_C7']['nilai'] * 100,
                                kn('misalignment')),
        'K4_kapasitas': ukur(dasar['kapasitas_kn_G8']['nilai'], u_k4['nilai']),
        'K5_resolusi': ukur(dasar['resolusi_kn_G9']['nilai'], u_k5['nilai']),
    }
    selisih_ui = max(
        rel(k['U_mentah_N']['nilai'] / k['divisor_Q']['nilai'], k['ui_U']['nilai'])
        for nama, k in komp.items() if nama != 'drift_standar'
    )
    # Drift: master TIDAK membagi divisor (U13 = N13). Dicatat apa adanya, bukan dikoreksi.
    rek_budget['U13_drift_tanpa_divisor'] = rel(komp['drift_standar']['U_mentah_N']['nilai'],
                                              komp['drift_standar']['ui_U']['nilai'])
    rek_budget['uici'] = max(rel(k['ui_U']['nilai'] * k['ci_X']['nilai'], k['uici_Z']['nilai']) for k in komp.values())
    rek_budget['uici_kuadrat'] = max(
        ukur(k['uici_Z']['nilai'] ** 2, k['uici_kuadrat_AC']['nilai']) for k in komp.values())
    rek_budget['uici4_per_vi'] = max(
        ukur(k['uici_kuadrat_AC']['nilai'] ** 2 / k['vi_S']['nilai'], k['uici4_per_vi_AG']['nilai'])
        for k in komp.values())
    agregat = {
        'jumlah_uici_kuadrat_AC17': s.sel(U, 'AC17'), 'sigma_uici4_per_vi_AG17': s.sel(U, 'AG17'),
        'uc_AC18': s.sel(U, 'AC18'), 'veff_AC19': s.sel(U, 'AC19'), 'k_AC20': s.sel(U, 'AC20'),
        'U_persen_AC21': s.sel(U, 'AC21'), 'U_kn_AH21': s.sel(U, 'AH21'),
    }
    rc = rantai([(k['ui_U']['nilai'], k['ci_X']['nilai'], k['vi_S']['nilai']) for k in komp.values()])
    sel_uc_veff = max(rel(rc['uc'], agregat['uc_AC18']['nilai']), rel(rc['veff'], agregat['veff_AC19']['nilai']))
    sel_k_u = max(rel(rc['k'], agregat['k_AC20']['nilai']), rel(rc['u_hitung'], agregat['U_persen_AC21']['nilai']))
    sel_u_kn = rel(agregat['U_persen_AC21']['nilai'] / 100 * u_k4['nilai'], agregat['U_kn_AH21']['nilai'])
    sel_jumlah = max(
        rel(sum(k['uici_kuadrat_AC']['nilai'] for k in komp.values()), agregat['jumlah_uici_kuadrat_AC17']['nilai']),
        rel(sum(k['uici4_per_vi_AG']['nilai'] for k in komp.values()), agregat['sigma_uici4_per_vi_AG17']['nilai']),
    )
    for nama, v in rek_budget.items():
        galat_bila_lebih(f'budget.{nama}', v, TOL_REKONSTRUKSI)
    galat_bila_lebih('ui = U/divisor', selisih_ui, TOL_REKONSTRUKSI)
    galat_bila_lebih('jumlah', sel_jumlah, TOL_REKONSTRUKSI)
    galat_bila_lebih('uc/veff', sel_uc_veff, TOL_REKONSTRUKSI)
    galat_bila_lebih('k/U', sel_k_u, TOL_REKONSTRUKSI_K)
    galat_bila_lebih('U kN', sel_u_kn, TOL_REKONSTRUKSI)

    # ---- CMC dan U cetak. UTM: AC22 (kN) -> AC23; Load Cell: AC22 bisa 'cek range' -> AH23 = U hitung ----
    cmc = {
        'AC22_cmc_kn': s.opsi(U, 'AC22'),
        'AC22_rumus': s.rumus(U, 'AC22'),
        'AH22': s.opsi(U, 'AH22'),
        'AC23': s.opsi(U, 'AC23'),
        'AH23': s.opsi(U, 'AH23'),
        'AC23_rumus': s.rumus(U, 'AC23'),
        'AH23_rumus': s.rumus(U, 'AH23'),
        'cmc_utm_DATABASE_S5_S8': s.deret(DB, ['S5', 'S6', 'S7', 'S8']),
        'jenis_beban_teks': s.opsi(U, 'AA7' if jenis == 'utm' else 'AD7'),
    }
    sumber_u_cetak = cmc['AC23'] if jenis == 'utm' else cmc['AH23']
    u_cetak_kn = sumber_u_cetak['nilai']
    cmc_kn = cmc['AC22_cmc_kn']['nilai']
    u_ref = max(agregat['U_kn_AH21']['nilai'], cmc_kn if cmc_kn is not None else 0.0)
    sel_cetak = rel(u_ref, u_cetak_kn)
    cetak = {
        'U_cetak_P35': s.sel(S, 'P35'),
        'k_cetak_T36': s.sel(S, 'T36'),
        'U_cetak_rumus_P35': s.rumus(S, 'P35'),
    }
    sel_cetak = max(sel_cetak, rel(u_cetak_kn / f, cetak['U_cetak_P35']['nilai']),
                    rel(agregat['k_AC20']['nilai'], cetak['k_cetak_T36']['nilai']))
    galat_bila_lebih('U cetak', sel_cetak, TOL_REKONSTRUKSI)

    drift_sel = s.opsi(U, 'N13')
    drift = {'N13_drift': drift_sel, 'N13_rumus': s.rumus(U, 'N13')}

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
            'budget': {'komponen': komp, 'agregat': agregat, 'drift': drift, 'K4_kapasitas_kn': u_k4},
            'cmc': cmc,
            'cetak': cetak,
        },
        'rekonstruksi': {
            'selisih_relatif_maks_terhadap_cache': max(
                *rk.values(), *rek_dasar.values(), *rek_budget.values(),
                selisih_ui, sel_jumlah, sel_uc_veff, sel_k_u, sel_u_kn, sel_cetak,
            ),
            'titik': rk,
            'sel_dasar': rek_dasar,
            'budget': rek_budget,
            'uc_veff': sel_uc_veff,
            'k_U_persen': sel_k_u,
            'U_kn': sel_u_kn,
            'U_cetak': sel_cetak,
            'catatan': 'konversi satuan -> rata/sd/RSD/RRPE -> Y/Z/AA -> nilai cetak SERTIFIKAT, lalu komponen -> uc -> '
                       'veff -> k (TINV: t 0,975, floor(veff)) -> U -> max(U; CMC) -> P35 dihitung ulang dari sel hulunya. '
                       'Selisih memakai lantai penyebut 0,1 untuk sel yang bisa bernilai ~0 (derau 1e-16 simpangan baku).',
        },
    }
    print(f'{jenis}: ok, {n} titik, {len(KOMPONEN)} komponen, '
          f'rekonstruksi maks {hasil["rekonstruksi"]["selisih_relatif_maks_terhadap_cache"]:.2e}')
    return hasil


def tabel_drift(wf, wv):
    """Isi `Tabel_Drift` (nama standar -> U drift, %), apa adanya. Master membaca KOLOM 2 saja."""
    d = wf.defined_names['Tabel_Drift']
    out = []
    for sheet, rentang in d.destinations:
        rentang = rentang.replace('$', '')
        for baris in wv[sheet][rentang]:
            out.append({
                'sel': f'{sheet}!{baris[0].coordinate}:{baris[1].coordinate}',
                'standar': baris[0].value,
                'drift_persen': angka(baris[1].value),
            })
    return out


# ---------------------------------------------------------------- Sieve Mesh

SV_IN, SV_PH, SV_UB, SV_SE = 'INPUT DATA', 'PERHITUNGAN', 'PERHITUNGAN U95%', 'SERTIFIKAT'
SV_SK, SV_DB = 'STANDAR_KALIBRATOR', 'DATABASE'
SV_KOL_ULANG = ['G', 'H', 'J', 'K', 'L', 'M']   # `PERHITUNGAN!G89:M91`: enam salinan opening 1..6
SV_PARAMETER = {
    'warp': {
        'avg': 'G80', 'idx': 'G81', 'kor': 'G82', 'sd': 'G83', 'ulang_baris': 89, 'ulang_sd': 'H92',
        'budget_baris': 11, 'uc': 'AC18', 'veff': 'AC19', 'k': 'AC20', 'U': 'AC21', 'cmc': 'AC22', 'u_cetak': 'AC24',
        'sert_baris': 20, 'sd_cetak': 'G26', 'vonis_sd': 24,
    },
    'weft': {
        'avg': 'J80', 'idx': 'J81', 'kor': 'J82', 'sd': 'J83', 'ulang_baris': 90, 'ulang_sd': 'K92',
        'budget_baris': 28, 'uc': 'AC35', 'veff': 'AC36', 'k': 'AC37', 'U': 'AC38', 'cmc': 'AC39', 'u_cetak': 'AC41',
        'sert_baris': 21, 'sd_cetak': 'G27', 'vonis_sd': 25,
    },
    'kawat': {
        'avg': 'L80', 'idx': 'L81', 'kor': 'L82', 'sd': 'L83', 'ulang_baris': 91, 'ulang_sd': 'M92',
        'budget_baris': 46, 'uc': 'AC53', 'veff': 'AC54', 'k': 'AC55', 'U': 'AC56', 'cmc': 'AC57', 'u_cetak': 'AC59',
        'sert_baris': 22, 'sd_cetak': 'G28', 'vonis_sd': None,
    },
}
SV_KOLOM_BUDGET = ['N', 'Q', 'S', 'U', 'X', 'Z']   # nilai asal, pembagi, vi, ui, ci, ui*ci


def sieve(path, jenis):
    sha, wf, wv = buka(path)
    s = Sumber(wf, wv)

    # ---- masukan: hanya angka, satuan/standar teknis, tanggal kalibrasi. Bukan Q10:Q12 (pelanggan).
    tgl = s.nilai(SV_IN, 'Q16')
    if not hasattr(tgl, 'strftime'):
        raise SystemExit('BERHENTI: INPUT DATA!Q16 (tanggal kalibrasi) bukan tanggal.')
    opening = []
    for blok, kol in enumerate((('G', 'H', 'I'), ('K', 'L', 'M'))):
        for baris in range(34, 84):
            w, f, k = (s.n(SV_IN, f'{c}{baris}', wajib=False) for c in kol)
            if w is None and f is None and k is None:
                continue
            opening.append({'no': blok * 50 + baris - 33, 'warp': w, 'weft': f, 'kawat': k,
                            'sel': f'{SV_IN}!{kol[0]}{baris}:{kol[2]}{baris}'})
    if len(opening) < 6:
        raise SystemExit('BERHENTI: kurang dari enam opening terbaca.')
    # Tiap opening harus lengkap tiga angka: sel kosong tidak boleh dibaca sebagai 0 (aturan repo).
    for o in opening:
        if None in (o['warp'], o['weft'], o['kawat']):
            raise SystemExit(f"BERHENTI: opening {o['no']} tidak lengkap.")

    masukan = {
        'tipe': s.sel(SV_IN, 'F5'),
        'tipe_teks': s.teks(SV_IN, 'Z14'),
        'nominal': s.sel(SV_IN, 'F14'),
        'satuan_teks': s.teks(SV_IN, 'Z13'),
        'standar_teks': s.teks(SV_IN, 'Z10'),
        'suhu_awal': s.sel(SV_IN, 'F23'),
        'suhu_akhir': s.sel(SV_IN, 'G23'),
        'suhu_rata_PERHITUNGAN_G17': s.sel(SV_PH, 'G17'),
        'tanggal_kalibrasi': {'sel': f'{SV_IN}!Q16', 'nilai': tgl.strftime('%Y-%m-%d')},
        'opening': opening,
    }

    # ---- tabel MPE: baris ukuran yang dipakai (STANDAR_KALIBRATOR!B41:N136, kolom 1 = ukuran mm)
    ukuran = s.n(SV_IN, 'Z16')
    baris_mpe = next((r for r in range(41, 137) if s.n(SV_SK, f'B{r}', wajib=False) == ukuran), None)
    if baris_mpe is None:
        raise SystemExit('BERHENTI: ukuran tidak ada di Tabel_MPE.')
    mpe = {'ukuran_Z16': s.sel(SV_IN, 'Z16'), 'baris': baris_mpe}
    for nama, kol in (('kolom5_compliance', 'F'), ('kolom6_inspection', 'G'), ('kolom7_calibration', 'H'),
                      ('kolom9_y', 'J'), ('kolom10_stdev_maks', 'K'), ('kolom11_kawat_preferred', 'L'),
                      ('kolom12_kawat_maks', 'M'), ('kolom13_kawat_min', 'N')):
        mpe[nama] = s.opsi(SV_SK, f'{kol}{baris_mpe}')

    # ---- rekonstruksi per parameter + penyalinan semua sel
    maks = 0.0
    parameter = {}
    for nama, c in SV_PARAMETER.items():
        nilai = [o[nama] for o in opening]
        rata = s.sel(SV_PH, c['avg'])
        sd = s.sel(SV_PH, c['sd'])
        maks = max(maks, galat_bila_lebih(f'{nama} rata-rata', ukur(sum(nilai) / len(nilai), rata['nilai']), TOL_REKONSTRUKSI))
        maks = max(maks, galat_bila_lebih(f'{nama} STDEV', ukur(statistics.stdev(nilai), sd['nilai']), TOL_REKONSTRUKSI))

        ulang = s.deret(SV_PH, [f"{k}{c['ulang_baris']}" for k in SV_KOL_ULANG])
        if ulang['nilai'] != nilai[:6]:
            raise SystemExit(f'BERHENTI: {nama} salinan pengulangan bukan opening 1..6.')
        ulang_sd = s.sel(SV_PH, c['ulang_sd'])
        maks = max(maks, galat_bila_lebih(f'{nama} STDEV pengulangan', ukur(statistics.stdev(nilai[:6]), ulang_sd['nilai']), TOL_REKONSTRUKSI))

        # Koreksi standar: master memakai kolom 3 (`VLOOKUP(...;3;0)`) dari K12:O21.
        idx = s.opsi(SV_PH, c['idx'])
        baris_k = next((r for r in range(12, 22) if s.n(SV_SK, f'K{r}', wajib=False) == idx['nilai']), None)
        if baris_k is None:
            raise SystemExit(f'BERHENTI: indeks koreksi {nama} tidak ada di Koreksi_Caliper.')
        kolom3 = s.nilai(SV_SK, f'M{baris_k}')
        kolom3_angka = 0.0 if kolom3 is None else float(kolom3)
        terkoreksi = s.sel(SV_PH, c['kor'])
        maks = max(maks, galat_bila_lebih(f'{nama} terkoreksi master', ukur(rata['nilai'] + kolom3_angka, terkoreksi['nilai']), TOL_REKONSTRUKSI))
        koreksi = {
            'indeks': idx,
            'baris_tabel': baris_k,
            'nilai_standar': s.sel(SV_SK, f'K{baris_k}'),
            'penunjukan': s.sel(SV_SK, f'L{baris_k}'),
            'kolom3_dibaca_master': {'sel': f'{SV_SK}!M{baris_k}', 'nilai': kolom3},
            'kolom4_koreksi': s.sel(SV_SK, f'N{baris_k}'),
            'kolom4_rumus': s.rumus(SV_SK, f'N{baris_k}'),
            'terkoreksi_master': terkoreksi,
        }

        # Budget: enam baris komponen.
        komp, ui_ci_vi = [], []
        for i in range(6):
            r = c['budget_baris'] + i
            d = {'baris': r}
            for kol in SV_KOLOM_BUDGET:
                d[kol] = s.sel(SV_UB, f'{kol}{r}')
            d['rumus_N'] = s.rumus(SV_UB, f'N{r}')
            d['rumus_X'] = s.rumus(SV_UB, f'X{r}')
            maks = max(maks, galat_bila_lebih(f'{nama} baris {r} ui', ukur(d['N']['nilai'] / d['Q']['nilai'], d['U']['nilai']), TOL_REKONSTRUKSI))
            maks = max(maks, galat_bila_lebih(f'{nama} baris {r} uici', ukur(d['U']['nilai'] * d['X']['nilai'], d['Z']['nilai']), TOL_REKONSTRUKSI))
            komp.append(d)
            ui_ci_vi.append((d['U']['nilai'], d['X']['nilai'], d['S']['nilai']))
        rc = rantai(ui_ci_vi)
        uc, veff, k, big_u = (s.sel(SV_UB, c[x]) for x in ('uc', 'veff', 'k', 'U'))
        maks = max(maks, galat_bila_lebih(f'{nama} uc', ukur(rc['uc'], uc['nilai']), TOL_REKONSTRUKSI))
        maks = max(maks, galat_bila_lebih(f'{nama} veff', ukur(rc['veff'], veff['nilai']), TOL_REKONSTRUKSI))
        maks = max(maks, galat_bila_lebih(f'{nama} U', ukur(k['nilai'] * rc['uc'], big_u['nilai']), TOL_REKONSTRUKSI))

        # Sertifikat.
        b = c['sert_baris']
        sert = {
            'H_nilai_tercetak': s.sel(SV_SE, f'H{b}'),
            'K_koreksi_tercetak': s.sel(SV_SE, f'K{b}'),
            'N_U95_tercetak': s.sel(SV_SE, f'N{b}'),
            'AB': s.sel(SV_SE, f'AB{b}'),
            'AE_vonis': s.teks(SV_SE, f'AE{b}'),
            'G_stdev': s.sel(SV_SE, c['sd_cetak']),
        }
        if nama == 'kawat':
            sert['AD_min'] = s.sel(SV_SE, 'AD22')
            sert['AD_maks'] = s.sel(SV_SE, 'AD23')
        else:
            sert['AD_batas_y'] = s.sel(SV_SE, f'AD{b}')
            v = c['vonis_sd']
            sert['stdev_AB'] = s.sel(SV_SE, f'AB{v}')
            sert['stdev_AD_batas'] = s.sel(SV_SE, f'AD{v}')
            sert['stdev_AE_vonis'] = s.teks(SV_SE, f'AE{v}')

        parameter[nama] = {
            'rata': rata, 'stdev': sd, 'pengulangan': ulang, 'pengulangan_stdev': ulang_sd,
            'koreksi': koreksi,
            'budget': {
                'komponen': komp, 'uc': uc, 'veff': veff, 'k': s.opsi(SV_UB, c['k']), 'U': big_u,
                'cmc': s.opsi(SV_UB, c['cmc']), 'U_cetak': s.sel(SV_UB, c['u_cetak']),
                'rumus_U_cetak': s.rumus(SV_UB, c['u_cetak']),
            },
            'sertifikat': sert,
        }

    # ---- status standar (NOW()) dan minimum opening
    status = {
        'sisa_hari_Z19': s.opsi(SV_IN, 'Z19'),
        'rumus_Z19': s.rumus(SV_IN, 'Z19'),
        'status_U25': s.opsi(SV_IN, 'U25'),
        'tabel_standar_DB_Z13_rumus': s.rumus(SV_DB, 'Z13'),
        'tabel_standar_DB_Z13': s.opsi(SV_DB, 'Z13'),
    }
    minimum = {'F16': s.opsi(SV_IN, 'F16'), 'rumus_F16': s.rumus(SV_IN, 'F16')}
    cmc = {'DATABASE_T5': s.opsi(SV_DB, 'T5'), 'DATABASE_T6': s.opsi(SV_DB, 'T6')}

    hasil = {
        'sumber': {
            'berkas': os.path.basename(path),
            'sha256': sha,
            'dibuka': 'didekripsi di memori dengan sandi dari pemilik (SIDIK_MASTER_SANDI); tidak ada salinan terbuka-kunci',
        },
        'masukan': masukan,
        'excel': {
            'nominal_PERHITUNGAN_E7': s.sel(SV_PH, 'E7'),
            'kawat_nominal_PERHITUNGAN_E8': s.sel(SV_PH, 'E8'),
            'k_cetak_SERTIFIKAT_O23': s.sel(SV_SE, 'O23'),
            'tabel_mpe': mpe,
            'minimum_opening': minimum,
            'status_standar': status,
            'cmc': cmc,
            'parameter': parameter,
        },
        'rekonstruksi': {
            'selisih_relatif_maks_terhadap_cache': maks,
            'catatan': 'rata-rata, STDEV, STDEV pengulangan (opening 1..6), koreksi master (kolom 3 kosong = 0), ui = N/Q, '
                       'ui*ci, uc, veff, U = k uc dihitung ulang dari sel hulunya dan dibandingkan ke nilai-cache. k = 2 diketik '
                       'di master (bukan TINV), jadi tidak direkonstruksi lewat scipy.',
        },
    }
    print(f'{jenis}: ok, {len(opening)} opening, 3 parameter x 6 komponen, rekonstruksi maks {maks:.2e}')
    return hasil


GENERATOR = {'utm': gaya, 'load_cell': gaya, 'sieve': sieve}


def tulis(nama, kunci, isi):
    kepala = {
        '_digenerate_oleh': 'docs/skrip/gen-fixture-paritas-batch8.py',
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
            tulis(KELUARAN[kunci], 'workbook', GENERATOR[kunci](os.path.join(sumber, BERKAS[kunci]), kunci))
    except SandiTidakAda as e:
        raise SystemExit(f'BERHENTI: variabel lingkungan {e} tidak ada.')
    print('selesai:', KELUAR)


if __name__ == '__main__':
    main()

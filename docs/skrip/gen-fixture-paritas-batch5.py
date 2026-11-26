"""Bangun tests/Fixtures/paritas/<alat>.json batch ke-5 dari workbook master.

Bahan uji PARITAS aplikasi-lawan-Excel batch alat ke-5 (`tests/Feature/Paritas/`):
MASUKAN yang diketik di workbook dan NILAI tiap tahap hitungan Excel, masing-masing
dengan alamat sel sumbernya (`SHEET!SEL`), disalin apa adanya dari nilai-cache
workbook. Keluarannya BUKAN keluaran PHP dan tidak boleh disunting dengan tangan.
Berdampingan dengan `gen-fixture-paritas-batch4.py` dan
`gen-fixture-paritas-analitik-batch2.py`; berkas itu tidak diubah, helper-nya
sengaja disalin supaya tiap generator berdiri sendiri.

## Aturan keras

- Sandi workbook HANYA dari variabel lingkungan `SIDIK_MASTER_SANDI`, dibaca ke
  memori. Tidak ada salinan terbuka-kunci di disk; sandi tidak dicetak, tidak
  ditulis ke fixture, dan tidak dimasukkan ke pesan galat (hanya tipe galat).
- Fixture TIDAK memuat nama/alamat pelanggan, nomor sertifikat, nomor order,
  maupun nama teknisi: skrip hanya membaca sel angka/tanggal yang disebut di bawah.
- Angka acuan = nilai-cache sel. Pengecualian (rekonstruksi rantai dari sel dasar,
  untuk memastikan pembacaan kami atas rumus benar) ditandai di fixture (`rekonstruksi`).
- Sebelum menulis, skrip menghitung ulang rantai `PERHITUNGAN U95%` dari sel dasarnya
  (komponen -> uc -> v_eff -> k -> U -> max(U; CMC)) dan membandingkannya ke
  nilai-cache; selisih relatif terbesar dicatat di fixture
  (`rekonstruksi.*`). Di atas batas skrip berhenti: berarti pembacaannya (bukan
  workbook) yang salah.

## Pakai

    py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-batch5.py <folder-Downloads> [alat ...]

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
    'dial_indicator': 'EXEL CALIBRATION SIDIK/Master Olah Data_Dial Indicator.xlsm',
}

I, P, U, DB, S = 'INPUT DATA', 'PERHITUNGAN', 'PERHITUNGAN U95%', 'DATABASE', 'SERTIFIKAT'


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
        return {'sel': f'{sheet}!{sel}', 'nilai': v if isinstance(v, str) else None}

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


def teks_excel(nilai, fmt):
    """Teks yang ditampilkan Excel untuk `nilai` berformat `fmt` (0.000 / 0 / ...).

    Dibulatkan dulu ke 15 angka penting (presisi tampilan Excel), baru ke
    desimal format, setengah menjauhi nol. Tanda minus dipertahankan walau
    hasilnya nol (perilaku Excel). Ini EMULASI, bukan teks yang dibaca dari
    render Excel.
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


def blok_budget(s, kol, baris_awal, nama_komponen, kecuali_ui=()):
    """Satu blok `PERHITUNGAN U95%` (satu budget): komponen + agregat, sel demi sel.

    Posisi baris agregat (relatif baris komponen pertama, n komponen):
    jumlah n, uc n+1, v_eff n+2, k n+3, U n+4, CMC n+5, U sertifikat n+6.
    Balik juga selisih rekonstruksi: (uc/veff, k/U/cetak, komponen).
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
    rk = rantai([(komp[x]['ui']['nilai'], komp[x]['ci']['nilai'], komp[x]['vi']['nilai']) for x in nama_komponen])
    selisih_uc_veff = max(rel(rk['uc'], agregat['uc']['nilai']), rel(rk['veff'], agregat['veff']['nilai']))
    selisih_k_u = max(
        rel(rk['k'], agregat['k']['nilai']), rel(rk['u_hitung'], agregat['u_hitung']['nilai']),
        rel(max(rk['u_hitung'], agregat['cmc']['nilai']), agregat['u_cetak']['nilai']),
    )
    # ui = U / pembagi; sel pengulangan menulis pembagi sebagai SQRT(n) langsung.
    selisih_ui = max(
        min(rel(komp[x]['ui']['nilai'], komp[x]['u_mentah']['nilai'] / komp[x]['pembagi']['nilai']),
            rel(komp[x]['ui']['nilai'], komp[x]['u_mentah']['nilai'] / math.sqrt(komp[x]['pembagi']['nilai'])))
        for x in nama_komponen if komp[x]['pembagi']['nilai'] and x not in kecuali_ui
    )
    return komp, agregat, {'uc_veff': selisih_uc_veff, 'k_u_cetak': selisih_k_u, 'ui': selisih_ui}


def galat_bila_lebih(nama, nilai, batas):
    if nilai > batas:
        raise SystemExit(f'BERHENTI: rekonstruksi {nama} meleset {nilai:.3e} > {batas:.0e} — pembacaan skrip yang salah.')
    return nilai


# ----------------------------------------------------------------------------
# Dial Indicator (mm) — sepuluh titik, kapasitas 25 mm, resolusi 0,01 mm
# ----------------------------------------------------------------------------
# Urutan = baris 5..14 `PERHITUNGAN U95%`. Komponen KESEBELAS (histeresis) hanya ada di aplikasi.
KOMP_DIAL = [
    'pengulangan', 'resolusi_uut', 'ketidakpastian_standar', 'suhu_ruang', 'koefisien_muai',
    'drift_standar', 'wringing', 'tegak_lurus', 'meja_granit', 'selisih_suhu',
]
KOL_DIAL = dict(u='K', pembagi='N', vi='Q', ui='T', ci='V', uici='X', kuadrat='AA', pangkat4='AC', agg='AA')
N_TITIK_DIAL = 10
BARIS_INPUT_DIAL = 37      # `INPUT DATA!C37`, tiap titik 3 baris
BARIS_PERH_DIAL = 31       # `PERHITUNGAN!B31`, tiap titik 3 baris


def dial_indicator(path):
    sha, wf, wv = buka(path)
    s = Sumber(wf, wv)

    # ---- INPUT DATA: hanya sel angka/tanggal (tanpa identitas pelanggan, nomor, teknisi) ----
    pra_sel = ['C31', 'D31', 'F31', 'G31', 'H31', 'I31', 'J31', 'K31', 'L31', 'M31']
    titik_in = []
    for i in range(N_TITIK_DIAL):
        r = BARIS_INPUT_DIAL + 3 * i
        keping_sel = [f'C{r + j}' for j in range(3) if s.n(I, f'C{r + j}', wajib=False) is not None]
        titik_in.append({
            'titik_ke': i + 1,
            'keping_mm': s.deret(I, keping_sel),
            'pembacaan_mm': s.deret(I, [f'{c}{r}' for c in 'FGHIJ']),
        })
    masukan = {
        'rentang_ukur_mm': s.sel(I, 'E14'),
        'kapasitas_mm': s.sel(I, 'E15'),
        'resolusi_mm': s.sel(I, 'E16'),
        'kode_satuan': s.sel(I, 'G14'),
        'suhu_ruang_awal_c': s.sel(I, 'E21'),
        'suhu_ruang_akhir_c': s.sel(I, 'F21'),
        'suhu_ruang_rata_c': s.sel(I, 'H21'),
        'tanggal_kalibrasi': {k: v for k, v in s.tanggal(I, 'O16').items() if k != '_dt'},
        'pra_evaluasi_mm': s.deret(I, pra_sel),
        'balok_pra_evaluasi_mm': s.deret(I, ['H29', 'I29']),
        'titik': titik_in,
    }

    # ---- PERHITUNGAN: per titik ----
    titik = []
    for i in range(N_TITIK_DIAL):
        r = BARIS_PERH_DIAL + 3 * i
        keping_terkoreksi = [f'F{r + j}' for j in range(3) if s.n(P, f'F{r + j}', wajib=False) is not None]
        nominal_keping = [f'C{r + j}' for j in range(3) if s.n(P, f'C{r + j}', wajib=False) is not None]
        pembacaan = s.deret(P, [f'{c}{r}' for c in 'IJKLM'])
        total = s.sel(P, f'H{r}')
        standar = s.sel(P, f'AB{r}')
        rata = s.sel(P, f'N{r}')
        koreksi = s.sel(P, f'AD{r}')
        sd = s.sel(P, f'AC{r}')
        # rekonstruksi: rata, sd, koreksi, total dari sel dasarnya
        rk = {
            'rata': rel(statistics.fmean(pembacaan['nilai']), rata['nilai']),
            'koreksi': rel(standar['nilai'] - rata['nilai'], koreksi['nilai']),
            'total': rel(math.fsum(s.n(P, c) for c in keping_terkoreksi), total['nilai']),
            'sd': rel(statistics.stdev(pembacaan['nilai']), sd['nilai']) if sd['nilai'] != 0 else abs(statistics.stdev(pembacaan['nilai'])),
        }
        titik.append({
            'titik_ke': i + 1,
            'jumlah_keping': s.sel(P, f'B{r}'),
            'keping_nominal_mm': s.deret(P, nominal_keping),
            'keping_terkoreksi_mm': s.deret(P, keping_terkoreksi),
            'total_nominal_mm': total,
            'pembacaan_mm': pembacaan,
            'rata_rata_mm': rata,
            'standar_terkoreksi_mm': standar,
            'simpangan_baku_mm': sd,
            'koreksi_mm': koreksi,
            '_rk': rk,
        })
    selisih_titik = max(max(t['_rk'].values()) for t in titik)
    for t in titik:
        del t['_rk']

    # ---- konstanta, suhu, panjang sensitivitas ----
    dasar = {
        'kapasitas_perhitungan_mm': s.sel(P, 'G8'),
        'resolusi_perhitungan_mm': s.sel(P, 'G9'),
        'suhu_balok_c': s.sel(P, 'O31'),
        'suhu_uut_c': s.sel(P, 'P31'),
        'theta_c': s.sel(P, 'Q31'),
        'alpha_balok_per_c': s.sel(P, 'S31'),
        'alpha_uut_per_c': s.sel(P, 'U31'),
        'delta_alpha_per_c_sesi': s.sel(P, 'V31'),
        'alpha_rata_per_c': s.sel(P, 'W31'),
        'delta_theta_c': s.sel(P, 'X31'),
        'delta_alpha_per_c': s.sel(P, 'V22'),
        'u_alpha_per_c': s.sel(P, 'W22'),
        'balok_evaluasi_nominal_mm': s.deret(P, ['H22', 'I22']),
        'balok_evaluasi_u_um': s.deret(P, ['H23', 'I23']),
        'u_standar_um': s.sel(P, 'O25'),
        'u_standar_mm': s.sel(P, 'P25'),
        'pengulangan_stdev_mm': s.sel(P, 'N25'),
        'jumlah_keping_maks': s.sel(P, 'B61'),
        'panjang_sensitivitas_mm': s.sel(P, 'C61'),
        'kapasitas_drift_mm': s.sel(P, 'L21'),
    }

    # ---- drift: X11 = NOW() (cache), W13 = tanggal kalibrasi balok ----
    x11 = s.tanggal(DB, 'X11')
    w13 = s.tanggal(DB, 'W13')
    umur_hari = (x11['_dt'] - w13['_dt']).total_seconds() / 86400
    k10 = s.sel(U, 'K10')
    k10_rekon = (0.02 + 0.00025 * dasar['kapasitas_drift_mm']['nilai']) * 1 / 1000 * (umur_hari / 12)
    drift = {
        'x11_now_cache': {k: v for k, v in x11.items() if k != '_dt'},
        'w13_tanggal_kalibrasi_balok': {k: v for k, v in w13.items() if k != '_dt'},
        'umur_hari_dari_cache': umur_hari,
        'k10_cache': k10,
        'k10_rumus': s.rumus(U, 'K10'),
        'pembagi_master': 12,
        'catatan': 'X11 = NOW() sehingga cache tumbuh tiap workbook dihitung ulang; tes mengunci tanggal sesi ke cache ini.',
    }

    # ---- budget PERHITUNGAN U95% ----
    komp, agregat, selisih = blok_budget(s, KOL_DIAL, 5, KOMP_DIAL)
    # ci panjang & suhu dari sel dasarnya; wringing dari B61; standar dari H23/I23
    c61 = dasar['panjang_sensitivitas_mm']['nilai']
    rek_lain = {
        'ci_suhu_V8': rel(komp['suhu_ruang']['ci']['nilai'], c61 * komp['koefisien_muai']['u_mentah']['nilai']),
        'ci_muai_V9': rel(komp['koefisien_muai']['ci']['nilai'], c61 * komp['suhu_ruang']['u_mentah']['nilai']),
        'ci_selisih_suhu_V14': rel(komp['selisih_suhu']['ci']['nilai'], komp['suhu_ruang']['ci']['nilai']),
        'wringing_K11': rel(komp['wringing']['u_mentah']['nilai'], math.sqrt(dasar['jumlah_keping_maks']['nilai'] * 0.05 ** 2) / 1000),
        'standar_K7': rel(komp['ketidakpastian_standar']['u_mentah']['nilai'], math.sqrt(sum(u ** 2 for u in dasar['balok_evaluasi_u_um']['nilai'])) / 1000),
        'standar_P25': rel(dasar['u_standar_mm']['nilai'], dasar['u_standar_um']['nilai'] / 1000),
        'drift_K10': rel(k10_rekon, k10['nilai']),
        'pengulangan_K5': abs(komp['pengulangan']['u_mentah']['nilai'] - dasar['pengulangan_stdev_mm']['nilai']),
        'resolusi_K6': rel(komp['resolusi_uut']['u_mentah']['nilai'], 0.5 * dasar['resolusi_perhitungan_mm']['nilai']),
    }
    galat_bila_lebih('uc/veff', selisih['uc_veff'], TOL_REKONSTRUKSI)
    galat_bila_lebih('k/U/cetak', selisih['k_u_cetak'], TOL_REKONSTRUKSI_K)
    galat_bila_lebih('ui = u/pembagi', selisih['ui'], TOL_REKONSTRUKSI)
    galat_bila_lebih('titik (rata/sd/koreksi/total)', selisih_titik, TOL_REKONSTRUKSI)
    for nama, v in rek_lain.items():
        # drift: X11 dibaca pada resolusi datetime openpyxl (milidetik) -> sedikit longgar.
        galat_bila_lebih(nama, v, TOL_REKONSTRUKSI_K if nama == 'drift_K10' else 1e-12 if nama == 'pengulangan_K5' else TOL_REKONSTRUKSI)

    # ---- CMC (µm) per pita dan pembagi satuan ----
    cmc = {
        'u95_um_per_pita': s.deret(DB, ['T5', 'T6', 'T7', 'T8']),
        'pembagi_satuan_T23': s.sel(DB, 'T23'),
    }

    # ---- angka CETAK (SERTIFIKAT) ----
    cetak_titik = []
    for i in range(N_TITIK_DIAL):
        r = 18 + i
        cetak_titik.append({
            'titik_ke': i + 1,
            'standar': cetak(s, S, f'D{r}'),
            'uut': cetak(s, S, f'G{r}'),
            'koreksi': cetak(s, S, f'L{r}'),
        })
    cetak_blok = {
        'titik': cetak_titik,
        'u95': cetak(s, S, 'J28'),
        'k': cetak(s, S, 'O29'),
        'suhu_ruang_cetak': s.sel(S, 'O12'),
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
            'cmc': cmc,
            'cetak': cetak_blok,
        },
        'rekonstruksi': {
            'selisih_relatif_maks_terhadap_cache': max(
                selisih['uc_veff'], selisih['k_u_cetak'], selisih['ui'], selisih_titik, *rek_lain.values(),
            ),
            'uc_veff': selisih['uc_veff'],
            'k_u_cetak': selisih['k_u_cetak'],
            'komponen_ui': selisih['ui'],
            'titik': selisih_titik,
            'sel_dasar': rek_lain,
            'catatan': 'komponen -> uc -> veff -> k (TINV: t 0,975, floor(veff)) -> U -> max(U, CMC) dihitung ulang dari sel komponen; '
                       'rata/sd/koreksi/total titik dihitung ulang dari pembacaan dan keping; sel dasar K5..K11, V8, V9, V14 dihitung ulang dari sel hulunya.',
        },
    }
    print(f'dial_indicator: ok, {N_TITIK_DIAL} titik, 10 komponen, '
          f'rekonstruksi maks {hasil["rekonstruksi"]["selisih_relatif_maks_terhadap_cache"]:.2e}')
    return hasil


GENERATOR = {
    'dial_indicator': ('dial-indicator.json', dial_indicator),
}


def tulis(nama, kunci, isi):
    kepala = {
        '_digenerate_oleh': 'docs/skrip/gen-fixture-paritas-batch5.py',
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

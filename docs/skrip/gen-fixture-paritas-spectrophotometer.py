"""Bangun tests/Fixtures/paritas/spectrophotometer.json dari workbook master.

Bahan uji PARITAS aplikasi-lawan-Excel untuk Spectrophotometer (batch 3,
`tests/Feature/Paritas/ParitasSpectrophotometerTest.php`): MASUKAN yang diketik di
workbook dan NILAI tiap tahap hitungan Excel, masing-masing dengan alamat sel
sumbernya (`SHEET!SEL`), disalin apa adanya dari nilai-cache workbook. Keluarannya
BUKAN keluaran PHP dan tidak boleh disunting dengan tangan. Berdampingan dengan
`gen-fixture-paritas-analitik-batch2.py`; berkas itu tidak diubah, helper-nya sengaja
disalin supaya generator ini berdiri sendiri.

Tiga grup titik dalam satu sheet: Holmium (panjang gelombang), Didymium (panjang
gelombang), dan %T (transmitan, 6 pembacaan per titik = 2 baris x 3 kolom).

## Aturan keras

- Sandi workbook HANYA dari variabel lingkungan `SIDIK_MASTER_SANDI`, dibaca ke
  memori. Tidak ada salinan terbuka-kunci di disk; sandi tidak dicetak, tidak
  ditulis ke fixture, dan tidak dimasukkan ke pesan galat (hanya tipe galat).
- Fixture TIDAK memuat nama/alamat pelanggan, nomor sertifikat, nomor order,
  maupun nama teknisi: skrip hanya membaca sel angka yang disebut di bawah, plus
  teks RUMUS beberapa sel (bukan nilainya) untuk mendokumentasikan selisih.
- Angka acuan = nilai-cache sel. Rekonstruksi mandiri (rata-rata, koreksi, STDEV,
  uc, v_eff, k, U, max(U; CMC)) hanya dipakai sebagai PEMERIKSA pembacaan kami atas
  rumus: selisih di atas 1e-11 menghentikan skrip (berarti pembacaannya, bukan
  workbook, yang salah). Selisih terbesar dicatat di `rekonstruksi`.
- Komponen yang ikut dijumlahkan ("disertakan") DIBACA dari rentang `SUM(Jx:Jy)` di
  baris total workbook, bukan diketik di skrip ini.

## Pakai

    py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-spectrophotometer.py <folder-Downloads>

`<folder-Downloads>` berisi `EXEL CALIBRATION SIDIK\\Master Olah Data_Spectrofotometer.xlsm`.
Dependensi: msoffcrypto-tool, openpyxl, scipy.
"""
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
TOL_MUTLAK = 1e-12

BERKAS = 'EXEL CALIBRATION SIDIK/Master Olah Data_Spectrofotometer.xlsm'

I, P, U, DB, S, K = 'INPUT DATA', 'PERHITUNGAN', 'PERHITUNGAN U95%', 'DATABASE', 'SERTIFIKAT', 'STANDAR_KALIBRATOR'

KOMP_PANJANG_GELOMBANG = ['standar_deviasi_maks', 'ketidakpastian_gelas_filter', 'resolusi_alat', 'drift_standar', 'spectral_bandwidth']
KOMP_TRANSMITAN = ['ketidakpastian_standar', 'keterulangan_pengukuran', 'resolusi_alat', 'drift_standar', 'spectral_bandwidth']

# Peta sel per grup (hasil penelusuran sel workbook; nilai & rumusnya dibaca ulang di bawah).
#   masukan: baris INPUT DATA per titik (satu baris, atau dua baris untuk %T).
#   perh:    baris PERHITUNGAN per titik (kolom G standar, H:J pembacaan, K rata-rata, L koreksi, M STDEV).
#   sert:    baris SERTIFIKAT per titik (kolom C nominal, J rata-rata, O koreksi).
GRUP = {
    'holmium': dict(
        satuan='nm', komponen=KOMP_PANJANG_GELOMBANG,
        masukan=[(r,) for r in range(35, 45)], perh=list(range(26, 36)), sert=list(range(19, 29)),
        maks='M36', budget_awal=10, total=16, u_standar=(K, 'M5'), cmc=(DB, 'S5'), resolusi=(I, 'G16'),
        u_cetak='M29', k_cetak='Q30',
    ),
    'didymium': dict(
        satuan='nm', komponen=KOMP_PANJANG_GELOMBANG,
        masukan=[(r,) for r in range(49, 58)], perh=list(range(40, 49)), sert=list(range(34, 43)),
        maks='M49', budget_awal=26, total=32, u_standar=(K, 'M18'), cmc=(DB, 'S6'), resolusi=(I, 'G16'),
        u_cetak='M43', k_cetak='Q44',
    ),
    'transmitan': dict(
        satuan='%T', komponen=KOMP_TRANSMITAN,
        masukan=[(66, 67), (68, 69), (70, 71), (72, 73), (74, 75)], perh=[57, 59, 61, 63, 65], sert=list(range(48, 53)),
        maks='M67', budget_awal=46, total=52, u_standar=(K, 'M29'), cmc=(DB, 'S7'), resolusi=(I, 'E16'),
        u_cetak='M53', k_cetak='Q54',
    ),
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

    def deret(self, sheet, sels):
        return {'sel': [f'{sheet}!{s}' for s in sels], 'nilai': [self.n(sheet, s) for s in sels]}

    def format(self, sheet, sel):
        return self.wf[sheet][sel].number_format

    def rumus(self, sheet, sel):
        v = self.wf[sheet][sel].value
        return v if isinstance(v, str) and v.startswith('=') else None


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
        # Nilai negatif yang membulat ke nol: Excel menampilkan `-0.0`.
        'nol_bertanda_minus': bool(v < 0 and not any(c in '123456789' for c in teks)),
    }


def tinv(p, df):
    """`TINV(p, df)` Excel: dua-sisi, derajat bebas dipotong ke bilangan bulat."""
    from scipy import stats

    return float(stats.t.ppf(1 - p / 2, math.floor(df)))


def batas(acuan, rel=TOL_REKONSTRUKSI, mutlak=TOL_MUTLAK):
    return max(mutlak, rel * abs(acuan))


def rantai(ui_ci_vi):
    """uc, v_eff, k (TINV), U dari daftar (ui, ci, vi) — rumus sel `PERHITUNGAN U95%`."""
    aa = [(ui * ci) ** 2 for ui, ci, _ in ui_ci_vi]
    uc = math.sqrt(sum(aa))
    veff = uc ** 4 / sum(a ** 2 / vi for a, (_, _, vi) in zip(aa, ui_ci_vi))
    k = tinv(0.05, veff)
    return {'uc': uc, 'veff': veff, 'k': k, 'u_hitung': uc * k}


class Pemeriksa:
    """Mengumpulkan selisih rekonstruksi terhadap nilai-cache; berhenti kalau melebihi batas."""

    def __init__(self):
        self.rasio_maks = 0.0
        self.jumlah = 0

    def banding(self, nama, hitung, cache, rel=TOL_REKONSTRUKSI, mutlak=TOL_MUTLAK):
        b = batas(cache, rel, mutlak)
        selisih = abs(hitung - cache)
        self.jumlah += 1
        self.rasio_maks = max(self.rasio_maks, selisih / b)
        if selisih > b:
            raise SystemExit(f'BERHENTI: rekonstruksi {nama} menyimpang dari cache: hitung={hitung!r} cache={cache!r} selisih={selisih:.3e} batas={b:.3e}')


def baris_disertakan(s, total_baris):
    """Baris komponen yang ikut dijumlahkan, dibaca dari `SUM(Jx:Jy)` baris total (J, K, L harus sama)."""
    rentang = []
    for kol in ('J', 'K', 'L'):
        rumus = s.rumus(U, f'{kol}{total_baris}') or ''
        m = re.fullmatch(rf'=SUM\({kol}(\d+):{kol}(\d+)\)', rumus)
        if not m:
            raise SystemExit(f'BERHENTI: rumus total {U}!{kol}{total_baris} tak dikenal: {rumus!r}')
        rentang.append((int(m.group(1)), int(m.group(2))))
    if len(set(rentang)) != 1:
        raise SystemExit(f'BERHENTI: rentang SUM J/K/L baris {total_baris} tidak sama: {rentang}')
    a, b = rentang[0]
    return set(range(a, b + 1))


def titik_grup(s, g, pem):
    out = []
    for idx, (masuk, perh, sert) in enumerate(zip(g['masukan'], g['perh'], g['sert'])):
        sel_baca = [f'{kol}{r}' for r in masuk for kol in ('H', 'I', 'J')]
        pembacaan = s.deret(I, sel_baca)
        standar_masukan = s.sel(I, f'G{masuk[0]}')
        avg = s.sel(P, f'K{perh}')
        kor = s.sel(P, f'L{perh}')
        sd = s.sel(P, f'M{perh}')
        std_perh = s.sel(P, f'G{perh}')
        if abs(std_perh['nilai'] - standar_masukan['nilai']) > 0:
            raise SystemExit(f'BERHENTI: standar {P}!G{perh} tidak sama dengan {I}!G{masuk[0]}')
        # Pemeriksa pembacaan rumus: K=AVERAGE, L=G-K, M=STDEV (sampel).
        xs = pembacaan['nilai']
        pem.banding(f'rata-rata {P}!K{perh}', sum(xs) / len(xs), avg['nilai'])
        pem.banding(f'koreksi {P}!L{perh}', standar_masukan['nilai'] - avg['nilai'], kor['nilai'])
        pem.banding(f'STDEV {P}!M{perh}', statistics.stdev(xs), sd['nilai'])
        out.append({
            'titik_ke': idx + 1,
            'standar': standar_masukan,
            'pembacaan': pembacaan,
            'jumlah_pembacaan': len(xs),
            'rata_rata': avg,
            'koreksi': kor,
            'standar_deviasi': sd,
            'rumus': {
                'rata_rata': s.rumus(P, f'K{perh}'),
                'koreksi': s.rumus(P, f'L{perh}'),
                'standar_deviasi': s.rumus(P, f'M{perh}'),
            },
            'cetak': {
                'nominal': cetak(s, S, f'C{sert}'),
                'rata_rata': cetak(s, S, f'J{sert}'),
                'koreksi': cetak(s, S, f'O{sert}'),
            },
        })
    return out


def budget_grup(s, g, pem):
    awal = g['budget_awal']
    disertakan = baris_disertakan(s, g['total'])
    komp = {}
    for j, nama in enumerate(g['komponen']):
        r = awal + j
        komp[nama] = {
            'baris': r,
            'u_mentah': s.sel(U, f'E{r}'),
            'pembagi': s.sel(U, f'F{r}'),
            'vi': s.sel(U, f'G{r}'),
            'ui': s.sel(U, f'H{r}'),
            'ci': s.sel(U, f'I{r}'),
            'uici': s.sel(U, f'J{r}'),
            'uici_kuadrat': s.sel(U, f'K{r}'),
            'uici4_per_vi': s.sel(U, f'L{r}'),
            'disertakan': r in disertakan,
            'rumus': {
                'u_mentah': s.rumus(U, f'E{r}'),
                'pembagi': s.rumus(U, f'F{r}'),
                'vi': s.rumus(U, f'G{r}'),
                'ui': s.rumus(U, f'H{r}'),
                'ci': s.rumus(U, f'I{r}'),
            },
        }
        k = komp[nama]
        pem.banding(f'uici {U}!J{r}', k['ui']['nilai'] * k['ci']['nilai'], k['uici']['nilai'])
        pem.banding(f'(uici)^2 {U}!K{r}', k['uici']['nilai'] ** 2, k['uici_kuadrat']['nilai'])
        pem.banding(f'(uici)^4/vi {U}!L{r}', k['uici']['nilai'] ** 4 / k['vi']['nilai'], k['uici4_per_vi']['nilai'])
    t = g['total']
    agregat = {
        'jumlah_uici': s.sel(U, f'J{t}'),
        'jumlah_uici_kuadrat': s.sel(U, f'K{t}'),
        'sigma_uici4_per_vi': s.sel(U, f'L{t}'),
        'uc': s.sel(U, f'J{t + 1}'),
        'veff': s.sel(U, f'J{t + 2}'),
        'k': s.sel(U, f'J{t + 3}'),
        'u_hitung': s.sel(U, f'J{t + 4}'),
        'cmc': s.sel(U, f'J{t + 5}'),
        'u_cetak': s.sel(U, f'J{t + 6}'),
        'rumus': {
            'rentang_dijumlahkan': sorted(disertakan),
            'veff': s.rumus(U, f'J{t + 2}'),
            'k': s.rumus(U, f'J{t + 3}'),
            'u_cetak': s.rumus(U, f'J{t + 6}'),
        },
    }
    # Pemeriksa rantai: komponen yang disertakan -> uc -> v_eff -> k -> U -> max(U; CMC).
    ikut = [komp[n] for n in g['komponen'] if komp[n]['disertakan']]
    rk = rantai([(c['ui']['nilai'], c['ci']['nilai'], c['vi']['nilai']) for c in ikut])
    pem.banding(f'uc {U}!J{t + 1}', rk['uc'], agregat['uc']['nilai'])
    pem.banding(f'v_eff {U}!J{t + 2}', rk['veff'], agregat['veff']['nilai'])
    pem.banding(f'k {U}!J{t + 3}', rk['k'], agregat['k']['nilai'], rel=0.0, mutlak=1e-9)
    pem.banding(f'U {U}!J{t + 4}', rk['u_hitung'], agregat['u_hitung']['nilai'], rel=1e-9)
    pem.banding(f'U sertifikat {U}!J{t + 6}', max(rk['u_hitung'], agregat['cmc']['nilai']), agregat['u_cetak']['nilai'], rel=1e-9)
    return komp, agregat


def spektro(path):
    sha, wf, wv = buka(path)
    s = Sumber(wf, wv)
    pem = Pemeriksa()
    grup = {}
    for nama, g in GRUP.items():
        komp, agregat = budget_grup(s, g, pem)
        titik = titik_grup(s, g, pem)
        sd_maks = s.sel(P, g['maks'])
        sd_titik = [t['standar_deviasi']['nilai'] for t in titik]
        # M36/M49/M67 = MAX(...): periksa terhadap STDEV tiap titik (rentang dibaca dari rumusnya).
        rumus_maks = s.rumus(P, g['maks'])
        m = re.fullmatch(r'=MAX\(M(\d+):M(\d+)\)', rumus_maks or '')
        if not m:
            raise SystemExit(f'BERHENTI: rumus {P}!{g["maks"]} tak dikenal: {rumus_maks!r}')
        a, b = int(m.group(1)), int(m.group(2))
        dalam = [t['standar_deviasi']['nilai'] for t, r in zip(titik, g['perh']) if a <= r <= b]
        pem.banding(f'MAX STDEV {P}!{g["maks"]}', max(dalam), sd_maks['nilai'])
        titik_di_luar_maks = [t['titik_ke'] for t, r in zip(titik, g['perh']) if not (a <= r <= b)]
        grup[nama] = {
            'satuan': g['satuan'],
            'titik': titik,
            'stdev_maks': {
                **sd_maks,
                'rumus': rumus_maks,
                'rentang_baris_perhitungan': [a, b],
                # Titik yang BUKAN bagian rentang MAX di Excel (M36 melewati baris pertama).
                'titik_di_luar_rentang_maks': titik_di_luar_maks,
                'maks_semua_titik': max(sd_titik),
            },
            'komponen': komp,
            'agregat': agregat,
        }
        grup[nama]['konstanta'] = {
            'u_standar': s.sel(*g['u_standar']),
            'cmc': s.sel(*g['cmc']),
            'resolusi': s.sel(*g['resolusi']),
        }
        grup[nama]['cetak'] = {
            'u95': cetak(s, S, g['u_cetak']),
            'k': cetak(s, S, g['k_cetak']),
        }
    # Pita CMC (teks rentang) dan lebar celah spectral bandwidth.
    celah = {
        'q29': s.sel(U, 'Q29'),
        'q30': s.sel(U, 'Q30'),
        'rumus_q30': s.rumus(U, 'Q30'),
    }
    # Konstanta dan rumus yang menjadi dasar SELISIH/MENUNGGU (hanya teks rumus; tanggal/nomor tidak disalin).
    catatan_sel = {
        'r2_transmitan': {**cetak(s, S, 'R48'), 'rumus': s.rumus(S, 'R48')},
        'tanggal_terbit_sertifikat_rumus': {'sel': f'{S}!B70', 'rumus': s.rumus(S, 'B70')},
        'tanggal_terbit_input_rumus': {'sel': f'{I}!D80', 'rumus': s.rumus(I, 'D80')},
        'panjang_gelombang_transmitan': s.sel(I, 'F66'),
    }
    hasil = {
        'sumber': {'berkas': os.path.basename(BERKAS), 'sha256_12': sha[:12]},
        'rekonstruksi': {
            'toleransi_relatif': TOL_REKONSTRUKSI,
            'jumlah_banding': pem.jumlah,
            'rasio_selisih_terhadap_batas_maks': pem.rasio_maks,
        },
        'grup': grup,
        'celah_spectral_bandwidth': celah,
        'catatan_sel': catatan_sel,
    }
    print(f'spectrophotometer: ok, {pem.jumlah} banding, rasio selisih/batas maks {pem.rasio_maks:.3f}')
    return hasil


def tulis(nama, kunci, isi):
    kepala = {
        '_digenerate_oleh': 'docs/skrip/gen-fixture-paritas-spectrophotometer.py',
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
        hasil = spektro(os.path.join(sumber, BERKAS))
    except SandiTidakAda as e:
        raise SystemExit(f'BERHENTI: variabel lingkungan {e} tidak ada.')

    tulis('spectrophotometer.json', 'workbook', hasil)
    print('selesai:', KELUAR)


if __name__ == '__main__':
    main()

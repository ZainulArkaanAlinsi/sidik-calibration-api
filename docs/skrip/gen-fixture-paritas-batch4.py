"""Bangun tests/Fixtures/paritas/<alat>.json batch ke-4 dari workbook master.

Bahan uji PARITAS aplikasi-lawan-Excel batch alat ke-4 (`tests/Feature/Paritas/`):
MASUKAN yang diketik di workbook dan NILAI tiap tahap hitungan Excel, masing-masing
dengan alamat sel sumbernya (`SHEET!SEL`), disalin apa adanya dari nilai-cache
workbook. Keluarannya BUKAN keluaran PHP dan tidak boleh disunting dengan tangan.
Berdampingan dengan `gen-fixture-paritas-analitik-batch2.py`; berkas itu tidak
diubah, helper-nya sengaja disalin supaya tiap generator berdiri sendiri.

## Aturan keras

- Sandi workbook HANYA dari variabel lingkungan `SIDIK_MASTER_SANDI`, dibaca ke
  memori. Tidak ada salinan terbuka-kunci di disk; sandi tidak dicetak, tidak
  ditulis ke fixture, dan tidak dimasukkan ke pesan galat (hanya tipe galat).
- Fixture TIDAK memuat nama/alamat pelanggan, nomor sertifikat, nomor order,
  maupun nama teknisi: skrip hanya membaca sel angka yang disebut di bawah.
- Angka acuan = nilai-cache sel. Pengecualian (rekonstruksi rantai dari sel dasar,
  untuk memastikan pembacaan kami atas rumus benar) ditandai di fixture (`sumber`).
- Sebelum menulis, skrip menghitung ulang rantai `PERHITUNGAN U95%` dari sel dasarnya
  (komponen -> uc -> v_eff -> k -> U -> max(U; CMC)) dan membandingkannya ke
  nilai-cache; selisih relatif terbesar dicatat di fixture
  (`rekonstruksi.selisih_relatif_maks_terhadap_cache`). Di atas 1e-11 skrip
  berhenti: berarti pembacaannya (bukan workbook) yang salah.

## Pakai

    py -3.12 -I -X utf8 docs/skrip/gen-fixture-paritas-batch4.py <folder-Downloads> [alat ...]

`<folder-Downloads>` berisi `EXEL CALIBRATION SIDIK\\` dengan workbook master tiap alat
(lihat `BERKAS`). Tanpa nama alat = semua alat yang terdaftar di `GENERATOR`.
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
    'do_meter': 'EXEL CALIBRATION SIDIK/Master Olah Data_DO Meter.xlsm',
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
            for x in nama_komponen if komp[x]['pembagi']['nilai'] and x not in kecuali_ui),
    )
    return komp, agregat, selisih


def kondisi(s, awal, akhir, perh, kolom, th_sel, sert=None):
    """Blok KONDISI LINGKUNGAN satu besaran (suhu atau RH), seperti sel PERHITUNGAN.

    kolom: huruf kolom (awal, akhir, average, indexed, koreksi, delta, u95_th, u95_sert).
    `indexed_value_tempelan`: True bila sel itu ANGKA yang diketik, bukan rumus.
    """
    r = perh['baris']
    out = {
        'masukan_diketik': {'awal': s.sel(I, awal), 'akhir': s.sel(I, akhir)},
        'excel_awal': s.sel(P, f"{kolom['awal']}{r}"),
        'excel_akhir': s.sel(P, f"{kolom['akhir']}{r}"),
        'average': s.sel(P, f"{kolom['average']}{r}"),
        'indexed_value': s.sel(P, f"{kolom['indexed']}{r}"),
        'indexed_value_tempelan': s.rumus(P, f"{kolom['indexed']}{r}") is None,
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


def sel_galat(s, sheet, sel):
    """Sel yang di-cache Excel sebagai galat (`#DIV/0!`, `#REF!`): teksnya, bukan angka."""
    v = s.nilai(sheet, sel)
    return {'sel': f'{sheet}!{sel}', 'nilai_teks': v if isinstance(v, str) else None, 'rumus': s.rumus(sheet, sel)}


# ----------------------------------------------------------------------------
# DO Meter (mg/L) — satu titik 8,77 mg/L
# ----------------------------------------------------------------------------
KOMP_DO = ['ketidakpastian_standar', 'resolusi_alat', 'ketidakpastian_temperature', 'faktor_pengenceran', 'pengulangan_pembacaan']


def do_meter(path):
    sha, wf, wv = buka(path)
    s = Sumber(wf, wv)
    komp, agg, rk = blok_budget(s, KOL_MERATA, 23, KOMP_DO)
    if rk > TOL_REKONSTRUKSI:
        raise SystemExit(f'do_meter: rekonstruksi menyimpang dari cache ({rk:.3e}); berhenti.')

    titik = {
        'nama': '8.77 mg/L',
        'masukan': {
            'titik_ukur': s.sel(I, 'G32'),
            'pembacaan_sesudah': s.deret(I, [f'G{r}' for r in range(43, 48)]),
            'suhu_sesudah_c': s.deret(I, [f'I{r}' for r in range(43, 48)]),
            'pembacaan_sebelum': s.deret(I, [f'G{r}' for r in range(34, 39)]),
            'suhu_sebelum_c': s.deret(I, [f'I{r}' for r in range(34, 39)]),
        },
        'standar': {
            'nilai_standar': s.sel(P, 'G35'),
            'rata_rata_uut': s.sel(P, 'G42'),
            'stdev_sampel': s.sel(P, 'G44'),
            'koreksi_lembar_uut_minus_standar': s.sel(P, 'G43'),
        },
        # Sampel: blok sebelum = sesudah adjustment (angka sama), jadi uji tidak bisa
        # membedakan blok mana yang dibaca aplikasi. Dicatat agar tidak disangka terbukti.
        'sebelum_adjustment': {
            'rata_rata': s.sel(P, 'G29'), 'koreksi_lembar': s.sel(P, 'G30'), 'stdev_sampel': s.sel(P, 'G31'),
        },
        'komponen': komp,
        'agregat': agg,
        'u95_standar_database': s.sel(DB, 'V13'),
        'cmc_database': s.sel(DB, 'S5'),
        'cetak': {
            'standar': cetak(s, S, 'E24'), 'uut': cetak(s, S, 'N24'),
            'koreksi': cetak(s, S, 'S24'), 'u95': cetak(s, S, 'Q25'),
        },
    }

    kolom_env = dict(awal='E', akhir='F', average='G', indexed='H', koreksi='N', delta='M', u95_th='O', u95_sert='Q')
    kond = {
        'th': s.teks(P, 'E16'),
        'suhu': kondisi(s, 'E21', 'F21', {'baris': 14}, kolom_env, 'E16', sert={'nilai': 'U10', 'u95': 'X10'}),
        'kelembaban': kondisi(s, 'E22', 'F22', {'baris': 15}, kolom_env, 'E16', sert={'nilai': 'U11', 'u95': 'X11'}),
        'rumus_u95_th_rh': {'sel': f'{P}!O15', 'rumus': s.rumus(P, 'O15')},
        'format_cetak': {'suhu': s.format(S, 'U10'), 'rh': s.format(S, 'U11')},
    }

    hasil = {
        'label': 'DO Meter (mg/L)',
        'berkas': os.path.basename(path),
        'sha256_12': sha[:12],
        'masukan': {'resolusi_alat': s.sel(I, 'E16')},
        'konstanta': {
            'u95_termometer_c': s.sel(DB, 'V14'), 'u95_sensor_c': s.sel(DB, 'V15'), 'u_temperature': s.sel(U, 'AE5'),
            'u95_micropipette_ul': s.sel(DB, 'V17'), 'u95_labu_ukur_ml': s.sel(DB, 'V18'),
            'uc_pengenceran_ml': s.sel(U, 'AE13'), 'uc_pengenceran_l': s.sel(U, 'M26'),
            'ci_suhu': s.sel(U, 'W25'), 'ci_pengenceran': s.sel(U, 'W26'),
            'rumus_ci_suhu': {'sel': f'{U}!W25', 'rumus': s.rumus(U, 'W25')},
            'rumus_ci_pengenceran': {'sel': f'{U}!W26', 'rumus': s.rumus(U, 'W26')},
            'rumus_u_pengenceran': {'sel': f'{U}!M26', 'rumus': s.rumus(U, 'M26')},
            # Resolusi komponen: M24 = G6*0,5 dan G6 membaca isian INPUT DATA (angka diketik, P-25).
            'rumus_resolusi_ui': {'sel': f'{U}!M24', 'rumus': s.rumus(U, 'M24')},
            'rumus_resolusi_sumber': {'sel': f'{U}!G6', 'rumus': s.rumus(U, 'G6')},
        },
        'titik': [titik],
        'cetak_k': cetak(s, S, 'V26'),
        'kondisi_lingkungan': kond,
        # Blok %O2 (20,9 %): seluruhnya galat di cache workbook. Aplikasi tidak mengolahnya.
        'blok_o2_galat': {
            'komponen_suhu_o2': sel_galat(s, U, 'M40'),
            'uc_o2': sel_galat(s, U, 'AB43'),
            'k_o2': sel_galat(s, U, 'AB45'),
            'u_o2': sel_galat(s, U, 'AB46'),
            'u_sertifikat_o2': sel_galat(s, U, 'AB48'),
            'u95_cetak_o2': sel_galat(s, S, 'Q20'),
            'k_cetak_o2': sel_galat(s, S, 'W21'),
            # Titik %O2 (20,9 %) hanya punya titik ukur; kolom pembacaannya kosong di INPUT DATA,
            # jadi PERHITUNGAN merata-ratakan sel kosong menjadi 0 dan koreksinya -20,9.
            'titik_ukur_o2': s.sel(I, 'J41'),
            'pembacaan_o2_sesudah_kosong': [s.nilai(I, f'J{r}') is None for r in range(43, 48)],
            'rata_rata_o2_excel': s.sel(P, 'J42'),
            'koreksi_o2_excel': s.sel(P, 'J43'),
            # Yang TERCETAK Excel untuk blok %O2 (SERTIFIKAT baris 19): standar 20,9, instrumen 0, koreksi 20,9.
            'cetak_o2': {
                'standar': cetak(s, S, 'E19'), 'instrumen': cetak(s, S, 'N19'), 'koreksi': cetak(s, S, 'S19'),
            },
        },
        'rekonstruksi': {
            'selisih_relatif_maks_terhadap_cache': rk,
            'catatan': 'komponen -> uc -> veff -> k (t 0,975; floor(veff)) -> U -> max(U, CMC) dihitung ulang dari sel komponen',
        },
        'tanggal_terbit': {
            'sertifikat': {'sel': f'{S}!B39', 'rumus': s.rumus(S, 'B39')},
            'input_data': {'sel': f'{I}!D52', 'rumus': s.rumus(I, 'D52')},
        },
    }
    print(f'do_meter: ok, rekonstruksi {rk:.2e}')
    return hasil


GENERATOR = {
    'do_meter': ('do-meter.json', do_meter),
}


def tulis(nama, kunci, isi):
    kepala = {
        '_digenerate_oleh': 'docs/skrip/gen-fixture-paritas-batch4.py',
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

#!/usr/bin/env python3
"""Generate `database/data/tabel-standar-sieve.json` dari master lab.

Sumber: ekspor CSV `Master Olah Data_Sieve Mesh.xlsm` (password spirit285),
sheet `STANDAR_KALIBRATOR` dan `DATABASE`. Seluruh sel yang dibaca di sini
KONSTANTA (bukan array formula), jadi nilai CSV-nya sama dengan yang tersimpan
di workbook — xlsm tidak perlu dibuka.

## Tabel_MPE disalin APA ADANYA, lalu DITANDAI

`STANDAR_KALIBRATOR!B41:N136` (96 baris, ASTM E11 / ISO 3310-1) memuat salah
ketik: 0,080 mm Ø kawat preferred 0,56 (di luar pita max/min miliknya sendiri),
1 mm tertulis 18000 µm, 10 mm tertulis 0,279 inch. Salah ketik itu TIDAK
dibetulkan di sini — membetulkannya diam-diam berarti JSON menyimpang dari
master tanpa jejak, dan yang berhak memutuskan angka yang benar itu lab
(`docs/pertanyaan-lab-sieve.md`). Yang ditulis: nilainya apa adanya + daftar
`tanda` berisi kolom mana yang janggal. `TabelStandarSieve` memblokir baris yang
kolom JANGGALNYA dipakai hitungan.

Aturan tandanya mekanis, bukan penilaian satu per satu:
  - `ukuran_um`   : |µm − mm×1000| > 1 %           (kolom tampilan)
  - `ukuran_inch` : |inch − mm/25,4| > 10 %         (kolom tampilan; ASTM
                    membulatkan penanda inch sampai ±3 %, jadi 10 % bukan pembulatan)
  - `kawat_preferred_mm` : preferred di luar [min, max] miliknya sendiri
  - `kawat_min_max` : min > max

Jalankan:  python docs/skrip/gen-tabel-standar-sieve.py [dir-csv]
"""
import csv
import json
import pathlib
import sys

AKAR = pathlib.Path(__file__).resolve().parents[2]
CSV_DIR = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else (
    AKAR / "Project-PT-Sidik/alat-alat-Pt-Sidik/Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Sieve_Mesh"
)
KELUARAN = AKAR / "database/data/tabel-standar-sieve.json"


def baca(nama):
    with open(CSV_DIR / nama, encoding="utf-8-sig", newline="") as f:
        return list(csv.reader(f))


def sel(baris, ref):
    """Sel bergaya Excel. csv.reader menggabung sel ber-enter jadi satu rekaman,
    jadi indeks rekaman = nomor baris Excel − 1 — DIPERIKSA lewat [pastikan]."""
    kol = 0
    i = 0
    while ref[i].isalpha():
        kol = kol * 26 + (ord(ref[i].upper()) - 64)
        i += 1
    r = int(ref[i:]) - 1
    return baris[r][kol - 1].strip() if r < len(baris) and kol - 1 < len(baris[r]) else ""


def pastikan(baris, ref, harap):
    if sel(baris, ref) != harap:
        raise SystemExit(f"{ref} berisi {sel(baris, ref)!r}, bukan {harap!r} — bentuk master berubah.")


def angka_atau_teks(v):
    """`'-'` dan `'all'` di Tabel_MPE itu makna, bukan sel kosong — disimpan
    sebagai teks supaya pembacanya wajib memutuskan artinya, bukan membaca 0."""
    try:
        return float(v)
    except ValueError:
        return v


K = baca("STANDAR_KALIBRATOR.csv")
DB = baca("DATABASE.csv")

pastikan(K, "B39", "Sieve Standard Size")
pastikan(K, "J40", "±Y (mm)")
pastikan(K, "F10", "Koreksi")
pastikan(K, "B7", "Resolusi")
pastikan(K, "J7", "Resolusi")
pastikan(K, "N10", "Koreksi")
pastikan(DB, "S13", "Digital Caliper")
pastikan(DB, "S14", "Digital Microscope")

mpe = []
for r in range(41, 137):
    ukuran = float(sel(K, f"B{r}"))
    baris = {
        "ukuran_mm": ukuran,
        "sieve_no": sel(K, f"C{r}"),
        "ukuran_um": angka_atau_teks(sel(K, f"D{r}")),
        "ukuran_inch": angka_atau_teks(sel(K, f"E{r}")),
        "min_opening": {
            "compliance": angka_atau_teks(sel(K, f"F{r}")),
            "inspection": angka_atau_teks(sel(K, f"G{r}")),
            "calibration": angka_atau_teks(sel(K, f"H{r}")),
        },
        "x_mm": angka_atau_teks(sel(K, f"I{r}")),
        "y_mm": angka_atau_teks(sel(K, f"J{r}")),
        "max_stdev_mm": angka_atau_teks(sel(K, f"K{r}")),
        "kawat_preferred_mm": angka_atau_teks(sel(K, f"L{r}")),
        "kawat_max_mm": angka_atau_teks(sel(K, f"M{r}")),
        "kawat_min_mm": angka_atau_teks(sel(K, f"N{r}")),
        "tanda": [],
    }
    um, inch = baris["ukuran_um"], baris["ukuran_inch"]
    if isinstance(um, float) and abs(um - ukuran * 1000) > 0.01 * ukuran * 1000:
        baris["tanda"].append("ukuran_um")
    if isinstance(inch, float) and abs(inch - ukuran / 25.4) > 0.10 * (ukuran / 25.4):
        baris["tanda"].append("ukuran_inch")
    pref, mx, mn = baris["kawat_preferred_mm"], baris["kawat_max_mm"], baris["kawat_min_mm"]
    if all(isinstance(x, float) for x in (pref, mx, mn)):
        if mn > mx:
            baris["tanda"].append("kawat_min_max")
        elif not (mn <= pref <= mx):
            baris["tanda"].append("kawat_preferred_mm")
    mpe.append(baris)

ukuran_semua = [b["ukuran_mm"] for b in mpe]
if len(set(ukuran_semua)) != len(ukuran_semua):
    raise SystemExit("Tabel_MPE memuat ukuran ganda — pencarian persis jadi ambigu.")


def koreksi(kol_nominal, kol_tunjuk, kol_koreksi, r0, r1):
    keluar = []
    for r in range(r0, r1 + 1):
        n = sel(K, f"{kol_nominal}{r}")
        if n == "":
            continue
        keluar.append({
            "nilai_standar_mm": float(n),
            "penunjukan_mm": float(sel(K, f"{kol_tunjuk}{r}")),
            "koreksi_mm": float(sel(K, f"{kol_koreksi}{r}")),
        })
    return keluar


def standar(r, kol_resolusi):
    return {
        "nama": sel(DB, f"S{r}"),
        "merk_tipe": sel(DB, f"T{r}"),
        "seri": sel(DB, f"U{r}"),
        "traceability": sel(DB, f"V{r}"),
        "tanggal_kalibrasi": sel(DB, f"W{r}")[:10],
        "interval_tahun": int(float(sel(DB, f"X{r}"))),
        "berlaku_sampai": sel(DB, f"Y{r}")[:10],
        "resolusi_mm": float(sel(K, kol_resolusi)),
    }


isi = {
    "_sumber": "Master Olah Data_Sieve Mesh.xlsm (sheet STANDAR_KALIBRATOR & DATABASE), password spirit285. "
               "Tabel_MPE disalin apa adanya; kolom yang janggal DITANDAI di `tanda`, tidak dibetulkan.",
    "_digenerate_oleh": "docs/skrip/gen-tabel-standar-sieve.py",
    "standar": {
        # DATABASE!R13:Z14 + resolusi STANDAR_KALIBRATOR!C7 / K7.
        "caliper": standar(13, "K7"),
        "mikroskop": standar(14, "C7"),
    },
    "koreksi": {
        # Kolom KOREKSI yang benar (N untuk caliper, F untuk mikroskop). Master
        # memakai VLOOKUP kolom 3 — kolom M/E yang KOSONG — jadi koreksinya
        # selalu 0 di workbook. Lihat SieveCalculator.
        "caliper": koreksi("K", "L", "N", 12, 21),
        "mikroskop_x": koreksi("C", "D", "F", 12, 22),
        "mikroskop_y": koreksi("C", "D", "F", 26, 36),
    },
    "u95_sertifikat_mm": {
        "caliper": float(sel(K, "O12")),
        "mikroskop_x": float(sel(K, "G12")),
        "mikroskop_y": float(sel(K, "G26")),
    },
    "cmc_master": [
        # DATABASE!S5:T6 — pemilihnya `PERHITUNGAN U95%!AC22`.
        {"label": sel(DB, "S5"), "standar": "mikroskop", "ukuran_min_mm": 0.0, "ukuran_maks_mm": 2.0, "u95_mm": float(sel(DB, "T5"))},
        {"label": sel(DB, "S6"), "standar": "caliper", "ukuran_min_mm_eksklusif": 2.0, "ukuran_maks_mm": None, "u95_mm": float(sel(DB, "T6"))},
    ],
    "cmc_lampiran": [
        # database/data/kemampuan-kalibrasi.json no. 33 "Sieve".
        {"label": "45 ~ 4000 µm", "ukuran_min_mm": 0.045, "ukuran_maks_mm": 4.0, "u95_mm": 0.00433},
        {"label": "4 ~ 100 mm", "ukuran_min_mm": 4.0, "ukuran_maks_mm": 100.0, "u95_mm": 0.02},
    ],
    # Angka telanjang di dalam rumus master — tidak ada di nilai CSV, jadi
    # ditulis di sini berikut sel asalnya.
    "konstanta": {
        "suhu_acuan_c": 20.0,                         # PERHITUNGAN U95%!X15 = D4*(AE5-20)
        "delta_alpha_per_c": 1e-5,                    # PERHITUNGAN U95%!N15
        "u_alpha_pengali": {"mikroskop": 1.0, "caliper": 2.0},  # catatan Y6/Y7
        "pembagi_sertifikat": {"mikroskop": 2.02, "caliper": 2.0},  # Q11
        "vi_sertifikat": 200,                         # S11
        "daya_baca_pengali": 0.5,                     # N12 = 0.5*E24
        "vi_daya_baca": 1000000,                      # S12
        "geometri_mm": 0.0005,                        # N13 = 0.5/1000
        "vi_geometri": 50,                            # S13
        "vi_suhu": 50,                                # S14, S15
        "pembagi_pengulangan": 6,                     # Q16 = 6 (bukan √6)
        "vi_pengulangan": 5,                          # S16
        "jumlah_opening_pengulangan": 6,              # INPUT DATA!H89:N91 = opening 1..6
        "k": 2.0,                                     # AC20 / AC37 / AC55
    },
    "mpe": mpe,
}

KELUARAN.write_text(json.dumps(isi, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")
ditandai = [(b["ukuran_mm"], b["tanda"]) for b in mpe if b["tanda"]]
print(f"Tulis {KELUARAN.relative_to(AKAR)} — {len(mpe)} baris Tabel_MPE, ditandai: {ditandai}")

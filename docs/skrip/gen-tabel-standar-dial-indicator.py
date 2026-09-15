#!/usr/bin/env python3
"""Generate `database/data/tabel-standar-dial-indicator.json` dari master lab.

Sumber: ekspor CSV `Master Olah Data_Dial Indicator.xlsm` (password spirit285).

Yang ditulis cuma yang KHAS Dial Indicator — pita CMC, identitas standar, dan
tetapan budget yang di master hidup sebagai angka telanjang di dalam rumus.
Tabel balok ukurnya TIDAK disalin: kepingnya sama persis dengan milik
Micrometer (satu set fisik Metrology GB-9122-0 S/N 160006), jadi mesin hitungnya
membaca `tabel-standar-micrometer.json`. Skrip ini MENGADU kedua tabel itu dan
menolak menulis kalau ada satu keping pun yang berbeda — dua salinan untuk satu
set balok ukur berarti yang satu diam-diam basi begitu set itu dikalibrasi ulang.

Jalankan:  python docs/skrip/gen-tabel-standar-dial-indicator.py [dir-csv]
"""
import csv
import json
import math
import pathlib
import sys

AKAR = pathlib.Path(__file__).resolve().parents[2]
CSV_DIR = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else (
    AKAR / "Project-PT-Sidik/alat-alat-Pt-Sidik/Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Dial_Indicator"
)
KELUARAN = AKAR / "database/data/tabel-standar-dial-indicator.json"
MICROMETER = AKAR / "database/data/tabel-standar-micrometer.json"


def baca(nama):
    with open(CSV_DIR / nama, encoding="utf-8-sig", newline="") as f:
        return list(csv.reader(f))


def sel(baris, ref):
    """Sel bergaya Excel (`T5`). csv.reader menggabung sel ber-enter jadi satu
    rekaman, jadi indeks rekaman = nomor baris Excel − 1 — dan itu DIPERIKSA
    lewat penanda di [pastikan], bukan dipercaya."""
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


db = baca("DATABASE.csv")
gb = baca("Standar_GB.csv")

pastikan(db, "T3", "CMC (µm)")
pastikan(db, "S13", "Gauge Block Standard")

# Keping balok ukur: Standar_GB!Q10:R132 (defined name `Nominal_mm`). Baris ber-
# nominal kosong itu artefak rumus `=E47` yang menunjuk sel kosong — dibuang.
keping = {}
for r in range(10, 133):
    n, v = sel(gb, f"Q{r}"), sel(gb, f"R{r}")
    if n == "":
        continue
    keping[float(n)] = float(v)

mikro = {float(k): float(v) for k, v in json.load(open(MICROMETER, encoding="utf-8"))["balok_ukur"].items()}
beda = sorted(set(keping) ^ set(mikro)) + [n for n in keping if n in mikro and abs(keping[n] - mikro[n]) > 1e-9]
if beda:
    raise SystemExit(f"Balok ukur Dial Indicator beda dari tabel Micrometer pada nominal {beda} — "
                     "set fisiknya satu, jadi putuskan dulu mana sertifikat yang berlaku.")

pita = []
for kode, r, maks in (("A", 5, 25.0), ("B", 6, 50.0), ("C", 7, 100.0), ("D", 8, 300.0)):
    pita.append({
        "kode": kode,
        "label": sel(db, f"S{r}"),
        "kapasitas_maks_mm": maks,
        # µm di DATABASE!T, dibagi 1000 oleh `PERHITUNGAN U95%!AA20`.
        "u95_mm": float(sel(db, f"T{r}")) / 1000,
    })

isi = {
    "_sumber": "Master Olah Data_Dial Indicator.xlsm (sheet DATABASE, Standar_GB, PERHITUNGAN U95%), password spirit285. "
               "Balok ukur dibaca dari tabel-standar-micrometer.json — set fisik yang sama, sudah diadu skrip ini.",
    "_digenerate_oleh": "docs/skrip/gen-tabel-standar-dial-indicator.py",
    "standar": {
        "nama": sel(db, "S13"),
        "merk_tipe": sel(db, "T13"),
        "seri": sel(db, "U13"),
        "traceability": sel(db, "V13"),
        "tanggal_kalibrasi": sel(db, "W13")[:10],
        "interval_tahun": int(float(sel(db, "X13"))),
    },
    "cmc": pita,
    # Angka telanjang di dalam rumus master — tidak ada di nilai CSV, jadi
    # ditulis di sini berikut sel asalnya.
    "konstanta": {
        "suhu_acuan_c": 20.0,                     # PERHITUNGAN!Q31 = AVERAGE(O31:P60)-20
        "delta_alpha_per_c": 1e-5,                # PERHITUNGAN!V22 = 1/100000
        "u_alpha_pengali": 2.0,                   # PERHITUNGAN!W22 = 2*V22
        "pembagi_muai": math.sqrt(6),             # PERHITUNGAN U95%!N9 = SQRT(6), walau J9 "rect."
        "alpha_per_c": 1.2e-6,                    # PERHITUNGAN!S31 & U31 = 1.2/1000000
        "pengulangan_pembagi_n": 5,               # PERHITUNGAN U95%!N5 = SQRT(5), Q5 = 5-1
        "drift_a_um": 0.02,                       # PERHITUNGAN U95%!K10
        "drift_b_um_per_mm": 0.00025,             # PERHITUNGAN U95%!K10
        "drift_pembagi_umur": 12,                 # PERHITUNGAN U95%!K10 ((X11-W13)/12), selisihnya HARI
        "wringing_um_per_keping": 0.05,           # PERHITUNGAN U95%!K11 = SQRT(B61*0.05^2)/1000
        "tegak_lurus_mm": 0.002,                  # PERHITUNGAN U95%!K12
        "meja_granit_mm": 0.0062,                 # PERHITUNGAN U95%!K13 = 6.2/1000
        "vi_type_b": 200,                         # PERHITUNGAN U95%!Q6:Q14
    },
}

KELUARAN.write_text(json.dumps(isi, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")
print(f"Tulis {KELUARAN.relative_to(AKAR)} — {len(pita)} pita CMC, {len(keping)} keping cocok dengan Micrometer.")

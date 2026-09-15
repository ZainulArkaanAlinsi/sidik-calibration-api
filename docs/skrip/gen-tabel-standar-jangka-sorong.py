#!/usr/bin/env python3
"""Generate `database/data/tabel-standar-jangka-sorong.json` dari master lab.

Sumber: ekspor CSV `Master Olah Data Caliper 2026 (std caliper checker+gb).xlsm`
(password spirit285).

## Dua standar, dua perlakuan

- **Caliper Checker** (Outside & Inside) TIDAK disalin. Tabelnya identik nilai
  demi nilai dengan `tabel-standar-height-gauge.json` — satu keping fisik
  Metrology CMG-9060C S/N 800035 — jadi mesin hitungnya membaca tabel itu. Skrip
  ini MENGADU keduanya dan berhenti kalau ada satu baris pun yang berbeda.
- **Balok ukur** (Depth) DISALIN sebagai snapshot milik workbook ini sendiri.
  Set nominalnya sama dengan Micrometer, tapi nilai 200 mm BERBEDA
  (199,99924 di sini, 200,00017 di Micrometer): dua sertifikat keping yang
  berbeda. Memilih satu sebagai "yang benar" berarti diam-diam menggeser angka
  yang sudah tercetak di salah satu sertifikat pelanggan.

Jalankan:  python docs/skrip/gen-tabel-standar-jangka-sorong.py [dir-csv]
"""
import csv
import json
import math
import pathlib
import sys

AKAR = pathlib.Path(__file__).resolve().parents[2]
CSV_DIR = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else (
    AKAR / "Project-PT-Sidik/alat-alat-Pt-Sidik/Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_"
)
KELUARAN = AKAR / "database/data/tabel-standar-jangka-sorong.json"
HEIGHT_GAUGE = AKAR / "database/data/tabel-standar-height-gauge.json"


def baca(nama):
    with open(CSV_DIR / nama, encoding="utf-8-sig", newline="") as f:
        return list(csv.reader(f))


def sel(baris, ref):
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


ck = baca("Std_CaliperCek.csv")
gb = baca("Standar_GB.csv")
db = baca("DATABASE.csv")
ks = baca("Perhitungan koef. Sensitivitas.csv")
inp = baca("INPUT DATA.csv")

pastikan(ck, "C2", "Caliper Checker")
pastikan(ck, "C9", "Outside  (mm)")
pastikan(ck, "C22", "Inside  (mm)")
pastikan(db, "S5", "CMC 0-300mm")
pastikan(db, "S13", "Gauge Block Standard")
pastikan(db, "S14", "Caliper Checker")
pastikan(ks, "D1", "CTE")
pastikan(inp, "C109", "Nominal Gauge Block")

# --- Caliper Checker: adu ke tabel Height Gauge -----------------------------
hg = json.load(open(HEIGHT_GAUGE, encoding="utf-8"))


def tabel_ck(mulai):
    return [
        (float(sel(ck, f"C{r}")), float(sel(ck, f"F{r}")), float(sel(ck, f"J{r}")))
        for r in range(mulai, mulai + 10)
    ]


for nama, mulai in (("outside", 10), ("inside", 23)):
    kita = tabel_ck(mulai)
    mereka = [(b["nominal_mm"], b["nilai_terkoreksi_mm"]) for b in hg[nama]]
    if len(kita) != len(mereka) or any(
        abs(a[0] - b[0]) > 1e-9 or abs(a[1] - b[1]) > 1e-9 for a, b in zip(kita, mereka)
    ):
        raise SystemExit(f"Caliper Checker {nama} beda dari tabel Height Gauge — satu keping fisik, "
                         "putuskan dulu sertifikat mana yang berlaku.")
    if any(abs(u - hg["standar"]["u95_um"]) > 1e-9 for _, _, u in kita):
        raise SystemExit(f"U95 Caliper Checker {nama} tidak lagi satu angka — mesin hitung membacanya sebagai satu.")

if sel(ck, "O2")[:10] != hg["standar"]["tanggal_kalibrasi"] or sel(ck, "C3") != hg["standar"]["seri"]:
    raise SystemExit("Identitas/tanggal Caliper Checker beda dari tabel Height Gauge.")

# --- Balok ukur Depth: snapshot sendiri -------------------------------------
# Standar_GB!Q10:S132 (`Nominal_mm`). 91 baris ber-nominal kosong itu artefak
# rumus yang menunjuk sel kosong — dibuang, bukan disalin sebagai keping nol.
keping = []
for r in range(10, 133):
    n = sel(gb, f"Q{r}")
    if n == "":
        continue
    keping.append({
        "nominal_mm": float(n),
        "nilai_terkoreksi_mm": float(sel(gb, f"R{r}")),
        # Kolom S berlabel "(m)" tapi isinya mm: 0,00012 = 0,12 µm keping 1 mm.
        "u95_mm": float(sel(gb, f"S{r}")),
    })

isi = {
    "_sumber": "Master Olah Data Caliper 2026 (std caliper checker+gb).xlsm (sheet Std_CaliperCek, Standar_GB, "
               "DATABASE, Perhitungan koef. Sensitivitas), password spirit285. Caliper Checker dibaca dari "
               "tabel-standar-height-gauge.json — keping fisik yang sama, sudah diadu skrip ini.",
    "_digenerate_oleh": "docs/skrip/gen-tabel-standar-jangka-sorong.py",
    "balok_ukur": {
        "nama": sel(db, "S13"),
        "merk_tipe": sel(db, "T13"),
        "seri": sel(db, "U13"),
        "traceability": sel(db, "V13"),
        "tanggal_kalibrasi": sel(db, "W13")[:10],
        "keping": keping,
    },
    # Lampiran LK-285-IDN no. 35 — Vernier Caliper 0-300 mm, 0,015 mm. Master
    # punya angkanya (DATABASE!T5 = 15 µm) tapi tidak menyambungkannya ke sheet
    # U95 mana pun; lihat pertanyaan lab §2.
    "cmc": {
        "label": sel(db, "S5"),
        "kapasitas_maks_mm": 300.0,
        "u95_mm": float(sel(db, "T5")) / 1000,
    },
    # Tumpukan balok ukur lima baris Depth, dari sesi master (INPUT DATA!C111:C123).
    # HP tidak punya jalur mengirim tumpukan per baris di tabel ber-kunci-bernama,
    # jadi tumpukannya dipatok server — pertanyaan lab §11.
    "tumpukan_depth_mm": [
        [float(sel(inp, f"C{r}")) for r in range(dasar, dasar + 3) if sel(inp, f"C{r}") != ""]
        for dasar in range(111, 124, 3)
    ],
    "konstanta": {
        "suhu_acuan_c": 20.0,                           # PERHITUNGAN!X37 = AVERAGE(V37:W37)-20
        "alpha_per_c": 1.2e-6,                          # PERHITUNGAN!Y37 & Z37
        "u_alpha_caliper_checker_per_c": 2e-6,          # PERHITUNGAN!Z24 = 2*(1/1000000)
        "u_alpha_balok_ukur_per_c": 2e-5,               # PERHITUNGAN!Z28 = 2*(1/100000)
        "pembagi_muai": math.sqrt(6),                   # U95!N9/N29/N48 = SQRT(6), walau berlabel rect.
        "drift_a_um": 0.02,                             # U95!K10
        "drift_b_um_per_mm": 0.00025,                   # U95!K10
        "drift_pembagi_umur": 12,                       # U95!K10 ((X11-W14)/12), selisihnya HARI
        "wringing_um_per_keping": 0.05,                 # U95!K11 = SQRT(B67*0.05^2)/1000
        "geometri_mm": 0.0005,                          # U95!K12
        "efek_mekanik_mm": 0.0005,                      # U95!K13
        "meja_granit_mm": 0.0062,                       # U95!K53 = 6.2/1000 (cuma Depth)
        "vi_type_b": 60,                                # U95!Q7:Q14 (FORM VALIDASI rev.15)
        "vi_resolusi": 1e9,                             # U95!Q6
        "pengulangan_pembagi_n": 10,                    # U95!N5/N24/N43 = SQRT(10), Q = 10-1
        # Depth: ci suhu = CTE x panjang balok 100 mm dari tautan luar [4]
        # ('Perhitungan koef. Sensitivitas'!E4) — ditiru, pertanyaan §6.
        "depth_ci_suhu_panjang_mm": float(sel(ks, "E4")),
        "depth_ci_suhu_cte_per_c": float(sel(ks, "E1")),
        # Depth: ci muai DIKETIK 50 (U95!V48), bukan rumus — ditiru, pertanyaan §6.
        "depth_ci_muai": 50.0,
    },
}

KELUARAN.write_text(json.dumps(isi, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")
print(f"Tulis {KELUARAN.relative_to(AKAR)} — {len(keping)} keping balok ukur; Caliper Checker cocok dengan Height Gauge.")

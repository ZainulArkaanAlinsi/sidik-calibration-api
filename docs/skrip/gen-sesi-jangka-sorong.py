#!/usr/bin/env python3
"""Generate `database/data/sesi-master-jangka-sorong.json` dari master lab.

Yang diambil cuma MASUKAN — identitas sesi, kedua blok Evaluation, kesejajaran,
dan ketiga tabel titik (Outside, Inside, Depth). Hasilnya TIDAK ditempel ke
seeder: dia lahir dari `JangkaSorongProfile::hitungPerGrup()`.

Angka yang tercetak di master ikut disalin ke `_acuan_master` supaya
`JangkaSorongMasterTest` mengadu tiap komponen KETIGA budget tanpa Excel.

## Bacaan Depth dari berkas INI, bukan dari cache master

`PERHITUNGAN!I106:S118` master membaca bacaan Depth dari workbook LAIN
(`[3]` = "Master Olah Data_Jangka Sorong draft.xlsm"). Di 4 dari 5 titik cache
itu berbeda dari `INPUT DATA` berkas ini. Yang diambil di sini `INPUT DATA` —
angka yang benar-benar diketik di lembar ini. Konsekuensinya koreksi Depth
tidak cocok dengan cache master, dan test mengujinya dari masukan lokal.
Budget Depth tetap cocok: tidak satu komponennya pun membaca bacaan titik
(repeatability-nya menunjuk sel kosong — lihat pertanyaan lab §3).

Nama & alamat pelanggan DIGANTI sintetis.

Jalankan:  python docs/skrip/gen-sesi-jangka-sorong.py [dir-csv]
"""
import csv
import json
import pathlib
import sys

AKAR = pathlib.Path(__file__).resolve().parents[2]
CSV_DIR = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else (
    AKAR / "Project-PT-Sidik/alat-alat-Pt-Sidik/Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Caliper_2026__std_caliper_checker_gb_"
)
KELUARAN = AKAR / "database/data/sesi-master-jangka-sorong.json"

# X1, X1', X2, X2', ..., X5, X5' — kolom O dilompati (sel gabungan).
KOLOM_BACAAN = ["F", "G", "H", "I", "J", "K", "L", "M", "N", "P"]
KOLOM_EVALUASI = ["C", "D", "F", "G", "H", "I", "J", "K", "L", "M"]


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


def angka(baris, refs):
    """Sel kosong DILEWATI, bukan dibaca nol."""
    return [float(sel(baris, r)) for r in refs if sel(baris, r) != ""]


inp = baca("INPUT DATA.csv")
hit = baca("PERHITUNGAN.csv")
u95 = baca("PERHITUNGAN U95%.csv")
db = baca("DATABASE.csv")

pastikan(inp, "C39", "Outside Measurement")
pastikan(inp, "C74", "Inside Measurement")
pastikan(inp, "C109", "Nominal Gauge Block")
pastikan(inp, "C130", "Atas")
pastikan(inp, "V22", "Baik")
pastikan(u95, "B43", "repeatability")
pastikan(u95, "B53", "Meja Rata (granite)")


def blok(mulai_input, mulai_hitung, jumlah, dengan_acuan_bacaan=True):
    titik, acuan = [], []
    for n in range(jumlah):
        ri = mulai_input + 3 * n
        rh = mulai_hitung + 3 * n
        nominal = angka(inp, [f"C{ri + k}" for k in range(3)])
        bacaan = angka(inp, [f"{k}{ri}" for k in KOLOM_BACAAN])
        if not nominal and not bacaan:
            continue
        titik.append({"urutan": n + 1, "nominal_mm": nominal, "pembacaan_mm": bacaan})
        a = {"urutan": n + 1, "total_nominal": float(sel(hit, f"H{rh}"))}
        if dengan_acuan_bacaan:
            a.update({
                "rata_rata": float(sel(hit, f"U{rh}")),
                "standar_terkoreksi": float(sel(hit, f"AH{rh}")),
                "simpangan_baku": float(sel(hit, f"AI{rh}")),
                "koreksi": float(sel(hit, f"AJ{rh}")),
            })
        acuan.append(a)
    return titik, acuan


outside, acuan_out = blok(42, 37, 10)
inside, acuan_in = blok(77, 72, 10)
# Depth: acuan bacaan TIDAK disalin — cache master berasal dari workbook lain.
depth, acuan_dep = blok(111, 106, 5, dengan_acuan_bacaan=False)


def komponen(baris):
    return [{
        "baris": r,
        "keterangan": sel(u95, f"B{r}"),
        "u": float(sel(u95, f"K{r}")),
        "pembagi": float(sel(u95, f"N{r}")),
        "ui": float(sel(u95, f"T{r}")),
        "ci": float(sel(u95, f"V{r}")),
        "vi": float(sel(u95, f"Q{r}")),
    } for r in baris]


def agregat(r_uc):
    return {
        "uc": float(sel(u95, f"AA{r_uc}")),
        "veff": float(sel(u95, f"AA{r_uc + 1}")),
        "k": float(sel(u95, f"AA{r_uc + 2}")),
        "u_diperluas": float(sel(u95, f"AA{r_uc + 3}")),
    }


isi = {
    "_catatan": "Sesi contoh dari Master Olah Data Caliper 2026 (std caliper checker+gb).xlsm (ber-password). "
                "Yang ditanam JangkaSorongSeeder cuma MASUKANNYA; `_acuan_master` dipakai JangkaSorongMasterTest.",
    "_digenerate_oleh": "docs/skrip/gen-sesi-jangka-sorong.py",
    "_sesi": {
        "nama_alat": sel(inp, "E10"),
        "merk": sel(inp, "E11"),
        "model": sel(inp, "E12"),
        "serial": sel(inp, "E13"),
        "satuan_alat": sel(inp, "Y13"),
        "rentang": sel(inp, "E14"),
        "kapasitas_mm": float(sel(inp, "E15")),
        "resolusi_mm": float(sel(inp, "E16")),
        # Sintetis — master memuat pelanggan sungguhan.
        "pelanggan": "Laboratorium Bahan Jalan Contoh",
        "alamat": "Jl. Contoh No. 27, Kota Contoh",
        "tanggal_terima": sel(inp, "O15")[:10],
        "tanggal": sel(inp, "O16")[:10],
        "nomor_sertifikat": sel(inp, "Q5"),
        "nomor_order": sel(inp, "Q6"),
        "suhu_awal": float(sel(inp, "E21")),
        "suhu_akhir": float(sel(inp, "F21")),
        "rh_awal": float(sel(inp, "E22")),
        "rh_akhir": float(sel(inp, "F22")),
        "thermohygro": sel(inp, "Y9"),
        # Y22 (Baik) & Y23 (Buruk) dua checkbox terpisah; master cuma membaca Y22.
        "kerataan_muka_ukur": "baik" if sel(inp, "Y22").upper() == "TRUE" else "buruk",
    },
    "pra_evaluasi_outside_mm": angka(inp, [f"{k}31" for k in KOLOM_EVALUASI]),
    "pra_evaluasi_inside_mm": angka(inp, [f"{k}36" for k in KOLOM_EVALUASI]),
    "kesejajaran": [
        {"posisi": sel(inp, f"C{r}"), "nominal_mm": float(sel(inp, f"D{r}")), "pembacaan_mm": float(sel(inp, f"F{r}"))}
        for r in (130, 131, 132)
    ],
    "outside": outside,
    "inside": inside,
    "depth": depth,
    "_acuan_master": {
        "now_master": sel(db, "X11"),
        "theta_c": float(sel(hit, "X37")),
        "l_maks_outside_mm": float(sel(hit, "C67")),
        "keping_maks_outside": int(float(sel(hit, "B67"))),
        "keping_maks_inside": int(float(sel(hit, "B102"))),
        "l_maks_depth_mm": float(sel(hit, "C121")),
        "keping_maks_depth": int(float(sel(hit, "B121"))),
        "stdev_evaluasi_outside": float(sel(hit, "N25")),
        "stdev_evaluasi_inside": float(sel(hit, "N31")),
        "outside": acuan_out,
        "inside": acuan_in,
        "depth": acuan_dep,
        "kesejajaran_koreksi": [float(sel(hit, f"H{r}")) for r in (126, 127, 128)],
        "budget": {
            "outside": {"komponen": komponen(range(5, 15)), **agregat(16), "u95_sertifikat": float(sel(u95, "AA21"))},
            "inside": {"komponen": komponen(range(24, 34)), **agregat(35), "u95_sertifikat": float(sel(u95, "AA40"))},
            "depth": {"komponen": komponen(range(43, 54)), **agregat(55), "u95_sertifikat": float(sel(u95, "AA60"))},
        },
    },
}

KELUARAN.write_text(json.dumps(isi, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")
print(f"Tulis {KELUARAN.relative_to(AKAR)} — outside {len(outside)}, inside {len(inside)}, depth {len(depth)} titik.")

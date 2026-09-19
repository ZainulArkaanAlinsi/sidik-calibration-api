#!/usr/bin/env python3
"""Generate `database/data/sesi-master-dial-indicator.json` dari master lab.

Yang diambil cuma MASUKAN — identitas sesi, blok Evaluation (sepuluh
pembacaan + balok ukurnya), dan sepuluh titik (tumpukan balok + pembacaan).
Hasilnya TIDAK ditempel ke seeder: dia lahir dari
`DialIndicatorProfile::hitungPerGrup()`, jadi kalau mesin hitungnya bergeser
yang merah `HitungUlangSemuaSesiTest` — bukan angka tempelan yang ikut diam.

Angka yang tercetak di master ikut disalin ke `_acuan_master` supaya
`DialIndicatorMasterTest` mengadu tiap komponen tanpa membuka Excel lagi.

Nama & alamat pelanggan DIGANTI sintetis. Master memuat pelanggan sungguhan,
dan berkas keluaran ini masuk repo.

Jalankan:  python docs/skrip/gen-sesi-dial-indicator.py [dir-csv]
"""
import csv
import json
import pathlib
import sys

AKAR = pathlib.Path(__file__).resolve().parents[2]
CSV_DIR = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else (
    AKAR / "Project-PT-Sidik/alat-alat-Pt-Sidik/Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Dial_Indicator"
)
KELUARAN = AKAR / "database/data/sesi-master-dial-indicator.json"


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
    """Sel kosong DILEWATI, bukan dibaca nol — master melompati kolom `E` di
    blok Evaluation (sel gabungan), dan nol yang ikut masuk menggeser simpangan
    bakunya tanpa satu pun error."""
    return [float(sel(baris, r)) for r in refs if sel(baris, r) != ""]


inp = baca("INPUT DATA.csv")
hit = baca("PERHITUNGAN.csv")
u95 = baca("PERHITUNGAN U95%.csv")
db = baca("DATABASE.csv")

pastikan(inp, "C29", "Nominal Balok Ukur (mm)")
pastikan(inp, "C35", "Nominal Balok Ukur")
pastikan(inp, "B14", "Rentang Ukur")
pastikan(u95, "B5", "repeatability")
pastikan(u95, "B14", "Selisih suhu Dial dengan balok ukur")

titik = []
for n, dasar in enumerate(range(37, 67, 3), start=1):
    balok = angka(inp, [f"C{r}" for r in range(dasar, dasar + 3)])
    baca_ = angka(inp, [f"{k}{dasar}" for k in "FGHIJ"])
    if not balok and not baca_:
        continue
    titik.append({"titik_ke": n, "balok_mm": balok, "pembacaan_mm": baca_})

acuan_titik = []
for i, t in enumerate(titik):
    r = 31 + 3 * i
    acuan_titik.append({
        "titik_ke": t["titik_ke"],
        "total_nominal": float(sel(hit, f"H{r}")),
        "rata_rata": float(sel(hit, f"N{r}")),
        "standar_terkoreksi": float(sel(hit, f"AB{r}")),
        "koreksi": float(sel(hit, f"AD{r}")),
        "simpangan_baku": float(sel(hit, f"AC{r}")),
    })

komponen = []
for r in range(5, 15):
    komponen.append({
        "baris": r,
        "keterangan": sel(u95, f"B{r}"),
        "u": float(sel(u95, f"K{r}")),
        "pembagi": float(sel(u95, f"N{r}")),
        "ui": float(sel(u95, f"T{r}")),
        "ci": float(sel(u95, f"V{r}")),
        "vi": float(sel(u95, f"Q{r}")),
    })

isi = {
    "_catatan": "Sesi contoh dari Master Olah Data_Dial Indicator.xlsm (ber-password). Yang ditanam DialIndicatorSeeder "
                "cuma MASUKANNYA; koreksi & budget dihitung profilnya. `_acuan_master` dipakai DialIndicatorMasterTest.",
    "_digenerate_oleh": "docs/skrip/gen-sesi-dial-indicator.py",
    "_sesi": {
        "nama_alat": sel(inp, "E10"),
        "merk": sel(inp, "E11"),
        "model": sel(inp, "E12"),
        "serial": sel(inp, "E13"),
        "satuan_alat": sel(inp, "Y13"),
        "rentang": sel(inp, "E14"),
        "kapasitas_mm": float(sel(inp, "E15")),
        "resolusi_mm": float(sel(inp, "E16")),
        # Sintetis — lihat docstring.
        "pelanggan": "PT Geoteknik Contoh Nusantara",
        "alamat": "Jl. Contoh No. 41, Bandung 40124",
        "tanggal_terima": sel(inp, "O15")[:10],
        "tanggal": sel(inp, "O16")[:10],
        "nomor_sertifikat": sel(inp, "Q5"),
        "nomor_order": sel(inp, "Q6"),
        "suhu_awal": float(sel(inp, "E21")),
        "suhu_akhir": float(sel(inp, "F21")),
        "rh_awal": float(sel(inp, "E22")),
        "rh_akhir": float(sel(inp, "F22")),
        "thermohygro": sel(inp, "Y9"),
    },
    "balok_pra_evaluasi_mm": angka(inp, [f"{k}29" for k in "HIJKLM"]),
    "pra_evaluasi_mm": angka(inp, [f"{k}31" for k in "CDEFGHIJKLM"]),
    "titik": titik,
    "_acuan_master": {
        # `DATABASE!X11 = NOW()` waktu berkas terakhir dihitung — umur drift
        # master lahir dari sini, bukan dari tanggal sesi.
        "now_master": sel(db, "X11"),
        "l_maks_master_mm": float(sel(hit, "C61")),
        "keping_maks": int(float(sel(hit, "B61"))),
        "theta_c": float(sel(hit, "Q31")),
        "titik": acuan_titik,
        "komponen": komponen,
        "uc": float(sel(u95, "AA16")),
        "veff": float(sel(u95, "AA17")),
        "k": float(sel(u95, "AA18")),
        "u_diperluas": float(sel(u95, "AA19")),
        "cmc_mm": float(sel(u95, "AA20")),
        "u95_sertifikat": float(sel(u95, "AA21")),
    },
}

KELUARAN.write_text(json.dumps(isi, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")
print(f"Tulis {KELUARAN.relative_to(AKAR)} — {len(titik)} titik, {len(isi['pra_evaluasi_mm'])} pembacaan Evaluation.")

#!/usr/bin/env python3
"""Generate `database/data/sesi-master-sieve.json` dari master lab.

Yang diambil cuma MASUKAN — identitas sesi, tipe sieve, nominal + satuan,
standar yang dipakai, frame, kondisi ruang, dan opening (warp x', weft y',
Ø kawat). Hasilnya TIDAK ditempel ke seeder: dia lahir dari
`SieveProfile::hitungPerGrup()`, jadi kalau mesin hitungnya bergeser yang merah
`HitungUlangSemuaSesiTest` — bukan angka tempelan yang ikut diam.

Blok "pengulangan 6x" (`INPUT DATA!H89:N91`) SENGAJA tidak disalin sebagai
masukan: isinya rumus `=G34..G39`, salinan opening 1..6, bukan pengukuran.
Nilai cache-nya tetap ikut ke `_acuan_master` supaya `SieveMasterTest` bisa
membuktikan bahwa menurunkannya dari opening 1..6 memang sama persis.

Sel yang array formula (`Z16`, `G81`, `J81`, `L81`) cuma dibaca NILAI
CACHE-nya dari CSV untuk `_acuan_master` — tidak ada rumus yang perlu dibaca
dari xlsm.

Nama & alamat pelanggan DIGANTI sintetis. Master memuat pelanggan sungguhan,
dan berkas keluaran ini masuk repo.

Jalankan:  python docs/skrip/gen-sesi-sieve.py [dir-csv]
"""
import csv
import json
import pathlib
import sys

AKAR = pathlib.Path(__file__).resolve().parents[2]
CSV_DIR = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else (
    AKAR / "Project-PT-Sidik/alat-alat-Pt-Sidik/Sieve_Dial_Caliper_CSV (4)/Master_Olah_Data_Sieve_Mesh"
)
KELUARAN = AKAR / "database/data/sesi-master-sieve.json"

TIPE = {1: "compliance", 2: "inspection", 3: "calibration"}      # DATABASE `Type_sieve`
SATUAN = {1: "mm", 2: "inch", 3: "µm"}                             # DATABASE `Satuan_unit`
STANDAR = {1: "caliper", 2: "mikroskop"}                           # DATABASE `Tabel_Standar`
PARAMETER = ("warp", "weft", "kawat")


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


def f(baris, ref):
    return float(sel(baris, ref))


inp = baca("INPUT DATA.csv")
hit = baca("PERHITUNGAN.csv")
u95 = baca("PERHITUNGAN U95%.csv")
srt = baca("SERTIFIKAT.csv")

pastikan(inp, "D32", "No.")
pastikan(hit, "B7", "Nominal Sieve")
pastikan(u95, "Q10", "Divisor")
pastikan(srt, "AE20", "PASS")

# Opening: dua blok × 50 baris (G34:I83 no. 1-50, K34:M83 no. 51-100). Opening
# yang ketiga kolomnya kosong DILEWATI, bukan dibaca nol — dan nomornya tetap
# nomor lembarnya, supaya urutan opening 1..6 (sumber pengulangan) tidak geser.
opening = []
for blok, kolom in ((0, "GHI"), (50, "KLM")):
    for i, r in enumerate(range(34, 84)):
        nilai = [sel(inp, f"{k}{r}") for k in kolom]
        if all(v == "" for v in nilai):
            continue
        opening.append({
            "no": blok + i + 1,
            **{p: (float(v) if v != "" else None) for p, v in zip(PARAMETER, nilai)},
        })

budget = {}
for p, dasar in (("warp", 11), ("weft", 28), ("kawat", 46)):
    komponen = []
    for j in range(6):
        r = dasar + j
        komponen.append({
            "baris": r,
            "u": f(u95, f"N{r}"),
            "pembagi": f(u95, f"Q{r}"),
            "vi": f(u95, f"S{r}"),
            "ui": f(u95, f"U{r}"),
            "ci": f(u95, f"X{r}"),
        })
    budget[p] = {
        "komponen": komponen,
        "uc": f(u95, f"AC{dasar + 7}"),
        "veff": f(u95, f"AC{dasar + 8}"),
        "k": f(u95, f"AC{dasar + 9}"),
        "u_diperluas": f(u95, f"AC{dasar + 10}"),
        "cmc_mm": f(u95, f"AC{dasar + 11}"),
        "u95_sertifikat": f(u95, f"AC{dasar + 13}"),
    }

isi = {
    "_catatan": "Sesi contoh dari Master Olah Data_Sieve Mesh.xlsm (pw spirit285). Yang ditanam SieveSeeder cuma "
                "MASUKANNYA; koreksi, budget, dan vonis dihitung profilnya. `_acuan_master` dipakai SieveMasterTest.",
    "_digenerate_oleh": "docs/skrip/gen-sesi-sieve.py",
    "_sesi": {
        "nama_alat": sel(inp, "F10"),
        "merk": sel(inp, "F11"),
        "model": sel(inp, "F12"),
        "serial": sel(inp, "F13"),
        "tipe": TIPE[int(f(inp, "F5"))],
        "nominal": f(inp, "F14"),
        "satuan": SATUAN[int(f(inp, "H14"))],
        "standar_dipakai": STANDAR[int(f(inp, "Q25"))],
        "frame_diameter_mm": [f(inp, "F17")],
        "frame_tinggi_mm": [f(inp, "F18")],
        # Sintetis — lihat docstring.
        "pelanggan": "PT Geoteknik Contoh Nusantara",
        "alamat": "Jl. Contoh No. 41, Bandung 40124",
        "tanggal_terima": sel(inp, "Q15")[:10],
        "tanggal": sel(inp, "Q16")[:10],
        "nomor_sertifikat": sel(inp, "S5"),
        "nomor_order": sel(inp, "S6"),
        "suhu_awal": f(inp, "F23"),
        "suhu_akhir": f(inp, "G23"),
        "rh_awal": f(inp, "F24"),
        "rh_akhir": f(inp, "G24"),
        "thermohygro": sel(inp, "Z9"),
    },
    "opening": opening,
    "_acuan_master": {
        "nominal_mm": f(hit, "E7"),
        "kawat_nominal_mm": f(hit, "E8"),
        "minimum_opening_master": f(inp, "F16"),
        "rata_rata": {"warp": f(hit, "G80"), "weft": f(hit, "J80"), "kawat": f(hit, "L80")},
        "simpangan_baku": {"warp": f(hit, "G83"), "weft": f(hit, "J83"), "kawat": f(hit, "L83")},
        "indeks_koreksi": {"warp": f(hit, "G81"), "weft": f(hit, "J81"), "kawat": f(hit, "L81")},
        # Terkoreksi versi MASTER (koreksi kolom kosong = 0).
        "terkoreksi_master": {"warp": f(hit, "G82"), "weft": f(hit, "J82"), "kawat": f(hit, "L82")},
        "simpangan_baku_pengulangan": {"warp": f(hit, "H92"), "weft": f(hit, "K92"), "kawat": f(hit, "M92")},
        "pengulangan_cache": {
            p: [f(inp, f"{k}{r}") for k in "HIKLMN"]
            for p, r in (("warp", 89), ("weft", 90), ("kawat", 91))
        },
        "budget": budget,
        "sertifikat": {
            "warp": {"koreksi": f(srt, "K20"), "y_mm": f(srt, "AD20"), "vonis": sel(srt, "AE20")},
            "weft": {"koreksi": f(srt, "K21"), "y_mm": f(srt, "AD21"), "vonis": sel(srt, "AE21")},
            "kawat": {"koreksi": f(srt, "K22"), "min_mm": f(srt, "AD22"), "max_mm": f(srt, "AD23"), "vonis": sel(srt, "AE22")},
            "stdev_warp": {"max_mm": f(srt, "AD24"), "vonis": sel(srt, "AE24")},
            "stdev_weft": {"max_mm": f(srt, "AD25"), "vonis": sel(srt, "AE25")},
        },
    },
}

KELUARAN.write_text(json.dumps(isi, ensure_ascii=False, indent=4) + "\n", encoding="utf-8")
print(f"Tulis {KELUARAN.relative_to(AKAR)} — {len(opening)} opening.")

#!/usr/bin/env python3
"""Generate `database/data/tabel-standar-volumetric.json` dari master lab.

Sumber: dua workbook master (ber-password), sudah diekspor ke CSV di
`Project-PT-Sidik/alat-alat-Pt-Sidik/Volumetric_Glassware_2026/`:

  - `Fixed_Volumetric_Glassware_2026/`      Labu Ukur, Pipet Volume, Picnometer
  - `Graduated_Volumetric_Glassware_2026/`  Gelas Ukur, Buret, Pipet Ukur

Tabelnya DIGENERATE, tidak diketik. Enam tabel CMC berisi puluhan pasang angka;
satu digit yang meleset waktu diketik menggeser lantai U95 satu rentang alat
tanpa satu pun error.

Yang ditulis:

  cmc                  enam tabel, SATU salinan. Skrip ini MENOLAK jalan kalau
                       kedua workbook tidak identik - kalau suatu hari lab
                       merevisi satu workbook saja, itu harus ketahuan, bukan
                       tertimpa diam-diam oleh workbook yang dibaca terakhir.
  diameter_iso4787     tabel diameter maksimum tabung (Fixed saja) - komponen
                       ketidakpastian meniskus.
  koefisien_muai       14 material (Graduated saja). Baru DUA yang dipakai
                       rumus master; sisanya disalin supaya pembaca berikutnya
                       tidak mengira lab tidak punya angkanya.
                       Lihat docs/pertanyaan-lab-volumetric.md no. 7.
  neraca               PER WORKBOOK, sengaja tidak digabung. Neraca ketiga beda
                       fisik: "Electronic Balance Fujitsu" di Fixed, "Electronic
                       Balance Precisa" di Graduated. Satu tabel bersama berarti
                       salah satunya menimpa yang lain.

Jalankan:  python docs/skrip/gen-tabel-standar-volumetric.py
"""
import csv
import json
import pathlib
import re
import sys

AKAR = pathlib.Path(__file__).resolve().parents[2]
SUMBER = AKAR / "Project-PT-Sidik/alat-alat-Pt-Sidik/Volumetric_Glassware_2026"
FIXED = SUMBER / "Fixed_Volumetric_Glassware_2026"
GRADUATED = SUMBER / "Graduated_Volumetric_Glassware_2026"
KELUARAN = AKAR / "database/data/tabel-standar-volumetric.json"

# Urutan kategori di DATABASE baris judul - sama dengan indeks Type_Alat master.
KATEGORI = ["Gelas Ukur", "Labu Ukur", "Buret", "Picnometer", "Pipet Volume", "Pipet Ukur"]


def baca(berkas):
    with open(berkas, encoding="utf-8-sig", newline="") as f:
        return list(csv.reader(f))


def nilai(teks):
    """Ambil nilai hasil dari sel ekspor `nilai [=rumus]`."""
    teks = (teks or "").strip()
    m = re.match(r"^(.*?)\s*\[", teks)
    return (m.group(1) if m else teks).strip()


def angka(teks):
    t = nilai(teks)
    if t == "":
        return None
    try:
        return float(t)
    except ValueError:
        return None


def sel(baris, i, k):
    return baris[i][k] if i < len(baris) and k < len(baris[i]) else ""


def tabel_cmc(berkas):
    """Enam tabel CMC dari DATABASE: pasangan (rentang, CMC) per kategori.

    Kolom V..AG (indeks 21..32), baris data mulai indeks 4.
    """
    b = baca(berkas)
    keluar = {}
    for n, nama in enumerate(KATEGORI):
        k_rentang, k_cmc = 21 + 2 * n, 22 + 2 * n
        baris = []
        for i in range(4, 16):
            r, c = angka(sel(b, i, k_rentang)), angka(sel(b, i, k_cmc))
            if r is not None and c is not None:
                baris.append({"nominal_ml": r, "cmc_ml": c})
        keluar[nama] = baris
    return keluar


def tabel_neraca(berkas):
    """Neraca standar: nama, LOP/resolusi (kolom AD), stdev (kolom AE)."""
    b = baca(berkas)
    keluar = []
    for i in range(len(b)):
        nama = nilai(sel(b, i, 21))
        if "Balance" in nama:
            keluar.append({
                "nama": nama,
                "merk_type": nilai(sel(b, i, 22)),
                "lop_g": angka(sel(b, i, 29)),
                "stdev_g": angka(sel(b, i, 30)),
            })
    return keluar


def tabel_diameter(berkas):
    keluar = []
    for r in baca(berkas)[1:]:
        if len(r) >= 2 and angka(r[0]) is not None and angka(r[1]) is not None:
            keluar.append({"batas_galat_ml": angka(r[0]), "diameter_maks_mm": angka(r[1])})
    return keluar


def tabel_muai(berkas):
    keluar = []
    for r in baca(berkas)[2:]:
        if len(r) >= 2 and nilai(r[0]) and angka(r[1]) is not None:
            keluar.append({"material": nilai(r[0]), "gamma_per_c": angka(r[1])})
    return keluar


def main():
    cmc_f = tabel_cmc(FIXED / "DATABASE.csv")
    cmc_g = tabel_cmc(GRADUATED / "DATABASE.csv")

    if cmc_f != cmc_g:
        beda = [k for k in KATEGORI if cmc_f[k] != cmc_g[k]]
        sys.exit(f"BERHENTI - tabel CMC kedua workbook TIDAK identik di: {', '.join(beda)}. "
                 "Periksa dulu mana yang direvisi lab; jangan biarkan yang terakhir dibaca menimpa.")

    data = {
        "_sumber": "Project-PT-Sidik/alat-alat-Pt-Sidik/Volumetric_Glassware_2026 - DIGENERATE, jangan diketik",
        "_generator": "docs/skrip/gen-tabel-standar-volumetric.py",
        "cmc": cmc_f,
        "diameter_iso4787": tabel_diameter(FIXED / "Tabel_Maximum_Internal_Diameter.csv"),
        "koefisien_muai": tabel_muai(GRADUATED / "Tabel_Koefisien_Muai_Bahan.csv"),
        "neraca": {
            "fixed": tabel_neraca(FIXED / "DATABASE.csv"),
            "graduated": tabel_neraca(GRADUATED / "DATABASE.csv"),
        },
    }

    KELUARAN.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")

    print(f"ditulis: {KELUARAN.relative_to(AKAR)}")
    for k in KATEGORI:
        print(f"  CMC {k:<13} {len(data['cmc'][k]):>2} baris")
    print(f"  diameter ISO 4787  {len(data['diameter_iso4787']):>2} baris")
    print(f"  koefisien muai     {len(data['koefisien_muai']):>2} material")
    print(f"  neraca fixed       {[n['nama'] for n in data['neraca']['fixed']]}")
    print(f"  neraca graduated   {[n['nama'] for n in data['neraca']['graduated']]}")


if __name__ == "__main__":
    main()

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
    """Neraca standar, kolom dicari lewat JUDULNYA - bukan indeks tetap.

    Kedua workbook TIDAK sekolom: Fixed punya `U95% | U95% units | Res. |
    Stdev`, Graduated cuma `U95% | U95% units | stdev` (tanpa Res). Versi
    pertama skrip ini membaca indeks tetap dan diam-diam menulis stdev
    Graduated sebagai resolusi - nol error, angka salah. Judul kolom yang tidak
    ketemu jadi `null`, dan kolom wajib yang hilang menghentikan skrip.
    """
    b = baca(berkas)
    judul_i = next((i for i, r in enumerate(b) if "Standart Meter/Indikator" in nilai(sel(b, i, 21))), None)
    if judul_i is None:
        sys.exit(f"BERHENTI - baris judul neraca tidak ketemu di {berkas}")

    kolom = {}
    for k, c in enumerate(b[judul_i]):
        t = nilai(c).strip().lower()
        if t == "u95%":
            kolom["u95_g"] = k
        elif t.startswith("res"):
            kolom["resolusi_g"] = k
        elif t == "stdev":
            kolom["stdev_g"] = k
        elif t == "s/n":
            kolom["serial"] = k
    for wajib in ("u95_g", "stdev_g"):
        if wajib not in kolom:
            sys.exit(f"BERHENTI - kolom {wajib} tidak ketemu di judul neraca {berkas}")

    keluar = []
    for i in range(judul_i + 1, len(b)):
        nama = nilai(sel(b, i, 21))
        if "Balance" not in nama:
            continue
        keluar.append({
            "nama": nama,
            "merk_type": nilai(sel(b, i, 22)),
            "serial": nilai(sel(b, i, kolom["serial"])) if "serial" in kolom else None,
            "u95_g": angka(sel(b, i, kolom["u95_g"])),
            "resolusi_g": angka(sel(b, i, kolom["resolusi_g"])) if "resolusi_g" in kolom else None,
            "stdev_g": angka(sel(b, i, kolom["stdev_g"])),
        })
    return keluar


def u95_suhu(berkas):
    """U95 termometer (Yokogawa) & sensor (PRT) dari DATABASE kolom U95%.

    Master PERHITUNGAN_U95% membaca dua sel ini (`DATABASE!AB22/AB23` Fixed,
    `AB21/AB22` Graduated - tabelnya bergeser satu baris) - SATU angka untuk seluruh
    rentang, bukan tabel U95 per titik di STANDARD_KALIBRATOR. Ditiru.
    """
    b = baca(berkas)
    for i, r in enumerate(b):
        if "Termometer" in nilai(sel(b, i, 21)):
            return {"termometer_c": angka(sel(b, i, 27)), "sensor_c": angka(sel(b, i + 1, 27))}
    sys.exit(f"BERHENTI - baris Termometer tidak ketemu di {berkas}")


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


def tabel_kalibrator(berkas):
    """Koreksi & U95 kalibrator Yokogawa (kolom PT100) dari STANDARD_KALIBRATOR.

    Dua blok berurutan di sheet yang sama: "TABEL KOREKSI" lalu "TABEL U95%".
    Titik yang kolom PT100-nya kosong (900 C ke atas) dilewati.
    """
    b = baca(berkas)
    koreksi, u95, blok = {}, {}, None
    for r in b:
        teks = " ".join(r).upper()
        if "TABEL KOREKSI" in teks:
            blok = koreksi
            continue
        if "TABEL U95" in teks:
            blok = u95
            continue
        if blok is None or len(r) < 3:
            continue
        titik, nilai_pt100 = angka(r[1]), angka(r[2])
        if titik is not None and nilai_pt100 is not None:
            blok[titik] = nilai_pt100
    return [
        {"titik_c": t, "koreksi_c": koreksi[t], "u95_c": u95.get(t)}
        for t in sorted(koreksi)
    ]


def tabel_sensor_prt(berkas):
    """Koreksi sensor PRT Pt-100 dari sheet LOKAL FC_Prt_Pt100 (kolom M = A - L).

    SENGAJA bukan dari sheet SENSOR_PT100: sheet itu membaca nilainya lewat
    tautan luar `'[4]FC Prt Pt100'!M..` - cache dari workbook LAIN yang bisa
    basi tanpa pemberitahuan. Sheet lokal menghitung angka yang sama sendiri
    (Callendar-Van Dusen dari resistansi), dan 21 Sep 2026 keduanya diadu:
    25 C dan 50 C selisih 0.
    """
    keluar = []
    for r in baca(berkas):
        if len(r) > 12:
            t, k = angka(r[0]), angka(r[12])
            if t is not None and k is not None:
                keluar.append({"titik_c": t, "koreksi_c": k})
    return sorted(keluar, key=lambda x: x["titik_c"])


def main():
    cmc_f = tabel_cmc(FIXED / "DATABASE.csv")
    cmc_g = tabel_cmc(GRADUATED / "DATABASE.csv")

    if cmc_f != cmc_g:
        beda = [k for k in KATEGORI if cmc_f[k] != cmc_g[k]]
        sys.exit(f"BERHENTI - tabel CMC kedua workbook TIDAK identik di: {', '.join(beda)}. "
                 "Periksa dulu mana yang direvisi lab; jangan biarkan yang terakhir dibaca menimpa.")

    if u95_suhu(FIXED / "DATABASE.csv") != u95_suhu(GRADUATED / "DATABASE.csv"):
        sys.exit("BERHENTI - U95 termometer/sensor kedua workbook TIDAK sama.")

    data = {
        "_sumber": "Project-PT-Sidik/alat-alat-Pt-Sidik/Volumetric_Glassware_2026 - DIGENERATE, jangan diketik",
        "_generator": "docs/skrip/gen-tabel-standar-volumetric.py",
        "cmc": cmc_f,
        "diameter_iso4787": tabel_diameter(FIXED / "Tabel_Maximum_Internal_Diameter.csv"),
        "koefisien_muai": tabel_muai(GRADUATED / "Tabel_Koefisien_Muai_Bahan.csv"),
        "koreksi_suhu": {
            "kalibrator": tabel_kalibrator(FIXED / "STANDARD_KALIBRATOR.csv"),
            "sensor_prt": tabel_sensor_prt(FIXED / "FC_Prt_Pt100.csv"),
            "u95": u95_suhu(FIXED / "DATABASE.csv"),
        },
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
    print(f"  koreksi kalibrator {len(data['koreksi_suhu']['kalibrator']):>2} titik")
    print(f"  koreksi sensor PRT {len(data['koreksi_suhu']['sensor_prt']):>2} titik")
    print(f"  neraca fixed       {[n['nama'] for n in data['neraca']['fixed']]}")
    print(f"  neraca graduated   {[n['nama'] for n in data['neraca']['graduated']]}")


if __name__ == "__main__":
    main()

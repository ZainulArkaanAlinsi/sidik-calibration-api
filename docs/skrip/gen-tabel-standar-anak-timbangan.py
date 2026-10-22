#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Generator `database/data/tabel-standar-anak-timbangan.json` (alat ke-29).

    python -I docs/skrip/gen-tabel-standar-anak-timbangan.py "<Anak timbangan F2_IMTE-LQ-197 hal 1.xlsx>"

**Dibaca dari ALAMAT SEL master** (22 Okt 2026), bukan lagi angka yang diketik
tangan. Sumbernya workbook lab terbaru `Anak timbangan F2_IMTE-LQ-197 hal 1.xlsx`
(berkas TIDAK masuk repo — data pelanggan; dibaca di tempat). Alamat yang sama
dipakai peta sel Studio `database/data/peta-sel/anak_timbangan.json`, jadi
generator dan impor Studio membaca sel yang sama. Sheet: `STD AT`, `DATABASE`,
`MPE AT`, `Deviasi Standard Timbangan`, dan kelima `Data Sens *`.

Sejarah: sampai 22 Okt 2026 angka disalin tangan dari `1.1 Anak Timbangan F1
1mg-500 g 202501022 imp.xlsx` (ekspor CSV-nya di
`Project-PT-Sidik/alat-alat-Pt-Sidik/Massa_Timbangan/`), dengan baris Fujitsu
diperbarui 7 Okt 2026 dari workbook F2 ini. Workbook F2 hal 1 memuat SEMUA angka
itu di sel (dibuktikan: keluaran generator ini = JSON lama, kecuali tipe angka
bulat yang kini ditulis float — nilainya sama).

Skrip ini **menurunkan ulang** tiap besaran turunan lalu mengadunya ke nilai
yang tersimpan di master:

  * keterulangan tiap neraca dari enam simpangan baku hariannya;
  * ketidakpastian sensitivitas tiap neraca dari rantai percobaan Msens;
  * kolom drift tiap keping standar dari kolom ketidakpastiannya;
  * koreksi meter lingkungan dari pasangan indikasinya.

Kalau ada yang menyimpang di luar toleransi, dia **menolak menulis**.

Kolom TURUNAN tetap dihitung di sini (kode), bukan diambil dari sel:
`stdev_harian_mg` (sel g x 1000), `gabungan_harian_mg` (akar rata-rata kuadrat),
`sensitivitas.u_relatif` (sqrt; sel `Data Sens *!I30` beda 1 ulp di Semi Micro),
`nominal_teks`, `pola_drift`, dan `nominal_at_uji_g` Mettler (master menulis
`20` + satuan `kg`).

## Tiga hal yang SENGAJA tidak "dibetulkan" di sini

**1. Keterulangan neraca.** Sel master berlabel `Rata-rata STDev` sebenarnya
berisi SIMPANGAN BAKU dari keenam simpangan baku harian, bukan rata-ratanya.
Ditiru apa adanya; `gabungan_harian_mg` ikut disimpan. Pertanyaan lab **§1**.

**2. Tabel densitas.** Memuat nilai yang mustahil untuk anak timbangan (10650
kg/m3 = timbal; 14400 kg/m3). Disalin apa adanya, ditandai
`"status": "disengketakan"`. Pertanyaan lab **§6**.

**3. Keping ber-bintang.** `0.02*`, `0.2*`, `2*`, `20*`, `200*` adalah keping
KEDUA bernominal sama. Keduanya disimpan. Pertanyaan lab **§8**.

Kolom `Stdev` di sheet `DATABASE` (AF30:AF34) tidak disalin — tidak ada rumus
hasil kalibrasi yang membacanya (pertanyaan lab §18). Meter `TH-7` tidak
disalin — kolom koreksinya tidak rekonsiliasi (pertanyaan lab §19).
"""
from __future__ import annotations

import datetime
import io
import json
import math
import pathlib
import statistics
import sys

import openpyxl

TOL = 5e-6
KELUARAN = (
    pathlib.Path(__file__).resolve().parents[2]
    / "database/data/tabel-standar-anak-timbangan.json"
)
BERKAS = pathlib.Path(sys.argv[1]) if len(sys.argv) > 1 else None
if BERKAS is None:
    sys.exit("pakai: gen-tabel-standar-anak-timbangan.py <Anak timbangan F2_IMTE-LQ-197 hal 1.xlsx>")


def buka(path: pathlib.Path):
    data = path.read_bytes()
    buf = io.BytesIO(data)
    if data[:4] == b"\xd0\xcf\x11\xe0":  # terenkripsi: sandi dari env, dekripsi ke memori
        import msoffcrypto
        import winreg

        with winreg.OpenKey(winreg.HKEY_CURRENT_USER, "Environment") as k:
            sandi = winreg.QueryValueEx(k, "SIDIK_MASTER_SANDI")[0]
        out = io.BytesIO()
        f = msoffcrypto.OfficeFile(buf)
        f.load_key(password=sandi)
        f.decrypt(out)
        buf = out
    buf.seek(0)
    return openpyxl.load_workbook(buf, data_only=True)


WB = buka(BERKAS)
menyimpang: list[str] = []


def sel(alamat: str):
    lembar, a = alamat.rsplit("!", 1)
    return WB[lembar][a].value


def angka(alamat: str) -> float:
    v = sel(alamat)
    if isinstance(v, bool) or not isinstance(v, (int, float)):
        menyimpang.append(f"{alamat} bukan angka: {v!r}")
        return float("nan")
    return float(v)


def angka_boleh(alamat: str) -> float | None:
    v = sel(alamat)
    return None if v is None or (isinstance(v, str) and v.strip() == "") else angka(alamat)


def teks(alamat: str, boleh_kosong: bool = False) -> str:
    v = sel(alamat)
    if isinstance(v, (int, float)) and not isinstance(v, bool) and float(v).is_integer():
        return str(int(v))
    if v is None or str(v).strip() == "":
        if not boleh_kosong:
            menyimpang.append(f"{alamat} kosong, padahal wajib teks")
        return ""
    return str(v).strip()


def tanggal(alamat: str) -> str:
    v = sel(alamat)
    if isinstance(v, datetime.datetime):
        return v.date().isoformat()
    if isinstance(v, datetime.date):
        return v.isoformat()
    menyimpang.append(f"{alamat} bukan tanggal: {v!r}")
    return ""


def adu(nama: str, hitung: float, master: float, tol: float = TOL, tol_rel: float | None = None) -> float:
    """Adu nilai turunan ke nilai tersimpan master. Balik nilai MASTER."""
    if tol_rel is None:
        tol_rel = tol
    beda = abs(hitung - master)
    rel = beda / abs(master) if master else beda
    if beda > tol and rel > tol_rel:
        menyimpang.append(f"{nama}: turunan={hitung!r} master={master!r} beda={beda:.3e} rel={rel:.3e}")
    return master


# ===================================================================== 1. Keping standar
# `STD AT!M5:R33` (OVERALL STANDAR ANAK TIMBANGAN): kelas, nominal (g; teks
# ber-bintang = keping kedua), konvensional (g), koreksi (g), U (mg), drift (mg).
KEPING_BARIS = range(5, 34)
DRIFT_KECUALI = {"10000": 4 / math.sqrt(3)}  # baris 10 kg memakai 4/sqrt(3) (§18)

keping_json = []
for b in KEPING_BARIS:
    mentah = sel(f"STD AT!N{b}")
    bintang = isinstance(mentah, str) and mentah.strip().endswith("*")
    if isinstance(mentah, str):
        nominal = float(mentah.strip().rstrip("*"))
        nominal_teks = mentah.strip()
    else:
        nominal = float(mentah)
        nominal_teks = str(int(nominal)) if nominal.is_integer() else repr(nominal)
    konv = angka(f"STD AT!O{b}")
    koreksi = angka(f"STD AT!P{b}")
    u_mg = angka(f"STD AT!Q{b}")
    drift_mg = angka(f"STD AT!R{b}")
    adu(f"keping {nominal_teks} koreksi", konv - nominal, koreksi, tol=0.0, tol_rel=1e-9)
    if nominal_teks in DRIFT_KECUALI:
        adu(f"keping {nominal_teks} drift (pola 4/sqrt3)", DRIFT_KECUALI[nominal_teks], drift_mg)
    else:
        adu(f"keping {nominal_teks} drift (pola U/2)", u_mg / 2, drift_mg, tol=1e-12)
    keping_json.append({
        "kelas": teks(f"STD AT!M{b}"),
        "nominal_teks": nominal_teks,
        "nominal_g": nominal,
        "bintang": bintang,
        "konvensional_g": konv,
        "koreksi_g": koreksi,
        "u_mg": u_mg,
        "drift_mg": drift_mg,
        "pola_drift": "4/sqrt(3)" if nominal_teks in DRIFT_KECUALI else "U/2",
    })

# Tujuh set fisik: `DATABASE!W19:AD25` + kelas & no. sertifikat dari blok kepala
# tiap set di `STD AT` (baris kepala: urutan DATABASE).
KEPALA_SET = [1, 33, 42, 51, 78, 60, 69]
set_json = []
for i, k in enumerate(KEPALA_SET):
    b = 19 + i
    set_json.append({
        "nama": teks(f"DATABASE!W{b}"),
        "kapasitas": teks(f"DATABASE!X{b}"),
        "kelas": teks(f"STD AT!D{k + 2}"),
        "merk_tipe": teks(f"DATABASE!Y{b}"),
        "no_seri": teks(f"DATABASE!Z{b}"),
        "tertelusur": teks(f"DATABASE!AA{b}"),
        "no_sertifikat": teks(f"STD AT!J{k + 3}", boleh_kosong=True),
        "tanggal_kalibrasi": tanggal(f"DATABASE!AB{b}"),
        "due_date": tanggal(f"DATABASE!AD{b}"),
        "interval_tahun": int(angka(f"DATABASE!AC{b}")),
    })

# ===================================================================== 2. Neraca
# `DATABASE!W30:AE34`, `STD AT!O85:Q89`, `Deviasi Standard Timbangan` (baris
# STDev harian & Rata-rata STDev per blok), `Data Sens *` (rantai Msens).
NERACA = [
    # (baris DATABASE, baris STD AT, baris STDev harian, sel nominal AT, sheet Data Sens)
    (30, 85, 19, "D4", "Data Sens Semi Micro"),
    (31, 86, 39, "D24", "Data Sens Analytical Balance"),
    (32, 87, 59, "D44", "Data Sens Fujitsu"),
    (33, 88, 78, "D63", "Data Sens Excellent"),
    (34, 89, 97, "D82", "Data Sens Mettler Toledo"),
]
DEV = "Deviasi Standard Timbangan"
neraca_json = []
for bd, bs, bh, sel_nominal, sens in NERACA:
    nama = teks(f"DATABASE!W{bd}")
    harian = [angka(f"{DEV}!{k}{bh}") for k in ("D", "I", "N", "S", "X", "AC")]
    rata_master = angka(f"{DEV}!D{bh + 1}")
    adu(f"{nama} keterulangan (stdev dari 6 stdev harian)", statistics.stdev(harian), rata_master, tol=0.0, tol_rel=1e-12)
    std_dev_mg = angka(f"STD AT!P{bs}")
    adu(f"{nama} std_dev_mg = Rata-rata STDev x 1000", rata_master * 1000, std_dev_mg, tol=0.0, tol_rel=1e-12)

    d1, d2 = angka(f"{sens}!G17"), angka(f"{sens}!G18")
    de_sens, u_msens, m_sens = angka(f"{sens}!D17"), angka(f"{sens}!D24"), angka(f"{sens}!D21")
    s_sens = abs(d1 - d2) / math.sqrt(2)
    u_rel = math.sqrt((s_sens / d1) ** 2 + (u_msens / m_sens) ** 2)
    u_sens_mg = angka(f"STD AT!Q{bs}")
    adu(f"{nama} usens", de_sens * u_rel, u_sens_mg, tol=0.0, tol_rel=1e-12)
    adu(f"{nama} usens = {sens}!G33", angka(f"{sens}!G33"), u_sens_mg, tol=0.0, tol_rel=0.0)

    # Nominal AT uji: sel blok Deviasi (g); Mettler menulis `20` + `kg`.
    nominal_uji = angka(f"{DEV}!{sel_nominal}")
    satuan_uji = teks(f"{DEV}!E{sel_nominal[1:]}", boleh_kosong=True)
    if satuan_uji == "kg":
        nominal_uji *= 1000

    neraca_json.append({
        "nama": nama,
        "merk_tipe": teks(f"DATABASE!X{bd}"),
        "no_seri": teks(f"DATABASE!Y{bd}"),
        "tertelusur": teks(f"DATABASE!Z{bd}"),
        "tanggal_database": tanggal(f"DATABASE!AA{bd}"),
        "kapasitas_g": angka(f"STD AT!O{bs}"),
        "resolusi_g": angka(f"DATABASE!AE{bd}"),
        "u95_g": angka(f"DATABASE!AC{bd}"),
        "nominal_at_uji_g": nominal_uji,
        "stdev_harian_mg": [x * 1000 for x in harian],
        "std_dev_mg": std_dev_mg,
        "gabungan_harian_mg": math.sqrt(sum(x * x for x in harian) / len(harian)) * 1000,
        "u_sens_mg": u_sens_mg,
        "sensitivitas": {
            "delta1_mg": d1,
            "delta2_mg": d2,
            "de_sens_mg": de_sens,
            "u_msens_mg": u_msens,
            "m_sens_mg": m_sens,
            "u_relatif": u_rel,
        },
    })

# ===================================================================== 3. Densitas
# `STD AT!M72:R83` "Kelas Anak Timbang OIML (kg/m3)". DISENGKETAKAN — lihat §6.
densitas = [
    {"nominal_g": angka(f"STD AT!M{b}"), **{k: angka_boleh(f"STD AT!{c}{b}") for k, c in zip(["E2", "F1", "F2", "M1", "M2"], "NOPQR")}}
    for b in range(72, 84)
]

# ===================================================================== 4. MPE
# `MPE AT!A4:I26` — OIML R111 Tabel 1, mg. Kolom kosong = tidak didefinisikan.
KELAS_MPE = ["F1", "F2", "M1", "M1-2", "M2", "M2-3", "M3"]
mpe = [
    {"label": teks(f"MPE AT!A{b}"), "nominal_g": angka(f"MPE AT!B{b}"),
     **{k: angka_boleh(f"MPE AT!{c}{b}") for k, c in zip(KELAS_MPE, "CDEFGHI")}}
    for b in range(4, 27)
]
for kelas in KELAS_MPE:
    deret = [(m["nominal_g"], m[kelas]) for m in mpe if m[kelas] is not None]
    for (n_besar, v_besar), (n_kecil, v_kecil) in zip(deret, deret[1:]):
        if v_besar < v_kecil:
            menyimpang.append(f"MPE {kelas}: {n_kecil} g ({v_kecil} mg) > {n_besar} g ({v_besar} mg)")

# ===================================================================== 5. Meter lingkungan
# `DATABASE!B32:R40` Thermobarometer: [standar, instrumen, koreksi] per titik.


def titik(kolom: str, baris: range) -> list:
    k1, k2, k3 = kolom
    return [[angka(f"DATABASE!{k1}{b}"), angka(f"DATABASE!{k2}{b}"), angka(f"DATABASE!{k3}{b}")] for b in baris]


METER = [{
    "nama": teks("DATABASE!B32"),
    "lokasi": teks("DATABASE!O32"),
    "tanggal_kalibrasi": tanggal("DATABASE!P32"),
    "due_date": tanggal("DATABASE!Q32"),
    "tertelusur": teks("DATABASE!R32"),
    "suhu": {"u95": angka("DATABASE!F32"), "titik": titik(("C", "D", "E"), range(32, 37))},
    "kelembaban": {"u95": angka("DATABASE!J32"), "titik": titik(("G", "H", "I"), range(32, 37))},
    "tekanan": {"u95": angka("DATABASE!N32"), "titik": titik(("K", "L", "M"), range(32, 41))},
}]
for m in METER:
    for besaran in ("suhu", "kelembaban", "tekanan"):
        for standar, instrumen, koreksi in m[besaran]["titik"]:
            adu(f"{m['nama']} {besaran} koreksi @{instrumen}", standar - instrumen, koreksi, tol=1e-9)

# ===================================================================== 6. Drift penimbangan
# `STD AT!N42:P48` LATEST RECORD DRIFT of WEIGHING.
drift = [
    {"maks_index_standar_g": angka(f"STD AT!N{b}"), "kelompok": teks(f"STD AT!O{b}"), "u_drift_g": angka(f"STD AT!P{b}")}
    for b in range(42, 49)
]

# ===================================================================== 7. Susun
data = {
    "sumber": {
        "workbook": "Anak timbangan F2_IMTE-LQ-197 hal 1.xlsx",
        "workbook_sebelumnya": "1.1 Anak Timbangan F1 1mg-500 g 202501022 imp.xlsx (angka identik kecuali baris Fujitsu yang diperbarui 7 Okt 2026)",
        "lembar_kerja": "SIDIK-FM-CAL-0541_Rev.0 - LEMBAR KERJA ANAK TIMBANGAN (Non KAN)",
        "metode": "SIDIK-IK-CAL-0535_Rev.0",
        "acuan": "OIML R111-1",
        "revisi_master": "3 (29 Mei 2026)",
        "dalam_lingkup_akreditasi": False,
        "catatan": (
            "Lampiran LK-285-IDN kelompok Massa cuma memuat 'Timbangan "
            "(Elektronik, mekanik)'. Kalibrasi anak timbangan di luar lingkup, "
            "dan nama lembar kerjanya sendiri menyebut (Non KAN). Pertanyaan lab §15."
        ),
        "digenerate_oleh": "docs/skrip/gen-tabel-standar-anak-timbangan.py",
    },
    # Konstanta metode = angka telanjang di rumus PERHITUNGAN U95% / FC (bukan
    # sel tabel); tetap kode, bukan data master.
    "konstanta": {
        "rho_udara_referensi_kg_m3": 1.2,
        "u_bouyancy_kg_m3": 0.12,
        "pembagi_rectangular": 1.73,
        "pembagi_normal": 2.0,
        "faktor_resolusi_ganda": 1.414,
        "vi": {
            "repeatability": 54,
            "sertifikat_calibrator_at": 60,
            "resolusi_timbangan_standard": 100,
            "instability": 9,
            "bouyancy": 100,
            "sensitivity": 100,
        },
        "catatan_pembagi": (
            "1,73 dipakai master, bukan sqrt(3)=1,7320508 (selisih 0,12 %, arah "
            "menaikkan ui). 1,414 dipakai master, bukan sqrt(2)=1,41421356 "
            "(selisih 0,015 %). Ditiru apa adanya. Pertanyaan lab §18."
        ),
        "rumus_densitas_udara": (
            "((0,34848*P) - (0,009*RH)*EXP(0,061*T)) / (T + 273,15), "
            "OIML R111 Lampiran E; T, RH, P memakai rata-rata MENTAH (§13)"
        ),
    },
    "standar_at": {"set": set_json, "keping": keping_json},
    "timbangan": neraca_json,
    "densitas": {
        "status": "disengketakan",
        "satuan": "kg/m3",
        "pertanyaan_lab": "§6",
        "catatan": (
            "Memuat nilai yang mustahil untuk anak timbangan (10650 = timbal, "
            "14400) dan kolom E2/F1 tertukar di 0,1 g dan 0,2 g. Disalin apa "
            "adanya supaya sesi lama bisa dihitung ulang; profil WAJIB menaikkan "
            "peringatan sesi selama lab belum menjawab."
        ),
        "kelas": ["E2", "F1", "F2", "M1", "M2"],
        "baris": densitas,
        "aturan_diatas_100g": (
            "Nominal >= 100 g memakai baris 100 g ('Note : Diatas 100 g nilainya =')"
        ),
    },
    "mpe": {
        "satuan": "mg",
        "acuan": "OIML R111-1 Tabel 1",
        "kelas": KELAS_MPE,
        "baris": mpe,
    },
    "meter_lingkungan": METER,
    "drift_penimbangan": drift,
}

if menyimpang:
    print("MENOLAK MENULIS — sel/turunan tidak cocok:", file=sys.stderr)
    for baris in menyimpang:
        print("  -", baris, file=sys.stderr)
    sys.exit(1)

KELUARAN.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
print(f"Ditulis {KELUARAN}")
print(f"  {len(keping_json)} keping standar, {len(neraca_json)} neraca, "
      f"{len(densitas)} baris densitas, {len(mpe)} baris MPE, {len(METER)} meter")

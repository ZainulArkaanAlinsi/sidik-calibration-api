#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Generator `database/data/tabel-standar-flowmeter-gravimetri.json`.

Sumbernya DUA workbook master ber-password `spirit285`:

  1. `1.2 Master olda Flowmeter Totalizer dini (2026) 140-2500L.xlsm`
  2. `2.1 Master olda Flowmeter Flowrate 100-980lpm 2026.xlsm`

Angka mentah di bawah disalin dari sheet `STANDAR KALIBRATOR` dan sheet drift
tersembunyi kedua workbook. Skrip ini **menurunkan ulang** tiap besaran turunan
(densitas piknometer, kestabilan, drift, koreksi timer) lalu mengadunya ke nilai
yang tersimpan di master. Kalau ada yang menyimpang di luar toleransi, dia
**menolak menulis** — JSON yang separuh benar jauh lebih berbahaya daripada
tidak ada JSON, karena angkanya tetap terlihat wajar di sertifikat.

Jangan mengetik JSON-nya dengan tangan. Menyunting hasilnya berarti berkasnya
menyimpang diam-diam dari master, dan itu sudah tiga kali meloloskan bug (TIDS,
Timbangan, Micrometer).

    python docs/skrip/gen-tabel-standar-flowmeter-gravimetri.py

## Kenapa tabel timbangan disimpan PER MODE

Kedua workbook menyimpan "timbangan ke-3" yang **bukan alat yang sama**:

| | Totalizer | Flowrate |
|---|---|---|
| Tipe | DJ Series | DFWLB-3 (tipe milik Dini Argeo) |
| S/N | HSEX1403752 | 0792531584 (S/N milik Dini Argeo) |
| Tertelusur | LK-285-IDN | LK-305-IDN |
| Tgl kalibrasi | 2024-07-22 (kedaluwarsa) | 2026-01-19 |
| Satuan tabel koreksi | **gram** | **kg** |
| Koreksi titik 27 & 30 | **0,1 kg** | 0,001 kg |

Memilih salah satu sebagai "yang benar" akan diam-diam menggeser angka yang
sudah tercetak di sertifikat pelanggan. Jadi keduanya disimpan dan dipilih per
sesi lewat mode-nya. Diangkat sebagai pertanyaan lab §15.
"""
from __future__ import annotations

import json
import math
import pathlib
import sys

TOL = 5e-6
KELUARAN = pathlib.Path(__file__).resolve().parents[2] / "database/data/tabel-standar-flowmeter-gravimetri.json"

menyimpang: list[str] = []


def adu(nama: str, hitung: float, master: float, tol: float = TOL) -> float:
    """Adu nilai turunan ke nilai tersimpan master. Catat kalau menyimpang."""
    beda = abs(hitung - master)
    rel = beda / abs(master) if master else beda
    if beda > tol and rel > tol:
        menyimpang.append(f"{nama}: turunan={hitung!r} master={master!r} beda={beda:.3e}")
    return master


# =====================================================================
# 1. Densitas air — piknometer, STANDAR KALIBRATOR!N61:P65 (SAMA di kedua workbook)
# =====================================================================
VOL_PIKNO_ML = 50.3139
DENSITAS_MENTAH = [
    # (suhu °C, tertimbang gram, densitas g/ml tersimpan master)
    (20.0, 50.2242, 0.9982171924657005),
    (27.0, 50.1020, 0.9957884401725965),
    (30.0, 50.0951, 0.9956513011314966),
    (50.5, 49.6516, 0.9868366395767374),
]

densitas_titik = []
for suhu, gram, tersimpan in DENSITAS_MENTAH:
    adu(f"densitas piknometer {suhu} °C", gram / VOL_PIKNO_ML, tersimpan)
    densitas_titik.append(
        {"suhu_c": suhu, "tertimbang_g": gram, "densitas_kg_per_l": tersimpan}
    )


def densitas_air(suhu: float) -> float | None:
    """Interpolasi LINIER antar titik piknometer — bukan Tanaka/Kell.

    Master gravimetri tidak menghitung densitas dengan rumus; dia mengukurnya
    dengan piknometer di empat suhu lalu menginterpolasi. Mengganti dengan
    Tanaka/Kell (yang dipakai varian UFM) menggeser tiap sertifikat lama di
    digit belakang.
    """
    for (t0, _, d0), (t1, _, d1) in zip(DENSITAS_MENTAH, DENSITAS_MENTAH[1:]):
        if t0 <= suhu <= t1:
            return d0 + (suhu - t0) * (d1 - d0) / (t1 - t0)
    return None


# Adu interpolasinya ke kolom turunan master (`STANDAR KALIBRATOR!P66:P80`) —
# suhu act tiap titik sesi contoh kedua workbook.
for suhu, tersimpan in [
    (25.48, 0.9963158263848134),      # Totalizer titik 1 awal
    (25.379999999999995, 0.9963505228461434),
    (25.28, 0.9963852193074735),
    (25.98, 0.9961423440781632),
    (24.98, 0.9964893086914637),      # Flowrate seluruh titik
]:
    adu(f"interpolasi densitas {suhu} °C", densitas_air(suhu), tersimpan)


# =====================================================================
# 2. Timbangan — per mode, karena kedua workbook TIDAK sepakat (lihat docstring)
# =====================================================================
def kestabilan(penimbangan: list[float], tersimpan: float) -> float:
    """(Max − Min)/2 dari tabel stabilitas penimbangan.

    Master Totalizer melabelinya `%FS`, master Flowrate `kg/m`. Nilainya
    kilogram di dua-duanya — label Totalizer yang salah. Pertanyaan lab §16.
    """
    return adu("kestabilan", (max(penimbangan) - min(penimbangan)) / 2, tersimpan)


def drift(koreksi_per_tahun: list[list[float]], tersimpan: float) -> float:
    """`Max. U drift` = maks dari 0,5·(Cmax − Cmin) tiap baris massa nominal.

    Judul kolom di sheet drift menulis `u(δmD) = 0,5 (Cmax − Cmin) / sqrt(3)`,
    tapi kolom yang benar-benar dihitung cuma `0,5 · ΔC` — pembagi √3 TIDAK
    pernah dipakai. Ditiru apa adanya (arahnya membuat u lebih besar daripada
    kalau √3 ikut), dan diangkat sebagai pertanyaan lab §17.
    """
    puncak = 0.0
    for baris in koreksi_per_tahun:
        nilai = [x for x in baris if x is not None]
        if nilai:
            puncak = max(puncak, 0.5 * (max(nilai) - min(nilai)))
    return adu("drift", puncak, tersimpan)


DINI = dict(
    nama="Timbangan Elektronik", merk="Dini Argeo", tipe="DFWLB-3", seri="0792531584",
    tertelusur="LK-285-IDN", tanggal_kalibrasi="2025-08-08", tanggal_jatuh_tempo="2026-08-08",
    u95_kg=0.52, resolusi_kg=0.1, satuan_tabel_koreksi="kg",
    koreksi=[(200, 0.0), (400, 0.0), (600, 0.0), (800, 0.0), (1000, 0.0),
             (1200, 0.0), (1400, 0.0), (1600, 0.0), (1800, 0.1), (2000, 0.1)],
)
# `Drift Timbangan Dini Argeo` D15:J32 — kolom tahun 2021..2026, sel kosong = None.
DRIFT_DINI = [
    [0.0, 0.0], [0.0, 0.0], [0.0, 0.0, 0.0], [0.1, -0.1], [-0.1, -0.1, 0.0],
    [0.0, -0.1], [-0.1, -0.1, 0.0], [0.0, -0.1], [-0.1, -0.1, 0.0], [0.1, 0.0],
    [-0.1, -0.2, 0.0], [0.2, 0.1, -0.2, 0.0], [-0.1, -0.2, 0.0], [0.2, 0.1],
    [-0.1, -0.3, 0.0], [0.2, 0.1], [-0.1, -0.3, 0.1], [0.2, 0.0, -0.1, -0.2, 0.1],
]

SARTORIUS = dict(
    nama="Timbangan Elektronik", merk="Sartorius", tipe="150GF", seri="34166240",
    tertelusur="LK-285-IDN", tanggal_kalibrasi="2025-07-07", tanggal_jatuh_tempo="2026-07-07",
    u95_kg=0.033, resolusi_kg=0.01, satuan_tabel_koreksi="kg",
    koreksi=[(15, 0.0), (30, 0.0), (45, 0.0), (60, 0.0), (75, -0.01),
             (90, -0.01), (105, 0.0), (120, 0.0), (135, 0.0), (150, 0.0)],
)
DRIFT_SARTORIUS = [
    [0.0], [0.0, 0.0, 0.0], [0.0], [0.0], [0.0, 0.01, 0.0], [0.0],
    [0.0, -0.01, 0.0, 0.0], [0.0, -0.02, 0.0, 0.0], [0.0], [-0.02, 0.0, -0.01],
    [0.0], [-0.01, 0.0, -0.01], [0.01, -0.02, 0.0, 0.0], [-0.03, -0.01, 0.0],
    [-0.03, -0.01, 0.0], [0.01, -0.04, -0.01, 0.0],
]

FUJITSU = dict(
    nama="Timbangan Elektronik", merk="Fujitsu", tipe="FSR-A", seri="SIDIK/134/2024",
    tertelusur="LK-285-IDN", tanggal_kalibrasi="2024-06-16", tanggal_jatuh_tempo="2025-06-16",
    u95_kg=1.6e-05, resolusi_kg=1e-06, satuan_tabel_koreksi="g",
    koreksi=[(120, 0.0), (240, 0.0), (360, 0.0), (500, 0.0), (600, 1e-06),
             (720, 1e-06), (840, 1e-06), (900, 1e-06), (1000, 1e-06), (1200, 1e-06)],
)

METTLER_TOT = dict(
    nama="Timbangan Elektronik", merk="Mettler", tipe="DJ Series", seri="HSEX1403752",
    tertelusur="LK-285-IDN", tanggal_kalibrasi="2024-07-22", tanggal_jatuh_tempo="2025-07-22",
    u95_kg=0.00017, resolusi_kg=0.0001, satuan_tabel_koreksi="g",
    koreksi=[(3, 0.0), (6, 0.0), (9, 0.0), (12, 0.0), (15, 0.0), (18, 0.0),
             (21, 0.0), (24, 0.0), (27, 0.1), (30, 0.1)],
)
METTLER_FLO = dict(
    nama="Timbangan Elektronik", merk="Mettler", tipe="DFWLB-3", seri="0792531584",
    tertelusur="LK-305-IDN", tanggal_kalibrasi="2026-01-19", tanggal_jatuh_tempo="2027-01-19",
    u95_kg=0.00017, resolusi_kg=0.0001, satuan_tabel_koreksi="kg",
    koreksi=[(3, 0.0), (6, 0.0), (9, 0.0), (12, 0.0), (15, 0.0), (18, 0.0),
             (21, -0.001), (24, 0.0), (27, 0.001), (30, 0.001)],
)


# Nama yang TERCETAK di kertas lembar kerja `SIDIK-FM-CAL-0538.A/B_Rev.3`,
# kalau berbeda dari nama di workbook. Kertas mendaftar empat checkbox:
# Dini Argeo / Sartorius / **Excellent** / Fujitsu — sementara kedua sheet
# workbook menyebut slot ketiga "Mettler".
#
# Keduanya dibawa, bukan dipilih salah satu. Teknisi memegang KERTAS; kalau
# dropdown cuma menyebut "Mettler", dia tidak menemukan nama yang dia lihat dan
# memilih yang lain — dan pilihan timbangan menentukan tabel koreksi, U95,
# kestabilan, DAN drift sekaligus. Angkanya tetap keluar dan tetap terlihat
# wajar. Mana yang benar diangkat sebagai pertanyaan lab §15.
NAMA_KERTAS = {3: "Excellent"}


def susun(kode: int, dasar: dict, kestabilan_kg: float, drift_kg: float) -> dict:
    return {
        "kode": kode,
        "nama": dasar["nama"],
        "nama_kertas": NAMA_KERTAS.get(kode),
        "merk": dasar["merk"],
        "tipe": dasar["tipe"],
        "seri": dasar["seri"],
        "tertelusur": dasar["tertelusur"],
        "tanggal_kalibrasi": dasar["tanggal_kalibrasi"],
        "tanggal_jatuh_tempo": dasar["tanggal_jatuh_tempo"],
        "u95_kg": dasar["u95_kg"],
        "resolusi_kg": dasar["resolusi_kg"],
        "satuan_tabel_koreksi": dasar["satuan_tabel_koreksi"],
        "kestabilan_kg": kestabilan_kg,
        "drift_kg": drift_kg,
        "koreksi": [{"titik": t, "koreksi_kg": k} for t, k in dasar["koreksi"]],
    }


drift_dini = drift(DRIFT_DINI, 0.2)
drift_sartorius = drift(DRIFT_SARTORIUS, 0.025)
# `Drift Timbangan Mettler` seluruh kolom koreksinya nol, jadi `Max. U drift`-nya
# 0 — sementara `STANDAR KALIBRATOR!Q51` (Totalizer) dan `Q32` (Flowrate) sama-sama
# berbunyi 8,5e-05 kg. Nilainya TIDAK bisa diturunkan dari sheet drift mana pun.
# Yang dipakai budget adalah 8,5e-05, jadi itu yang disimpan — dan
# ketidakcocokannya diangkat sebagai pertanyaan lab §18, bukan didiamkan.
DRIFT_METTLER = 8.5e-05
DRIFT_FUJITSU = 7.999999999999999e-09

timbangan = {
    "totalizer": {
        "1": susun(1, DINI, kestabilan([546.2, 547.0, 546.2, 545.4], 0.8000000000000114), drift_dini),
        "2": susun(2, SARTORIUS, kestabilan([108.12, 108.22, 108.17, 108.15], 0.04999999999999716), drift_sartorius),
        "3": susun(3, METTLER_TOT, kestabilan([4.2333, 4.295999999999999, 4.2876, 4.310700000000001], 0.0387000000000004), DRIFT_METTLER),
        "4": susun(4, FUJITSU, kestabilan([999.875, 1000.005, 999.865, 999.965], 0.06999999999999318) / 1000, DRIFT_FUJITSU),
    },
    "flowrate": {
        "1": susun(1, DINI, kestabilan([546.2, 546.8, 546.5, 545.4], 0.6999999999999886), drift_dini),
        "2": susun(2, SARTORIUS, kestabilan([108.12, 108.22, 108.17, 108.15], 0.04999999999999716), drift_sartorius),
        "3": susun(3, METTLER_FLO, kestabilan([4.2333, 4.295999999999999, 4.2876, 4.310700000000001], 0.0387000000000004), DRIFT_METTLER),
    },
}

# =====================================================================
# 3. Kalibrator suhu Yokogawa + sensor TC Type K
# =====================================================================
kalibrator_suhu = {
    "nama": "Temperature Calibrator",
    "merk": "Yokogawa",
    "tipe": "CA 150 Handy Cal",
    "seri": "23P1005",
    "tertelusur": "LK-241-IDN",
    "tanggal_kalibrasi": "2025-08-12",
    "tanggal_jatuh_tempo": "2026-08-12",
    "drift_c": 0.06,
    # `E`-nya (Konversi Satuan Standart) KOSONG di baris 25 dan 50 pada kedua
    # workbook; yang dipakai VLOOKUP kolom `F` (Pembacaan Alat/UUT). Ditiru.
    "titik": [
        {"pembacaan_c": -100.0, "koreksi_c": 0.0, "u95_c": 0.35},
        {"pembacaan_c": -20.0, "koreksi_c": 0.0, "u95_c": 0.35},
        {"pembacaan_c": 0.0, "koreksi_c": 0.0, "u95_c": 0.34},
        {"pembacaan_c": 25.0, "koreksi_c": -0.02, "u95_c": 0.34},
        {"pembacaan_c": 50.0, "koreksi_c": -0.05, "u95_c": 0.34},
        {"pembacaan_c": 100.1, "koreksi_c": -0.09999999999999432, "u95_c": 0.34},
    ],
}

sensor_suhu = {
    "nama": "Thermocouple",
    "tipe": "Type K",
    "seri": "TC-01,02",
    "tertelusur": "LK-064-IDN",
    "tanggal_kalibrasi": "2024-09-06",
    "tanggal_jatuh_tempo": "2026-09-06",
    "u95_c": 0.44,
}

# =====================================================================
# 4. Timer software — koreksi tersimpan dalam DETIK, dipakai dalam MENIT
# =====================================================================
TIMER_MENTAH = [
    # (set point menit, koreksi detik, koreksi menit tersimpan master)
    (1, 0.21, 0.0035),
    (5, 0.081, 0.00135),
    (10, 0.058, 0.0009666666666666667),
    (30, 0.032, 0.0005333333333333334),
]
timer_titik = []
for menit, detik, tersimpan in TIMER_MENTAH:
    adu(f"koreksi timer {menit} min", detik / 60.0, tersimpan)
    timer_titik.append({"titik_min": float(menit), "koreksi_min": tersimpan})

# `Drift Timer Software Flowmeter` — Max. U drift 18 ms -> 0,018 s -> 0,0003 min.
adu("drift timer (ms -> min)", 18 / 1000.0 / 60.0, 0.0003)
adu("u95 timer (s -> min)", 0.14 / 60.0, 0.0023333333333333335)

timer = {
    "nama": "Stopwatch",
    "merk": "Weight Scale",
    "tipe": "Timer.FM.1",
    "seri": "SW-1",
    "tertelusur": "LK-361-IDN",
    "tanggal_kalibrasi": "2025-04-25",
    "tanggal_jatuh_tempo": "2026-04-25",
    "u95_s": 0.14,
    "u95_min": 0.0023333333333333335,
    "drift_min": 0.0003,
    "titik": timer_titik,
}

# =====================================================================
# 5. Tetapan
# =====================================================================
konstanta = {
    "densitas_udara_kg_per_l": 0.0012,
    "densitas_anak_timbang_kg_per_l": 8.0,
    "u95_densitas_air_kg_per_l": 0.0005,
    "koefisien_muai_air_per_c": 0.00021,
    "persen_bouyancy": 0.0005,
    "pembagi_rect": 1.73,
    "pembagi_normal": 2.0,
    "pembagi_drift": 2.0,
    "pembagi_ut_water": 2 * math.sqrt(3.0),
    "vi_type_b": 50.0,
    "vi_normal": 60.0,
    "volume_pipa_l": 0.10129012000000001,
}

# =====================================================================
# 6. Tulis — HANYA kalau tidak ada yang menyimpang
# =====================================================================
data = {
    "_sumber": (
        "1.2 Master olda Flowmeter Totalizer dini (2026) 140-2500L.xlsm & "
        "2.1 Master olda Flowmeter Flowrate 100-980lpm 2026.xlsm (password spirit285). "
        "Digenerate docs/skrip/gen-tabel-standar-flowmeter-gravimetri.py — jangan diketik tangan."
    ),
    "_metode": "ISO 4185 — static weighing method. PERHITUNGAN FC!B74 menulis judulnya sendiri.",
    "_penyimpangan": {
        "timbangan_3_beda_alat": (
            "Kedua workbook menyimpan timbangan ke-3 dengan tipe, S/N, nomor akreditasi, "
            "tanggal kalibrasi, DAN satuan tabel koreksi yang berbeda. Keduanya disimpan; "
            "dipilih per mode. Ditambah nama KETIGA: kertas lembar kerja Rev.3 menyebutnya "
            "`Excellent`, bukan `Mettler` — disimpan di `nama_kertas`. Pertanyaan lab §15."
        ),
        "drift_tanpa_akar3": (
            "Judul kolom sheet drift menulis 0,5·ΔC/√3, kolom yang dihitung cuma 0,5·ΔC. "
            "Ditiru; pertanyaan lab §17."
        ),
        "drift_mettler_tak_bersumber": (
            "Sheet `Drift Timbangan Mettler` seluruhnya nol, tapi tabel drift berbunyi "
            "8,5e-05 kg. Yang dipakai budget 8,5e-05. Pertanyaan lab §18."
        ),
        "u95_timbangan_beda_sheet": (
            "DATABASE menulis U95 Mettler 0,05 dan Fujitsu 0,016; STANDAR KALIBRATOR "
            "menulis 0,00017 dan 1,6e-05. Yang dipakai budget dari STANDAR KALIBRATOR. "
            "Pertanyaan lab §19."
        ),
        "densitas_diukur_setelah_sesi": (
            "Pengukuran densitas piknometer bertanggal 18 Mei 2026, sesi contoh 5 Jan 2026. "
            "Pertanyaan lab §20."
        ),
    },
    "timbangan": timbangan,
    "densitas_air": {
        "volume_piknometer_ml": VOL_PIKNO_ML,
        "tanggal_ukur": "2026-05-18",
        "pic": "NR",
        "tertelusur": "LK-285-IDN",
        "interpolasi": "linier",
        "titik": densitas_titik,
    },
    "kalibrator_suhu": kalibrator_suhu,
    "sensor_suhu": sensor_suhu,
    "timer": timer,
    "konstanta": konstanta,
}

if menyimpang:
    print("MENOLAK MENULIS — ada turunan yang tidak cocok dengan master:", file=sys.stderr)
    for baris in menyimpang:
        print("  -", baris, file=sys.stderr)
    sys.exit(1)

KELUARAN.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
jumlah = sum(len(v) for v in timbangan.values())
print(f"Ditulis {KELUARAN} — {jumlah} baris timbangan, "
      f"{len(densitas_titik)} titik densitas, {len(timer_titik)} titik timer. "
      f"Seluruh turunan cocok pada {TOL}.")

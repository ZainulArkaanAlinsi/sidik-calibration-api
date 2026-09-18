#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Generator `database/data/tabel-kalibrator-enclosure.json`.

Sumber (CSV hasil ekspor workbook master, dua direktori):

  * `Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa/STANDAR KALIBRATOR.csv`
    — satu berkas, EMPAT tabel: koreksi & U95% dua kalibrator suhu (Constant,
    Yokogawa) berdampingan, lalu koreksi & U95% sensor termokopel (PRT PT100,
    Type K 16 kanal, Type N 10 kanal) berdampingan juga. Drift satu-angka utuk
    kalibrator DAN sensor duduk di panel kanan atas berkas yang sama
    ("TABEL RECORD DRIFT" / "Sensor TC").
  * `Master_Olah_Data_Suhu_Enclosure_Recorder/Standar_Kalibrator.csv` — pola
    sama, tapi kalibrator-nya "Temperature Recorder" 20 kanal (CH1-CH20) dan
    sensor termokopel yang SAMA ditelusur ulang khusus rentang recorder.

`STANDAR-CONSTANT.csv`, `STANDAR-YOKOGAWA.csv`, `SENSOR PT100.csv`,
`TERMOCOUPLE TYPE K.csv`, `TERMOCOUPLE TYPE N.csv` di direktori
Constant_Yokogawa BUKAN sumber skrip ini — nilainya sama, tapi bentuknya
pecahan sheet yang lebih rawan salah baca (lihat catatan di bawah). Dua
`STANDAR KALIBRATOR.csv` / `Standar_Kalibrator.csv` sudah memuat semuanya
dalam satu tabel yang konsisten, dan itu yang diadu di sini.

## Tiga hal yang PERLU dibaca sebelum menyunting skrip ini

**1. `TERMOCOUPLE TYPE K.csv` / `TERMOCOUPLE TYPE N.csv` (kedua direktori)
kolom oC-nya SALAH BARIS.** Sheet itu punya kolom "Titik Kalibrasi (oC)" yang
bergeser satu baris ke atas dari kolom Correction pasangannya — baris beroC
`-20` menyimpan koreksi yang sebetulnya milik oC `0`, dst. `STANDAR
KALIBRATOR.csv` / `Standar_Kalibrator.csv` (blok "TABEL KOREKSI SENSOR" /
"TABEL NILAI KOREKSI SENSOR TERMOKOPEL") TIDAK kena — kolom oC-nya lurus
dengan koreksinya (dibuktikan: baris oC=25 di sana punya TCK-01..16 =
-0,175, PERSIS di tengah antara -0,27 (oC=0) dan -0,08 (oC=50), sementara di
`TERMOCOUPLE TYPE K.csv` nilai -0,175 itu tidak pernah muncul sama sekali).
Karena itu skrip ini SELALU membaca dari `STANDAR KALIBRATOR.csv` /
`Standar_Kalibrator.csv`, bukan dari sheet TERMOCOUPLE terpisah.

**2. Noise titik-mengambang Excel (~1e-15 s.d. 1e-18) dibulatkan ke NOL, bukan
disalin.** Sel seperti `2.8449465006019636e-15` (Type K @ 50 oC, Constant) atau
`-6.938893903907228e-18` (Type K CH15 @ 0 oC, Recorder) adalah hasil `A-A`
Excel yang mestinya nol pas tapi digit binernya meleset di ~10^-15. Ambang
`1e-9` dipakai karena titik data ASLI yang paling kecil yang pernah terlihat
di tabel-tabel ini (~1e-5, sensor PRT PT100 — dan itu pun tidak ikut ke JSON,
lihat #3) masih 10.000x lebih besar dari ambang ini.

**3. Sensor PRT PT100 per-titik (kolom `PRT PT100` di TABEL KOREKSI/U95%
SENSOR) TIDAK ikut ke JSON.** Skema `sensor.{yoko,recorder}.{koreksi,u95}`
cuma memuat `Type K`/`Type N` — dibuktikan dari berkas yang sudah ada
(`meter`/`sensor` di JSON lama tidak punya `sensor.yoko.koreksi.PT100`).
Hanya *drift* PT100 (satu angka, panel "Sensor TC") yang ikut. Kolom PT100
di tabel koreksi/U95% sensor sendiri berisi angka sisa regresi ITS-90 (orde
1e-4 s.d. 1e-1) yang juga punya dua sel `#REF!` (oC 300, 400) — bukan
dipertimbangkan di sini karena skema JSON-nya memang tidak menampungnya.

## Yang TIDAK bisa ditelusur / catatan pembulatan

Ditulis persis di docblock test `tests/Unit/TabelKalibratorEnclosureCocokMasterTest.php`
supaya satu sumber kebenaran untuk apa yang teruji dan apa yang tidak.

Jalankan:
    python docs/skrip/gen-tabel-kalibrator-enclosure.py
    python docs/skrip/gen-tabel-kalibrator-enclosure.py --keluar /tmp/out.json
"""
from __future__ import annotations

import argparse
import csv
import json
import pathlib

AKAR = pathlib.Path(__file__).resolve().parents[2]
DIR_CY = (
    AKAR
    / "Project-PT-Sidik/alat-alat-Pt-Sidik/suhu_&_kelembapan"
    / "Master_Olah_Data_Suhu_Enclosure_Constant_Yokogawa"
)
DIR_REC = (
    AKAR
    / "Project-PT-Sidik/alat-alat-Pt-Sidik/suhu_&_kelembapan"
    / "Master_Olah_Data_Suhu_Enclosure_Recorder"
)
KELUARAN_DEFAULT = AKAR / "database/data/tabel-kalibrator-enclosure.json"

AMBANG_NOISE = 1e-9  # lihat catatan #2 di docstring


def baca_csv(path: pathlib.Path) -> list[list[str]]:
    with open(path, encoding="utf-8-sig", newline="") as f:
        return [row for row in csv.reader(f)]


def sel(row: list[str], col: int) -> str:
    return row[col].strip() if col < len(row) else ""


def bulat(x: float) -> float:
    """10 angka penting, lalu bekukan noise titik-mengambang ke nol (#2)."""
    if abs(x) < AMBANG_NOISE:
        return 0.0
    return float(f"{x:.10g}")


def kunci_suhu(s: str) -> str:
    """CSV menulis suhu sebagai teks bilangan bulat ('-100', '25', ...) —
    dipertahankan apa adanya supaya kuncinya sama persis dengan JSON lama."""
    f = float(s)
    return str(int(f)) if f == int(f) else str(f)


def ambil_nilai(row: list[str], col: int) -> float | None:
    v = sel(row, col)
    if v == "" or "#REF" in v or "#" in v:
        return None
    return bulat(float(v))


def parse_grid(rows, baris_range, kol_suhu, label_ke_kolom):
    """{label: {suhu: nilai}} — dipakai untuk tabel meter (label=jenis
    sensor standar) DAN tabel channel datar. Label yang sel-nya kosong di
    SELURUH baris (mis. Type B/T/R/S di kalibrator Yokogawa yang seluruh
    Correction-nya #REF!/kosong) dibuang, bukan diikutkan sebagai dict
    kosong — supaya sama seperti JSON lama yang memang tidak punya
    kuncinya sama sekali untuk jenis itu."""
    out = {label: {} for label in label_ke_kolom}
    for r in baris_range:
        row = rows[r]
        suhu_teks = sel(row, kol_suhu)
        if suhu_teks == "":
            continue
        suhu = kunci_suhu(suhu_teks)
        for label, kol in label_ke_kolom.items():
            v = ambil_nilai(row, kol)
            if v is None:
                continue
            out[label][suhu] = v
    return {label: isi for label, isi in out.items() if isi}


def parse_drift(rows, baris_idx, kol_label, kol_nilai):
    """Panel drift satu-kolom: baris berlabel + nilai, dinormalkan ke
    {'PT100'|'Type N'|'Type K': nilai}."""
    out = {}
    for r in baris_idx:
        row = rows[r]
        label = sel(row, kol_label)
        nilai_teks = sel(row, kol_nilai)
        if label == "" or nilai_teks == "":
            continue
        if "PT100" in label:
            kunci = "PT100"
        elif "Type N" in label:
            kunci = "Type N"
        elif "Type K" in label:
            kunci = "Type K"
        else:
            continue
        out[kunci] = bulat(float(nilai_teks))
    return out


def urutan_suhu(*grids) -> list[str]:
    """Urutan-tampil suhu, union unik dari beberapa tabel `{label: {suhu:
    nilai}}`, urutan kemunculan pertama dipertahankan (union koreksi lalu
    U95%, sesuai baris master)."""
    out: list[str] = []
    seen = set()
    for grid in grids:
        for suhu_dict in grid.values():
            for suhu in suhu_dict:
                if suhu not in seen:
                    seen.add(suhu)
                    out.append(suhu)
    return out


def urutan_suhu_channel(*grids) -> list[str]:
    """Sama seperti `urutan_suhu`, tapi untuk tabel `meter.recorder` yang
    tiga lapis: `{jenis: {channel: {suhu: nilai}}}`."""
    out: list[str] = []
    seen = set()
    for grid in grids:
        for channel_dict in grid.values():
            for suhu_dict in channel_dict.values():
                for suhu in suhu_dict:
                    if suhu not in seen:
                        seen.add(suhu)
                        out.append(suhu)
    return out


def main(keluaran: pathlib.Path) -> None:
    cy = baca_csv(DIR_CY / "STANDAR KALIBRATOR.csv")
    rec = baca_csv(DIR_REC / "Standar_Kalibrator.csv")

    # ============================================================ meter.constant / meter.yokogawa
    # Baris 9..26 (0-based) = suhu -100..1700, Constant kolom 1-9,
    # Yokogawa kolom 12-20 (offset +11). Type J SENGAJA tidak ikut — tidak
    # ada di skema JSON (kolomnya kosong di kedua kalibrator, lihat CSV).
    JENIS_METER = ["PT100", "Type K", "Type N", "Type B", "Type T", "Type R", "Type S"]
    BARIS_METER = range(9, 27)
    BARIS_METER_U95 = range(31, 49)

    kolom_constant = {j: 2 + i for i, j in enumerate(JENIS_METER)}
    kolom_yokogawa = {j: 13 + i for i, j in enumerate(JENIS_METER)}

    meter_constant_koreksi = parse_grid(cy, BARIS_METER, 1, kolom_constant)
    meter_yokogawa_koreksi = parse_grid(cy, BARIS_METER, 12, kolom_yokogawa)
    meter_constant_u95 = parse_grid(cy, BARIS_METER_U95, 1, kolom_constant)
    meter_yokogawa_u95 = parse_grid(cy, BARIS_METER_U95, 12, kolom_yokogawa)

    # Drift: panel "TABEL RECORD DRIFT" kanan-atas, baris 5-7, kolom
    # 23/24 (Constant) dan 25/26 (Yokogawa).
    meter_constant_drift = parse_drift(cy, range(5, 8), 23, 24)
    meter_yokogawa_drift = parse_drift(cy, range(5, 8), 25, 26)

    # ============================================================ sensor.yoko
    # "TABEL KOREKSI SENSOR" / "TABEL U95% SENSOR": kolom suhu=1, PT100=2
    # (TIDAK ikut, #3), TCK-01..16 kolom 3..18, TCN3..12 kolom 19..28.
    kolom_tck = {str(i): 2 + i for i in range(1, 17)}
    kolom_tcn = {str(i): 16 + i for i in range(3, 13)}

    BARIS_SENSOR_YOKO_KOREKSI = range(63, 78)
    BARIS_SENSOR_YOKO_U95 = range(84, 99)

    sensor_yoko_koreksi = {
        "Type K": parse_grid(cy, BARIS_SENSOR_YOKO_KOREKSI, 1, kolom_tck),
        "Type N": parse_grid(cy, BARIS_SENSOR_YOKO_KOREKSI, 1, kolom_tcn),
    }
    sensor_yoko_u95 = {
        "Type K": parse_grid(cy, BARIS_SENSOR_YOKO_U95, 1, kolom_tck),
        "Type N": parse_grid(cy, BARIS_SENSOR_YOKO_U95, 1, kolom_tcn),
    }

    # Drift sensor: panel "Sensor TC", baris 11-13, kolom 23/24.
    sensor_yoko_drift = parse_drift(cy, range(11, 14), 23, 24)

    # ============================================================ meter.recorder
    # "TABEL NILAI KOREKSI/U95% TEMPERATURE RECORDER": suhu kolom 1,
    # Type N CH1-20 kolom 3-22, Type K CH1-20 kolom 23-42.
    kolom_ch_typen = {str(i): 2 + i for i in range(1, 21)}
    kolom_ch_typek = {str(i): 22 + i for i in range(1, 21)}

    BARIS_RECORDER_KOREKSI = range(8, 24)
    BARIS_RECORDER_U95 = range(29, 45)

    meter_recorder_koreksi = {
        "Type N": parse_grid(rec, BARIS_RECORDER_KOREKSI, 1, kolom_ch_typen),
        "Type K": parse_grid(rec, BARIS_RECORDER_KOREKSI, 1, kolom_ch_typek),
    }
    meter_recorder_u95 = {
        "Type N": parse_grid(rec, BARIS_RECORDER_U95, 1, kolom_ch_typen),
        "Type K": parse_grid(rec, BARIS_RECORDER_U95, 1, kolom_ch_typek),
    }

    # Drift: panel "Recorder" kanan-atas, baris 7-8, kolom 45/46.
    meter_recorder_drift = parse_drift(rec, range(7, 9), 45, 46)

    # ============================================================ sensor.recorder
    # "TABEL NILAI KOREKSI/U95% SENSOR TERMOKOPEL": suhu kolom 1,
    # TCN3-12 kolom 3-12 (kolom == nomor kanal), TCK-01..16 kolom 23-38.
    kolom_tcn_rec = {str(i): i for i in range(3, 13)}
    kolom_tck_rec = {str(i): 22 + i for i in range(1, 17)}

    BARIS_SENSOR_REC_KOREKSI = range(52, 67)
    BARIS_SENSOR_REC_U95 = range(71, 86)

    sensor_recorder_koreksi = {
        "Type K": parse_grid(rec, BARIS_SENSOR_REC_KOREKSI, 1, kolom_tck_rec),
        "Type N": parse_grid(rec, BARIS_SENSOR_REC_KOREKSI, 1, kolom_tcn_rec),
    }
    sensor_recorder_u95 = {
        "Type K": parse_grid(rec, BARIS_SENSOR_REC_U95, 1, kolom_tck_rec),
        "Type N": parse_grid(rec, BARIS_SENSOR_REC_U95, 1, kolom_tcn_rec),
    }

    # Drift sensor: panel "Sensor TC", baris 14-15, kolom 45/46.
    sensor_recorder_drift = parse_drift(rec, range(14, 16), 45, 46)

    # ============================================================ index_temps
    # Union suhu tabel koreksi lalu U95% METER (bukan sensor) — urutan
    # kemunculan pertama, sama seperti yang tersirat di JSON lama.
    index_temps_yoko = urutan_suhu(meter_constant_koreksi, meter_constant_u95)
    index_temps_recorder = urutan_suhu_channel(meter_recorder_koreksi, meter_recorder_u95)

    data = {
        "meter": {
            "constant": {
                "koreksi": meter_constant_koreksi,
                "u95": meter_constant_u95,
                "drift": meter_constant_drift,
            },
            "yokogawa": {
                "koreksi": meter_yokogawa_koreksi,
                "u95": meter_yokogawa_u95,
                "drift": meter_yokogawa_drift,
            },
            "recorder": {
                "koreksi": meter_recorder_koreksi,
                "u95": meter_recorder_u95,
                "drift": meter_recorder_drift,
            },
        },
        "sensor": {
            "yoko": {
                "koreksi": sensor_yoko_koreksi,
                "u95": sensor_yoko_u95,
                "drift": sensor_yoko_drift,
            },
            "recorder": {
                "koreksi": sensor_recorder_koreksi,
                "u95": sensor_recorder_u95,
                "drift": sensor_recorder_drift,
            },
        },
        "index_temps": {
            "yoko": index_temps_yoko,
            "recorder": index_temps_recorder,
        },
    }

    keluaran.write_text(json.dumps(data, indent=1, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"Ditulis {keluaran}")


if __name__ == "__main__":
    ap = argparse.ArgumentParser()
    ap.add_argument("--keluar", type=pathlib.Path, default=KELUARAN_DEFAULT)
    args = ap.parse_args()
    main(args.keluar)

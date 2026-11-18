#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Generator `tests/Fixtures/cetak-master/kelompok-b.json` (PK-5, 18 Nov 2026).

Angka yang TERCETAK di lembar `SERTIFIKAT` workbook master kelompok B: Gaya
(Load Cell, UTM, Proving Ring), Enclosure (Constant Yokogawa, Recorder),
Thermocouple, Termometer Gelas, Sieve, Autoclave, Timer/Stopwatch, pH meter,
dan Flowmeter Ultrasonic (Totalizer, Flowrate). Dipakai
`*SesiContohCocokMasterTest` untuk mengadu angka yang dicetak `sertifikat.pdf`
(renderer asli) ke angka yang dicetak master — bukan ke keluaran kode.

    py -3.12 -I -X utf8 docs/skrip/gen-cetak-master-kelompok-b.py <folder-master> [<folder-master> ...] [--cek]

Ini TIDAK perkakas baru: pembaca sel, pembulatan ala Excel, dan penyandian
baris dipinjam apa adanya dari `gen-cetak-master-massa-volume.py` (PK-4), yang
pembulatannya sudah dibuktikan lawan `Range.Text` Excel
(`cek-teks-cetak-excel.ps1`). Yang beda hanya daftar master dan rentang barisnya.
Format sel `General` berpecahan (mis. `k` = 1,9939...) tetap TIDAK ditebak:
`cetak` = null, dan test melewatkannya dengan alasan tertulis.

## Baris dan kolom TERSEMBUNYI tidak dicetak

Excel tidak mencetak baris/kolom yang disembunyikan, padahal `Range.Text` dan
openpyxl tetap membacanya. Master menyembunyikan baris titik nol (Gaya), baris
set point kosong (Enclosure, Thermocouple, Termometer Gelas, Autoclave, Timer),
dan baris `#N/A` (Flowmeter). Tanpa penyaringan ini baris-baris itu menjadi
"selisih" palsu — aplikasi memang tidak mencetaknya, dan master pun tidak.
Karena itu `baris_tersembunyi` dan sel di kolom tersembunyi dibuang di sini, dan
daftar baris yang dibuang dicatat di fixture (`baris_tersembunyi`) supaya
terbaca di diff.

## Dua salinan workbook: urutan folder menentukan

Sieve dan Timer ada di DUA salinan berisi beda (sha256 beda): `Downloads/` (Sep 2026) dan
`Downloads/EXEL CALIBRATION SIDIK/` (Nov 2026). Fixture ini dibekukan dari salinan EXEL: sebut
folder EXEL PERTAMA di baris perintah. `--cek` hanya melapor SAMA bila urutan itu dipenuhi (generator
mengambil berkas dari folder pertama yang memilikinya). Salinan mana yang otoritatif belum
dikonfirmasi lab; sha256 tiap master yang dipakai tercatat di fixture.

Sandi master dari env `SIDIK_MASTER_SANDI` (tidak dicetak, tidak ditulis).
`--cek` = jangan tulis, hanya laporkan bila fixture lama beda (exit 1).
"""
from __future__ import annotations

import importlib.util
import json
import pathlib
import sys

AKAR = pathlib.Path(__file__).resolve().parents[2]
KELUARAN = AKAR / "tests/Fixtures/cetak-master/kelompok-b.json"

_spec = importlib.util.spec_from_file_location("gen_cetak_pk4", pathlib.Path(__file__).with_name("gen-cetak-master-massa-volume.py"))
pk4 = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(pk4)

# kunci -> (berkas, lembar, baris awal, baris akhir, kolom maksimum)
#
# Rentang dipilih dari lembar `SERTIFIKAT` masing-masing master (dibaca 18 Nov
# 2026): hanya tabel hasil dan kalimat k/U95 — bukan kop, kapasitas, kondisi
# lingkungan, "Standard used", dan bukan kolom di luar area cetak (Sieve kolom
# X ke kanan = "Unprinted area"; Flowmeter kolom X ke kanan = hitungan antara).
SUMBER = {
    "gaya/load_cell": ("Master Olah Data_Gaya_Load Cell.xlsm", "SERTIFIKAT", 19, 37, "AF"),
    "gaya/utm": ("Master Olah Data_Gaya_UTM.xlsm", "SERTIFIKAT", 19, 37, "AF"),
    "gaya/proving_ring": ("Master Olah Data_Gaya_Profing Ring.xlsm", "SERTIFIKAT", 20, 40, "AF"),
    "enclosure/constant_yokogawa": ("Master Olah Data_Suhu_Enclosure_Constant Yokogawa.xlsm", "SERTIFIKAT", 24, 47, "W"),
    "enclosure/recorder": ("Master Olah Data_Suhu_Enclosure_Recorder.xlsm", "SERTIFIKAT", 24, 47, "W"),
    "suhu/thermocouple": ("Master Olah Data_Suhu_Thermocouple.xlsm", "SERTIFIKAT", 19, 35, "W"),
    "suhu/thermometer_glass": ("Master Olah Data_Suhu_Thermometer Glass.xlsm", "SERTIFIKAT", 20, 36, "W"),
    "sieve/mesh": ("Master Olah Data_Sieve Mesh.xlsm", "SERTIFIKAT", 18, 28, "V"),
    "autoclave/autoclave": ("Master Olah Data_Autoclave.xlsm", "SERTIFIKAT", 24, 43, "W"),
    "timer/stopwatch": ("Master Olda_Timer dan Stopwatch.xlsm", "SERTIFIKAT", 17, 29, "Y"),
    "ph/imte_wq_129": ("pH meter_IMTE-WQ-129.xlsm", "SERTIFIKAT", 19, 24, "Y"),
    "ph/trial": ("Master Olah Data_pH for trial.xlsm", "SERTIFIKAT", 19, 24, "Y"),
    "flowmeter/ultrasonic_totalizer": ("1.3 Master Olah Data Flowmeter_Ultrasonic Totalizer (1000-1900L) 2026.xlsm", "SERTIFIKAT", 20, 26, "Q"),
    "flowmeter/ultrasonic_flowrate": ("Master olda Ultrasonic Flowrate (100-300 lpm) 2026.xlsm", "SERTIFIKAT", 24, 29, "Q"),
}


def kolom_tersembunyi(ws) -> set[int]:
    """Indeks kolom (1-berbasis) yang disembunyikan di lembar `ws`."""
    hasil: set[int] = set()
    for d in ws.column_dimensions.values():
        if d.hidden and d.min and d.max:
            hasil.update(range(d.min, min(d.max, 200) + 1))
    return hasil


def baris_tercetak(ws, awal: int, akhir: int, kolom: str):
    """`baris_sertifikat` PK-4, minus baris dan kolom yang disembunyikan master."""
    sembunyi_baris = [r for r in range(awal, akhir + 1) if ws.row_dimensions[r].hidden]
    sembunyi_kolom = kolom_tersembunyi(ws)
    hasil = []
    for b in pk4.baris_sertifikat(ws, awal, akhir, kolom):
        if b["baris"] in sembunyi_baris:
            continue
        sel = [s for s in b["sel"] if pk4.column_index_from_string("".join(c for c in s["sel"] if c.isalpha())) not in sembunyi_kolom]
        if sel:
            hasil.append({"baris": b["baris"], "sel": sel})
    return sembunyi_baris, hasil


def main() -> int:
    argv = [a for a in sys.argv[1:] if a != "--cek"]
    cek = "--cek" in sys.argv
    if not argv:
        sys.exit(__doc__)
    folder = [pathlib.Path(a) for a in argv]

    keluar = {
        "_catatan": (
            "Dibekukan oleh docs/skrip/gen-cetak-master-kelompok-b.py dari workbook master asli: "
            "angka sel + kode number_format + teks cetak ala Excel. Tanpa teks sel, tanpa data pelanggan. "
            "Jangan disunting tangan."
        ),
        "master": {},
    }
    for kunci, (nama, lembar, awal, akhir, kolom) in SUMBER.items():
        jalur = next((f / nama for f in folder if (f / nama).is_file()), None)
        if jalur is None:
            sys.exit(f"{kunci}: berkas '{nama}' tidak ada di folder yang diberikan.")
        wb, sha = pk4.buka(jalur)
        sembunyi, baris = baris_tercetak(wb[lembar], awal, akhir, kolom)
        keluar["master"][kunci] = {
            "berkas": nama,
            "sha256": sha,
            "lembar": lembar,
            "rentang_baris": [awal, akhir],
            "kolom_maks": kolom,
            "baris_tersembunyi": sembunyi,
            "baris": baris,
        }

    teks = json.dumps(keluar, indent=1, ensure_ascii=False) + "\n"
    if cek:
        lama = KELUARAN.read_text(encoding="utf-8") if KELUARAN.exists() else ""
        if lama != teks:
            print("BEDA: fixture lama tidak sama dengan bacaan master sekarang.")
            return 1
        print("SAMA.")
        return 0
    KELUARAN.parent.mkdir(parents=True, exist_ok=True)
    KELUARAN.write_text(teks, encoding="utf-8")
    print(f"Ditulis {KELUARAN.relative_to(AKAR)} ({len(keluar['master'])} master).")
    return 0


if __name__ == "__main__":
    sys.exit(main())

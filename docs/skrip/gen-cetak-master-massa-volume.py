#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Generator `tests/Fixtures/cetak-master/massa-volume.json` (PK-4, 17 Nov 2026).

Angka yang TERCETAK di lembar `SERTIFIKAT` workbook master kelompok Massa &
Volume: Timbangan (kg, gram, substitusi), Anak Timbangan (F1, F2 hal 1-3),
Volumetric Glassware (Fixed, Graduated), dan Piston Volume (Fixed, Graduated).
Dipakai `*SesiContohCocokMasterTest` untuk mengadu angka yang dicetak
`sertifikat.pdf` (renderer asli) ke angka yang dicetak master — bukan ke
keluaran kode ini.

    py -3.12 -I docs/skrip/gen-cetak-master-massa-volume.py <folder-master> [<folder-master> ...] [--cek]

Dibutuhkan `openpyxl` dan `msoffcrypto-tool`. Sandi master dibaca dari env
`SIDIK_MASTER_SANDI` (atau HKCU\\Environment bila env proses tak membawanya);
tidak pernah dicetak dan tidak pernah ditulis ke keluaran. `--cek` = jangan
tulis, hanya laporkan bila fixture lama beda dari bacaan sekarang (exit 1).

## Yang ditulis, dan yang TIDAK

Per baris lembar `SERTIFIKAT` di rentang yang dipilih: sel angka (alamat,
nilai G17, kode number_format, dan teks cetak yang dihitung dari keduanya
sebagaimana Excel membulatkan). TIDAK ada teks sel, nama pelanggan, alamat,
nomor seri, atau tanggal. Teks cetak untuk sel berformat `General` dengan
bilangan pecahan TIDAK ditebak (Excel memilih jumlah digit menurut lebar
kolom): `cetak` = null dan test melewatkannya dengan alasan tertulis.

## Pembulatan = cara Excel (dibuktikan terhadap Excel sendiri)

Teks cetak diverifikasi terhadap `Range.Text` Excel (COM) lewat
`docs/skrip/cek-teks-cetak-excel.ps1` — Excel sendiri yang menjadi hakim
tampilan, bukan emulasi ini. Hasil 17 Nov 2026 ada di rincian PK-4.

Excel membulatkan dari representasi 15 angka penting nilai tersimpan, bukan
dari 17 digit float: 0,7549999999999955 dengan format `0.00` tercetak `0.75`
(15 angka penting -> 0,754999999999996 -> dua desimal -> `0.75`), sedangkan
nilai yang lebih dulu dibulatkan ke 8 desimal (0,75500000, kolom decimal(20,8))
tercetak `0.76`. Itu yang dipakai di sini, setengah-menjauhi-nol. Selisih seri ini
bukan urusan generator: `PembulatanTitikSeriMasterTest` yang menjaganya.

## Cache master

Nilai yang dibaca = nilai cache yang tersimpan di berkas (data_only). Cache
bisa basi bila ada tautan luar (contoh terbukti: tautan `[3]` master
substitusi, `docs/pertanyaan-lab-timbangan.md` T7). Baris yang bergantung pada
tautan luar tetap ditulis apa adanya; selisihnya muncul sebagai temuan di
test, tidak dikoreksi di sini.
"""
from __future__ import annotations

import datetime
import hashlib
import io
import json
import os
import pathlib
import re
import sys
from decimal import ROUND_HALF_UP, Decimal

import msoffcrypto
import openpyxl
from openpyxl.utils import column_index_from_string

AKAR = pathlib.Path(__file__).resolve().parents[2]
KELUARAN = AKAR / "tests/Fixtures/cetak-master/massa-volume.json"

# kunci -> (berkas, lembar, baris awal, baris akhir, kolom maksimum)
#
# Rentang dipilih dari lembar `SERTIFIKAT` masing-masing master (dibaca 17 Nov
# 2026): hanya tabel hasil dan kalimat k/U95 — bukan kop, bukan "Standard
# used", bukan tabel MPE/kelas di sisi kanan (kolom AM ke kanan di Timbangan).
SUMBER = {
    "timbangan/kg": ("(New) Master Olda Timbangan kg.xlsm", "SERTIFIKAT", 16, 56, "AL"),
    "timbangan/gram": ("(New) Master Olda Timbangan gram.xlsm", "SERTIFIKAT", 16, 56, "AL"),
    "timbangan/substitusi": ("(TERBARU) Master Olda Timbangan Subtitusi 291025.xlsm", "SERTIFIKAT", 16, 56, "AL"),
    "anak_timbangan/f1": ("1. 1 Anak Timbangan F1 1mg-500 g 202501022 imp.xlsx", "SERTIFIKAT", 14, 41, "P"),
    "anak_timbangan/f2_hal1": ("Anak timbangan F2_IMTE-LQ-197 hal 1.xlsx", "SERTIFIKAT", 14, 41, "P"),
    "anak_timbangan/f2_hal2": ("Anak timbangan F2_IMTE-LQ-197 hal 2.xlsx", "SERTIFIKAT", 14, 41, "P"),
    "anak_timbangan/f2_hal3": ("Anak timbangan F2_IMTE-LQ-197 hal 3.xlsx", "SERTIFIKAT", 14, 41, "P"),
    "volumetric/fixed": ("(NEW) Master Olah Data_Fixed Volumetric Glassware_2026.xlsm", "SERTIFIKAT", 18, 24, "Z"),
    "volumetric/graduated": ("(New) Master Olah Data_Graduated Volumetric Glassware _ 2026.xlsm", "SERTIFIKAT", 18, 28, "Z"),
    "piston_volume/fixed": ("Master Olah Data_Fixed Piston Volume.xlsm", "SERTIFIKAT", 18, 24, "Y"),
    "piston_volume/graduated": ("Master Olah Data_Graduated Piston Volume.xlsm", "SERTIFIKAT", 18, 26, "Y"),
}

GALAT = {"#VALUE!", "#N/A", "#REF!", "#DIV/0!", "#NAME?", "#NUM!"}
# Nominal kembar Anak Timbangan dicetak master sebagai teks berbintang (`2*`, `20*`,
# `200*`). Untuk pencocokan ANGKA bintangnya dibuang (`cetak`) tetapi tidak
# disembunyikan: `bintang: true` ikut tertulis di fixture, dan aplikasi TIDAK
# mencetak bintang itu — selisih terbuka PK4-8
# (docs/pertanyaan-lab-adu-cetak-massa-volume.md, docs/pertanyaan-lab-anak-timbangan.md
# §26), dikunci di AnakTimbanganSesiContohCocokMasterTest.
ANGKA_TEKS = re.compile(r"^-?\d+(\.\d+)?\*?$")


def sandi() -> str:
    s = os.environ.get("SIDIK_MASTER_SANDI")
    if s:
        return s
    try:
        import winreg

        with winreg.OpenKey(winreg.HKEY_CURRENT_USER, "Environment") as k:
            return winreg.QueryValueEx(k, "SIDIK_MASTER_SANDI")[0]
    except Exception:  # pragma: no cover - lingkungan tanpa registry
        sys.exit("SIDIK_MASTER_SANDI tidak tersedia (env proses maupun HKCU\\Environment).")


def buka(jalur: pathlib.Path):
    data = jalur.read_bytes()
    buf = io.BytesIO(data)
    if data[:4] == b"\xd0\xcf\x11\xe0":
        keluar = io.BytesIO()
        f = msoffcrypto.OfficeFile(buf)
        f.load_key(password=sandi())
        f.decrypt(keluar)
        buf = keluar
    buf.seek(0)
    return openpyxl.load_workbook(buf, data_only=True), hashlib.sha256(data).hexdigest()


def format_excel(nilai, kode: str):
    """Teks cetak Excel untuk angka `nilai` berformat `kode`; None bila tak pasti."""
    if isinstance(nilai, bool):
        return None
    kode = (kode or "General").split(";")[0]
    if kode == "General":
        if float(nilai) == int(nilai) and abs(nilai) < 1e11:
            return str(int(nilai))
        return None
    m = re.fullmatch(r"0(?:\.(0+))?", kode)
    if not m:
        return None
    desimal = len(m.group(1) or "")
    d = Decimal(format(float(nilai), ".15g"))
    q = d.quantize(Decimal(1).scaleb(-desimal), rounding=ROUND_HALF_UP)
    teks = format(q, "f")
    # Excel tidak mencetak tanda minus bila angka negatif membulat ke nol
    # (-0,0003 berformat `0.00` tercetak `0.00`): dibuktikan lewat `Range.Text`
    # Excel (COM) 17 Nov 2026 pada L29/L30 master Timbangan kg.
    if q == 0 and teks.startswith("-"):
        teks = teks[1:]
    return teks


def baris_sertifikat(ws, awal: int, akhir: int, kol_maks: str):
    batas = column_index_from_string(kol_maks)
    hasil = []
    for baris in ws.iter_rows(min_row=awal, max_row=akhir, max_col=batas):
        sel_baris = []
        for c in baris:
            v = c.value
            if v is None or isinstance(v, (bool, datetime.datetime, datetime.date)):
                continue
            if isinstance(v, str):
                if v in GALAT:
                    sel_baris.append({"sel": c.coordinate, "galat": v})
                elif ANGKA_TEKS.match(v):
                    sel_baris.append({
                        "sel": c.coordinate,
                        "teks": v,
                        # Bintang dibuang dari `cetak` supaya baris dapat dicocokkan per angka;
                        # keberadaannya tetap tercatat di `bintang` (PK4-8, bukan "sengaja diabaikan").
                        "cetak": v.rstrip("*"),
                        "bintang": v.endswith("*"),
                    })
                continue
            if isinstance(v, (int, float)):
                sel_baris.append({
                    "sel": c.coordinate,
                    "nilai": repr(float(v)) if isinstance(v, float) else v,
                    "format": c.number_format,
                    "cetak": format_excel(v, c.number_format),
                })
        if sel_baris:
            hasil.append({"baris": baris[0].row, "sel": sel_baris})
    return hasil


# Masukan `INPUT DATA` Timbangan yang TIDAK dibawa sesi contoh seeder, tetapi
# tercetak di master: Effect of Tare (standar, m1, m2) dan beban Loading
# Influence. Alamatnya per varian karena substitusi bergeser satu baris di
# blok tare dan dua baris di blok eksentrisitas.
MASUKAN_TIMBANGAN = {
    "timbangan/kg": {"standar": "K40", "m1": "O40", "m2": "O41", "beban": "I67"},
    "timbangan/gram": {"standar": "K40", "m1": "O40", "m2": "O41", "beban": "I67"},
    "timbangan/substitusi": {"standar": "K39", "m1": "O39", "m2": "O40", "beban": "I69"},
}


def masukan_timbangan(wb, alamat: dict) -> dict:
    ws = wb["INPUT DATA"]
    hasil = {}
    for nama, sel in alamat.items():
        v = ws[sel].value
        hasil[nama] = {"sel": "INPUT DATA!" + sel, "nilai": float(v)}
    return hasil


def masukan_f2(wb) -> dict:
    """Bacaan ABBA keping F2 dari `INPUT DATA` (blok 1-8: dua kelompok baris pertama).

    Dua belas blok sisanya (baris 54-75) di workbook F2 adalah salinan sesi lain
    (`docs/pertanyaan-lab-anak-timbangan.md` §30) dan sengaja tidak diambil.
    """
    ws = wb["INPUT DATA"]
    kolom = [("C", "E"), ("I", "K"), ("N", "P"), ("T", "V")]
    keping = []
    nomor = 0
    for awal in (38, 46):
        for k_nilai, k_baca in kolom:
            nomor += 1
            nominal = ws[f"{k_nilai}{awal + 1}"].value
            baca = [ws[f"{k_baca}{awal + 2 + i}"].value for i in range(4)]
            if not isinstance(nominal, (int, float)) or not all(isinstance(b, (int, float)) for b in baca):
                continue
            keping.append({
                "blok": nomor,
                "sel_nominal": f"{k_nilai}{awal + 1}",
                "nominal": nominal,
                "s1": baca[0], "t1": baca[1], "t2": baca[2], "s2": baca[3],
            })
    lingkungan = {
        "suhu": [ws["E21"].value, ws["F21"].value],
        "kelembaban": [ws["E22"].value, ws["F22"].value],
        "tekanan": [ws["E24"].value, ws["F24"].value],
        "kelas_uut": ws["AB24"].value,
        "kelas_standar": ws["AB25"].value,
        "neraca": ws["AB20"].value,
    }
    return {"keping": keping, "lingkungan": lingkungan}


def main() -> int:
    argv = [a for a in sys.argv[1:] if a != "--cek"]
    cek = "--cek" in sys.argv
    if not argv:
        sys.exit(__doc__)
    folder = [pathlib.Path(a) for a in argv]

    keluar = {
        "_catatan": (
            "Dibekukan oleh docs/skrip/gen-cetak-master-massa-volume.py dari workbook master asli: "
            "angka sel + kode number_format + teks cetak ala Excel. Tanpa teks sel, tanpa data pelanggan. "
            "Jangan disunting tangan."
        ),
        "master": {},
    }
    for kunci, (nama, lembar, awal, akhir, kolom) in SUMBER.items():
        jalur = next((f / nama for f in folder if (f / nama).is_file()), None)
        if jalur is None:
            sys.exit(f"{kunci}: berkas '{nama}' tidak ada di folder yang diberikan.")
        wb, sha = buka(jalur)
        keluar["master"][kunci] = {
            "berkas": nama,
            "sha256": sha,
            "lembar": lembar,
            "rentang_baris": [awal, akhir],
            "kolom_maks": kolom,
            "baris": baris_sertifikat(wb[lembar], awal, akhir, kolom),
        }
        if kunci.startswith("anak_timbangan/f2_"):
            keluar["master"][kunci]["masukan"] = masukan_f2(wb)
        if kunci in MASUKAN_TIMBANGAN:
            keluar["master"][kunci]["masukan"] = masukan_timbangan(wb, MASUKAN_TIMBANGAN[kunci])

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

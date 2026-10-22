#!/usr/bin/env python3
"""Generator tabel acuan (lapis 1) profil instrumen analitik yang dulu konstanta kode.

    python -I docs/skrip/gen-tabel-standar-analitik.py <paket> "<master .xlsm>"

Dibaca dari ALAMAT SEL master (22 Okt 2026); alamat yang sama dipakai peta sel
Studio `database/data/peta-sel/<paket>.json`. Master dibaca di tempat,
didekripsi ke MEMORI (sandi dari HKCU Environment `SIDIK_MASTER_SANDI`, tidak
dicetak); tidak ada salinan yang ditulis. Generator MENOLAK menulis bila label
penjaga bergeser atau tabelnya tidak lagi berbentuk yang dimodelkan kode.

Paket:

  refractometer — `Master Olah Data_Refractometer.xlsm`, lembar
    `Tab Konversi Temperatur` (B4:H116, T 19,0..30,2 °C step 0,1). Kode
    memodelkan tabel itu sebagai garis lurus `deviasi = -k·(T-20)`; k dibaca
    dari baris T = 19 (`E4` °Brix, `H4` n20D: deviasi pada 1 °C di bawah 20 °C)
    dan dibulatkan 10 desimal (derau float Excel `0.07000000000000028`). Semua
    113 baris diadu ke garis itu — tabel yang tidak linear lagi DITOLAK, karena
    kode tidak bisa mewakilinya dengan satu koefisien. Batas tabel = `B4` dan
    `B116` (round 10: kolom T menumpuk derau `30.2000000000002`).

"""
import io
import json
import pathlib
import sys
import winreg

import msoffcrypto
import openpyxl

PAKET, BERKAS = sys.argv[1], pathlib.Path(sys.argv[2])
REPO = pathlib.Path(__file__).resolve().parents[2]


def buka(path):
    data = path.read_bytes()
    buf = io.BytesIO(data)
    if data[:4] == b"\xd0\xcf\x11\xe0":
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


def sel(lembar, alamat):
    return WB[lembar][alamat].value


def label(lembar, alamat, harap):
    v = sel(lembar, alamat)
    if str(v).strip() != harap:
        sys.exit(f"MENOLAK: {lembar}!{alamat} = {v!r}, harap {harap!r} (struktur bergeser)")


def angka(lembar, alamat):
    v = sel(lembar, alamat)
    if isinstance(v, bool) or not isinstance(v, (int, float)):
        sys.exit(f"MENOLAK: {lembar}!{alamat} = {v!r}, bukan angka")
    return float(v)


def tulis(nama, isi):
    keluar = REPO / "database/data" / nama
    keluar.write_text(json.dumps(isi, ensure_ascii=False, indent=1) + "\n", encoding="utf-8", newline="\n")
    print("Ditulis", keluar)


def refractometer():
    t = "Tab Konversi Temperatur"
    for a, h in [("B2", "T"), ("C2", "Observed Value"), ("F2", "Observed Value"), ("B3", "oC"), ("C3", "oBrix"), ("F3", "n20D")]:
        label(t, a, h)
    if angka(t, "B4") != 19.0:
        sys.exit(f"MENOLAK: {t}!B4 = {sel(t, 'B4')!r}, harap 19 (k dibaca dari baris 1 °C di bawah 20 °C)")
    k_brix = round(angka(t, "E4"), 10)
    k_n20d = round(angka(t, "H4"), 10)
    for b in range(4, 117):
        T = angka(t, f"B{b}")
        if abs(T - (19.0 + (b - 4) * 0.1)) > 1e-9:
            sys.exit(f"MENOLAK: {t}!B{b} = {T}, bukan langkah 0,1 °C")
        for kol_obs, kol_kor, k in [("C", "D", k_brix), ("F", "G", k_n20d)]:
            obs, kor = angka(t, f"{kol_obs}{b}"), angka(t, f"{kol_kor}{b}")
            if abs(kor - (obs + (T - 20.0) * k)) > 1e-12:
                sys.exit(f"MENOLAK: {t}!{kol_kor}{b} = {kor} tidak di garis k = {k} (tabel tidak linear lagi)")
    tulis("tabel-standar-refractometer.json", {
        "_sumber": "Master Olah Data_Refractometer.xlsm 'Tab Konversi Temperatur'!B4:H116 (docs/skrip/gen-tabel-standar-analitik.py). JANGAN diedit tangan.",
        "koreksi_suhu": {
            "koef_n20d_per_c": k_n20d,
            "koef_brix_per_c": k_brix,
            "suhu_tabel_min_c": round(angka(t, "B4"), 10),
            "suhu_tabel_maks_c": round(angka(t, "B116"), 10),
        },
    })


{
    "refractometer": refractometer,
}.get(PAKET, lambda: sys.exit(f"paket tak dikenal: {PAKET}"))()

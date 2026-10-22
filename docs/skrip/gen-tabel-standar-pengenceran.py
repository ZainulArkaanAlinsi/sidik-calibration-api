#!/usr/bin/env python3
"""Generator tabel standar pengenceran Chlorine Meter & DO Meter (lapis 1).

    python -I docs/skrip/gen-tabel-standar-pengenceran.py chlorine_meter "<Master Olah Data_Chlorine Meter.xlsm>"
    python -I docs/skrip/gen-tabel-standar-pengenceran.py do_meter "<Master Olah Data_DO Meter.xlsm>"

Dibaca dari ALAMAT SEL master (22 Okt 2026) — dulu konstanta kode
`ChlorineProfile::U95_MICROPIPETTE_ML/U95_LABU_UKUR_ML` dan
`DoMeterProfile::U95_MICROPIPETTE/U95_LABU_UKUR`. Alamat yang sama dipakai peta
sel Studio `database/data/peta-sel/<paket>.json`. Master dibaca di tempat,
didekripsi ke MEMORI (sandi dari HKCU Environment `SIDIK_MASTER_SANDI`, tidak
dicetak); tidak ada salinan yang ditulis.

  Chlorine Meter: DATABASE!W20 (Micropippete, 0,89 µL) & W21 (Labu Ukur, mL).
    Master memakai `PERHITUNGAN U95%!Q12 = W20/1000` (mL); nilai Excel itu
    0.0008900000000000001 (derau float), jadi disimpan round(W20/1000, 10) —
    sama dengan konstanta kode lama 0.00089 (pola generator Micrometer).
  DO Meter: DATABASE!V17 (Micropippete, µL — dibagi 1000 DI DALAM rumus
    `Q16 = V17/2/1000`) & V18 (Labu Ukur, mL), disimpan apa adanya.

Yang TIDAK dipindah (bukan sel tabel): `ChlorineProfile::CI_PENGENCERAN`
= `PERHITUNGAN U95%!W25 = AE11/100*C20` — turunan rumus dari Uc DAN titik
contoh sesi master (C20 = 1,74; W40 menyalin W25 untuk titik kedua), jadi
lapis 2 (struktur), tetap kode.
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


db = buka(BERKAS)["DATABASE"]


def label(alamat, harap):
    v = db[alamat].value
    if str(v).strip() != harap:
        sys.exit(f"MENOLAK: DATABASE!{alamat} = {v!r}, harap {harap!r} (struktur bergeser)")


def angka(alamat):
    v = db[alamat].value
    if isinstance(v, bool) or not isinstance(v, (int, float)):
        sys.exit(f"MENOLAK: DATABASE!{alamat} = {v!r}, bukan angka")
    return float(v)


if PAKET == "chlorine_meter":
    label("S20", "Micropippete")
    label("S21", "Labu Ukur")
    isi = {
        "_sumber": "Master Olah Data_Chlorine Meter.xlsm DATABASE!W20:W21 (docs/skrip/gen-tabel-standar-pengenceran.py). JANGAN diedit tangan.",
        "pengenceran": {
            "u95_micropipette_ml": round(angka("W20") / 1000, 10),
            "u95_labu_ukur_ml": angka("W21"),
        },
    }
    keluar = REPO / "database/data/tabel-standar-chlorine-meter.json"
elif PAKET == "do_meter":
    label("R17", "Micropippete")
    label("R18", "Labu Ukur")
    isi = {
        "_sumber": "Master Olah Data_DO Meter.xlsm DATABASE!V17:V18 (docs/skrip/gen-tabel-standar-pengenceran.py). JANGAN diedit tangan.",
        "pengenceran": {
            "u95_micropipette_ul": angka("V17"),
            "u95_labu_ukur_ml": angka("V18"),
        },
    }
    keluar = REPO / "database/data/tabel-standar-do-meter.json"
else:
    sys.exit("paket harus chlorine_meter atau do_meter")

keluar.write_text(json.dumps(isi, ensure_ascii=False, indent=1) + "\n", encoding="utf-8", newline="\n")
print("Ditulis", keluar)

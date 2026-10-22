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

  spectrophotometer — `Master Olah Data_Spectrofotometer.xlsm`, lembar
    `PERHITUNGAN U95%` N10:O27: 18 pasangan [nilai acuan, nilai terbaca] (nm)
    tabel celah Spectral Bandwidth (sumber tercetak N31: SNSU PK.F-01:2020).
    Dulu `SpectrophotometerCalculator::GAP_SPECTRAL_BANDWIDTH`. Rata-rata
    `Q30 = ABS(AVERAGE(P10:P27))` tetap dihitung kode (lapis 2).

  viscometer — `5. Viscometer 86068360 terbaru .xlsm` (lembar `MPE Visco`
    identik di `Master Olah Data_Viscometer.xlsm`): Tabel D-2 Brookfield TK
    `D6:D17` (12 model) dan Tabel D-1 SMC `D22:D84` (63 spindle), dulu angka
    `tk`/`smc` di `ViscometerProfile::TABEL_TK`/`TABEL_SMC`. Hanya ANGKA yang
    dipindah, sebagai daftar urut baris: label model/spindle & grup tetap kode
    (lapis 3 — kode menulis `SC4-18`/`CPE/CPA`, master `sc4-18`/`CPE or CPA`),
    dipasangkan per posisi. Label master diadu ke label kode (abaikan
    huruf besar/kecil) supaya posisi tidak bergeser diam-diam.

  autoclave — `Master Olah Data_Autoclave.xlsm`: CMC `DATABASE!S5` (suhu, °C)
    & `S6` (tekanan, bar); lembar `STANDAR KALIBRATOR`: tiga Temperature
    Calibrator (S/N `D25`/`D39`/`D53`, resolusi `J25`/`J39`/`J53`, tabel
    set/koreksi/U95 `D28:F33`, `D42:F47`, `D56:F61`) dan Pressure Disk (S/N
    `D5`, resolusi `J5`, tabel set/koreksi UP/U95 `D9:E15` + `G9:G15`) — dulu
    `config/autoclave.php`. Nama standar ditulis dari kode (nama baris
    `standards` DB; master menulis "Pressure Disk", DB "Pressure Disk Logger").
    Koreksi DOWN (`F9:F15`) tidak dipakai rumus master, tidak dibawa.

  hydrometer — `Hydrometer 0.600 - 0.650 gmL.xlsx` (ringan, bersinker) DAN
    `Hydrometer 1.800 - 2.000 gmL.xlsx` (berat); berikan KEDUANYA, generator
    menolak bila angkanya beda. `Tabel Surface Tension!A2:B8`, blok
    "Uncertainty of Calibrator" `NILAI U95%` (E29/E30/E34/E35, N29/N30/N34/N35,
    F67, F72), U95 thermohygro `DATABASE!F31`/`J31` (alamat sama di kedua
    master) dan konstanta metode `PERHITUNGAN` (alamatnya bergeser dua baris di
    master berat; sinker hanya di master ringan). Dulu konstanta
    `TabelStandarHydrometer`.
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


def spectrophotometer():
    t = "PERHITUNGAN U95%"
    for a, h in [("N8", "Spectral Bandwidth"), ("P8", "Gap (nm)"), ("N29", "Average (%)"), ("N30", "Average (nm)")]:
        label(t, a, h)
    if not str(sel(t, "N31") or "").startswith("Sumber : SNSU PK.F-01:2020"):
        sys.exit(f"MENOLAK: {t}!N31 = {sel(t, 'N31')!r}, sumber tabel berubah")
    baris = [{"acuan_nm": angka(t, f"N{b}"), "terbaca_nm": angka(t, f"O{b}")} for b in range(10, 28)]
    if sel(t, "N28") is not None:
        sys.exit(f"MENOLAK: {t}!N28 terisi — tabel lebih dari 18 baris, AVERAGE(P10:P27) master tidak mencakupnya")
    tulis("tabel-standar-spectrophotometer.json", {
        "_sumber": "Master Olah Data_Spectrofotometer.xlsm 'PERHITUNGAN U95%'!N10:O27 (docs/skrip/gen-tabel-standar-analitik.py). JANGAN diedit tangan.",
        "celah_spectral_bandwidth": baris,
    })


VISCO_TK = ["DV2TLV", "2.5DV2TLV", "5DV2TLV", "1/4DV2TRV", "1/2DV2TRV", "DV2TRV", "DV2THA", "2DV2THA",
            "2.5DV2THA", "DV2THB", "2DV2THB", "2.5DV2THB"]
VISCO_SMC = ["RV1", "RV2", "RV3", "RV4", "RV5", "RV6", "RV7", "HA1", "HA2", "HA3", "HA4", "HA5", "HA6", "HA7",
             "HB1", "HB2", "HB3", "HB4", "HB5", "HB6", "HB7", "LV1", "LV2", "LV3", "LV4 or 4B2", "LV5", "LV-2C",
             "LV-3C", "SPIRAL", "T-A", "T-B", "T-C", "T-D", "T-E", "T-F", "ULA", "DIN-81", "DIN-82", "DIN-83",
             "DIN-85", "DIN-86", "DIN-87", "SC4-14", "SC4-15", "SC4-16", "SC4-18", "SC4-21", "SC4-25", "SC4-27",
             "SC4-28", "SC4-29", "SC4-31", "SC4-34", "CPE-40 or CPA-40Z", "CPE-41 or CPA-41Z", "CPE-42 or CPA-42Z",
             "CPE-51 or CPA-51Z", "CPE-52 or CPA-52Z", "V-71", "V-72", "V-73", "V-74", "V-75"]


def viscometer():
    t = "MPE Visco"
    for a, h in [("A3", "Table D-2 for finding the TK (Torque Constant) value"), ("B5", "MODEL (on body)"), ("D5", "TK"),
                 ("A19", "Table D-1 for finding the SMC (Spindle Multiplier Constant) value"), ("C21", "SPINDLE"), ("D21", "SMC")]:
        label(t, a, h)
    for daftar, kol, awal in [(VISCO_TK, "B", 6), (VISCO_SMC, "C", 22)]:
        for i, nama in enumerate(daftar):
            v = str(sel(t, f"{kol}{awal + i}") or "").strip()
            if v.lower() != nama.lower():
                sys.exit(f"MENOLAK: {t}!{kol}{awal + i} = {v!r}, harap {nama!r} (urutan tabel bergeser)")
        if sel(t, f"{kol}{awal + len(daftar)}") not in (None, ""):
            sys.exit(f"MENOLAK: {t}!{kol}{awal + len(daftar)} terisi — tabel bertambah baris, label kode belum ikut")
    tulis("tabel-standar-viscometer.json", {
        "_sumber": "5. Viscometer 86068360 terbaru .xlsm 'MPE Visco'!D6:D17 & D22:D84 (docs/skrip/gen-tabel-standar-analitik.py). JANGAN diedit tangan.",
        "brookfield": {
            "tk": [angka(t, f"D{b}") for b in range(6, 6 + len(VISCO_TK))],
            "smc": [angka(t, f"D{b}") for b in range(22, 22 + len(VISCO_SMC))],
        },
    })


def autoclave():
    db, sk = "DATABASE", "STANDAR KALIBRATOR"
    for a, h in [("D7", "Set Point (bar)"), ("E7", "Correction (bar)"), ("G7", "U95% (bar)"), ("E8", "UP"),
                 ("D27", "Set Point (oC)"), ("D41", "Set Point (oC)"), ("D55", "Set Point (oC)"),
                 ("D22", "Temperature Calibrator 1"), ("D36", "Temperature Calibrator 2"), ("D50", "Temperature Calibrator 3")]:
        label(sk, a, h)

    def serial(a):
        v = sel(sk, a)
        return str(int(v)) if isinstance(v, (int, float)) and float(v).is_integer() else str(v).strip()

    suhu = []
    for n, (nama, s_, r_, b0) in enumerate([("D22", "D25", "J25", 28), ("D36", "D39", "J39", 42), ("D50", "D53", "J53", 56)], 1):
        suhu.append({
            "nama": f"Temperature Calibrator {n}",
            "serial": serial(s_),
            "resolusi": angka(sk, r_),
            "tabel": [{"set": angka(sk, f"D{b}"), "koreksi": angka(sk, f"E{b}"), "u95": angka(sk, f"F{b}")} for b in range(b0, b0 + 6)],
        })
    tulis("tabel-standar-autoclave.json", {
        "_sumber": "Master Olah Data_Autoclave.xlsm DATABASE!S5:S6 & 'STANDAR KALIBRATOR' (docs/skrip/gen-tabel-standar-analitik.py). JANGAN diedit tangan.",
        "cmc": {"suhu": angka(db, "S5"), "tekanan": angka(db, "S6")},
        "suhu": suhu,
        "tekanan": {
            "nama": "Pressure Disk Logger",
            "serial": serial("D5"),
            "resolusi": angka(sk, "J5"),
            "tabel": [{"set": angka(sk, f"D{b}"), "koreksi_up": angka(sk, f"E{b}"), "u95": angka(sk, f"G{b}")} for b in range(9, 16)],
        },
    })


HYDRO_PERHITUNGAN = {
    # jalur: (sel ringan, label ringan, sel berat, label berat) — label = kolom E baris itu
    "alpha": ("J83", "E83", "J85", "E85", "koefisien ekspansi volumetrik bahan"),
    "kappa": ("J86", "E86", "J88", "E88", "isothermal compressibility coefficient of the fluid"),
    "err_densitas": ("J87", "E87", "J89", "E89", "kesalahan densitas karena (kurangnya) stabilitas nilai densitas referensi (g/cm3)"),
    "phi": ("J89", "E89", "J91", "E91", "phi"),
    "gravitasi_cm_s2": ("J90", "E90", "J92", "E92", "percepatan gravitasi"),
    "err_timbang": ("AF84", None, "AE86", None, None),
    "densitas_beban_standar": ("AF85", None, "AE87", None, None),
    "tekanan_acuan_hpa": ("AD29", None, "AC29", None, None),
}


def hydrometer_satu(path):
    global WB
    WB = buka(pathlib.Path(path))
    nu, db, ts, ph = "NILAI U95%", "DATABASE", "Tabel Surface Tension", "PERHITUNGAN"
    for a, h in [("C29", "LOP atau read."), ("C30", "k atau 31/2"), ("C34", "stdev timb"), ("C35", "n"),
                 ("K29", "U95% Thermometer"), ("K34", "U95% Sensor"), ("C67", "Density of Liquid Reference"), ("C72", "Local Gravity")]:
        label(nu, a, h)
    label(ts, "A1", "toC")
    berat = "1.800" in pathlib.Path(path).name
    konst = {}
    for k, (sr, lr, sb, lb, teks) in HYDRO_PERHITUNGAN.items():
        a, l = (sb, lb) if berat else (sr, lr)
        if l:
            label(ph, l, teks)
        konst[k] = angka(ph, a)
    if not berat:
        label(ph, "E91", "Densitas beban tambahan")
        konst["densitas_sinker"] = angka(ph, "J91")
    n = angka(nu, "E35")
    if not n.is_integer():
        sys.exit(f"MENOLAK: {nu}!E35 = {n}, bukan bilangan bulat")
    return {
        "surface_tension": [{"suhu_c": angka(ts, f"A{b}"), "dyne_cm": angka(ts, f"B{b}")} for b in range(2, 9)],
        "konstanta": konst,
        "standar": {
            "u95_timbangan_lop": angka(nu, "E29"), "k_timbangan_lop": angka(nu, "E30"),
            "stdev_timbang": angka(nu, "E34"), "n_timbang": int(n),
            "u95_thermometer": angka(nu, "N29"), "k_thermometer": angka(nu, "N30"),
            "u95_sensor": angka(nu, "N34"), "k_sensor": angka(nu, "N35"),
            "u_densitas_acuan": angka(nu, "F67"), "u_gravitasi": angka(nu, "F72"),
            "u95_th_suhu": angka(db, "F31"), "u95_th_rh": angka(db, "J31"),
        },
    }


def hydrometer():
    if len(sys.argv) != 4:
        sys.exit("hydrometer butuh dua master: <ringan 0.600-0.650> <berat 1.800-2.000>")
    ringan, berat = hydrometer_satu(sys.argv[2]), hydrometer_satu(sys.argv[3])
    sinker = ringan["konstanta"].pop("densitas_sinker")
    if ringan != berat:
        sys.exit("MENOLAK: angka acuan master ringan & berat berbeda — satu JSON tidak bisa mewakili keduanya")
    ringan["konstanta"]["densitas_sinker"] = sinker
    tulis("tabel-standar-hydrometer.json", {
        "_sumber": "Hydrometer 0.600 - 0.650 gmL.xlsx & Hydrometer 1.800 - 2.000 gmL.xlsx: Tabel Surface Tension, NILAI U95%, DATABASE!F31/J31, PERHITUNGAN (docs/skrip/gen-tabel-standar-analitik.py). JANGAN diedit tangan.",
        **ringan,
    })


{
    "refractometer": refractometer,
    "hydrometer": hydrometer,
    "autoclave": autoclave,
    "viscometer": viscometer,
    "spectrophotometer": spectrophotometer,
}.get(PAKET, lambda: sys.exit(f"paket tak dikenal: {PAKET}"))()

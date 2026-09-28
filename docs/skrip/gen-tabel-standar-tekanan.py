#!/usr/bin/env python3
"""
Reimplementasi rantai hitung EMPAT master tekanan, diadu SEL DEMI SEL ke cache Excel.

Dua mode:
  master : tiru persis, termasuk cacat T-1 (ABC4), T-2 (I44 kosong), T-11 (13G Vacum kolom 4)
  benar  : cacat itu dihitung benar (keputusan pengguna 28 Sep 2026)

Mode master WAJIB mereproduksi seluruh sel cache Excel. Mode benar cuma berbeda di
komponen yang dibetulkan.

Keluaran (argumen --tulis <dir_repo>):
  database/data/tabel-standar-tekanan.json   tabel referensi, TANPA identitas pelanggan
  database/data/sesi-master-tekanan.json     masukan sesi contoh + harapan (Excel & benar)
"""
import datetime
import hashlib
import io
import json
import math
import os
import statistics as st
import sys

import msoffcrypto
import openpyxl
import mpmath as mp

PASSWORD = "spirit285"
TOL = 1e-12
mp.mp.dps = 40

BERKAS = {
    "druck07g": "Master_Olda_Pressure_DRUCK07G.xlsm",
    "druck13g": "Master_Olda_Pressure_DRUCK13G.xlsm",
    "spmk": "Master_Olda_Pressure_SPMK.xlsm",
    "differential": "Master_Olda_Pressure_Differential.xlsm",
}

# Nama berkas asli di folder kerja lab (buat jejak sumber, bukan dibuka).
NAMA_ASLI = {
    "druck07g": "Master Olda Pressure DRUCK07G -0.8~2bar new.xlsm",
    "druck13g": "Master Olda Pressure DRUCK13G 0~20bar.xlsm",
    "spmk": "Master Olda Pressure SPMK.xlsm",
    "differential": "Master Olda Pressure Differential.xlsm",
}


def tinv(df):
    """TINV(0,05; df) Excel = t(0,975; floor(df)) — dihitung eksak (mpmath)."""
    d = mp.mpf(int(df))
    f = lambda t: mp.betainc(d / 2, mp.mpf(1) / 2, 0, d / (d + t * t), regularized=True) / 2 - mp.mpf("0.025")
    return float(mp.findroot(f, 2.0))


def buka(path):
    buf = io.BytesIO()
    with open(path, "rb") as fh:
        off = msoffcrypto.OfficeFile(fh)
        off.load_key(password=PASSWORD)
        off.decrypt(buf)
    buf.seek(0)
    wf = openpyxl.load_workbook(buf)
    buf.seek(0)
    wv = openpyxl.load_workbook(buf, data_only=True)
    return wf, wv


def tgl(v):
    if isinstance(v, (datetime.datetime, datetime.date)):
        return v.strftime("%Y-%m-%d")
    return v


# ---------------------------------------------------------------------------
# Peta per varian. Semua yang BEDA antar-master tinggal di sini, bukan di if.
# ---------------------------------------------------------------------------
PETA = {
    "druck07g": dict(
        satuan_kerja="kPa", sheet_standar="STANDAR_DRUCK", baris_standar=(10, 28),
        kolom_standar=dict(set_point=4, koreksi_up=5, koreksi_down=6, u95=7),
        resolusi_standar="STANDAR_DRUCK!K5",
        fc=dict(koreksi_up=30, koreksi_down=31, terkoreksi_up=32, terkoreksi_down=33, u95_titik=34),
        u95_kalibrator="max_per_titik",
        drift=dict(sel="'Druck (kPa) vacum'!H4", sheet="Druck (kPa) vacum", koordinat="H4",
                   metode="spesifikasi_pabrik",
                   catatan="Sel berlabel 'drift sementara' = 0,0185% FS x 84 kPa. Riwayat rekalibrasi di sheet "
                           "yang sama tidak dipakai budget (temuan T-7)."),
        koreksi_tinggi=False, beda_level=False, jenis_tekanan=True,
        u95_baris=range(9, 15), agregat_baris=dict(jumlah=15, uc=16, veff=17, k=18, U=19, cmc=20, final=21),
    ),
    "druck13g": dict(
        satuan_kerja="Psi", sheet_standar="STANDAR_DRUCK", baris_standar=(8, 18),
        kolom_standar=dict(set_point=4, koreksi_up=5, koreksi_down=6, u95=7),
        resolusi_standar="STANDAR_DRUCK!K4",
        fc=dict(koreksi_up=30, koreksi_down=32, terkoreksi_up=33, terkoreksi_down=34, u95_titik=35),
        u95_kalibrator="max_per_titik",
        drift=dict(sel="'Druck (psi)'!H5", sheet="Druck (psi)", koordinat="H5", metode="spesifikasi_pabrik",
                   catatan="Sel berlabel 'drift sementara' = 0,0185% FS x 290 psi (temuan T-7)."),
        koreksi_tinggi=False, beda_level=False, jenis_tekanan=True,
        u95_baris=range(9, 15), agregat_baris=dict(jumlah=15, uc=16, veff=17, k=18, U=19, cmc=20, final=21),
    ),
    "spmk": dict(
        satuan_kerja="Bar", sheet_standar="STANDAR_SPMK", baris_standar=(8, 20),
        kolom_standar=dict(set_point=4, koreksi_up=5, koreksi_down=6, u95=7),
        resolusi_standar="STANDAR_SPMK!K5",
        fc=dict(koreksi_up=30, koreksi_down=31, koreksi_tinggi=32, terkoreksi_up=33, terkoreksi_down=34),
        u95_kalibrator="indeks_maks",
        drift=dict(sel="'Drift SPMK (bar)'!P26", sheet="Drift SPMK (bar)", koordinat="P26",
                   metode="setengah_rentang_maks",
                   catatan="MAX((maks-min)/2) dari riwayat pembacaan rekalibrasi per set point."),
        koreksi_tinggi=True, beda_level=True, jenis_tekanan=False,
        u95_baris=range(9, 16), agregat_baris=dict(jumlah=16, uc=17, veff=18, k=19, U=20, cmc=21, final=22),
    ),
    "differential": dict(
        satuan_kerja="mBar", sheet_standar="STANDAR_ADDITEL", baris_standar=(8, 29),
        kolom_standar=dict(set_point=4, koreksi_up=5, koreksi_down=5, u95=6),
        resolusi_standar="STANDAR_ADDITEL!J6",
        fc=dict(koreksi_up=30, koreksi_down=30, terkoreksi_up=32, terkoreksi_down=33),
        u95_kalibrator="indeks_maks",
        drift=dict(sel="'Record Drift Additel'!M39", sheet="Record Drift Additel", koordinat="M39",
                   metode="setengah_selisih_maks",
                   catatan="0,5 x MAX(dC). Sheet yang sama menandai metode STDEV/akar3 (T38) dengan "
                           "catatan 'PAKAI INI' tapi budget memakai M39 (temuan T-8)."),
        koreksi_tinggi=False, beda_level=False, jenis_tekanan=False,
        u95_baris=range(9, 15), agregat_baris=dict(jumlah=15, uc=16, veff=17, k=18, U=19, cmc=20, final=21),
    ),
}


def ambil(wv, ref):
    sh, ko = ref.split("!")
    return wv[sh.strip("'")][ko].value


def baca_varian(nama, path):
    wf, wv = buka(path)
    p = PETA[nama]
    db = wv["DATABASE"]

    # --- konversi satuan -> satuan kerja: R29:S40, berhenti di baris kosong
    konversi = []
    for r in range(29, 41):
        sat = db.cell(r, 18).value
        fak = db.cell(r, 19).value
        if sat is None or not isinstance(fak, (int, float)):
            continue
        konversi.append({"satuan": str(sat).strip(), "faktor": float(fak)})

    # --- titik standar; sel gabungan (merged) diisi dari sel teratasnya
    ws = wf[p["sheet_standar"]]
    wsv = wv[p["sheet_standar"]]
    gabung = {}
    for rng in ws.merged_cells.ranges:
        atas = wsv.cell(rng.min_row, rng.min_col).value
        for rr in range(rng.min_row, rng.max_row + 1):
            for cc in range(rng.min_col, rng.max_col + 1):
                gabung[(rr, cc)] = (atas, rng.coord)
    titik = []
    b0, b1 = p["baris_standar"]
    ks = p["kolom_standar"]
    for r in range(b0, b1 + 1):
        sp = wsv.cell(r, ks["set_point"]).value
        if sp is None:
            continue

        def sel(c):
            v = wsv.cell(r, c).value
            asal = None
            if v is None and (r, c) in gabung:
                v, asal = gabung[(r, c)]
            return (float(v) if isinstance(v, (int, float)) else None), asal

        ku, _ = sel(ks["koreksi_up"])
        kd, _ = sel(ks["koreksi_down"])
        u95, asal_u95 = sel(ks["u95"])
        baris = {"set_point": float(sp), "koreksi_up": ku, "koreksi_down": kd, "u95": u95}
        if asal_u95:
            baris["u95_dari_sel_gabungan"] = asal_u95
        titik.append(baris)

    std = {
        "nama": db["R13"].value, "merk_tipe": db["S13"].value, "nomor_seri": str(db["T13"].value),
        "tertelusur": db["U13"].value, "tanggal_kalibrasi": tgl(db["V13"].value),
        "interval_tahun": db["W13"].value, "jatuh_tempo": tgl(db["X13"].value),
        "resolusi": float(ambil(wv, p["resolusi_standar"])),
    }

    media = []
    for r in range(19, 22):
        media.append({"nomor": db.cell(r, 17).value, "media": db.cell(r, 18).value,
                      "massa_jenis": float(db.cell(r, 19).value)})

    drift_nilai = float(ambil(wv, p["drift"]["sel"]))
    drift_rumus = wf[p["drift"]["sheet"]][p["drift"]["koordinat"]].value

    cmc = {}
    for r in (5, 6):
        lab = db.cell(r, 18).value
        val = db.cell(r, 19).value
        if lab is None or not isinstance(val, (int, float)):
            continue
        cmc[str(lab)] = {"nilai": float(val), "rumus_master": wf["DATABASE"].cell(r, 19).value}

    wf.close(); wv.close()
    return {
        "satuan_kerja": p["satuan_kerja"], "konversi": konversi, "titik_standar": titik,
        "standar": std, "media": media,
        "drift": {"nilai": drift_nilai, "sel_master": p["drift"]["sel"], "rumus_master": str(drift_rumus),
                  "metode": p["drift"]["metode"], "catatan": p["drift"]["catatan"]},
        "cmc_master": cmc,
    }


def baca_masukan(nama, path):
    """Isian teknisi dari INPUT_DATA. Identitas pelanggan TIDAK dibaca."""
    wf, wv = buka(path)
    inp = wv["INPUT_DATA"]
    fcv = wv["PERHITUNGAN FC"]
    titik = []
    for r in range(31, 45):
        s = inp.cell(r, 6).value
        if s is None:
            continue
        up = [inp.cell(r, c).value for c in (8, 9, 10)]
        dn = [inp.cell(r, c).value for c in (11, 12, 13)]
        titik.append({"setelan": float(s), "up": [float(x) for x in up], "down": [float(x) for x in dn]})
    tampilan = "digital" if inp["W14"].value == 1 else "analog"
    m = {
        "satuan": inp["Z6"].value,
        "tampilan": tampilan,
        "rasio_jarum": {1: "1/2", 2: "1/5", 3: "1/10"}.get(inp["K14"].value) if tampilan == "analog" else None,
        "resolusi": float(inp["E16"].value),
        "kapasitas": float(inp["E15"].value),
        "media": int(inp["E5"].value) if inp["E5"].value is not None else None,
        "jenis_tekanan": ({"Vacum": "vakum", "Non Vacum": "non_vakum"}.get(inp["Z17"].value)
                          if PETA[nama]["jenis_tekanan"] else None),
        "tinggi_standar": fcv["I40"].value if PETA[nama]["koreksi_tinggi"] else None,
        "tinggi_uut": fcv["I41"].value if PETA[nama]["koreksi_tinggi"] else None,
        "beda_tinggi": fcv["I42"].value if PETA[nama]["koreksi_tinggi"] else None,
        "titik": titik,
    }
    wf.close(); wv.close()
    return m


# ---------------------------------------------------------------------------
# Rantai hitung — cermin persis yang akan ditulis di PHP
# ---------------------------------------------------------------------------
def faktor(tabel, satuan):
    for k in tabel["konversi"]:
        if k["satuan"].lower() == str(satuan).lower():
            return k["faktor"]
    raise KeyError(satuan)


def indeks_terdekat(daftar, e):
    """INDEX/MATCH(MIN(ABS())) — kembar diambil yang PERTAMA di daftar (`<`, bukan `<=`)."""
    terbaik = None
    jarak = None
    for sp in daftar:
        d = abs(sp - e)
        if jarak is None or d < jarak:
            jarak = d
            terbaik = sp
    return terbaik


def baris_standar(tabel, sp):
    for b in tabel["titik_standar"]:
        if b["set_point"] == sp:
            return b
    return None


def hitung(nama, tabel, m, mode, g_lokal=9.676):
    p = PETA[nama]
    f = faktor(tabel, m["satuan"])
    daftar = [b["set_point"] for b in tabel["titik_standar"]]
    media = {x["nomor"]: x["massa_jenis"] for x in tabel["media"]}
    rho = media.get(m["media"])

    # koreksi beda tinggi (SPMK): R43 = I42*R40*R42 ; R44 = R43/100000
    koreksi_tinggi = 0.0
    R43 = None
    if p["koreksi_tinggi"]:
        R43 = (m["beda_tinggi"] or 0.0) * rho * g_lokal
        koreksi_tinggi = R43 / 100000

    per = []
    for i, t in enumerate(m["titik"]):
        E = t["setelan"] * f
        G = [v * f for v in t["up"]]
        J = [v * f for v in t["down"]]
        M = sum(G) / 3            # AVERAGE Excel = SUM/COUNT
        N = sum(J) / 3
        sd_up = st.stdev(G)
        sd_dn = st.stdev(J)
        AC = indeks_terdekat(daftar, E)
        bs = baris_standar(tabel, AC)
        ku = bs["koreksi_up"] or 0.0
        kd = bs["koreksi_down"] or 0.0
        if nama == "druck13g" and m["jenis_tekanan"] == "vakum" and mode == "master":
            ku = bs["u95"] or 0.0          # T-11: cabang Vacum membaca kolom 4 (U95)
        if nama == "differential":
            kd = ku                         # satu kolom koreksi dipakai UP & DOWN
        if p["koreksi_tinggi"]:
            tu = M + ku + koreksi_tinggi
            td = N + kd + koreksi_tinggi
        else:
            tu = M + ku
            td = N + kd
        u95_titik = None
        if p["u95_kalibrator"] == "max_per_titik":
            if nama == "druck07g" and mode == "master":
                u95_titik = baris_standar(tabel, 0.0)["u95"]   # T-1: VLOOKUP(ABC4,...) -> set point 0
            else:
                u95_titik = bs["u95"]
        per.append(dict(
            E=E, G=G, J=J, M=M, N=N, dev_up=E - M, dev_down=E - N,
            sd_up=sd_up, sd_down=sd_dn,
            hys=[G[k] - J[k] for k in range(3)], hys_avg=M - N,
            indeks=AC, koreksi_up=ku, koreksi_down=kd, koreksi_tinggi=koreksi_tinggi if p["koreksi_tinggi"] else None,
            terkoreksi_up=tu, terkoreksi_down=td, u95_titik=u95_titik,
        ))

    maks_koreksi = max(max(abs(x["dev_up"]), abs(x["dev_down"])) for x in per)
    maks_sd_up = max(x["sd_up"] for x in per)
    maks_sd_down = max(x["sd_down"] for x in per)
    zero = max(abs(per[0]["G"][k] - per[0]["J"][k]) for k in range(3))   # baris PERTAMA, persis Q39
    maks_indeks = max(x["indeks"] for x in per)

    if p["u95_kalibrator"] == "max_per_titik":
        u95_kal = max(x["u95_titik"] for x in per)
    else:
        u95_kal = baris_standar(tabel, maks_indeks)["u95"]

    # resolusi
    res_kerja = m["resolusi"] * f
    kap_kerja = m["kapasitas"] * f
    if m["tampilan"] == "digital":
        pembagi_res = 2.0
    else:
        pembagi_res = {"1/2": 2.0, "1/5": 5.0, "1/10": 10.0}[m["rasio_jarum"]]
    U_res = res_kerja / pembagi_res

    maks_sd = max(maks_sd_up, maks_sd_down)
    if mode == "master" and nama != "spmk":
        U_ulang = 0.0              # T-2: 'PERHITUNGAN FC'!I44 kosong
    else:
        U_ulang = maks_sd          # rumus induk SPMK: MAX(U24:V37)

    R3 = math.sqrt(3)
    komp = [
        ("sertifikat_kalibrator", u95_kal, 2.0, 60.0, 1.0),
        ("daya_baca_uut", U_res, R3, 50.0, 1.0),
        ("daya_baca_standar", tabel["standar"]["resolusi"] / 2, R3, 50.0, 1.0),
        ("drift_standar", tabel["drift"]["nilai"], R3, 50.0, 1.0),
    ]
    L43 = L44 = L45 = None
    if p["beda_level"]:
        L43 = rho * g_lokal * maks_koreksi
        L44 = L43 / 100000
        L45 = L44 / kap_kerja
        komp.append(("beda_level", 0.37, 2.0, 60.0, L45))
        komp.append(("pengulangan", U_ulang, 3.0, 2.0, 1.0))
        komp.append(("zero_error", zero, R3, 50.0, 1.0))
    else:
        komp.append(("zero_error", zero, R3, 50.0, 1.0))
        komp.append(("pengulangan", U_ulang, 3.0, 2.0, 1.0))

    rinci = []
    s2 = 0.0
    s4 = 0.0
    for (kode, U, div, vi, ci) in komp:
        u = U / div
        z = u * ci
        ac = z ** 2
        ag = (ac ** 2) / vi
        s2 += ac
        s4 += ag
        rinci.append(dict(kode=kode, U=U, pembagi=div, vi=vi, ci=ci, u=u, uici=z, kuadrat=ac, pangkat4_per_v=ag))
    uc = math.sqrt(s2)
    veff = uc ** 4 / s4
    k = tinv(veff)
    U = uc * k

    return dict(
        faktor=f, per_titik=per,
        agregat=dict(maks_koreksi=maks_koreksi, maks_stdev_up=maks_sd_up, maks_stdev_down=maks_sd_down,
                     maks_stdev=maks_sd, zero_deviasi=zero, maks_indeks=maks_indeks, u95_kalibrator=u95_kal,
                     resolusi_kerja=res_kerja, kapasitas_kerja=kap_kerja,
                     R43=R43, koreksi_tinggi=koreksi_tinggi if p["koreksi_tinggi"] else None,
                     L43=L43, L44=L44, L45=L45),
        komponen=rinci, jumlah_kuadrat=s2, jumlah_pangkat4=s4, uc=uc, veff=veff, df=int(veff), k=k, U=U,
    )


def cmc_kerja(nama, tabel, m):
    if nama in ("druck07g", "druck13g"):
        kunci = "Vacum" if m["jenis_tekanan"] == "vakum" else "Non Vacum"
    elif nama == "spmk":
        kunci = "Pressure"
    else:
        kunci = "-10 mbar ~ 10 mbar"
    return tabel["cmc_master"][kunci]["nilai"]


# ---------------------------------------------------------------------------
# Adu ke Excel
# ---------------------------------------------------------------------------
hasil = []


def cek(label, harap, dapat, tol=TOL):
    if harap is None or dapat is None:
        ok = harap == dapat
        d = 0.0 if ok else float("nan")
    else:
        d = abs(float(harap) - float(dapat))
        # Relatif untuk angka besar: L43 SPMK ~144 lahir dari Q38 (cocok 1e-15)
        # dikali rho*g = 8805. Mutlak 1e-12 di angka ratusan = 1e-14 relatif,
        # lebih ketat dari presisi double itu sendiri.
        ok = d <= tol * max(1.0, abs(float(harap)))
    hasil.append((label, harap, dapat, d, ok))


def adu(nama, path, tabel, m, r):
    p = PETA[nama]
    _, wv = buka(path)
    fc = wv["PERHITUNGAN FC"]
    u9 = wv["PERHITUNGAN U95%"]
    se = wv["SERTIFIKAT"]
    for i, x in enumerate(r["per_titik"]):
        row = 24 + i
        L = lambda c: fc.cell(row, c).value
        cek(f"{nama} t{i+1} E", L(5), x["E"])
        for j in range(3):
            cek(f"{nama} t{i+1} G{j}", L(7 + j), x["G"][j])
            cek(f"{nama} t{i+1} J{j}", L(10 + j), x["J"][j])
        cek(f"{nama} t{i+1} M", L(13), x["M"]); cek(f"{nama} t{i+1} N", L(14), x["N"])
        cek(f"{nama} t{i+1} O", L(15), x["dev_up"]); cek(f"{nama} t{i+1} P", L(16), x["dev_down"])
        cek(f"{nama} t{i+1} Q", L(17), x["dev_up"]); cek(f"{nama} t{i+1} R", L(18), x["dev_down"])
        cek(f"{nama} t{i+1} S", L(19), abs(x["dev_up"])); cek(f"{nama} t{i+1} T", L(20), abs(x["dev_down"]))
        cek(f"{nama} t{i+1} U", L(21), x["sd_up"]); cek(f"{nama} t{i+1} V", L(22), x["sd_down"])
        for j in range(3):
            cek(f"{nama} t{i+1} hys{j}", L(23 + j), x["hys"][j])
        cek(f"{nama} t{i+1} Z", L(26), x["hys_avg"])
        cek(f"{nama} t{i+1} AC", L(29), x["indeks"])
        cek(f"{nama} t{i+1} koreksi_up", L(p["fc"]["koreksi_up"]), x["koreksi_up"])
        if nama != "differential":
            cek(f"{nama} t{i+1} koreksi_down", L(p["fc"]["koreksi_down"]), x["koreksi_down"])
        if p["koreksi_tinggi"]:
            cek(f"{nama} t{i+1} koreksi_tinggi", L(p["fc"]["koreksi_tinggi"]), x["koreksi_tinggi"])
        cek(f"{nama} t{i+1} terkoreksi_up", L(p["fc"]["terkoreksi_up"]), x["terkoreksi_up"])
        cek(f"{nama} t{i+1} terkoreksi_down", L(p["fc"]["terkoreksi_down"]), x["terkoreksi_down"])
        if "u95_titik" in p["fc"]:
            cek(f"{nama} t{i+1} u95_titik", L(p["fc"]["u95_titik"]), x["u95_titik"])
        # sertifikat
        srow = 22 + i
        S = lambda c: se.cell(srow, c).value
        f = r["faktor"]
        cek(f"{nama} sert t{i+1} E", S(5), m["titik"][i]["setelan"])
        cek(f"{nama} sert t{i+1} I std up", S(9), x["terkoreksi_up"] / f)
        cek(f"{nama} sert t{i+1} L std down", S(12), x["terkoreksi_down"] / f)
        cek(f"{nama} sert t{i+1} N corr up", S(14), x["terkoreksi_up"] / f - m["titik"][i]["setelan"])
        cek(f"{nama} sert t{i+1} P corr down", S(16), x["terkoreksi_down"] / f - m["titik"][i]["setelan"])
        for j, c in enumerate((18, 20, 22)):
            cek(f"{nama} sert t{i+1} hys{j}", S(c), x["hys"][j] / f)
    a = r["agregat"]
    cek(f"{nama} Q38 maks koreksi", fc["Q38"].value, a["maks_koreksi"])
    cek(f"{nama} U38", fc["U38"].value, a["maks_stdev_up"])
    cek(f"{nama} V38", fc["V38"].value, a["maks_stdev_down"])
    cek(f"{nama} Q39 zero", fc["Q39"].value, a["zero_deviasi"])
    cek(f"{nama} AC38 maks indeks", fc["AC38"].value, a["maks_indeks"])
    cek(f"{nama} H8 kapasitas kerja", fc["H8"].value, a["kapasitas_kerja"])
    cek(f"{nama} H9 resolusi kerja", fc["H9"].value, a["resolusi_kerja"])
    if p["koreksi_tinggi"]:
        cek(f"{nama} R43", fc["R43"].value, a["R43"]); cek(f"{nama} R44", fc["R44"].value, a["koreksi_tinggi"])
        cek(f"{nama} L43", fc["L43"].value, a["L43"]); cek(f"{nama} L44", fc["L44"].value, a["L44"])
        cek(f"{nama} L45", fc["L45"].value, a["L45"]); cek(f"{nama} I44", fc["I44"].value, a["maks_stdev"])
    for idx, row in enumerate(p["u95_baris"]):
        kk = r["komponen"][idx]
        cek(f"{nama} U95 r{row} {kk['kode']} U", u9.cell(row, 14).value, kk["U"])
        cek(f"{nama} U95 r{row} {kk['kode']} div", u9.cell(row, 17).value, kk["pembagi"])
        cek(f"{nama} U95 r{row} {kk['kode']} vi", u9.cell(row, 19).value, kk["vi"])
        cek(f"{nama} U95 r{row} {kk['kode']} ui", u9.cell(row, 21).value, kk["u"])
        cek(f"{nama} U95 r{row} {kk['kode']} ci", u9.cell(row, 24).value, kk["ci"])
        cek(f"{nama} U95 r{row} {kk['kode']} uici", u9.cell(row, 26).value, kk["uici"])
        cek(f"{nama} U95 r{row} {kk['kode']} kuadrat", u9.cell(row, 29).value, kk["kuadrat"])
        cek(f"{nama} U95 r{row} {kk['kode']} ^4/v", u9.cell(row, 33).value, kk["pangkat4_per_v"])
    ab = p["agregat_baris"]
    cek(f"{nama} jumlah kuadrat", u9.cell(ab["jumlah"], 29).value, r["jumlah_kuadrat"])
    cek(f"{nama} jumlah ^4/v", u9.cell(ab["jumlah"], 33).value, r["jumlah_pangkat4"])
    cek(f"{nama} uc", u9.cell(ab["uc"], 29).value, r["uc"])
    cek(f"{nama} veff", u9.cell(ab["veff"], 29).value, r["veff"])
    cek(f"{nama} k", u9.cell(ab["k"], 29).value, r["k"])
    cek(f"{nama} U", u9.cell(ab["U"], 29).value, r["U"])
    cmc = cmc_kerja(nama, tabel, m)
    cek(f"{nama} CMC", u9.cell(ab["cmc"], 29).value, cmc)
    u95 = max(r["U"], cmc)
    cek(f"{nama} U95 final", u9.cell(ab["final"], 29).value, u95)
    cek(f"{nama} sert U95 tampil", se["P35"].value, u95 / r["faktor"])
    cek(f"{nama} sert k cetak", se["U36"].value, round(r["k"], 1))
    wv.close()
    return u95, cmc


def sha256_berkas(path):
    h = hashlib.sha256()
    with open(path, "rb") as fh:
        for blok in iter(lambda: fh.read(1 << 20), b""):
            h.update(blok)
    return h.hexdigest()


def cek_manifest(akar, sha):
    """Workbook yang dibaca WAJIB versi yang dibekukan di manifest.

    Kalau tidak, JSON tidak ditulis: workbook baru = acuan baru, dan itu harus
    dicatat dulu (manifest + entri versi di log-metode-tekanan-piston.json)
    sebelum angka aplikasi boleh ikut bergeser.
    """
    with open(os.path.join(akar, "database/data/manifest-workbook-tekanan-piston.json"), encoding="utf-8") as fh:
        beku = {b["sha256"] for b in json.load(fh)["berkas"]}
    asing = {k: v for k, v in sha.items() if v not in beku}
    for k, v in asing.items():
        print(f"  TOLAK: {k} sha256 {v} tidak ada di manifest yang dibekukan")
    return not asing


def main():
    folder = sys.argv[1]
    tulis = sys.argv[3] if len(sys.argv) > 3 and sys.argv[2] == "--tulis" else None
    tabel_semua = {}
    sesi_semua = {}
    for nama, fn in BERKAS.items():
        path = os.path.join(folder, fn)
        tabel = baca_varian(nama, path)
        m = baca_masukan(nama, path)
        r_master = hitung(nama, tabel, m, "master")
        u95_master, cmc = adu(nama, path, tabel, m, r_master)
        r_benar = hitung(nama, tabel, m, "benar")
        u95_benar = max(r_benar["U"], cmc)
        tabel_semua[nama] = tabel
        sesi_semua[nama] = {
            "masukan": m,
            "harapan_master_excel": {**r_master, "cmc": cmc, "u95": u95_master},
            "harapan_benar": {**r_benar, "cmc": cmc, "u95": u95_benar},
        }
        print(f"{nama:13s} master U={r_master['U']!r} U95={u95_master!r} | benar U={r_benar['U']!r} "
              f"veff={r_benar['veff']!r} k={r_benar['k']!r} U95={u95_benar!r}")

    merah = [h for h in hasil if not h[4]]
    for h in merah:
        print("  MERAH", h[0], "excel=", repr(h[1]), "hitung=", repr(h[2]), "selisih=", h[3])
    terburuk = max((h[3] for h in hasil if h[3] == h[3]), default=0.0)
    print(f"\n{len(hasil) - len(merah)} hijau, {len(merah)} merah dari {len(hasil)} sel; selisih terbesar {terburuk:.3e}")

    sha = {nama: sha256_berkas(os.path.join(folder, fn)) for nama, fn in BERKAS.items()}
    if tulis and not merah and not cek_manifest(tulis, sha):
        sys.exit(2)
    if tulis and not merah:
        meta = {
            "_sumber": {
                "dibuat_oleh": "docs/skrip/gen-tabel-standar-tekanan.py",
                "tanggal": datetime.date.today().isoformat(),
                "workbook": NAMA_ASLI,
                "workbook_sha256": sha,
                "catatan": "Digenerate dari empat Master Olah Data tekanan. JANGAN disunting tangan — "
                           "jalankan ulang skripnya. Identitas pelanggan tidak ikut diekspor.",
            },
            "gravitasi_lokal": 9.676,
            "gravitasi_standar": 9.807,
            "beda_level": {"u": 0.37, "pembagi": 2.0, "vi": 60, "sumber": "'PERHITUNGAN U95%'!N13 SPMK (angka tetap)"},
            "pembagi_resolusi": {"1/2": 2.0, "1/5": 5.0, "1/10": 10.0, "digital": 2.0},
        }
        with open(os.path.join(tulis, "database/data/tabel-standar-tekanan.json"), "w", encoding="utf-8", newline="\n") as fh:
            json.dump({**meta, "varian": tabel_semua}, fh, ensure_ascii=False, indent=2)
            fh.write("\n")
        with open(os.path.join(tulis, "database/data/sesi-master-tekanan.json"), "w", encoding="utf-8", newline="\n") as fh:
            json.dump({"_sumber": meta["_sumber"], "sesi": sesi_semua}, fh, ensure_ascii=False, indent=2)
            fh.write("\n")
        print("JSON ditulis.")
    sys.exit(1 if merah else 0)


if __name__ == "__main__":
    main()

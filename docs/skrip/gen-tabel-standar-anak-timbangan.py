#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Generator `database/data/tabel-standar-anak-timbangan.json` (alat ke-29).

Sumbernya `1.1 Anak Timbangan F1 1mg-500 g 202501022 imp.xlsx` — sheet
`STD AT`, `DATABASE`, `MPE AT`, `Deviasi Standard Timbangan`, dan kelima sheet
`Data Sens *`.

Angka mentah di bawah disalin apa adanya dari master. Skrip ini **menurunkan
ulang** tiap besaran turunan lalu mengadunya ke nilai yang tersimpan di master:

  * keterulangan tiap neraca dari enam simpangan baku hariannya;
  * ketidakpastian sensitivitas tiap neraca dari rantai percobaan Msens;
  * kolom drift tiap keping standar dari kolom ketidakpastiannya.

Kalau ada yang menyimpang di luar toleransi, dia **menolak menulis** — JSON yang
separuh benar jauh lebih berbahaya daripada tidak ada JSON, karena angkanya
tetap terlihat wajar di sertifikat.

Jangan mengetik JSON-nya dengan tangan. Menyunting hasilnya berarti berkasnya
menyimpang diam-diam dari master, dan itu sudah tiga kali meloloskan bug (TIDS,
Timbangan, Micrometer).

    python docs/skrip/gen-tabel-standar-anak-timbangan.py

## Tiga hal yang SENGAJA tidak "dibetulkan" di sini

**1. Keterulangan neraca.** Sel master berlabel `Rata-rata STDev` sebenarnya
berisi SIMPANGAN BAKU dari keenam simpangan baku harian, bukan rata-ratanya —
terbukti untuk kelima neraca sampai epsilon mesin. Nilainya lebih kecil daripada
keterulangan hari mana pun, dan dia komponen terbesar di budget. Kalau diganti
gabungan kuadrat harian, U95 seluruh sertifikat naik ~1,9x. Ditiru apa adanya;
`gabungan_harian_mg` ikut disimpan supaya dampaknya bisa dihitung tanpa
menjalankan skrip ini lagi. Pertanyaan lab **§1**.

**2. Tabel densitas.** Memuat nilai yang mustahil untuk anak timbangan (10650
kg/m3 = timbal; 14400 kg/m3), kolom-kolomnya saling meminjam angka dengan pola
geser yang putus-putus, dan kolom E2/F1 tertukar antara 0,1 g dan 0,2 g.
Disalin apa adanya supaya sesi lama bisa dihitung ulang jadi angka yang sama
dengan kertas yang menerbitkannya, TAPI ditandai `"status": "disengketakan"` —
profil wajib menaikkan peringatan sesi selama lab belum menjawab. Pertanyaan
lab **§6**.

**3. Keping ber-bintang.** `0.02*`, `0.2*`, `2*`, `20*`, `200*` adalah keping
KEDUA bernominal sama. Pencarian exact master selalu mendarat di baris pertama,
jadi yang ber-bintang tidak pernah terpilih. Keduanya tetap disimpan supaya
jejaknya tidak hilang. Pertanyaan lab **§8**.

## Yang TIDAK ikut ke JSON, dan alasannya

Kolom `Stdev` di sheet `DATABASE` (Analytical Balance 8,5e-5 g) berbeda dari
keterulangan yang dipakai budget (4,636e-5 g). Tidak ada rumus hasil kalibrasi
yang membacanya, jadi tidak disalin — kalau ikut, dia jadi angka kedua yang
kelihatan berwenang untuk hal yang sama. Dicatat di pertanyaan lab §18.
"""
from __future__ import annotations

import json
import math
import pathlib
import statistics
import sys

TOL = 5e-6
KELUARAN = (
    pathlib.Path(__file__).resolve().parents[2]
    / "database/data/tabel-standar-anak-timbangan.json"
)

menyimpang: list[str] = []


def adu(
    nama: str,
    hitung: float,
    master: float,
    tol: float = TOL,
    tol_rel: float | None = None,
) -> float:
    """Adu nilai turunan ke nilai tersimpan master. Balik nilai MASTER.

    `tol` mutlak, `tol_rel` relatif (default: sama dengan `tol`). Lolos kalau
    salah satu di bawah ambang.

    Besaran yang nilainya kecil-kecil — keterulangan neraca dalam gram, orde
    10⁻⁵ — WAJIB memakai `tol=0.0` dan `tol_rel` tersendiri. Dengan ambang
    mutlak 10⁻¹⁵, angka sekecil itu lolos meski digitnya tertukar di posisi
    signifikan ke-12, dan penjaga ini jadi hiasan.
    """
    if tol_rel is None:
        tol_rel = tol
    beda = abs(hitung - master)
    rel = beda / abs(master) if master else beda
    if beda > tol and rel > tol_rel:
        menyimpang.append(
            f"{nama}: turunan={hitung!r} master={master!r} "
            f"beda={beda:.3e} rel={rel:.3e}"
        )
    return master


# ===================================================================== 1. Keping standar
# `STD AT` blok OVERALL STANDAR ANAK TIMBANGAN. Satuan: nominal & konvensional
# gram; ketidakpastian & drift miligram. Bintang = keping kedua bernominal sama.
#
#   (kelas, nominal_teks, konvensional_g, koreksi_g, u_mg, drift_mg)
KEPING = [
    ("E2", "0.001", 0.00100015, 1.4999999999997654e-07, 0.0008, 0.0004),
    ("E2", "0.002", 0.00200127, 1.2699999999998303e-06, 0.0009, 0.00045),
    ("E2", "0.002*", 0.00200133, 1.3299999999999076e-06, 0.0009, 0.00045),
    ("E2", "0.005", 0.0050016, 1.6000000000000389e-06, 0.0009, 0.00045),
    ("E2", "0.01", 0.010005100000000001, 5.100000000000937e-06, 0.001, 0.0005),
    ("E2", "0.02", 0.0200027, 2.7000000000013125e-06, 0.001, 0.0005),
    ("E2", "0.02*", 0.020000900000000002, 9.00000000001594e-07, 0.001, 0.0005),
    ("E2", "0.05", 0.0500026, 2.599999999998437e-06, 0.0013, 0.00065),
    ("E2", "0.1", 0.100006, 5.999999999992123e-06, 0.0015, 0.00075),
    ("E2", "0.2", 0.2000033, 3.2999999999838714e-06, 0.0016, 0.0008),
    ("E2", "0.2*", 0.2000078, 7.80000000000225e-06, 0.0016, 0.0008),
    ("E2", "0.5", 0.4999991, -9.000000000258801e-07, 0.0023, 0.00115),
    ("E2", "1", 1.0000136, 1.3599999999946988e-05, 0.0033, 0.00165),
    ("E2", "2", 2.0000192, 1.920000000010802e-05, 0.0036, 0.0018),
    ("E2", "2*", 2.0000185, 1.8499999999921357e-05, 0.0036, 0.0018),
    ("E2", "5", 5.0000284, 2.8399999999706438e-05, 0.0046, 0.0023),
    ("E2", "10", 10.000075, 7.500000000071338e-05, 0.0059, 0.00295),
    ("E2", "20", 20.0000948, 9.479999999939537e-05, 0.0071, 0.00355),
    ("E2", "20*", 20.0000812, 8.120000000033656e-05, 0.0071, 0.00355),
    ("E2", "50", 50.000212, 0.0002119999999976585, 0.011, 0.0055),
    ("E2", "100", 100.000144, 0.00014400000000591717, 0.023, 0.0115),
    ("E2", "200", 200.000149, 0.00014899999999329339, 0.03, 0.015),
    ("E2", "200*", 200.000104, 0.00010399999999322063, 0.03, 0.015),
    ("E2", "500", 500.00051, 0.0005100000000197724, 0.07, 0.035),
    ("E2", "1000", 1000.00053, 0.0005300000000261207, 0.28, 0.14),
    ("F1", "2000", 2000.00437, 0.004370000000108121, 0.34, 0.17),
    ("F1", "5000", 5000.01, 0.010000000000218279, 0.7, 0.35),
    ("F1", "10000", 10000.007, 0.006999999999607098, 15, 2.3094010767038933),
    ("F1", "20000", 20000.0275, 0.02750000000014552, 8.1, 4.05),
]

# Baris 10 kg memutus pola `drift = U/2`: dia memakai 4/sqrt(3). Baris itu juga
# satu-satunya yang tertelusur ke LK-022-IDN. Dicatat, bukan diperbaiki (§18).
DRIFT_KECUALI = {"10000": 4 / math.sqrt(3)}

keping_json = []
for kelas, teks, konv, koreksi, u_mg, drift_mg in KEPING:
    nominal = float(teks.rstrip("*"))
    adu(f"keping {teks} koreksi", konv - nominal, koreksi, tol=0.0, tol_rel=1e-9)
    if teks in DRIFT_KECUALI:
        adu(f"keping {teks} drift (pola 4/sqrt3)", DRIFT_KECUALI[teks], drift_mg)
    else:
        adu(f"keping {teks} drift (pola U/2)", u_mg / 2, drift_mg, tol=1e-12)
    keping_json.append(
        {
            "kelas": kelas,
            "nominal_teks": teks,
            "nominal_g": nominal,
            "bintang": teks.endswith("*"),
            "konvensional_g": konv,
            "koreksi_g": koreksi,
            "u_mg": u_mg,
            "drift_mg": drift_mg,
            "pola_drift": "4/sqrt(3)" if teks in DRIFT_KECUALI else "U/2",
        }
    )

# Tujuh set fisik yang menyusun tabel di atas (`STD AT` + `DATABASE`).
SET_STANDAR = [
    ("Anak Timbangan E2 200 g", "1 mg - 200 g", "E2", "Accurate/Stainless", "75870",
     "LK-045-IDN", "0518/25/KAL/01/2026", "2026-01-22", "2029-01-22", 3),
    ("Anak Timbangan E2 500 g", "500 g", "E2", "Accurate/Stainless", "100636",
     "LK-045-IDN", "0221/25/KAL/01/2026", "2026-01-13", "2029-01-13", 3),
    ("Anak Timbangan E2 1 kg", "1000 g", "E2", "Want Ballance/E2", "120015",
     "LK-045-IDN", "", "2026-02-23", "2029-02-23", 3),
    ("Anak Timbangan F1-2", "2000 g", "F1", "Accurate/F1", "45723",
     "LK-045-IDN", "", "2026-02-04", "2028-02-04", 2),
    ("Anak Timbangan F1-5", "5000 g", "F1", "Accurate/F1", "111962",
     "LK-045-IDN", "", "2026-02-04", "2028-02-04", 2),
    ("Anak Timbangan F1-10", "10 kg", "F1", "Excellent/F1", "4321",
     "LK-022-IDN", "", "2024-10-21", "2026-10-21", 2),
    ("Anak Timbangan F1-20", "20 kg", "F1", "Want Ballance/F1", "130016",
     "LK-045-IDN", "", "2026-02-23", "2028-02-23", 2),
]

# ===================================================================== 2. Neraca
# `Deviasi Standard Timbangan` (enam hari x sepuluh ulangan ABBA) dan kelima
# sheet `Data Sens *`. Semua simpangan baku harian dalam GRAM.
#
#   nama: (merk_tipe, sn, tertelusur, tanggal_database, kapasitas_g, resolusi_g,
#          u95_g, nominal_at_uji_g, [stdev harian], rata_rata_stdev_master_g)
NERACA = {
    "Semi Micro Balance": (
        "OHAUS PIONEER/PX85", "C543502629", "LK-064-IDN", "2026-01-26",
        80.0, 1e-05, 9.7e-05, 50.0,
        [8.959786704781921e-06, 7.149203529406107e-06, 6.146362971424353e-06,
         5.868938953596891e-06, 1.0749676997214277e-05, 9.143911150739058e-06],
        1.923103720334314e-06,
    ),
    "Analytical Balance": (
        "Mettler Toledo/XS204", "1129063525", "LK-305-IDN", "2026-01-19",
        220.0, 0.0001, 0.0011, 200.0,
        [5.3748384990441254e-05, 4.9721446302238236e-05, 0.00011547005384175837,
         0.00012122064364381199, 0.00011167910378871372, 0.00017320508076263755],
        4.636297841870652e-05,
    ),
    "Electronic Balance Fujitsu": (
        "Fujitsu/FSR-A", "SIDIK/134/2024", "LK-305-IDN", "2026-01-19",
        1200.0, 0.001, 0.0019, 1000.0,
        [0.000437797517875104, 0.00045946829172547574, 0.00040824829045420924,
         0.0005868938953747555, 0.0009204467514105059, 0.0006701782515623399],
        0.0001940774013471526,
    ),
    "Electronic Balance Excellent": (
        "Excellent/DJ", "HSEX1403752", "LK-305-IDN", "2026-01-19",
        3100.0, 0.01, 0.007, 2000.0,
        [0.0036893239368597525, 0.00745355992499252, 0.006258327785167171,
         0.004216370213554004, 0.003374742788549695, 0.006146362971523002],
        0.0016537714466089547,
    ),
    "Electronic Balance  Mettler": (
        "Mettler Toledo/IND690", "3127471", "LK-305-IDN", "2026-01-19",
        30000.0, 0.01, 0.32, 20000.0,
        [0.00316227765966219, 0.006433419687839654, 0.007090682462051761,
         0.005163977794547142, 0.006433419688248057, 0.005676462121778928],
        0.0013948572862164344,
    ),
}

# Rantai percobaan sensitivitas, semua dalam MILIGRAM kecuali yang disebut lain.
#   nama: (delta1_mg, delta2_mg, de_sens_mg, u_msens_mg, m_sens_mg, usens_master_mg)
#
# `delta1`/`delta2` = selisih rata-rata pembacaan dengan dan tanpa keping Msens,
# satu lewat sisi T dan satu lewat sisi S. Urutannya TIDAK konsisten antar sheet
# (Analytical menyimpan sisi-T dulu, Mettler menyimpan sisi-S dulu) — yang selalu
# jadi penyebut cuma `delta1`, apa pun sisinya. Ditiru apa adanya.
#
# Excellent: `delta1 = delta2 = 1000` padahal pembacaannya sendiri memberi 995
# dan 1005. Karena itu suku keterulangannya nol dan `usens`-nya lahir dari
# `u_msens/m_sens` saja. Dicatat di pertanyaan lab §18.
SENSITIVITAS = {
    "Semi Micro Balance": (
        2.0050000000004786, 2.0000000000024443, -0.014999999997655777,
        0.001, 20.0027, -2.6461006446691237e-05,
    ),
    "Analytical Balance": (
        20.050000000004786, 20.09999999999934, -0.14999999999076863,
        0.001, 20.0027, -0.00026461006456712755,
    ),
    "Electronic Balance Fujitsu": (
        99.50000000000614, 99.99999999999432, -3.0000000000143245,
        0.0015, 100.006, -0.010659996194014476,
    ),
    "Electronic Balance Excellent": (
        1000.0, 1000.0, -14.999999999986358,
        0.0033, 1000.0136, -4.9499326809110374e-05,
    ),
    "Electronic Balance  Mettler": (
        5000.0, 4989.999999999782, -5.000000000109139,
        0.0046, 5000.0284, -0.007071069308394962,
    ),
}

neraca_json = []
for nama, (merk, sn, tertelusur, tanggal, kap, res, u95, nominal_uji,
           stdev_harian, rata_master) in NERACA.items():
    # Sel master berlabel "Rata-rata STDev" sebenarnya SIMPANGAN BAKU dari
    # keenam angka harian. Diturunkan ulang, bukan disalin (pertanyaan lab §1).
    std_dev_g = adu(
        f"{nama} keterulangan (stdev dari 6 stdev harian)",
        statistics.stdev(stdev_harian),
        rata_master,
        tol=0.0,
        tol_rel=1e-12,
    )
    d1, d2, de_sens, u_msens, m_sens, usens_master = SENSITIVITAS[nama]
    s_sens = abs(d1 - d2) / math.sqrt(2)
    u_rel = math.sqrt((s_sens / d1) ** 2 + (u_msens / m_sens) ** 2)
    u_sens_mg = adu(
        f"{nama} usens", de_sens * u_rel, usens_master, tol=0.0, tol_rel=1e-12
    )

    neraca_json.append(
        {
            "nama": nama,
            "merk_tipe": merk,
            "no_seri": sn,
            "tertelusur": tertelusur,
            "tanggal_database": tanggal,
            "kapasitas_g": kap,
            "resolusi_g": res,
            "u95_g": u95,
            "nominal_at_uji_g": nominal_uji,
            "stdev_harian_mg": [x * 1000 for x in stdev_harian],
            "std_dev_mg": std_dev_g * 1000,
            "gabungan_harian_mg": math.sqrt(
                sum(x * x for x in stdev_harian) / len(stdev_harian)
            ) * 1000,
            "u_sens_mg": u_sens_mg,
            "sensitivitas": {
                "delta1_mg": d1,
                "delta2_mg": d2,
                "de_sens_mg": de_sens,
                "u_msens_mg": u_msens,
                "m_sens_mg": m_sens,
                "u_relatif": u_rel,
            },
        }
    )

# ===================================================================== 3. Densitas
# `STD AT` blok "Kelas Anak Timbang OIML (kg/m3)". DISENGKETAKAN — lihat §6.
DENSITAS = [
    (100.0, 8010, 8060.000000000001, 8550, 4400, 2300),
    (50.0, 8010, 8080, 9000, 4000, None),
    (20.0, 8035, 8350, 14400, 2600, None),
    (10.0, 8080, 9000, 4000, 2000, None),
    (5.0, 8250, 10650, 3000, 4000, None),
    (2.0, 9000, 4000, 2000, 3000, None),
    (1.0, 10650, 3000, 4000, 2000, None),
    (0.5, 4400, 2200, 3000, None, None),
    (0.2, 3000, 4400, 2200, None, None),
    (0.1, 4400, 3000, None, None, None),
    (0.05, 3400, None, None, None, None),
    (0.01, 2300, None, None, None, None),
]

# ===================================================================== 4. MPE
# `MPE AT` — OIML R111 Tabel 1, satuan miligram. Kolom kosong = kelas itu tidak
# didefinisikan untuk nominal tersebut.
MPE = [
    ("20 kg", 20000, 100, 300, 1000, None, 3000, None, 10000),
    ("10 kg", 10000, 50, 160, 500, None, 1600, None, 5000),
    ("5 kg", 5000, 25, 80, 250, None, 800, None, 2500),
    ("2 kg", 2000, 10, 30, 100, None, 300, None, 1000),
    ("1 kg", 1000, 5, 16, 50, None, 160, None, 500),
    ("500 g", 500, 2.5, 8, 25, None, 80, None, 250),
    ("200 g", 200, 1, 3, 10, None, 30, None, 100),
    ("100 g", 100, 0.5, 1.6, 5, None, 16, None, 50),
    ("50 g", 50, 0.3, 1, 3, None, 10, None, 30),
    ("20 g", 20, 0.25, 0.8, 2.5, None, 8, None, 25),
    ("10 g", 10, 0.2, 0.6, 2, None, 6, None, 20),
    ("5 g", 5, 0.16, 0.5, 1.6, None, 5, None, 16),
    ("2 g", 2, 0.12, 0.4, 1.2, None, 4, None, 12),
    ("1 g", 1, 0.1, 0.3, 1, None, 3, None, 10),
    ("500 mg", 0.5, 0.08, 0.25, 0.8, None, 2.5, None, None),
    ("200 mg", 0.2, 0.06, 0.2, 0.6, None, 2, None, None),
    ("100 mg", 0.1, 0.05, 0.16, 0.5, None, 1.6, None, None),
    ("50 mg", 0.05, 0.04, 0.12, 0.4, None, None, None, None),
    ("20 mg", 0.02, 0.03, 0.1, 0.3, None, None, None, None),
    ("10 mg", 0.01, 0.025, 0.08, 0.25, None, None, None, None),
    ("5 mg", 0.005, 0.02, 0.06, 0.2, None, None, None, None),
    ("2 mg", 0.002, 0.02, 0.06, 0.2, None, None, None, None),
    ("1 mg", 0.001, 0.02, 0.06, 0.2, None, None, None, None),
]
KELAS_MPE = ["F1", "F2", "M1", "M1-2", "M2", "M2-3", "M3"]

# MPE OIML tidak pernah turun waktu nominalnya naik. Kalau tabelnya pernah
# tergeser, monotonisitas itu yang pertama patah.
for kelas_ke, kelas in enumerate(KELAS_MPE):
    deret = [(b[1], b[2 + kelas_ke]) for b in MPE if b[2 + kelas_ke] is not None]
    for (n_besar, v_besar), (n_kecil, v_kecil) in zip(deret, deret[1:]):
        if v_besar < v_kecil:
            menyimpang.append(
                f"MPE {kelas}: {n_kecil} g ({v_kecil} mg) > {n_besar} g ({v_besar} mg)"
            )

# ===================================================================== 5. Meter lingkungan
# `DATABASE` blok Environmental Meter. Titik indeks dipilih TERDEKAT dari
# "Standart Indication"; seri memilih yang lebih rendah (§13).
METER = [
    {
        "nama": "Thermobarometer",
        "lokasi": "Inlab (Lab. Volumetrik)",
        "tanggal_kalibrasi": "2024-06-26",
        "due_date": "2026-06-27",
        "tertelusur": "LK-172-IDN",
        "suhu": {"u95": 1.2, "titik": [[20.1, 20, 0.1], [26, 25, 1], [29.7, 30, -0.3],
                                       [34.7, 35, -0.3], [39.8, 40, -0.2]]},
        "kelembaban": {"u95": 3, "titik": [[38, 40, -2], [48.5, 50, -1.5], [59.2, 60, -0.8],
                                           [70, 70, 0], [81.1, 80, 1.1]]},
        "tekanan": {"u95": 2, "titik": [[931, 930, 1], [941.1, 940, 1.1], [950.9, 950, 0.9],
                                        [961.2, 960, 1.2], [971, 970, 1], [981.1, 980, 1.1],
                                        [990.6, 990, 0.6], [1001, 1000, 1], [1006, 1005, 1]]},
    },
    # Meter kedua `TH-7` SENGAJA tidak disalin. Kolom Correction-nya tidak
    # rekonsiliasi dengan pasangan indikasinya sendiri:
    #
    #   suhu 15,32 vs 15  -> selisih 0,32, tertulis 0,36  (+0,04)
    #   suhu 20,36 vs 20  -> selisih 0,36, tertulis 0,40  (+0,04)
    #   suhu 30,27 vs 30  -> selisih 0,27, tertulis 0,31  (+0,04)
    #   suhu 40,78 vs 40  -> selisih 0,78, tertulis 0,78  (cocok)
    #   RH   48,72 vs 50  -> selisih -1,28, tertulis -0,88 (+0,40)
    #   RH   69,06 vs 70  -> selisih -0,94, tertulis -0,54 (+0,40)
    #
    # Offsetnya tetap (0,04 untuk suhu, 0,40 untuk RH) dan meleset di empat dari
    # lima baris — pola yang tidak bisa dijelaskan dari nilainya saja.
    # Thermobarometer, yang dipakai sesi anak timbangan, rekonsiliasi sempurna.
    # Menyalin tabel yang tidak rekonsiliasi berarti menaruh angka yang belum
    # terverifikasi di server dengan tampang berwenang. Pertanyaan lab §19.
]

for m in METER:
    for besaran in ("suhu", "kelembaban", "tekanan"):
        blok = m[besaran]
        if blok is None:
            continue
        for standar, instrumen, koreksi in blok["titik"]:
            adu(f"{m['nama']} {besaran} koreksi @{instrumen}",
                standar - instrumen, koreksi, tol=1e-9)

# ===================================================================== 6. Susun
data = {
    "sumber": {
        "workbook": "1.1 Anak Timbangan F1 1mg-500 g 202501022 imp.xlsx",
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
    "standar_at": {"set": [
        {
            "nama": nama, "kapasitas": kap, "kelas": kelas, "merk_tipe": merk,
            "no_seri": sn, "tertelusur": tert, "no_sertifikat": cert,
            "tanggal_kalibrasi": tgl, "due_date": due, "interval_tahun": interval,
        }
        for nama, kap, kelas, merk, sn, tert, cert, tgl, due, interval in SET_STANDAR
    ], "keping": keping_json},
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
        "baris": [
            {"nominal_g": b[0], "E2": b[1], "F1": b[2], "F2": b[3], "M1": b[4], "M2": b[5]}
            for b in DENSITAS
        ],
        "aturan_diatas_100g": (
            "Nominal >= 100 g memakai baris 100 g ('Note : Diatas 100 g nilainya =')"
        ),
    },
    "mpe": {
        "satuan": "mg",
        "acuan": "OIML R111-1 Tabel 1",
        "kelas": KELAS_MPE,
        "baris": [
            {"label": b[0], "nominal_g": b[1],
             **{k: b[2 + i] for i, k in enumerate(KELAS_MPE)}}
            for b in MPE
        ],
    },
    "meter_lingkungan": METER,
    "drift_penimbangan": [
        {"maks_index_standar_g": 200, "kelompok": "E2 0.1-200 g", "u_drift_g": 0},
        {"maks_index_standar_g": 500, "kelompok": "E2 500 g", "u_drift_g": 0},
        {"maks_index_standar_g": 1000, "kelompok": "E2 1000 g", "u_drift_g": 0},
        {"maks_index_standar_g": 2000, "kelompok": "F1 2 kg", "u_drift_g": 0},
        {"maks_index_standar_g": 5000, "kelompok": "F1 5 kg", "u_drift_g": 0},
        {"maks_index_standar_g": 10000, "kelompok": "F1 10 kg",
         "u_drift_g": 0.0023094010767038932},
        {"maks_index_standar_g": 20000, "kelompok": "F1 20 kg", "u_drift_g": 0},
    ],
}

if menyimpang:
    print("MENOLAK MENULIS — turunan tidak cocok dengan master:", file=sys.stderr)
    for baris in menyimpang:
        print("  -", baris, file=sys.stderr)
    sys.exit(1)

KELUARAN.write_text(
    json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
)
print(f"Ditulis {KELUARAN}")
print(f"  {len(keping_json)} keping standar, {len(neraca_json)} neraca, "
      f"{len(DENSITAS)} baris densitas, {len(MPE)} baris MPE, {len(METER)} meter")

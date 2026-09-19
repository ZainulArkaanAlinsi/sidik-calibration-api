#!/usr/bin/env python3
"""Generate `database/data/tabel-standar-flowmeter.json` dari DUA master lab.

Sumber (ber-password, sudah diekspor ke CSV di
`Project-PT-Sidik/alat-alat-Pt-Sidik/Aliran_CSV/`):

  1. `1.3 Master Olah Data Flowmeter_Ultrasonic Totalizer (1000-1900L) 2026.xlsm`
  2. `Master olda Ultrasonic Flowrate (100-300 lpm) 2026.xlsm`

## Kenapa DUA workbook dibaca, bukan satu

Keduanya memuat KEDUA tabel standar (`std_totalizer` di `D10:H21`,
`std_flowrate` di `Q10:U18`) untuk keping UFM Krohne yang SAMA. Kalau salah satu
pernah diperbarui sendirian, angka yang terbit tergantung workbook mana yang
kebetulan dipakai teknisi — dan tidak ada satu pun sel yang memprotes. Skrip ini
mengadu keduanya baris demi baris dan **berhenti tanpa menulis** kalau berbeda.

## Baris kosong DIBUANG, bukan disalin

`std_totalizer` menyapu 12 baris (4 terisi), `std_flowrate` 9 baris (3 terisi).
Di master, sel kosong dibaca `0` oleh `INDEX/MATCH(MIN(ABS(...)))`, jadi bacaan
di bawah ~50 L lebih dekat ke `0` daripada ke 100,168 — `INDEX` memulangkan 0,
`VLOOKUP(0)` memulangkan `#N/A`, dan sertifikat mencetak `#N/A` ke pelanggan.
Baris kosong tidak pernah masuk JSON; nilai di luar jangkauan tabel jadi `null`
di `TabelStandarFlowmeter` dan pemanggil mengangkatnya jadi titik yang diblokir
dengan alasan kebaca.

Jalankan:  python docs/skrip/gen-tabel-standar-flowmeter.py
"""
import csv
import json
import pathlib

AKAR = pathlib.Path(__file__).resolve().parents[2]
CSV_AKAR = AKAR / "Project-PT-Sidik/alat-alat-Pt-Sidik/Aliran_CSV"
DIR = {
    "totalizer": CSV_AKAR / "1_3_Master_Olah_Data_Flowmeter_Ultrasonic_Totalizer__1000-1900L__2026",
    "flowrate": CSV_AKAR / "Master_olda_Ultrasonic_Flowrate__100-300_lpm__2026",
}
KELUARAN = AKAR / "database/data/tabel-standar-flowmeter.json"


def kol(huruf):
    """Huruf kolom Excel -> indeks 0-based."""
    n = 0
    for ch in huruf:
        n = n * 26 + ord(ch) - 64
    return n - 1


def baca(mode, nama):
    with open(DIR[mode] / nama, encoding="utf-8-sig", newline="") as f:
        return list(csv.reader(f))


def sel(baris, alamat):
    """Sel lewat alamat Excel, mis. `D10`. CSV baris N = baris sheet N."""
    huruf = "".join(c for c in alamat if c.isalpha())
    nomor = int("".join(c for c in alamat if c.isdigit()))
    i, j = nomor - 1, kol(huruf)
    if i >= len(baris) or j >= len(baris[i]):
        return ""
    return baris[i][j].strip()


def angka(baris, alamat):
    v = sel(baris, alamat)
    return float(v) if v not in ("", "#N/A", "#REF!", "#VALUE!", "#DIV/0!") else None


def tabel_standar(baris, kolom, baris0, baris1):
    """Baris TERISI saja. `kolom` = (standard, uut, koreksi, %OR, U)."""
    keluar = []
    for r in range(baris0, baris1 + 1):
        std = angka(baris, f"{kolom[0]}{r}")
        if std in (None, 0.0):
            continue
        baris_std = {
            "standard": std,
            "uut": angka(baris, f"{kolom[1]}{r}"),
            "koreksi": angka(baris, f"{kolom[2]}{r}"),
            "persen_of_reading": angka(baris, f"{kolom[3]}{r}"),
            "u": angka(baris, f"{kolom[4]}{r}"),
        }
        if None in baris_std.values():
            raise SystemExit(f"Baris {r} tabel standar setengah terisi: {baris_std}. "
                             "Setengah baris tidak boleh masuk — koreksinya dipakai utuh.")
        # Koreksi WAJIB = uut - standard, dan U WAJIB = %OR/100 * uut. Diperiksa,
        # bukan dipercaya: kedua kolom itu rumus di master, dan sertifikat
        # standar berikutnya yang ditempel sebagai NILAI (bukan rumus) sudah
        # pernah menggigit repo ini di alat lain.
        if abs((baris_std["uut"] - baris_std["standard"]) - baris_std["koreksi"]) > 5e-9:
            raise SystemExit(f"Baris {r}: koreksi != uut - standard.")
        if abs(baris_std["persen_of_reading"] / 100 * baris_std["uut"] - baris_std["u"]) > 5e-9:
            raise SystemExit(f"Baris {r}: U != %OR/100 * uut.")
        keluar.append(baris_std)
    return keluar


def pita_cmc(baris, baris0, baris1):
    """Pita CMC dari `DATABASE!R5:S6` — labelnya teks, batasnya diurai dari situ."""
    keluar = []
    for r in range(baris0, baris1 + 1):
        label = sel(baris, f"R{r}")
        persen = angka(baris, f"S{r}")
        if not label or persen is None:
            continue
        # "10 - 78 L" / "190.6 - 519.4 Lpm" -> (10.0, 78.0, "L")
        kiri, kanan = [p.strip() for p in label.split("-", 1)]
        potong = kanan.split(" ", 1)
        keluar.append({
            "label": label,
            "min": float(kiri),
            "maks": float(potong[0]),
            "satuan": potong[1].strip() if len(potong) > 1 else "",
            "cmc_persen_of_reading": persen,
        })
    if len(keluar) != 2:
        raise SystemExit(f"Pita CMC bukan dua: {keluar}. Lampiran akreditasi no. 30/31 masing-masing dua pita.")
    return keluar


def main():
    sk = {m: baca(m, "STANDAR KALIBRATOR.csv") for m in DIR}
    db = {m: baca(m, "DATABASE.csv") for m in DIR}

    # --- tabel standar: WAJIB identik di kedua workbook -------------------
    tot = {m: tabel_standar(sk[m], "DEFGH", 10, 21) for m in DIR}
    flw = {m: tabel_standar(sk[m], "QRSTU", 10, 18) for m in DIR}

    if tot["totalizer"] != tot["flowrate"]:
        raise SystemExit("std_totalizer BEDA antara kedua workbook — jangan pilih salah satu.\n"
                         f" totalizer: {tot['totalizer']}\n flowrate : {tot['flowrate']}")
    if flw["totalizer"] != flw["flowrate"]:
        raise SystemExit("std_flowrate BEDA antara kedua workbook — jangan pilih salah satu.\n"
                         f" totalizer: {flw['totalizer']}\n flowrate : {flw['flowrate']}")

    std_totalizer, std_flowrate = tot["totalizer"], flw["totalizer"]

    if len(std_totalizer) != 4:
        raise SystemExit(f"std_totalizer {len(std_totalizer)} baris terisi, bukan 4.")
    if len(std_flowrate) != 3:
        raise SystemExit(f"std_flowrate {len(std_flowrate)} baris terisi, bukan 3.")

    # --- pita CMC: BEDA per varian, dan itu memang benar ------------------
    cmc_tot = pita_cmc(db["totalizer"], 5, 6)
    cmc_flw = pita_cmc(db["flowrate"], 5, 6)

    if cmc_tot[0]["satuan"] != "L" or cmc_flw[0]["satuan"] != "Lpm":
        raise SystemExit(f"Satuan pita CMC tertukar: {cmc_tot} / {cmc_flw}")

    # --- ketidakpastian standar pendukung --------------------------------
    # Dibaca dari workbook Totalizer (`DATABASE!V15`/`V16`) dan diadu ke
    # workbook Flowrate yang membacanya dari `STANDAR KALIBRATOR!E32`/`R32`.
    u95_yoko = {"totalizer": angka(db["totalizer"], "V15"), "flowrate": angka(sk["flowrate"], "E32")}
    u95_typek = {"totalizer": angka(db["totalizer"], "V16"), "flowrate": angka(sk["flowrate"], "R32")}

    for nama, pasang in (("U95 Yokogawa", u95_yoko), ("U95 Type K", u95_typek)):
        if pasang["totalizer"] is None or pasang["flowrate"] is None:
            raise SystemExit(f"{nama} tidak terbaca: {pasang}")
        if abs(pasang["totalizer"] - pasang["flowrate"]) > 1e-12:
            raise SystemExit(f"{nama} beda antar-workbook: {pasang}")

    u95_caliper = angka(sk["totalizer"], "E43")
    u95_thickness = angka(sk["totalizer"], "R43")

    data = {
        "_sumber": "1.3 Master Olah Data Flowmeter_Ultrasonic Totalizer (1000-1900L) 2026.xlsm & "
                   "Master olda Ultrasonic Flowrate (100-300 lpm) 2026.xlsm (ber-password). "
                   "Digenerate docs/skrip/gen-tabel-standar-flowmeter.py — jangan diketik tangan.",
        "_catatan_baris_kosong": "std_totalizer menyapu 12 baris di master (4 terisi), std_flowrate 9 "
                                 "baris (3 terisi). Baris kosong SENGAJA tidak disalin: di master dia "
                                 "dibaca 0 oleh MIN(ABS(...)) dan menerbitkan #N/A ke sertifikat.",
        "_catatan_validasi": "FORM VALIDASI kolom VALIDATION KOSONG di kedua master (J7/K8). Angka di "
                             "sini berasal dari master yang belum lolos validasi internalnya sendiri — "
                             "lihat docs/pertanyaan-lab-flowmeter.md §1.",
        "std_totalizer": std_totalizer,
        "std_flowrate": std_flowrate,
        "cmc": {"totalizer": cmc_tot, "flowrate": cmc_flw},
        "standar": {
            "ufm": {
                "nama": "Ultrasonic Flowmeter",
                "merk": "Krohne",
                "tipe": "UFC300",
                "seri": sel(db["totalizer"], "T14"),
                "tertelusur": sel(db["totalizer"], "U14"),
                "tanggal_kalibrasi": "2025-08-08",
                "tanggal_jatuh_tempo": "2026-08-08",
                "resolusi": 0.001,
            },
            "yokogawa": {
                "nama": "Temperature Calibrator",
                "merk": "Yokogawa",
                "tipe": "CA 150 Handy Cal",
                "seri": sel(db["totalizer"], "T15"),
                "tertelusur": sel(db["totalizer"], "U15"),
                "tanggal_kalibrasi": "2025-08-12",
                "tanggal_jatuh_tempo": "2026-08-12",
                "u95_c": u95_yoko["totalizer"],
            },
            "thermocouple": {
                "nama": "Thermocouple Type K",
                "merk": "-",
                "tipe": "Type K",
                "seri": sel(db["totalizer"], "T16"),
                "tertelusur": sel(db["totalizer"], "U16"),
                "tanggal_kalibrasi": "2024-09-06",
                "tanggal_jatuh_tempo": "2026-09-06",
                "u95_c": u95_typek["totalizer"],
            },
            "caliper": {
                "nama": "Digital Caliper",
                "merk": "Tesa",
                "tipe": "Cal-IP67",
                # `DATABASE!T17` dan `STANDAR KALIBRATOR!D39` sama-sama LPI-0368,
                # sementara `PERHITUNGAN U95%!F13` menulis CLP-130990. Yang
                # dipungut yang dipakai jalur hitung; selisihnya jadi pertanyaan
                # lab §17.
                "seri": sel(db["totalizer"], "T17"),
                "tertelusur": sel(db["totalizer"], "U17"),
                "tanggal_kalibrasi": "2025-07-25",
                "tanggal_jatuh_tempo": "2026-07-25",
                "u95_mm": u95_caliper,
            },
            "thickness_gauge": {
                "nama": "Ultrasonic Thickness Gauge",
                "merk": "-",
                "tipe": "TM-8812",
                "seri": sel(sk["totalizer"], "Q39"),
                # `DATABASE!U27` kosong di master — ketertelusurannya BELUM
                # tercatat. Disimpan null, bukan diisi tebakan.
                "tertelusur": None,
                "tanggal_kalibrasi": "2025-08-01",
                "tanggal_jatuh_tempo": "2026-08-01",
                "u95_mm": u95_thickness,
            },
            "timer": {
                "nama": "Timer Software Flowmeter",
                "merk": "-",
                "tipe": "-",
                "seri": sel(db["flowrate"], "T19"),
                "tertelusur": "LK-361-IDN",
                "tanggal_kalibrasi": "2025-04-25",
                "tanggal_jatuh_tempo": "2026-04-25",
                # Hanya dipakai varian Flowrate (durasi 20/40/60 detik). TIDAK
                # masuk budget mana pun — lihat pertanyaan lab §11.
                "hanya_flowrate": True,
            },
        },
        "konstanta": {
            # π master ditulis 3,14, bukan PI(). Ditiru; menggeser A 0,05 %.
            # Pertanyaan lab §8.
            "pi": 3.14,
            # Pembagi 1,73 dipakai komponen rectangular, sementara komponen
            # pengulangan memakai SQRT(3) sungguhan di sheet yang SAMA.
            # Ditiru — pembulatan ke bawah membuat u sedikit lebih besar
            # (arah aman). Pertanyaan lab §7.
            "pembagi_rect": 1.73,
            "pembagi_normal": 2.0,
            "vi_type_b": 50.0,
            "vi_standar_ufm": 60.0,
            # vi komponen suhu BEDA antar-varian: 2 di Totalizer, 50 di
            # Flowrate — komponen yang sama. Pertanyaan lab §6.
            "vi_suhu_totalizer": 2.0,
            "vi_suhu_flowrate": 50.0,
            # Pembagi cross-sectional juga beda: 2 di Totalizer, 1,73 di
            # Flowrate. Pertanyaan lab §6.
            "pembagi_cross_section_totalizer": 2.0,
            "pembagi_cross_section_flowrate": 1.73,
            "velocity_profile_persen": 0.11,
            "geometry_factor_persen": 0.3,
            "drift_ufm_persen": 1.0,
            # Koefisien sensitivitas suhu: STD_terkoreksi * 0,00021 / rho^2.
            # Asal 0,00021 tidak bersumber di workbook mana pun — pertanyaan §9.
            "koefisien_muai_air_per_c": 0.00021,
            # Densitas air murni bebas udara — Tanaka/Kell (ITS-90).
            "densitas_a0": 0.999974,
            "densitas_a1": 3.983035,
            "densitas_a2": 301.797,
            "densitas_a3": 522528.9,
            "densitas_a4": 69.34881,
            "u95_caliper_mm": u95_caliper,
            "u95_thickness_gauge_mm": u95_thickness,
            "u95_yokogawa_c": u95_yoko["totalizer"],
            "u95_thermocouple_c": u95_typek["totalizer"],
        },
        "satuan": {
            # Faktor konversi ke satuan budget. Yang berbasis MASSA sengaja
            # `null`: master menulis `perlu dibagi densitas` (teks) untuk kg,
            # `=S23/1000` (0,0166667 — salah dimensi) untuk kg/h, dan `#REF!`
            # untuk kg/min. Tidak ada satu pun yang bisa dipakai; titik
            # bersatuan massa tanpa densitas UUT DIBLOKIR.
            "totalizer": {"L": 1.0, "m3": 1000.0, "usg": 3.78541, "ml": 0.001, "kg": None},
            "flowrate": {
                "LPM": 1.0,
                "m3/h": 1000.0 / 60.0,
                "usg/min": 3.785411784,
                "m3/min": 1000.0,
                "kg/h": None,
                "kg/min": None,
            },
        },
    }

    KELUARAN.parent.mkdir(parents=True, exist_ok=True)
    KELUARAN.write_text(json.dumps(data, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"Ditulis: {KELUARAN}")
    print(f"  std_totalizer {len(std_totalizer)} baris, std_flowrate {len(std_flowrate)} baris")
    print(f"  CMC totalizer {[p['label'] for p in cmc_tot]}")
    print(f"  CMC flowrate  {[p['label'] for p in cmc_flw]}")


if __name__ == "__main__":
    main()

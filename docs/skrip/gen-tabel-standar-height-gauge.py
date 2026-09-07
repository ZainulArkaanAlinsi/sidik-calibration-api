#!/usr/bin/env python3
"""Generate `database/data/tabel-standar-height-gauge.json` dari master lab.

Sumber: `Master_olda_Height_Gauge_600_mm_2026.xlsm` (password `spirit285`),
sudah diekspor ke CSV di
`Project-PT-Sidik/alat-alat-Pt-Sidik/panjang/Height_Gauge_600mm_CSV/`.

Tabel standarnya DIGENERATE, tidak diketik: dua puluh angka koreksi Caliper
Checker (Outside + Inside) diketik tangan berarti satu digit yang meleset
menggeser koreksi satu titik sertifikat tanpa satu pun error.

Yang WAJIB ikut walau tidak dipakai jalur hitung: tabel **Inside**
(`Std_CaliperCek!C23:J32`). Seluruh `VLOOKUP` Height Gauge memakai
`Nom_Outside`, jadi Inside tidak pernah tersentuh — tapi dia ADA di master, dan
membuangnya berarti pembaca berikutnya mengira lab tidak punya angkanya. Dia
disalin, TIDAK disambungkan; lihat `docs/pertanyaan-lab-height-gauge.md` §9.

Jalankan:  python docs/skrip/gen-tabel-standar-height-gauge.py
"""
import csv
import json
import pathlib

AKAR = pathlib.Path(__file__).resolve().parents[2]
CSV_DIR = AKAR / "Project-PT-Sidik/alat-alat-Pt-Sidik/panjang/Height_Gauge_600mm_CSV"
KELUARAN = AKAR / "database/data/tabel-standar-height-gauge.json"

# Kolom sheet `Std_CaliperCek` (0-based): C=2 nominal, F=5 nilai terkoreksi,
# H=7 koreksi (mm), I=8 koreksi (um), J=9 U95 (um).
KOL_NOMINAL, KOL_TERKOREKSI, KOL_KOREKSI_UM, KOL_U95_UM = 2, 5, 8, 9


def baca(nama):
    with open(CSV_DIR / nama, encoding="utf-8-sig", newline="") as f:
        return [r for r in csv.reader(f)]


def sel(baris, i, kol):
    return baris[i][kol].strip() if i < len(baris) and kol < len(baris[i]) else ""


def tabel(baris, mulai):
    """Sepuluh baris nominal/terkoreksi mulai dari indeks `mulai` (0-based)."""
    keluar = []
    for i in range(mulai, mulai + 10):
        nominal = sel(baris, i, KOL_NOMINAL)
        terkoreksi = sel(baris, i, KOL_TERKOREKSI)
        if not nominal or not terkoreksi:
            raise SystemExit(f"Baris {i + 1} tabel standar kosong — CSV tidak utuh.")
        u95 = float(sel(baris, i, KOL_U95_UM))
        # U95 4,1 um berlaku untuk KESEPULUH baris di KEDUA tabel. Diperiksa,
        # bukan diasumsikan: begitu lab menerbitkan sertifikat Caliper Checker
        # yang U95-nya berbeda per nominal, komponen budget #3 berhenti bisa
        # jadi satu angka tingkat-sesi dan skrip ini harus berhenti dulu.
        if abs(u95 - 4.1) > 1e-9:
            raise SystemExit(f"Baris {i + 1}: U95 {u95} != 4,1 um — budget #3 bukan lagi satu angka sesi.")
        keluar.append({
            "nominal_mm": float(nominal),
            "nilai_terkoreksi_mm": float(terkoreksi),
            "koreksi_um": float(sel(baris, i, KOL_KOREKSI_UM)),
        })
    return keluar


def main():
    b = baca("Std_CaliperCek.csv")

    # Sheet dimulai baris 1 = indeks 0. Tabel Outside `C10:J19` -> indeks 9;
    # Inside `C23:J32` -> indeks 22.
    outside = tabel(b, 9)
    inside = tabel(b, 22)

    nominal_outside = [t["nominal_mm"] for t in outside]
    if nominal_outside != [25, 50, 100, 150, 200, 300, 400, 500, 550, 600]:
        raise SystemExit(f"Sepuluh nominal Outside berubah: {nominal_outside}. "
                         "Titik lembar kerja PRA-CETAK ikut dari sini — periksa Instruksi Kerja dulu.")

    data = {
        "_sumber": "Master_olda_Height_Gauge_600_mm_2026.xlsm (sheet Std_CaliperCek & DATABASE), "
                   "password spirit285. Tabel Inside ikut disalin tapi TIDAK tersambung ke mesin "
                   "hitung — seluruh VLOOKUP jalur Height Gauge memakai Nom_Outside.",
        "_digenerate_oleh": "docs/skrip/gen-tabel-standar-height-gauge.py",
        "standar": {
            "nama": "Caliper Checker",
            "merk": "Metrology",
            "tipe": "CMG-9060C",
            "merk_tipe": "Metrology/CMG-9060C",
            "seri": "800035",
            "tanggal_kalibrasi": "2026-01-09",
            "tertelusur": "LK-404-IDN",
            "rentang": "0-600 mm",
            "u95_um": 4.1,
        },
        "outside": outside,
        "inside": inside,
        "konstanta": {
            "suhu_acuan_c": 20.0,
            # P35 = Q35: koefisien muai balok & UUT, dipakai suku koreksi `Y`.
            "alpha_per_c": 1.2e-6,
            # S24 = 2 x R24: rentang muai dua benda, dipakai budget #5 dan
            # sebagai `ci` budget #4/#9. Beda 67% dari `alpha_per_c` di atas,
            # dan keduanya ditiru apa adanya — lihat pertanyaan lab §3.
            "delta_alpha_per_c": 2e-6,
            "drift_a_mm": 0.02,
            "drift_b_mm_per_mm": 0.00025,
            # `K10` master membagi selisih HARI dengan 12 sementara satuan
            # komponennya ditulis `mm/th`. Ditiru — membetulkannya ke 365
            # membuat U yang terbit LEBIH KECIL. Pertanyaan lab §1.
            "drift_pembagi_umur": 12,
            "geometri_mm": 0.0005,
            "meja_granit_mm": 0.0044,
            "batas_paralelisme_mm": 0.01,
            "vi_type_b_normal": 200,
            "vi_type_b_rect": 60,
            "vi_repeatability": 9,
            # `N9 = SQRT(6)` sementara `J9` menulis distribusinya `rect.`
            # (pembaginya semestinya akar 3). Ditiru — pertanyaan lab §2.
            "pembagi_muai": 6 ** 0.5,
        },
    }

    KELUARAN.write_text(json.dumps(data, indent=4, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"ditulis {KELUARAN.relative_to(AKAR)} — {len(outside)} baris Outside, {len(inside)} Inside")


if __name__ == "__main__":
    main()

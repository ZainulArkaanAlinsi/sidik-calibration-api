#!/usr/bin/env python3
"""Generate `database/data/sesi-master-height-gauge.json` dari master lab.

Yang diambil cuma MASUKAN — identitas sesi, tiga pembacaan paralelisme, sepuluh
pra-evaluasi, dan tiga pembacaan tiap titik. Hasilnya (koreksi, budget, U95)
TIDAK ditempel: dia lahir dari `HeightGaugeProfile::hitungPerGrup()` waktu
seeder jalan, jadi kalau mesin hitungnya bergeser yang merah
`HitungUlangSemuaSesiTest` — bukan angka tempelan yang ikut bergeser diam-diam.

Angka acuan master ikut disalin ke blok `_acuan_master` supaya
`HeightGaugeMasterTest` mengadu ke sana tanpa membuka Excel lagi.

Jalankan:  python docs/skrip/gen-sesi-height-gauge.py
"""
import csv
import json
import pathlib

AKAR = pathlib.Path(__file__).resolve().parents[2]
CSV_DIR = AKAR / "Project-PT-Sidik/alat-alat-Pt-Sidik/panjang/Height_Gauge_600mm_CSV"
KELUARAN = AKAR / "database/data/sesi-master-height-gauge.json"


def baca(nama):
    with open(CSV_DIR / nama, encoding="utf-8-sig", newline="") as f:
        return [r for r in csv.reader(f)]


def sel(baris, i, kol):
    return baris[i][kol].strip() if i < len(baris) and kol < len(baris[i]) else ""


def cari(baris, kol, teks, mulai=0):
    """Indeks baris pertama yang kolom `kol`-nya berisi `teks`.

    Barisnya DICARI, bukan dipatok nomor. Sel `A1` master berisi teks dua
    baris ("KALIBRASI
HEIGHT GAUGE"), jadi satu baris CSV memuat dua baris
    berkas — dan nomor baris Excel tidak sama dengan indeks `csv.reader`.
    Mematoknya membuat setiap ekspor ulang menggeser SELURUH pembacaan satu
    baris tanpa satu pun error: yang terbaca tetap sepuluh angka, cuma angka
    milik titik yang salah.
    """
    for i in range(mulai, len(baris)):
        if sel(baris, i, kol) == teks:
            return i
    raise SystemExit(f"Penanda {teks!r} di kolom {kol} tidak ketemu — bentuk master berubah.")


def angka(baris, i, kolom):
    """Angka dari beberapa kolom, kolom kosong DILEWATI bukan dibaca nol.

    Master melompati kolom `E` di ketiga blok pengukuran — itu merger sel,
    bukan slot yang belum diisi. Membacanya sebagai nol menambah satu
    pembacaan 0,0 ke tiap deret, dan yang bergeser rata-rata & simpangan
    bakunya, tanpa satu pun error.
    """
    keluar = []
    for k in kolom:
        v = sel(baris, i, k)
        if v != "":
            keluar.append(float(v))
    return keluar


def main():
    inp = baca("INPUT DATA.csv")
    per = baca("PERHITUNGAN.csv")

    # --- Blok 1: paralelisme, satu baris di bawah kepala `X1` ---
    # Kolom C, D, dan F — `E` DILEWATI. Itu merger sel, bukan slot keempat;
    # membacanya sebagai nol menambah pembacaan 0,0 yang menggeser Max/Min.
    i_par = cari(inp, 2, "X1") + 1
    paralelisme = angka(inp, i_par, [2, 3, 4, 5])

    # --- Blok 2: pra-evaluasi, satu baris di bawah kepala `X1`..`X10` ---
    i_pra = cari(inp, 12, "X10") + 1
    pra_evaluasi = angka(inp, i_pra, list(range(2, 13)))

    # --- Blok 3: sepuluh titik, mulai satu baris di bawah kepala `X1`/`X2`/`X3` ---
    i_titik = cari(inp, 5, "X1") + 1
    titik = []
    for n in range(10):
        i = i_titik + n * 3
        titik.append({
            "titik_ke": n + 1,
            "nominal_mm": float(sel(inp, i, 2)),
            "pembacaan_mm": angka(inp, i, [5, 6, 7]),
        })

    # --- Angka acuan master, buat HeightGaugeMasterTest ---
    # Sheet PERHITUNGAN: baris titik dipatok dari kepala `wringing`, kolom AA
    # (indeks 26) = Koreksi. Simpangan baku pra-evaluasi di kolom N (13),
    # sebaris dengan sepuluh pembacaannya.
    i_hit = cari(per, 1, "wringing") + 1
    koreksi = [float(sel(per, i_hit + n * 3, 26)) for n in range(10)]
    stdev_pra = float(sel(per, cari(per, 13, "mm", cari(per, 2, "X1")) + 1, 13))

    u95 = baca("PERHITUNGAN U95%.csv")
    # Sheet U95: kolom `u`=10, pembagi=13, vi=16, ui=19, ci=21,
    # (uici)^2=26. Sembilan baris komponen mulai di bawah kepala `Componen`.
    i_k = cari(u95, 1, "Componen") + 1
    komponen = []
    for i in range(i_k, i_k + 9):
        komponen.append({
            "nama": sel(u95, i, 1),
            "satuan": sel(u95, i, 8),
            "distribusi": sel(u95, i, 9),
            "u": float(sel(u95, i, 10)),
            "pembagi": float(sel(u95, i, 13)),
            "vi": float(sel(u95, i, 16)),
            "ui": float(sel(u95, i, 19)),
            "ci": float(sel(u95, i, 21)),
        })

    data = {
        "_catatan": "Sesi contoh dari Master_olda_Height_Gauge_600_mm_2026.xlsm (ber-password). "
                    "Yang ditanam HeightGaugeSeeder cuma MASUKANNYA; koreksi & budget dihitung "
                    "profilnya. `_acuan_master` dipakai HeightGaugeMasterTest, bukan seeder.",
        "_digenerate_oleh": "docs/skrip/gen-sesi-height-gauge.py",
        "_sesi": {
            "nama_alat": "Height Gauge",
            "merk": "Insize",
            "model": "Digital",
            "serial": "1610232804",
            "rentang": "0-600",
            "satuan_alat": "mm",
            "kapasitas_mm": 600.0,
            "resolusi_mm": 0.01,
            "pelanggan": "PT Turbin Contoh Nusantara",
            "alamat": "Jl. Contoh No. 154, KP IV-Bandung 40174",
            "tanggal_terima": "2026-05-05",
            "tanggal": "2026-05-05",
            "nomor_sertifikat": "001-UBLK-05.26",
            "nomor_order": "UBLK-1-5.2026",
            "suhu_awal": 20.2,
            "suhu_akhir": 20.3,
            "rh_awal": 44.0,
            "rh_akhir": 55.0,
            "thermohygro": "TH-1",
            "kerataan_muka_ukur": "baik",
        },
        "paralelisme_mm": paralelisme,
        "pra_evaluasi_mm": pra_evaluasi,
        "titik": titik,
        "_acuan_master": {
            "_catatan": "Angka yang tercetak di masternya sendiri. Umur drift 153,6637324074108 "
                        "hari itu NOW() master (DATABASE!X11 = 2026-06-11 15:55:46,48) dikurangi "
                        "tanggal kalibrasi Caliper Checker (W13 = 2026-01-09) — bukan tanggal "
                        "sesinya. HeightGaugeMasterTest mengoper angka itu apa adanya supaya "
                        "aggregat master bisa direproduksi; sesi ter-seed memakai tanggal "
                        "kalibrasi sesi (116 hari) dan karena itu U-nya sedikit berbeda.",
            "umur_drift_hari": 153.6637324074108,
            "paralelisme_hasil_mm": float(sel(inp, i_par + 1, 9)),
            "stdev_pra_evaluasi_mm": stdev_pra,
            "koreksi_mm": koreksi,
            "komponen": komponen,
            "jumlah_uici_kuadrat": float(sel(u95, i_k + 9, 26)),
            "jumlah_uici_pangkat4_per_vi": float(sel(u95, i_k + 9, 28)),
            "uc_mm": float(sel(u95, i_k + 10, 26)),
            "veff": float(sel(u95, i_k + 11, 26)),
            "k": float(sel(u95, i_k + 12, 26)),
            "u_diperluas_mm": float(sel(u95, i_k + 13, 26)),
        },
    }

    if len(paralelisme) != 3 or len(pra_evaluasi) != 10 or len(titik) != 10:
        raise SystemExit(f"Bentuk sesi tidak utuh: paralelisme={len(paralelisme)} "
                         f"pra_evaluasi={len(pra_evaluasi)} titik={len(titik)}")
    for t in titik:
        if len(t["pembacaan_mm"]) != 3:
            raise SystemExit(f"Titik {t['titik_ke']} bukan 3 pembacaan: {t['pembacaan_mm']}")

    KELUARAN.write_text(json.dumps(data, indent=4, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"ditulis {KELUARAN.relative_to(AKAR)}")


if __name__ == "__main__":
    main()

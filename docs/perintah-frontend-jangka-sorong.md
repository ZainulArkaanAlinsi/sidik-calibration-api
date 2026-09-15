# Perintah frontend — lembar Jangka Sorong (Vernier Caliper)

Dokumen ini berdiri sendiri: tempel ke sesi kerja `sidik-calibration-mobile` apa adanya.

## Ringkas

Backend punya profil baru **`jangka_sorong`** (lampiran LK-285-IDN no. 35, kertas `SIDIK-FM-CAL-0527_Rev.2`).
Alat ber-`nama_alat_kemampuan = "Vernier Caliper"` (atau nama/alias `Jangka Sorong`, `Jangka Sorong Digital`,
`Digital Caliper`, `Vernier Kaliper`) mendapat lembar ini dari `GET /api/calibrations/lembar-kerja`.

Tidak ada tipe tabel baru. Semua bentuk sudah dikenal HP:

- tabel ber-`simpan_ke: "spesifikasi_alat.…"` (seperti Evaluation Height Gauge / Repeatability Timbangan);
- tabel ber-`simpan_ke: "measurements[].<kunci>"` (seperti kelima tabel Flowmeter) — **digabung per posisi baris**.

**Yang wajib dicek di HP:** `lib/services/lembar_kerja_service.dart` harus punya cabang/fixture untuk
`jangka_sorong`. Tanpa itu mode mock jatuh ke lembar pH tiga buffer — lembar yang salah tanpa error.
Fixture `contoh_lembar_kerja_*.dart` digenerate dari respons API sungguhan, jangan diketik.

## Urutan bagian (`bagian[].kode`)

| # | kode | isi |
|---|---|---|
| 1 | `identitas_alat` | identitas, satuan (`mm`/`inch` — **tidak ada µm**), kapasitas, resolusi, suhu/RH awal-akhir, kerataan muka ukur (satu pilihan `baik`/`buruk`), lokasi, thermohygro |
| 2 | `pemilik` | nama, alamat, nomor order |
| 3 | `usage_check` | Caliper Checker CMG-9060C · Gauge Block GB-9122-0 |
| 4 | `kesejajaran` | tabel 3 baris (Atas/Tengah/Bawah) × kolom `nominal` & `pembacaan`, 1 pengulangan, `offset_kunci` 5000 |
| 5 | `evaluasi` | dua tabel: Evaluation Outside (`offset_kunci` 3000) & Inside (4000), masing-masing 1 baris × 10 pembacaan |
| 6 | `hasil_outside` | 11 baris pra-cetak (0, 25, 50, 100, 150, 200, 300, 400, 500, 550, 600) × 10 pembacaan X1, X1', …, X5, X5' |
| 7 | `hasil_inside` | 11 baris pra-cetak (0 + 10 nominal Inside) × 5 pembacaan X1..X5, `offset_kunci` 1000 |
| 8 | `hasil_depth` | 5 baris pra-cetak (tumpukan balok ukur: 10, 20, 30, 40, 50 mm) × 5 pembacaan, `offset_kunci` 2000 |
| 9 | `penutup` | catatan, dikalibrasi/diperiksa oleh |

Semua tabel titik `titik_bisa_diubah: false`. Baris yang tidak dipakai (mis. 400-600 mm untuk caliper 300 mm)
**dibiarkan kosong** — jangan dihapus dari layar: server memetakan pembacaan ke nominal lewat **posisi baris**.

## Payload `POST /api/calibrations`

```json
{
  "equipment_id": 123,
  "input_method": "manual",
  "tanggal_kalibrasi": "2026-01-15",
  "suhu_awal": 20.2, "suhu_akhir": 20.3,
  "kelembaban_awal": 44, "kelembaban_akhir": 55,
  "spesifikasi_alat": {
    "rentang_ukur": "0-300",
    "jangka_sorong": {
      "satuan": "mm",
      "kapasitas_mm": 300,
      "resolusi_mm": 0.02,
      "kerataan_muka_ukur": "baik",
      "pra_evaluasi_outside": {"baris": [{"titik_ukur": null, "pembacaan": [300.00, 300.02, 300.00, 300.02, 300.00, 300.00, 300.02, 300.00, 300.00, 300.02]}]},
      "pra_evaluasi_inside":  {"baris": [{"titik_ukur": null, "pembacaan": [300.00, 300.02, 300.00, 300.00, 300.02, 300.00, 300.00, 300.02, 300.00, 300.00]}]},
      "kesejajaran": {"baris": [
        {"titik_ukur": 1, "nominal": [11.0], "pembacaan": [11.00]},
        {"titik_ukur": 2, "nominal": [11.0], "pembacaan": [11.02]},
        {"titik_ukur": 3, "nominal": [11.0], "pembacaan": [11.00]}
      ]}
    }
  },
  "measurements": [
    {"titik_ukur": 0,  "js_outside": [0,0,0,0,0,0,0,0,0,0], "js_inside": [0,0,0,0,0], "js_depth": [10.00,10.00,10.02,10.00,10.00]},
    {"titik_ukur": 25, "js_outside": [25.00,25.02,25.00,25.00,25.02,25.00,25.00,25.00,25.02,25.00], "js_inside": [25.02,25.00,25.00,25.02,25.00], "js_depth": [20.00,20.02,20.00,20.00,20.00]},
    {"titik_ukur": 50, "js_outside": [50.02,50.00,50.00,50.02,50.00,50.00,50.02,50.00,50.00,50.00], "js_inside": [null,null,null,null,null]}
  ]
}
```

- `measurements[i]` = baris ke-i **ketiga** tabel titik sekaligus (itulah perilaku `_measurementsDeretBernama`
  yang sudah ada). Baris i yang cuma diisi di satu tabel tetap dikirim dengan kunci tabel itu saja.
- `titik_ukur` diambil dari tabel pertama (Outside) dan dipakai server sebagai **pemeriksa** urutan — kalau
  menunjuk baris lain, titik Outside itu ditolak dengan alasan kebaca.
- Deret yang kosong semua **jangan** dikirim sebagai kunci (sudah perilaku HP sekarang).

## Desimal koma

`parseAngka()` di HP sudah menerima koma (`19,06` → `19.06`). Pastikan SEMUA kotak angka di lembar ini lewat
`parseAngka`, termasuk kolom `nominal` tabel Kesejajaran. Server menolak string non-angka (422), bukan menebak.

## Aturan server yang perlu ditampilkan jelas ke teknisi

Datang di respons sebagai `belum_dihitung[]` (`titik_ke` + `alasan`):

| Kondisi | Akibat |
|---|---|
| Kapasitas kosong | seluruh sesi ditahan |
| Kapasitas > 300 mm | sesi TERBIT tanpa lantai CMC dan tanpa klaim akreditasi (peringatan `jangka_sorong_diluar_akreditasi`) |
| Resolusi kosong | seluruh sesi ditahan |
| Evaluation Outside < 2 pembacaan | semua titik Outside ditahan |
| Evaluation Inside < 2 pembacaan | semua titik Inside ditahan (Outside tetap terbit) |
| Tidak ada titik Depth dengan ≥ 2 pembacaan | semua titik Depth ditahan |
| Nominal tidak terdaftar | titik itu ditahan |

`titik_ke` hasil: Outside 1..11, Inside 101..111, Depth 201..205 — tampilkan per tabel, jangan diurut campur.

Peringatan (tidak menahan): Evaluation seragam, kerataan muka ukur "buruk".

## Hasil

Tiap baris hitungan membawa `ketidakpastian_diperluas` = U95 **grupnya** (Outside, Inside, dan Depth
berbeda), `koreksi`, `rata_rata`, dan `titik_ukur` = nilai standar terkoreksi. Satuan selalu mm, 5 desimal.

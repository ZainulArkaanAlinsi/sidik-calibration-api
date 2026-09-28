# Perintah frontend — keluarga TEKANAN & PISTON VOLUME (6 profil, 28 Sep 2026)

Berdiri sendiri: yang mengerjakan HP tidak perlu membuka kode server.

## Profil & kodenya

| `kode` profil | Nama lampiran (`nama_alat_kemampuan`) | Kertas | Metode |
|---|---|---|---|
| `pressure_gauge` | Pressure Gauge | SIDIK-FM-CAL-0507_Rev.5 | SIDIK-IK-CAL-0504_Rev.6 |
| `vacuum_gauge` | Vacuum Gauge | SIDIK-FM-CAL-0507_Rev.5 | SIDIK-IK-CAL-0534_Rev.1 |
| `differential_pressure` | Differential Pressure | SIDIK-FM-CAL-0507_Rev.5 | SIDIK-IK-CAL-0532_Rev.0 |
| `piston_pipette` | Piston Pipette | SIDIK-FM-CAL-0529_Rev.3 | SIDIK-IK-CAL-0522_Rev.5 |
| `dispensett` | Dispensett | SIDIK-FM-CAL-0529_Rev.3 | SIDIK-IK-CAL-0522_Rev.5 |
| `buret_digital` | Buret Digital | SIDIK-FM-CAL-0529_Rev.3 | SIDIK-IK-CAL-0522_Rev.5 |

Bentuk lembar: `GET /api/calibrations/lembar-kerja?equipment_id=…` (seperti alat
lain). **Mode mock HP** butuh CABANG PER PROFIL di
`lib/services/lembar_kerja_service.dart` — tanpa cabang, keenamnya jatuh ke `_`
dan memajang lembar pH tanpa error (sudah terjadi di TIDS, Timbangan,
Micrometer). Fixture-nya **digenerate**, jangan diketik:

```bash
php docs/skrip/gen-contoh-lembar-kerja.php
# menulis lib/models/contoh_lembar_kerja_tekanan.dart & contoh_lembar_kerja_piston.dart
```

Fungsinya: `contohBentukLembarKerja{PressureGauge,VacuumGauge,DifferentialPressure,PistonPipette,Dispensett,BuretDigital}()`.

## TEKANAN — payload

```json
{
  "equipment_id": 1, "input_method": "manual", "tanggal_kalibrasi": "2026-09-24",
  "suhu_awal": 24.5, "suhu_akhir": 24.7, "kelembaban_awal": 51, "kelembaban_akhir": 52,
  "thermohygro_standard_id": 7,
  "spesifikasi_alat": { "tekanan": {
    "varian": "druck13g",            // druck07g | druck13g | spmk | differential (dibatasi per profil)
    "satuan": "Psi",                 // daftar PER VARIAN — lihat pilihan field
    "tampilan": "analog",            // analog | digital
    "rasio_jarum": "1/5",            // WAJIB kalau analog: 1/2 | 1/5 | 1/10
    "resolusi": 1, "kapasitas": 200,
    "media": 2, "tinggi_standar": 0.18, "tinggi_uut": 0, "beda_tinggi": 0.18   // SPMK saja
  }},
  "measurements": [
    { "titik_ukur": 0,  "tekanan_up": [0, 0, 0],        "tekanan_down": [0, 0, 0] },
    { "titik_ukur": 50, "tekanan_up": [49.8, 49.9, 49.8], "tekanan_down": [49.6, 49.7, 49.7] }
  ]
}
```

- `titik_ukur` = **setelan UUT**; kotak UP/DOWN = **bacaan STANDAR**. Satu satuan untuk keduanya.
- Dua tabel sinkron (`offset_kunci` 1000 & 2000), nominal dari tabel pertama — pola Proving Ring.
- Tepat **3 UP + 3 DOWN** per titik, maksimal 14 titik. Titik pertama WAJIB nol (zero error dibaca dari situ).
- Field bertanda `mempengaruhi_ketidakpastian: true` (varian, rasio jarum, media, beda tinggi) **tidak boleh diisi otomatis dari OCR** tanpa konfirmasi teknisi.

**Server memblokir** (titik pulang di `belum_dihitung` dengan alasan terbaca): varian tidak berlaku untuk alat, satuan di luar daftar varian, analog tanpa rasio jarum, resolusi/kapasitas ≤ 0, SPMK tanpa media/beda tinggi, jumlah bacaan salah, **titik di luar rentang kalibrator** (T-4). Standar kedaluwarsa ditahan validator saat approve.

## PISTON VOLUME — payload

```json
{
  "equipment_id": 5, "tanggal_kalibrasi": "2026-02-13",
  "suhu_awal": 20.4, "suhu_akhir": 20.5, "kelembaban_awal": 56, "kelembaban_akhir": 55,
  "tekanan_awal": 933.2, "tekanan_akhir": 933.1,            // hPa — WAJIB (densitas udara)
  "spesifikasi_alat": { "piston": {
    "keluarga": "fixed",              // fixed (1 titik) | graduated (MIN, MID, MAX)
    "satuan": "ml", "kapasitas": 10,
    "sub_jenis": null,                // dispensett: single_stroke|multi_stroke; buret_digital: hand_driven|motor_driven
    "timbangan": "Analytical Balance",// Analytical Balance | Electronic Balance Excellent | Electronic Balance Fujitsu
    "penguapan": {"1": 0}             // koreksi penguapan per titik (g), opsional
  }},
  "measurements": [
    { "titik_ukur": 10,
      "piston_kumulatif": [0, 10.0768, 20.1327, 30.1368, 40.1319, 50.1227, 60.1135, 70.1043, 80.0951, 90.0859, 100.0767],
      "piston_suhu_air": [27.1, 27.1] }
  ]
}
```

- `piston_kumulatif` = **M0..M10 KUMULATIF** (angka di timbangan), tepat 11 kotak;
  label kotaknya dari `pengulangan_arah` (`M0`…`M10`).
- Tabel kumulatif membawa **`kumulatif: true`** → HP WAJIB menampilkan
  **selisih `M_i − M_{i−1}`** di bawah tiap kotak saat mengetik. Satu digit
  salah di M1..M9 (30,1368 → 30,7368) tidak mengubah rata-rata sama sekali; yang
  membengkak cuma STDEV (≈9×) — tanpa selisih, salah ketik tidak terlihat.
  **Sudah dibangun** di mobile: `TabelHasil.kumulatif` +
  `_SelisihKumulatif` (`lembar_kerja_tabel.dart`); selisih negatif merah.
  Dijaga `test/lembar_kerja_tabel_kumulatif_test.dart`.
- Graduated: tiga baris (MIN, MID, MAX) — massanya mirip (~2/4/10 g), jaga
  jangan tertukar kolom.

**Server memblokir**: keluarga/satuan/kapasitas/timbangan kosong, sub-jenis
kosong (Dispensett & Buret Digital), kondisi lingkungan termasuk **tekanan
udara** kosong, jumlah titik salah (fixed 1 / graduated 3), kumulatif ≠ 11 kotak,
suhu air ≠ 2, **kumulatif turun** (kecuali tara-ulang >200 g di M4/M7
graduated), timbangan/termometer lewat jatuh tempo pada tanggal kalibrasi.

## Koma desimal

HP membakukan `99,6` → `99.6`; server membakukan lagi (`AngkaDesimal`). Bentuk
ambigu (`1.234,5`) DITOLAK 422, bukan ditebak.

## Hasil

- Tekanan: tiap titik satu baris hasil; `koreksi` = koreksi arah UP. DOWN,
  histeresis 1–3, dan penunjukan standar kedua arah ada di
  `type_b_components.tekanan_rantai` dan tercetak di blok sertifikat `tekanan`.
- Piston: `titik_ukur` = Nominal, `rata_rata` = Actual Volume (V20).
  `keputusan`/`toleransi` SELALU `null` sampai aturan keputusan dijawab lab
  (V-6) — MPE & vonis usulan ada di `type_b_components.kesesuaian`, jangan
  ditampilkan sebagai vonis resmi.

## Temuan validator baru (28 Sep 2026)

Dua kode ERROR baru di `GET /calibrations/{id}/validasi` (dan di tolakan
approve 422). Dua-duanya **tidak bisa** dilewati `abaikan_peringatan` — HP
jangan menawarkan tombol "setujui tetap" untuk keduanya.

| Kode | Arti | Yang ditampilkan |
|---|---|---|
| `menunggu_keputusan_tm` | Sesi memicu cacat master yang belum diputuskan Technical Manager (tekanan T-1/T-2/T-11: semua sesi DRUCK07G, DRUCK13G, Differential; piston G-2/G-7/G-8 hanya kalau terpicu). Angkanya DIHITUNG dua mode, sertifikat tidak terbit. | `pesan` sudah memuat KEDUA angka (U hitung & U95 terbit; T-11 koreksi per titik; piston V20 per titik atau CMC). `konteks.penyimpangan` (mis. `T-11`) dan `konteks.pertanyaan` (mis. `P-11`) untuk label. Tampilkan sebagai "Ditahan — menunggu keputusan TM", bukan "salah". |
| `versi_rumus_tidak_sepadan` | Versi rumus yang menghitung sesi beda dari versi formula yang distempelkan. Muncul kalau log metode diperbarui tanpa versi formula baru. | `konteks.dihitung` & `konteks.distempel`. Urusan admin/developer, bukan teknisi. |

`kalibrasi:sapu-sesi` melaporkan sesi `menunggu_keputusan_tm` sebagai
**DITAHAN**, bukan ERROR — datanya sehat, keputusannya yang belum ada.

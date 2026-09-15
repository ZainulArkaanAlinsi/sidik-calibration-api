# Perintah Frontend — Dial Indicator (alat ke-30)

Dokumen ini berdiri sendiri. Tempel ke sesi kerja `sidik-calibration-mobile`.

## Status server (15 Sep 2026)

- Profil `dial_indicator` terdaftar. Alat dicocokkan lewat `equipments.nama_alat_kemampuan = "Dial Indicator"`
  atau nama/alias: `Dial Gauge`, `Dial Indikator`, `Jam Ukur`, `Dial Test Indicator`, `Dial Consolidation`.
- `GET /api/calibrations/lembar-kerja?equipment_id=…` memulangkan lembar `SIDIK-FM-CAL-0526_Rev.3`.
- Jalur simpan, hitung ulang, dan validator pra-terbit sudah tersambung dan dites lewat API
  (`DialIndicatorSesiTest`).

## Bentuk lembar

Bagian berurutan: `identitas_alat` → `pemilik` → `usage_check` → `evaluasi` → `hasil` → `penutup`.

### `identitas_alat` — field khusus

| kode | tipe | catatan |
|---|---|---|
| `spesifikasi_alat.dial_indicator.satuan` | pilihan `mm`/`inch`/`µm` | **isi lebih dulu** — mengubah arti kapasitas, resolusi, Evaluation, dan penunjukan |
| `spesifikasi_alat.dial_indicator.kapasitas_mm` | angka | dalam SATUAN ALAT (nama kunci historis) |
| `spesifikasi_alat.dial_indicator.resolusi_mm` | angka | dalam SATUAN ALAT |

### `evaluasi`

- Field `spesifikasi_alat.dial_indicator.balok_pra_evaluasi` tipe **`daftar_angka`**, satuan mm.
  Contoh isian `14+11`. Kirim sebagai array `[14, 11]` (pakai `pecahDaftarAngka`) — server juga menerima
  teks `"14+11"`.
- Tabel `simpan_ke: spesifikasi_alat.dial_indicator.pra_evaluasi`, satu baris, 10 pengulangan,
  `offset_kunci: 1000`. Dikirim sebagai cerminan tabel (`{baris:[{titik_ukur, pembacaan:[…]}]}`) seperti
  tabel `simpan_ke` lain — server meratakannya.

### `hasil`

- `titik_bisa_diubah: true`, 10 baris awal `titik_ukur: null` (kunci baris = indeks).
- `kolom_baris`: `nominal` tipe **`daftar_angka`** — tumpukan balok ukur, mm, mis. `2,5+1,3+1,2`.
  **Koma = koma desimal, BUKAN pemisah** (sudah benar di `pecahDaftarAngka`).
- `pengulangan` 6 dengan `pengulangan_arah`: `UP X1`, `UP X2`, `UP X3`, `DOWN X1`, `DOWN X2`, `DOWN X3`.
- `grup: di_pembacaan`, tanpa `peran` (jangan membelokkan ke jalur pasangan).

## Payload `POST /api/calibrations`

```json
{
  "equipment_id": 123,
  "input_method": "manual",
  "tanggal_kalibrasi": "2024-05-06",
  "suhu_awal": 20.8, "suhu_akhir": 20.7,
  "kelembaban_awal": 56, "kelembaban_akhir": 59,
  "measurements": [
    { "titik_ukur": 0, "nominal": [1.1],           "pembacaan": [1.1, 1.1, 1.1, 1.1, 1.1, 1.1] },
    { "titik_ukur": 0, "nominal": [2.5, 1.3, 1.2], "pembacaan": [5.0, 5.0, 5.0, 5.0, 5.0, 5.0] }
  ],
  "spesifikasi_alat": {
    "rentang_ukur": "0-25",
    "dial_indicator": {
      "satuan": "mm",
      "kapasitas_mm": 25,
      "resolusi_mm": 0.01,
      "balok_pra_evaluasi": [14, 11],
      "pra_evaluasi": { "baris": [ { "titik_ukur": null, "pembacaan": [25.01, 25.01, 25.01, 25.01, 25.01, 25.01, 25.01, 25.01, 25.01, 25.01] } ] }
    }
  }
}
```

- `titik_ukur` boleh `null`/`0`: server mengisinya dengan jumlah keping.
- Angka berkoma dalam teks (`"19,06"`) **diterima** server dan dibaca 19.06 di pembacaan, nominal,
  titik ukur, suhu, kelembapan, kapasitas, resolusi. Bentuk ambigu (`"1.234,5"`) tetap ditolak 422.
- Urutan kotak dipertahankan: kotak kosong di tengah tidak menggeser UP/DOWN.

## Kapan titik/sesi TIDAK dihitung (muncul di `belum_dihitung` pratinjau)

| Kondisi | Akibat |
|---|---|
| Keping tidak ada di daftar Gauge Block GB-9122-0 | titik itu saja, alasan menyebut kepingnya |
| Kapasitas kosong atau > 300 mm | seluruh sesi (tanpa pita CMC) |
| Evaluation < 2 pembacaan | seluruh sesi |
| Balok ukur Evaluation kosong/tak terdaftar | seluruh sesi |
| Resolusi kosong | seluruh sesi |

Sepuluh bacaan Evaluation identik **tetap terbit** dengan peringatan (lihat
`docs/pertanyaan-lab-dial-indicator.md` §5).

Daftar nominal keping yang sah (mm): 1; 1,1; 1,2; 1,3; 1,5; 1,6; 1,7; 1,8; 1,9; 2,5; 5; 6; 7,5; 9; 11; 13;
14; 16; 17; 19; 21; 40; 50; 60; 70; 75; 76,2; 80; 90; 100; 101,6; 200. Validasi di HP boleh jadi lapis
pertama; server tetap penentu.

## Hasil per titik (`uncertainty_calculations`)

`titik_ukur` = standar terkoreksi (mm), `rata_rata`, `koreksi` = standar − rata-rata, `ketidakpastian_diperluas`
= max(U, CMC pita) dalam mm, `faktor_cakupan_k` dari t-Student (bukan dipatok 2). Budget satu per sesi —
semua titik membawa U95 yang sama. Sertifikat lima desimal, satuan mm. Tanpa PASS/FAIL.

## Yang wajib dikerjakan di mobile

1. Cabang `'dial_indicator'` di `lib/services/lembar_kerja_service.dart` + bentuk mock
   `contoh_lembar_kerja_*.dart` — **digenerate** `php docs/skrip/gen-contoh-lembar-kerja.php`, jangan diketik.
   `bentuk_mock_semua_profil_test.dart` akan merah sampai ini ada.
2. Pastikan field tingkat-bagian bertipe `daftar_angka` (balok Evaluation) dikirim sebagai array.
3. Label `pengulangan_arah` UP/DOWN tampil di kepala kolom.

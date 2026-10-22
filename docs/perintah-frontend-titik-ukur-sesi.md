# Perintah frontend — Titik ukur sesi (tambah / ubah / nonaktifkan, sinkron lintas perangkat)

> Server: branch `feat/titik-ukur-sesi` (22 Okt 2026). PRD: `docs/permintaan-user-7.md` §55.
> Status mobile: **BELUM DIKERJAKAN.** Berkas ini berdiri sendiri — tidak perlu membaca kode server.

## 1. Ringkas

Titik ukur khusus sesi (tambah titik, ubah nominal, nonaktifkan titik, fase sebelum/sesudah
adjustment, jumlah pengulangan, titik permintaan pelanggan) sekarang **disimpan server** di
sesi, bukan hanya di draf lokal (`lembar_kerja_state.dart` `_titikKustom`). Perangkat mana pun
yang membuka sesi yang sama melihat set yang sama.

Aturan yang ditegakkan server (bukan cuma disembunyikan di UI):

- Titik yang sudah tersimpan **tidak bisa dihapus**, hanya **dinonaktifkan dengan alasan**.
- **Pembacaan titik nonaktif tidak pernah dihapus** dan tidak boleh diubah. HP wajib tetap
  mengirim baris pembacaan titik nonaktif **apa adanya** di `measurements`.
- Titik nonaktif **tidak dihitung** dan **tidak tercetak** di sertifikat; pembacaannya tetap
  terlihat di jejak (`pembacaan_mentah`).
- Konkurensi optimistis: tiap perubahan menaikkan `revisi`; kiriman berdasar revisi basi → **409**.

## 2. Profil yang mendukung

`batas.didukung` (lihat §3) yang menentukan — jangan menulis daftar di HP. Saat ini:

| Profil | Jenis nominal | Maks titik aktif | Tambah | Nonaktif | Pengulangan |
|---|---|---|---|---|---|
| Timer/Stopwatch | `saran` (60, 300, … s) | 10 | ya | ya | 2–10 (bawaan 3) |
| Timbangan (blok Accuracy, tabel `akurasi`) | `saran` (tangga 10–100 % rentang) | jumlah baris tangga (10) | ya | ya, **kecuali varian substitusi** | dipatok (`null`) |
| Micrometer | `dipatok` (11 nominal kertas) | 11 | **tidak** | ya | 2–10 (bawaan 5) |
| Tekanan (Pressure / Vacuum / Differential) | `bebas` | 14 | ya | ya, **kecuali titik pertama** (zero error dibaca dari titik pertama; `pertama_wajib_aktif`) | dipatok 3 (min=maks=3) |
| Lembar jalur datar: pH, Turbidimeter, Chlorine, Refractometer, Conductivity, Viscometer, DO, Gas Detector | dari bentuk lembar | jumlah baris lembar | kalau baris `titik_bisa_diubah` | ya | 2–10 |

TITS, Centrifuge, dan Tachometer sengaja `didukung: false` sampai diverifikasi per profil.
Profil lain pulang `didukung: false` + `alasan` — tampilkan alasan itu, jangan tawarkan
pengaturan titik. Server menolak `titik_sesi` untuk profil itu (422).

## 3. Membaca

### 3a. Lembar kosong — `GET /api/calibrations/lembar-kerja?equipment_id=…`

Dua kunci baru di `data`:

```json
"batas_titik": {
  "didukung": true,
  "alasan": null,
  "tabel": [{
    "kode": "utama",
    "judul": "Set point",
    "min": 1, "maks": 10,
    "boleh_tambah": true,
    "boleh_nonaktif": true,
    "alasan_tambah": null, "alasan_nonaktif": null,
    "nominal": "saran",            // dipatok | saran | bebas
    "bawaan": [60, 300, 600, ...],
    "fase": ["sesudah"],           // fase yang BOLEH dipilih; ["sebelum","sesudah"] di lembar datar ber-as-found
    "satuan": "s",
    "pengulangan": {"min": 2, "maks": 10, "bawaan": 3}   // null = dipatok lembar
  }]
},
"titik_sesi_bawaan": [
  {"id": "profil-utama-1", "tabel": "utama", "nominal": 60, "satuan": "s", "fase": ["sesudah"],
   "aktif": true, "asal": "profil", "alasan": null, "justifikasi_cmc": null, "tambahan": false}
]
```

`titik_sesi_bawaan` = set pertama yang dipakai HP saat teknisi mulai menyesuaikan titik. `id`
bawaan deterministik (`profil-<tabel>-<n>`).

### 3b. Sesi tersimpan — `GET /api/calibrations/{id}`

```json
"meta": {
  "titik_sesi": {
    "batas": { ...sama seperti batas_titik... },
    "set": { "revisi": 2, "titik": [...], "pengulangan": {"utama": 3},
             "diubah_oleh": 7, "diubah_pada": "2026-10-22T10:00:00+07:00" },   // null = sesi belum punya set
    "bawaan": [...] | null,          // diisi hanya kalau set masih null
    "di_luar_cmc": ["besar"]         // id titik AKTIF di luar cakupan CMC akreditasi
  }
}
```

`set` juga ada di `data.spesifikasi_alat.titik_sesi` (sumbernya sama), tapi **baca dari
`meta.titik_sesi.set`** dan **jangan pernah mengirim balik** `spesifikasi_alat.titik_sesi` —
server membuang salinan itu.

## 4. Menulis

Kunci baru `titik_sesi` di badan `POST /api/calibrations`, `PUT /api/calibrations/{id}`, dan
`POST /api/calibrations/preview`:

```json
"titik_sesi": {
  "revisi": 2,                       // revisi yang jadi dasar perubahan; sesi baru: 0
  "titik": [
    {"id": "profil-utama-1", "tabel": "utama", "nominal": 60, "satuan": "s",
     "fase": ["sesudah"], "aktif": true, "asal": "profil"},
    {"id": "9b1c…uuid", "tabel": "utama", "nominal": 75, "asal": "pelanggan",
     "alasan": "Permintaan pelanggan PO-123", "aktif": true},
    {"id": "profil-utama-2", "tabel": "utama", "nominal": 300, "asal": "profil",
     "aktif": false, "alasan": "Alat pelanggan macet di 300 s"}
  ],
  "pengulangan": {"utama": 3}        // opsional; null per tabel = kembali ke bawaan
}
```

Field per titik:

| Field | Wajib | Aturan |
|---|---|---|
| `id` | ya | unik, ≤64, `[A-Za-z0-9_.:-]`; titik baru → UUID buatan HP; **tetap** sepanjang umur titik |
| `tabel` | kalau batas punya >1 tabel | salah satu `batas.tabel[].kode` |
| `nominal` | ya | angka (koma `0,5` diterima); **unik dalam satu sesi** (aktif maupun nonaktif) |
| `satuan` | tidak | bawaan = `tabel.satuan` |
| `fase` | tidak | bawaan `["sesudah"]`; wajib memuat `sesudah`; subset `tabel.fase` |
| `aktif` | tidak | bawaan `true` |
| `asal` | tidak | `profil` / `teknisi` (bawaan) / `pelanggan` |
| `alasan` | **wajib (≥5 huruf)** bila `aktif=false` atau `asal=pelanggan` | ≤500 |
| `justifikasi_cmc` | tidak | isi untuk titik di luar cakupan CMC (§6) |

Aturan yang wajib dipatuhi HP (server menolak 422 `errors.titik_sesi` / `errors.measurements`):

1. **Kirim SELURUH set**, termasuk titik nonaktif. Titik yang hilang dari kiriman = 422.
2. Perubahan set di `PUT` **wajib bersama `measurements`** (seluruh tabel), supaya hasil hitung
   disusun ulang dari titik aktif.
3. Tiap baris `measurements` wajib punya `titik_ukur` = `nominal` salah satu titik di set
   (Timbangan: **jumlah keping** `nominal[]` baris itu = nominal titik). Pembacaan di nominal
   tak terdaftar → 422.
4. Baris pembacaan titik nonaktif dikirim **persis sama** dengan yang tersimpan (ambil dari
   `pembacaan_mentah` / draf). Diubah atau dihilangkan → 422. Mau mengoreksi? Aktifkan dulu.
5. **Nominal titik yang sudah punya pembacaan tersimpan tidak bisa diubah** (aktif maupun
   nonaktif) — nonaktifkan titik itu lalu tambah titik baru. Nominal titik yang belum punya
   pembacaan boleh diubah (kecuali `dipatok`).
   `dipatok`: nominal titik `asal: profil` tidak bisa diubah; titik di luar `bawaan` ditolak
   bila `boleh_tambah=false`.
6. Jumlah titik **aktif** per tabel di `[min, maks]`; total titik ≤ 60.
7. Nominal titik aktif wajib di dalam rentang ukur alat (aturan yang sama dengan pemeriksa
   pembacaan: `range_min − toleransi … range_max + toleransi`).
8. `pengulangan` di `[min, maks]`; tidak boleh lebih kecil dari pembacaan tersimpan
   (pembacaan tidak pernah dibuang), dan baris yang dikirim tidak boleh melebihi jumlahnya.
9. Tabel dengan `pertama_wajib_aktif: true` (Tekanan): titik pertama set tidak bisa dinonaktifkan
   (`alasan_pertama` menjelaskan kenapa), dan baris pertama `measurements` wajib titik itu.
   Kirim `measurements` mengikuti urutan set.
10. Kiriman tanpa kunci `titik_sesi` (APK lama) **tidak mengubah** set tersimpan; titik nonaktif
   tetap tidak dihitung.

`preview`: kirim `titik_sesi` yang sedang dipegang layar (revisi tidak diperiksa) supaya angka
preview = angka yang nanti tersimpan. Tanpa `titik_sesi`, preview menghitung semua titik.

Respons sukses `POST`/`PUT` membawa `meta.titik_sesi` (bentuk sama dengan §3b) bila sesi punya
set — pakai `meta.titik_sesi.set.revisi` sebagai `revisi` kiriman berikutnya.

### Konflik — 409

```json
{"message": "Titik ukur sesi ini sudah diubah dari perangkat lain (revisi 2, kiriman berdasar revisi 1). …",
 "kode": "titik_sesi_konflik",
 "titik_sesi": { ...set terbaru di server... }}
```

HP: ganti set lokal dengan `titik_sesi` dari respons, tampilkan bedanya ke teknisi, minta dia
mengulang perubahannya. **Jangan** mengirim ulang otomatis dengan revisi baru — itu menimpa
keputusan perangkat lain.

### Realtime

Simpan sukses menyiarkan `PerubahanDataOrganisasi` (`kalibrasi`, `diubah`/`dibuat`, id sesi) —
sinyal yang sudah dipakai. Layar sesi yang terbuka di perangkat lain cukup memuat ulang
`GET /api/calibrations/{id}`.

## 5. Hasil hitung

- `meta.belum_dihitung` (respons simpan) dan `data.belum_dihitung` (preview) memuat titik
  nonaktif dengan alasan "Titik dinonaktifkan teknisi (…)" — tampilkan sebagai keterangan, bukan
  error.
- `data.titik` / `uncertainty_calculations` hanya berisi titik aktif. `titik_ke` titik lain
  **tidak bergeser**.

## 6. CMC

Titik aktif di luar cakupan CMC lampiran akreditasi muncul di `meta.titik_sesi.di_luar_cmc`
(id). Validator (`GET /calibrations/{id}/validasi`, approve) memberi temuan
`titik_di_luar_cmc`:

- tanpa `justifikasi_cmc` → **error**, sertifikat tidak terbit;
- dengan justifikasi → **peringatan** (admin melanjutkan sadar).

Jalan keluarnya: nonaktifkan titik (dengan alasan) atau isi `justifikasi_cmc`. Tandai titiknya
di layar sejak `di_luar_cmc` memuatnya.

## 7. Sertifikat

Titik `asal: pelanggan` yang aktif dicetak seperti titik lain, plus catatan kaki di bawah tabel
hasil: `Titik atas permintaan pelanggan: 75 s.` Tidak ada yang perlu dikerjakan HP.

## 8. Test yang diharapkan di mobile

- set dari `titik_sesi_bawaan` → tambah titik pelanggan → simpan → buka di perangkat lain (mock
  GET) → set sama;
- nonaktifkan → baris pembacaan tetap terkirim apa adanya;
- 409 → set lokal diganti dari respons, tidak kirim ulang otomatis;
- `didukung: false` → pengatur titik tidak tampil, alasan tampil;
- `muatDariSesi` memulihkan set dari `meta.titik_sesi.set` (hari ini tidak dipulihkan sama sekali).

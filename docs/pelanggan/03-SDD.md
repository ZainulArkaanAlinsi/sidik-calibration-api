# 03 — SDD: SIDIK Pelanggan

> Versi 0.1 · 16 Sep 2026 · Turunan dari `02-SRS.md`
> Mengikuti aturan `CLAUDE.md` repo backend: kolom baru adalah pilihan terakhir, dua suite test wajib hijau, dan perubahan dicatat di `docs/BACA-DULU-BACKEND.md`.

## 1. Arsitektur

```
┌────────────────────────┐      ┌────────────────────────────┐
│  SIDIK Pelanggan (App) │      │  CertiCal Internal (App)    │
│  repo: sidik-pelanggan │      │  repo: sidik-calibration-   │
│  -mobile               │      │  mobile  (admin/teknisi)    │
│  com.ptsidik.pelanggan │      │  com.ptsidik.kalibrasi      │
└──────────┬─────────────┘      └─────────────┬──────────────┘
           │ HTTPS /api/pelanggan/v1/*          │ HTTPS /api/*
           │ token ability: pelanggan           │ token ability: internal
           ▼                                    ▼
┌─────────────────────────────────────────────────────────────┐
│  sidik-calibration-api (Laravel 13) — SATU backend           │
│  ┌───────────────────────┐   ┌────────────────────────────┐ │
│  │ Modul Pelanggan        │   │ Modul Internal (yang ada)  │ │
│  │ routes/api_pelanggan   │   │ routes/api.php             │ │
│  │ Controllers\Pelanggan  │   │ Controllers\Api            │ │
│  │ Resources\Pelanggan    │   │ Resources                  │ │
│  └──────────┬────────────┘   └───────────┬────────────────┘ │
│             └──────── Services bersama ───┘                  │
│   AlurPermintaan · SinkronJadwalAlat · PengingatPelanggan    │
│   PenerimaNotifikasi · PengirimPush (FCM) · GenerateCertificate│
│  Scheduler (Asia/Jakarta) · Queue (database)                 │
└───────┬───────────────────────┬───────────────────┬─────────┘
        ▼                       ▼                   ▼
   MySQL (satu DB)      Object storage (R2)    FCM (satu Firebase
   organization_id      privat, URL bertanda   project per lingkungan,
   + customer_id        tangan 5 menit         2 app Android terdaftar)
```

Kenapa satu backend dan satu database: alat, order, sesi, dan sertifikat pelanggan **memang** data yang sama dengan yang dikerjakan teknisi. Memisahkan database berarti sinkronisasi dua arah, dan itu sumber bug yang jauh lebih besar daripada isolasi modul.

Kenapa dua aplikasi dan dua repo: lihat `ADR-001-Strategi-Repo.md`.

## 2. Tata letak modul pelanggan di backend

```
app/
  Http/
    Controllers/Pelanggan/          ← semua controller pelanggan
      AuthController.php  AlatController.php  SertifikatController.php
      PermintaanController.php  PesanController.php  AnggotaController.php
      NotifikasiController.php  PerangkatController.php  AkunController.php
    Controllers/Api/Admin/          ← endpoint lab untuk mengelola pelanggan
      PengajuanAkunController.php  UndanganController.php
      PermintaanInboxController.php  VerifikasiAlatController.php
    Middleware/
      PastikanAplikasi.php          ← cek ability token: pelanggan | internal
      KonteksPerusahaan.php         ← resolve X-Perusahaan-Id → keanggotaan aktif
    Requests/Pelanggan/*            Resources/Pelanggan/*   ← DTO khusus pelanggan
  Services/Pelanggan/
    KonteksPerusahaan.php  AlurPermintaan.php  StatusAlatPelanggan.php
    PengingatPelanggan.php  PembersihFoto.php  PenganonimAkun.php
  Services/SinkronJadwalAlat.php    ← dipakai GenerateCertificate & revisi
  Models/  CustomerMember.php  UndanganPelanggan.php  PengajuanAkunPelanggan.php
           PermintaanKalibrasi.php  PermintaanKalibrasiItem.php
           PermintaanKalibrasiRiwayat.php  PesanPermintaan.php
           PengingatPelangganTerkirim.php
routes/api_pelanggan.php            ← didaftarkan di bootstrap/app.php, prefix api/pelanggan/v1
tests/Feature/Pelanggan/*           ← termasuk IsolasiPerusahaanTest, RuteTidakBocorTest
```

**Aturan keras:**

1. `Resources\Pelanggan\*` **tidak boleh** mewarisi atau memakai ulang Resource internal. Resource internal memuat field seperti `nama_alat_kemampuan`, reviewer, dan status sesi. Satu field baru di resource internal tidak boleh otomatis bocor ke pelanggan.
2. Controller pelanggan tidak menulis query `Equipment::query()` mentah. Semua lewat scope `->milikPerusahaan($konteks)`.
3. Tidak ada `if ($user->role === 'pelanggan')` di controller internal. Pemisahannya di rute dan middleware.

## 3. Autentikasi & otorisasi

### 3.1 Token

- Sanctum tetap dipakai. Token pelanggan dibuat dengan ability `['pelanggan']` (atau `['pelanggan:menunggu']`) dan `expires_at` = sekarang + 90 hari.
- Token internal diberi ability `['internal']` saat login internal. **Migrasi token lama:** token internal yang sudah beredar tidak punya ability ini. Middleware internal menerima token tanpa ability selama masa transisi 30 hari, lalu mewajibkannya. Tanggal cut-off dicatat di `BACA-DULU-BACKEND.md`.
- Idle 30 hari: dicek dari `personal_access_tokens.last_used_at` di middleware `PastikanAplikasi`.
- Konfigurasi global `sanctum.expiration` tetap `null` untuk app internal, supaya teknisi di lapangan tidak tiba-tiba ter-logout di tengah pekerjaan. Masa berlaku token internal diputuskan terpisah.

### 3.2 Gerbang rute (deny by default)

```php
// routes/api.php — grup internal yang sudah ada
Route::middleware(['auth:sanctum', 'aplikasi:internal', 'role:admin,teknisi,viewer'])->group(...);

// routes/api_pelanggan.php
Route::prefix('auth')->group(/* daftar, verifikasi, undangan, masuk, lupa-sandi */);
Route::middleware(['auth:sanctum', 'aplikasi:pelanggan', 'role:pelanggan'])->group(function () {
    Route::get('/saya', ...);                     // boleh untuk token pelanggan:menunggu
    Route::middleware('konteks.perusahaan')->group(/* semua data */);
});
```

Channel realtime: `organisasi.{id}` menolak user ber-role `pelanggan`. Pelanggan tidak memakai Reverb di MVP.

### 3.3 Konteks perusahaan

`KonteksPerusahaan` membaca header `X-Perusahaan-Id`. Kalau header kosong dan user hanya punya satu keanggotaan aktif, keanggotaan itu yang dipakai. Hasilnya objek `{customer_id, member_id, peran}` yang disuntikkan ke container per request. Keanggotaan tidak valid atau nonaktif → 404.

### 3.4 Otorisasi aksi

| Aksi | pic_utama | staf |
|---|---|---|
| Lihat alat, sertifikat, permintaan, pesan | ✓ | ✓ |
| Tambah/ubah alat, buat permintaan, kirim pesan, isi resi | ✓ | ✓ |
| Batalkan permintaan | ✓ | hanya permintaan buatannya |
| Undang / nonaktifkan anggota | ✓ | ✗ |
| Ubah preferensi notifikasi | miliknya sendiri | miliknya sendiri |

### 3.5 Izin lab (K4)

Tambah kolom `users.boleh_otorisasi_sertifikat` (boolean, default `false`). Migrasi mengisi `true` untuk admin yang sudah ada, supaya perilaku sekarang tidak berubah, lalu manajemen mencabut yang tidak berhak. Rute `approve`/`reject` memakai middleware `izin:otorisasi_sertifikat`. `MatriksIzin::PETA` diperbarui supaya app internal bisa menyembunyikan tombolnya.

## 4. Perubahan data (ERD)

Semua migrasi **additive**: tidak ada drop atau rename kolom yang dipakai app internal. Semua bisa di-rollback.

### 4.1 Tabel yang diubah

| Tabel | Perubahan | Alasan |
|---|---|---|
| `users` | nilai role baru `pelanggan`; `status` + `pending_email`, `pending_verifikasi`; kolom `boleh_otorisasi_sertifikat` bool; `telepon` nullable; `jabatan` nullable; `dianonimkan_pada` nullable | Akun pelanggan, K4, hapus akun |
| `customers` | `pic_admin_id` FK users nullable; `maks_anggota` smallint default 50; nilai `sumber` + `pelanggan` — **kolomnya `string`, bukan enum, jadi nol migrasi untuk nilai ini** (dikoreksi 16 Sep 2026) | PIC default, batas anggota |
| `equipments` | `diinput_oleh` enum(`lab`,`pelanggan`) default `lab`; `status_verifikasi_lab` enum(`terverifikasi`,`belum`,`di_luar_ruang_lingkup`) default `terverifikasi`; `catatan_verifikasi_lab` text null; `dikunci_pada` timestamp null | Alat dari pelanggan, penguncian identitas |
| `device_tokens` | `aplikasi` enum(`internal`,`pelanggan`) default `internal` | Push hanya ke aplikasi yang benar |
| `certificates` | `digantikan_oleh` FK certificates nullable (kalau belum bisa diturunkan dari `revision_of`) | Tampilan revisi |

Catatan `users.status` & `role`: cek dulu apakah kolomnya enum di MySQL. Kalau enum, menambah nilai butuh `ALTER`, dan migrasinya harus diuji di suite MySQL (bukan hanya SQLite, yang tidak menegakkan enum).

### 4.2 Tabel baru

**`customer_members`** — id · organization_id · customer_id FK · user_id FK · peran enum(`pic_utama`,`staf`) · status enum(`aktif`,`nonaktif`) · diundang_oleh FK users null · dinonaktifkan_oleh FK null · dinonaktifkan_pada null · timestamps · UNIQUE(customer_id, user_id) · INDEX(user_id, status)

**`undangan_pelanggan`** — id · organization_id · customer_id · email · kode_hash · peran · dibuat_oleh · kedaluwarsa_pada · dipakai_pada null · dipakai_oleh null · dibatalkan_pada null · timestamps · INDEX(email)

**`pengajuan_akun_pelanggan`** — id · organization_id · user_id · nama_perusahaan · alamat_perusahaan · jabatan · status enum(`menunggu`,`disetujui`,`ditolak`) · customer_id null (terisi saat disetujui) · diputus_oleh null · diputus_pada null · alasan_tolak null · timestamps

**`permintaan_kalibrasi`** — id · ulid (dipakai di URL pelanggan) · organization_id · customer_id · nomor UNIQUE · dibuat_oleh (user) · client_request_id UNIQUE(customer_id, client_request_id) · metode enum(`onsite`,`kirim_ke_lab`) · status (lihat §5) · alamat_onsite null · kontak_onsite_nama null · kontak_onsite_telepon null · tanggal_diinginkan_mulai null · tanggal_diinginkan_selesai null · jadwal_onsite_mulai datetime null · zona_waktu_lokasi default `Asia/Jakarta` · kurir_kirim null · resi_kirim null · kurir_balik null · resi_balik null · ditangani_oleh null · diambil_pada null · order_id null · alasan_tolak null · alasan_batal null · dibatalkan_oleh null · catatan_pelanggan text null · selesai_pada null · timestamps · softDeletes · INDEX(organization_id, status, ditangani_oleh) · INDEX(customer_id, status)

**`permintaan_kalibrasi_items`** — id · permintaan_id · equipment_id · hasil_tinjauan enum(`menunggu`,`diterima`,`di_luar_ruang_lingkup`,`ditolak`) · catatan_tinjauan null · order_item_id null · hasil_akhir enum(`belum`,`sertifikat_terbit`,`tidak_dapat_dikalibrasi`) · certificate_id null · catatan_pelanggan null · timestamps · UNIQUE(permintaan_id, equipment_id)

**`permintaan_kalibrasi_riwayat`** — id · permintaan_id · dari_status null · ke_status · oleh_user_id null (null = sistem) · catatan null · created_at

**`pesan_permintaan`** — id · permintaan_id · pengirim_user_id · sisi enum(`pelanggan`,`lab`) · isi text · lampiran json null · created_at · INDEX(permintaan_id, created_at)

**`pesan_permintaan_dibaca`** — permintaan_id · user_id · terakhir_dibaca_pada · PRIMARY(permintaan_id, user_id)

**`pengingat_pelanggan_terkirim`** — id · equipment_id · tanggal_jatuh_tempo date · titik enum(`h30`,`h7`,`h1`,`h0`,`p7`,`p30`) · dikirim_pada · UNIQUE(equipment_id, tanggal_jatuh_tempo, titik)

**`preferensi_notifikasi_anggota`** — member_id PK · jadwal bool · status_permintaan bool · pesan bool · email_ringkasan bool · updated_at

**`persetujuan_dokumen`** — id · user_id · jenis enum(`kebijakan_privasi`,`syarat_ketentuan`) · versi · disetujui_pada · ip null

**`otp_pelanggan`** — id · user_id · tujuan enum(`verifikasi_email`,`atur_ulang_sandi`) · kode_hash · kedaluwarsa_pada · percobaan tinyint default 0 · dikunci_sampai null · dipakai_pada null · timestamps · INDEX(user_id, tujuan)

> **Ditambahkan 16 Sep 2026; tidak ada di versi 0.1 dokumen ini.** REQ-AUTH-01/02
> dan REQ-AUTH-10 menuntut OTP 6 digit berumur 10 menit, sekali pakai, dan
> terkunci 15 menit sesudah 5 percobaan salah — tapi tidak ada tabel yang bisa
> menyimpannya. Tiga hal yang menentukan bentuknya:
>
> - **`kode_hash`, bukan kodenya.** OTP itu kredensial berumur pendek. Tabel yang
>   menyimpannya polos berarti siapa pun yang bisa membaca database bisa
>   mengambil alih akun mana pun tanpa menyentuh email korban.
> - **`percobaan` + `dikunci_sampai` ada di baris OTP-nya**, bukan cuma di rate
>   limiter per IP. Bedanya menentukan: throttle per IP dilewati dengan ganti
>   jaringan, penguncian per akun tidak.
> - **Model `OtpPelanggan` sengaja TANPA trait `Diaudit`.** `audit_logs` menyimpan
>   nilai lama & baru tiap kolom, jadi mengauditnya berarti hash OTP ikut
>   tersalin ke tabel kedua — dan REQ-PRV-02 melarang log server menyimpan OTP.

## 5. State machine permintaan

```
                 ┌─────────── dibatalkan (pelanggan/admin) ◄──────────┐
                 │                                                      │
diajukan ──ambil──► ditinjau ──konfirmasi──► dikonfirmasi               │
    │                  │                        │                       │
    └──────tolak───────┴──► ditolak             ├─onsite──► terjadwal ──┤
                                                │              │        │
                                                │         (sesi dibuat) │
                                                └─kirim──► menunggu_alat┤
                                                               │        │
                                                        alat_diterima   │
                                                               │        │
                                            dikerjakan ◄───────┘        │
                                                │                       │
                         semua item beres ──────┤                       │
                              onsite ──► selesai                         │
                     kirim_ke_lab ──► menunggu_pengiriman_balik          │
                                           │ (resi balik)                │
                                      dikirim_balik ──► selesai          │
```

| Dari | Ke | Oleh | Syarat |
|---|---|---|---|
| diajukan | ditinjau | admin (ambil) | atomik, `ditangani_oleh IS NULL` |
| diajukan, ditinjau | ditolak | admin | alasan wajib |
| ditinjau | dikonfirmasi | admin penangan | ≥ 1 item diterima → buat Order + OrderItem |
| dikonfirmasi | terjadwal | admin | metode onsite, jadwal + teknisi diisi |
| dikonfirmasi | menunggu_alat | sistem | otomatis untuk kirim_ke_lab |
| menunggu_alat | alat_diterima | admin | kondisi per item wajib |
| terjadwal, alat_diterima | dikerjakan | sistem | sesi kalibrasi pertama dibuat untuk item mana pun |
| dikerjakan | selesai | sistem | onsite, semua item diterima punya hasil_akhir ≠ belum |
| dikerjakan | menunggu_pengiriman_balik | sistem | kirim_ke_lab, syarat sama |
| menunggu_pengiriman_balik | dikirim_balik | admin | kurir + resi balik |
| dikirim_balik | selesai | pelanggan / sistem | konfirmasi terima atau 14 hari |
| diajukan, ditinjau, dikonfirmasi, menunggu_alat | dibatalkan | pelanggan | REQ-PMT-08 |
| terjadwal | dibatalkan | pelanggan | jadwal ≥ 2 hari lagi |
| semua non-final | dibatalkan | admin | alasan wajib |

Semua transisi lewat `AlurPermintaan::pindah($permintaan, $ke, $aktor, $catatan)`. Service ini memvalidasi tabel, menulis riwayat, mengirim notifikasi lewat event, dan berjalan di dalam transaksi dengan `lockForUpdate()`. Controller tidak boleh mengubah kolom `status` langsung.

Pemicu otomatis (`dikerjakan`, `selesai`, `menunggu_pengiriman_balik`) dipasang di tempat sesi dibuat dan di akhir `GenerateCertificate`, lewat event `SesiKalibrasiDibuat` dan `SertifikatTerbit`. Keduanya dipanggil **setelah commit** (`afterCommit`), supaya permintaan tidak maju karena transaksi sesi yang ternyata rollback.

Pemetaan status ke label pelanggan ada di 04-UI-UX §5.

## 6. Sinkron jadwal alat (perbaikan U3)

`SinkronJadwalAlat::untuk(Equipment $alat)`:

1. Ambil sertifikat aktif terakhir alat: `status = terbit`, belum digantikan, dan `diterbitkan_pada` terbaru.
2. Set `tanggal_kalibrasi_terakhir` = tanggal kalibrasi sesi sertifikat itu, `tanggal_jatuh_tempo` = `berlaku_sampai` sertifikat itu, dan `dikunci_pada` kalau masih null.
3. Kalau tidak ada sertifikat aktif, jangan menimpa tanggal yang diisi manual/impor (alat lama dari Excel).

Dipanggil dari: akhir `GenerateCertificate` saat status menjadi `terbit` (di transaksi yang sama), alur revisi sertifikat, dan perintah sekali jalan `php artisan alat:sinkron-jadwal --dry-run` untuk membetulkan data yang sudah ada. Output `--dry-run` wajib ditinjau manusia sebelum dijalankan tanpa flag.

Test minimal: approve dengan `berlaku_sampai` kustom → alat ikut. Approve tanpa `berlaku_sampai` → default masa berlaku organisasi. Revisi dengan tanggal baru → alat ikut revisi. Sertifikat gagal generate → alat tidak berubah. Alat impor tanpa sertifikat → tanggal manual tetap.

## 7. Spesifikasi API `/api/pelanggan/v1`

Konvensi mengikuti `docs/kontrak-api.md` §0: `{data}`, `{data, meta}`, 422 bawaan Laravel, dan setiap error punya `message` yang layak ditampilkan. Tambahan untuk pelanggan: setiap error non-422 punya `kode` stabil (misal `akun_belum_diverifikasi`, `transisi_tidak_valid`, `field_terkunci`, `versi_aplikasi_usang`), karena aplikasi yang sudah terpasang di ribuan HP tidak boleh bergantung pada isi teks pesan.

Header wajib dari aplikasi: `Authorization`, `Accept: application/json`, `X-App-Versi: 1.0.0+12`, `X-Request-Id: <uuid>`, dan `X-Perusahaan-Id` kalau anggota > 1 perusahaan.

### 7.1 Publik

| Metode | Path | Keterangan |
|---|---|---|
| GET | `/app/status` | `{versi_minimum, versi_terbaru, maintenance: bool, pesan_maintenance}`. Dibaca dari config, tanpa DB. |
| POST | `/auth/daftar` | REQ-AUTH-01 |
| POST | `/auth/verifikasi-email` | `{email, otp}` |
| POST | `/auth/kirim-ulang-otp` | Throttle ketat |
| POST | `/auth/terima-undangan` | `{email, kode, nama, sandi, telepon}` |
| POST | `/auth/masuk` | `{email, sandi, nama_perangkat}` → `{token, kedaluwarsa_pada, user, keanggotaan[]}` |
| POST | `/auth/lupa-sandi`, `/auth/atur-ulang-sandi` | OTP ke email |

### 7.2 Butuh token

| Metode | Path | Keterangan |
|---|---|---|
| GET | `/saya` | Profil + status akun + daftar keanggotaan |
| PATCH | `/saya` | Nama, telepon, jabatan |
| POST | `/saya/ganti-sandi` | Mencabut token lain |
| DELETE | `/saya` | REQ-AUTH-11, body `{sandi}` |
| POST | `/auth/keluar`, `/auth/keluar-semua` | |
| POST / DELETE | `/perangkat` | Token FCM, `aplikasi=pelanggan` diisi server |
| GET | `/beranda` | Ringkasan hitungan status alat + permintaan aktif |
| GET | `/alat` | `?cari=&status=&page=` |
| POST | `/alat` | REQ-ALT-02, respons menyertakan `kembar_dengan[]` kalau ada |
| GET / PATCH | `/alat/{id}` | REQ-ALT-04 |
| POST / DELETE | `/alat/{id}/foto`, `/alat/{id}/foto/{fotoId}` | multipart |
| GET | `/alat/{id}/sertifikat` | Riwayat |
| GET | `/sertifikat/{id}` | Detail tampilan pelanggan |
| POST | `/sertifikat/{id}/tautan-unduh` | → `{url, kedaluwarsa_pada}` |
| GET / POST | `/permintaan` | List (`?status=aktif|selesai`) / buat |
| GET | `/permintaan/{ulid}` | Detail + items + riwayat |
| POST | `/permintaan/{ulid}/batal` | `{alasan}` |
| POST | `/permintaan/{ulid}/pengiriman` | `{kurir, resi}` |
| POST | `/permintaan/{ulid}/alat-sudah-kembali` | `dikirim_balik` → `selesai` |
| GET / POST | `/permintaan/{ulid}/pesan` | Paginasi berbasis kursor |
| POST | `/permintaan/{ulid}/pesan/dibaca` | |
| GET | `/notifikasi`, `/notifikasi/jumlah-belum-dibaca` | |
| POST | `/notifikasi/{id}/dibaca`, `/notifikasi/dibaca-semua` | |
| GET / PUT | `/preferensi-notifikasi` | |
| GET | `/anggota` | Semua peran |
| POST | `/anggota/undangan` | pic_utama |
| DELETE | `/anggota/undangan/{id}` | pic_utama |
| POST | `/anggota/{id}/nonaktifkan` | pic_utama |

### 7.3 Endpoint lab (app internal, `role:admin`)

| Metode | Path | Keterangan |
|---|---|---|
| GET | `/api/admin/pengajuan-akun` | Beserta saran pelanggan mirip (`nama_normal`) |
| POST | `/api/admin/pengajuan-akun/{id}/setujui` | `{customer_id}` atau `{pelanggan_baru:{...}}` |
| POST | `/api/admin/pengajuan-akun/{id}/tolak` | `{alasan}` |
| POST | `/api/customers/{id}/undangan` | `{email, peran}` |
| PATCH | `/api/customers/{id}/pic-admin` | `{user_id}` |
| GET | `/api/admin/permintaan` | `?tampilan=belum_diambil|saya|aktif|arsip` |
| GET | `/api/admin/permintaan/{id}` | |
| POST | `/api/admin/permintaan/{id}/ambil` | 409 kalau keduluan |
| POST | `/api/admin/permintaan/{id}/alihkan` | `{user_id, catatan}` |
| POST | `/api/admin/permintaan/{id}/konfirmasi` | `{items:[{id, hasil_tinjauan, catatan}], tanggal_janji_selesai}` |
| POST | `/api/admin/permintaan/{id}/tolak` | `{alasan}` |
| POST | `/api/admin/permintaan/{id}/jadwal` | `{mulai, teknisi_ids[]}` |
| POST | `/api/admin/permintaan/{id}/alat-diterima` | `{items:[{id, kondisi, kelengkapan, foto[]}]}` |
| POST | `/api/admin/permintaan/{id}/kirim-balik` | `{kurir, resi}` |
| POST | `/api/admin/permintaan/{id}/batal` | `{alasan}` |
| GET / POST | `/api/admin/permintaan/{id}/pesan` | |
| POST | `/api/equipments/{id}/verifikasi-lab` | `{status_verifikasi_lab, nama_alat_kemampuan, equipment_category_id, catatan}` |

### 7.4 Contoh respons

`GET /api/pelanggan/v1/alat/412`

```json
{
  "data": {
    "id": 412,
    "nama_alat": "Timbangan Analitik",
    "merk": "Ohaus", "model": "PX224", "serial_number": "B123456789",
    "no_identifikasi": "QA-TA-07",
    "range": { "min": 0, "max": 220, "satuan": "g" },
    "resolusi": 0.0001,
    "lokasi": "Lab QC Gedung B",
    "status_kalibrasi": "mendekati_jadwal",
    "tanggal_kalibrasi_terakhir": "2026-04-12",
    "jadwal_kalibrasi_ulang": "2026-10-12",
    "sisa_hari": 26,
    "verifikasi_lab": { "status": "terverifikasi", "catatan": null },
    "identitas_terkunci": true,
    "permintaan_aktif": null,
    "foto": [ { "id": 9, "url_thumbnail": "https://…signed…" } ],
    "sertifikat_terakhir": { "id": 3301, "nomor": "CAL/2026/04/0187", "keputusan": "PASS" }
  }
}
```

`POST /api/admin/permintaan/88/ambil` kalau keduluan admin lain:

```json
{ "kode": "sudah_diambil", "message": "Permintaan ini sudah ditangani Sari sejak 09.14.", "data": { "ditangani_oleh": { "id": 7, "nama": "Sari" } } }
```

## 8. Notifikasi & pengingat

### 8.1 Pengiriman

- Satu Firebase project **per lingkungan** (staging, produksi). Di tiap project terdaftar dua aplikasi Android (internal & pelanggan). `FcmPengirimPush` yang sudah ada tetap dipakai dengan satu service account per lingkungan.
- `PenerimaNotifikasi` ditambah `anggotaAktifPerusahaan(customerId, jenisPreferensi)`. Push ke pelanggan hanya memakai `device_tokens.aplikasi = pelanggan`.
- Setiap notifikasi = baris `notifications` (database channel, sudah ada) + push. Push membawa `data.tautan` (misal `sidikpelanggan://alat/412`) untuk deep link.
- Channel Android: `jadwal`, `permintaan`, `pesan`. Prioritas tinggi hanya untuk jadwal onsite H-1 dan pesan.

### 8.2 Pengingat jadwal — `PengingatPelanggan`

Dijadwalkan `Schedule::command('pelanggan:kirim-pengingat')->dailyAt('07:00')->timezone('Asia/Jakarta')->withoutOverlapping()->onOneServer()`.

**Jangan** mengganti `APP_TIMEZONE` global dari UTC ke Asia/Jakarta di sistem yang sudah berjalan, karena timestamp lama akan terbaca bergeser 7 jam. Cukup pakai timezone di scheduler dan `now('Asia/Jakarta')->toDateString()` untuk perbandingan tanggal. Scheduler yang sudah ada (`alat:cek-jatuh-tempo`, `standar:cek-kadaluarsa`) juga diberi `->timezone('Asia/Jakarta')`.

```
hari_ini = tanggal Asia/Jakarta
untuk tiap alat: status aktif, customer punya anggota aktif, tanggal_jatuh_tempo not null,
                 bukan dalam_proses (REQ-NTF-05)
  selisih = tanggal_jatuh_tempo - hari_ini (hari)
  titik_jatuh = titik terakhir yang sudah tercapai dari [h30:30, h7:7, h1:1, h0:0, p7:-7, p30:-30]
                 (titik tercapai jika selisih <= nilai titik)
  jika titik_jatuh ada
     dan belum ada baris (alat, tanggal_jatuh_tempo, titik_jatuh) di pengingat_pelanggan_terkirim
     dan tidak ada titik yang lebih "lanjut" sudah terkirim untuk tanggal yang sama
  → kumpulkan ke bucket[customer_id][titik_jatuh]
tulis semua baris pengingat_pelanggan_terkirim (INSERT IGNORE / upsert) DULU dalam transaksi,
lalu kirim notifikasi gabungan per anggota (queue)
```

Menulis tanda terkirim sebelum push memilih risiko "satu pengingat tidak sampai" dibanding "pengingat terkirim berulang tiap hari". Kegagalan push tetap aman karena notifikasi database tetap ada (REQ-NTF-06) dan tercatat di log.

**Kalau server tidur atau mati di jam 07:00:** algoritma di atas berbasis "titik yang sudah tercapai", bukan "tepat hari ini". Jadi saat perintah akhirnya jalan (misal dipicu pinger atau jalan jam 09:00), titik yang terlewat tetap terkirim sekali.

## 9. Berkas & media

- Disk `arsip` wajib driver S3/R2 di staging dan produksi. Aplikasi menolak start fitur upload pelanggan kalau `arsip.awet` false (feature flag dimatikan otomatis dengan log peringatan).
- Path: `pelanggan/{customer_id}/alat/{equipment_id}/{ulid}.jpg` dan `pelanggan/{customer_id}/permintaan/{ulid}/{ulid}.{ext}`. Bucket privat.
- `PembersihFoto`: validasi MIME dari isi file (bukan ekstensi), decode → buang EXIF → resize sisi terpanjang 2.000 px → encode JPEG kualitas 85. Tolak kalau decode gagal.
- URL unduh: `temporaryUrl()` 5 menit, dibuat hanya setelah cek kepemilikan.

## 10. Lingkungan & konfigurasi

| Variabel / flag | Lokal | Staging (gratis) | Produksi |
|---|---|---|---|
| `APP_ENV` | local | staging | production |
| `FITUR_PELANGGAN` | true | true | **false** sampai rilis M7, lalu true |
| `PELANGGAN_VERSI_MINIMUM` | 0.0.0 | naik tiap perubahan merusak | naik tiap perubahan merusak |
| `PELANGGAN_MAINTENANCE` | false | false | true saat maintenance |
| `ARSIP_DRIVER` | local | s3 (bucket staging) | s3 (bucket produksi) |
| `MAIL_MAILER` | log | smtp (layanan email transaksional paket gratis) | smtp (paket berbayar/terverifikasi domain) |
| `BROADCAST_CONNECTION` | log | log | log (pelanggan tidak butuh Reverb) |
| Firebase project | staging | `sidik-staging` | `sidik-produksi` |
| `CRON_PEMICU_TOKEN` | — | diisi (lihat 07-Runbook §2) | kosong (cron asli di VPS) |
| DB | lokal | DB staging terpisah, **data dummy** | DB produksi |

Kalau `FITUR_PELANGGAN=false`, seluruh `routes/api_pelanggan.php` membalas 503 `{kode: "belum_tersedia"}`, scheduler pelanggan tidak jalan, dan menu inbox pelanggan di app internal disembunyikan (dibaca dari `/me/permissions`).

## 11. Observabilitas

- `X-Request-Id` dari aplikasi diteruskan ke log (`Log::withContext`). Kalau kosong, server membuatnya dan mengembalikannya di header respons.
- Crashlytics di aplikasi pelanggan, dengan user ID berupa hash (bukan email).
- Log terstruktur untuk: login gagal, 404 lintas perusahaan (indikasi percobaan IDOR, dihitung per user), transisi permintaan, pengiriman push (jumlah sukses/gagal), dan run pengingat.
- `/api/health` ditambah blok `pelanggan: {fitur_nyala, jadwal_pengingat_terakhir, antrean_tertunda}`. Status saja, bukan data.
- Alarm minimal: run pengingat tidak tercatat > 26 jam, antrean > 500 job > 30 menit, lonjakan 404 lintas perusahaan dari satu user (> 20/jam).

## 12. Strategi test

| Lapisan | Apa | Contoh wajib |
|---|---|---|
| Unit (backend) | `AlurPermintaan`, `StatusAlatPelanggan`, `PengingatPelanggan`, `SinkronJadwalAlat`, `KonteksPerusahaan` | Semua transisi valid ✓, semua transisi di luar tabel ✗; titik pengingat saat server "tidur" 3 hari; tanggal jatuh tempo diubah mundur/maju |
| Feature (backend) | Semua endpoint `/pelanggan/v1` dan `/admin/permintaan` | Status code, bentuk JSON, throttle, notifikasi terkirim (`Notification::fake`) |
| Keamanan (backend) | `IsolasiPerusahaanTest` dengan data provider: **setiap** rute ber-ID dicoba dengan ID milik perusahaan lain → 404 | Otomatis membaca daftar rute, sehingga rute baru tanpa test membuat test gagal |
| Keamanan (backend) | `RuteInternalMenolakPelangganTest`: loop semua rute `api/*` non-pelanggan dengan token pelanggan → 401/403 | Mirip pendekatan `MatriksIzin` |
| Konkurensi | Dua request `ambil` paralel (MySQL suite) | Hanya satu sukses |
| Kontrak | JSON fixture respons disimpan di repo backend dan disalin ke repo aplikasi; test aplikasi mem-parse fixture yang sama | Perubahan nama field memecah test di dua sisi |
| Widget (app) | Layar utama dengan state loading/kosong/error/offline | Golden test untuk beranda, detail alat, timeline permintaan |
| Integrasi (app) | Alur daftar → masuk → tambah alat → ajukan, melawan backend staging | Jalan di CI malam hari, bukan tiap PR |
| Manual / UAT | Skenario 07-Runbook §5 di HP asli (Xiaomi, Samsung, Oppo/Vivo) | Termasuk izin notifikasi ditolak & mode hemat baterai |

## 13. Yang perlu ditinjau ulang nanti

- Kalau duplikasi kode antara dua repo aplikasi terus bertambah: ekstrak paket `sidik_ui_kit` (ADR-001).
- Kalau jumlah pelanggan > ±300 perusahaan aktif: pindahkan pengingat ke chunk + queue per perusahaan, dan pertimbangkan Redis untuk queue/cache.
- Kalau pelanggan meminta SSO atau 2FA: tambahkan di atas Sanctum, tanpa mengubah kontrak v1.
- Kalau iOS/web jadi prioritas (K8): kontrak API sudah netral platform, yang perlu ditambah hanya klien.

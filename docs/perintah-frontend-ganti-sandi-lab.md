# Perintah Frontend — Ganti Sandi Sendiri (aplikasi lab) + izin `audit.lihat`

**Berkas ini lengkap dan berdiri sendiri.** Tempel seluruh isi di bawah garis
ke sesi kerja frontend aplikasi lab (`sidik-calibration-mobile`) — tidak perlu
membuka dokumen lain.

| | |
|---|---|
| Repo API | `sidik-calibration-api`, branch `feat/pelanggan-lanjut-20261112`, dasar `origin/main` `006f1b68` (belum di-commit saat berkas ini ditulis; periksa hash di PR-nya) |
| Rute | `POST /api/me/ganti-sandi` (BARU) · izin `audit.lihat` di `GET /api/me/permissions` (BARU) |
| Status backend | Selesai & hijau di SQLite (22 test `GantiSandiLabTest`, 28 test `MeIzinTest` dkk). **Gerbang MySQL penuh belum dijalankan** — jangan anggap terverifikasi di produksi sebelum itu |
| Kontrak penuh | `docs/kontrak-api.md` → `POST /api/me/ganti-sandi` dan `GET /api/me/permissions` |

---

Bertindak sebagai Frontend Engineer untuk aplikasi kalibrasi PT Sidik (aplikasi lab, Flutter).

Dua hal baru dari server, keduanya **additive** — aplikasi yang sudah terpasang tidak berubah perilakunya:

1. Pengguna lab kini bisa **mengganti sandinya sendiri** dari layar Profil. Sebelumnya satu-satunya jalan adalah
   admin menekan "reset sandi" (sandi barunya diketik orang lain) atau lupa-sandi lewat email. Untuk `super_admin`
   keduanya buntu, jadi layar ini terutama untuk dia.
2. Izin tersendiri **`audit.lihat`** untuk layar riwayat perubahan data (`GET /api/audit-logs`).

## 0. Aturan paling penting

1. Frontend TIDAK menilai sandi: panjang minimal, "pernah bocor", dan "sama dengan yang lama" dinilai server.
   Boleh menampilkan petunjuk "minimal 10 karakter" dan mencocokkan kolom konfirmasi di layar untuk kenyamanan,
   tetapi **yang menentukan jawaban server** (`422`). Tampilkan `errors.<field>[0]` apa adanya.
2. Bercabang pada **`kode`**, bukan pada isi `message`: `sandi_lama_salah` (422). Kalimatnya boleh berubah.
3. **Jangan menyimpan atau mencatat sandi** (state yang bertahan, log, analytics, crash report). Kosongkan ketiga
   kolom begitu request selesai, sukses maupun gagal.
4. **Jangan menggerbangi layar ini dengan izin di `/me/permissions`.** Tidak ada nama izin untuknya: semua peran
   internal yang sudah masuk — `admin`, `teknisi`, `viewer`, **dan `super_admin`** — berhak atas sandinya sendiri.
   Jangan menyembunyikannya untuk `viewer`.
5. Kalau ambigu, berhenti dan tanya — jangan menambal dengan asumsi.

## 1. `POST /api/me/ganti-sandi`

Header: `Authorization: Bearer <token aplikasi lab>` (token dari `POST /api/login`).

```json
{
  "sandi_lama": "…",
  "sandi": "…",
  "sandi_confirmation": "…"
}
```

| Field | Wajib | Aturan (ditegakkan server) |
|---|---|---|
| `sandi_lama` | ya | harus cocok dengan sandi sekarang |
| `sandi` | ya | minimal **10 karakter**; bukan sandi yang pernah bocor (cek k-anonymity ke Have I Been Pwned — bila layanan itu tidak terjangkau, server tidak memblokir); **tidak sama** dengan `sandi_lama`; sama dengan `sandi_confirmation` |
| `sandi_confirmation` | ya | harus sama dengan `sandi` |

Nama field memakai underscore persis seperti di atas (`sandi_confirmation`, bukan `konfirmasi_sandi`).

### Sukses — `200`

```json
{ "message": "Sandi berhasil diganti.", "data": { "sesi_dicabut": 2 } }
```

- `sesi_dicabut` = jumlah token **lain** akun ini yang dihapus server (HP lain, dan Sidik Studio kalau akunnya
  `STUDIO_AKUN`). **Token yang sedang dipakai dipertahankan** — **jangan** memanggil logout dan **jangan** melempar
  pengguna ke layar masuk. Cukup tampilkan "Sandi berhasil diganti" dan, kalau `sesi_dicabut > 0`, satu kalimat
  "N perangkat lain telah dikeluarkan".
- Perangkat lain akan menerima `401` pada request berikutnya dan harus kembali ke layar masuk — itu perilaku
  aplikasi yang sudah ada untuk token tidak valid, tidak ada yang baru.
- Peran, status, dan data akun lain tidak berubah. Tidak perlu memanggil ulang `/me` atau `/me/permissions`.

### Gagal

Contoh badan di bawah adalah keluaran nyata dari server (test `GantiSandiLabTest`).

| Status | Kapan | Badan | Yang dilakukan aplikasi |
|---|---|---|---|
| `422` | `sandi_lama` salah | `{"kode":"sandi_lama_salah","message":"Sandi lama tidak cocok."}` | Pesan di bawah kolom **sandi lama**; kosongkan kolom itu |
| `422` | sandi baru < 10 karakter | `{"message":"Sandi baru minimal 10 karakter.","errors":{"sandi":["Sandi baru minimal 10 karakter."]}}` | `errors.sandi[0]` di bawah kolom sandi baru |
| `422` | konfirmasi tidak cocok | `{"message":"Konfirmasi sandi baru tidak cocok.","errors":{"sandi":["Konfirmasi sandi baru tidak cocok."]}}` | idem (kunci galatnya `sandi`, bukan `sandi_confirmation`) |
| `422` | sandi baru = sandi lama | `{"message":"Sandi baru tidak boleh sama dengan sandi lama.","errors":{"sandi":["Sandi baru tidak boleh sama dengan sandi lama."]}}` | idem |
| `422` | sandi baru pernah bocor | `errors.sandi[0]` = `"Sandi ini pernah muncul di kebocoran data. Pilih sandi lain."` | idem |
| `422` | ada yang kosong | `{"message":"Sandi lama wajib diisi. (and 1 more error)","errors":{"sandi_lama":["Sandi lama wajib diisi."],"sandi":["Sandi baru wajib diisi."]}}` | tiap `errors.<field>[0]` di kolomnya; **jangan** menampilkan `message` (ia memuat "(and 1 more error)" bawaan Laravel) |
| `401` | tanpa token / token tidak valid / akun nonaktif | `{"message":"Unauthenticated."}` (akun nonaktif: `{"kode":"akun_nonaktif",…}`) | layar masuk |
| `403` | token bukan token aplikasi lab (token Sidik Studio atau token aplikasi Pelanggan) | `{"kode":"bukan_aplikasi_ini",…}` | tidak untuk dipanggil dari aplikasi itu |
| `429` | lebih dari **5 percobaan per menit per akun** | `{"message":"Kebanyakan percobaan. Tunggu sebentar, terus coba lagi."}` | nonaktifkan tombol sebentar; tampilkan pesan |

Catatan galat validasi: untuk satu kolom bisa ada beberapa pesan; tampilkan yang pertama.
Hanya `sandi_lama_salah` yang punya `kode`; galat validasi Laravel tidak.

## 2. Hal yang perlu diketahui sebelum merancang layar

- **Sandi awal akun dibuat admin** (admin mengetiknya). Belum ada "wajib ganti saat pertama masuk" — itu sengaja
  tidak dibangun. Layar ini yang memberi pengguna jalan menggantinya; boleh menyarankannya (tidak memaksa) di
  Profil.
- **Sidik Studio** (akun `STUDIO_AKUN`, token ber-ability `studio`) **tidak bisa** memanggil rute ini (`403`).
  Kalau pemilik mengganti sandi dari aplikasi lab, sesi Studio-nya ikut dicabut (token lain) — wajar.
- Server menolak menyimpan/mengembalikan sandi apa pun. Jejak audit mencatat bahwa sandi diganti
  (`GET /api/audit-logs`, entri `users`/`diubah`, kolom `password` bernilai `"[disensor]"`), tanpa nilainya.
- Rute ini tidak membatasi peran; `viewer` pun boleh. Tidak ada tombol yang perlu disembunyikan berdasarkan peran.

## 3. Izin baru `audit.lihat`

`GET /api/me/permissions` kini memuat `"audit.lihat"` di `data.boleh` untuk **`admin` dan `super_admin`**, tidak untuk
`teknisi` dan `viewer`. Rutenya sendiri (`GET /api/audit-logs` dan `GET /api/audit-logs/export`) **tidak berubah**:
siapa yang boleh mengaksesnya sama persis dengan sebelumnya, hanya sekarang punya nama izin sendiri.

- Menu/layar "Riwayat Perubahan": tampilkan hanya bila `boleh.contains('audit.lihat')`. Jangan lagi menebaknya
  dari `pengguna.kelola`.
- Jumlah izin sekarang: `super_admin` 64 · `admin` 63 · `teknisi` 28 · `viewer` 19.
- Tambahkan `auditLihat = 'audit.lihat'` ke `NamaIzin` (`lib/models/izin.dart`) **lalu** jalankan ulang generator
  fixture di repo API: `php artisan tinker --execute="require 'docs/skrip/gen-nama-izin-mobile.php';"`
  (menulis `test/fixtures/nama_izin.json`, jangan disunting tangan) agar `test/nama_izin_test.dart` tetap hijau.
  Mengganti isi fixture tanpa menambah `NamaIzin` tidak diperlukan.

## 4. Yang TIDAK berubah

`POST /api/login`, `POST /api/logout`, `POST /api/logout-all`, `GET /api/me`, `POST /api/users/{id}/reset-password`
(reset oleh admin) — tidak disentuh. Reset oleh admin tetap ada untuk kasus lupa total.

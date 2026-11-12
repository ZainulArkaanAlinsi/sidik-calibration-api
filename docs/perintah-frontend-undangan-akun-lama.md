# Perintah Frontend — Terima Undangan untuk Email yang SUDAH Punya Akun (aplikasi Pelanggan)

**Berkas ini lengkap dan berdiri sendiri.** Tempel seluruh isi di bawah garis ke sesi kerja frontend
aplikasi pelanggan (`sidik-pelanggan-mobile`) — tidak perlu membuka dokumen lain.

| | |
|---|---|
| Repo API | `sidik-calibration-api`, branch `fix/undangan-akun-lama-20261112`, menumpuk di atas `feat/pelanggan-lanjut-20261112` (PR #255). Belum di-commit saat berkas ini ditulis; periksa hash di PR-nya |
| Rute | `POST /api/pelanggan/v1/auth/terima-undangan` — **hanya** kasus email yang sudah punya akun pelanggan |
| Sifat perubahan | **Perbaikan keamanan yang disengaja**, satu-satunya perubahan perilaku di `/pelanggan/v1` kali ini. Akun BARU tidak berubah sama sekali |
| Status backend | Selesai & hijau di SQLite (`UndanganAkunLamaTest` + `TerimaUndanganTest` dkk). **Gerbang MySQL penuh belum dijalankan** |
| Kontrak penuh | `docs/kontrak-api-pelanggan.md` §1 `terima-undangan` dan §11 |

---

Bertindak sebagai Frontend Engineer untuk aplikasi pelanggan PT Sidik (Flutter, read-only).

## 0. Kenapa server berubah (satu paragraf)

Dulu, kalau email undangan sudah punya akun pelanggan, server cukup mencocokkan kode lalu langsung
menerbitkan token untuk akun itu — **tanpa sandi**. Admin lab melihat kode polos undangan di balasan
`POST /api/customers/{c}/undangan`, jadi admin lab bisa mengundang email pelanggan mana pun lalu
mengambil alih akunnya. Sekarang server **mewajibkan sandi akun lama** yang benar sebelum token terbit.

## 1. Perilaku server sekarang (email SUDAH punya akun pelanggan)

Server memeriksa berurutan, dan baru membuka pemeriksaan berikutnya bila yang sebelumnya lolos:

| Urutan | Kondisi | Balasan | `kode` |
|---|---|---|---|
| 0 | Kode salah / terpakai / kedaluwarsa / dibatalkan (apa pun akunnya) | 422 | `undangan_tidak_berlaku` — **tidak berubah**, tidak membedakan akun ada atau tidak |
| 1 | Akun itu akun internal PT Sidik | 422 | `undangan_akun_internal` — tidak berubah |
| 2 | Email dikunci (5 kegagalan sandi per 15 menit, **satu ember dengan `masuk`**) | 429 | `terlalu_sering` |
| 3 | `sandi` yang dikirim tidak cocok dengan sandi akun | 422 | **`sandi_akun_lama_salah`** (BARU) |
| 4 | Akun terdaftar di laboratorium lain dari perusahaan pengundang | 422 | **`undangan_organisasi_berbeda`** (BARU) |
| 5 | Akun `pending_email` | 403 | `email_belum_diverifikasi` (kode yang sama dengan `masuk`; `message` di jalur ini menyuruh menghubungi PT Sidik) |
| 6 | Akun `nonaktif` atau sudah dihapus/dianonimkan | 403 | `akun_nonaktif` (kode yang sama dengan `masuk`) |
| – | Semua lolos | 201 | `data.akun_sudah_ada = true`, token terbit seperti `masuk` |

Bentuk galat tetap `{"kode": "...", "message": "..."}` (tanpa `errors`).

Yang tidak berubah: akun **baru** (email belum punya akun) — `sandi` di formulir adalah sandi baru yang
disimpan, balasan 201 dengan `akun_sudah_ada = false`. Validasi field (`sandi` min 10 karakter dan tidak
boleh sandi bocor, `telepon`, `setuju_syarat`) tetap dijalankan **lebih dulu untuk semua orang** dan tetap
422 berbentuk `errors.<field>`.

Setiap penukaran sukses untuk akun lama kini mencatat jejak audit dan mengirim **notifikasi netral** ke
pemilik akun (kategori `pelanggan.akun_ditautkan`, judul "Akun Anda ditautkan ke perusahaan lain", tanpa
`tautan`). Aplikasi tidak wajib berbuat apa pun untuk itu: baris itu muncul di `GET /notifikasi` dan
lewat push seperti notifikasi lain; `tautan` kosong berarti ketukan hanya membuka kotak masuk.

## 2. Dampak pada aplikasi 0.2.2 yang sudah terpasang (tanpa perubahan kode)

Aplikasi 0.2.2 **tidak tahu** sebelum mengirim apakah email undangannya sudah punya akun, jadi
formulirnya selalu meminta "sandi baru". Akibatnya:

- Orang dengan email yang SUDAH punya akun, yang mengisi sandi baru karangannya → **422
  `sandi_akun_lama_salah`**. Layar yang ada menampilkan `message` server sebagai galat formulir (kode
  `terima_undangan_screen.dart` memakai `e.pesan` bila `e.isian` kosong), jadi pengguna melihat kalimat
  yang menyuruhnya mengisi sandi akun yang sudah ada, lalu bisa mencoba lagi. **Tidak crash, tidak
  keluar dari layar, kode undangan tetap hidup.**
- Orang yang kebetulan mengisi sandi akun lamanya → 201 seperti biasa; dialog `DialogAkunSudahAda`
  muncul dengan teks lama ("Nama dan sandi yang baru Anda isi **tidak dipakai**") — teks itu sekarang
  **menyesatkan** karena sandi yang diisi justru dipakai (lihat §3.D).
- Lima kali salah dalam 15 menit → 429 `terlalu_sering`; pesan server memuat hitung mundur. Ember yang
  sama dengan `masuk`, jadi login ikut tertahan selama kunci itu hidup.

## 3. Yang perlu diubah di aplikasi (tulis perubahannya di repo aplikasi, bukan di sini)

**A. Teks bantuan kolom sandi di layar "Pakai kode undangan".** Kolom itu sekarang melayani dua kasus.
Ganti label/bantuan menjadi kira-kira: *"Sandi. Sudah punya akun SIDIK Pelanggan dengan email ini?
Isi sandi akun Anda yang sudah ada. Belum punya? Buat sandi baru, minimal 10 karakter."* Jangan
mengubah validasi lokal untuk akun baru (min 10 karakter), tetapi **jangan menahan kiriman** hanya
karena sandi lama pendek: server yang menilai, dan akun lama boleh punya sandi yang tidak lolos aturan
10 karakter. Catatan: validasi `sandi` di server tetap dijalankan sebelum pemeriksaan akun, jadi sandi
lama yang kurang dari 10 karakter atau yang sudah tercatat bocor tetap ditolak dengan galat field
`errors.sandi` — arahkan pengguna itu ke **Lupa sandi?** (`/auth/lupa-sandi`), lalu ulangi undangan.

**B. Tangani kode galat baru di `terimaUndangan`** (bercabang pada `kode`, bukan `message`):

| `kode` | Tampilan |
|---|---|
| `sandi_akun_lama_salah` (422) | Tampilkan `message` di bawah kolom sandi; **jangan** mengosongkan kolom kode atau formulir lain; sediakan tautan "Lupa sandi?" |
| `terlalu_sering` (429) | Hitung mundur dari `message`/header `Retry-After` bila ada; nonaktifkan tombol kirim sementara |
| `undangan_organisasi_berbeda` (422) | Layar kontak PT Sidik; tidak bisa diselesaikan pengguna |
| `akun_nonaktif` (403) | Layar kontak PT Sidik (sama seperti di `masuk`) |
| `email_belum_diverifikasi` (403) | Tampilkan pesan server dan arahkan hubungi PT Sidik. **Jangan** melempar ke layar OTP verifikasi email — `kirim-ulang-otp` sudah dicabut |

**C. `tampakAkunLama` (`lib/services/layanan_pelanggan.dart`) dibuang.** Server sudah mengirim
`data.akun_sudah_ada` (bool, 201) sejak 12 Nov 2026 — baca itu, bukan tebakan dari jumlah keanggotaan
atau perbedaan nama. `SesiBaru.akunSudahAda` diisi dari field itu. Perbarui test yang memakai
heuristik lama.

**D. Teks `DialogAkunSudahAda`.** Ganti ke kira-kira: *"Perusahaan dari undangan ini sudah ditambahkan
ke akun SIDIK Pelanggan Anda yang sudah ada. Sandi Anda tidak berubah. Kalau lupa sandi, keluar lalu
pilih 'Lupa sandi?' di layar Masuk."* Hapus kalimat "Nama dan sandi yang baru Anda isi tidak dipakai".

**E. Rilis.** Perubahan ini boleh menyusul lewat rilis aplikasi biasa (prompt pembaruan dalam
aplikasi). 0.2.2 tetap berfungsi seperti §2.

## 4. Aturan yang tidak boleh dilanggar

1. Bercabang pada `kode`, bukan pada isi `message`; kalimat server boleh berubah.
2. Jangan menyimpan, mencatat, atau menampilkan ulang sandi (state yang bertahan, log, analytics).
3. Aplikasi pelanggan tetap read-only: tidak ada rute baru yang boleh dipanggil dari perubahan ini;
   `POST /auth/terima-undangan` sudah ada di allow-list `test/guard_tulis_test.dart`.
4. Kalau ambigu, berhenti dan tanya — jangan menambal dengan asumsi.

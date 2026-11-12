# Kontrak API — SIDIK Pelanggan (`/api/pelanggan/v1`)

> Versi 0.4 · 16 Sep 2026 · Cakupan: **M1-03 s/d M1-09** (auth, akun, undangan, anggota, hapus akun).
> Endpoint alat, sertifikat, permintaan, dan pesan menyusul di Fase 6.

Dokumen ini buat @ZainulArkaanAlinsi (Flutter, repo `sidik-pelanggan-mobile`).
Isinya endpoint yang SUDAH jalan di server, bukan rencana.

Berbeda dari `docs/kontrak-api.md` (app internal teknisi): **base URL-nya lain,
tokennya lain, dan akunnya lain.** Satu akun tidak bisa dipakai di dua aplikasi —
itu disengaja, lihat §2.

**Kalau ada yang mau diubah, kabarin dulu.** Contoh JSON di bawah bukan diketik
tangan: dia disalin dari `tests/Fixtures/pelanggan/*.json`, yang diadu ulang tiap
kali suite jalan (`KontrakResponsPelangganTest`). Jadi kalau dokumen ini dan
server berbeda, yang salah dokumennya — dan testnya sudah merah duluan.

---

## 0. Aturan umum

**Base URL**: `/api/pelanggan/v1`. Waktu dev dari emulator Android:
`http://10.0.2.2:8000/api/pelanggan/v1`.

**Header yang selalu dikirim**:

```
Authorization: Bearer <token>      (kecuali endpoint §1)
Accept: application/json
X-App-Versi: 1.0.0+12
X-Request-Id: <uuid v4>
```

**Sukses** selalu dibungkus `data`; list menambah `meta` paginasi Laravel.

**Error 422** bentuknya bawaan Laravel (`message` + `errors` per field).

**Error selain 422 SELALU punya `kode`** — dan `kode` itulah yang dipakai
aplikasi buat bercabang, **bukan `message`**:

```json
{ "kode": "email_belum_diverifikasi", "message": "Email Anda belum diverifikasi. …" }
```

Alasannya: aplikasi yang sudah terpasang di HP orang tidak bisa disuruh ikut
berubah hari itu juga. Kalimat Indonesianya boleh diperbaiki kapan saja; `kode`
tidak (NFR-12).

### Daftar `kode` yang sudah dipakai

| `kode` | HTTP | Kapan | Yang harus dilakukan aplikasi |
|---|---|---|---|
| `belum_tersedia` | 503 | `FITUR_PELANGGAN` mati | Layar "belum tersedia", jangan retry otomatis |
| `versi_aplikasi_usang` | 426 | Di bawah `versi_minimum` | Layar wajib update (S21) |
| `terlalu_sering` | 429 | Rate limit | Tampilkan hitung mundur, jangan retry langsung |
| `kredensial_salah` | 401 | Email/sandi salah | "Email atau sandi salah" — jangan bedakan |
| `email_belum_diverifikasi` | 403 | Akun `pending_email` | Lempar ke layar OTP (S05 langkah 3) |
| `akun_nonaktif` | 403 | Nonaktif / dihapus | Layar kontak PT Sidik |
| `bukan_akun_pelanggan` | 403 | Akun lab masuk di sini | "Pakai aplikasi teknisi" |
| `bukan_aplikasi_ini` | 403 | Token dari aplikasi lain | Paksa keluar, hapus token lokal |
| `sesi_berakhir` | 401 | Token nganggur > 30 hari | Ke layar masuk, **draf lokal jangan dihapus** |
| `token_lama` | 401 | Token lama lewat masa transisi | Sama seperti `sesi_berakhir` |
| `otp_salah` | 422 | Kode salah / kedaluwarsa / sudah dipakai | Kosongkan field, izinkan coba lagi |
| `otp_terkunci` | 429 | Salah 5 kali | Hitung mundur 15 menit |
| `sandi_lama_salah` | 422 | Ganti sandi, sandi lama meleset | Tandai field sandi lama |
| `akun_belum_diverifikasi` | 403 | Token `pelanggan:menunggu` menyentuh data | Kembali ke S06 |
| `undangan_tidak_berlaku` | 422 | Kode undangan salah / kedaluwarsa / sudah dipakai | Satu pesan saja — jangan tebak mana penyebabnya |
| `undangan_akun_internal` | 422 | Email undangan ternyata akun internal PT Sidik | "Email ini akun internal, tidak bisa diundang" |
| `batas_anggota` | 422 | Perusahaan sudah penuh (`data.maks_anggota`) | Tampilkan angkanya, arahkan hubungi PT Sidik |
| `pic_utama_terakhir` | 422 | PIC utama terakhir menonaktifkan dirinya | Minta angkat PIC utama lain dulu |
| `sudah_diputus` | 409 | Admin lain mendahului (sisi lab) | Muat ulang antrean |
| `perusahaan_belum_dipilih` | 400 | Anggota > 1 perusahaan, `X-Perusahaan-Id` kosong | Tampilkan pemilih dari `data.pilihan` |
| `bukan_pic_utama` | 403 | Aksi keanggotaan oleh staf | Sembunyikan tombolnya, jangan tampilkan lalu gagal |
| `sudah_nonaktif` | 422 | Anggota sudah nonaktif | Muat ulang daftar |
| `sandi_salah` | 422 | Sandi salah waktu menghapus akun | Tandai field sandi, akun tidak jadi dihapus |

---

## 1. Tanpa token

### `GET /app/status`

Satu-satunya endpoint yang tetap jalan waktu `FITUR_PELANGGAN` mati. Dipanggil
tiap kali aplikasi dibuka dan tiap kali kembali dari background.

```json
{
  "data": {
    "versi_minimum": "0.0.0",
    "versi_terbaru": "0.0.0",
    "maintenance": false,
    "pesan_maintenance": "Aplikasi sedang dalam perbaikan. Silakan coba lagi sebentar lagi."
  }
}
```

### `GET /app/info` — kontak, dokumen hukum, alamat lab (10 Nov 2026, additive)

Dipakai layar Bantuan. **Publik** (tanpa token) dan **di luar gerbang `FITUR_PELANGGAN`** seperti
`/app/status`, supaya layar "belum tersedia"/pemeliharaan tetap bisa menampilkan tombol hubungi lab.
Nol query database; isinya dari env `PELANGGAN_INFO_*` (lewat `config/pelanggan.php`).

Field yang belum diisi **selalu `null`** (bukan `""`, bukan 404, bukan error) dan aplikasi
menyembunyikan tombolnya. Server tidak pernah mengarang nomor atau tautan; `whatsapp_url` tidak
diturunkan dari `whatsapp`. `versi` dokumen ikut `PELANGGAN_VERSI_KEBIJAKAN_PRIVASI` /
`PELANGGAN_VERSI_SYARAT_KETENTUAN` dan hanya dikirim bila `url`-nya terisi (kalau tidak, `null`).
Kondisi default (semua env kosong):

```json
{
  "data": {
    "organisasi": { "nama": null, "alamat": null, "telepon": null },
    "kontak": { "whatsapp": null, "whatsapp_url": null, "email": null, "jam_layanan": null },
    "dokumen": {
      "kebijakan_privasi": { "url": null, "versi": null },
      "syarat_ketentuan": { "url": null, "versi": null },
      "hapus_akun_url": null
    },
    "petunjuk_pengemasan": null
  }
}
```

| Field respons | Env |
|---|---|
| `organisasi.nama` / `.alamat` / `.telepon` | `PELANGGAN_INFO_NAMA_ORGANISASI` / `_ALAMAT_ORGANISASI` / `_TELEPON_ORGANISASI` |
| `kontak.whatsapp` / `.whatsapp_url` | `PELANGGAN_INFO_WHATSAPP` / `_WHATSAPP_URL` |
| `kontak.email` / `.jam_layanan` | `PELANGGAN_INFO_EMAIL` / `_JAM_LAYANAN` |
| `dokumen.kebijakan_privasi.url` / `dokumen.syarat_ketentuan.url` | `PELANGGAN_INFO_KEBIJAKAN_PRIVASI_URL` / `_SYARAT_URL` |
| `dokumen.hapus_akun_url` | `PELANGGAN_INFO_HAPUS_AKUN_URL` |
| `petunjuk_pengemasan` | `PELANGGAN_INFO_PETUNJUK_PENGEMASAN` |

Penjaga: `InfoAplikasiPelangganTest`, `KontrakResponsPelangganTest::test_bentuk_respons_app_info`.

### ~~`POST /auth/daftar`~~ · ~~`/auth/verifikasi-email`~~ · ~~`/auth/kirim-ulang-otp`~~ — DICABUT 18 Sep 2026

Ketiganya menjawab **404**. Pendaftaran mandiri pelanggan tidak ada lagi.

**Satu-satunya cara akun pelanggan lahir: undangan** — `POST /auth/terima-undangan`
di bawah. Kodenya dikirim admin lab atau PIC utama perusahaan itu ke alamat email
calon anggota.

Kenapa dicabut — lengkapnya di `AGENTS.md` §Akun Lahir dari Undangan. Ringkasnya:
yang menjamin seseorang berhak atas data PT X bukan klaim yang dia ketik sendiri
di layar daftar, melainkan bahwa orang yang SUDAH berwenang mengirim kode ke
alamat emailnya.

**Yang aplikasi harus ubah:** buang layar daftar. Layar yang tersisa di alur
masuk cuma **masuk**, **terima undangan**, dan **lupa sandi**.

**OTP tidak ikut mati.** `lupa-sandi` dan `atur-ulang-sandi` masih memakainya,
dengan aturan yang persis sama (berlaku 10 menit, kunci 15 menit sesudah 5
percobaan salah). Yang hilang cuma OTP bertujuan verifikasi email.

Dijaga `PintuDaftarMandiriTertutupTest`.

### `POST /auth/terima-undangan` — REQ-AUTH-06

Throttle: **10 per 15 menit per email**.

Jalur KEDUA masuk ke aplikasi, dan sengaja berbeda dari `daftar`: di sini
**tidak ada OTP dan tidak ada antrean verifikasi**. Yang menjamin orangnya
berhak bukan klaim yang dia ketik, melainkan bahwa seseorang yang sudah
berwenang (PIC utama perusahaan itu, atau admin lab) mengirim kode ke alamat
emailnya.

```json
{
  "email": "budi@contoh.test",
  "kode": "K7M2QP4R",
  "nama": "Budi Diundang",
  "sandi": "Kalibrasi#2026Sidik",
  "telepon": "0812-3456-7890",
  "jabatan": "QA Staff",
  "setuju_syarat": true,
  "nama_perangkat": "Pixel 8a Budi"
}
```

- **Kode 8 simbol**, abjadnya **tanpa `O` `0` `I` `1` `L`** — orang mengetiknya
  ulang dari email. Huruf kecil dan spasi **dirapikan server**, jadi kirim apa
  adanya dari field input.
- Berlaku **7 hari**, sekali pakai, dan hanya cocok untuk email yang diundang.
- Undangan **baru membatalkan yang lama** untuk pasangan email+perusahaan yang
  sama.
- Email yang **sudah punya akun pelanggan** tidak dibuatkan akun kedua — dia
  jadi anggota perusahaan tambahan (kasus konsultan). `nama` dan `sandi` yang
  dikirim **diabaikan** untuk akun yang sudah ada.

Balasan **201**, bentuknya sama dengan `masuk`:

```json
{
  "data": {
    "token": "<token>",
    "kedaluwarsa_pada": "<iso8601>",
    "kemampuan": "pelanggan",
    "user": {
      "id": "<int>",
      "nama": "Budi Diundang",
      "email": "budi@contoh.test",
      "telepon": "+6281234567890",
      "jabatan": "QA Staff",
      "status": "aktif",
      "butuh_verifikasi": false,
      "keanggotaan": [
        {
          "customer_id": "<int>",
          "nama_perusahaan": "PT Contoh Pelanggan",
          "peran": "staf"
        }
      ],
      "pengajuan": null
    }
  }
}
```

Semua kegagalan kode dijawab **satu pesan yang sama** (`undangan_tidak_berlaku`)
— jangan tampilkan tebakan "mungkin sudah kedaluwarsa"; server memang tidak
memberi tahu yang mana.

### `POST /auth/masuk` — REQ-AUTH-03/07/08/10

Throttle: **10 per menit per IP**, DAN **5 kegagalan per email → kunci 15 menit**.

```json
{ "email": "budi@contoh.test", "sandi": "Kalibrasi#2026Sidik", "nama_perangkat": "Pixel 8a Budi" }
```

```json
{
  "data": {
    "token": "<token>",
    "kedaluwarsa_pada": "<iso8601>",
    "kemampuan": "pelanggan",
    "user": {
      "id": "<int>",
      "nama": "Budi Anggota",
      "email": "budi@contoh.test",
      "telepon": "+628123456789",
      "jabatan": "QA Manager",
      "status": "aktif",
      "butuh_verifikasi": false,
      "keanggotaan": [
        {
          "customer_id": "<int>",
          "nama_perusahaan": "PT Contoh Pelanggan",
          "peran": "pic_utama"
        }
      ],
      "pengajuan": null
    }
  }
}
```

- `kemampuan` = `pelanggan` (sudah ditautkan admin) atau `pelanggan:menunggu`
  (masih antre). Aplikasi memakai ini buat memilih beranda vs layar S06.
- `kedaluwarsa_pada` = **90 hari** sejak terbit. Token juga mati kalau **nganggur
  30 hari**. Dua-duanya membalas 401 dengan `kode` sendiri — jangan hapus draf
  lokal waktu itu terjadi.
- Gagal → **401 `kredensial_salah`**, dan pesannya sama persis buat "email tidak
  terdaftar" dan "sandi salah". Jangan bikin kalimat yang membedakan.

```json
{
  "kode": "kredensial_salah",
  "message": "Email atau sandi salah."
}
```

### `POST /auth/lupa-sandi` · `POST /auth/atur-ulang-sandi`

Throttle: `lupa-sandi` **3 per 15 menit per email** (ember kirim),
`atur-ulang-sandi` **10 per 15 menit per email** (ember periksa).

```json
{ "email": "budi@contoh.test" }
```

Selalu **200**, bahkan buat email yang tidak ada. Lalu:

```json
{ "email": "budi@contoh.test", "otp": "483920", "sandi": "SandiBaru#2026Sidik" }
```

Berhasil → **200**, dan **SEMUA sesi di semua perangkat dicabut**. Aplikasi wajib
menghapus token lokalnya dan kembali ke layar masuk.

---

## 2. Butuh token — akun yang masih menunggu verifikasi pun boleh

Token `pelanggan:menunggu` **boleh** menyentuh grup ini. Itu yang bikin layar S06
("menunggu verifikasi", tarik untuk refresh) bisa membaca statusnya sendiri.

### `GET /saya`

```json
{
  "data": {
    "id": "<int>",
    "nama": "Budi Menunggu",
    "email": "budi@contoh.test",
    "telepon": "+628123456789",
    "jabatan": "QA Manager",
    "status": "pending_verifikasi",
    "butuh_verifikasi": true,
    "keanggotaan": [],
    "pengajuan": {
      "status": "menunggu",
      "nama_perusahaan": "PT Klaim Pendaftar",
      "diajukan_pada": "<iso8601>",
      "diputus_pada": null,
      "alasan_tolak": null
    }
  }
}
```

Sesudah admin menolak:

```json
{
  "data": {
    "id": "<int>",
    "nama": "Budi Ditolak",
    "email": "budi@contoh.test",
    "telepon": "+628123456789",
    "jabatan": "QA Manager",
    "status": "pending_verifikasi",
    "butuh_verifikasi": true,
    "keanggotaan": [],
    "pengajuan": {
      "status": "ditolak",
      "nama_perusahaan": "PT Klaim Pendaftar",
      "diajukan_pada": "<iso8601>",
      "diputus_pada": null,
      "alasan_tolak": "Nama perusahaan tidak cocok dengan data kami."
    }
  }
}
```

`butuh_verifikasi` adalah jawaban SERVER buat "boleh buka beranda atau belum".
Jangan hitung sendiri dari `status` — daftar statusnya bisa bertambah.

### `PATCH /saya`

Body boleh sebagian: `nama`, `telepon`, `jabatan`. Field yang tidak dikirim
tidak berubah.

**`email` tidak bisa diganti sendiri** — dikirim pun diabaikan. Ganti email
lewat admin (M1-05).

### `POST /saya/ganti-sandi`

```json
{ "sandi_lama": "…", "sandi": "SandiBaru#2026Sidik" }
```

Berhasil → **200**, `data.sesi_dicabut` berisi jumlah sesi LAIN yang dicabut.
Sesi yang sedang dipakai **tetap hidup**, jadi aplikasi tidak perlu masuk ulang.

### `DELETE /saya` — REQ-AUTH-11

Throttle: **5 per menit per orang**.

```json
{ "sandi": "Kalibrasi#2026Sidik", "konfirmasi": true }
```

`konfirmasi` wajib `true` — konfirmasi KEDUA di luar sandi, karena ini tidak bisa
dibatalkan dan satu tap yang salah di layar HP tidak boleh cukup.

```json
{
  "message": "Akun Anda sudah dihapus.",
  "data": {
    "dihapus": [
      "nama",
      "email",
      "nomor HP",
      "jabatan",
      "seluruh sesi & perangkat"
    ],
    "tetap_tersimpan": [
      "data perusahaan",
      "alat",
      "permintaan kalibrasi",
      "sertifikat"
    ]
  }
}
```

**Layar konfirmasi WAJIB menyebut `tetap_tersimpan` sebelum tombolnya ditekan.**
Ambil kalimatnya dari balasan ini, jangan ditulis ulang di aplikasi — kalau
berbeda dari yang benar-benar dilakukan server, yang dijanjikan ke orangnya
bukan yang dia dapatkan.

Kenapa data itu tetap: perusahaan, alat, permintaan, dan sertifikat adalah
**rekaman laboratorium terakreditasi**. Sertifikat yang sudah terbit harus tetap
bisa ditelusuri ke alat dan sesi kalibrasinya selama masa simpan yang diwajibkan
ISO/IEC 17025. Yang hilang identitas orangnya, bukan jejak pengukurannya.

Boleh dipakai akun yang **masih menunggu verifikasi** juga — orang yang ditolak
tidak boleh terjebak dengan data pribadi yang tidak bisa dia cabut.

PIC utama **terakhir** BOLEH menghapus akunnya (beda dari `nonaktifkan` yang
menolak). Admin lab yang dikabari bahwa perusahaan itu jadi tanpa PIC utama.

Sesudah berhasil: seluruh sesi mati seketika. Aplikasi hapus token lokalnya dan
kembali ke layar sambutan.

### Halaman web hapus akun — REQ-PRV-03

`GET /hapus-akun` di domain yang sama (bukan di bawah `/api`). Wajib dicantumkan
di listing Play Store: Google menuntut URL yang bisa dibuka **tanpa memasang
aplikasinya**.

Halaman itu **tidak ikut mati** waktu `FITUR_PELANGGAN=false`.

### `POST /auth/keluar` · `POST /auth/keluar-semua`

`keluar` mencabut token yang sedang dipakai saja. `keluar-semua` mencabut
semuanya termasuk yang sekarang, dan memulangkan `data.sesi_dicabut`.

---

## 3. Butuh token DAN akun terverifikasi

Semua endpoint di bagian ini juga melewati **`KonteksPerusahaan`**, jadi tiga
aturan berlaku buat semuanya:

- **`X-Perusahaan-Id` menentukan perusahaan aktif.** Anggota SATU perusahaan
  boleh menghilangkannya — server memilihkan. Anggota lebih dari satu WAJIB
  mengirimnya, kalau tidak dapat **400 `perusahaan_belum_dipilih`** beserta
  `data.pilihan` yang siap ditampilkan sebagai pemilih.
- **Header yang bukan miliknya dijawab 404, bukan 403** — termasuk keanggotaan
  yang sudah dinonaktifkan. Jangan tampilkan "akses ditolak"; perlakukan seperti
  perusahaan yang tidak ada dan kembalikan ke pemilih.
- **Perusahaan tidak pernah dikirim di body atau di URL.** Server mengambilnya
  dari header saja.

### `GET /anggota` — REQ-ANG-03

Semua peran boleh melihat.

```json
{
  "data": {
    "anggota": [
      {
        "id": "<int>",
        "peran": "pic_utama",
        "status": "aktif",
        "bergabung_pada": "<iso8601>",
        "dinonaktifkan_pada": null,
        "orang": {
          "id": "<int>",
          "nama": "Budi PIC",
          "email": "budi@contoh.test",
          "telepon": "+628123456789",
          "jabatan": "QA Manager"
        },
        "saya": true
      },
      {
        "id": "<int>",
        "peran": "staf",
        "status": "aktif",
        "bergabung_pada": "<iso8601>",
        "dinonaktifkan_pada": null,
        "orang": {
          "id": "<int>",
          "nama": "Sari Staf",
          "email": "sari@contoh.test",
          "telepon": "+628123456789",
          "jabatan": "QA Manager"
        },
        "saya": false
      }
    ],
    "undangan_menunggu": [],
    "maks_anggota": 50,
    "saya": {
      "customer_id": "<int>",
      "member_id": "<int>",
      "peran": "pic_utama"
    }
  }
}
```

`undangan_menunggu` ikut supaya PIC utama tahu dia sudah mengundang seseorang —
tanpa itu dia mengundang lagi, dan undangan kedua **membatalkan** kode yang sudah
terkirim.

`saya` di tiap baris anggota dijawab server, jangan dihitung aplikasi dari id
yang disimpan lokal (nilai yang gampang basi sesudah ganti akun).

### `POST /anggota/undangan` — REQ-ANG-01 · **PIC utama saja**

Throttle: **5 per menit per orang** (lebih ketat dari jalur admin lab: tiap
undangan mengirim email ke alamat yang diketik pemanggil).

```json
{ "email": "rekan@contoh.test", "peran": "staf" }
```

`peran` boleh `staf` atau `pic_utama` — PIC utama memang boleh mengangkat PIC
utama lain, supaya perusahaan yang PIC-nya keluar kerja tidak perlu menelepon
lab.

### `DELETE /anggota/undangan/{id}` · **PIC utama saja**

Membatalkan, bukan menghapus — jejak "siapa mengundang siapa" tetap ada.

### `POST /anggota/{id}/nonaktifkan` — REQ-ANG-02 · **PIC utama saja**

Seluruh sesi orang itu dicabut di request yang sama, **kecuali** dia masih
anggota aktif di perusahaan lain (kasus konsultan) — lalu yang berubah cuma
`X-Perusahaan-Id` yang boleh dia pakai.

PIC utama **terakhir** tidak bisa menonaktifkan dirinya sendiri: `422
pic_utama_terakhir`. Arahkan dia mengangkat PIC utama lain dulu.



Token `pelanggan:menunggu` ditolak di SELURUH bagian ini dengan:

```json
{
  "kode": "akun_belum_diverifikasi",
  "message": "Akun Anda masih menunggu verifikasi PT Sidik. Kami kabari begitu selesai."
}
```

---

## 4. Sisi LAB — `/api/admin/...` (aplikasi internal, bukan aplikasi pelanggan)

Ditulis di dokumen ini, bukan di `docs/kontrak-api.md`, supaya satu modul
terbaca di satu tempat. Pemanggilnya **aplikasi internal** dengan token
ber-ability `internal` dan `role:admin` — base URL `/api`, bukan
`/api/pelanggan/v1`.

Rutenya sengaja **tidak** ikut mati waktu `FITUR_PELANGGAN=false`: antrean yang
telanjur berisi tetap harus bisa diputus.

| Metode | Path | Keterangan |
|---|---|---|
| GET | `/api/admin/pengajuan-akun` | `?status=menunggu` (default) · `disetujui` · `ditolak` |
| POST | `/api/admin/pengajuan-akun/{id}/setujui` | `{customer_id}` **atau** `{pelanggan_baru:{nama,…}}` — wajib salah satu |
| POST | `/api/admin/pengajuan-akun/{id}/tolak` | `{alasan}` minimal 10 karakter |
| POST | `/api/customers/{id}/undangan` | `{email, peran}` |
| DELETE | `/api/customers/{id}/undangan/{undanganId}` | Membatalkan, bukan menghapus |
| PATCH | `/api/customers/{id}/pic-admin` | `{user_id}` (boleh `null`) |

#### `POST /api/customers/{id}/undangan` — kode ikut dibalas (Fase 1a, 10 Nov 2026)

Pemanggil: `admin` dan `super_admin` (K-49-14) di organisasinya sendiri.
`teknisi`/`viewer` `403`, akun pelanggan `403`/`404`, admin lab lain `404`.
Throttle `pelanggan-undang` (20/menit per admin).

```json
// 201
{
  "message": "Undangan dibuat untuk budi@contoh.test. Kirim kodenya langsung bila email tidak sampai.",
  "data": {
    "id": 12,
    "email": "budi@contoh.test",
    "peran": "staf",
    "kedaluwarsa_pada": "2026-11-17T09:00:00+07:00",
    "berlaku_sampai": "2026-11-17T09:00:00+07:00",
    "kode": "K7M2QX9P",
    "email_terkirim": false
  }
}
```

- **`data.kode` hanya muncul SEKALI, di balasan pembuatan ini.** Server cuma
  menyimpan hash-nya; tidak ada endpoint yang bisa membacanya lagi. Aplikasi lab
  yang ingin menampilkannya harus menampilkannya sekarang (dan jangan menyimpannya
  di log, analytics, atau penyimpanan lokal). Lupa/hilang = buat undangan baru
  (yang lama otomatis batal).
- Kode ini persis yang dikirim di email dan yang ditampilkan panel Filament:
  8 karakter `A-Z2-9` tanpa `0 O 1 I L`, ditukar di `POST /pelanggan/v1/auth/terima-undangan`.
- **`data.email_terkirim`**: `true` hanya bila email diserahkan ke mailer sungguhan
  tanpa galat. `false` bila terjadi galat kirim (yang ditelan server) ATAU server
  masih memakai mailer `log`/`array`. Ini BUKAN bukti email sampai ke kotak masuk
  penerima — kalau orangnya bilang belum menerima, bacakan `kode`-nya langsung.
  `false` bukan kegagalan: undangannya sah dan kodenya bisa dipakai.
- `berlaku_sampai` bernilai sama dengan `kedaluwarsa_pada` (nama lama tetap ada).
- Pada `422` (anggota aktif sudah ada, batas anggota) dan semua penolakan lain
  tidak ada `kode` di balasan.
- `POST /api/pelanggan/v1/anggota/undangan` (PIC utama) **tidak** berubah: kodenya
  hanya lewat email.

```json
{
  "data": [
    {
      "id": "<int>",
      "status": "menunggu",
      "diajukan_pada": "<iso8601>",
      "klaim": {
        "nama_perusahaan": "PT Klaim Pendaftar",
        "alamat_perusahaan": "Jl. Contoh No. 1, Bandung",
        "jabatan": "QA Manager"
      },
      "pemohon": {
        "id": "<int>",
        "nama": "Budi Menunggu",
        "email": "budi@contoh.test",
        "telepon": "+628123456789",
        "status": "pending_verifikasi"
      },
      "keputusan": null,
      "saran_pelanggan": [
        {
          "id": "<int>",
          "nama": "PT Klaim Pendaftar",
          "alamat": "Jl. Contoh No. 1, Bandung",
          "sama_persis": true
        }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

Tiga hal yang menentukan bentuk layarnya:

- **`klaim`, bukan `nama_perusahaan`.** Yang ada di situ nama yang DIKETIK
  pendaftar, bukan pelanggan yang sudah terverifikasi. Menyajikannya sebagai
  fakta menuntun admin menyetujui tanpa memeriksa — itu pintu R-D02.
- **`saran_pelanggan` itu saran, bukan pilihan.** Aturannya sama dengan impor
  pelanggan: badan usaha yang berbeda (`PT` vs `CV`) tidak pernah disarankan
  berpasangan, karena itu dua badan hukum dengan NPWP berbeda.
- **Admin WAJIB memilih.** Mengirim keduanya, atau tidak sama sekali, dijawab
  422. Tidak ada jalan "terima klaim apa adanya".

Dua admin menekan "setujui" bersamaan: yang kedua dapat **409 `sudah_diputus`**
beserta nama admin yang mendahului. Muat ulang antrean, jangan ulangi kirim.

## 5. Yang SENGAJA belum ada

Ditulis supaya tidak ditunggu:

| Belum ada | Kapan | Catatan |
|---|---|---|
| `POST/DELETE /perangkat` (FCM) | Fase 6 | `device_tokens.aplikasi` sudah ada |
| `/beranda`, `/alat`, `/sertifikat`, `/permintaan`, `/pesan` | Fase 6 | |

## 6. Keadaan akhir aplikasi pelanggan — hanya baca (K-50-09, 16 Okt 2026)

Permintaan, koreksi, dan foto DICABUT (keputusan pemilik 15 Okt). Rute yang **dihapus** (balasan
sekarang `404`): `/permintaan*` (daftar, buat, detail, batal, pesan, resi, foto item),
`/koreksi*`, `/foto*`, `/alat/{id}/foto`, `/alat/{id}/minta-koreksi`,
`/sertifikat/{id}/minta-koreksi`. Sisi lab: `/permintaan-pelanggan*`, `/koreksi-pelanggan*`,
`/foto-pelanggan/{id}` dan izin `permintaan.*` ikut hilang. Tabel & kolom TIDAK di-drop.

Rute yang dipakai aplikasi: `auth/{terima-undangan,masuk,keluar,keluar-semua,lupa-sandi,
atur-ulang-sandi}`, `beranda`, `alat` (daftar, `alat/{id}` termasuk `riwayat_sertifikat`, dan
status jatuh tempo `aman|segera|lewat|belum_ada|nonaktif`), `sertifikat` (daftar + `?q=` nomor/alat/nomor order/
seri di PT sendiri — nomor PT lain = daftar kosong, `sertifikat/{id}`, `sertifikat/{id}/unduh`),
`notifikasi*`, `saya`, `preferensi-notifikasi`, `app/status`, `app/info`. `/anggota*` dan `/paket*` tidak (selain `?q=` nomor order di `/paket`, §9)
disentuh. **`PATCH /alat/{id}` DICABUT 20 Okt 2026** (keputusan pemilik; balasan sekarang `405`/`404`):
pelanggan tidak mengubah data alat, perubahan lewat lab. Field `terkunci`/`field_terkunci`/`catatan`/
`resolusi` di detail alat tetap dikirim (kontrak tidak dipersempit). Rute tulis yang tersisa dikunci
`PengecualianTulisPelangganTest`.

Respons sertifikat tidak lagi memuat `bisa_minta_koreksi`, `koreksi_menunggu`, `data_cetak`; respons
detail alat tidak lagi memuat `foto` dan `koreksi_menunggu`. Pesan `field_terkunci` kini
"Hubungi lab bila ada yang keliru". Nomor sertifikat yang `ditarik` tidak terlihat pelanggan sampai
revisi `-R<n>` terbit.


## 7. `status_alat` — lima status alat (additive, 20 Okt 2026)

Field BARU di setiap objek alat: `GET /alat` (`data[]`), `GET /alat/{id}` (`data`), dan
`GET /beranda` (`data.perlu_perhatian[]`). Field lama `status_kalibrasi`
(`aman|segera_jatuh_tempo|lewat_jatuh_tempo|belum_ada_jadwal|nonaktif`) **tetap dikirim** apa
adanya — tidak ada yang dihapus atau diganti nama.

| `status_alat` | Aturan (dicek berurutan, turunan, tidak disimpan) |
|---|---|
| `nonaktif` | `equipments.status = nonaktif` |
| `belum_pernah` | belum ada sertifikat berstatus `terbit` untuk alat ini **dan** `tanggal_kalibrasi_terakhir` kosong (alat impor bertanggal kalibrasi dinilai dari tanggal) |
| `lewat_jadwal` | `hari_ke_jatuh_tempo < 0` |
| `mendekati_jadwal` | `0 ≤ hari_ke_jatuh_tempo ≤ 30` (= anak tangga pertama pengingat, `meta.jendela_segera_hari`) |
| `terkalibrasi` | selain di atas (termasuk bersertifikat tanpa tanggal jatuh tempo) |

`dalam_proses` (REQ-ALT-05) **tidak dikirim**: sumbernya permintaan kalibrasi pelanggan, yang dicabut
K-50-09. Aplikasi pelanggan (`StatusKalibrasi.dariApi`) sudah membaca keenam kode dan jatuh ke
`status_kalibrasi` kalau `status_alat` tidak ada, jadi APK lama maupun baru aman.
Ringkasan beranda (`ringkasan.*`) tidak berubah. Penjaga: `StatusAlatPelangganTest`.

## 8. Notifikasi `sertifikat terbit` ke anggota perusahaan (additive, 10 Nov 2026)

Sebelumnya `GenerateCertificate::kabarin()` hanya mengabari teknisi & admin penerbit; pelanggan
baru tahu bila admin mengirim email manual atau membuka aplikasi. Sekarang, begitu sertifikat
berstatus `terbit`, server mengirim notifikasi ke **anggota aktif perusahaan pemilik alat**
(`equipment.customer` dari sesi sertifikat; akun `aktif` DAN `customer_members.status = aktif`).
Tidak ada rute baru, tidak ada migrasi, tidak ada field respons baru: notifikasinya muncul lewat
`GET /notifikasi` yang sudah ada dan lewat push FCM (`NotifikasiSistem::toPush`).

| Kejadian | `kategori` | `tautan` | Saklar |
|---|---|---|---|
| Sertifikat pertama terbit | `pelanggan.sertifikat_terbit` | `{tipe: "pelanggan_sertifikat", id: <id sertifikat>}` | `status_permintaan` (bawaan menyala) |
| Revisi `<nomor>-R<n>` terbit (hasil tarik → setujui ulang) | `pelanggan.sertifikat_direvisi` | `{tipe: "pelanggan_sertifikat", id: <id revisi>}` | **tidak ada** — selalu dikirim (ISO/IEC 17025 §7.8.8) |

Isi `sertifikat_terbit` (netral; tampil di layar kunci lewat push):
`judul` "Sertifikat kalibrasi Anda sudah terbit", `isi` "Sertifikat {nomor} untuk {nama alat}
(terbit dd-mm-yyyy) sudah bisa dibuka di aplikasi." Tidak memuat nomor seri, nama perusahaan,
hasil/PASS-FAIL, nama teknisi atau penerbit, maupun catatan internal. Notifikasi revisi memakai
`SertifikatBerubah` yang sudah ada (hanya `catatan_pelanggan`).

Aturan pengiriman:

- Hanya sertifikat berstatus `terbit` (bukan `gagal`, `menunggu_generate`, `ditarik`, `dibatalkan`);
  status dibaca ulang dari database saat mengirim.
- Hanya perusahaan pemilik alat; perusahaan yang `organization_id`-nya tidak sama dengan sertifikat
  dilewati. Anggota/akun nonaktif tidak dikabari.
- Sekali per sertifikat per akun: `tanda_tangan` di payload (`pelanggan.sertifikat_terbit:<id>`)
  diperiksa sebelum mengirim; job yang diulang untuk sertifikat terbit juga berhenti lebih awal.
  Retry sertifikat `gagal` mengabari sekali, saat akhirnya terbit.
- Kegagalan notifikasi tidak pernah menggagalkan penerbitan atau me-retry job; log hanya memuat
  `certificate_id` dan kelas galat (tanpa pesan galat, email, atau nama).

Penjaga: `NotifikasiSertifikatTerbitPelangganTest` (14 kasus).

#### Kabar susulan untuk anggota yang bergabung SESUDAH sertifikat terbit (12 Nov 2026)

Kabar di atas hanya menjangkau anggota yang sudah aktif saat sertifikat terbit. Anggota yang
diundang/diaktifkan belakangan **sudah bisa membuka** sertifikat perusahaannya (isolasi per
`customer_id` dari `KonteksPerusahaan`, bukan per tanggal masuk; perusahaan lain tetap `404`),
tapi dulu tidak pernah diberi tahu. Sekarang, di pintu tunggal `Keanggotaan::daftarkan()` (undangan
ditukar di `POST /auth/terima-undangan`, atau admin menyetujui pengajuan), anggota yang BARU
bergabung atau dihidupkan kembali dikabari **sekali, ringkas**. Tanpa rute baru, tanpa migrasi,
tanpa field respons baru: muncul lewat `GET /notifikasi` dan push.

| Kejadian | `kategori` | `tautan` | Saklar |
|---|---|---|---|
| Bergabung ke perusahaan yang sudah punya sertifikat berlaku | `pelanggan.sertifikat_tersedia` | `null` (buka beranda) | `status_permintaan` (bawaan menyala) |

Isi (netral; layar kunci): `judul` "Sertifikat kalibrasi sudah tersedia", `isi` "{n} sertifikat
kalibrasi perusahaan Anda sudah bisa dibuka di aplikasi." Hanya jumlah — tanpa nomor, nama alat,
nama perusahaan, atau hasil.

Aturan:

- Satu kabar, bukan satu per sertifikat. Yang dihitung hanya sertifikat perusahaan itu sendiri
  yang `terbit` dan belum digantikan (lewat `LingkupData`); `0` = tidak ada kabar.
- Sekali per akun per perusahaan: `tanda_tangan` `pelanggan.sertifikat_tersedia:<customer_id>`.
  Undangan ulang / pengaktifan kembali tidak mengirim lagi. Memanggil `daftarkan()` untuk anggota
  yang sudah aktif bukan "bergabung".
- Saklar `status_permintaan` dihormati, termasuk untuk anggota lama yang diundang kembali.
- Perusahaan yang `organization_id`-nya beda dari keanggotaannya, anggota/akun nonaktif: dilewati.
- Kegagalan tidak pernah menggagalkan pendaftaran anggota; log hanya `customer_member_id` dan
  kelas galat. Dikirim sesudah transaksi commit (`DB::afterCommit`).

Penjaga: `KabarSusulanAnggotaBaruTest`. Kebijakan produk yang masih terbuka (kabar ini
satu kali atau tiap kali bergabung kembali, perlu tidaknya tautan ke daftar sertifikat) dicatat
di `docs/pertanyaan-lab-notifikasi-susulan.md`.

### Catatan serah terima untuk aplikasi pelanggan (`sidik-pelanggan-mobile`, diperiksa pada `origin/main` 5d4d4a5)

**Tidak ada yang wajib diubah agar notifikasi ini tampil dan bisa dibuka.**

- `lib/screens/tautan.dart` sudah memetakan `pelanggan_sertifikat` + id → `SertifikatDetailScreen`,
  dipakai bersama oleh ketukan baris kotak masuk dan ketukan push. `lib/screens/notifikasi/notifikasi_screen.dart`
  hanya membaca `judul`/`isi`/`tautan`; `kategori` hanya dibedakan untuk `pelanggan_alat_jatuh_tempo`.
  Jadi `pelanggan.sertifikat_terbit` dan `pelanggan.sertifikat_direvisi` tampil dengan judul/isi dari
  server dan membuka detail sertifikat. `test/model_test.dart` sudah menguji bentuk tautan ini.
- Teks kosong-state kotak masuk dan teks izin push ("Kami kabari saat sertifikat terbit …") sudah
  menjanjikan kabar ini; sekarang janjinya benar.

Yang bisa dipertimbangkan (tidak memblokir, belum dikerjakan di repo aplikasi):

1. **Tidak ada layar `preferensi-notifikasi` di aplikasi** (tidak ada rujukan ke endpoint itu di `lib/`).
   Saklar `status_permintaan` karenanya tidak bisa dimatikan pengguna dari aplikasi; bawaannya menyala.
   Bila layar itu dibuat, labelnya jangan "status permintaan" saja — permintaan kalibrasi dicabut
   (K-50-09) dan saklar itu sekarang juga memegang kabar sertifikat terbit.
2. Id kanal Android push `sidik_kerja` / `sidik_pengingat` tidak ditemukan di kode Android aplikasi
   pelanggan (`lib/`, `android/`). Pengingat jatuh tempo sudah memakai pola yang sama; perilaku
   kanal bawaan FCM di HP nyata **belum diverifikasi**.
3. `kategori` baru tidak punya ikon khusus; kotak masuk memakai tampilan yang sama untuk semua baris.


## 9. Pencarian dari NOMOR ORDER (additive, 11 Nov 2026)

Tanpa migrasi, tanpa field respons baru, tanpa rute baru — hanya parameter `q` yang kini lebih luas.

| Rute | Perubahan |
|---|---|
| `GET /sertifikat?q=` | Selain nomor sertifikat, nama alat, dan nomor seri, `q` kini juga mencocokkan **nomor order**: `calibration_sessions.nomor_order` (yang tercetak di sertifikat) dan `orders.nomor` (nomor order pelacakan) dari sertifikat tersebut. Pencocokan `LIKE %q%`, `max:100` karakter. |
| `GET /paket?q=` | **Parameter baru** (opsional, `max:100`): mencari `orders.nomor` (`LIKE %q%`). Berlaku bersama `selesai`, `per_page`, dan pagination. |

Aturan isolasi tidak berubah dan dijaga `IsolasiPerusahaanTest` serta `CariDariNomorOrderTest`:

- Pencarian hanya berjalan **di dalam** himpunan milik perusahaan pemanggil (`LingkupData`). Nomor order PT
  lain dijawab `200` dengan daftar kosong (`meta.total = 0`), sama persis dengan nomor yang tidak ada —
  bukan `403`, bukan `404`.
- Order yang dicocokkan lewat `orders.nomor` dikunci ke `customer_id` pemanggil (dari `Konteks`, tidak dari
  request); sesi yang salah-tautan ke order PT lain tidak bisa dipakai menebak nomor itu.
- Nomor order yang tercetak di sertifikat dibaca dari kolom `calibration_sessions.nomor_order`, bukan dari
  `snapshot.header.order_number` (pencarian di dalam JSON tidak dibuktikan setara di MySQL; kolom
  biasanya sama dengan isi snapshot karena Order Number wajib terisi sebelum sesi disetujui).

Aplikasi pelanggan tidak wajib diubah; kolom pencarian yang sudah ada otomatis menerima nomor order.

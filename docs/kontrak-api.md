# Kontrak API — apa yang dibutuhin Mobile

Dokumen ini buat @raihannazhiif (backend, [`sidik-calibration-api`](https://github.com/ZainulArkaanAlinsi/sidik-calibration-api)). Isinya daftar endpoint yang app Flutter panggil, plus bentuk JSON yang diharapkan — biar nggak ada tebak-tebakan nama field.

**Kalau ada yang mau diubah, boleh banget** — tapi kabarin dulu, jangan diam-diam, karena mobile ngoding persis ngikutin bentuk di sini. Ubah namanya di dokumen ini juga biar tetap satu sumber kebenaran.

---

## 0. Aturan Umum (berlaku buat semua endpoint)

**Base URL**: `/api` — mobile nembak ke `http://10.0.2.2:8000/api` waktu dev (itu cara emulator Android manggil `localhost` laptop).

**Auth**: token Bearer lewat header. Semua endpoint butuh ini kecuali `/health`, `/login`, dan `/verify/{qr_token}`.
```
Authorization: Bearer <token>
Accept: application/json
```

> **Update 14 Jul — bukan JWT, tapi Laravel Sanctum.** Buat mobile caranya sama persis (tetap `Authorization: Bearer <token>`, tinggal simpan stringnya), cuma bentuk tokennya beda: `1|JpQDXLhSEz...`, bukan `eyJhbGci...`. Konsekuensinya: **token Sanctum nggak punya masa berlaku, jadi nggak ada endpoint `/refresh`** dan nggak perlu logic auto-refresh di app. Token cuma mati kalau dipanggil `/logout` atau dicabut admin.

**Tanggal**: selalu format ISO 8601 (`2026-07-14T09:30:00Z`), jangan `14/07/2026` — biar Dart bisa `DateTime.parse()` langsung tanpa nebak format.

**Angka desimal**: kirim sebagai **number**, bukan string. `"nilai": 10.05` ✅, `"nilai": "10.05"` ❌.

**Sukses (1 objek)** — data selalu dibungkus `data`:
```json
{ "data": { "id": 1, "nama": "..." } }
```

**Sukses (list, pakai paginasi Laravel)**:
```json
{
  "data": [ { "id": 1 }, { "id": 2 } ],
  "meta": { "current_page": 1, "last_page": 5, "per_page": 15, "total": 68 }
}
```

**Error validasi (422)** — format bawaan Laravel, mobile udah siap baca ini buat nampilin error per field:
```json
{
  "message": "Data yang dikirim tidak valid.",
  "errors": {
    "nama_alat": ["Nama alat wajib diisi."],
    "serial_number": ["Nomor seri sudah dipakai alat lain."]
  }
}
```

**Error lain**: `401` token invalid/kadaluarsa · `403` role nggak punya akses · `404` nggak ketemu · `500` error server. Selalu ada field `message` yang layak ditampilin ke user.

---

## 1. PALING PERTAMA — Health Check

Bikin ini duluan, hari ini juga kalau bisa. Kecil, tapi begitu ada, mobile bisa buktiin sambungannya jalan sebelum fitur beneran dibangun di atasnya.

**`GET /api/health`** — tanpa auth.
```json
{ "status": "ok", "app": "sidik-calibration-api", "time": "2026-07-14T09:30:00Z" }
```

> ✅ **Live sejak 14 Jul.** Satu bedanya: `app` isinya ambil dari `APP_NAME` backend, sekarang nilainya `"SIDIK Calibration"` (bukan `"sidik-calibration-api"`). Kalau mobile nggak nge-assert field itu, aman.

---

## 2. Auth (dibutuhin Minggu 2)

> ✅ **Semua endpoint di bagian ini udah live sejak 14 Jul** — login (ID pegawai & email dua-duanya jalan), register, `/me`, `/logout`, plus approval admin (`GET /api/users?status=pending`, `approve`, `reject`). Dites end-to-end, termasuk skenario daftar-sambil-ngaku-admin: role dari client diabaikan, akunnya tetap `teknisi` + `pending`.
>
> Yang belum ada di dokumen ini, tolong dicatat mobile:
> - **`429 Too Many Requests`** bisa muncul: login dibatesin **10 percobaan/menit per IP**, register **5/menit**. Siapin pesan "coba lagi sebentar" di UI.
> - Akun **`nonaktif`** ditolak `403` juga, pesannya `"Akun ini nonaktif. Hubungi admin."` (beda dari pesan `pending`).
> - **`organization_id` masih `null`** buat akun hasil register — tabel `organizations` belum ada (baru dirancang di ERD hari ini). Jangan dianggap wajib int dulu di sisi Dart, biar nggak crash pas parsing.
> - Akun dev buat nyoba: `SDK-0001` (admin) · `SDK-0002` (teknisi) · `SDK-0003` (viewer) · `SDK-0099` (sengaja `pending`, buat nyobain layar "belum disetujui"). Password semua `rahasia123` — **di laptop doang**. Di server sandinya dari `SEED_ADMIN_PASSWORD`, atau acak kalau variabel itu kosong.

### `POST /api/login`

**Login nerima ID pegawai ATAU email** di satu field `identifier` — teknisi di lapangan hafal nomor pegawainya (`SDK-0001`), bukan emailnya. Backend yang nebak: kalau ada `@` anggap email, kalau nggak anggap `employee_id`.

Request:
```json
{ "identifier": "SDK-0001", "password": "rahasia123" }
```
(atau `{ "identifier": "admin@sidik.test", "password": "..." }` — dua-duanya harus jalan)

Response `200`:
```json
{
  "data": {
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "user": {
      "id": 1,
      "nama": "Budi Santoso",
      "email": "admin@sidik.test",
      "employee_id": "SDK-0001",
      "role": "admin",
      "status": "aktif",
      "department": "Quality Control",
      "organization_id": 1
    }
  }
}
```
Kredensial salah → `401` `{ "message": "ID pegawai / email atau password salah." }`

> **`role` wajib: `admin` / `teknisi` / `viewer`** — persis, huruf kecil. Mobile pakai ini buat nentuin menu mana yang dirender.
> **`status` wajib: `aktif` / `pending` / `nonaktif`.**
> **`employee_id` wajib unik** (dipakai buat login).

**PENTING — akun `pending` WAJIB ditolak login di backend** dengan `403`:
```json
{ "message": "Akun kamu belum disetujui admin. Tunggu konfirmasi dulu ya." }
```
Mobile juga nolak di sisi UI, **tapi itu nggak cukup** — orang bisa nembak API langsung pakai curl, jadi backend harus jadi benteng aslinya.

### `POST /api/register`

Daftar mandiri buat teknisi. **Akun yang dibuat NGGAK boleh langsung aktif.**

Request:
```json
{
  "nama": "Eko Prasetyo",
  "employee_id": "SDK-0099",
  "department": "Kalibrasi",
  "email": "eko@ptsidik.com",
  "password": "rahasia123"
}
```
Response `201`:
```json
{ "message": "Pendaftaran terkirim. Akun menunggu persetujuan admin." }
```

Aturan yang wajib dipegang backend:
- Akun baru **selalu** `status: "pending"` dan `role: "teknisi"` (default)
- **User NGGAK boleh milih role sendiri** waktu daftar — kalau field `role` dikirim dari client, **abaikan**. Kalau nggak, siapa pun bisa daftar jadi `admin` dan langsung bisa approve dirinya sendiri
- `email` & `employee_id` dobel → `422` dengan pesan jelas ("Email ini sudah terdaftar." / "ID pegawai ini sudah terdaftar.")
- Password minimal 8 karakter

### `POST /api/forgot-password`
Request: `{ "email": "admin@sidik.test" }`
Response `200`: `{ "message": "Link reset password udah dikirim ke email kamu." }`
Email nggak terdaftar → `404` `{ "message": "Email ini nggak terdaftar." }`

> **Reset lewat email, bukan lewat `employee_id`** — biar yang bisa ganti password cuma orang yang megang emailnya. Kalau reset bisa pakai ID pegawai doang, siapa pun yang tahu nomor pegawai orang lain bisa reset password dia.
>
> ⚠️ **Catatan keamanan yang perlu kita omongin.** Balikin `404 "Email ini nggak terdaftar"` itu **ngebocorin email mana yang punya akun** (namanya *user enumeration*) — orang bisa nebak-nebak email buat tahu siapa aja karyawan yang punya akun. Praktik yang lebih aman: **selalu** jawab `200 "Kalau emailnya terdaftar, link udah dikirim"`, tanpa ngasih tahu ada atau nggak.
>
> Buat sekarang mobile ngikutin catatan harian (yang minta state "error email nggak terdaftar"), tapi kalau kamu setuju, kita ganti dua-duanya ke pola yang aman. **Ini keputusan yang perlu diambil bareng, bukan diam-diam.**

> ### ✅ Keputusan (backend, 14 Jul): **pakai pola yang aman.**
> Kamu bener, jadi backend ngikutin usulan kamu. `POST /api/forgot-password` **selalu** balikin `200` dengan pesan yang sama, mau emailnya terdaftar atau nggak — **nggak ada `404`**:
> ```json
> { "message": "Kalau email itu terdaftar, link reset password udah dikirim ke sana." }
> ```
> **Efeknya buat mobile: layar Reset Password cuma butuh 2 state, bukan 3.** State "error email nggak terdaftar" nggak bisa dibikin (backend emang nggak ngasih tahu), jadi ganti aja jadi layar "cek email kamu". Catatan harian [[2026-07-20]] udah dikoreksi.
>
> Rate limit: **5 percobaan/menit per IP**, jatahnya sendiri — nggak nyampur sama jatah login.

### `POST /api/reset-password`
Dipakai dari link di email (deep link ke app).

Request — **`email` ikut dikirim**, ya:
```json
{ "token": "...", "email": "teknisi@sidik.test", "password": "passwordbaru123" }
```

> ✅ **Live sejak 14 Jul.** Tiga catatan:
> - **`email` wajib ada.** Token reset itu nempel ke email, jadi backend butuh dua-duanya buat nyocokin. Mobile udah punya nilainya: link di email bentuknya `sidik://reset-password?token=...&email=...` — tinggal dibaca dari deep link-nya.
> - **Deep link `sidik://`** — tolong daftarin scheme itu di Android manifest. Waktu dev backend pakai `MAIL_MAILER=log`, jadi link-nya nongol di `storage/logs/laravel.log` (bisa di-copy manual buat tes).
> - **`password_confirmation` opsional.** Kalau dikirim, dicek harus sama; kalau nggak, ya udah — konfirmasinya kamu cek di UI.
> - Sukses → `200 { "message": "Password berhasil diubah. Silakan login lagi." }`. Token ngawur/kadaluarsa → `422`.
> - **Semua token login lama otomatis dicabut** sesudah reset berhasil. Jadi kalau HP lama masih megang sesi, sesinya mati — justru itu alasan orang me-reset password.

### `GET /api/me`
Buat validasi token yang tersimpan waktu app dibuka (splash). Response: objek `user` yang sama kayak di atas.

### `GET /api/me/permissions` — matriks peran (live 26 Jul)

Yang bikin tombol bisa **disembunyiin sebelum ditekan**, bukan dipajang lalu kena
`403`. Panggil sekali sehabis login, simpen di state.

```json
{
  "data": {
    "role": "teknisi",
    "boleh": [
      "alat.lihat", "alat.tambah", "alat.ubah", "alat.hapus",
      "kalibrasi.lihat", "kalibrasi.buat", "kalibrasi.ubah",
      "kalibrasi.hitung-preview", "kalibrasi.pindai-foto",
      "kalibrasi.verifikasi-pindai",
      "sertifikat.lihat", "sertifikat.unduh",
      "laporan.lihat", "laporan.export",
      "arsip.lihat", "arsip.berkas.unduh",
      "pelanggan.dropdown", "standar.lihat", "ruangan.lihat",
      "metode.lihat", "kategori.lihat",
      "dashboard.lihat", "notifikasi.lihat"
    ],
    "batasan": {
      "kalibrasi": "sendiri",
      "sertifikat": "sendiri",
      "laporan": "sendiri",
      "dashboard": "sendiri"
    }
  }
}
```

Jumlah izin sekarang: **admin 44 · teknisi 23 · viewer 15.**

- **`boleh` itu daftar putih.** Nama izin yang nggak ada di situ = ditolak. Jangan
  nebak dari `role` lagi — itu yang bikin bug "mulus di admin, mentok di teknisi".
- **`batasan` beda dari `boleh`.** `boleh` jawab "layarnya kebuka apa nggak";
  `batasan` jawab "isinya sebanyak apa". Teknisi **boleh** buka `/calibrations`,
  cuma dapat pekerjaannya sendiri. Nilainya `sendiri` atau `semua`.
- **Ini alat TAMPILAN, bukan penjagaan.** Penjagaannya tetap di server: kalau
  mobile ngeyel manggil, tetap `403`. Jangan dipakai buat mutusin hal yang
  sifatnya keamanan.
- **Nama izinnya STABIL** — aman di-hardcode di mobile. Yang bisa berubah itu
  isi `boleh` per role.

> **Kenapa ini nggak bisa basi.** Daftarnya nggak ditulis tangan — dihitung dari
> middleware `role:` di rute yang beneran terdaftar (`app/Services/MatriksIzin.php`).
> Jadi begitu ada yang mindahin rute masuk/keluar blok `role:admin`, jawaban
> endpoint ini ikut berubah di request berikutnya. `MeIzinTest` manggil **semua**
> endpoint yang dipetakan pakai ketiga role dan mastiin `403`-nya kejadian persis
> kalau izinnya nggak ada — jadi daftar ini nggak bisa bohong tanpa test-nya merah.

**Jawaban tiga pertanyaan di `permintaan-endpoint-fase-2.md` §1:**

1. **Viewer boleh lihat arsip & sertifikat?** **Boleh** — baca semua, nulis nggak
   ada sama sekali. Persis kayak asumsi mobile sekarang.
2. **Teknisi boleh lihat sesi teknisi lain?** **Nggak.** `batasan.kalibrasi:
   "sendiri"`. Kalau nebak ID lewat `GET /calibrations/{id}` punya orang lain,
   dapat `404` (bukan `403` — biar nggak jadi cara ngintip ID mana yang ada).
3. **Ada rencana role keempat?** **Nggak.** Penanda tangan sertifikat diputusin
   jadi **atribut**, bukan role (keputusan 26 Jul), jadi `UserRole` di mobile tetap
   tiga.

### `POST /api/logout`
Response `200`: `{ "message": "Berhasil logout." }`

### Approval akun (admin-only) — dibutuhin biar register-nya ada gunanya
- **`GET /api/users?status=pending`** — daftar akun yang nunggu disetujui
- **`POST /api/users/{id}/approve`** — body `{ "role": "teknisi" }`. Admin yang nentuin role-nya di sini, bukan si pendaftar. Setelah ini `status` jadi `aktif` dan orangnya baru bisa login
- **`POST /api/users/{id}/reject`** — tolak pendaftaran

Kalau endpoint approve belum ada, **register jadi jebakan**: orang daftar, terus nggak pernah bisa masuk selamanya, dan nggak ada yang tahu. Jadi dua-duanya harus jalan bareng.

> ⚠️ **20 Jul — perubahan perilaku, tolong dicek di sisi mobile.** `GET /api/users` sebelumnya balikin user dari **semua PT**, bukan cuma PT-nya admin yang login — itu bug, bukan fitur. Sekarang udah dikunci per organisasi.
>
> Efeknya buat mobile: **jumlah baris di layar approval bisa berkurang** kalau selama ini ada data lintas-PT yang keikut. Dan `{id}` milik PT lain sekarang balik **`404`** di `approve`/`reject`/`update`/`reset-password`, yang tadinya `200`. Kalau ada layar yang nyimpen ID hasil listing lama, itu yang perlu diperiksa.
>
> Catatan yang sama berlaku buat `GET /api/technicians` (Bagian 9) — dari awal emang udah dikunci per PT.

---

## 3. Data Alat (dibutuhin Minggu 3)

### `GET /api/equipments`
Query params yang mobile pakai: `?search=kaliper&category=panjang&status=overdue&page=1`

```json
{
  "data": [
    {
      "id": 12,
      "nama_alat": "Jangka Sorong Mitutoyo",
      "serial_number": "MT-500-196-30",
      "kategori": "panjang",
      "merk": "Mitutoyo",
      "pelanggan": { "id": 3, "nama": "PT Maju Jaya" },
      "tanggal_kalibrasi_terakhir": "2026-01-15T00:00:00Z",
      "tanggal_jatuh_tempo": "2027-01-15T00:00:00Z",
      "status": "aktif"
    }
  ],
  "meta": { "current_page": 1, "last_page": 3, "per_page": 15, "total": 42 }
}
```

> **`status` wajib salah satu dari: `aktif` / `overdue` / `nonaktif`.**
> **`kategori`** ngikutin kelompok pengukuran di `data-kemampuan-kalibrasi.json` (panjang, massa, suhu, tekanan, volume, dst) — pakai **string huruf kecil** yang konsisten, jangan campur "Panjang" dan "panjang".

> ## ✅ Live sejak 14 Jul — tapi BACA INI DULU sebelum ngoding
>
> **1. Kode kategorinya bukan `"suhu"`.** Kelompok pengukuran di lampiran akreditasi ada 10, dan kodenya slug dari nama aslinya — jadi ada yang panjang. **Jangan di-hardcode dari ingatan**, ambil dari `GET /api/categories`. Daftar lengkapnya:
>
> `panjang` · `massa` · `volume` · `tekanan` · `gaya` · `aliran` · `densitas` · `instrumen-analitik` · **`suhu-dan-kelembapan`** · **`waktu-dan-frekuensi`**
>
> **2. Nulis alat pakai `pelanggan_id`, bukan objek `pelanggan`.** Responsnya tetap objek (`"pelanggan": {"id":3,"nama":"..."}`), tapi buat `POST`/`PUT` kirim `"pelanggan_id": 3`.
>
> **3. `status: "overdue"` NGGAK bisa dikirim.** Dia dihitung backend dari `tanggal_jatuh_tempo` — kalau dikirim di body, ditolak `422`. Yang bisa diset cuma `aktif`/`nonaktif`. Sebabnya: kalau `overdue` disimpen, nilainya basi tiap ganti hari.
>
> **4. Hak akses**: baca = semua role (termasuk viewer). Nulis (`POST`/`PUT`/`DELETE`) = **admin & teknisi**; viewer ditolak `403`. Sesuai permintaan kamu.
>
> **5. Field bonus di response** (di luar kontrak, aman diabaikan): `model`, `no_identifikasi`, `range_min`, `range_max`, `satuan`, `resolusi`, `toleransi`, `lokasi`, dan **`nama_alat_kemampuan`**. Ini dibutuhin nanti pas layar kalibrasi.
>
> **6. `meta` paginasinya lebih gemuk dari yang kamu tulis** — Laravel ikut ngirim `from`, `to`, `path`, `links`. Superset, jadi aman; abaikan aja yang nggak kepakai.
>
> **✅ 18 Jul — audit ulang, `nama_alat_kemampuan` sekarang beneran dipakai mobile.** Sebelumnya field ini kekirim di response tapi nggak pernah diisi lewat form Alat — efeknya SEMUA alat yang didaftarin lewat app selama ini kemungkinan kalibrasinya jatuh ke jalur ketidakpastian generik (standar+resolusi), bukan CMC resmi hasil akreditasi (`GumCalculator::kemampuanUntukTitik()` di backend cocokin lewat field ini + rentang, bukan cuma `equipment_category_id`). Form Alat sekarang punya dropdown "Jenis Alat (Kemampuan Kalibrasi)" yang isinya dari `GET /api/categories/{kode}` → `kemampuan[].nama_alat`, opsional tapi direkomendasiin diisi. Field `catatan` juga sekarang kepakai (ada di `EquipmentRequest` tapi belum pernah dikirim mobile).

### `GET /api/equipments/{id}` — 1 objek, bentuk sama.
### `POST /api/equipments` · `PUT /api/equipments/{id}` · `DELETE /api/equipments/{id}`
Body sama seperti field di atas (tanpa `id`). Teknisi & admin boleh; **viewer harus ditolak `403`.**

### `GET /api/categories`
Mobile butuh ini buat isi dropdown kategori + nyiapin worksheet dinamis (kolom tiap kategori beda-beda):
```json
{
  "data": [
    {
      "kode": "panjang",
      "nama": "Panjang",
      "rentang_ukur": "0 – 300 mm",
      "ketidakpastian_terbaik": 0.005,
      "satuan": "mm"
    }
  ]
}
```

> ✅ **Live sejak 14 Jul**, isinya 10 kategori dari lampiran akreditasi (151 rentang kemampuan).
>
> ⚠️ **`rentang_ukur` / `ketidakpastian_terbaik` / `satuan` di sini cuma RINGKASAN, jangan dipakai buat validasi.** Satu kelompok pengukuran bisa punya banyak satuan sekaligus — "Panjang" isinya µm **dan** mm, "Instrumen Analitik" isinya pH, NTU, cP, µS. Angka yang ditampilin itu diambil dari satuan yang paling sering muncul di kelompok itu, jadi cocoknya buat dipajang sekilas doang.
>
> Buat validasi rentang & nyiapin worksheet, pakai **`GET /api/categories/{kode}`** — balikin semua rentang kemampuan (CMC) kategori itu satu per satu:
> ```json
> { "data": { "kode": "panjang", "nama": "Panjang", "kemampuan": [
>     { "nama_alat": "Micrometer", "parameter": null, "range_min": 0, "range_max": 25,
>       "range_note": null, "satuan": "mm", "ketidakpastian_terbaik": 0.00083,
>       "satuan_ketidakpastian": "mm", "faktor_cakupan": 2, "metode": "SIDIK-IK-CAL-0515_Rev.3",
>       "sumber": "akreditasi", "tanpa_cmc": false, "alasan_tanpa_cmc": null,
>       "profil": null, "punya_toleransi": true }
> ] } }
> ```
> **`range_min` bisa `null`** — 59 dari 151 kemampuan emang nggak punya batas bawah numerik: ada yang titik tunggal (buret "25 mL"), ada yang batasnya kata-kata (oven "ambient ~ 300 °C" → teks aslinya ada di `range_note`). Jangan diparse jadi `double` mentah-mentah, nanti crash.
>
> ### `punya_toleransi` — Update 27 Agt
>
> **`false` = jenis alat ini NGGAK divonis PASS/FAIL, jadi `equipments.toleransi` boleh kosong.** Berlaku buat **19 dari 24** profil — yang DIVONIS justru minoritas: cuma pH Meter, Turbidimeter, Chlorine Meter, Refractometer, dan Viscometer. Sisanya (Conductivity, Spectrophotometer, Autoklaf, DO Meter, Gas Detector, TITS, TIDS, Timbangan, kelima Enclosure, ketiga alat suhu, dan ketiga alat Waktu & Frekuensi) masternya berhenti di `Correction` + `U95%` — nggak ada batas keberterimaan sama sekali di lembar kerjanya.
>
> `true` juga buat nama alat yang nggak dikenal profil mana pun (jalur generik): di situ toleransi memang penentu PASS/FAIL-nya.
>
> Jawabannya lahir dari `CalibrationProfileRegistry`, jadi profil ke-21 ikut kejawab tanpa rilis APK baru — alasan yang sama persis kayak `profil` di baris yang sama. Jangan bikin daftar nama alat tandingan di sisi mobile.
>
> **Yang mobile perlu lakuin:** form Alat berhenti mewajibkan `toleransi` waktu `punya_toleransi: false`. Field yang nggak ada di response (server lama) dianggap `true` — perilaku lama.
>
> **Kenapa ini penting, bukan kosmetik:** form Alat dulu mewajibkan `toleransi` buat SEMUA alat, alasannya "nanti kena 422". 422 itu nggak pernah datang (lihat catatan di §4), dan yang datang justru teknisi mengarang angka toleransi — mengarang kriteria kelulusan buat alat yang nggak divonis. Mengisi kolom itu pernah mematikan seluruh sesi Conductivity.
>
> ### Rentang master dipecah per GOLONGAN, bukan per alat
>
> Satu `nama_alat` biasanya punya beberapa baris. Yang perlu diperhatiin waktu ngerangkumnya jadi satu rentang alat: **baris-baris itu bisa beda SATUAN, dan yang beda satuan nggak boleh digabung.**
>
> | `nama_alat` | baris master | rentang alatnya |
> |---|---|---|
> | Thermocouple | −20–150, 150–400, 400–600 °C | −20–600 °C ✅ satu satuan, sah digabung |
> | Termometer Gelas | 0–100, 100–200 °C | 0–200 °C ✅ |
> | Thermohygrometer | Suhu 15–50 °C, Kelembapan 30–90 %RH | ❌ dua besaran, JANGAN jadi 15–90 |
> | Autoklaf | Suhu 105–121 °C, Tekanan 0–4 bar | ❌ JANGAN jadi 0–121 |
> | Mesin UTM | 0–500 kgf, 10–3000 kN | ❌ JANGAN jadi 0–3000 |
>
> Angka gabungan lintas satuan kayak gitu nggak ditolak siapa pun — dia lolos ke `range_min`/`range_max` alat, ikut ke lembar kerja, dan ikut ke sertifikat.

---

## 4. Kalibrasi (dibutuhin Minggu 4, jalur kamera Minggu 5)

> ## ✅ Live sejak 14 Jul — tapi ADA 2 PERUBAHAN KONTRAK, baca dulu sebelum ngoding
>
> Bentuk `hasil` & nilai enum-nya persis kayak yang kamu tulis. Yang berubah cuma dua, dan dua-duanya nggak bisa dihindarin:
>
> **1. `standard_id` sekarang WAJIB di `POST`/`PUT` — ini field baru, belum ada di dokumen versi kamu.**
>
> Ketidakpastian standar acuan itu **komponen Type B terbesar** di perhitungan GUM. Tanpa dia, `ketidakpastian_diperluas` (U) yang kita hitung jadi lebih kecil dari yang sebenernya — dan alat yang harusnya FAIL malah lolos jadi PASS. Buat lab terakreditasi itu temuan serius, jadi backend nolak `422` kalau `standard_id` nggak dikirim.
>
> **Yang mobile perlu siapin**: dropdown "Standar Acuan" di layar kalibrasi. Endpoint-nya **udah ada** — lihat `GET /api/standards` di bawah. Standar hasil seeder id-nya `1` (Gauge Block Set Grade 0).
>
> **2. Keputusan PASS/FAIL pakai *guarded acceptance* (ILAC-G8), bukan `|error| ≤ toleransi`.**
>
> Alat lulus cuma kalau **`|error| + U ≤ toleransi`** — ketidakpastian pengukurannya ikut diperhitungkan. Efeknya: alat yang errornya mepet batas sekarang **FAIL**, padahal aturan sederhana bakal bilang PASS.
> ```
> toleransi ±0.05 · error 0.047 · U 0.0062
>   |error| ≤ toleransi        → 0.047  ≤ 0.05  → PASS   ❌ nggak dipakai
>   |error| + U ≤ toleransi    → 0.0532 > 0.05  → FAIL   ✅ ini yang dipakai
> ```
> Mobile nggak perlu ngitung apa pun — cukup tahu kenapa ada alat yang kelihatannya "masih masuk toleransi" tapi hasilnya FAIL, biar nggak dikira bug.
>
> ### Aturan lain yang bikin `422` (siapin pesannya di UI)
> - **Tiap titik ukur minimal 2 pembacaan.** Type A itu standar deviasi antar-pengulangan — dari satu angka nggak ada sebaran yang bisa dihitung. (Aturan "minimal 3" yang kamu tulis di contoh reject itu **nggak** dipaksain backend — biar tetap jadi penilaian admin.)
> - **Alat yang `toleransi`-nya masih kosong ditolak — TAPI cuma buat alat yang emang divonis PASS/FAIL.** Tanpa batas, PASS/FAIL nggak ada artinya. Isi dulu lewat `PUT /api/equipments/{id}`.
>   **⚠️ Update 27 Agt — batasannya:** 19 dari 24 profil (semua kecuali pH, Turbidimeter, Chlorine, Refractometer, Viscometer) masternya emang berhenti di `U95%` tanpa batas keberterimaan, dan `CalibrationValidator::periksaKelengkapanHitung()` sengaja melewatinya — 422-nya nggak pernah datang buat alat-alat itu. Jangan dibaca sebagai "semua alat wajib toleransi": bacaan itu bikin form Alat mewajibkan kolom yang nggak punya isi yang benar, dan teknisi ngarang angkanya. Tanya server lewat `punya_toleransi` di `GET /api/categories/{kode}` (§3).
> - **Standar yang sertifikatnya kadaluarsa ditolak.** Ketertelusurannya putus.
> - `tanggal_kalibrasi` nggak boleh di masa depan.
>
> ### Tambahan di luar kontrak
> - **`status: "draft"` boleh dikirim di `POST`** — buat "simpan dulu, lanjut nanti". Kalau nggak dikirim, sesi langsung masuk antrean approval (`menunggu_approval`), sesuai contoh kamu.
> - **`PUT /api/calibrations/{id}`** — teknisi ngerjain ulang sesi yang ditolak admin (`perlu_revisi`) atau nerusin draft. Body-nya sama kayak `POST`. Tanpa ini, tombol "reject" jadi jalan buntu: teknisi dikasih catatan revisi tapi nggak bisa ngapa-ngapain. Sesi yang udah `disetujui` **nggak bisa** diubah (`422`) — angka di sertifikat yang udah dipegang pelanggan nggak boleh berubah diam-diam.
> - **Field bonus di response** (superset, aman diabaikan): `nomor_sesi` (`KAL/2026/07/0001`), `standar_acuan`, `suhu_ruang`, `kelembaban`, `lokasi`, `sertifikat`, dan **`titik`** — rincian tiap titik ukur. Mobile udah nampilin ini di layar Detail Hasil Kalibrasi (`lib/screens/history/calibration_detail_screen.dart`), sinkron sama `CalibrationResource::toArray()`.
>
> **✅ Bentuk `titik` — dikonfirmasi dari `CalibrationResource.php` (commit `06af54e`, 18 Jul):**
> ```json
> "titik": [
>   {
>     "titik_ke": 2,
>     "titik_ukur": 6.9889072,
>     "rata_rata": 7.004,
>     "error": 0.0150928,
>     "koreksi": -0.0150928,
>     "standar_deviasi": 0.0054772256,
>     "jumlah_pengulangan": 5,
>     "type_a": 0.0054772256,
>     "type_b": 0.01047,
>     "type_b_components": [
>       { "sumber": "ketidakpastian_standar", "keterangan": "Sertifikat standar pH Buffer Solution 7 (U=0.02 pH, k=2)", "distribusi": "normal", "nilai": 0.01 },
>       { "sumber": "resolusi_alat", "keterangan": "Resolusi alat 0.01 pH", "distribusi": "persegi", "nilai": 0.005 }
>     ],
>     "ketidakpastian_gabungan": 0.010714869,
>     "faktor_cakupan_k": 1.9706589608,
>     "ketidakpastian_diperluas": 0.0211089499,
>     "toleransi": 0.05,
>     "keputusan": "PASS",
>     "standar_acuan": { "id": 3, "nama": "pH Buffer Solution 7", "no_sertifikat": "HC46341939" }
>   }
> ]
> ```
> Catatan buat mobile: **nggak ada `satuan` atau `pembacaan` di dalam tiap `titik`** — beda dari yang mobile kira sebelumnya. Pembacaan mentahnya ada di field terpisah `pembacaan_mentah` (array top-level, cuma ikut di `GET /api/calibrations/{id}` — bukan di daftar), isinya `{id, titik_ke, pembacaan_ke, pembacaan, input_source, is_verified, photo_path, ocr_confidence, ocr_raw_text}`, dikelompokkan lewat `titik_ke` yang sama. `type_b_komponen` yang mobile tulis sebelumnya salah nama field — yang bener `type_b_components` (komponennya `sumber`/`keterangan`/`distribusi`/`nilai`, dan `keterangan` udah diformat siap-tampil, jangan disusun ulang jadi kalimat sendiri).
>
> **`titik` cuma keisi setelah sesi lewat kalkulasi** (`disetujui` / `menunggu_approval` yang udah diproses) — mobile nganggep array kosong `[]` buat `draft`, dan nampilin pesan "belum dihitung" bukan tabel kosong.
> - **`sertifikat`** (bukan `certificate_id` doang) — objek `{id, nomor, status, pdf_url}` embed langsung di detail sesi, `pdf_url` cuma keisi kalau `status: "terbit"`. Mobile masih manggil `GET /api/certificates/{id}` terpisah lewat `approval_service.dart` (belum dipindah ke sini) — dua-duanya jalan, tapi kalau mau lebih hemat 1 request, tinggal pakai field ini.
> - **`measurements[].standard_id`** (per titik, opsional) — buat kategori yang butuh standar BEDA per titik ukur, kayak pH (buffer 4/7/10 masing-masing sertifikatnya sendiri, lihat `SERTIFIKAT.csv` di worksheet asli). ✅ **Mobile sekarang ngirim ini** — `ph_calibration_input_screen.dart` punya dropdown "Standar Acuan (Termometer & Sensor)" buat sesi (kondisi lingkungan) + dropdown standar buffer terpisah di tiap kartu titik (4/7/10), masing-masing kekirim sebagai `measurements[i].standard_id`.
> - **`measurements[].pembacaan_sebelum`** (per titik, opsional, array angka) — pembacaan **as-found** (sebelum alat di-adjustment). ✅ **Live sejak 20 Jul.** Murni dokumentasi kondisi alat, **TIDAK ikut** `GumCalculator::hitungTitik()` — cuma disimpan ke `raw_measurements` dengan `tahap: sebelum_adjustment` (lawannya `sesudah_adjustment`, yang dipakai buat hitungan resmi). Nggak ada minimum jumlah pembacaan (beda dari `pembacaan` utama yang wajib ≥2). Ikut balik di `GET /api/calibrations/{id}` lewat `pembacaan_mentah[].tahap`.
> - **`client_request_id`** (opsional, UUID) — idempotency key buat retry submit yang aman kalau koneksi putus pas nunggu respons. ✅ **Mobile sekarang ngirim ini** — di-generate sekali (`generateUuidV4()`, `lib/core/utils/uuid.dart`) waktu layar input dibuka, dipakai ulang tiap tap tombol kirim/simpan draft di sesi form yang sama.
> - **`lokasi`** sekarang enum `lab` / `onsite` (default `lab`), bukan teks bebas. ✅ **Mobile sekarang punya field-nya** — dropdown "Lokasi Kalibrasi" (Lab / Onsite) di kedua layar input (generik & pH).
> - **`hasil` itu ringkasan dari titik PENENTU**, bukan titik pertama. Sesi bisa punya banyak titik ukur tapi sertifikat cuma nampilin satu keputusan — yang dipajang adalah titik yang paling mepet ke batas (|error| + U terbesar). **Satu titik FAIL bikin seluruh sesi FAIL.**
>
> ### Soal `?mine=true`
> Teknisi **selalu** cuma dapat sesi miliknya sendiri — nggak peduli query param-nya diisi apa. `mine=false` bukan pintu belakang. Param `mine=true` cuma berfungsi buat **admin & viewer** yang mau nyaring punya sendiri. Ada testnya.

### `GET /api/calibrations/lembar-kerja?profil=...` — bentuk per jenis alat

✅ **Live (Turbidimeter, Agu).** Bentuk lembar kerja beda per jenis alat.
`?profil=turbidimeter` (atau `?instrumen=Turbidimeter`) balikin lembar NTU
(titik 1/100/1000, resolusi per-titik di `bagian[].tabel[].baris[].desimal`).
Tanpa param = pH (default — mobile lama nggak berubah). Detail rumus & arsitektur
profil: `docs/SPEC-turbidimeter-profile.md`.

### Lembar **Timbangan** (`profil=timbangan`) — alat ke-21, kelompok Massa

✅ **Live 31 Agt 2026.** Satu-satunya lembar yang bentuknya **tujuh blok**, bukan satu tabel
titik. Handoff lengkap: `docs/perintah-frontend-timbangan.md`.

Yang beda dari dua puluh lembar lain, dan yang wajib dibaca sebelum menggambar layarnya:

- **Dua bagian ber-`tabel`.** `akurasi` (10 baris × 4 kolom) dan `keterulangan` (2 baris × 10
  pengulangan × **2 sub-kolom**). Bentuknya sama dengan lembar TIDS & ketiga alat suhu, jadi
  widget tabel yang ada bisa dipakai ulang — yang baru cuma sub-kolom kedua di keterulangan.
- **Empat kolom `akurasi` BUKAN pengulangan.** `pengulangan_arah` melabelinya `z`, `m`, `m'`,
  `z'` — nol sebelum, dua pembacaan berbeban, nol sesudah. Jangan ditulis "Pengulangan 1..4".
- **Empat dropdown menentukan ANGKA**, bukan tampilan: `tipe_timbangan`
  (`Non-Analytical`/`Analytical` — memilih tabel anak timbangan F1 vs E2), `varian_master`
  (`kg`/`gram`/`substitusi` — memilih revisi master), `tipe_display`, `jenis_timbangan`.
  Pilihannya dikirim server; **jangan dipetakan ulang di HP.**
- **Blok tingkat-sesi lewat `spesifikasi_alat`**, bukan `measurements`: `keterulangan`,
  `eksentrisitas`, `histeresis`, plus `varian_master`/`tipe_display`/`tipe_timbangan`/`satuan`.
- **`measurements[].titik_ukur` tetap wajib** (jumlah nominal keping, = kolom `Nominal` kertas).
  Massa konvensionalnya diturunkan server dari tabel anak timbangan, jangan dikirim dari HP.
- **`spesifikasi_alat.keterulangan` / `.eksentrisitas` / `.histeresis` berbentuk OBJEK**, bukan
  teks — kunci `spesifikasi_alat` lain tetap teks pendek (maks 64 karakter).
- **`measurements[].nominal` urutannya MENGIKAT** — Mass 1..6 kolom-major. Slot pertama dapat
  `ci` = 10 di varian substitusi; keping yang mendarat di slot salah menggeser budget tanpa error.
- **DUA ketidakpastian per titik** (NMI Monograph 4). `ketidakpastian_diperluas` = U95% of
  **Correction** (kolom Correction, bagian 3 sertifikat). U95% of **Weighing** (bagian 7) ikut di
  `type_b_components` sebagai baris ber-`sumber: "u95_penimbangan"`. Tiap baris budget bawa
  `budget: "koreksi" | "penimbangan"` — nama komponennya memang bertabrakan antar-budget.
- **Belum ada jalur kamera** (`bentuk_pindai_foto.didukung = false`) dan **belum ada vonis
  PASS/FAIL** (`punyaToleransi()` = false; batas keberterimaannya MPE kelas SNSU PK.M-02:2021
  yang butuh nilai `e` dari teknisi). Jangan gambar tombol foto atau chip lulus/tidak.
- **`kode_dokumen` null** — kertas lembar kerjanya belum pernah dikirim lab; ketiga workbook cuma
  memuat `SIDIK-FM-CAL-2403_Rev. 0`, formulir SERTIFIKAT bersama.

Tiga sesi contoh sudah ter-seed: `011-CAL-525` (kg), `019-CAL-425` (gram, Analytical),
`0136-CAL-123` (substitusi 2000 kg).

### `status_standar` di respons sesi — banner kepala lembar kerja

✅ **Live 25 Jul.** Ikut di `GET /api/calibrations` & `GET /api/calibrations/{id}`.

```json
"status_standar": {
  "ringkasan": "expired",
  "pesan": "ONE OR MORE STANDARD EXPIRED",
  "standar": [
    { "id": 10, "nama": "pH Buffer Solution 7", "status": "valid", "hari_menuju_kadaluarsa": 190 },
    { "id": 12, "nama": "Termometer & Sensor Std.", "status": "expired", "hari_menuju_kadaluarsa": -3 }
  ]
}
```

- **`ringkasan` itu status TERBURUK** dari semua standar yang kepakai di sesi itu:
  standar default sesi, thermohygro, standar per titik ukur (buffer 4/7/10
  masing-masing punya sertifikat sendiri), dan baris Usage Check. Satu yang
  kadaluarsa udah cukup nahan penerbitan sertifikat, jadi banner-nya nggak boleh
  kalem cuma karena standar lain masih valid.
- **`pesan` udah siap tampil** — jangan disusun ulang di mobile, biar kalimatnya
  sama sama yang tercetak di lembar kerja. **`null` = nggak usah nampilin banner
  sama sekali.**
- **`standar[]`** buat badge per baris di bagian PENGERJAAN. Standar yang kepakai
  dari dua jalur (mis. standar default sesi juga kepakai di titik pertama) cukup
  muncul sekali.

### `GET /api/standards` — isi dropdown "Standar Acuan"

✅ **Live sejak 14 Jul.** Baca: semua role (termasuk viewer). Nggak pakai paginasi — daftar standar lab itu pendek, jadi langsung kekirim semua (sama kayak `/categories`).

```json
{
  "data": [
    {
      "id": 1,
      "nama": "Gauge Block Set Grade 0",
      "merk": "Mitutoyo",
      "model": "516-905",
      "serial_number": "GB-STD-001",
      "no_sertifikat": "SNSU/2025/P-0142",
      "tertelusur_ke": "SNSU-BSN",
      "berlaku_sampai": "2027-07-13",
      "masih_berlaku": true,
      "ketidakpastian": 0.0004,
      "satuan_ketidakpastian": "mm",
      "faktor_cakupan": 2,
      "drift": null
    }
  ]
}
```

> ## ✅ 25 Jul — `status_kalibrasi` & `hari_menuju_kadaluarsa` (worksheet-ph §2.1)
>
> `masih_berlaku` (bool) nggak cukup buat kepala lembar kerja: worksheet-nya punya
> state **TENGAH** — "mau kadaluarsa". Sekarang tiap standar ikut bawa:
>
> ```json
> "status_kalibrasi": "valid",          // valid | warning | expired
> "hari_menuju_kadaluarsa": 23
> ```
>
> - **`hari_menuju_kadaluarsa` BERTANDA**: positif = sisa hari, `0` = habis hari
>   ini, **negatif = udah lewat** (`-12` artinya kadaluarsa 12 hari lalu). `null`
>   kalau standar itu nggak punya tanggal berlaku — beda artinya dari `0`.
> - **Ambang `warning` ditentukan BACKEND**, per organisasi
>   (`organization.settings.reminder_hari_sebelum`, default **30 hari**) — knob yang
>   sama dipakai reminder alat jatuh tempo. Sengaja satu sumber: kalau mobile
>   nentuin ambangnya sendiri, dia bisa nampilin VALID buat standar yang nanti
>   ditolak backend waktu approve, dan teknisi baru tahu setelah kerjaannya kelar.
> - ⚠️ **`status_kalibrasi` yang jadi pegangan, BUKAN `hari_menuju_kadaluarsa`.**
>   Standar yang habis HARI INI balik `hari_menuju_kadaluarsa: 0` tapi
>   `status_kalibrasi: "expired"` — konsisten sama `masih_berlaku` dan sama aturan
>   yang nolak `POST /calibrations` dengan `422`. Jangan nurunin status sendiri
>   dari angka harinya; pakai angkanya buat teks doang ("lewat 12 hari").

> **Pakai `masih_berlaku`, jangan banding-bandingin `berlaku_sampai` sendiri** — gampang salah zona waktu. Standar yang `masih_berlaku: false` **ditolak `422`** kalau dipakai kalibrasi (ketertelusurannya putus), jadi jangan dibikin bisa dipilih di dropdown.
>
> Standar kadaluarsa **tetap ikut kekirim**, sengaja — kalau disembunyiin, teknisi yang nyari standar yang biasa dia pakai bakal ngira datanya kehapus, padahal cuma perlu dikalibrasi ulang. Kalau mau yang bersih aja: **`GET /api/standards?berlaku_saja=true`**.
>
> `ketidakpastian` itu nilai **diperluas** (udah dikali `faktor_cakupan`), persis kayak yang tertulis di sertifikat standarnya. Backend yang bagi balik waktu ngitung Type B — **mobile cukup nampilin apa adanya, jangan diutak-atik.**
>
> Ada juga **`GET /api/standards/{id}`** kalau butuh satu objek.
>
> **✅ 18 Jul — `POST`/`PUT`/`DELETE /api/standards` ternyata udah ada** (admin doang, dijaga `role:admin`) — dokumen ini ketinggalan, mobile baru sadar pas ngecek `StandardController.php` langsung. Layar kelola Standar Acuan (list + form CRUD) sekarang ada di app (Profil → Standar Acuan admin), dan field `model` yang sebelumnya kelewat di model mobile sekarang ikut ditangkep.

### `POST /api/calibrations`
Bikin sesi kalibrasi + kirim data mentah sekaligus. **Data dari input manual dan dari hasil scan kamera masuk ke endpoint yang sama persis** — nggak usah bikin endpoint terpisah buat OCR. Bedanya cuma di field `input_method` (buat statistik, bukan buat logic beda).

```json
{
  "equipment_id": 12,
  "kategori": "panjang",
  "input_method": "manual",
  "tanggal_kalibrasi": "2026-07-14T09:00:00Z",
  "suhu_ruang": 23.5,
  "kelembaban": 55.0,
  "measurements": [
    { "titik_ukur": 50.0, "pembacaan": [50.02, 50.01, 50.03], "satuan": "mm" },
    { "titik_ukur": 100.0, "pembacaan": [100.05, 100.04, 100.05], "satuan": "mm" }
  ]
}
```
`input_method`: `manual` atau `ocr`.

Response `201` — balikin sesi yang udah kehitung (lihat bentuknya di bawah).

> ## ✅ 26 Agt — bentuk PASANGAN buat 3 alat suhu baru (+ TIDS, 28 Agt)
>
> **Thermocouple · Termometer Gelas · Thermohygrometer** (alat ke-18, 19, 20) TIDAK
> memakai `measurements[].pembacaan`. **TIDS menyusul 28 Agt** — lihat blok
> khususnya di bawah. Keempatnya membaca **dua deret per titik** —
> probe standar lab dan UUT dicelup bersamaan ke dryblock/oilbath/chamber yang
> sama, lalu dibaca bergantian tiap 10 detik dalam satu sapuan 90 detik:
>
> ```
> 0″ standar · 10″ UUT · 20″ standar · 30″ UUT · … · 80″ standar · 90″ UUT
> ```
>
> Jadi nilai standarnya **data sesi**, bukan konstanta dari master `standards`
> seperti buffer pH 4,01. Kirim dua-duanya:
>
> ```json
> {
>   "equipment_id": 31,
>   "standard_id": 7,
>   "tipe_sensor": "Type K",
>   "alat_bantu": "A",
>   "measurements": [
>     { "titik_ukur": 50,  "no_probe": 1, "standar": [49.5, 49.5, 49.5, 49.5, 49.5], "uut": [49.9, 49.9, 49.9, 49.9, 49.9] },
>     { "titik_ukur": 100, "no_probe": 2, "standar": [99, 99, 99, 99, 99],           "uut": [99.9, 99.9, 99.9, 99.9, 99.9] }
>   ]
> }
> ```
>
> **Cara tahu lembar mana yang begini:** `GET /api/calibrations/lembar-kerja`
> memulangkan `bagian[].tabel[]` yang tiap elemennya punya **`peran`**
> (`standar` / `uut`). Lembar datar tidak punya `peran`. Jangan hardcode daftar
> kode profil — baca `peran`.
>
> **`grup` tidak selalu ada.** Tiga lembar pasangan pertama (Thermocouple,
> Termometer Gelas, Thermohygrometer) mengisinya; **TIDS tidak mengirim kuncinya
> sama sekali** — hilang dari JSON, bukan berisi `null`. Identitas tabel TIDS
> dipegang `tahap` (`pembacaan_standard` / `pembacaan_uut`), karena kunci sel
> berkas geometri OCR-nya dibangun dari situ dan kertasnya sudah tercetak. Jadi
> yang membaca wajib punya jalur cadangan kunci-hilang (`kunciTabel` di HP jatuh
> ke `peran`, dan itu tetap unik) — bukan cuma pemeriksaan `=== null`.
>
> ### `tipe_sensor` vs `tipe_thermocouple` — dua hal, dan gampang ketuker
>
> Namanya mirip, letaknya beda, dan yang satu menggerakkan angka sementara yang
> satu tidak.
>
> | | `tipe_sensor` | `spesifikasi_alat.tipe_thermocouple` |
> |---|---|---|
> | Milik siapa | sensor **acuan lab** | alat **pelanggan** yang dikalibrasi |
> | Di kertas | blok *Pengerjaan* | blok *Identitas Alat dan Data Customer* |
> | Pilihan | 3 (yang lab punya sertifikatnya) | 10 (sesuai `SIDIK-FM-CAL-0535_Rev.2`) |
> | Efek ke angka | **memilih tabel koreksi** + 2 komponen budget | **nol** |
> | Di sertifikat | tercetak di tabel standar | tidak tercetak |
>
> Di master dua-duanya ada dan sering bernilai sama — `INPUT DATA!E4`
> (`Sensor Type (UUT)`) dan `INPUT DATA!O23` (`Standar Sensor`) sama-sama `2` di
> sesi contoh, dua-duanya Type K. Kebetulan itu yang bikin gampang disangka satu
> kolom.
>
> **Jangan memanjangkan `tipe_sensor` jadi sepuluh** cuma karena kertas UUT-nya
> sepuluh: lab cuma punya sertifikat & ketertelusuran buat RTD, Type K, dan
> Type N. Tujuh sisanya di `DATABASE!Q23:Q27` memang tercantum tanpa nomor seri
> dan tanpa ketertelusuran — lab tidak memilikinya.
>
> ### Field per alat
>
> | Field | Alat | Isi | Kalau kosong |
> |---|---|---|---|
> | `tipe_sensor` | Thermocouple | `RTD` · `Type K` · `Type N` | angkanya DITAHAN, alasannya di `belum_dihitung` |
> | `spesifikasi_alat.tipe_thermocouple` | Thermocouple | `Type K/N/S/J/B/E/T/R` · `RTD/PT100` · `Lainnya` | tidak menahan apa pun — catatan kerja |
> | `spesifikasi_alat.tipe_thermocouple_lain` | Thermocouple | teks bebas, cuma waktu pilihannya `Lainnya` | idem |
> | `alat_bantu` | Thermocouple | `A` (Isotech, −20…150 °C) · `B` (Techne, 150…600 °C) | idem |
> | `alat_bantu` | Termometer Gelas | `satu` · `dua` (dua oilbath) | idem |
> | `measurements[].no_probe` | Thermocouple | Type K → 1–16 · Type N → **3–12** · RTD → 17 | titik itu diblokir |
> | `tipe_pencelupan` | Termometer Gelas | `Partial` / `Total` / `Complete Immersion` | cuma peringatan (tercetak di sertifikat) |
> | `titik_es` | Termometer Gelas | array 3 angka, uji titik es 30 menit | komponen budget-nya dihitung nol |
> | `measurements[].parameter` | Thermohygro | `suhu` / `kelembaban` | dianggap `suhu` |
> | `tipe_sensor` | **TIDS** | `RTD` · `Type K` · `Type N` | angkanya DITAHAN |
> | `alat_bantu` | **TIDS** | `A` (Isotech) · `B` (Techne) | angkanya DITAHAN |
> | `measurements[].no_probe` | **TIDS** | Type K → 1–16 · Type N → **3–12** · RTD → 17 | titik itu diblokir |
> | `titik_es` | **TIDS** | array 2 angka (Awal & Akhir uji titik es 0 °C) | komponen `Drift UUT` jadi nol + peringatan sesi |
>
> **`no_probe` penomorannya BEDA per tipe** dan itu dari kertasnya sendiri: *"If
> using Thermocouple Type N, No. Thermocouple START FROM 3. If using PRT PT100
> (RTD), No. Thermocouple ALL 17."* Dropdown-nya sudah dikirim bentuk lembar kerja
> lengkap dengan `grup` = nama tipe sensornya, jadi saring dari situ — jangan
> ditulis ulang di HP.
>
> ### 🆕 28 Agt — TIDS ikut jalur pasangan, dan `pembacaan` datar DIPENSIUNKAN
>
> Lembar **Temperatur Indikator dengan Sensor** (`SIDIK-FM-CAL-0506 Rev.4`) pindah
> ke jalur ini begitu dua workbook master TIDS turun dari lab. Yang berubah buat
> HP:
>
> | | Sebelum | Sesudah |
> |---|---|---|
> | Tabel `Pembacaan Standard` | `simpan_ke: null` — **35 kotak yang diisi teknisi nggak pernah nyampe server** | `measurements[].standar` |
> | Tabel `Pembacaan Alat` | `measurements[].pembacaan` | `measurements[].uut` |
> | `tabel[].peran` | tidak ada | `standar` / `uut` |
> | Kolom per baris | tidak ada | `kolom_baris`: `no_probe` (No. Termokopel) |
> | Uji titik es | `spesifikasi_alat.titik_es_awal` / `.titik_es_akhir` | `titik_es_1` / `titik_es_2` → kolom sesi `titik_es` |
> | `budget_ketidakpastian.tersedia` | `false` | **`true`** |
> | `sumbu_uut.keputusan_skema` | `belum_diambil` | **`lima_ulangan`** |
>
> **Payload lama tetap tersimpan.** Server memindahkan `measurements[].pembacaan`
> ke deret `uut` kalau `standar`/`uut` dua-duanya kosong — jadi APK yang belum
> diperbarui nggak kehilangan kerja lapangannya. Yang HILANG tetap deret
> standarnya (memang nggak pernah dikirim), dan tanpa itu sesinya nggak kehitung.
>
> **Lima kolom itu lima ULANGAN, bukan lima UUT.** Kepala kolom di kertas berbunyi
> `0" (UUT1)`…`90" (UUT5)` dan dulu dibaca sebagai lima alat pelanggan dalam satu
> lembar. Dua workbook master menamai kolom yang sama `PRT1`…`PRT5` lalu
> memakainya `AVERAGE`+`STDEV` per baris. Label cetaknya **tidak diubah** (itu
> jangkar sumbu mendatar jalur foto); yang berubah artinya, dan artinya dikirim di
> `sumbu_uut.keputusan_skema` + `sumbu_uut.daftar[].label_master`.
>
> **Tiga baris `Standard used`, bukan dua.** Baris ketiga Temperature Recorder
> Graptech GL840 (s/n `C305B1470`) — keluarga standar itu yang menentukan tabel
> koreksi mana yang dipakai (`recorder` per KANAL, `constant`/`yokogawa` per tipe
> sensor). Server menurunkannya dari `standard_id` yang dipilih; HP nggak perlu
> mengirim kode keluarganya.
>
> **Peringatan sesi baru yang wajib ditampilkan:**
> `tids_master_recorder_sel_tetap` & `tids_master_tiga_komponen_tidak_dijumlah` —
> dua-duanya menyebut penyimpangan workbook master yang ditiru apa adanya, dan
> tiga dari empat penyimpangan itu bikin U95 lebih KECIL. Ini yang menahan tombol
> APPROVE sampai ada manusia yang membacanya.
>
> **Thermohygro tidak punya `alat_bantu`.** Chamber-nya (Biobase ≥ 50 %RH / GEA
> < 50 %RH) diturunkan SERVER dari set point, karena satu sesi memakai dua-duanya
> sekaligus dan masing-masing punya U95 sendiri. Tabel standar kelembapan membawa
> `chamber_per_baris` supaya HP bisa menuliskannya di sebelah set point.
>
> ### Yang balik di `GET /api/calibrations/{id}`
>
> `alat_bantu`, `tipe_pencelupan`, `titik_es`, dan `pembacaan_mentah[].peran_sensor`
> + `.sensor_ke` semuanya ikut. Itu yang bikin sesi yang dikembalikan admin pulang
> UTUH — tanpa `peran_sensor`, HP tidak punya cara tahu angka mana milik deret yang
> mana, dan teknisi mengetik ulang dua tabel penuh.
>
> Plus **`alat_bantu_label`** — nama unitnya, bukan kodenya:
>
> ```json
> { "alat_bantu": "A", "alat_bantu_label": "A — Isotech Fast Cal Low (−20…150 °C)" }
> ```
>
> Ada karena dua pembaca respons ini butuh hal berbeda. Lembar kerja yang dibuka
> lagi butuh `alat_bantu` mentah — dropdown-nya mencocokkan `nilai`, dan nama tiap
> pilihan sudah datang bareng `GET /api/calibrations/lembar-kerja`. Layar detail
> admin tidak memuat template itu, jadi di sana `A` tetap `A`.
>
> **Jangan salin peta kode→nama ke HP.** Daftarnya bertambah begitu lab membeli
> dryblock baru, dan peta yang tertinggal menampilkan kode mentah tanpa satu pun
> error — di layar yang justru dipakai memutuskan penerbitan sertifikat. `null`
> untuk alat yang tidak punya alat bantu (termasuk Thermohygro) dan untuk kode
> yang tidak dikenal profilnya.
>
> **U95-nya SATU per sesi** (Thermohygro: satu per grup parameter+chamber), dicetak
> sebagai baris di bawah tabel — bukan kolom per titik. `desimal_u95` tetap dibaca
> dari respons seperti biasa.

> ## ✅ 2 Sep — TEBAKAN MESIN per sel (`*_ocr`), buat mengukur akurasi kamera
>
> Kunci OPSIONAL. Lembar yang seluruhnya diketik tangan tidak mengirimnya sama
> sekali, dan tanpa kunci ini perilaku server sama persis seperti sebelumnya.
>
> ### Kenapa ada
>
> Teknisi mengoreksi angka hasil foto **di kotak yang sama**. Begitu dia mengetik
> ulang, tebakan mesinnya tertimpa — dan yang sampai server cuma angka akhir.
> Akibatnya akurasi kamera **tidak bisa dihitung sama sekali**, termasuk metrik
> yang paling menentukan: sel yang keisi otomatis dengan keyakinan tinggi padahal
> salah. Itu satu-satunya kegagalan yang tidak ada yang lihat sampai sertifikatnya
> terbit.
>
> Yang membacanya `php artisan ocr:akurasi-kamera`.
>
> ### Bentuk dasarnya
>
> ```json
> { "raw_text": "5O.O2", "confidence": 0.93 }
> ```
>
> - `raw_text` — teks **APA ADANYA** dari pengenal, maksimal 255 karakter.
>   Jangan dibersihkan, jangan ditambah koma, jangan ditebak desimalnya.
>   Yang jelas ngawur justru contoh paling berharga.
> - `confidence` — 0..1, **boleh tidak ada**. ML Kit cuma menyetel skor di
>   sebagian versi & perangkat. Yang tidak diketahui **jangan diisi angka
>   karangan**: kunci `confidence`-nya dihilangkan saja. Skor karangan persis
>   yang bikin sel salah divonis hijau.
>
> ### Enam bentuk, ikut bentuk deret nilainya
>
> | Lembar | Kunci | Bentuk |
> |---|---|---|
> | ~13 lembar titik × Repeat | `measurements[].ocr` | deret, sejajar `pembacaan` |
> | Thermocouple, T. Gelas, Thermohygro, **TIDS** | `measurements[].standar_ocr` · `.uut_ocr` | deret, sejajar deret sisinya |
> | Timer/Stopwatch | `measurements[].standar_ocr` · `.uut_ocr` | deret berisi **objek per kotak**: `{jam: {...}, menit: {...}, detik: {...}, milidetik: {...}}` |
> | 5 Enclosure (grid) | `measurements[].sensor_grid[].ocr` | deret, sejajar `pembacaan` baris itu |
> | 5 Enclosure (dua baris deret) | `measurements[].indikator_ocr` · `.suhu_ruang_ocr` | deret, sejajar deret angkanya |
> | Timbangan (blok Repeatability) | `spesifikasi_alat.keterulangan.baris[].<kolom>_ocr` | deret, sejajar `<kolom>` |
> | Autoklaf (`POST /calibrations/autoclave`) | `ocr.<jalur nilainya>` | bercermin ke jalur nilainya, berawalan `ocr.` |
>
> ### Aturan yang MENGIKAT
>
> 1. **Panjangnya selalu sepanjang deret nilainya**, termasuk `null`-nya. Server
>    membaca `$ocr[$urutan]` dengan indeks `pembacaan` yang **sama persis**, jadi
>    deret yang dirapatkan bikin tebakan Repeat 3 tercatat sebagai tebakan
>    Repeat 1 — pasangan yang salah, tanpa satu pun error.
> 2. **Sisi standar & sisi UUT tidak boleh tertukar.** Yang tercetak di sertifikat
>    `Correction`, yaitu SELISIH keduanya; tebakan yang tertukar sisi menggeser
>    selisih itu sementara kedua angkanya tetap wajar.
> 3. **Jangan kirim kunci kosong.** Deret berisi `null` semua bikin pembacanya
>    mengira ada jalur kamera di kolom itu. Satu set point grid bisa punya 40
>    baris; kunci kosong di tiap baris itu beban yang tidak dibayar apa pun.
> 4. **Timer/Stopwatch: jangan gabungkan empat kotak jadi satu teks.** Server yang
>    menyusunnya jadi satu penunjukan, lewat jalur yang **sama** dengan yang
>    menyusun nilai finalnya. Kalau HP yang menyusun, dua sisi memakai jalan
>    berbeda — dan begitu salah satunya berubah, yang diadu bukan lagi dua besaran
>    yang sama.
>
> ### Yang ditolak server
>
> | Keadaan | Akibat |
> |---|---|
> | Kotak Timer yang tidak dikenal (mis. `milidetk`) | **422** |
> | `confidence` di luar 0..1, `raw_text` > 255 karakter | **422** |
> | Timer: ada kotak berisi yang tidak ketebak | tebakannya **dibuang** — menyusun dari sebagian berarti mencampur tebakan mesin dengan ketikan teknisi |
> | Timer: tebakan bukan angka (mis. `1S`) | **ditolak**, tidak dibulatkan. `(int) '1S'` di PHP itu `1` — diam dan salah |
>
> ### Efek sampingnya ke gerbang verifikasi
>
> Baris yang **membawa tebakan** lahir `is_verified: false` dan
> `input_source: "ocr"` — **walaupun `input_method` sesinya `manual`**. Ini
> disengaja: sebelumnya beberapa jalur cuma punya satu gerbang (`input_method`),
> jadi baris yang benar-benar dari kamera lolos jadi terverifikasi begitu sesinya
> tercatat manual, dan gerbang approve tidak pernah bunyi.
>
> Konsekuensi buat HP: sesi yang mengirim tebakan **wajib** melewati
> `POST /calibrations/{id}/measurements/verify` sebelum bisa disetujui admin.

### 4a. `POST /api/calibrations/preview` — hitung sambil ngetik

✅ **Live 25 Jul.** Diminta di `permintaan-worksheet-ph.md` §4. Admin & teknisi;
viewer `403`. Rate limit **120/menit per IP** (dipanggil tiap selesai satu baris,
bukan sekali per sesi).

**Body-nya SAMA PERSIS kayak `POST /api/calibrations`** — kirim aja draft yang
sedang diisi. Nggak perlu payload kedua, nggak perlu field tambahan.
`client_request_id` boleh ikut, diabaikan (preview bukan submit).

**Nggak nyimpen apa pun**: nggak ada baris sesi/pembacaan/hitungan, nggak makan
nomor sesi, nggak ngirim notifikasi, nggak nyiarin sinyal realtime. Aman dipanggil
tiap ketukan.

Response `200`:

```json
{
  "data": {
    "keputusan": "PASS",
    "hasil": {
      "rata_rata": 7.03, "error": 0.03,
      "ketidakpastian_gabungan": 0.01055448,
      "faktor_cakupan_k": 2, "ketidakpastian_diperluas": 0.02110895,
      "keputusan": "PASS"
    },
    "titik": [ { "titik_ke": 1, "rata_rata": 7.03, "error": 0.03, "koreksi": -0.03, "…": "…" } ],
    "lembar_perhitungan": [
      { "tahap": "sebelum_adjustment", "judul": "Before Adjustment Reading",
        "titik": [ { "titik_ke": 1, "standard": 7.0021, "average": 7.11, "correction": 0.1079, "stdev": 0.01414, "…": "…" } ],
        "max_stdev": 0.01414 },
      { "tahap": "sesudah_adjustment", "judul": "After Adjustment Reading", "titik": [ "…" ], "max_stdev": 0.01 }
    ],
    "kondisi_lingkungan": {
      "suhu": { "awal": 22.2, "akhir": 22.4, "average": 22.3, "delta": 0.2, "u95_sertifikat": 0.2, "nilai_terkoreksi": 22.3, "satuan": "°C" },
      "kelembaban": { "…": "…" },
      "thermohygro": null, "thermohygro_serial": null
    },
    "belum_dihitung": [
      { "titik_ke": 3, "alasan": "Baru 1 pembacaan terisi, minimal 2 — standar deviasi nggak bisa dihitung dari satu angka." }
    ]
  }
}
```

**Yang penting dipahami soal bentuknya:**

- **`hasil` & `titik` sama arti + sama bentuk kayak di `GET /api/calibrations/{id}`.**
  Dua-duanya lewat helper yang sama di backend, jadi **parser yang udah ada bisa
  dipakai ulang apa adanya**. `hasil` = ringkasan titik penentu (yang paling mepet
  batas toleransi), bukan gabungan semua titik.
- **`lembar_perhitungan` = `data.hasil`-nya `GET /api/calibrations/{id}/perhitungan`**,
  byte-per-byte. Namanya beda **sengaja**: di detail sesi, key `hasil` artinya
  ringkasan titik penentu. Satu nama dua arti itu jebakan, jadi di sini dipisah.
  Isinya dua tabel (Before/After adjustment) lengkap sama baris Average,
  Correction, STDEV, dan MAX STDEV — persis kolom yang dihitung Excel-nya lab.
- **`belum_dihitung`** ngasih tahu KENAPA satu titik belum keluar angkanya. Tanpa
  ini, titik yang nggak muncul di `titik` kelihatan kayak bug. Alasannya sudah
  berupa kalimat siap tampil — jangan disusun ulang di mobile.
- **`keputusan` di top-level** sama nilainya sama `hasil.keputusan`. Ada di luar
  supaya tetap kebaca waktu `hasil` masih `null` (belum ada titik yang kehitung).
- `titik` **cuma berisi titik yang bisa dihitung**. Titik yang belum cukup datanya
  pindah ke `belum_dihitung`, jadi `titik` bisa lebih pendek dari `measurements`.

> **Angkanya dijamin identik sama yang nanti tersimpan** — bukan "mirip". Preview
> dan submit mutar fungsi yang sama, dan pembulatan ke presisi kolom DB dilakuin
> sebelum keduanya, jadi nggak ada selisih di desimal jauh. Ada test yang ngirim
> payload sama ke `/preview` dan ke `/calibrations` terus ngebandingin field per
> field (`CalibrationPreviewTest`). Ini yang bikin aman nampilin angkanya ke
> teknisi: yang di layar sama sama yang bakal tercetak di sertifikat.
>
> Makanya **jangan hitung Average/Correction/STDEV sendiri di mobile** walau
> rumusnya kelihatan sepele. Bukan soal susah — soal `standard` di lembar
> perhitungan itu nilai buffer pada SUHU LARUTAN saat itu (4,0092252 di 22,2 °C),
> bukan nominal 4,00, dan diturunkan dari persamaan di sertifikat buffer.

### `GET /api/calibrations` · `GET /api/calibrations/{id}`
```json
{
  "data": {
    "id": 88,
    "equipment": { "id": 12, "nama_alat": "Jangka Sorong Mitutoyo" },
    "teknisi": { "id": 4, "nama": "Andi" },
    "tanggal_kalibrasi": "2026-07-14T09:00:00Z",
    "status": "menunggu_approval",
    "hasil": {
      "rata_rata": 50.02,
      "error": 0.02,
      "ketidakpastian_gabungan": 0.0031,
      "faktor_cakupan_k": 2.0,
      "ketidakpastian_diperluas": 0.0062,
      "keputusan": "PASS"
    },
    "catatan_revisi": null,
    "certificate_id": null
  }
}
```

> **`status` wajib salah satu dari: `draft` / `menunggu_approval` / `disetujui` / `perlu_revisi`.**
> **`keputusan`: `PASS` atau `FAIL`** (huruf besar). Ingat: **FAIL tetap boleh terbit sertifikat**, statusnya aja beda — jangan diblokir di backend.
> Perhitungan GUM (Type A + Type B → gabungan → `U = k × u_c`) dan keputusan ILAC-G8 **dihitung di backend**, mobile cuma nampilin. Mobile nggak ngitung apa pun.

> ## ✅ 25 Jul — objek embed digemukin (permintaan-endpoint.md §5c–5e)
>
> Layar pencocokan sertifikat dulu kepaksa nembak tiga endpoint tambahan cuma
> buat ngisi kepala dokumen. Sekarang ikut di `GET /api/calibrations/{id}`:
>
> ```json
> "teknisi": {
>   "id": 4, "nama": "Dwi Rahayu",
>   "employee_id": "SDK-2001",     // buat login
>   "kode_teknisi": "DR",          // <- INI yang dicetak di kolom "Technician ID"
>   "department": "Kalibrasi"
> },
> "reviewer": { "id": 1, "nama": "Alex Misramto", "kode_teknisi": "AM" },
> "standar_acuan": {
>   "id": 10, "nama": "pH Buffer Solution 7", "no_sertifikat": "HC46341939",
>   "merk": "Supelco", "model": "Merck",
>   "merk_type": "Supelco/Merck",   // kolom "Merk/Type" digabung backend
>   "serial_number": "HC46341939",
>   "tertelusur_ke": "Merck KGaA"
> },
> "sertifikat": {
>   "id": 9, "nomor": "012-CAL-524", "status": "terbit", "pdf_url": "...",
>   "diterbitkan_pada": "2024-05-30",
>   "berlaku_sampai": "2025-05-29",
>   "qr_token": "DEMOQR123",
>   "qr_payload": "https://.../verify/DEMOQR123"
> }
> ```
>
> - ⚠️ **Kolom "Technician ID" di lembar kerja & sertifikat isinya `kode_teknisi`
>   (`DR`), BUKAN `employee_id` (`SDK-2001`).** Contoh di `permintaan-endpoint.md`
>   §5c nulis `"employee_id": "DR"` — itu ketuker. Kalau `kode_teknisi` belum
>   diisi, backend jatuh ke inisial nama; lebih baik inisial daripada kolom kosong
>   di dokumen resmi.
> - **`reviewer` = "Checked by"** — admin yang approve/reject sesi. `null` selama
>   sesinya belum diperiksa.
> - **`merk_type` digabung di backend** (`merk` + `model`, dipisah `/`, yang kosong
>   dilewat) supaya dua klien nggak bikin dua gaya penulisan buat satu kolom di
>   dokumen resmi. Bagian mentahnya tetap ikut kalau mau dirender sendiri.
> - **`standar_acuan` bentuknya SAMA di level sesi dan di tiap `titik[]`** — satu
>   parser cukup.
> - **`qr_payload` udah URL siap di-render** jadi QR; jangan nyusun URL sendiri
>   dari `qr_token`, biar domainnya nggak bisa salah.
> - **Penanda tangan sertifikat BELUM ada** — masih nunggu keputusan apakah
>   "Manajer Teknis" itu role keempat atau atribut di user.
>
> ### 🔄 PERUBAHAN 26 Jul: semua field tanggal jadi TANGGAL POLOS
>
> **Yang berubah:** field bertipe tanggal sekarang keluar sebagai `"2024-05-30"`,
> **bukan lagi** `"2024-05-29T17:00:00Z"`.
>
> **Kenapa:** kolomnya cast `date` (nggak ada jamnya) dan `APP_TIMEZONE`-nya
> `Asia/Jakarta` — begitu diserialisasi ke UTC dia mundur 7 jam, ke jam 17:00
> **hari sebelumnya**. Jadi `diterbitkan_pada: "2024-05-29T17:00:00Z"` itu
> sebenernya **tanggal 30 Mei**. Konsumen yang ngambil 10 karakter pertama dapat
> tanggal salah sehari, dan nilainya nggak bisa dipakai balik jadi penyaring
> tanggal. Di sertifikat, tanggal terbit yang salah sehari itu cacat dokumen
> terkendali.
>
> **Field yang kena:**
>
> | Endpoint | Field |
> |---|---|
> | `GET /calibrations`, `/{id}` | `tanggal_kalibrasi`, `tanggal_terima` |
> | `GET /certificates/{id}` & embed `sertifikat` | `diterbitkan_pada`, `berlaku_sampai` |
> | `GET /equipments` | `tanggal_kalibrasi_terakhir`, `tanggal_jatuh_tempo` |
> | `GET /standards` | `berlaku_sampai` |
> | `GET /organization` | `akreditasi_mulai`, `akreditasi_berakhir` |
> | `GET /calibration-methods` | `berlaku_mulai` |
> | `GET /verify/{qr_token}` | `diterbitkan_pada`, `berlaku_sampai`, `tanggal_kalibrasi` |
> | `GET /laporan/kalibrasi` | `tanggal_kalibrasi` |
>
> **Yang TIDAK berubah:** `created_at`/`dibuat_pada`/`dibaca_pada` — itu `datetime`
> asli, jamnya beneran berarti, jadi tetap ISO `2026-07-26T09:10:00Z`.
>
> **Dampak buat mobile:** kecil. `DateTime.parse("2024-05-30")` di Dart tetap
> jalan, dan sekarang hasilnya **bener** tanpa perlu konversi zona waktu. Kode
> yang motong 10 karakter pertama juga jadi bener sendiri. Yang perlu diperiksa
> cuma kode yang menganggap string-nya **pasti** ada `T`/`Z`-nya (mis. regex ISO
> ketat, atau `substring(11)` buat ngambil jam).
>
> Dijaga `tests/Feature/FormatTanggalApiTest.php` biar nggak balik ke ISO.

### Riwayat
`GET /api/calibrations?mine=true` — teknisi cuma lihat kalibrasi miliknya sendiri; **admin lihat semua**. Filter ini penting, jangan sampai teknisi bisa lihat punya orang lain.

---

## 5. Approval & Sertifikat (dibutuhin Minggu 8)

### `POST /api/calibrations/{id}/approve` — **admin doang**, teknisi/viewer → `403`.
Response: sesi dengan `status: "disetujui"`. Generate sertifikat jalan di **queue** (async), jadi `certificate_id` boleh masih `null` sesaat.

Body-nya opsional semua:

```json
{ "berlaku_sampai": "2029-02-28", "abaikan_peringatan": false }
```

**`berlaku_sampai` — masa berlaku sertifikat, ditentukan admin (baru 26 Jul).**
Interval kalibrasi itu keputusan teknis: beda per jenis alat, seberapa sering
dipakai, dan permintaan pelanggan. Jadi angkanya milik admin, bukan dipaku di kode.

Tiga lapis, dari yang paling spesifik:

1. `berlaku_sampai` dikirim → dipakai apa adanya
2. nggak dikirim → **default organisasi**, `settings.masa_berlaku_sertifikat_bulan`
   (bisa disetel di panel: Pengaturan Organisasi → Penerbitan sertifikat)
3. organisasi belum nyetel → **12 bulan**

- **Dihitung dari `tanggal_kalibrasi`, bukan tanggal terbit.** Sertifikat bisa
  terbit beberapa hari sesudah alat dikerjain (contoh Tirta Gracia: kalibrasi
  26 Mei, terbit 30 Mei). Kalau dihitung dari tanggal terbit, masa berlakunya
  diam-diam kepanjangan dan alat lewat jatuh tempo tanpa ada yang sadar.
- Harus **sesudah** `tanggal_kalibrasi` → kalau nggak, `422`.
- Maksimal **10 tahun** dari sekarang → nahan salah ketik tahun (2035 → 2350).
- Kalau validasinya gagal, **sesinya nggak keburu disetujui** — statusnya tetap
  `menunggu_approval`.
- **Sesudah sertifikat terbit, masa berlakunya nggak bisa diubah lagi.** Aturan
  yang sama kayak `PATCH /calibrations/{id}/admin`: sertifikat yang udah terbit
  itu dokumen terkendali. Kalau salah, jalannya lewat reject → revisi → approve
  ulang, biar ada jejaknya.

**Buat UI:** taruh date-picker `berlaku_sampai` di dialog "Setujui", **prefilled**
pakai default organisasi (`tanggal_kalibrasi` + N bulan) supaya admin nggak perlu
ngetik tiap kali — ngetik tanggal manual berulang itu justru sumber salah ketik di
kolom yang nentuin kapan alat harus dikalibrasi lagi.

### `POST /api/calibrations/{id}/reject`
```json
{ "catatan_revisi": "Titik ukur 100mm cuma 2 pembacaan, minimal 3." }
```
Response: status jadi `perlu_revisi` + `catatan_revisi` keisi. Mobile bakal nampilin catatan ini ke teknisi.

### `GET /api/certificates/{id}`
```json
{
  "data": {
    "id": 21,
    "nomor": "CAL/2026/07/0001",
    "calibration_id": 88,
    "status": "terbit",
    "pdf_url": "https://.../certificates/CAL-2026-07-0001.pdf",
    "qr_token": "a1b2c3d4e5",
    "revision_of": null,
    "diterbitkan_pada": "2026-07-14T10:15:00Z"
  }
}
```
> **`status`: `menunggu_generate` / `terbit` / `gagal`.** Kalau `gagal`, mobile nampilin tombol retry — jadi tolong sediain `POST /api/certificates/{id}/retry`.
> `pdf_url` idealnya URL yang bisa langsung diunduh mobile (signed URL / route yang nerima Bearer token).

### `GET /api/verify/{qr_token}` — **tanpa auth** (dipakai orang luar yang scan QR).

> ✅ **Live sejak 14 Jul — dan ADA DUA VERSINYA, ini penting buat nentuin isi QR-nya.**
>
> **1. `GET /verify/{qr_token}` (halaman web, bukan `/api`).** Ini yang harus ditaruh di QR sertifikat. Alasannya: yang scan itu orang luar (auditor, pelanggan) pakai **kamera HP biasa** — yang kebuka **browser**, bukan app kita. Kalau QR-nya diisi URL `/api/...`, yang muncul di layar mereka JSON mentah. Halaman webnya nampilin nomor sertifikat, alat, pemilik, tanggal, dan hasil PASS/FAIL dengan rapi + kop lab & nomor akreditasi. QR ngawur → halaman "Sertifikat tidak ditemukan" (404), bukan error mentah.
>
> **2. `GET /api/verify/{qr_token}` (JSON).** Ini buat kalau **mobile** mau nampilin hasil scan di dalam app (misal teknisi scan sertifikat lama pakai fitur scan di app):
> ```json
> { "data": {
>     "nomor": "CAL/2026/07/0001", "status": "terbit", "keputusan": "PASS",
>     "diterbitkan_pada": "2026-06-15T00:00:00Z", "berlaku_sampai": "2027-06-14T00:00:00Z",
>     "kadaluarsa": false,
>     "alat": { "nama_alat": "Jangka Sorong Mitutoyo", "serial_number": "MT-500-196-30", "pemilik": "PT Maju Jaya" },
>     "tanggal_kalibrasi": "2026-06-14T00:00:00Z",
>     "diterbitkan_oleh": { "nama": "PT Sistem Dirgantara Inovasi Teknologi (PT Sidik)", "no_akreditasi": "LK-285-IDN" }
> } }
> ```
> QR nggak ketemu → `404 { "message": "Sertifikat dengan kode QR ini tidak terdaftar." }`
>
> Dua-duanya **cuma nampilin sertifikat yang statusnya `terbit`** — yang masih `menunggu_generate` dianggap nggak ada. Isinya sengaja dibatesin (nggak ada data mentah pengukuran, nggak ada nama/email teknisi), karena ini halaman publik.
>
> **Sertifikat contoh buat nyoba**: token `DEMOQR123` → `http://10.0.2.2:8000/verify/DEMOQR123` (udah ada di seeder).

---

## 6. Notifikasi (dibutuhin Minggu 9)

### `GET /api/notifications`

> ## ⛔ KOREKSI 25 Juli 2026 — tiga nama field di versi lama SALAH
>
> Bentuk yang dulu ditulis di sini nggak pernah cocok sama yang backend kirim.
> Yang salah: `tipe` → sebenarnya **`kategori`**, `pesan` → **`isi`**,
> `created_at` → **`dibuat_pada`**. Kalau parser dibikin ngikutin versi lama,
> judul & isi notifikasinya **kosong di layar** tanpa error apa pun.
>
> Bentuk di bawah dicek langsung ke `app/Http/Resources/NotificationResource.php`.

```json
{
  "data": [
    {
      "id": "9f1c...-uuid",
      "kategori": "sesi_menunggu_approval",
      "judul": "Sesi KAL/2026/07/0012 menunggu persetujuan",
      "isi": "pH Meter Mettler Toledo · teknisi Dwi Rahayu",
      "ikon": "heroicon-o-inbox-arrow-down",
      "warna": "info",
      "tautan": { "tipe": "calibration", "id": 12 },
      "dibaca": false,
      "dibaca_pada": null,
      "dibuat_pada": "2026-07-14T08:00:00Z"
    }
  ]
}
```

Catatan yang bikin beda dari dugaan:

- **`id` itu UUID string**, bukan integer — barisnya `DatabaseNotification`
  bawaan Laravel. Jangan di-parse jadi `int`.
- **`kategori`**, nilai yang beneran dipakai (dari `app/Notifications/`):
  `jatuh_tempo` · `sesi_menunggu_approval` · `sesi_disetujui` ·
  `sesi_perlu_revisi` · `sertifikat_terbit` · **`akun.menunggu_persetujuan`** ·
  **`sertifikat.gagal`** · **`standar.kadaluarsa`** · `umum` (fallback).
- **`tautan`** bentuknya `{ "tipe": ..., "id": ... }` — dipakai buat langsung
  buka layar yang dimaksud waktu notifikasinya diketuk. Bisa `null`.
- **`ikon`** itu nama ikon Heroicon (dipakai lonceng panel admin). Mobile boleh
  abaikan dan pakai ikon sendiri berdasarkan `kategori`.
- `warna` isinya token Filament (`info`, `warning`, `danger`, `success`).

### Endpoint notifikasi yang tersedia

```
GET    /api/notifications
GET    /api/notifications/unread-count
POST   /api/notifications/{id}/read        ← `/read`, BUKAN `/baca`
POST   /api/notifications/read-all
DELETE /api/notifications/{id}
```

> Dokumen lain sempat nyebut `POST /notifications/{id}/baca`. Itu nggak ada —
> yang bener `/read`.

### Tiga kejadian baru yang sekarang nyampe ke admin (live 26 Jul)

Nutup `permintaan-endpoint-fase-2.md` §2 — *"kalo ada sesuatu dan yang dibutuhkan
sama admin maka semuanya dikirim ke bagian admin."* Tiga yang tadinya nggak
dikabarin ke siapa pun:

| `kategori` | Kapan | `tautan` | Warna |
|---|---|---|---|
| `akun.menunggu_persetujuan` | ada yang `POST /register` | `{tipe: "users", filter: "pending", id}` | `warning` |
| `sertifikat.gagal` | PDF sertifikat gagal dibuat | `{tipe: "certificates", id}` | `danger` |
| `standar.kadaluarsa` | scheduler harian nemu standar mendekati/lewat habis | `{tipe: "standards", filter: "expired"\|"warning", standar: [...]}` | `danger`/`warning` |

Yang dikabarin: **admin `aktif` di organisasi itu**. `pending`/`nonaktif` nggak —
mereka nggak bisa login, jadi notifikasinya cuma numpuk. Teknisi & viewer juga
nggak: tiganya cuma bisa ditindak admin.

**`standar.kadaluarsa` bawa daftar rincian di `tautan.standar`**, jadi tap-nya bisa
langsung nampilin standar mana aja yang kena tanpa request tambahan:

```json
"tautan": {
  "tipe": "standards",
  "filter": "expired",
  "standar": [
    {
      "id": 5, "nama": "TH-5", "no_sertifikat": "LK-285-IDN",
      "berlaku_sampai": "2024-06-18",
      "hari_menuju_kadaluarsa": -768,
      "status_kalibrasi": "expired"
    }
  ]
}
```

- **`hari_menuju_kadaluarsa` bertanda:** negatif = udah lewat segitu hari.
- **Ambangnya sama** dengan badge `warning` di `GET /standards`:
  `organization.settings.reminder_hari_sebelum` (default 30). Sengaja satu angka —
  kalau ambang notifikasi beda dari ambang badge, admin bisa lihat badge kuning
  tanpa pernah dikabarin, atau sebaliknya.

> ### Anti-spam: isi yang sama nggak diulang tiap pagi
>
> `standar.kadaluarsa` dipicu scheduler **harian** dan ambangnya **30 hari**. Tanpa
> penjaga, admin dapat baris yang persis sama 30 kali berturut-turut — dan sesudah
> minggu pertama nggak ada yang buka loncengnya lagi.
>
> Aturannya dua:
>
> 1. **Isi sama** → dilewat selama **7 hari**, terus diingetin lagi.
> 2. **Isi berubah** (standar baru masuk jendela, atau satu berubah dari `warning`
>    jadi `expired`) → dikirim **saat itu juga**, nggak nunggu masa tenang. Itu
>    justru kabar yang paling nggak boleh telat.
>
> Efeknya buat mobile: **nggak ada.** Ini murni di sisi server; yang berubah cuma
> jumlah baris yang masuk. Tapi berguna diketahui waktu nyoba manual — kalau
> command-nya dijalanin dua kali dan yang kedua nggak nambah notifikasi, itu
> perilaku yang benar, bukan bug.
>
> `akun.menunggu_persetujuan` & `sertifikat.gagal` **nggak** kena penjaga ini: tiap
> kejadiannya beneran beda, dan nahan salah satunya berarti ada kabar yang nggak
> nyampe. Sertifikat yang gagal lagi sesudah retry tetap dikabarin — kegagalan
> kedua itu kabar baru (berarti bukan gangguan sesaat).

**Yang jalan otomatis** (butuh `php artisan schedule:work` di dev, atau cron ke
`schedule:run` di prod):

```
07:00  alat:cek-jatuh-tempo        alat mendekati/lewat jatuh tempo
07:05  standar:cek-kadaluarsa      sertifikat standar acuan
```

Bisa juga dipanggil manual: `php artisan standar:cek-kadaluarsa --hari=45`
(`--hari` maksa ambang yang sama ke semua organisasi, buat nyoba).

---

## 7. Dashboard (biar nggak ngambil 5 endpoint sekaligus)

### `GET /api/dashboard`
Isinya beda tergantung role — teknisi dapat ringkasan miliknya, admin dapat lintas-teknisi. Backend yang nentuin dari token, mobile nggak ngirim role.
```json
{
  "data": {
    "total_alat": 42,
    "alat_overdue": 3,
    "kalibrasi_draft": 2,
    "menunggu_approval": 5,
    "kalibrasi_selesai": 27,
    "menunggu_proses": 8,
    "total_sertifikat": 137,
    "sertifikat_bulan_ini": 12,
    "grafik_pekerjaan": [
      { "bulan": "2026-02", "label": "Feb 2026", "masuk": 4, "selesai": 3 },
      { "bulan": "2026-03", "label": "Mar 2026", "masuk": 0, "selesai": 0 },
      { "bulan": "2026-04", "label": "Apr 2026", "masuk": 7, "selesai": 6 },
      { "bulan": "2026-05", "label": "May 2026", "masuk": 5, "selesai": 5 },
      { "bulan": "2026-06", "label": "Jun 2026", "masuk": 9, "selesai": 8 },
      { "bulan": "2026-07", "label": "Jul 2026", "masuk": 3, "selesai": 1 }
    ]
  }
}
```

> ✅ **Live sejak 14 Jul**, persis bentuk ini. Role diambil dari token (teknisi cuma ngitung kalibrasi miliknya sendiri; admin & viewer lintas-teknisi) — mobile nggak usah ngirim apa-apa.
>
> **✅ 20 Jul — tiga field baru, key lama semuanya dipertahanin** jadi layar yang sekarang nggak pecah:
>
> - **`kalibrasi_selesai`** — jumlah sesi berstatus `disetujui`. Sengaja **bukan** "sertifikat terbit": generate PDF jalan di queue, jadi sesi yang baru di-approve bakal kehitung "belum selesai" kalau worker lagi ngantre, padahal kerjaan teknisinya udah kelar.
> - **`menunggu_proses`** — semua sesi yang **bukan** `disetujui`, jadi `draft` + `menunggu_approval` + `perlu_revisi` nyatu di satu angka. Ini yang dimaksud kartu "Menunggu proses" di spec; `kalibrasi_draft` & `menunggu_approval` tetap ada kalau mobile mau mecah lagi rinciannya.
>   > ⚠️ Ini **numpuk** sama `kalibrasi_draft` + `menunggu_approval`, bukan angka terpisah. Jangan dijumlahin bareng ketiganya di satu baris total — nanti kehitung dobel.
> - **`grafik_pekerjaan`** — 6 bulan terakhir termasuk bulan berjalan, urutannya **lama → baru**, jadi bisa langsung digambar tanpa nyortir. `masuk` = sesi yang tanggal kalibrasinya jatuh di bulan itu; `selesai` = yang di-approve di bulan itu. **Bulan tanpa kerjaan tetap keluar dengan nilai `0`, nggak dilewat** — jadi sumbu X-nya selalu 6 titik dan jaraknya rata.
>     - `bulan` (`"2026-07"`) buat key/sorting, `label` (`"Jul 2026"`) udah siap tempel ke sumbu X — mobile nggak usah nerjemahin nama bulan sendiri.
>       > ⚠️ Namanya `bulan`, **bukan** `periode`. Yang ngirim `periode` cuma `GET /dashboard/tren`. Mobile sempat salah baca key ini dan akibatnya sumbu X grafik Dashboard kosong melompong di HP, padahal semua test ijo — `MockDashboardService` waktu itu ngelewatin parser-nya. Sekarang mock-nya ikut lewat `fromJson`, dan ada `test/dashboard_response_test.dart` yang nguji pakai potongan respons asli.
>     - Grafiknya ikut kesaring per role, sama kayak kartu angkanya: teknisi cuma lihat kerjaannya sendiri.
>
> **✅ 21 Jul — `total_sertifikat`** (sertifikat terbit sepanjang waktu).
>
> ⚠️ **Cakupan angkanya nggak seragam**, dan ini nentuin cara nampilinnya:
>
> | Kelompok | Field | Cakupan |
> |---|---|---|
> | Sesi | `kalibrasi_draft`, `menunggu_approval`, `kalibrasi_selesai`, `menunggu_proses`, `grafik_pekerjaan` | teknisi = **punya dia sendiri**; admin/viewer = se-lab |
> | Alat & sertifikat | `total_alat`, `alat_overdue`, `total_sertifikat`, `sertifikat_bulan_ini` | **selalu se-lab**, termasuk buat teknisi |
>
> Jadi di layar teknisi wajar muncul "Kalibrasi selesai: 2" bareng "Sertifikat: 137". Dashboard misahin dua kelompok ini secara visual (kartu hero berlabel "SE-LAB" vs seksi "KALIBRASI SAYA") — jangan digabung jadi satu deret kartu tanpa keterangan, nanti kebaca kayak datanya ngaco.

### `GET /api/dashboard/tren` — grafik rentang bebas (live 26 Jul)

Buat grafik yang periodenya dipilih user, bukan dipatok 6 bulan kayak
`grafik_pekerjaan`. **Semua role**; teknisi cuma dapat pekerjaannya sendiri.

```
GET /api/dashboard/tren?dari=2026-05-01&sampai=2026-07-31&satuan=bulan
```

| Penyaring | Isi | Bawaan kalau nggak dikirim |
|---|---|---|
| `satuan` | `hari` · `minggu` · `bulan` | `bulan` |
| `sampai` | `YYYY-MM-DD` | hari ini |
| `dari` | `YYYY-MM-DD` | `hari` → 30 hari · `minggu` → 12 minggu · `bulan` → 6 bulan |

```json
{
  "data": [
    { "periode": "2026-05", "label": "Mei 2026", "masuk": 14, "selesai": 11 },
    { "periode": "2026-06", "label": "Jun 2026", "masuk": 9,  "selesai": 9  },
    { "periode": "2026-07", "label": "Jul 2026", "masuk": 6,  "selesai": 3  }
  ],
  "penyaring": { "dari": "2026-05-01", "sampai": "2026-07-31", "satuan": "bulan" }
}
```

- **Urutannya lama → baru**, bisa langsung digambar tanpa nyortir.
- **Periode kosong tetap keluar dengan nilai `0`, nggak dilewat** — kalau dilewat,
  grafiknya bohong: jeda kosong ketutup dan tren naik-turunnya kelihatan lebih
  mulus dari kenyataan.
- `masuk` = sesi yang **tanggal kalibrasinya** jatuh di periode itu.
  `selesai` = yang **di-approve** di periode itu (`reviewed_at`), bukan tanggal
  sertifikat terbit — generate PDF jalan di queue, jadi sesi yang baru di-approve
  bakal kehitung "belum selesai" kalau worker lagi ngantre.
- `label` udah siap tempel ke sumbu X. `hari`/`minggu` → `"23 Jul"`,
  `bulan` → `"Jul 2026"`. Minggu dilabeli tanggal **mulai**nya — `"W30"` nggak
  berarti apa-apa buat yang lihat grafik.
- **Kunci `periode` buat `minggu` pakai tahun-minggu ISO**: `"2026-W01"`. Perhatiin
  di pergantian tahun — 29 Des 2025 itu minggu ke-1 tahun **2026** menurut ISO,
  jadi jangan diasumsikan 4 karakter pertamanya sama dengan tahun tanggalnya.
- **`dari`/`sampai` dinormalisasi ke batas periode.** `dari=2026-01-31&satuan=bulan`
  tetap mulai dari `2026-01`, bukan ngelewatin Januari.

> ⚠️ **Nama kuncinya `periode`, BUKAN `bulan`.** Sebaliknya `grafik_pekerjaan` di
> `GET /dashboard` pakai `bulan`, bukan `periode`. Dua-duanya sengaja beda dan
> **nggak** dibikin alias — mobile pernah kena bug nyata gara-gara ketuker (sumbu X
> Dashboard kosong melompong padahal semua test ijo). Satu endpoint, satu nama.

**Batas: maksimal 400 periode.** Lebih dari itu `422`, bukan hasil yang dipotong —
hasil yang dipotong sepi-sepi bikin orang salah baca tren, dan 400+ batang di layar
HP itu garis abu-abu, bukan grafik. Kalau kena, sempitin rentangnya atau naikin
`satuan` (`hari` → `minggu` → `bulan`). `satuan` ngawur & `sampai` lebih awal dari
`dari` juga `422`.

> **Angkanya dijamin sama dengan `grafik_pekerjaan`.** Dua-duanya narik dari satu
> service (`TrenPekerjaan`), dan ada test yang ngebandingin keluaran keduanya bulan
> per bulan. Jadi `/dashboard/tren?satuan=bulan` yang dibuka tanpa penyaring bakal
> ngasih angka identik dengan grafik di Dashboard — kalau beda, itu bug, bukan beda
> definisi.

---

## 8. Master Data PT & Pelanggan — **admin doang** (live 14 Jul)

Belum ada di kontrak versi kamu, tapi udah jalan di backend. Dibutuhin buat layar Pengaturan (admin).

- **`GET /api/organization`** · **`PUT /api/organization`** — data PT: `nama`, `alamat`, `telepon`, `email`, `no_akreditasi`, plus **`logo_url`** (read-only, diisi lewat endpoint di bawah). Ini yang bakal dicetak di kop sertifikat. *Nggak ada create/delete* — satu instalasi = satu PT.
  > **✅ 18 Jul — response-nya lebih gemuk dari yang didokumentasiin, mobile baru nyusul makainya**: `standar_akreditasi`, `akreditasi_mulai`, `akreditasi_berakhir`, dan `akreditasi_masih_berlaku` (dihitung backend, read-only — jangan dikirim balik waktu `PUT`). Ini yang nentuin akreditasi lab (LK-285-IDN) masih sah apa nggak; sebelumnya nggak ada di layar mana pun. `settings` (array) juga ada di response tapi mobile sengaja belum kasih UI buat itu — bentuknya belum didokumentasiin.
- **`POST /api/organization/logo`** · **`DELETE /api/organization/logo`** — ✅ live 25 Jul.
  Logo yang dicetak di kop sertifikat. Multipart, field **`logo`**, admin doang.
  > **PNG atau JPG doang, maks 2 MB.** WEBP ditolak `422` walau itu gambar sah:
  > logonya berakhir di PDF lewat dompdf yang nggak bisa render WEBP, dan mime-nya
  > ditebak dari ekstensi — WEBP bakal dilabeli JPEG dan kop sertifikatnya rusak
  > tanpa error apa pun.
  >
  > Balikannya objek organisasi lengkap (sama kayak `GET /organization`), jadi
  > `logo_url` yang baru langsung kepakai tanpa request ulang.
  >
  > `logo_url` **`null`** artinya org ini belum ngunggah logo — **bukan** berarti
  > sertifikatnya bakal tanpa logo. PDF jatuh ke logo bawaan
  > (`public/images/logo-sidik.png`); kalau itu juga nggak ada, kop-nya jadi teks
  > doang dan PDF tetap kebuat.
  >
  > Ganti logo otomatis ngehapus yang lama. `DELETE` idempoten — aman dipanggil
  > walau belum ada logonya.
- **`GET /api/customers?search=&page=`** · **`POST`** · **`GET/PUT/DELETE /api/customers/{id}`** — CRUD pelanggan. Field: `nama`, `alamat`, `contact_person`, `telepon`, `email` (+ `jumlah_alat` di response).
- **Pelanggan yang masih punya alat nggak bisa dihapus** → `422`. Kalau dipaksa, alat & riwayat kalibrasinya jadi yatim. Mobile: tampilin pesannya apa adanya.

Teknisi & viewer yang nembak endpoint ini dapat `403`.

> ⚠️ **21 Jul — jangan pakai `GET /customers` buat isi dropdown pelanggan di form Alat.**
>
> `POST /equipments` boleh dipakai **teknisi**, tapi `/customers` admin-only. Waktu picker pelanggan di form Alat masih narik dari sini, hasilnya: form-nya mulus waktu dites pakai akun admin, tapi di akun teknisi request-nya `403` → daftarnya kosong. Dan `pelanggan_id` itu **wajib**, jadi teknisi mentok, nggak bisa nyimpen alat sama sekali.
>
> Pakai **`GET /api/arsip/perusahaan?search=`** (kebuka semua role) — balikannya `data[].id` & `data[].nama`, `id`-nya yang dipakai jadi `pelanggan_id`. Di mobile ini `CustomerLookupService`, kepisah dari `CustomerService` yang buat layar CRUD Pelanggan.
>
> Daftarnya **dipaginasi 15/halaman**, jadi pencariannya dilempar ke server lewat `?search=` — nyaring di sisi mobile cuma nyaring halaman pertama, dan pelanggan ke-16 dst. jadi nggak kejangkau.

> ## ⛔ KOREKSI 25 Juli 2026 — saran di atas SALAH, jangan diikutin
>
> Dicek ke `FolderController@index`. Empat hal keliru, dan yang pertama bisa
> nyimpen data ke pelanggan yang salah:
>
> | Klaim di atas | Kenyataan |
> |---|---|
> | `data[].id` dipakai jadi `pelanggan_id` | ⛔ **`id` itu id FOLDER, bukan pelanggan.** Id pelanggan ada di **`data[].pelanggan.id`**. Ngirim id folder sebagai `pelanggan_id` bakal ditolak `422` — atau lebih buruk, nyantol ke pelanggan lain yang id-nya kebetulan sama |
> | `?search=` | Param yang kebaca **`?q=`**. `search` diabaikan diam-diam, jadi daftarnya balik utuh dan kelihatan kayak filternya nggak jalan |
> | "dipaginasi 15/halaman" | **Nggak dipaginasi sama sekali** — `->get()`, semua baris balik |
> | "kebuka semua role" | Kebuka, **tapi isinya disaring per role.** Buat teknisi cuma folder yang ada isinya buat DIA |
>
> **Yang terakhir itu yang bikin saran ini nggak nyelesaiin masalahnya.** Endpoint
> ini ngelist FOLDER, dan folder cuma ada buat PT yang udah pernah punya
> sertifikat. Jadi buat teknisi yang mau nyimpen alat milik pelanggan **baru**,
> PT-nya nggak akan nongol — persis dead-end yang mau dihindarin.
>
> **Pakai ini:** ✅ **`GET /api/customers/lookup`** (live 25 Jul, kebuka semua
> role) — dibikin persis buat kasus ini. Bentuknya di bawah.
>
> **⚠️ Update 27 Agt (2) — baris pertama tabel itu KAMBUH di layar Arsip.**
> Layar Arsip juga membaca `data[].id` dari `/arsip/perusahaan` dan
> mengirimnya ke `GET /arsip/perusahaan/{customer}/folder`, yang ngiket ke
> `Customer`. Hasilnya admin membuka arsip **PT lain** — status 200, nol error,
> judulnya tetap nama PT yang dipencet. Diuji tiga PT: **dua kebuka arsip PT
> lain.** Sekarang tiap baris membawa `pelanggan.id` sendiri (lihat §8a), jadi
> yang membaca nggak perlu menebak dari `id`. Dijaga
> `IdPelangganDiDaftarArsipTest`.
>
> **⚠️ Update 27 Agt — mobile baru beneran pindah hari ini.** Koreksi ini ditulis
> 25 Jul, endpoint-nya jadi hari itu juga, tapi `ApiCustomerLookupService` di
> repo mobile masih nembak `/arsip/perusahaan?search=` sampai 27 Agt. Jadi
> keempat baris tabel di atas itu bukan bahaya teoretis — itu yang berjalan di
> APK selama sebulan. Kalau nemu jalur lain yang masih nembak
> `/arsip/perusahaan` buat ngisi daftar pelanggan, itu bug yang sama, bukan
> pilihan lain.

### `GET /api/customers/lookup` — dropdown pelanggan, semua role

✅ **Live 25 Jul.** Ini yang dipakai picker pelanggan di form Alat, **bukan**
`/arsip/perusahaan` (lihat koreksi di atas) dan bukan `/customers` (admin-only).

```
GET /api/customers/lookup?search=tirta&page=1
```

```json
{
  "data": [
    { "id": 3, "nama": "PT TIRTA GRACIA SEMESTA MANDIRI", "alamat": "Jl. Arteri Primer A-10 ..." }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 }
}
```

- **`data[].id` itu id PELANGGAN** — langsung kepakai jadi `pelanggan_id` waktu
  `POST`/`PUT /equipments`. Nggak perlu diturunin dari apa pun.
- **Pelanggan baru yang belum punya sertifikat TETAP nongol.** Ini bedanya paling
  penting dari `/arsip/perusahaan`, yang cuma ngelist PT yang udah punya arsip.
- **Dipaginasi 15/halaman**, jadi pencariannya dilempar ke server lewat `?search=`
  — nyaring di sisi mobile cuma nyaring halaman pertama, dan pelanggan ke-16 dst.
  jadi nggak kejangkau. `?q=` diterima juga sebagai alias.
- **Update 27 Agt — `?search=` nyari `nama` ATAU `alamat`.** Begitu cara teknisi
  mengingat pelanggannya: satu kawasan industri isinya belasan PT bernama mirip,
  dan yang dia pegang alamat penjemputannya. `?search=Cikarang` mulangin semua PT
  di Cikarang. Saringan organisasinya tetap kepasang (kurungnya eksplisit di
  query) — endpoint ini kebuka semua role, dan `orWhere` tanpa kurung bakal
  nembus ke pelanggan lab sebelah.
- **Cuma `id`, `nama`, `alamat`.** `contact_person`/`telepon`/`email` sengaja
  nggak ikut: ini dropdown, bukan layar CRUD — role yang nggak boleh ngelola
  pelanggan nggak perlu megang kontaknya. `alamat` ikut karena blok OWNER di
  lembar kerja butuh (dan itu udah kekirim lewat `EquipmentResource.pelanggan`).
- CRUD pelanggan **tetap admin-only** — endpoint ini bukan pintu belakang ke situ.
- **Update 29 Agt — tahan tanda baca.** `?search=PT Maju` sekarang juga ketemu
  `PT. Maju Jaya`, lewat kolom turunan `nama_normal` (huruf kecil semua, tanda
  baca & spasi ganda diratakan). Bentuk badan usaha **tidak** dibuang: `PT Maju`
  dan `CV Maju` dua badan hukum berbeda dengan NPWP berbeda. Yang nggak ketemu
  bakal didaftarkan ulang, dan kembar bikin riwayat kalibrasi satu perusahaan
  terbelah.

---

### `GET /api/customers/direktori` — cari PT di direktori LUAR (live 29 Agt)

Role: **admin & teknisi**. Di-throttle `30/menit`.

```http
GET /api/customers/direktori?search=sinar%20rejeki
```

```json
{
  "data": [
    { "ref": "ChIJ...", "nama": "PT Sinar Rejeki Manufaktur", "alamat": "Kawasan Industri MM2100 ..." }
  ]
}
```

Dipakai waktu `/customers/lookup` nol hasil — pelanggannya beneran belum pernah
masuk master lab. Tanpa ini teknisi mengetik nama & alamat dari ingatan, dan
alamat yang salah ketik mendarat di blok OWNER sertifikat.

- **Ini proxy, bukan HP nembak penyedianya langsung.** API key-nya cuma ada di
  `.env` server (`DIREKTORI_PERUSAHAAN_KEY`). Key di dalam APK bisa dicabut siapa
  pun dari berkasnya lalu dipakai orang lain atas tagihan lab ini — dan
  endpointnya ditagih **per request**.
- **`ref` itu id tempat menurut direktorinya, BUKAN `customers.id`.** Dia nggak
  bisa dipakai jadi `pelanggan_id`. Barisnya baru punya id pelanggan sesudah
  dikirim ke `POST /api/customers/cepat`.
- **Tiga jawaban yang WAJIB dibedakan klien:**

  | Status | Artinya | Yang harus dilakukan layar |
  |---|---|---|
  | `200` + daftar (boleh kosong) | Direktorinya menjawab, segitu hasilnya | Pajang apa adanya |
  | `503` + `tersedia: false` | API key belum disetel di server ini | Bilang belum disetel; **jangan** tawarkan "coba lagi" |
  | `502` + `tersedia: true` | Direktorinya nggak bisa dihubungi / nolak | Bilang lagi mati, arahkan ke ketik tangan |

  Diratakan jadi "daftar kosong", teknisi membacanya sebagai *PT-nya nggak ada di
  direktori* lalu mendaftarkan ulang perusahaan yang sebenarnya ada di sana —
  nambah kembar justru lewat fitur yang dipasang buat menguranginya.
- **Bukan data akta.** Sumbernya direktori tempat usaha (perusahaan sebagaimana
  muncul di peta). AHU/OSS tidak membuka API publik. Hasilnya harus tetap bisa
  disunting teknisi sebelum tersimpan.
- Pesan error penyedianya **tidak** diteruskan ke klien — bisa memuat potongan
  key atau id proyek.

---

### `POST /api/customers/cepat` — daftarkan PT baru dari lapangan (live 29 Agt)

Role: **admin & teknisi**. Langsung kepakai, tanpa antrean persetujuan — sejalan
dengan keputusan K3/K4 buat nama alat.

```json
{ "nama": "PT Sinar Rejeki", "alamat": "MM2100 Blok C-3", "direktori_ref": "ChIJ...", "tetap_buat": false }
```

Sukses `201`:

```json
{ "data": { "id": 42, "nama": "PT Sinar Rejeki", "alamat": "MM2100 Blok C-3" } }
```

- **Cuma `nama` & `alamat`.** `contact_person`/`telepon`/`email` **tidak
  diterima** — teknisi di gerbang pabrik nggak punya data itu, dan kolom yang ada
  di form pasti ada yang mengisinya dengan tebakan.
- **`sumber` & `organization_id` diisi SERVER, dan payload-nya diabaikan.**
  `sumber` = `direktori` kalau `direktori_ref` ada, kalau nggak `admin`/`teknisi`
  menurut role. Kalau `sumber` boleh datang dari klien, satu `{"sumber":"admin"}`
  dari HP cukup buat bikin baris ketikan lapangan menyamar jadi baris yang sudah
  diperiksa admin.
- **`409` = ada yang mirip, dan itu jalan keluarnya, bukan kegagalan:**

  ```json
  {
    "message": "Ada pelanggan dengan nama yang mirip. ...",
    "nama_persis_sudah_ada": false,
    "kandidat": [ { "id": 7, "nama": "PT. Maju Jaya", "alamat": "..." } ]
  }
  ```

  - `nama_persis_sudah_ada: false` → bisa ditembus dengan `tetap_buat: true`.
  - `nama_persis_sudah_ada: true` → **buntu.** Ditahan unique index di database;
    `tetap_buat` nggak menembusnya. Layar **tidak boleh** menampilkan tombol
    tembus di keadaan ini.

  Kemiripannya diadu lewat `nama_normal` **dan** `direktori_ref`, dan keduanya
  disaring ke organisasi pemanggil.

---

## 8a. Folder Manager — buka folder satu PT (live 25 Jul)

### Bentuk baris `GET /api/arsip/perusahaan` — DUA id, beda arti

```json
{ "data": [
    { "id": 3, "nama": "PT Alfa", "tipe": "sistem", "parent_id": null,
      "pelanggan": { "id": 1, "nama": "PT Alfa", "alamat": "Jl. Raya Cikarang KM 27, Bekasi" },
      "jumlah_alat": 4, "jumlah_sertifikat": 2, "jumlah_folder": 3, "jumlah_file": 12 }
] }
```

| Kunci | Artinya | Dipakai buat |
|---|---|---|
| `id` | **id FOLDER** | `GET /arsip/folders/{id}` |
| `pelanggan.id` | **id PELANGGAN** | `GET /arsip/perusahaan/{customer}/folder` |

**Dua-duanya sering beda.** Folder akar PT dibikin belakangan (find-or-create
waktu PT-nya pertama dibuka), jadi urutannya nggak ikut urutan pelanggan.
Mengirim `id` ke rute pelanggan membuka arsip PT lain yang id-nya kebetulan
sama — **status 200, nol error**, dan judulnya tetap nama PT yang dipencet.

`pelanggan` **bisa `null`**: `folders.customer_id` boleh kosong (folder akar
manual, mis. "Dokumen Mutu"). Di situ yang benar dibuka lewat `id` sebagai
folder biasa — jangan ditebak ke pelanggan.

`pelanggan.alamat` ikut sejak 27 Agt; kartu PT di layar Arsip memang
menampilkannya (nama tebal, alamat kecil di bawahnya), dan sebelum ini baris
kecil itu selalu kosong tanpa ada error yang bunyi.

### `GET /api/arsip/perusahaan/{customer}/folder`

Tap PT → lihat isinya. `{customer}` itu **id PELANGGAN**, bukan id folder (lihat
koreksi di §8 — ini beda yang gampang kebalik).

Balikannya **bentuk yang sama persis** kayak `GET /api/arsip/folders/{id}`
(breadcrumb + `sub_folder[]` + `file[]`), karena handler-nya memang yang sama.
Jadi **parser folder yang udah ada bisa dipakai apa adanya.**

Bedanya sama `GET /api/arsip/folders/{id}`: yang ini **find-or-create** — PT yang
belum pernah punya sertifikat pun tetap kebuka, folder akarnya dibikin saat itu.
Tanpa ini tap PT mentok `404` padahal PT-nya jelas ada.

**Siapa yang bisa apa** — beda per role, dan ini bukan kebetulan:

| Role | Folder belum ada | Folder udah ada |
|---|---|---|
| admin | **dibikin**, balik `200` | `200` |
| teknisi | `404` (nggak dibikin) | `200` **kalau ada berkas yang dia boleh lihat**, kalau nggak `404` |
| viewer | `404` (nggak dibikin) | `200` |

- **Cuma admin yang bikin.** `POST`/`PUT`/`DELETE /folders` semuanya admin-only,
  jadi endpoint ini nggak boleh jadi celah nulis buat role yang di tempat lain
  ditolak. Viewer itu role baca-saja — bikin baris sebagai efek samping `GET`
  bikin klaim itu berhenti benar.
- **Teknisi dapat `404` buat PT yang dia nggak punya kerjaan di situ**, bukan
  folder kosong. Ini aturan privasi Folder Manager yang udah lama jalan: folder
  kosong yang ditampilin ngasih tahu "PT ini ada arsipnya, cuma bukan urusanmu",
  dan menu ini ada di navbar teknisi. Di alur normal nggak kerasa — daftar PT
  yang teknisi lihat udah tersaring, jadi yang bisa dia tap selalu ada isinya.
- PT milik lab lain → `404` (bukan `403`), dan nggak ninggalin folder apa pun.

### `PUT /api/arsip/folders/{id}/pindah` — pindah folder (live 27 Jul)

**Admin doang.** Kepisah dari `PUT /arsip/folders/{id}` yang cuma rename, karena
yang ini nyentuh struktur pohonnya.

```json
{ "parent_id": 12 }
```

- **`parent_id` WAJIB dikirim**, tapi boleh `null` — `null` artinya jadiin folder
  akar. Kalau nggak dikirim sama sekali → `422` (biar "lupa ngirim" nggak diam-diam
  kebaca sebagai "pindahin ke akar").
- Balikannya objek folder, bentuknya sama kayak `PUT /arsip/folders/{id}`.
- **Drag ke induk yang sama → `200`**, bukan error. Biar UI drag-drop nggak
  nampilin merah cuma gara-gara user naruh balik di tempat asalnya.

**Empat penolakan `422`, dan tiga di antaranya perlu dipatuhi UI:**

| Kasus | Kenapa |
|---|---|
| Folder bertipe **`sistem`** | `FolderOrganizer` nemuin folder akar PT dari `parent_id = null` dan folder tahun dari `parent_id = akar->id`. Begitu dipindah, kriterianya nggak nyocok, sertifikat berikutnya bikin folder **baru**, dan arsip satu PT **kepecah dua**. Aturan yang sama yang udah nolak rename folder sistem |
| Dipindah ke **dirinya sendiri** | — |
| Dipindah ke **keturunannya sendiri** | Bikin siklus: folder-nya lepas dari pohon dan **ilang dari semua layar** tanpa error. Barisnya masih di DB, tapi nggak bisa dijangkau |
| **Nama bentrok** di folder tujuan | Nama folder harus unik dalam satu induk — aturan yang sama kayak rename & create |

> **Buat UI:** folder yang `tipe`-nya `sistem` jangan dibikin bisa di-drag sama
> sekali. `tipe` udah ikut di respons `/folders`, jadi ini bisa dicegah sebelum
> user nyoba dan kena `422`.

### `PUT /api/arsip/berkas/{sesiId}/pindah` — pindah berkas (live 27 Jul)

**Admin doang.** `{sesiId}` itu **id SESI KALIBRASI**, bukan id `folder_files` —
itu yang dipegang mobile di layar arsip.

```json
{ "folder_id": 12 }
```

- `folder_id` **wajib**, nggak boleh `null` (berkas harus ada di suatu folder).
- Balikannya objek berkas, bentuknya sama kayak `PUT /api/folder-files/{id}`.
- Drag ke folder yang sama → `200`.

| Kasus | Status |
|---|---|
| Sesi belum punya sertifikat | `404` — belum ada berkasnya di arsip |
| Sertifikatnya belum pernah ditautkan ke folder | `404` |
| Sertifikat tertaut di **lebih dari satu** folder | `422` + daftar kandidatnya di `data` |
| Sertifikat udah ada di folder tujuan | `422` |
| Sesi / folder tujuan milik lab lain | `404` / `422` |

> **Soal yang `422` "tertaut di lebih dari satu folder":** unique index-nya
> `(folder_id, certificate_id)`, bukan `certificate_id` sendiri — jadi satu
> sertifikat **bisa** nyangkut di dua folder. Kalau kejadian, backend **nanya**
> bukan nebak, dan daftar kandidatnya ikut dikirim di `data` biar admin bisa milih
> tanpa nyari lagi. Pindahin yang dipilih pakai `PUT /api/folder-files/{id}` dengan
> id berkasnya langsung.
>
> Kenapa nggak ditebak aja: yang salah kepindah **nggak keliatan sebagai error** —
> cuma sebagai berkas yang ilang dari folder yang orangnya lihat.

---

## 9. Master Data Ruangan & Teknisi (live 20 Jul)

Dua master data terakhir dari spec yang sebelumnya belum ada backend-nya sama sekali.

### `GET /api/rooms` — ruangan lab

Beda sama `lokasi` di sesi kalibrasi. Field itu enum `lab`/`onsite`, cuma misahin "dikerjain di lab" vs "di tempat pelanggan". Yang ini jawab pertanyaan lain: ruangan **mana** di dalam lab, dan syarat suhu/kelembabannya berapa.

```json
{
  "data": [
    {
      "id": 1,
      "kode": "R-01",
      "nama": "Ruang Kalibrasi Massa",
      "lokasi": "Lantai 2",
      "suhu_min": 18.0,
      "suhu_max": 25.0,
      "kelembaban_min": 40.0,
      "kelembaban_max": 60.0,
      "keterangan": null,
      "aktif": true
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 }
}
```

- **`GET /api/rooms?search=&hanya_aktif=&page=`** · **`GET /api/rooms/{id}`** — **semua role boleh baca**, beda sama master data lain. Teknisi butuh ini buat isi dropdown "Ruangan" waktu ngisi sesi.
- **`POST`** · **`PUT /api/rooms/{id}`** · **`DELETE /api/rooms/{id}`** — **admin doang**, teknisi/viewer → `403`.
- **`?hanya_aktif=1`** buat dropdown — ruangan lama dinonaktifin (`aktif: false`), bukan dihapus, biar sesi tahun lalu yang nunjuk ke situ tetap kebaca pas audit. Layar master data jangan pakai filter ini, biar yang nonaktif tetap kelihatan & bisa diaktifin lagi.
- **`search`** nyari di `nama` **atau** `kode` — orang lab hafalnya "R-01", yang kebaca di layar "Ruang Kalibrasi Massa".
- `kode` unik **per PT**, bukan global. Dobel → `422` di field `kode`.
- Rentang kebalik (`suhu_max` < `suhu_min`, atau kelembabannya) ditolak `422`, **termasuk kalau `PUT`-nya cuma ngirim satu sisi** — batas satunya diambil dari data tersimpan. Kalau lolos, tiap sesi di ruangan itu ketulis melanggar syarat selamanya.
- `kelembaban_min`/`max` itu persen, dibatasi 0–100.
- Semua angka dikirim sebagai **number**, bukan string — nullable semua (nggak semua ruangan punya syarat terkendali).

> ⚠️ **Belum nyambung ke sesi kalibrasi.** Ini masih master data berdiri sendiri — `POST /api/calibrations` **belum** nerima `room_id`. Nambahin itu ngubah bentuk sesi, jadi ditahan dulu sampai disepakati. Kalau mobile mau layar sesi bisa milih ruangan, bilang dulu biar dibarengin.

### `GET /api/technicians` — data teknisi (**admin doang**)

```json
{
  "data": [
    {
      "id": 4,
      "nama": "Budi Santoso",
      "employee_id": "SDK-2001",
      "email": "budi@sidik.test",
      "department": "Kalibrasi",
      "status": "aktif",
      "jumlah_kalibrasi": 12
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 1 }
}
```

- **`GET /api/technicians?search=&status=&page=`** · **`POST`** · **`GET/PUT/DELETE /api/technicians/{id}`** — semuanya admin, teknisi/viewer → `403`.
- **Teknisi itu `users` yang role-nya `teknisi`, bukan tabel terpisah.** Jadi `id` di sini **sama** dengan `teknisi_id` yang muncul di sesi kalibrasi & sertifikat — aman dipakai buat nyambungin layar. Dibikin tabel sendiri, orang yang sama bakal punya dua identitas: satu buat login, satu buat ditulis di sertifikat.
- **`POST`** butuh `nama`, `employee_id`, `email`, `password` (min 8), `department` opsional. Akun langsung `aktif` & role `teknisi` — nggak nyangkut di antrean approval, karena itu gunanya nyaring pendaftar mandiri, bukan akun yang dibikinin admin.
- **`role` nggak bisa dikirim** — diabaikan, selalu dipaksa `teknisi`. Kalau bisa, layar "tambah teknisi" berubah jadi jalan pintas bikin akun admin.
- **`password` nggak bisa dikirim waktu `PUT`** → `422`. Ganti password lewat `POST /api/users/{id}/reset-password`, biar aksi sensitif itu nggak nempel diam-diam di form edit biasa.
- **`PUT` dengan `status: "nonaktif"` langsung nyabut semua token orangnya** — dia ketendang dari app saat itu juga.
- **Teknisi yang punya riwayat kalibrasi nggak bisa dihapus** → `422`. Namanya nempel di sertifikat yang udah terbit; hapus dia = putus ketertelusuran yang justru dicari asesor waktu audit. `jumlah_kalibrasi` di response ada persis buat ini — pakai buat nge-disable tombol hapus + jelasin kenapa. Jalan keluarnya: `PUT` jadi `nonaktif`.
- `search` nyari di `nama` atau `employee_id`. `status` isinya `aktif`/`nonaktif`.
- ID yang bukan teknisi (admin/viewer) balik **`404`**, bukan `403` — endpoint ini nggak boleh jadi jalan pintas ngintip atau ngehapus akun admin lewat URL yang kedengarannya nggak berbahaya.

---

## 10. Laporan Kalibrasi + Export (live 26 Jul)

### `GET /api/laporan/kalibrasi`

**Semua role boleh.** Tapi **teknisi cuma dapat pekerjaannya sendiri** — aturan
yang sama kayak `/calibrations?mine=true`. Kalau di sini dilonggarin, penyaringan
di layar Riwayat jadi nggak ada artinya: tinggal buka Laporan buat ngintip.

```
GET /api/laporan/kalibrasi?dari=2026-07-01&sampai=2026-07-31
    &pelanggan_id=3&teknisi_id=5&kategori=instrumen-analitik
    &status=disetujui&keputusan=PASS&page=1
```

| Penyaring | Isi | Catatan |
|---|---|---|
| `dari` / `sampai` | `YYYY-MM-DD` | **Dua ujungnya INKLUSIF.** "1–31 Juli" ikut tanggal 31 |
| `pelanggan_id` | id dari `GET /customers/lookup` | |
| `teknisi_id` | id dari `GET /technicians` | |
| `kategori` | **KODE** kategori (`instrumen-analitik`), bukan id | Sama gayanya kayak penyaring `/equipments` |
| `status` | `draft` · `menunggu_approval` · `disetujui` · `perlu_revisi` | Nilai lain → `422` |
| `keputusan` | `PASS` · `FAIL` | |

Semua opsional. `sampai` lebih awal dari `dari` → `422`.

```json
{
  "data": [
    {
      "id": 2,
      "nomor_sesi": "2405.13.A",
      "tanggal_kalibrasi": "2024-05-26",
      "pelanggan": { "id": 3, "nama": "PT TIRTA GRACIA SEMESTA MANDIRI" },
      "alat": { "id": 6, "nama_alat": "pH Meter", "serial_number": "B628755900" },
      "kategori": { "kode": "instrumen-analitik", "nama": "Instrumen Analitik" },
      "teknisi": { "id": 5, "nama": "Dwi Rahayu", "kode_teknisi": "DR" },
      "status": "disetujui",
      "keputusan": "PASS",
      "ketidakpastian_diperluas": 0.0303272,
      "sertifikat": { "id": 2, "nomor": "012-CAL-524", "status": "terbit" }
    }
  ],
  "ringkasan": {
    "total": 4, "pass": 3, "fail": 0,
    "belum_ada_keputusan": 1, "disetujui": 3
  },
  "penyaring": {
    "Dari tanggal": "2026-07-01", "Sampai tanggal": "2026-07-31",
    "Pelanggan": "PT Tirta Gracia", "Teknisi": null,
    "Kategori": "Instrumen Analitik", "Status": "disetujui", "Keputusan": "PASS"
  },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 15, "total": 4 }
}
```

> ### ⚠️ `tanggal_kalibrasi` di sini TANGGAL POLOS — beda dari endpoint lain
>
> `"2024-05-26"`, bukan `"2024-05-25T17:00:00Z"`. Ini **sengaja beda** dari field
> `date` di endpoint lain (lihat peringatan di §4 soal `diterbitkan_pada`), karena
> dua alasan:
>
> 1. Tanggalnya harus **sama** dengan yang dicetak di PDF/Excel. Satu sesi nggak
>    boleh punya dua tanggal beda tergantung dilihat di mana.
> 2. Nilainya harus bisa **dipakai balik jadi penyaring** `dari`/`sampai`. Kalau
>    dikirim sebagai ISO, tombol "filter tanggal sesi ini" balik kosong, dan
>    date-picker "1–31 Juli" diam-diam ngebuang sesi tanggal 1.
>
> Jadi di endpoint ini **boleh** dipakai apa adanya buat ditampilin & buat
> penyaring — nggak usah di-`DateTime.parse()`.

- **`ringkasan` dihitung dari SELURUH hasil penyaring, bukan dari halaman yang
  kebuka.** `ringkasan.total` == `meta.total`. Angka "total 15" yang ternyata cuma
  berarti "15 di halaman ini" itu menyesatkan di dokumen yang dikirim ke asesor.
- **`ringkasan.belum_ada_keputusan`** = sesi yang belum lewat perhitungan (draft /
  datanya belum cukup). Dipisah biar `total` nggak kelihatan nggak nyambung sama
  `pass + fail`.
- **`penyaring` itu penyaring versi manusia** — pakai buat nampilin "sedang
  disaring: …" di kepala layar. `null` artinya "Semua". Id dari lab lain
  di-resolve jadi `null`, bukan nama PT orang.
- **`ketidakpastian_diperluas` diambil dari titik PENENTU** (yang paling mepet
  batas toleransi) — **angka yang sama** yang muncul di `hasil` detail sesi, bukan
  rata-rata semua titik. `null` kalau sesinya belum dihitung.
- **Barisnya sengaja RINGKAS.** Nggak ada `titik[]`, `pembacaan_mentah`,
  `type_b_components`, atau `status_standar` — laporan itu tabel, dan bawa semua
  itu per baris bikin HP keabisan memori di lab yang datanya setahun. Butuh
  rinciannya? `GET /calibrations/{id}`.
- Urutannya `tanggal_kalibrasi` naik, lalu `id`. 15 baris/halaman.

### `GET /api/laporan/kalibrasi/export?format=pdf|xlsx`

`format` **wajib** (`pdf` atau `xlsx`; lain → `422`). **Semua penyaring di atas
jalan sama persis di sini** — query-nya dipegang satu service yang sama, jadi file
yang kedownload isinya sama dengan yang dilihat di layar.

- Balikannya **file download** (`Content-Disposition: attachment`), nama
  `Laporan-Kalibrasi-YYYY-MM-DD.pdf|xlsx`. Bukan JSON.
- **Nggak ada baris yang cocok → `404`**, bukan file kosong. File 11 kolom tanpa
  isi itu kelihatan kayak "nggak ada data" padahal bisa jadi penyaringnya salah.
- PDF-nya **A4 landscape** — 11 kolom nggak masuk di portrait.
- **Penyaring & ringkasannya ikut dicetak di kepala file.** File yang udah nyebar
  harus bisa dijelasin isinya periode/pelanggan mana; itu pertanyaan pertama
  asesor waktu lihat rekap.
- Maksimal **5000 baris** per export. Lebih dari itu, sempitin rentang tanggalnya.
- Throttle **`20/menit`** khusus buat `/export` (endpoint layar-nya nggak
  dithrottle) — bikin file dari 5000 baris jauh lebih berat dari baca biasa.
  Kalau kena, respons `429`. Jadi jangan panggil `/export` tiap penyaring diubah;
  panggil waktu tombol "Unduh" ditekan aja.

---

## 11. Riwayat Perubahan Data / Audit (live 27 Jul)

**Admin doang, dan baca-saja.** Nggak ada `POST`/`PUT`/`DELETE` — baris audit lahir
dari perubahan datanya sendiri, bukan dari request. Riwayat yang bisa ditulis tangan
berhenti jadi bukti.

Kenapa ini ada: ISO/IEC 17025 minta rekaman bisa ditelusuri — siapa ngubah apa,
kapan, dari nilai berapa ke berapa. Menu "Kelola Data" di desktop ngasih admin
keleluasaan kayak phpMyAdmin, dan phpMyAdmin nggak nyatet apa pun
(`arsitektur-desktop-database.md` Keputusan 4).

### `GET /api/audit-logs`

```
GET /api/audit-logs?entity_type=standards&entity_id=1&action=diubah
    &changed_by=5&dari=2026-07-01&sampai=2026-07-31&page=1
```

| Penyaring | Isi |
|---|---|
| `entity_type` | **nama TABEL**, mis. `standards`, `equipment`, `calibration_sessions` |
| `entity_id` | id baris yang diubah |
| `action` | `dibikin` · `diubah` · `dihapus` · `dipulihkan` (lain → `422`) |
| `changed_by` | id user pelakunya |
| `dari` / `sampai` | `YYYY-MM-DD`, **dua ujungnya inklusif** — aturan yang sama kayak `/laporan/kalibrasi` |

```json
{
  "data": [
    {
      "id": 812,
      "entitas": { "tipe": "standards", "id": 1 },
      "aksi": "diubah",
      "perubahan": {
        "nama": { "lama": "TH-1", "baru": "TH-1 (kalibrasi ulang)" }
      },
      "pelaku": { "id": 1, "nama": "Alex Misramto", "role": "admin" },
      "oleh_sistem": false,
      "catatan": null,
      "dicatat_pada": "2026-07-27T10:17:43Z"
    }
  ],
  "penyaring": { "entity_type": "standards", "entity_id": 1 },
  "meta": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 1 }
}
```

- **`entity_type` itu nama TABEL, bukan nama kelas PHP.** Nama kelas bisa berubah
  kalau kodenya di-refactor, dan riwayat lama nggak boleh ikut basi gara-gara itu.
- **`perubahan` disusun per kolom** (`lama` → `baru`), bukan dua objek utuh yang
  harus dibandingin sendiri di layar. Yang dicari asesor "apa yang berubah", bukan
  seluruh isi baris.
- **Cuma kolom yang BERUBAH yang kecatat.** Snapshot penuh tiap update bikin
  riwayatnya nggak kebaca manusia. `updated_at` sengaja nggak ikut — dia berubah di
  setiap update, jadi kalau ikut, tiap baris punya "perubahan" palsu.
- **`aksi: "dihapus"` / `"dipulihkan"` `perubahan`-nya kosong `{}`** — yang penting
  di situ kejadiannya, bukan nilainya. Isi barisnya masih bisa dilihat dari riwayat
  `dibikin`/`diubah` sebelumnya.
- **`oleh_sistem: true` (dan `pelaku: null`)** artinya perubahannya BUKAN dari orang
  yang login — job di queue (`GenerateCertificate`), command artisan, atau seeder.
  Itu bukan data hilang; dibiarin `null` lebih jujur daripada dituduhin ke user acak.
- Urutannya **terbaru dulu**, 25 baris/halaman.

> ### `password` nggak pernah masuk riwayat
>
> Nilainya diganti `"[disensor]"`, tapi **nama kolomnya tetap kecatat** — jadi masih
> kelihatan "password diubah", tanpa nyimpen isinya. Alasannya bukan kerapian:
> `password` itu hash, dan hash yang kesimpen di tabel lain berarti satu kebocoran
> jadi dua. Sama perlakuannya buat `remember_token`.

### `GET /api/audit-logs/export` — CSV

Penyaringnya **sama persis** kayak `index`. Balikannya file CSV (`text/csv`,
`Content-Disposition: attachment`), nama `Riwayat-Perubahan-YYYY-MM-DD.csv`.

```
Waktu,Entitas,ID,Aksi,Pelaku,Role,Kolom,Nilai Lama,Nilai Baru,Catatan
2026-07-27 10:17:43,standards,1,diubah,Alex Misramto,admin,nama,TH-1,TH-1 (kalibrasi ulang),
```

- **Satu baris per KOLOM yang berubah**, bukan satu baris dengan sel berisi JSON.
  CSV yang selnya JSON nggak bisa disaring atau di-pivot di Excel — dan itu
  satu-satunya alasan orang mau CSV-nya.
- Ada **BOM UTF-8**. Tanpa itu, Excel di Windows nampilin nama PT yang ada karakter
  non-ASCII jadi kacau, dan itu yang dibaca asesor.
- Pelaku `null` ditulis `(sistem)`.
- Maksimal **10.000 baris**, di-stream (nggak numpuk di memori). Throttle `20/menit`.

### Yang perlu diketahui frontend

- **Pencatatannya OTOMATIS lewat model event**, bukan ditempel per endpoint. Jadi
  semua jalur ikut kecatat: API, panel Filament, command artisan, job di queue.
  Nggak ada yang perlu dipanggil dari sisi klien.
- **Entitas yang diaudit:** `equipment`, `customers`, `standards`, `users`,
  `calibration_sessions`, `certificates`, `folders`, `folder_files`,
  `organizations`, `rooms`, `calibration_methods`, `equipment_categories`.
- **Riwayat lab lain nggak kebaca** — di-scope `organization_id`. Ini justru tabel
  yang paling nggak boleh kebuka lebar: isinya nilai sebelum & sesudah dari seluruh
  data lab, termasuk data pelanggan dan angka kalibrasi. Itu juga alasan
  teknisi/viewer dapat `403`, bukan daftar kosong.

---

## 12. Rumus Kalibrasi Berversi (live 27 Jul — fondasi)

**Admin doang.** Salah ngetik di sini ngubah angka yang masuk sertifikat
terakreditasi — ini menu paling berbahaya di seluruh aplikasi
(`arsitektur-desktop-database.md` Keputusan 5).

> ### ⚠️ Yang UDAH ada vs yang BELUM
>
> **Udah:** pencatatan versi + stempel versi di tiap hasil hitung + validasi rentang
> berlaku. Ini fondasi ketertelusurannya, dan **inilah bagian yang bikin rumus boleh
> diubah nanti**: tanpa stempel, ngubah rumus bikin seluruh riwayat kalibrasi nggak
> bisa dipertanggungjawabkan.
>
> **Belum:** evaluator ekspresinya. Jadi ngubah versi rumus **belum ngubah cara
> ngitungnya sama sekali** — dia baru nyatet "dihitung pakai aturan versi berapa".
> `sumber: "database"` ditolak `422` selama evaluatornya belum ada, sengaja: versi
> yang tercatat "dihitung dari database" padahal angkanya tetap dari kode itu riwayat
> yang bohong, dan itu lebih berbahaya daripada fiturnya belum ada.

### `GET /api/formulas`

```json
{
  "data": [
    {
      "id": 2, "kode": "gum-ph", "nama": "Ketidakpastian GUM (jalur pH)",
      "besaran": "ph", "jumlah_versi": 1,
      "versi_berlaku": {
        "id": 2, "nomor_versi": 1, "sumber": "kode", "status": "aktif",
        "berlaku_dari": "2000-01-01", "berlaku_sampai": null,
        "parameter": { "faktor_cakupan_k": 2, "dihitung_oleh": "App\\Services\\GumCalculator" },
        "ekspresi": null, "pembuat": null, "oleh_sistem": true
      }
    }
  ]
}
```

- **Versi 1 dibikin OTOMATIS** waktu organisasinya pertama buka endpoint ini atau
  pertama nyimpen kalibrasi. Nggak ada langkah setup — lab yang udah jalan sebelum
  fitur ini ada nggak boleh dipaksa nyetel rumus dulu sebelum bisa nyimpen kalibrasi.
- **`sumber: "kode"`** artinya angkanya dihitung program (`GumCalculator`), dan
  `parameter` nyatet nilai yang beneran dipakai. Ini jujur: rumus yang sekarang jalan
  MEMANG ada di kode.
- **`berlaku_dari` versi 1 dipatok `2000-01-01`**, bukan hari ini. Kalau dipatok hari
  ini, sesi lama yang di-revisi nggak nemu versi yang berlaku di tanggalnya.
- `berlaku_sampai: null` = masih berlaku sampai sekarang.

### Arti `status` — beda dari bacaan naifnya, dan ini penting

Keputusan 5 nulis "versi lama diarsipkan". Kalau itu dibaca sebagai "statusnya jadi
`arsip`", fiturnya justru **rusak**: pencarian versi per-tanggal cuma ngambil yang
`aktif`, jadi sesi tahun lalu nggak nemu versinya lagi.

Yang nentuin versi mana berlaku buat suatu tanggal itu **rentang tanggalnya**, bukan
statusnya:

| Status | Arti |
|---|---|
| `aktif` | Bagian dari garis waktu yang sah. Versi yang udah **digantikan tetap `aktif`**, cuma `berlaku_sampai`-nya ditutup — dia masih jawaban yang BENAR buat tanggal di rentangnya |
| `draft` | Belum masuk garis waktu. Boleh ada beberapa sekaligus; admin bisa nyiapin calon sebelum milih |
| `arsip` | **Dibatalkan**, jangan dipakai buat tanggal apa pun. Buat versi yang kebikin karena salah — **bukan** buat versi yang digantikan secara wajar |

### `GET /api/formulas/{id}/versions`

Riwayat versi, terbaru dulu. Sesudah nerbitin versi 2:

```
v2: aktif  2026-08-01 → sekarang
v1: aktif  2000-01-01 → 2026-07-31
```

### `GET /api/formulas/{id}/versi-berlaku?tanggal=2024-05-26`

**Pertanyaan yang bikin fitur ini ada:** *"sesi 26 Mei dihitung pakai aturan yang
mana?"* — beda dari "aturan apa yang dipakai sekarang". `tanggal` opsional (bawaan:
hari ini). Balikannya satu objek versi, atau `null` kalau nggak ada yang berlaku.

### `POST /api/formulas/{id}/versions` — terbitin versi baru

```json
{
  "sumber": "kode",
  "berlaku_dari": "2026-08-01",
  "parameter": { "faktor_cakupan_k": 2 },
  "catatan": "Ikut IK Rev.7",
  "langsung_aktif": true
}
```

- **`langsung_aktif: true` bikin DUA hal dalam satu transaksi**: versi baru dibikin
  `aktif`, dan rentang versi sebelumnya **ditutup sehari sebelum** versi baru mulai.
  Dua langkah itu satu operasi — kalau dipisah, ada jeda di mana dua versi sama-sama
  berlaku buat satu tanggal, dan di jeda itu "sesi ini pakai versi mana" nggak punya
  jawaban.
- **Tanpa `langsung_aktif` → jadi `draft`**, dan ini yang disarankan. Keputusan 5
  minta "uji coba sebelum disimpan", dan draft yang belum masuk garis waktu itu
  tempat paling aman buat nyoba.
- `sumber: "database"` → `422` (evaluator belum ada, lihat peringatan di atas).
- Rentang yang tumpang tindih sama versi aktif lain → `422` dengan pesan yang nyebut
  versi mana yang nabrak.

### `PATCH /api/formula-versions/{id}`

Cuma `status` (→ `aktif` / `arsip`) dan `catatan`.

- **Rentang tanggalnya SENGAJA nggak bisa diubah.** Ngubah rentang versi yang udah
  dipakai berarti ngubah jawaban dari "sesi ini dihitung pakai versi apa" — secara
  retroaktif, buat sesi yang sertifikatnya udah terbit. Kalau aturannya salah,
  jalannya terbitin versi baru.
- **Versi yang udah kepakai di hasil hitung nggak bisa diarsipin** → `422`. Kalau
  diarsipin, angka yang udah terbit nggak bisa dijelasin lagi — kebalikan dari
  gunanya fitur ini.
- Ngaktifin `draft` yang rentangnya nabrak → `422`.

### Stempel di hasil hitung

`uncertainty_calculations.formula_version_id` — versi yang **menghasilkan** angka di
baris itu. Distempel dari versi yang berlaku di **tanggal kalibrasi** sesi, bukan
"yang aktif sekarang".

Bedanya bikin atau nggak: sesi 26 Mei yang di-revisi hari ini harus tetap kestempel
versi yang berlaku 26 Mei. Kalau pakai "yang aktif sekarang", angkanya dihitung
aturan lama tapi kecatat aturan baru — dan itu **lebih menyesatkan daripada nggak
distempel sama sekali**.

`null` cuma buat baris yang udah ada sebelum fitur ini dipasang. Itu jujur: versinya
beneran nggak diketahui, dan nebak lebih buruk.

> **Semua perubahan versi rumus kecatat di `audit_logs`** (§11) — Keputusan 5 minta
> itu eksplisit. Termasuk penutupan rentang versi lama, jadi satu operasi "terbitin
> versi baru" ninggalin jejak lengkap: siapa, kapan, versi mana yang ditutup.

---

## 13. Kirim Sertifikat ke Email Pelanggan (live 27 Jul)

**Admin doang.** Nutup `permintaan-endpoint-fase-2.md` §3d.

Kenapa di backend dan bukan di mobile — dua alasan dari permintaannya:
alamat pengirim harus domain lab (bukan Gmail teknisi), dan **pengirimannya wajib
tercatat buat audit**: siapa ngirim sertifikat ke siapa, kapan.

### `POST /api/certificates/{id}/kirim-email`

```json
{ "ke": ["pic@pelanggan.co.id"], "cc": ["manajer@pelanggan.co.id"] }
```

| Field | Aturan |
|---|---|
| `ke` | **wajib**, array, 1–10 alamat, format email valid |
| `cc` | opsional, array, maks 10 |

```json
{
  "message": "Sertifikat terkirim ke pic@pelanggan.co.id.",
  "data": {
    "id": 7,
    "ke": ["pic@pelanggan.co.id"],
    "cc": ["manajer@pelanggan.co.id"],
    "status": "terkirim",
    "error": null,
    "dikirim_oleh": { "id": 1, "nama": "Alex Misramto" },
    "dikirim_pada": "2026-07-27T04:31:24Z"
  }
}
```

- **PDF-nya DILAMPIRKAN**, bukan dikirim sebagai tautan unduh. Tautan ke disk privat
  butuh login dan pelanggan nggak punya akun; tautan publik berarti sertifikat bisa
  diakses siapa pun yang dapat URL-nya. Nama lampirannya `Sertifikat-{nomor}.pdf`.
- **Dikirim SINKRON**, bukan lewat queue. Admin yang mencet "Kirim" perlu tahu
  hasilnya saat itu juga — kiriman yang di-queue lalu gagal diam-diam berarti
  pelanggan nggak pernah nerima dan nggak ada yang sadar sampai ditanya. Jadi
  responsnya baru balik sesudah email-nya beneran keluar.
- Alamat yang dobel digabung otomatis.
- Throttle **`20/menit`** — ini ngirim dokumen resmi ke luar, bukan baca data.

**Status yang mungkin:**

| Kode | Kapan |
|---|---|
| `200` | Terkirim |
| `502` | **Gagal kirim** (SMTP nolak/mati). Percobaannya **tetap tercatat**, dan `data.error` bawa pesan aslinya |
| `422` | Sertifikat belum terbit · sertifikat belum punya PDF · PDF-nya raib dari penyimpanan · alamat nggak valid · lebih dari 10 alamat |
| `403` | Teknisi / viewer |
| `404` | Sertifikat lab lain |

> **`422`-nya dipisah per sebab.** "Belum terbit" dan "belum punya PDF" itu dua
> masalah beda dengan dua tindakan beda, jadi pesannya nggak digabung — pesan
> gabungan bikin admin baca "harus terbit & punya PDF, yang ini statusnya `terbit`"
> dan nyangka backend-nya ngaco.

### `GET /api/certificates/{id}/riwayat-email`

Riwayat pengiriman sertifikat itu, **terbaru dulu**. Bentuk barisnya sama kayak
`data` di atas.

- **Semua percobaan tercatat, termasuk yang GAGAL.** "Kami udah nyoba kirim tapi
  alamatnya nolak" itu justru informasi yang dicari waktu pelanggan ngaku nggak
  nerima. Kalau cuma yang sukses dicatat, riwayatnya bohong lewat kelalaian.
- **Riwayatnya tabel terpisah, bukan kolom `dikirim_pada` di sertifikat.** Kirim
  ulang itu kejadian normal (pelanggan kehilangan filenya, ganti PIC, alamatnya
  salah ketik), dan kolom tunggal cuma nyimpen yang terakhir — "kapan kita ngirim ke
  PIC yang lama" jadi nggak bisa dijawab.
- Barisnya **nggak bisa diubah** (ditolak di level model), sama alasannya kayak
  `audit_logs`.

### Soal alamat pengirim — dan kenapa `From`-nya bukan email lab

Permintaannya: *"alamat pengirim harus domain lab (bukan Gmail teknisi)."* Itu
dipenuhi lewat **`MAIL_FROM_ADDRESS` di `.env`**, yang disetel ke domain lab.

Email organisasi (`organization.email`) ditaruh di **`Reply-To`**, **bukan** `From`.
Ini bukan kelalaian: `From` yang domainnya beda dari domain yang beneran ngirim bikin
**SPF/DKIM gagal**, dan email-nya masuk spam atau ditolak server penerima. Jadi
`From` = domain pengirim yang sah, dan balasan pelanggan tetap nyampe ke lab lewat
`Reply-To`.

> ### Yang masih perlu dari sisi operasional
>
> Kodenya **selesai dan udah diuji**, termasuk lampiran PDF-nya. Yang belum: **isi
> `MAIL_*` di `.env` produksi**. Sekarang `MAIL_MAILER=log`, jadi email-nya kerender
> lengkap tapi masuk `storage/logs/laravel.log`, bukan ke internet — dan itu memang
> perilaku yang bener buat dev.
>
> Begitu SMTP-nya diisi, **nggak ada kode yang perlu diubah**. Yang perlu disetel:
>
> ```
> MAIL_MAILER=smtp
> MAIL_HOST=…
> MAIL_PORT=587
> MAIL_USERNAME=…
> MAIL_PASSWORD=…
> MAIL_FROM_ADDRESS="sertifikat@domain-lab.com"   ← WAJIB domain lab
> MAIL_FROM_NAME="PT Sidik Kalibrasi"
> ```

---

## 14. Tanda Tangan di Sertifikat (live 27 Jul)

**Admin doang.** Nutup `permintaan-endpoint-fase-2.md` §3c — bagian terakhir dari
"Pelengkap sertifikat" (logo & QR udah live duluan di §8).

Gambar tanda tangan penanggung jawab teknis, dicetak **di atas garis tanda tangan**
di footer sertifikat. Nama & jabatannya tetap dari `settings.penandatangan_nama` /
`settings.penandatangan_jabatan` yang udah ada — yang baru di sini cuma gambarnya.

> **Gambarnya disimpen di disk PRIVAT, dan itu keputusan keamanan.** Logo ada di disk
> publik karena itu identitas yang memang dipajang. Tanda tangan **nggak boleh**:
> gambar tanda tangan yang URL-nya bisa diakses siapa pun berarti siapa pun bisa
> nempelin ke dokumen palsu. Konsekuensinya buat mobile: **responsnya nggak bawa
> `tanda_tangan_url`** — nggak akan pernah ada. Yang ada cuma penanda
> `punya_tanda_tangan`, dan pratinjaunya lewat endpoint yang ngecek hak akses.

### Yang nambah di objek organisasi

Muncul di semua respons yang bawa organisasi (`GET /organization`, dst):

```json
{
  "punya_tanda_tangan": true,
  "tanda_tangan": { "geser_x_mm": -8.5, "geser_y_mm": 4, "lebar_mm": 42 }
}
```

`tanda_tangan` **selalu ada dan selalu lengkap**, walaupun gambarnya belum diunggah —
isinya nilai bawaan. Mobile nggak perlu nyiapin cabang buat `null`.

### Yang nambah di objek `sertifikat`

Muncul di `GET /calibrations/{id}` (objek `sertifikat` yang di-embed) **dan** di
respons sertifikat sendiri:

```json
"penanda_tangan": { "nama": "Alex Misramto", "jabatan": "Technical Manager" }
```

- **Nilainya BEKU dari snapshot sertifikat**, bukan dibaca live dari pengaturan
  organisasi. Ganti penandatangan di pengaturan **nggak ngubah sertifikat yang udah
  terbit** — kalau berubah, sertifikat lama bakal nampilin nama yang beda dari yang
  kecetak di PDF-nya sendiri, dan yang lihat nggak punya cara buat tahu mana yang
  bener.
- `null` kalau sertifikatnya belum terbit / belum punya snapshot.
- **Nggak ada `ttd_url`** di sini, sama alasannya kayak di atas.

### `POST /api/organization/tanda-tangan`

Multipart, field **`tanda_tangan`**.

| Field | Aturan |
|---|---|
| `tanda_tangan` | **wajib**, gambar, **PNG doang**, maks **2 MB** |

> **PNG doang — bukan PNG/JPG kayak logo.** JPG nggak punya latar transparan, jadi
> tanda tangannya kecetak sebagai **kotak putih** yang nutupin garis tanda tangan &
> nama di bawahnya. Rusaknya **sunyi**: nggak ada error, ketahuannya baru waktu ada
> yang buka PDF-nya. Jadi ditolak di pintu masuk, dan pesan `422`-nya nyebut alasannya
> — tampilkan apa adanya ke admin, jangan diganti "format tidak didukung".

Balikannya objek organisasi dengan `punya_tanda_tangan: true`. Gambar lama otomatis
kehapus — tapi **sesudah** yang baru kesimpen, biar unggahan yang gagal nggak bikin
sertifikat berikutnya terbit tanpa tanda tangan tanpa ada yang sadar.

### `GET /api/organization/tanda-tangan`

Nge-stream gambarnya (`Content-Type: image/png`) — **bukan JSON**. Ini yang dipakai
buat nampilin pratinjau di layar pengaturan & nanti di UI drag-and-drop.

Ada karena file-nya di disk privat, jadi nggak bisa dipanggil lewat URL storage.
Butuh header `Authorization` kayak endpoint lain — di Flutter berarti lewat
`Image.memory` dari respons `http`, **bukan** `Image.network`.

`404` kalau belum ada gambarnya.

### `DELETE /api/organization/tanda-tangan`

Hapus gambarnya. Sertifikat balik nyetak **garis kosong buat tanda tangan basah** —
itu state yang sah, bukan sertifikat rusak.

### `PATCH /api/organization/tanda-tangan/posisi`

Posisi & ukuran cetaknya. Semua field opsional — kirim yang berubah aja.

```json
{ "geser_x_mm": -8.5, "geser_y_mm": 4, "lebar_mm": 42 }
```

| Field | Aturan | Bawaan |
|---|---|---|
| `geser_x_mm` | numerik, **−40 … 40**. Negatif = ke kiri | `0` |
| `geser_y_mm` | numerik, **−40 … 40**. **POSITIF = NAIK** | `0` |
| `lebar_mm` | numerik, **10 … 80**. Tingginya ngikut, rasio dijaga | `35` |

- **Kirim `null`** di suatu field = balikin ke bawaan (kuncinya dibuang, bukan
  disimpen `null`).
- Setelan lain di `settings` (penandatangan, kode dokumen, ambang reminder, masa
  berlaku) **nggak keinjek** — yang dikirim digabung, bukan nimpa seluruh objek.
- Nilai di luar batas → `422`. Nilai ngawur yang masuk lewat jalur lain
  (`PUT /organization` yang nerima `settings` bebas, atau panel admin) **tetap
  dibatasin waktu dibaca**, jadi API nggak akan pernah balikin angka di luar rentang.

> **`geser_y_mm` POSITIF = NAIK.** Ini kebalikan dari koordinat layar, jadi gampang
> kebalik waktu bikin UI drag. Kalau drag ke atas malah bikin tanda tangannya turun,
> yang salah tanda di sisi UI — backend-nya udah dites buat arah ini.

> **Geserannya RELATIF ke blok tanda tangan, bukan koordinat absolut halaman.** Tinggi
> isi sertifikat berubah-ubah (jumlah titik ukur & standar beda tiap sesi), jadi blok
> tanda tangannya naik-turun. Koordinat absolut yang pas di satu sertifikat bakal
> nimpa tabel di sertifikat lain — dan itu baru ketahuan sesudah PDF-nya nyampe
> pelanggan.

### Kenapa posisinya disimpen SEKALI, bukan per sertifikat

Sertifikat yang udah terbit itu **dokumen terkendali**. Kalau posisi tanda tangan
disimpen per sertifikat, berarti sertifikat yang udah dikirim ke pelanggan bisa
diubah — dan dua orang bisa punya PDF beda dengan nomor sertifikat yang sama. Dengan
disimpen di tingkat template, admin geser **sekali** dan semua sertifikat konsisten.

Ini juga alasan tinggi blok tanda tangannya **dipatok**, nggak ikut tinggi gambar: dua
sertifikat dengan format resmi yang sama nggak boleh beda tata letak cuma gara-gara
yang satu diunggahin gambar TTD.

**Status yang mungkin** (berlaku buat keempat endpoint):

| Kode | Kapan |
|---|---|
| `200` | Beres |
| `422` | Bukan PNG · lebih dari 2 MB · nggak ada file · nilai posisi di luar batas |
| `403` | Teknisi / viewer — **nggak boleh nyentuh sama sekali**, termasuk pratinjau |
| `404` | `GET` waktu gambarnya belum ada |
| `401` | Tanpa token |

> ### Yang masih perlu dari sisi operasional
>
> Kodenya **selesai dan udah diuji** end-to-end sampai PDF-nya (22 test), pakai PNG
> placeholder. Yang belum: **file PNG tanda tangan asli + jabatan resminya** dari lab.
> Nggak ada kode yang perlu diubah waktu file aslinya masuk — admin tinggal unggah
> lewat panel atau endpoint di atas.

---

## Akun buat nyoba (seeder)

| ID pegawai | Email | Role | Status |
|---|---|---|---|
| `SDK-0001` | admin@sidik.test | admin | aktif |
| `SDK-0002` | teknisi@sidik.test | teknisi | aktif |
| `SDK-0003` | viewer@sidik.test | viewer | aktif |
| `SDK-0099` | eko@sidik.test | teknisi | **pending** (buat nyoba layar "belum disetujui") |

Password semua `rahasia123`. Login boleh pakai ID pegawai **atau** email.

Datanya juga udah keisi: **5 alat** (2 di antaranya sengaja `overdue`), 2 pelanggan, 10 kategori, 151 rentang kemampuan kalibrasi. Jadi layar Dashboard & Daftar Alat bisa langsung nampilin data asli — nggak usah pakai dummy.

---

## Yang paling penting buat disepakati sekarang

1. ✅ **`GET /api/health`** — udah ada sejak 14 Jul.
2. ✅ **`POST /api/login` pakai `identifier`** (ID pegawai **atau** email) — udah jalan, dua-duanya.
3. ✅ **Akun `pending` ditolak login di backend** (403) — udah, dan diuji pakai curl langsung (bukan cuma lewat app).
4. ✅ **User nggak bisa milih role sendiri waktu daftar** — `role` dari client diabaikan; ada test yang khusus nyoba nyelipin `"role":"admin"` waktu register, hasilnya tetap `teknisi` + `pending`.
5. ✅ **Nama field: bahasa Indonesia** — diikutin, `nama` bukan `name`. Dikunci pakai test, jadi kalau ada yang ngubah diam-diam, testnya merah duluan sebelum app-nya rusak.
6. ✅ **Nilai enum persis** — diikutin, termasuk `PASS`/`FAIL` huruf besar. **Kecuali satu**: kode kategori nggak sesingkat contoh di dokumen ini (`suhu-dan-kelembapan`, bukan `suhu`) — ambil dari `GET /api/categories`, jangan di-hardcode.
7. ✅ **CORS** — nggak disentuh, sesuai saran kamu.

### Tiga lubang yang sempat kebuka — semuanya udah ditutup 14 Jul

**1. `POST /api/logout-all`** — auth, semua role.
```json
{ "message": "Berhasil keluar dari semua perangkat.", "data": { "sesi_dicabut": 2 } }
```
Token Sanctum nggak kadaluarsa sendiri, jadi tanpa ini sesi di HP yang ilang bakal hidup **selamanya**. Mobile: taruh tombol "Keluar dari semua perangkat" di layar Profil. Sesudah manggil ini, token yang lagi dipakai ikut mati juga — jadi langsung lempar ke layar login.

**2. `organization_id` pendaftar baru sekarang langsung keisi**, nggak nunggu approve. Satu instalasi = satu PT, jadi nggak ada yang perlu dipilih. Layar profil aman, nggak bakal dapat PT kosong.

**3. Admin bisa benerin akun & nyetel ulang password** — ini penting: reset password jalannya lewat **email**, tapi login pakai **ID pegawai**. Orang yang salah ketik emailnya waktu daftar (`eko@gmial.com`) bakal kekunci selamanya kalau nggak ada yang bisa benerin.

- **`PUT /api/users/{id}`** — admin-only. Body (semua opsional): `nama`, `email`, `employee_id`, `department`, `role`, `status`. Nyetel `status: "nonaktif"` langsung mutusin sesi orangnya.
- **`POST /api/users/{id}/reset-password`** — admin-only. Body: `{ "password": "passwordbaru123" }` (min 8). Semua sesi lama user itu dicabut. Password barunya admin kasih tahu langsung ke orangnya.

Teknisi/viewer yang nembak dua endpoint itu dapat `403` — udah dites, termasuk skenario teknisi nyoba nyetel ulang password admin.

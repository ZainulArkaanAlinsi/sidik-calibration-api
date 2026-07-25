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
> - Akun dev buat nyoba: `SDK-0001` (admin) · `SDK-0002` (teknisi) · `SDK-0003` (viewer) · `SDK-0099` (sengaja `pending`, buat nyobain layar "belum disetujui"). Password semua `rahasia123`.

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
>       "satuan_ketidakpastian": "mm", "faktor_cakupan": 2, "metode": "SIDIK-IK-CAL-0515_Rev.3" }
> ] } }
> ```
> **`range_min` bisa `null`** — 59 dari 151 kemampuan emang nggak punya batas bawah numerik: ada yang titik tunggal (buret "25 mL"), ada yang batasnya kata-kata (oven "ambient ~ 300 °C" → teks aslinya ada di `range_note`). Jangan diparse jadi `double` mentah-mentah, nanti crash.

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
> - **Alat yang `toleransi`-nya masih kosong ditolak.** Tanpa batas, PASS/FAIL nggak ada artinya. Isi dulu lewat `PUT /api/equipments/{id}`.
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
      "berlaku_sampai": "2027-07-13T17:00:00Z",
      "masih_berlaku": true,
      "ketidakpastian": 0.0004,
      "satuan_ketidakpastian": "mm",
      "faktor_cakupan": 2,
      "drift": null
    }
  ]
}
```

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

### Riwayat
`GET /api/calibrations?mine=true` — teknisi cuma lihat kalibrasi miliknya sendiri; **admin lihat semua**. Filter ini penting, jangan sampai teknisi bisa lihat punya orang lain.

---

## 5. Approval & Sertifikat (dibutuhin Minggu 8)

### `POST /api/calibrations/{id}/approve` — **admin doang**, teknisi/viewer → `403`.
Response: sesi dengan `status: "disetujui"`. Generate sertifikat jalan di **queue** (async), jadi `certificate_id` boleh masih `null` sesaat.

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
  `sesi_perlu_revisi` · `sertifikat_terbit` · `umum` (fallback).
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

---

## 8. Master Data PT & Pelanggan — **admin doang** (live 14 Jul)

Belum ada di kontrak versi kamu, tapi udah jalan di backend. Dibutuhin buat layar Pengaturan (admin).

- **`GET /api/organization`** · **`PUT /api/organization`** — data PT: `nama`, `alamat`, `telepon`, `email`, `no_akreditasi`. Ini yang bakal dicetak di kop sertifikat. *Nggak ada create/delete* — satu instalasi = satu PT.
  > **✅ 18 Jul — response-nya lebih gemuk dari yang didokumentasiin, mobile baru nyusul makainya**: `standar_akreditasi`, `akreditasi_mulai`, `akreditasi_berakhir`, dan `akreditasi_masih_berlaku` (dihitung backend, read-only — jangan dikirim balik waktu `PUT`). Ini yang nentuin akreditasi lab (LK-285-IDN) masih sah apa nggak; sebelumnya nggak ada di layar mana pun. `settings` (array) juga ada di response tapi mobile sengaja belum kasih UI buat itu — bentuknya belum didokumentasiin.
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
> **Sementara ini:** kalau pelanggannya udah punya arsip, ambil dari
> `data[].pelanggan.id` (pakai `?q=` buat nyari). Kalau belum, teknisi memang
> belum punya jalan — **butuh endpoint lookup pelanggan yang kebuka semua role**,
> dan itu belum ada. Status: [`BACA-DULU-BACKEND.md`](BACA-DULU-BACKEND.md) §3.

---

## 8a. Folder Manager — buka folder satu PT (live 25 Jul)

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

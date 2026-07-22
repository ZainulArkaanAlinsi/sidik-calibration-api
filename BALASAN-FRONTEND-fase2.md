# Balasan Backend — Fase 2

*22 Juli 2026 malam. Semua di bawah sudah jalan & ada test-nya di branch `feat/kalibrasi-ph-lengkap-dan-arsip`.*

---

## 🔴 BACA DULU — kredensial uji sudah berubah

Dokumen kalian nulis: *"`ASM-0001` dan `@asmo.test` jangan diganti kalau belum dikoordinasi."*

**Sayangnya keburu diganti hari ini**, atas permintaan pemilik produk (rename brand ASMO → Sidik menyeluruh). Pesan kalian dan rename-nya kejadian di hari yang sama, jadi kita kesenggol. Maaf.

| | Lama ❌ | Baru ✅ |
|---|---|---|
| Login | `admin@asmo.test` | `admin@sidik.test` |
| | `teknisi@asmo.test` | `teknisi@sidik.test` |
| | `viewer@asmo.test` | `viewer@sidik.test` |
| | `eko@asmo.test` | `eko@sidik.test` |
| employee_id | `ASM-000x` | `SDK-000x` |
| Deep link reset password | `asmo://reset-password` | `sidik://reset-password` |
| Database | `asmo_db` | `sidik_db` |

Password tetap `rahasia123`. Field login tetap `identifier` (email **atau** employee_id).

**Yang perlu kalian lakukan:** ganti kredensial uji, dan **daftarkan skema `sidik://`** di Android/iOS — kalau nggak, alur lupa-password putus (email kekirim, link nggak buka app).

Kalau ada versi app lama yang masih beredar, backend bisa dibalikin sementara lewat env `RESET_PASSWORD_URL=asmo://reset-password` sampai semua user update. Bilang aja kalau butuh.

Detail lengkap: `HANDOFF-FRONTEND-rename-sidik.md`.

---

## §2a — `titik_ukur`: kirim yang **TERKOREKSI SUHU**. Kalian sudah benar.

**Jawaban: terus kirim `4.009244572` (terkoreksi), jangan nominal `3.99`.** Nggak ada yang perlu dirombak.

Alasannya bukan selera, tapi karena angka di sertifikat ikut berubah:

`titik_ukur` itu **nilai benar** yang dipakai ngitung koreksi:
```
koreksi = rata_rata − titik_ukur
```

Sertifikat asli 012-CAL-524 titik pH 4 mencetak:
```
Standard Value : 4.009244572     ← ini titik_ukur
UUT Reading    : 4.000
Correction     : 0.009244572
```

Kalau dikirim nominal `3.99`, koreksinya jadi `0.010` — **beda dari sertifikat asli**, dan yang tercetak buat pelanggan jadi salah.

Kenapa `3.99` muncul di worksheet: itu **label di botol buffer**, nilai pada suhu referensi. Nilai pH buffer bergerak mengikuti suhu larutan, jadi nilai yang benar-benar dipakai dihitung dulu dari suhu larutan lewat rumus kuadratik (mis. buffer pH 4: `y = 3e-5·x² − 0.0023·x + 4.0455`). Di sheet perhitungan Excel-nya, kolomnya memang sudah jadi `4,0092`, bukan `3,99`.

### ⚠️ Kenapa ini gampang salah tanpa ketahuan

Backend nyocokin CMC pakai `round(titik_ukur)` dengan toleransi geser 0.1. Artinya `3.99` **maupun** `4.0092` sama-sama ketemu CMC buffer 4 — nggak ada error, nggak ada 422. Yang beda cuma **angka koreksi di sertifikat**. Jadi kalau salah kirim, semuanya kelihatan normal sampai ada yang membandingkan sertifikat dengan arsip lama.

Sudah diverifikasi: record pH ter-seed mereproduksi sertifikat 012-CAL-524 **persis** (U95% `0.023432 / 0.021109 / 0.031000`) memakai nilai terkoreksi.

**Opsi kalau kalian lebih suka kirim nominal:** backend punya rumus kuadratik ketiga buffer, jadi bisa saja backend yang menghitung dari `nominal + suhu larutan`. Tapi itu penambahan baru — **jangan tunggu itu**, kontrak sekarang sudah benar. Bilang kalau memang lebih enak begitu.

---

## §2b — Matriks peran: **SUDAH ADA**

- **`MATRIKS-PERAN.md`** di root repo — endpoint × role, lengkap 4 kelompok.
- **`GET /api/me/permissions`** juga sudah jadi (kalian bilang "kalau mau sekalian rapi" — sekalian dibikin).

```jsonc
{ "data": { "role": "teknisi", "boleh": ["alat.tambah", "kalibrasi.buat", ...] } }
```
viewer **13** ability (baca doang) ⊂ teknisi **24** ⊂ admin **47**.

Daftarnya diturunkan dari middleware di `routes/api.php`, dan ada test yang mastiin ability yang **tidak** dipunyai satu role beneran ditolak 403 — jadi kalau daftar & middleware nyimpang, test jebol duluan.

### Jawaban 3 pertanyaan kalian
1. **Viewer boleh lihat arsip & sertifikat?** **Ya** — baca semua, nggak bisa nulis. Tebakan kalian benar.
2. **Teknisi lihat sesi teknisi lain?** **Tidak** — dan bukan cuma disaring di daftar: buka per-ID punya orang lain dibalas **404**, jadi nggak bisa ditebak. Tebakan kalian benar.
3. **Role keempat (Manajer Teknis)?** **Tidak — sudah diputuskan.** Pemilik produk memilih tetap sebagai **atribut `department`**, bukan role keempat. Penanda tangan = admin yang meng-approve, jabatannya dari `department`-nya. Alur approval tidak berubah, dan §3 #9 sudah selesai dikerjakan atas dasar keputusan ini.

### Soal bug "mulus di admin, mentok di teknisi"
Akar masalahnya: `GET /customers` admin-only tapi `pelanggan_id` wajib di form Tambah Alat. **Pakai `GET /api/arsip/perusahaan?search=` buat dropdown pelanggan** — kebuka semua role, ada pencarian, balikin `id` + `nama`. Itu jalan keluar tanpa perlu ngubah hak akses master data.

---

## §3 — Status nomor 1–12

| # | Permintaan | Status |
|---|---|---|
| 1 | `qr_token` di objek sertifikat | ✅ selesai — `qr_token` + `qr_url` |
| 2 | `nomor_order` + `tanggal_terima` | ✅ selesai — field-nya udah lama ada; datanya di record pH contoh sekarang ikut diisi |
| 3 | `employee_id` di objek teknisi | ✅ selesai |
| 4 | `equipment` digemukin (+pelanggan) | ✅ selesai |
| 5 | `merk_type` + `tertelusur_ke` di standar | ✅ selesai (+ `serial_number`) |
| 6 | `logo_url` di organisasi | ✅ selesai (+ `POST /organization/logo`) |
| 7 | Notifikasi kejadian butuh admin | ✅ selesai |
| 8 | `POST /calibrations/preview` | ✅ selesai |
| 9 | Penanda tangan / Manajer Teknis | ✅ selesai — keputusan: **atribut `department`**, bukan role keempat |
| 10 | `POST /certificates/{id}/kirim-email` | ✅ selesai |
| 11 | `room_id` di sesi kalibrasi | ✅ selesai |
| 12 | Laporan + export | ✅ selesai (PDF & CSV; `.xlsx` asli lihat catatan) |

**Semua 12 permintaan selesai.** Rinciannya di bawah.

### Endpoint baru

```
GET  /api/me/permissions                       ability pemanggil
GET  /api/notifications?belum_dibaca=1         + meta_tambahan.belum_dibaca (badge)
POST /api/notifications/{id}/baca
POST /api/notifications/baca-semua
POST /api/calibrations/preview                 hitung tanpa simpan
POST /api/me/ttd                               unggah TTD sendiri (multipart `ttd`)
POST /api/organization/logo                    unggah logo (multipart `logo`)
POST /api/certificates/{id}/kirim-email        { ke: [...], cc: [...] }
GET  /api/laporan/kalibrasi                    + filter, berpaginasi
GET  /api/laporan/kalibrasi/export?format=pdf|csv
```

### #8 `POST /calibrations/preview`

Ngitung koreksi & U95% dari pembacaan **tanpa nyimpen apa pun** — nggak bikin sesi, nggak nomor sesi, nggak notifikasi. Aman dipanggil tiap kali teknisi ngetik.

```jsonc
POST { "equipment_id": 6, "standard_id": 3,
       "measurements": [{ "titik_ukur": 6.9889072, "pembacaan": [7.01,7.01,7,7,7] }] }

→ { "data": { "keputusan": "PASS", "titik": [ { ...bentuknya SAMA kayak titik[] di detail sesi... } ] } }
```

Bentuk `titik[]`-nya sengaja disamain sama `GET /calibrations/{id}`, jadi parser mobile bisa dipakai ulang. Ada test yang mastiin angka preview **sama persis** sama yang tersimpan lewat `POST /calibrations` biasa — kalau dua jalur itu pernah nyimpang, sertifikat bakal beda dari yang dilihat teknisi waktu ngisi.

### #9 Penanda tangan

Keputusan pemilik produk: **"Manajer Teknis" itu atribut `department`, bukan role keempat.** Penanda tangan = admin yang meng-approve. Jadi cukup atur `department` akun admin dengan benar.

```jsonc
"penanda_tangan": { "nama": "Alex Misramto", "jabatan": "Technical Manager", "ttd_url": "..." }
```
Ada di objek `sertifikat` (sesuai permintaan kalian) DAN di level atas respons sesi — yang level atas kepakai buat sesi yang udah disetujui tapi PDF-nya belum kelar digenerate.

Unggah TTD: `POST /api/me/ttd`. Sengaja **TTD sendiri**, bukan `/users/{id}/ttd` yang dikelola admin — kalau admin bisa ngunggahin TTD orang lain, sertifikat terakreditasi bisa ditandatangani atas nama orang yang nggak pernah menyetujuinya. PNG/JPG doang (SVG nggak kebaca dompdf).

### #10 Kirim email

`POST /api/certificates/{id}/kirim-email` — admin doang. PDF **dilampirkan** (bukan tautan unduh: tautan butuh login, pelanggan nggak punya akun). Tiap pengiriman tercatat di tabel `certificate_emails` buat audit — siapa ngirim ke siapa, kapan.

Sertifikat yang PDF-nya belum jadi ditolak `422` — email tanpa lampiran lebih membingungkan daripada nggak ada email.

⚠️ Jalan otomatis begitu SMTP lab diisi. Sekarang `MAIL_MAILER=log`, jadi isinya nongol di `storage/logs`, belum beneran kekirim.

### #11 `room_id`

Disepakati: dipakai. `POST`/`PUT /calibrations` nerima `room_id`, dan responsnya bawa:
```jsonc
"ruangan": { "id": 1, "nama": "Lab. Uji A" }
```
Ini yang ngisi kolom *Calibration Location*. Beda dari `lokasi` yang cuma `lab`/`onsite`. Silakan tambahin dropdown "Ruangan" — daftarnya dari `GET /api/rooms` (kebuka semua role).

### #12 Laporan

```
GET /api/laporan/kalibrasi?dari=&sampai=&pelanggan_id=&teknisi_id=&kategori=&status=&keputusan=&page=
GET /api/laporan/kalibrasi/export?format=pdf|csv   (+ filter yang sama)
```
Responsnya bawa `meta.ringkasan` (total/pass/fail/disetujui/bersertifikat) yang dihitung dari **seluruh hasil filter**, bukan cuma halaman yang lagi dibuka — biar angka footer nggak berubah tiap ganti halaman.

Cakupan datanya ngikut aturan yang sama kayak `/calibrations`: teknisi cuma sesinya sendiri, admin & viewer seluruh lab. Ringkasan ikut disaring.

⚠️ **`.xlsx` asli belum didukung** — butuh paket tambahan (phpspreadsheet) yang belum dipasang. `format=csv` kebuka langsung di Excel dan udah pakai BOM UTF-8 biar nama pelanggan non-ASCII nggak ngacak. Kalau kalian butuh `.xlsx` beneran, bilang — tinggal pasang paketnya.

Ekspor dibatesin **5000 baris** sekali jalan; lebih dari itu dibalas `422` dengan pesan biar rentangnya dipersempit.

### Bentuk field baru (1–6)

**Detail sesi — `GET /api/calibrations/{id}`:**
```jsonc
"equipment": {
  "id": 6, "nama_alat": "pH Meter", "serial_number": "B628755900",
  "merk": "Mettler Toledo", "model": "Five Easy", "no_identifikasi": null,
  "range_min": 0, "range_max": 14, "satuan": "pH",
  "rentang_ukur": "0–14 pH",        // siap tempel
  "resolusi": 0.01, "toleransi": 0.2,
  "pelanggan": { "id": 3, "nama": "PT TIRTA GRACIA SEMESTA MANDIRI", "alamat": "..." }
},
"teknisi": { "id": 5, "nama": "DR", "employee_id": "PTS-DR" },
"standar_acuan": {
  "id": 3, "nama": "pH Buffer Solution 7", "no_sertifikat": "HC46341939",
  "serial_number": "HC46341939", "merk_type": "Supelco/Merck", "tertelusur_ke": "Merck KGaA"
},
"sertifikat": { "...": "...", "qr_token": "scnwqx1nrv", "qr_url": "https://.../verify/scnwqx1nrv" }
```
`merk_type` & `tertelusur_ke` juga ikut di tiap `titik[].standar_acuan` (buffer per titik beda-beda).

**Organisasi — `GET /api/organization`** (admin):
```jsonc
"logo_url": "http://.../storage/logos/sidik.png"   // null kalau belum diunggah
```
Unggah: `POST /api/organization/logo` (multipart, field `logo`, PNG/JPG, maks 2 MB). SVG ditolak — dompdf nggak bisa render SVG, nanti logonya diam-diam ilang dari PDF.

**Notifikasi:**
```
GET  /api/notifications?belum_dibaca=1     // + meta_tambahan.belum_dibaca buat badge
POST /api/notifications/{id}/baca
POST /api/notifications/baca-semua
```
Kejadian: sesi masuk approval → admin · sesi ditolak → teknisi pembuat · sertifikat gagal → admin · pendaftaran akun → admin · alat jatuh tempo → admin.

---

## ⚠️ `qr_url` masih menunjuk alamat dev

Nilai `qr_url` **dibekukan waktu sertifikat digenerate**, dan yang ada sekarang isinya alamat dev (`http://localhost:8000/...` / IP LAN). Itu juga yang **tercetak di PDF**.

Sebelum sertifikat sungguhan diterbitkan, `APP_URL` di server wajib diisi domain publik tetap. Ini bukan sesuatu yang bisa ditambal mobile — kalau QR di kertas menunjuk localhost, pelanggan nggak bisa memindainya. Sudah disampaikan ke pemilik produk.

---

## §4 — Nama seeder

✅ Sudah: "Teknisi ASMO" → **"Teknisi Sidik"** (juga Admin & Viewer). Tapi lihat peringatan di paling atas — email & employee_id ikut berubah.

---

## Kalau ada yang keliru di sini

Dokumen ini ditulis setelah ngecek langsung ke kode & nembak endpoint-nya, bukan dari ingatan. Tapi kalau ada yang di sini ditulis "selesai" padahal di mobile nggak kelihatan, bilang — lebih baik dibetulin daripada kalian nunggu barang yang ternyata namanya beda.

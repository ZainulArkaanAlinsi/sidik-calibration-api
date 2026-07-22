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
viewer **10** ability (baca doang) ⊂ teknisi **22** ⊂ admin **43**.

Daftarnya diturunkan dari middleware di `routes/api.php`, dan ada test yang mastiin ability yang **tidak** dipunyai satu role beneran ditolak 403 — jadi kalau daftar & middleware nyimpang, test jebol duluan.

### Jawaban 3 pertanyaan kalian
1. **Viewer boleh lihat arsip & sertifikat?** **Ya** — baca semua, nggak bisa nulis. Tebakan kalian benar.
2. **Teknisi lihat sesi teknisi lain?** **Tidak** — dan bukan cuma disaring di daftar: buka per-ID punya orang lain dibalas **404**, jadi nggak bisa ditebak. Tebakan kalian benar.
3. **Role keempat (Manajer Teknis)?** **Belum ada.** Sekarang penanda tangan = admin yang meng-approve, jabatannya dari field `department`. Jadi "Manajer Teknis" = atribut, bukan role. Kalau mau jadi role sungguhan, itu mengubah alur approval — **masih nunggu keputusan pemilik produk**, jadi §3 #9 belum bisa jalan.

### Soal bug "mulus di admin, mentok di teknisi"
Akar masalahnya: `GET /customers` admin-only tapi `pelanggan_id` wajib di form Tambah Alat. **Pakai `GET /api/arsip/perusahaan?search=` buat dropdown pelanggan** — kebuka semua role, ada pencarian, balikin `id` + `nama`. Itu jalan keluar tanpa perlu ngubah hak akses master data.

---

## §3 — Status nomor 1–12

| # | Permintaan | Status |
|---|---|---|
| 1 | `qr_token` di objek sertifikat | ✅ **selesai** — `qr_token` + `qr_url` |
| 2 | `nomor_order` + `tanggal_terima` | ✅ **sudah ada sejak lama** — silakan cek lagi, mungkin kelewat |
| 3 | `employee_id` di objek teknisi | ✅ **selesai** |
| 4 | `equipment` digemukin (+pelanggan) | ✅ **selesai** |
| 5 | `merk_type` + `tertelusur_ke` di standar | ✅ **selesai** (+ `serial_number`) |
| 6 | `logo_url` di organisasi | ✅ **selesai** (+ endpoint unggah) |
| 7 | Notifikasi kejadian butuh admin | ✅ **selesai** |
| 8 | `POST /calibrations/preview` | ⬜ belum |
| 9 | Penanda tangan / Manajer Teknis | ⏸ nunggu keputusan role |
| 10 | `POST /certificates/{id}/kirim-email` | ⬜ belum — butuh SMTP lab dulu (sekarang `MAIL_MAILER=log`) |
| 11 | `room_id` di sesi kalibrasi | ⬜ belum |
| 12 | Laporan + export | ⬜ belum |

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

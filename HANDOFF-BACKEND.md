# Handoff Backend — Update 21–22 Juli 2026

Buat yang nerusin backend ini (Raihan). Isinya: apa yang berubah, **kenapa** diputuskan begitu, jebakan yang udah ketemu, dan apa yang masih nggantung.

Branch: **`feat/kalibrasi-ph-lengkap-dan-arsip`** (17 commit di atas `main`, 101 file, ~8.5k baris). Semua ke-push, **319 test hijau**.

---

## 0. Mulai dari sini

```bash
git checkout feat/kalibrasi-ph-lengkap-dan-arsip
composer install
php artisan migrate          # 10 migrasi baru, semuanya aditif & nullable
php artisan db:seed          # atau: migrate:fresh --seed
php artisan test             # harus 319 hijau
```

⚠️ **Kredensial dev berubah** (rename ASMO → Sidik): `admin@sidik.test` / `teknisi@sidik.test` / `viewer@sidik.test`, password `rahasia123`, employee_id `SDK-000x`. Database sekarang **`sidik_db`** (yang lama udah dihapus; dump cadangan ada di `C:\Users\USER\backup-asmo_db-2026-07-22.sql`).

**Dokumen pendamping:** `MATRIKS-PERAN.md` (endpoint × role, digenerate dari middleware) · `BALASAN-FRONTEND-fase2.md` · `HANDOFF-FRONTEND-pH.md` · `HANDOFF-FRONTEND-rename-sidik.md` · `HANDOFF-FRONTEND-dashboard.md` · `HANDOFF-FOLDER-ARSIP.md`

---

## 1. Yang berubah

### a. Kalibrasi pH — dari "nempel angka" jadi beneran ngitung

Ini perubahan paling dalam. Dulu `GumCalculator` cuma nempel `ketidakpastian_terbaik` (CMC) apa adanya. Sekarang dia **ngitung budget ketidakpastian penuh**, lalu ambil `max(U_hitung, CMC)` — aturan akreditasi: lab nggak boleh ngeklaim lebih teliti dari CMC-nya.

- `app/Services/StudentTDistribution.php` **(baru)** — invers CDF t lewat incomplete beta. Dipakai ngitung faktor cakupan `k` dari derajat kebebasan efektif, bukan dikunci 2.
- `GumCalculator::agregasiBudget()` — 5 komponen → RSS → Welch-Satterthwaite → `k` → `U = k·Uc`.
- `GumCalculator::hitungDariBudgetPenuh()` — aktif kalau `CalibrationCapability` punya konstanta suhu; kalau nggak, jatuh ke jalur lama (kompatibel mundur).
- `GumCalculator::ketidakpastianLingkungan()` — `U = 2·√((U_TH/2)² + (|awal−akhir|/2)²)`.

**Rumusnya di-reverse-engineer dari `Project-PT-Sidik/Master Olah Data_pH for trial_CSV/`** (workbook `.xlsm`-nya ter-password, tapi CSV export + folder `_RAPI/` lengkap). Bukan dikarang. Hasilnya mereproduksi sertifikat asli **012-CAL-524** persis: U95% `0.023432 / 0.021109 / 0.031000`.

> 🔴 **`PhMeterCapabilitySeeder.ketidakpastian_terbaik` sekarang CMC MENTAH (0.023/0.021/0.031), bukan hasil max yang udah jadi.** Dulu diisi angka hasil hitung Excel. Jangan dibalikin — app sekarang yang ngitung max-nya. Ini yang nutup catatan "BATASAN YANG DIKETAHUI" di seeder itu.

### b. Data worksheet yang sebelumnya nggak ketampung

| Tabel | Kolom baru |
|---|---|
| `calibration_sessions` | suhu/kelembaban **awal & akhir** + koreksi + U95% + `thermohygro`, `room_id`, `folder_id`, `nomor_sertifikat`, `dihitung_oleh`, `metode_kalibrasi` |
| `raw_measurements` | `suhu` (suhu larutan tiap pembacaan) |
| `calibration_capabilities` | `u_temperature`, `ci_suhu`, `u_perbedaan_suhu`, `ci_perbedaan_suhu`, `koef_suhu_a/b/c` |
| `users` | `ttd_path` |
| `folders`, `certificate_emails` | tabel baru |

Tahap **sebelum/sesudah adjustment** dua-duanya dihitung (rata-rata, koreksi, STDEV) dan tercetak di PDF.

### c. Endpoint baru

```
GET  /me/permissions                      POST /me/ttd
GET  /notifications  POST /notifications/{id}/baca · /baca-semua
POST /calibrations/preview                POST /organization/logo
POST /certificates/{id}/kirim-email
GET  /laporan/kalibrasi                   GET /laporan/kalibrasi/export?format=pdf|csv
GET  /arsip/perusahaan · /perusahaan/{customer} · /alat/{equipment}
GET/POST/PUT/DELETE /arsip/folders/...    PUT /arsip/berkas/{calibration}/pindah
```

### d. Rename ASMO → Sidik
Kredensial, deep link (`asmo://` → `sidik://`), nama DB. Detail: `HANDOFF-FRONTEND-rename-sidik.md`.

---

## 2. Keputusan yang jangan di-undo tanpa baca ini dulu

1. **Arsip ada DUA lapis, saling melengkapi.** (a) Arsip turunan (read-only, dari relasi Customer→Equipment→Sesi) dan (b) folder pohon (tabel `folders`, bisa disusun user). **Bukan duplikat** — jangan hapus salah satunya.

2. **`titik_ukur` WAJIB nilai terkoreksi suhu**, bukan nominal label botol. Buffer pH 4 pada 22.2 °C = `4.0092`, bukan `3.99`. Sekarang ada penjagaan di `CalibrationRequest::periksaTitikUkurTerkoreksiSuhu()` yang nolak kalau nyimpang > 0.005 dari kurva `y = a·x² + b·x + c`.
   **Kenapa penting:** kalau salah, pencocokan CMC TETAP berhasil (matching pakai pembulatan + toleransi 0.1) — nggak ada error sama sekali, cuma angka koreksi di sertifikat yang salah.

3. **Penanda tangan = admin yang approve, jabatan dari `department`.** Keputusan pemilik produk: "Manajer Teknis" itu **atribut, bukan role keempat**. Role tetap 3: admin/teknisi/viewer.

4. **Unggah TTD lewat `/me/ttd` (punya sendiri), BUKAN `/users/{id}/ttd`.** Kalau admin bisa ngunggahin TTD orang lain, sertifikat terakreditasi bisa ditandatangani atas nama orang yang nggak pernah menyetujuinya. Ada test yang mastiin jalan itu nggak ada.

5. **Hapus folder arsip = admin doang.** Teknisi boleh bikin/rename/pindah. Arsip lab itu barang audit.

6. **OCR jalan di MOBILE, backend cuma nyimpen + wajib verifikasi manusia.** Backend nggak pernah menjalankan OCR.

7. **Rekap laporan dirakit server, bukan HP.** Kalau mobile yang ngerangkum, angkanya bisa beda tipis (pembulatan, zona waktu) dari yang tercetak di sertifikat — buat lab terakreditasi itu temuan.

---

## 3. Jebakan yang udah ketemu (biar nggak kejeblos dua kali)

| Jebakan | Kondisinya |
|---|---|
| **Tabel `notifications` dipakai 2 pihak** | Lonceng Filament nulis `title`/`body`; app ini nulis `tipe`/`judul`/`isi`/`tautan`. `NotificationResource` yang nyeragamin waktu dibaca. Jangan bikin salah satu ngalahin yang lain. |
| **`qr_payload` DIBEKUKAN waktu generate** | Ikut tercetak di PDF. Kalau `APP_URL` masih localhost/IP LAN, QR di kertas nggak bisa dipindai pelanggan — dan itu baru ketahuan setelah sertifikat beredar. Ada peringatan di log sekarang. |
| **`faktor_cakupan_k` dulu `decimal(5,2)`** | Ngebuletin semua `k` jadi 1.97, ngilangin beda antar titik. Udah dilebarin ke `decimal(8,6)`. |
| **`TINV` Excel meleset ~3e-5** | Invers-t kita mateng (cocok nilai tabel <1e-6). Selisihnya artefak Excel; pada presisi sertifikat (6 desimal) hasilnya identik. Jangan "dibenerin" ngikutin Excel. |
| **Alat belum di-link diam-diam pakai jalur generik** | `nama_alat_kemampuan` kosong → U jauh beda dari CMC, sertifikat tetap terbit kelihatan normal. Sekarang bikin peringatan di log. |
| **Test pakai SQLite in-memory, produksi MySQL** | Fungsi tanggal beda nama. Jangan `GROUP BY DATE_FORMAT(...)` — kelompokin di PHP (lihat `DashboardController::grafikPekerjaan`). |
| **`GET /customers` admin-only, tapi `POST /equipments` boleh teknisi** | Form Tambah Alat mulus di admin, mentok di teknisi. Pakai `GET /arsip/perusahaan?search=` buat dropdown pelanggan (kebuka semua role). |

---

## 4. Yang masih nggantung

**Butuh keputusan/aksi orang, bukan kode:**
- `APP_URL` masih alamat dev → **wajib domain publik sebelum sertifikat sungguhan terbit**
- SMTP lab belum diisi (`MAIL_MAILER=log`) → kirim email udah jadi & ada test-nya, tapi belum beneran kekirim
- Tim mobile perlu dikabarin soal kredensial & skema `sidik://`

**Sengaja belum dikerjain:**
- **`.xlsx` asli** — butuh `phpspreadsheet`. Sekarang ekspor CSV (kebuka di Excel, udah BOM UTF-8). Sengaja nggak nambah dependency gede tanpa persetujuan.
- **Flow meter / totalizer** — worksheet-nya ada di foto, polanya udah kebentuk dari pH.
- **Push notification (FCM)** — polling dulu, frontend bilang cukup.
- **UI mobile** — repo terpisah (`asmo_mobile`).

**Batasan yang diketahui:**
- `ci_suhu` disimpen sebagai konstanta @25 °C ngikutin workbook, bukan dihitung ulang per suhu larutan sesi.
- `u_perbedaan_suhu` buat pH 4 punya `ci = 1` sedangkan pH 7/10 ~0.003 — **inkonsistensi di workbook aslinya** (validator lab sendiri nanya "referensi dari mana"). Ditiru apa adanya biar hasilnya cocok sertifikat; kalau lab mau dibenerin, ini titik ubahnya.

---

## 5. Di mana ngeliat kebenarannya

Kalau ragu sama angka pH, **jangan tebak** — dua tempat ini yang nentuin:

1. `Project-PT-Sidik/Master Olah Data_pH for trial_RAPI/` — versi rapi dari workbook lab (03 = budget ketidakpastian, 04 = koefisien suhu).
2. Test: `tests/Unit/UncertaintyBudgetTest.php` (reproduksi budget per titik), `tests/Feature/PhCalibrationTest.php` (alur API), `tests/Feature/WorksheetLangkahAwalTest.php` (regresi input → cetak).

Record referensinya di-seed `TirtaGraciaPhMeterSeeder` — kalibrasi ASLI 012-CAL-524, ketidakpastiannya **dihitung lewat jalur yang sama dengan app**, bukan angka beku. Kalau rumus berubah, seeder ikut kepengaruh — dan itu memang disengaja.

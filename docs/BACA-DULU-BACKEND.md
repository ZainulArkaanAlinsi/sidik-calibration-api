# Status Backend — dicek langsung ke kode, 25 Juli 2026

**Halaman ini satu-satunya sumber status yang boleh dipercaya.** Dokumen lain di
`docs/` isinya *permintaan* dari mobile, dan beberapa tanda ✅-nya salah — tabel
ringkasan di `permintaan-endpoint.md` §7 dan `permintaan-endpoint-fase-2.md` §4
nandain barang yang ternyata nggak ada di repo ini. Kalau bentrok, **halaman ini
yang menang.**

Semua isi di bawah diverifikasi ke `routes/api.php`, `app/Http/Resources/`, dan
migrasi — bukan dari dokumen. Cara ngecek ulang sendiri:

```bash
php artisan route:list --path=api
```

> ### Kenapa versi sebelumnya kacau
> Halaman ini dulu ditulis tim mobile sambil ngecek repo **`asmo-api`**, bukan
> repo ini (`sidik-calibration-api`). Isinya lalu dipindah apa adanya, jadi
> statusnya nyeritain backend yang beda. Ironisnya versi lamanya sendiri udah
> ngingetin: *"sebelum ngirim apa pun ke backend, cek dulu `routes/api.php`."*

---

## Peta dokumen di `docs/`

Biar jelas mana yang dibaca buat apa:

| File | Isinya | Percaya statusnya? |
|---|---|---|
| **`BACA-DULU-BACKEND.md`** (ini) | Status apa yang udah/belum jalan | ✅ **ya** — diverifikasi ke kode |
| [`Spesifikasi-Aplikasi-Kalibrasi.md`](Spesifikasi-Aplikasi-Kalibrasi.md) | Spec produk asli. **Ini rujukan komentar "spesifikasi poin N" yang kesebar di 45 tempat di kode** | ✅ ya (dokumen kebutuhan, bukan status) |
| [`kontrak-api.md`](kontrak-api.md) | Bentuk JSON tiap endpoint | ✅ ya, sesudah koreksi §6 (nama field notifikasi) |
| [`realtime-sync.md`](realtime-sync.md) | Arsitektur broadcast + contoh klien Laravel Echo | ✅ ya |
| [`SPEC-vision-prompt.md`](SPEC-vision-prompt.md) | Prompt & implementasi AI Vision | ✅ ya, kecuali klaim caching (lihat §4) |
| [`Rekap-Data-Kemampuan-Kalibrasi.md`](Rekap-Data-Kemampuan-Kalibrasi.md) | Data CMC dari lampiran akreditasi LK-285-IDN | ✅ ya (data sumber) |
| [`infrastruktur-vps-produksi.md`](infrastruktur-vps-produksi.md) | Rencana deploy VPS | ✅ ya (usulan) |
| `permintaan-*.md` | **Permintaan** dari mobile ke backend | ⚠️ **jangan** — beberapa tanda ✅-nya salah, udah dikasih catatan koreksi |
| [`arsitektur-desktop-database.md`](arsitektur-desktop-database.md) | Rencana desktop | ⚠️ sebagian digantiin `infrastruktur-vps-produksi.md` |

---

## 0. WAJIB dibaca sebelum setup — dua hal yang bikin mentok

### `php artisan migrate` di MySQL fresh sempat GAGAL — sudah dibetulkan

Migrasi `worksheet_extraction_logs` bikin nama index 65 karakter, dan MySQL nolak
di 64 (error 1059). Jahatnya: `CREATE TABLE` lolos dulu, `ADD INDEX` gagal — jadi
tabelnya ketinggalan tapi migrasinya nggak kecatat, dan `migrate` sesudahnya
mentok `table already exists` selamanya.

Nggak ketangkep 300+ test karena `phpunit.xml` jalan di **SQLite in-memory**, yang
nggak punya batas panjang identifier. Yang kena cuma MySQL — alias dev & produksi.

Sekarang index-nya dinamain eksplisit, dan ada `tests/Feature/SkemaDatabaseTest.php`
yang gagal kalau ada nama index lewat 64 karakter lagi. Setup dari nol udah diuji
tembus sampai seeder pH.

**Kalau DB dev kamu udah kena:** tabel `worksheet_extraction_logs`-nya kosong dan
nggak kecatat di tabel `migrations`. Drop tabelnya, terus `php artisan migrate`.

### Kredensial dev: `SDK-*`, bukan `ASM-*`

Seeder sebelumnya masih bikin akun `ASM-*` / `@asmo.test` sementara
`kontrak-api.md` udah nulis `SDK-*` / `@sidik.test`. Sekarang **kodenya** yang
disamain ke dokumen, jadi tabel akun di `kontrak-api.md` udah bener.

| ID pegawai | Email | Role | Status |
|---|---|---|---|
| `SDK-0001` | admin@sidik.test | admin | aktif |
| `SDK-0002` | teknisi@sidik.test | teknisi | aktif |
| `SDK-0003` | viewer@sidik.test | viewer | aktif |
| `SDK-0099` | eko@sidik.test | teknisi | **pending** |

Password semua `rahasia123`. Login pakai ID pegawai **atau** email.

Seeder `TirtaGraciaPhMeterSeeder` juga bikin dua akun PT Sidik asli yang kepakai
di demo sertifikat pH `012-CAL-524` — belum pernah didokumentasiin sebelumnya:

| ID pegawai | Email | Peran di worksheet |
|---|---|---|
| `PTS-DR` | teknisi.dr@pt-sidik.com | teknisi yang ngerjain (`Technician ID: DR`) |
| `PTS-AM` | alex.misramto@pt-sidik.com | admin, penanda tangan (`Alex Misramto, Technical Manager`) |

Deep link reset password sekarang `sidik://reset-password` (dulu `asmo://`) —
tolong daftarin scheme-nya di manifest. Nama database di `.env.example` jadi
`sidik_db`, tapi ini **nggak ngaruh apa pun ke API**; DB lokal boleh dinamain apa
aja.

---

## 1. Yang SUDAH JALAN — aman dibangun sekarang

Khusus jalur **pH**, ini yang udah kepasang lengkap:

| Kebutuhan | Endpoint / field |
|---|---|
| Bentuk form lembar kerja pH | `GET /calibrations/lembar-kerja` — **cocok persis** sama `permintaan-backend-2026-07-24.md` §1, semua key wajib ada, enum `tipe` & `sumber` sama nilai-per-nilai |
| Kirim / perbaiki lembar kerja | `POST /calibrations`, `PUT /calibrations/{id}` |
| **Hitung sambil ngetik** | `POST /calibrations/preview` — body sama persis kayak `POST /calibrations`, nggak nyimpen apa pun. Lihat [`kontrak-api.md` §4a](kontrak-api.md) |
| Standar beda per titik (buffer 4/7/10) | `measurements[].standard_id` |
| Pembacaan as-found | `measurements[].pembacaan_sebelum` |
| Idempotency retry submit | `client_request_id` (UUID) |
| Ruangan di sesi | `room_id` — ikut di request & response (`ruangan.{id,kode,nama}`) |
| AI Vision baca foto tabel | `POST /raw-measurements/extract-from-photo` |
| Konfirmasi hasil pindai | `POST /calibrations/{id}/measurements/verify` |
| Hitung ulang & periksa (admin) | `GET /calibrations/{id}/validasi` |
| Lembar perhitungan (admin) | `GET /calibrations/{id}/perhitungan` |
| Field administratif sertifikat | `PATCH /calibrations/{id}/admin` |
| Approve / reject | `POST /calibrations/{id}/approve` · `/reject` |
| Sertifikat + PDF + Excel + QR | `GET /certificates/{id}`, `/download`, `/excel`, `/qr`, `POST /{id}/retry` |
| Rekap sertifikat (Excel) | `GET /certificates/export/excel` (admin) |
| Verifikasi QR publik | `GET /verify/{qr_token}` (web) & `GET /api/verify/{qr_token}` (JSON) |
| Grafik Dashboard | `grafik_pekerjaan` di `GET /dashboard` — 6 bulan, urut lama→baru |
| Notifikasi | `GET /notifications`, `/unread-count`, `POST /{id}/read`, `/read-all`, `DELETE /{id}` |
| Reminder jatuh tempo | otomatis tiap pagi + `POST /reminders/jatuh-tempo` (manual, admin) |
| Metode kalibrasi (IK) | `GET/POST/PUT/DELETE /calibration-methods` |
| Import Excel | `GET /imports/format`, `POST /imports/excel` |
| Master data | `/equipments`, `/categories`, `/standards`, `/customers`, `/rooms`, `/technicians`, `/organization` |
| Folder arsip (browse/rename/hapus) | `/folders`, `/folder-files`, alias `/arsip/perusahaan`, `/arsip/folders/{id}` |
| **Tap PT → buka folder akarnya** | `GET /arsip/perusahaan/{customer}/folder` — find-or-create, bentuknya sama kayak `show`. Lihat [`kontrak-api.md` §8a](kontrak-api.md) |
| Realtime sync | `POST /broadcasting/auth` + channel di `routes/channels.php` — arsitektur & contoh klien Echo di [`realtime-sync.md`](realtime-sync.md) |

---

## 2. ADA, tapi NAMANYA BEDA dari yang diminta docs

**Baca bagian ini sebelum bikin tiket.** Empat barang yang dokumen lain bilang
"belum ada" itu sebenernya udah dikirim backend, cuma nama fieldnya lain. Kalau
dicari pakai nama di dokumen, hasilnya nggak ketemu dan kelihatan kayak hilang.

| Diminta docs | Nama sebenarnya | Ada di | Catatan |
|---|---|---|---|
| `qr_url` | **`qr_payload`** | `GET /certificates/{id}` | Isinya persis `url("/verify/{token}")` — URL siap di-render jadi QR. Lihat `GenerateCertificate.php:86` |
| `tanggal_terbit` | **`diterbitkan_pada`** | `GET /certificates/{id}` | ISO 8601 |
| `merk_type` di `standar_acuan` | **`merk` + `model`** | `GET /standards/{id}` | Datanya lengkap, cuma kepisah dua field, bukan satu string gabungan |
| `calculated_by` / "Checked by" | **`reviewed_by`** | kolom `calibration_sessions` | Di lembar kerja tampil sebagai `reviewer.nama`. **Bukan** `signed_by` — itu orang lain dan belum ada |

> ⚠️ **Perhatiin kolom "Ada di".** Tiga yang pertama ada di endpoint **khusus**-nya,
> **bukan** nempel di objek yang di-embed dalam `GET /calibrations/{id}`. Objek
> embed-nya masih ringkas:
>
> ```
> sertifikat     → { id, nomor, status, pdf_url }          (tanpa qr_payload / diterbitkan_pada)
> standar_acuan  → { id, nama, no_sertifikat }             (tanpa merk / model / tertelusur_ke)
> teknisi        → { id, nama }                            (tanpa employee_id)
> ```
>
> Jadi buat layar pencocokan sertifikat, sekarang **masih perlu request tambahan**
> ke `/certificates/{id}`, `/standards/{id}`, dan `/technicians`. Itu bukan bug,
> cuma belum digemukin — permintaannya ada di `permintaan-endpoint.md` §5c–5e.

---

## 3. BELUM ADA — jangan dibangun frontend-nya dulu

Diverifikasi absen dari `routes/api.php` dan `app/`:

| Yang diminta | Diminta di | Dampak kalau dipaksa |
|---|---|---|
| `logo_url` + `POST /organization/logo` | fase-2 §3a | Kop sertifikat tanpa logo |
| `POST /certificates/{id}/kirim-email` | fase-2 §3d | Kirim sertifikat ke pelanggan belum bisa |
| `GET /laporan/kalibrasi` + `/export` | fase-2 §5 | Seluruh bagian Laporan. `GET /certificates/export/excel` nutup sebagian (rekap sertifikat), tapi tanpa filter pelanggan/teknisi/kategori |
| `GET /me/permissions` | fase-2 §1 | Tombol muncul terus ditolak 403. Sementara pakai role dari `/me` |
| `GET /dashboard/tren?dari=&sampai=&satuan=` | permintaan-endpoint §2 | Grafik Dashboard **aman** (pakai `grafik_pekerjaan`); yang belum bisa cuma grafik rentang tanggal bebas |
| Entitas `/orders` | permintaan-endpoint §4 | Nggak ada layar Order tersendiri. `nomor_order` & `tanggal_terima` udah ada di sesi |
| `status_kalibrasi` + `hari_menuju_kadaluarsa` di standar | worksheet-ph §2.1 | Banner "ONE OR MORE STANDARD EXPIRED". `masih_berlaku` (bool) udah ada, tapi state **WARNING (H-30)** belum |
| `signed_by` / penanda tangan | worksheet-ph §2.3 | Blok tanda tangan. `reviewed_by` bukan gantinya — beda orang |
| `audit_logs` + rumus berversi | arsitektur-desktop §Keputusan 4 & 5 | Menu Kelola Data & Rumus di desktop |

### Tiga operasi arsip yang masih kurang

`permintaan-endpoint-fase-2.md` §4 bilang bagian ini "UDAH DIBIKIN, JANGAN
DIULANG". **Itu salah** — yang bener `permintaan-backend-2026-07-24.md` §2b.
Yang ada cuma alias baca/rename/hapus; tiga ini belum:

| Mobile manggil | Status |
|---|---|
| `GET /arsip/perusahaan/{customerId}/folder` (find-or-create folder akar PT) | ✅ **jadi 25 Jul** — lihat §1 & `kontrak-api.md` §8a |
| `PUT /arsip/folders/{id}/pindah` | ❌ sekunder |
| `PUT /arsip/berkas/{sesiId}/pindah` | ❌ sekunder |

Jadi tinggal dua, dua-duanya sekunder: browse/rename/hapus **dan** tap PT udah
bisa disambungin sekarang.

**Satu hal yang ketemu waktu ngerjain ini dan belum ada:** teknisi belum punya
jalan buat milih pelanggan **baru** di form Alat. `kontrak-api.md` §8 nyaranin
pakai `GET /arsip/perusahaan`, tapi itu ngelist FOLDER — dan folder cuma ada buat
PT yang udah pernah punya sertifikat, plus buat teknisi isinya disaring lagi.
Rinciannya di koreksi §8. **Butuh endpoint lookup pelanggan yang kebuka semua
role**; `GET /customers` admin-only, jadi belum kepakai.

Test-nya juga bukan `tests/Feature/FolderArsipTest.php` (nggak ada file itu) —
yang ada `FolderManagerTest.php` + `FolderManagerArsipAliasTest.php`.

---

## 4. Prompt caching AI Vision: sekarang MASIH nol

`SPEC-vision-prompt.md` §5 klaim hemat ~90% dari prompt caching. Belum kejadian,
dan ini bukan bug kode:

- Breakpoint cache dipasang di blok system, panjangnya cuma **~400 token**.
- Ambang minimum prefix yang bisa di-cache: **1024 token** di `claude-opus-4-8`
  (512 di `claude-opus-5`). Di bawah ambang, API **nggak ngasih error** — dia
  cuma diam-diam nggak nge-cache.
- Breakpoint kedua (di few-shot terakhir) bakal lewat ambang, **tapi foto
  few-shot-nya belum ada**: `storage/app/few_shot/` isinya cuma README.

**Cara benerin:** taruh `few_shot_before.jpg` & `few_shot_after.jpg` di
`storage/app/few_shot/` (tugas ini ada di SPEC §4). Begitu ada, caching nyala
sendiri tanpa ubah kode — dan sekalian naikin akurasi baca tulisan tangan.

**Cara mantaunya:** kolom `cache_read_input_tokens` di `worksheet_extraction_logs`
sekarang nyimpen angkanya. `0` terus = caching nggak jalan.

```sql
SELECT status, input_tokens, cache_read_input_tokens, created_at
FROM worksheet_extraction_logs ORDER BY id DESC LIMIT 10;
```

---

## 5. Catatan model AI

Default `claude-opus-4-8` (`config/services.php`). **`claude-opus-5` harganya
sama persis** ($5/$25 per 1M token) dan lebih bagus buat baca dokumen/tabel —
swap langsung lewat `ANTHROPIC_MODEL` di `.env`.

Dua hal ikut berubah kalau pindah: thinking nyala default di Opus 5, jadi
`ANTHROPIC_MAX_TOKENS=2048` sekarang ngebatasin thinking + output sekaligus
(naikin), dan ambang cache turun ke 512 token.

Yang **jangan** diubah: `temperature` sengaja nggak dikirim (dihapus di Opus 4.8
& Sonnet 5, kirim → error 400), dan bentuk JSON dijamin `output_config.format`.
Angka sertifikat tetap dari `GumCalculator` yang deterministik, **bukan AI** —
alasannya di `SPEC-vision-prompt.md` §9.

---

## 6. Kalau nemu beda lagi

Jangan tambal di frontend. Bilang dulu, karena beda bentuk field itu hampir
selalu tanda dokumennya basi, bukan backend-nya salah — dan nambal di dua sisi
bikin dua sumber kebenaran.

Cek ke kode sebelum bikin tiket, lima menit:

```bash
php artisan route:list --path=api | grep <yang-dicari>
grep -rn "<nama_field>" app/Http/Resources/
```

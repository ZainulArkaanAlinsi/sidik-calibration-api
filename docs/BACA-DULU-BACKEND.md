# Status Backend — dicek langsung ke kode, 25 Juli 2026

> ⚠️ **Isi §0–§6 di bawah statusnya per 25 Juli.** Ada tiga hari kerja perubahan
> sesudah itu (29–30 Juli) yang **belum kesusul ke bagian-bagian itu** — termasuk
> beberapa yang ngubah perilaku, bukan cuma benerin. Ringkasannya +
> penunjuknya ada di [bagian **Update 30 Juli**](#update-30-juli-2026--kalau-kamu-yang-nerusin-backend-baca-ini-dulu)
> tepat di bawah peta dokumen. Baca itu dulu sebelum mutusin apa pun dari §1.

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
| **`BACA-DULU-BACKEND.md`** (ini) | Status apa yang udah/belum jalan | ✅ **ya** — tapi §0–§6 per **25 Juli**, lihat Update 30 Juli |
| [`HANDOFF-BACKEND-29-Jul-adendum.md`](HANDOFF-BACKEND-29-Jul-adendum.md) | Rincian teknis & **alasan** perubahan 29 Juli, plus yang belum dikerjain | ✅ ya — diverifikasi ke kode & hasil test |
| [`HANDOFF-FRONTEND-29-Jul.md`](HANDOFF-FRONTEND-29-Jul.md) | Perubahan yang kelihatan dari sisi API 29 Juli, buat mobile | ✅ ya |
| [`skrip/e2e-ph.py`](skrip/e2e-ph.py) | Uji rantai pH → sertifikat lawan server beneran | ✅ ya — alat, bukan dokumen |
| [`Spesifikasi-Aplikasi-Kalibrasi.md`](Spesifikasi-Aplikasi-Kalibrasi.md) | Spec produk asli. **Ini rujukan komentar "spesifikasi poin N" yang kesebar di 45 tempat di kode** | ✅ ya (dokumen kebutuhan, bukan status) |
| [`kontrak-api.md`](kontrak-api.md) | Bentuk JSON tiap endpoint | ✅ ya, sesudah koreksi §6 (nama field notifikasi) |
| [`realtime-sync.md`](realtime-sync.md) | Arsitektur broadcast + contoh klien Laravel Echo | ✅ ya |
| [`SPEC-vision-prompt.md`](SPEC-vision-prompt.md) | Prompt & implementasi AI Vision | ✅ ya, kecuali klaim caching (lihat §4) |
| [`Rekap-Data-Kemampuan-Kalibrasi.md`](Rekap-Data-Kemampuan-Kalibrasi.md) | Data CMC dari lampiran akreditasi LK-285-IDN | ✅ ya (data sumber) |
| [`infrastruktur-vps-produksi.md`](infrastruktur-vps-produksi.md) | Rencana deploy VPS | ✅ ya (usulan) |
| `permintaan-*.md` | **Permintaan** dari mobile ke backend | ⚠️ **jangan** — beberapa tanda ✅-nya salah, udah dikasih catatan koreksi |
| [`arsitektur-desktop-database.md`](arsitektur-desktop-database.md) | Rencana desktop | ⚠️ sebagian digantiin `infrastruktur-vps-produksi.md` |

---

## 31 Juli 2026 — branch `feat/kalibrasi-ph-lengkap-dan-arsip` DITUTUP

Branch itu **nggak akan di-merge**. Keputusan Zain, 31 Juli.

Isinya sudah nggak sepadan sama ongkosnya: 30 commit, 105 file, dan **90 file
konflik** lawan `main` — 11 di antaranya file inti yang paling banyak berubah
minggu ini. Sebagian besar isinya juga udah ada di `main` lewat jalan lain:

| Isi branch | Status di `main` |
|---|---|
| Budget ketidakpastian penuh + student's t | ✅ udah diangkat (`99f5f57`) |
| Arsip / file manager | ✅ ada, ditulis ulang dengan bentuk beda |
| Matriks peran & `/me/permissions` | ✅ ada |
| Koreksi suhu buffer, kondisi lingkungan | ✅ ada |
| **Order Kalibrasi + penugasan teknisi** | ❌ **nggak ada, dan nggak jadi dibangun** |

### ⚠️ Yang ikut ditutup: entitas Order

Baris terakhir itu yang perlu disadari. Branch ini punya fitur **Order Kalibrasi**
yang jalan (`Order`, `OrderItem`, `OrderController`, resource, factory) plus
**penugasan teknisi per alat + antrean "Tugas Saya"**. Di `main` nol rute — dan
sekarang statusnya bukan "belum dibangun", tapi **nggak jadi dibangun**.

§3 di bawah masih nulis `/orders` sebagai "BELUM ADA, jangan dibangun frontend-nya
dulu". Kalimat itu sekarang perlu dibaca sebagai **permanen**, bukan "nanti".

### Kodenya nggak hilang

Diikat tag sebelum ditutup, dan tag-nya udah di remote:

```bash
git checkout -b <nama-baru> arsip/ph-lengkap-dan-order
```

`HANDOFF-BACKEND.md` asli juga cuma ada di situ — kalau ada yang nyari dokumen
itu, di tag ini tempatnya, bukan di `main`.

---

## 🔴 31 Juli 2026 — buffer pH 4 KADALUARSA, alur pH 3 titik berhenti

Buat @raihannazhiif. Ini bukan bug, dan **jangan dicari di kode** — datanya yang
lewat masa berlaku.

| Standar | Serial | `berlaku_sampai` | Status |
|---|---|---|---|
| **pH Buffer Solution 4** | `HC32513535` | **2026-07-31** | ❌ **kadaluarsa hari ini** |
| pH Buffer Solution 7 | `HC46341939` | 2027-12-17 | ✅ masih berlaku |
| pH Buffer Solution 10 | `HC45400338` | 2027-07-31 | ✅ masih berlaku |

**Efeknya:** `POST /calibrations` balikin **422** buat titik mana pun yang pakai
buffer 4 —

```
Sertifikat standar acuan titik ini udah kadaluarsa, jadi nggak boleh dipakai kalibrasi.
```

Karena kalibrasi pH baku itu 3 titik (4/7/10), **seluruh alur pH berhenti** sampai
buffernya diganti. Yang nolak `CalibrationRequest::withValidator()`, dan itu
perilaku yang BENER: standar yang lewat masa berlaku bikin ketertelusuran putus,
dan itu temuan asesor.

**Yang bakal bikin salah sangka:** `docs/skrip/e2e-ph.py` sekarang berhenti di
mata rantai ke-4 dengan `PUTUS DI: nyari 3 standar buffer pH` + pesan kadaluarsa.
Itu penjaganya bekerja, **bukan** regresi dari perubahan pH minggu ini. Kalau
skripnya tiba-tiba merah, cek tanggal standarnya duluan sebelum ngulik kode.

**Yang dibutuhin: keputusan lab, bukan tambalan kode.** Salah satu dari —

1. Buffer fisiknya diganti, sertifikat baru diinput ke master Standar; atau
2. `berlaku_sampai` diperpanjang **kalau memang ada sertifikat baru yang mendasari**.

Tanggalnya sengaja NGGAK digeser dari sisi ini. Itu masa berlaku sertifikat
standar beneran — bukan angka yang boleh diubah biar aplikasinya jalan lagi.

---

## Update 30 Juli 2026 — kalau kamu yang nerusin backend, baca ini dulu

Enam commit masuk 29–30 Juli, dan **§1 di bawah belum nyusul**. Yang di bawah ini
bukan daftar changelog — cuma yang bisa bikin kamu salah ambil keputusan kalau nggak
tau. Alasan & rinciannya di
[`HANDOFF-BACKEND-29-Jul-adendum.md`](HANDOFF-BACKEND-29-Jul-adendum.md).

Suite penuh sesudah semuanya: **604/604**.

### Yang ngubah PERILAKU, bukan cuma benerin

| Commit | Apa | Yang perlu kamu tau |
|---|---|---|
| `b86eed3` | **U95 sekarang ikut sebaran pembacaan.** `u_c = sqrt(u_cmc² + u_A²)`, dilantai ke CMC | Angka di sertifikat berubah. **Sertifikat lama nggak ikut** — snapshot-nya beku. Kalau ada yang perlu dihitung ulang, itu **keputusan mutu**, bukan teknis; jangan nerbitin ulang sendirian |
| `d3fa98d` | **Approve & retry nerbitin sertifikat LANGSUNG**, bukan lewat antrean | `queue:listen` nggak wajib lagi buat dua jalur itu. **Masih wajib buat panel admin Filament** — lihat "Yang belum" di bawah |
| `d3fa98d` | Lembar kerja pH dirombak ngikut kertasnya | 5 kolom baru diketik teknisi (`alat_model`, `alat_serial_number`, `alat_merk`, `pemilik_nama`, `pemilik_alamat`). `CertificateSnapshotBuilder` ngutamain isian teknisi, master cuma cadangan |
| `d3fa98d` | `/dashboard/tren` bawaan 5 → **6** bulan | Bug kalender: `subMonths()` polos dari tanggal 29–31 nyelonong maju. Sekarang `subMonthsNoOverflow()` |
| `ab80b80` | **QR di PDF jadi opt-in**, default nggak nyetak | Dulu default-nya nyetak, jadi setelan yang ilang bikin dokumen resmi salah bentuk tanpa tanda apa pun |
| `c35b044` | **Nama pelanggan unique per organisasi** | Dua lapis: unique index + `Rule::unique`. Migrasinya **nolak jalan** kalau kembar udah ada, dengan pesan yang nyebut namanya |

### Empat pola kegagalan yang udah kena di repo ini

Ini yang paling berharga buat dibawa ke kerjaan berikutnya. Keempatnya **lolos dari
seluruh suite test**, dan ketemunya cuma gara-gara app-nya beneran dipakai.

1. **Penjaga `hasTable` bikin `migrate` nggak mati, tapi NGGAK bikin tabel lamanya
   jadi bener.** Tabel `folders` keskip penjaga, bentuknya ketinggalan dua kolom, dan
   sertifikat terbit tanpa pernah ketaut ke folder — errornya ketelen `Log::warning`.
   Tiap tabel yang keskip penjaga mesti dicocokin kolomnya satu-satu.
2. **Setelan organisasi bisa ditimpa `db:seed`.** `OrganizationSeeder` nulis
   `settings` sebagai array utuh, jadi seed nimpa seluruh JSON dan satu kunci ilang
   tanpa suara. Setelan yang ngatur bentuk dokumen resmi **wajib default opt-in**,
   biar kunci yang ilang bikin sesuatu absen (kelihatan) bukan salah bentuk (nggak
   kelihatan).
3. **Kolom sisa skema lama yang nggak ada di migrasi mana pun.** `thermohygro` (teks)
   nyamperin relasi `thermohygro()` — di Eloquent atribut menang atas relasi, jadi
   `GET /calibrations` mati 500 buat semua orang. Test tetap hijau karena DB test
   dibangun dari migrasi yang nggak punya kolom itu.
4. **Test hijau bukan bukti jalan.** Test pakai SQLite in-memory yang dibangun dari
   migrasi; dev & produksi pakai MySQL yang skemanya udah terlanjur kena sejarah.
   Kelas kegagalan yang cuma ada di sisi kanan: kolom nyasar, migrasi belum jalan,
   nama index kepanjangan, pekerja antrean mati, standar kadaluarsa, `.env` nunjuk IP
   lama. **Semua enam itu pernah kejadian di sini.**

### Yang belum — dan keputusannya nunggu orang, bukan kode

| Hal | Kondisi |
|---|---|
| **Dua pemanggil Filament masih lewat antrean** | `CalibrationSessionsTable:180` & `CertificatesTable:119` masih `GenerateCertificate::dispatch()`. Artinya **`queue:listen` tetap wajib buat panel admin**. Sengaja nggak diikutin — itu mesin desktop yang emang ada worker, dan aksinya bukan jalur pemulihan satu arah kayak retry |
| **`rooms` masih 0 baris** | Belum ada seeder-nya, dan di mobile belum ada layar master Ruangan. Belum nahan apa-apa (sesi lab jalan pakai `lokasi=lab` tanpa `room_id`), tapi "Calibration Location" di sertifikat bakal kosong |
| **Kunci Gemini sempat lewat chat** | Perlu diputer/diganti di Google Cloud Console. Kuncinya di `.env`, bukan di kode |
| **Berkas non-sertifikat belum ketaut ke folder PT** | Baru sertifikat yang otomatis masuk. Ini fitur baru, bentuknya belum diputusin (subfolder per alat? berkas apa aja?) |

### ⚠️ Branch `feat/kalibrasi-ph-lengkap-dan-arsip` — jangan di-merge tanpa sesi khusus

Di sinilah `HANDOFF-BACKEND.md` yang asli berada — **file itu ada di branch itu, bukan
di `main`**. Kalau kamu diarahkan "baca HANDOFF-BACKEND.md" terus nggak nemu, itu
sebabnya.

Diukur 30 Juli, branch itu vs `main`:

| | Angka |
|---|---|
| Commit di depan `main` | **30** |
| File kesentuh | **105** |
| **File konflik kalau di-merge** | **90** |
| Di antaranya, file yang baru disentuh 29–30 Juli | **11** |

Sebelas file yang tabrakan itu inti alur: `CalibrationController`,
`CertificateController`, `DashboardController`, `CalibrationRequest`,
`CalibrationResource`, `GenerateCertificate`, `CalibrationSession`,
`DatabaseSeeder`, `routes/api.php`, dan dua blade sertifikat.

Cara ngecek ulang sendiri, tanpa nyentuh working tree:

```bash
git merge-tree --write-tree --name-only main feat/kalibrasi-ph-lengkap-dan-arsip
```

**Jadi ini bukan "tinggal merge".** Sebelas file itu persis yang paling banyak berubah
minggu ini, jadi resolusinya butuh orang yang tau maksud kedua sisi — dan itu paling
aman dikerjain sebagai satu pekerjaan tersendiri, bukan nyelip di tengah yang lain.

### Cara cek cepat kalau ada yang kelihatan aneh

Sebelum ngulik kode, jalanin rantai penuhnya lawan server beneran:

```bash
php artisan serve --host=0.0.0.0 --port=8000
python docs/skrip/e2e-ph.py http://127.0.0.1:8000/api sertifikat.pdf
```

Sebelas mata rantai dari login sampai unduh PDF. Keluarnya `SEMUA MATA RANTAI
TERSAMBUNG` atau `PUTUS DI: <langkah>` + sebab + petunjuk. Ini yang paling cepat
mbedain "bug mobile atau backend" — dan dia nembak lapisan yang test **nggak** lewatin
(lihat pola kegagalan #4 di atas).

> Skripnya bikin data beneran tiap dijalanin (sesi + sertifikat baru di DB dev).
> Itu disengaja: yang mau dibuktiin justru jalur yang nyampe database.

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

Seeder `PhMeterSeeder` juga bikin dua akun PT Sidik asli yang kepakai
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
| **Badge & banner status standar** | `status_kalibrasi` + `hari_menuju_kadaluarsa` di `/standards`, `status_standar` di respons sesi. Ambang dari `organization.settings.reminder_hari_sebelum` (default 30) |
| Pembacaan as-found | `measurements[].pembacaan_sebelum` |
| Idempotency retry submit | `client_request_id` (UUID) |
| Ruangan di sesi | `room_id` — ikut di request & response (`ruangan.{id,kode,nama}`) |
| AI Vision baca foto tabel (CADANGAN, mobile sudah pindah ke pindai lokal; saklar `VISION_AKTIF`) | `POST /raw-measurements/extract-from-photo` |
| Konfirmasi hasil pindai | `POST /calibrations/{id}/measurements/verify` |
| Hitung ulang & periksa (admin) | `GET /calibrations/{id}/validasi` |
| Lembar perhitungan (admin) | `GET /calibrations/{id}/perhitungan` |
| Field administratif sertifikat | `PATCH /calibrations/{id}/admin` |
| Approve / reject | `POST /calibrations/{id}/approve` · `/reject` |
| Sertifikat + PDF + Excel + QR | `GET /certificates/{id}`, `/download`, `/excel`, `/qr`, `POST /{id}/retry` |
| Rekap sertifikat (Excel) | `GET /certificates/export/excel` (admin) |
| Verifikasi QR publik | `GET /verify/{qr_token}` (web) & `GET /api/verify/{qr_token}` (JSON) |
| Grafik Dashboard | `grafik_pekerjaan` di `GET /dashboard` — 6 bulan, urut lama→baru. Kuncinya `bulan` |
| **Grafik tren rentang bebas** | `GET /dashboard/tren?dari=&sampai=&satuan=hari\|minggu\|bulan`. Kuncinya **`periode`** (bukan `bulan` — sengaja beda). Angkanya dijamin sama dengan `grafik_pekerjaan`, satu service. Maks 400 periode. Lihat [`kontrak-api.md` §7](kontrak-api.md) |
| Notifikasi | `GET /notifications`, `/unread-count`, `POST /{id}/read`, `/read-all`, `DELETE /{id}` |
| **Kejadian yang butuh admin sekarang nyampe** | 3 kategori baru: `akun.menunggu_persetujuan`, `sertifikat.gagal`, `standar.kadaluarsa`. Cuma ke admin **aktif**. Yang harian ditahan anti-spam (isi sama nggak diulang 7 hari; isi berubah dikirim saat itu juga). Lihat [`kontrak-api.md` §6](kontrak-api.md) |
| Reminder jatuh tempo | otomatis tiap pagi + `POST /reminders/jatuh-tempo` (manual, admin) |
| Metode kalibrasi (IK) | `GET/POST/PUT/DELETE /calibration-methods` |
| Import Excel | `GET /imports/format`, `POST /imports/excel` |
| Master data | `/equipments`, `/categories`, `/standards`, `/customers`, `/rooms`, `/technicians`, `/organization` |
| **Logo kop sertifikat** | `logo_url` di `/organization`; unggah `POST /organization/logo`, hapus `DELETE`. PNG/JPG doang — WEBP nggak bisa dicetak dompdf |
| **Dropdown pelanggan (semua role)** | `GET /customers/lookup?search=` — id/nama/alamat, dipaginasi. Ini yang dipakai picker di form Alat, BUKAN `/arsip/perusahaan`. Lihat [`kontrak-api.md` §8](kontrak-api.md) |
| Folder arsip (browse/rename/hapus) | `/folders`, `/folder-files`, alias `/arsip/perusahaan`, `/arsip/folders/{id}` |
| **Tap PT → buka folder akarnya** | `GET /arsip/perusahaan/{customer}/folder` — find-or-create, bentuknya sama kayak `show`. Lihat [`kontrak-api.md` §8a](kontrak-api.md) |
| **Pindah folder & berkas arsip** | `PUT /arsip/folders/{id}/pindah` (body `{parent_id}`) & `PUT /arsip/berkas/{sesiId}/pindah` (body `{folder_id}`). Folder `sistem` ditolak `422`; pindah ke keturunan sendiri ditolak `422`. Lihat [`kontrak-api.md` §8a](kontrak-api.md) |
| **Laporan kalibrasi + export** | `GET /laporan/kalibrasi` (dipaginasi + `ringkasan`) & `GET /laporan/kalibrasi/export?format=pdf\|xlsx`. Semua role; teknisi cuma dapat pekerjaannya sendiri. Lihat [`kontrak-api.md` §10](kontrak-api.md) |
| **Masa berlaku sertifikat ditentukan admin** | `berlaku_sampai` (opsional) di `POST /calibrations/{id}/approve`. Kalau nggak dikirim → `settings.masa_berlaku_sertifikat_bulan`, default 12. Dihitung dari **tanggal kalibrasi**, bukan tanggal terbit. Lihat [`kontrak-api.md` §5](kontrak-api.md) |
| **Tanggal keluar sebagai tanggal polos** | `"2024-05-30"`, bukan lagi `"2024-05-29T17:00:00Z"`. Kena semua field bercast `date`; `created_at` dll tetap ISO. Daftar lengkap + dampaknya di [`kontrak-api.md` §4](kontrak-api.md) |
| **Matriks peran** | `GET /me/permissions` — `boleh[]` (daftar putih nama izin) + `batasan{}` (`sendiri`/`semua`). Dihitung dari middleware rute, jadi nggak bisa basi. Admin 44 · teknisi 23 · viewer 15. Lihat [`kontrak-api.md` §2](kontrak-api.md) |
| Realtime sync | `POST /broadcasting/auth` + channel di `routes/channels.php` — arsitektur & contoh klien Echo di [`realtime-sync.md`](realtime-sync.md) |
| **Riwayat perubahan data (audit)** | `GET /audit-logs` + `/audit-logs/export` (CSV). Admin doang & **baca-saja**. Kecatat OTOMATIS dari model event, jadi semua jalur ikut: API, panel Filament, queue, command. `password` disensor. Lihat [`kontrak-api.md` §11](kontrak-api.md) |
| **Rumus kalibrasi berversi** | `GET /formulas`, `/{id}/versions`, `/{id}/versi-berlaku?tanggal=`, `POST /{id}/versions`, `PATCH /formula-versions/{id}`. Admin doang. Hasil hitung distempel `formula_version_id` dari versi yang berlaku di **tanggal kalibrasi**. ⚠️ **Fondasi** — evaluator ekspresinya belum ada, jadi ngubah versi belum ngubah cara hitung. Lihat [`kontrak-api.md` §12](kontrak-api.md) |
| **Kirim sertifikat ke email pelanggan** | `POST /certificates/{id}/kirim-email` (body `{ke:[], cc:[]}`) + `GET /certificates/{id}/riwayat-email`. Admin doang, PDF dilampirkan, tiap percobaan (termasuk gagal) tercatat. ⚠️ Butuh `MAIL_*` diisi di `.env` produksi — sekarang `MAIL_MAILER=log`. Lihat [`kontrak-api.md` §13](kontrak-api.md) |

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
> ✅ **25 Jul — objek embed-nya UDAH digemukin**, jadi layar pencocokan sertifikat
> nggak perlu request tambahan lagi:
>
> ```
> sertifikat     → + diterbitkan_pada, berlaku_sampai, qr_token, qr_payload
> standar_acuan  → + merk, model, merk_type, serial_number, tertelusur_ke
> teknisi        → + employee_id, kode_teknisi, department
> reviewer       → BARU ("Checked by" — admin yang approve/reject)
> ```
>
> ✅ **27 Jul — penanda tangan sertifikat UDAH JADI**, jadi bagian embed ini kelar:
>
> ```
> sertifikat     → + penanda_tangan { nama, jabatan }
> ```
>
> Keputusan yang ditunggu terjawab: **"Manajer Teknis" bukan role keempat**, tapi
> atribut organisasi (`settings.penandatangan_nama` / `_jabatan`) plus gambar TTD yang
> diunggah admin. Kontrak: [`kontrak-api.md` §14](kontrak-api.md).
>
> Dua hal yang perlu diperhatiin mobile:
>
> 1. **Nggak ada `ttd_url`** — gambarnya di disk privat, jadi ambilnya lewat
>    `GET /organization/tanda-tangan` (pakai `Image.memory`, bukan `Image.network`).
> 2. `penanda_tangan` **beku dari snapshot**, bukan dari pengaturan yang berlaku
>    sekarang. Jadi jangan disamain sama `organization.settings.penandatangan_nama`
>    buat sertifikat lama — dua-duanya bener, cuma beda waktu.

---

## 3. BELUM ADA — jangan dibangun frontend-nya dulu

Diverifikasi absen dari `routes/api.php` dan `app/`:

| Yang diminta | Diminta di | Dampak kalau dipaksa |
|---|---|---|
| Entitas `/orders` | permintaan-endpoint §4 | Nggak ada layar Order tersendiri. `nomor_order` & `tanggal_terima` udah ada di sesi |
| `calculated_by` / `signed_by` **di worksheet** | worksheet-ph §2.3 | Blok approval Halaman 2 ("Calculated by" / "Signed by" per sesi, FK ke `users`, plus `inisial` di `UserResource`). `reviewed_by` bukan gantinya — beda orang. **Beda dari penanda tangan sertifikat**, yang udah jadi 27 Jul (`kontrak-api.md` §14): yang itu satu orang tingkat lab buat semua sertifikat, yang ini per sesi |
| **Evaluator ekspresi rumus** | arsitektur-desktop §Keputusan 5 | Ngubah versi rumus **belum ngubah cara hitung**. Pencatatan versi + stempel `formula_version_id` di hasil hitung udah jadi 27 Jul (lihat §1); yang belum: mesin yang ngeksekusi ekspresi dari DB, plus uji-coba-sebelum-disimpan. `audit_logs` (Keputusan 4) juga udah jadi |

### Tiga operasi arsip — ✅ SEMUANYA UDAH JADI (27 Jul)

`permintaan-endpoint-fase-2.md` §4 bilang bagian ini "UDAH DIBIKIN, JANGAN
DIULANG" waktu belum ada. **Itu salah** — yang bener `permintaan-backend-2026-07-24.md`
§2b. Sekarang ketiganya beneran ada:

| Mobile manggil | Status |
|---|---|
| `GET /arsip/perusahaan/{customerId}/folder` (find-or-create folder akar PT) | ✅ **jadi 25 Jul** — lihat §1 & `kontrak-api.md` §8a |
| `PUT /arsip/folders/{id}/pindah` | ✅ **jadi 27 Jul** — body `{parent_id}`; `null` = jadiin akar |
| `PUT /arsip/berkas/{sesiId}/pindah` | ✅ **jadi 27 Jul** — body `{folder_id}`; dikunci **id sesi**, bukan id `folder_files` |

**Dua batasan yang perlu dipegang frontend:**

1. **Folder `sistem` nggak bisa dipindah** → `422`. Alasannya sama kayak larangan
   rename yang udah ada: `FolderOrganizer` nemuin folder akar PT dari
   `parent_id = null` dan folder tahun dari `parent_id = akar->id`. Begitu dipindah,
   kriterianya nggak nyocok lagi, sertifikat berikutnya bikin folder **baru**, dan
   arsip satu PT kepecah dua. Jadi di UI, folder `sistem` jangan dibikin bisa
   di-drag.
2. **Folder nggak bisa dipindah ke dalam keturunannya sendiri** → `422`. Kalau
   lolos, folder-nya lepas dari pohon dan **ilang dari semua layar** tanpa error —
   barisnya masih ada di DB tapi nggak bisa dijangkau lagi.

Rinciannya di [`kontrak-api.md` §8a](kontrak-api.md).

**Satu hal yang ketemu waktu ngerjain ini — dan udah ditutup:** teknisi nggak
punya jalan buat milih pelanggan **baru** di form Alat. `kontrak-api.md` §8
nyaranin pakai `GET /arsip/perusahaan`, tapi itu ngelist FOLDER — dan folder cuma
ada buat PT yang udah pernah punya sertifikat, plus buat teknisi isinya disaring
lagi. Karena `pelanggan_id` itu wajib, teknisi beneran mentok: nggak bisa nyimpen
alat sama sekali buat pelanggan baru.

✅ **Sekarang pakai `GET /customers/lookup`** (kebuka semua role, live 25 Jul).
Rinciannya di [`kontrak-api.md` §8](kontrak-api.md).

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

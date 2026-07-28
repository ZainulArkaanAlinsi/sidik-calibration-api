# Permintaan Endpoint Baru — dari Mobile ke Backend

> ⚠️ **Baca [`BACA-DULU-BACKEND.md`](BACA-DULU-BACKEND.md) dulu.** Sebagian isi
> dokumen ini udah dikerjain backend duluan; halaman itu yang nandain mana yang
> masih berlaku.

Dokumen ini nutup celah antara spec fitur yang diminta atasan sama endpoint
yang sekarang ada di `sidik-calibration-api`. Formatnya ngikutin
[`kontrak-api.md`](kontrak-api.md).

Yang ditulis di sini **cuma yang bener-bener ngeblok**. Fitur yang bisa
digarap mobile tanpa backend baru nggak dimasukin.

Urutannya udah diurutin dari yang paling murah ke paling mahal — nomor 1 dan 2
kemungkinan cuma nambah beberapa baris di controller yang udah ada.

> **Baca ini dulu — dikoreksi 25 Juli, diperbarui 26 Juli 2026.** Catatan lama di
> sini bilang nomor **1–4 udah kelar semua**. Itu nggak bener waktu itu: yang ada
> cuma **1 & 3**. Nomor **2** (`/dashboard/tren`) ✅ **udah jadi 26 Jul**; nomor
> **4** (entitas `/orders`) masih nggak ada di repo ini. Nomor **5**: 5a–5d ✅ kelar, 5e 🔸 sebagian (penanda tangan belum). **`room_id` udah jalan** — nggak perlu disepakati lagi. Rincian di
> [`BACA-DULU-BACKEND.md`](BACA-DULU-BACKEND.md).
>
> Permintaan lanjutan (peran & hak akses, notifikasi ke admin, pelengkap
> sertifikat, CRUD arsip, Laporan) ada di dokumen terpisah:
> [`permintaan-endpoint-fase-2.md`](permintaan-endpoint-fase-2.md).

---

## 0. Kenapa ini muncul

Empat bagian spec (Dashboard, Master Data, Order Kalibrasi, Input Pengukuran)
udah dipetakan ke kode. Sebagian besar ternyata **udah ada** dan tinggal dipoles.
Yang tersisa nggak bisa digarap mobile karena datanya memang belum pernah
dikirim backend — bukan karena layarnya belum dibikin.

Mobile sengaja **nggak** bikin layar-layar itu pakai data karangan. Layar yang
kelihatan jadi tapi isinya kosong lebih berbahaya daripada layar yang belum ada,
apalagi kalau sampai kebuka di depan klien.

---

## 1. Tambahan field di `GET /api/dashboard` — **paling murah**

Sekarang responsnya:

```json
{
  "data": {
    "total_alat": 5,
    "alat_overdue": 2,
    "kalibrasi_draft": 0,
    "menunggu_approval": 0,
    "sertifikat_bulan_ini": 1
  }
}
```

Spec minta kartu **"Kalibrasi selesai"**, dan angka itu belum ada. Yang paling
mendekati (`sertifikat_bulan_ini`) beda arti — sesi bisa selesai tanpa
sertifikatnya keburu terbit.

**Minta ditambah:**

```json
"kalibrasi_selesai": 12
```

Definisinya diserahkan backend (kemungkinan `status = disetujui`), tapi tolong
dikabarin batasannya apa — mobile cuma nampilin, nggak nurunin sendiri.

> Mobile udah aman kalau field-nya belum ada: `DashboardSummary.fromJson()`
> nganggep field yang hilang sebagai `0`, nggak bikin app crash. Jadi ini bisa
> dirilis kapan aja tanpa nunggu mobile.

---

## 2. Endpoint grafik pekerjaan — ✅ SUDAH JADI (26 Jul)

> **`GET /api/dashboard/tren` udah live**, bentuknya persis kayak yang diminta di
> bawah. Kontrak lengkap: [`kontrak-api.md` §7](kontrak-api.md).
>
> Ketiga catatan di bawah dijalanin: agregasinya di backend, scope-nya ngikut role
> dari token, dan **periode kosong tetap dikirim dengan nilai `0`**.
>
> Yang ikut dikirim di luar permintaan:
>
> - **`label`** siap tempel ke sumbu X (`"Jul 2026"`, `"23 Jul"`) — biar mobile
>   nggak nerjemahin nama bulan sendiri di tiap layar, dan nggak beda-beda antar layar.
> - **`penyaring`** (dari/sampai/satuan yang beneran dipakai) — berguna waktu
>   `dari` nggak dikirim dan backend milih bawaannya.
> - **Batas 400 periode** → `422`, bukan hasil yang dipotong. Hasil yang dipotong
>   sepi-sepi bikin orang salah baca tren.
>
> **Dua hal yang perlu diperhatiin waktu nyambungin:**
>
> 1. **Kuncinya `periode`, BUKAN `bulan`.** `grafik_pekerjaan` di `/dashboard`
>    kebalikannya: `bulan`, bukan `periode`. Sengaja beda dan **nggak** dibikin
>    alias — pernah ada bug nyata gara-gara ketuker.
> 2. **`periode` buat `satuan=minggu` pakai tahun-minggu ISO** (`"2026-W01"`). Di
>    pergantian tahun, 29 Des 2025 itu minggu ke-1 tahun **2026** — jadi jangan
>    diasumsikan 4 karakter pertamanya sama dengan tahun tanggalnya.
>
> Angkanya **dijamin sama** dengan `grafik_pekerjaan`: dua-duanya narik dari satu
> service, dan ada test yang ngebandingin keluarannya bulan per bulan.

<details>
<summary>Permintaan aslinya (buat arsip)</summary>


Spec minta **grafik pekerjaan** di Dashboard, plus grafik Error/Koreksi/Deviasi
di halaman Perhitungan. Semua endpoint yang ada sekarang cuma ngasih angka
sesaat — nggak ada deret waktu, jadi nggak ada yang bisa digambar.

**Minta endpoint baru:**

```
GET /api/dashboard/tren?dari=2026-05-01&sampai=2026-07-31&satuan=bulan
```

`satuan`: `hari` | `minggu` | `bulan`

```json
{
  "data": [
    { "periode": "2026-05", "masuk": 14, "selesai": 11 },
    { "periode": "2026-06", "masuk": 9,  "selesai": 9  },
    { "periode": "2026-07", "masuk": 6,  "selesai": 3  }
  ]
}
```

Catatan:

- Agregasinya di backend, bukan mobile. Kalau mobile narik semua sesi lalu
  ngitung sendiri, di lab yang udah jalan setahun itu ribuan baris cuma buat
  gambar 12 titik.
- Scope-nya ngikut role kayak `/dashboard` — teknisi lihat miliknya sendiri,
  admin lintas-teknisi. Diambil dari token, mobile nggak ngirim role.
- Periode kosong tetap dikirim dengan nilai `0`, jangan di-skip — biar sumbu
  waktunya nggak bolong.

</details>

---

## 3. CRUD Data Ruangan

Spec naruh **Data Ruangan** di Master Data, sederajat sama Pelanggan / Alat /
Standar. Sekarang nggak ada apa-apa — nggak ada tabel, model, maupun route.

**Minta CRUD standar**, bentuknya ngikut pola `customers` yang udah ada:

```
GET    /api/rooms
GET    /api/rooms/{id}
POST   /api/rooms
PUT    /api/rooms/{id}
DELETE /api/rooms/{id}
```

```json
{
  "id": 1,
  "nama": "Lab Kalibrasi 1",
  "kode": "LK-01",
  "lokasi": "Gedung A Lantai 2",
  "suhu_min": 18.0,
  "suhu_maks": 25.0,
  "kelembaban_min": 40.0,
  "kelembaban_maks": 65.0,
  "organization_id": 1
}
```

Kenapa rentang suhu & kelembaban ikut: form kalibrasi pH udah nangkep kondisi
lingkungan awal/akhir. Kalau ruangan punya rentang yang diizinkan, mobile bisa
langsung ngingetin teknisi waktu kondisinya keluar batas — sebelum datanya
kekirim dan ditolak di tahap approval.

Sama kayak pelanggan, `DELETE` tolong nolak dengan **422** kalau ruangannya
masih kepakai di sesi kalibrasi, dan pesannya dikirim apa adanya — mobile
nampilin persis pesan dari backend.

---

## 4. Entitas Order Kalibrasi — **paling besar**

Ini bukan nambah field, tapi entitas baru. Sekarang "nomor order" cuma **field
teks** yang nempel di sesi kalibrasi, bukan sesuatu yang bisa dicari, dicetak,
atau ditugaskan ke teknisi.

Spec-nya minta Order jadi entitas sendiri: satu order masuk (alat dari
pelanggan) → ditugaskan ke teknisi → baru jadi sesi kalibrasi.

**Minta:**

```
GET    /api/orders
GET    /api/orders/{id}
POST   /api/orders
PUT    /api/orders/{id}
```

```json
{
  "id": 1,
  "nomor_order": "ORD-2026-07-001",
  "tanggal": "2026-07-20",
  "tanggal_terima": "2026-07-18",
  "customer": { "id": 3, "nama": "PT Tirta Gracia" },
  "equipment": {
    "id": 5,
    "nama_alat": "pH Meter Bench",
    "merk": "Hanna",
    "model": "HI-2211",
    "serial_number": "HN-2211-05",
    "rentang_ukur": "0–14 pH"
  },
  "lokasi_kalibrasi": "lab",
  "teknisi": { "id": 2, "nama": "Teknisi ASMO" },
  "status": "menunggu"
}
```

Dua hal yang **belum ada di model Equipment** dan dibutuhin di sini:

| Field | Kenapa |
|---|---|
| `rentang_ukur` | Diminta spec di form Order; sekarang nggak ketemu di model |
| penugasan `teknisi` | Belum ada relasi teknisi ke order/sesi mana pun |

Yang perlu diputuskan bareng sebelum digarap:

1. Sesi kalibrasi nyantol ke order (`order_id`), atau order cuma pembungkus
   longgar? Ini nentuin apakah `nomor_order` di sesi tetap dipertahankan atau
   diganti relasi.
2. Satu order = satu alat, atau bisa banyak alat sekaligus? Klien biasanya
   nganter beberapa alat barengan, tapi bentuk JSON di atas masih asumsi
   satu alat per order.

**Cetak Work Order** nyusul setelah entitasnya ada — kemungkinan bentuknya
sama kayak sertifikat (`GET /api/orders/{id}/download`, balikin PDF, bukan HTML).

---

## 5. Perkaya `GET /api/calibrations/{id}` buat layar Sertifikat — **murah, sebagian datanya udah ada**

*Ditambahkan 22 Juli 2026.*

### Kenapa

Mobile sekarang punya layar Sertifikat yang dipakai teknisi/admin buat
**nyocokin isi sertifikat sebelum di-approve**. Ini penting karena sesi yang
udah `disetujui` nggak bisa diubah lagi (`422`) — begitu sertifikatnya terbit
dan dipegang pelanggan, angka yang salah nggak bisa ditarik diam-diam.

Masalahnya: respons sesi cuma ngirim

```json
"equipment": { "id": 12, "nama_alat": "Jangka Sorong Mitutoyo" }
```

Padahal formulir sertifikat resmi (`SIDIK-FM-CAL-2403_Rev. 0`) punya belasan
field lain di kepalanya. PDF-nya sendiri **udah bener** — backend yang generate
dan backend punya semua datanya. Yang nggak punya cuma layar HP-nya.

Akibatnya layar Sertifikat di mobile cuma bisa nampilin sebagian: tanggal,
teknisi, metode, kondisi lingkungan, tabel Laporan Kalibrasi, dan Standar yang
Dipakai. Sisanya sengaja **nggak dirender sama sekali** — bukan dikasih strip
kosong, karena kolom kosong di layar pencocokan bikin orang ngira datanya juga
ilang di sertifikat.

### Yang diminta

Tolong `GET /api/calibrations/{id}` ikut ngirim field di bawah. Urutannya dari
yang paling murah.

**(a) Dua field yang datanya UDAH ADA di baris sesi — mobile sendiri yang
ngirim waktu `POST`, cuma nggak pernah dibalikin:**

```json
"nomor_order": "2405.13.A",
"tanggal_terima": "2024-05-26"
```

Ini isi kolom *Order Number* & *Received Date*. Kemungkinan cuma nambah dua
baris di `CalibrationResource`.

**(b) Objek `equipment` digemukin** — semuanya udah ada di tabel `equipments`:

```json
"equipment": {
  "id": 12,
  "nama_alat": "pH Meter",
  "merk": "Mettler Toledo",
  "model": "Five Easy",
  "serial_number": "B628755900",
  "range_min": 0, "range_max": 14, "satuan": "pH",
  "resolusi": 0.01,
  "pelanggan": { "nama": "PT TIRTA GRACIA SEMESTA MANDIRI", "alamat": "Jl. Arteri Primer A-10 ..." }
}
```

Ngisi kolom *Manufacturer*, *Model/Type*, *Serial Number*,
*Capacity/Graduation* (`range_min`–`range_max` / `resolusi`), *Owner*, dan
*Address*. Bentuknya sengaja disamain persis sama `GET /api/equipments` yang
udah ada, jadi mobile bisa pakai parser yang sama.

**(c) `teknisi` ikut bawa ID pegawainya:**

```json
"teknisi": { "id": 4, "nama": "Dwi Rahayu", "employee_id": "DR" }
```

Kolom *Technician ID* di sertifikat isinya `DR`, bukan nama panjang.
`employee_id` udah ada di tabel `users` (mobile udah makai di layar Profil).

**(d) `standar_acuan` di tiap titik ikut dua kolom sisanya:**

```json
"standar_acuan": {
  "id": 3, "nama": "pH Buffer Solution 7", "no_sertifikat": "HC46341939",
  "merk_type": "Supelco/Merck",
  "tertelusur_ke": "Merck KGaA"
}
```

Tabel *Standard used* di sertifikat punya 4 kolom; yang nyampe ke mobile baru
2. Buat baris "Termometer & Sensor Std." nilai `tertelusur_ke`-nya
`LK-285-IDN`, jadi kelihatannya ini emang kolom di tabel `standards`.

**(e) `sertifikat` ikut tanggal terbit & penanda tangan:**

```json
"sertifikat": { "id": 9, "nomor": "012-CAL-524", "status": "terbit", "pdf_url": "...",
                "tanggal_terbit": "2024-05-30",
                "penanda_tangan": { "nama": "Alex Misramto", "jabatan": "Technical Manager" } }
```

Buat *Issuance Date* dan blok tanda tangan.

### Satu yang perlu disepakati dulu, bukan sekadar nambah field

Kolom **Calibration Location** di sertifikat isinya `Lab. Uji A` — itu
**ruangan**, dan `kontrak-api.md` §9 sendiri nyatet:

> ⚠️ Belum nyambung ke sesi kalibrasi. `POST /api/calibrations` **belum**
> nerima `room_id`. […] Kalau mobile mau layar sesi bisa milih ruangan, bilang
> dulu biar dibarengin.

Ini bilangnya: **iya, mobile butuh.** Sekarang sesi cuma nyimpen `lokasi` enum
`lab`/`onsite`, yang nggak cukup buat ngisi kolom itu. Kalau `room_id`
ditambahin di `POST`/`PUT` + ikut dibalikin di response, mobile nambahin
dropdown "Ruangan" di form sesi.

Karena ini ngubah bentuk sesi, silakan tentuin timing-nya — mobile ngikut.

### Aman dirilis bertahap

Semua field di atas **tambahan**, nggak ada yang ngubah/ngilangin field lama.
Parser mobile (`CalibrationDetail.fromJson`) nganggep field yang belum ada
sebagai `null` dan barisnya nggak dirender — jadi (a) bisa dirilis sendirian
minggu ini tanpa nunggu (b)–(e), dan tanpa nunggu mobile update.

---

## 6. Yang **tidak** diminta, dan alasannya

Supaya nggak salah paham arah:

| Item spec | Kenapa nggak diminta |
|---|---|
| Kolom Koreksi & Ketidakpastian di form input | Itu **hasil hitungan**, bukan isian teknisi. Udah diputuskan jadi domain backend (`Aturan Bisnis Inti.md`). Mobile nampilin hasilnya, nggak ngitung. |
| Tombol "Hitung" di mobile | Sama. Kalau HP ikut ngitung GUM/ILAC-G8, angkanya berisiko beda sama sertifikat resmi — masalah serius buat lab terakreditasi. |
| Import Excel | Pola desktop. Di HP, ngambil file Excel lalu mappingnya lebih ribet daripada ngetik langsung. Kalau tetap dibutuhin, lebih pas di panel web admin. |
| Backup Database | Urusan server, bukan aplikasi HP. |
| User Management penuh | Sebagian udah kepenuhin `GET /users` + approve/reject/reset-password, dan layar Data Teknisi udah dibikin di atas itu. Bikin/hapus akun lebih pas di panel admin. |

---

## 7. Ringkasan buat rapat

> ⛔ **KOREKSI 25 Juli 2026.** Nomor 2 & 4 di tabel ini sempat ditandain ✅ padahal
> **nggak ada di repo ini** — dicek ke `routes/api.php`, nggak ada route
> `/dashboard/tren` maupun `/orders`, dan nggak ada `OrderController` atau model
> `Order`. Tandanya kelihatannya nyalin dari repo `asmo-api`. Status terverifikasi
> ada di [`BACA-DULU-BACKEND.md`](BACA-DULU-BACKEND.md).
>
> ✅ **26 Juli 2026: nomor 2 (`/dashboard/tren`) sekarang beneran ada.** Nomor 4
> (`/orders`) masih belum — dan masih perlu dipastiin dulu beneran dibutuhin apa
> nggak, soalnya `nomor_order` & `tanggal_terima` udah ada di sesi.

| # | Permintaan | Ukuran | Ngeblok | Status |
|---|---|---|---|---|
| 1 | `kalibrasi_selesai` di `/dashboard` | Kecil | Kartu "Kalibrasi selesai" | ✅ udah ada |
| ~~2~~ | ~~`GET /dashboard/tren`~~ | — | — | ✅ **jadi 26 Jul** — `hari`/`minggu`/`bulan`, periode kosong tetap `0`, plus `label` & `penyaring`. Kuncinya `periode` (bukan `bulan`) |
| 3 | CRUD `/rooms` | Sedang | Master Data → Data Ruangan | ✅ udah ada |
| 4 | Entitas `/orders` + `rentang_ukur` + teknisi | Besar | Seluruh bagian Order Kalibrasi | ❌ **belum ada** (nggak ada route/controller/model). `rentang_ukur` ✅ ada di `EquipmentResource`; `nomor_order` & `tanggal_terima` ✅ ada di sesi |
| 5a | `nomor_order` + `tanggal_terima` di detail sesi | **Sangat kecil** | 2 kolom kepala sertifikat | ✅ **ada** (`CalibrationResource:30,41`) |
| 5b | `equipment` digemukin (+ `pelanggan.alamat`) | Kecil | 6 kolom kepala sertifikat | ✅ **ada** (`EquipmentResource`) |
| 5c | `employee_id` di `teknisi` | Sangat kecil | Kolom *Technician ID* | ✅ **jadi 25 Jul** — plus `kode_teknisi` & `department`. ⚠️ Kolom *Technician ID* isinya `kode_teknisi` (`DR`), BUKAN `employee_id` (`SDK-2001`) — contoh di §5c ketuker |
| 5d | `merk_type` + `tertelusur_ke` di `standar_acuan` | Kecil | 2 kolom tabel *Standard used* | ✅ **jadi 25 Jul** — plus `merk`, `model`, `serial_number`. Level sesi & per-titik dua-duanya |
| 5e | `tanggal_terbit` + penanda tangan di `sertifikat` | Kecil | *Issuance Date* + blok TTD | 🔸 **SEBAGIAN 25 Jul** — `diterbitkan_pada`, `berlaku_sampai`, `qr_token`, `qr_payload` ikut. **Penanda tangan belum** (butuh keputusan: role keempat atau atribut user). `reviewer` ("Checked by") udah ikut |
| — | `room_id` di sesi kalibrasi | Sedang | Kolom *Calibration Location* | ✅ **udah jalan** — ikut di request & response sebagai `ruangan.{id,kode,nama}` |

Nomor **5a** paling murah dan datanya udah kesimpen — mobile sendiri yang
ngirim waktu `POST`, cuma nggak pernah dibalikin. Bisa dirilis sendirian.

Semua permintaan nomor 5 sifatnya **nambah field**, nggak ngubah yang lama, dan
mobile udah aman nerima respons tanpa field-field itu.

---

*Disusun 20 Juli 2026 · dibaca dari `develop` setelah PR #21 & #22 di-merge,
dicek langsung ke `routes/api.php` dan controller-nya, bukan dari dokumentasi.*

*Nomor 5 ditambahkan 22 Juli 2026. Daftar kolomnya dicocokin baris per baris
sama sertifikat asli `012-CAL-524` (formulir `SIDIK-FM-CAL-2403_Rev. 0`) yang
dikirim Zainul, bukan dikarang dari nama field. Yang dari sisi mobile
diverifikasi ke kode: `nomor_order` & `tanggal_terima` emang udah dikirim
`CalibrationDraft.toJson()`, `employee_id` udah dipakai layar Profil, dan
catatan `room_id` dikutip dari `kontrak-api.md` §9.*

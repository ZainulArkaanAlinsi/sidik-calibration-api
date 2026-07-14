---
aliases: [ERD, ERD Awal, Skema Database]
---

# ERD Awal — Skema Database ASMO

🏠 [[Dashboard]] · Dirancang [[2026-07-14]] (Minggu 1 Hari ke-2) · PIC Backend: **Raihan**

Rancangan tabel untuk pipeline kalibrasi: **alat → sesi kalibrasi → data mentah → perhitungan ketidakpastian → sertifikat**. Jadi acuan migration di [[2026-07-15]].

Aturan yang dipegang saat merancang: [[Aturan Bisnis Inti]]. Batas rentang & ketidakpastian per kategori: [[Data Kemampuan Kalibrasi]].

## Diagram ERD

```mermaid
erDiagram
    ORGANIZATIONS ||--o{ USERS : "punya"
    ORGANIZATIONS ||--o{ CUSTOMERS : "punya"
    ORGANIZATIONS ||--o{ EQUIPMENT_CATEGORIES : "punya"
    ORGANIZATIONS ||--o{ EQUIPMENTS : "punya"
    ORGANIZATIONS ||--o{ STANDARDS : "punya"
    ORGANIZATIONS ||--o{ CERTIFICATES : "menerbitkan"

    CUSTOMERS ||--o{ EQUIPMENTS : "memiliki alat"
    EQUIPMENT_CATEGORIES ||--o{ EQUIPMENTS : "mengklasifikasi"
    EQUIPMENT_CATEGORIES ||--o{ CALIBRATION_CAPABILITIES : "punya rentang CMC"

    EQUIPMENTS ||--o{ CALIBRATION_SESSIONS : "dikalibrasi lewat"
    USERS ||--o{ CALIBRATION_SESSIONS : "diinput teknisi"
    USERS ||--o{ CALIBRATION_SESSIONS : "direview admin"
    STANDARDS ||--o{ CALIBRATION_SESSIONS : "jadi acuan"

    CALIBRATION_SESSIONS ||--o{ RAW_MEASUREMENTS : "menghasilkan"
    CALIBRATION_SESSIONS ||--o{ UNCERTAINTY_CALCULATIONS : "dihitung jadi"
    CALIBRATION_SESSIONS ||--o| CERTIFICATES : "diterbitkan jadi"
    USERS ||--o{ CERTIFICATES : "disetujui oleh"
    CERTIFICATES ||--o| CERTIFICATES : "revisi dari"

    ORGANIZATIONS {
        bigint id PK
        string name "nama PT / laboratorium"
        string address
        string phone
        string email
        string accreditation_no "no. akreditasi KAN, tampil di sertifikat"
        string logo_path
        json settings "prefix nomor sertifikat, masa berlaku default, dll"
        timestamp created_at
        timestamp updated_at
    }

    USERS {
        bigint id PK
        bigint organization_id FK
        string name
        string email UK "unik global, dipakai buat login"
        string password
        enum role "admin | teknisi | viewer"
        string phone
        string signature_path "ttd buat dibubuhkan di sertifikat"
        boolean is_active
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at "soft delete, jangan hard delete (audit)"
    }

    CUSTOMERS {
        bigint id PK
        bigint organization_id FK
        string name "pelanggan pemilik alat"
        string address
        string contact_person
        string phone
        string email
        timestamp deleted_at
    }

    EQUIPMENT_CATEGORIES {
        bigint id PK
        bigint organization_id FK
        string code UK "unik per organisasi, contoh: TEMP-IND"
        string name "contoh: Temperature Indicator tanpa Sensor"
        string measurement_group "Suhu | Massa | Volume | Tekanan | Gaya | dll"
        string default_unit
        string method_document "contoh: SIDIK-IK-CAL-0502_Rev.3"
        json worksheet_schema "definisi kolom baku worksheet per kategori"
        timestamp deleted_at
    }

    CALIBRATION_CAPABILITIES {
        bigint id PK
        bigint equipment_category_id FK
        string parameter "nullable, contoh: Thermocouple sensor type K"
        decimal range_min
        decimal range_max
        string unit
        decimal best_uncertainty "U terbaik lab (CMC)"
        string uncertainty_unit
        decimal coverage_factor "default 2 (95 persen)"
    }

    EQUIPMENTS {
        bigint id PK
        bigint organization_id FK
        bigint customer_id FK
        bigint equipment_category_id FK
        string name
        string manufacturer
        string model
        string serial_number UK "unik per organisasi"
        string identification_no "no. inventaris pelanggan"
        decimal range_min
        decimal range_max
        string unit
        decimal resolution "resolusi alat, komponen Type B"
        decimal tolerance "batas keberterimaan buat ILAC-G8, nullable"
        string location
        date last_calibration_date
        date next_calibration_date "dipakai notifikasi jatuh tempo"
        enum status "active | inactive | retired"
        timestamp deleted_at
    }

    STANDARDS {
        bigint id PK
        bigint organization_id FK
        string name "alat standar / acuan milik lab"
        string manufacturer
        string model
        string serial_number
        string certificate_no "no. sertifikat kalibrasi si standar"
        string traceability "tertelusur ke: SNSU-BSN / NMI lain"
        date valid_until "kalau lewat, nggak boleh dipakai kalibrasi"
        decimal uncertainty "U standar, sumber utama Type B"
        string uncertainty_unit
        decimal coverage_factor
        decimal drift "hanyutan per tahun, nullable"
    }

    CALIBRATION_SESSIONS {
        bigint id PK
        bigint organization_id FK
        bigint equipment_id FK
        bigint technician_id FK "users.id, yang input"
        bigint standard_id FK "nullable sampai teknisi pilih"
        bigint reviewed_by FK "users.id admin, nullable"
        string session_number UK "unik per organisasi"
        enum input_method "manual | ocr"
        enum status "draft | submitted | revision | approved | certified"
        enum result "pass | fail, nullable sebelum dihitung"
        date calibration_date
        enum location_type "lab | onsite"
        decimal ambient_temperature "kondisi lingkungan saat kalibrasi"
        decimal ambient_humidity
        text review_notes "catatan revisi dari admin"
        timestamp submitted_at
        timestamp reviewed_at
    }

    RAW_MEASUREMENTS {
        bigint id PK
        bigint calibration_session_id FK
        int point_index "titik ukur ke-berapa"
        int reading_index "pengulangan ke-berapa di titik itu"
        decimal nominal_value "nilai acuan / setpoint standar"
        decimal reading_value "pembacaan alat yang dikalibrasi"
        string unit
        enum input_source "manual | ocr"
        decimal ocr_confidence "nullable, skor keyakinan ML Kit"
        text ocr_raw_text "nullable, teks mentah hasil scan"
        string photo_path "nullable, bukti foto"
        boolean is_verified "wajib true sebelum submit"
        timestamp created_at
    }

    UNCERTAINTY_CALCULATIONS {
        bigint id PK
        bigint calibration_session_id FK
        int point_index
        decimal nominal_value
        decimal mean_reading "rata-rata pembacaan di titik ini"
        decimal error_value "mean_reading - nominal_value"
        decimal correction "negatif dari error"
        decimal std_deviation
        int repeat_count "n pengulangan"
        decimal type_a "u_a = s / akar(n)"
        json type_b_components "rincian tiap komponen Type B, lihat catatan di bawah"
        decimal type_b "u_b gabungan"
        decimal combined_uncertainty "u_c"
        decimal coverage_factor "k, default 2"
        decimal effective_dof "v_eff Welch-Satterthwaite, nullable"
        decimal expanded_uncertainty "U = k dikali u_c"
        decimal tolerance "batas keberterimaan yang dipakai"
        enum result "pass | fail, per titik ukur (ILAC-G8)"
        timestamp calculated_at
    }

    CERTIFICATES {
        bigint id PK
        bigint organization_id FK
        bigint calibration_session_id FK
        bigint issued_by FK "users.id admin yang approve"
        bigint revision_of FK "certificates.id, nullable"
        string certificate_number UK "CAL/2026/07/0001, unik per organisasi"
        uuid verification_token UK "dipakai di URL verifikasi publik"
        text qr_payload "payload terenkripsi AES-256"
        string pdf_path
        date issued_date
        date valid_until
        enum status "issued | revised | revoked"
        text revision_reason "nullable"
        timestamp created_at
    }
```

## Kamus Tabel Singkat

| Tabel | Isinya apa | Kenapa perlu |
|---|---|---|
| `organizations` | PT / laboratorium | Akar multi-tenant. Nomor sertifikat unik **per organisasi**, jadi tenant wajib ada dari awal |
| `users` | admin / teknisi / viewer | Role menentukan akses (lihat [[Aturan Bisnis Inti]]) |
| `customers` | pelanggan pemilik alat | Master data; alat dikalibrasi atas nama pelanggan, namanya nongol di sertifikat |
| `equipment_categories` | kategori alat + kolom worksheet baku | Menentukan bentuk worksheet & metode kalibrasi yang dipakai teknisi |
| `calibration_capabilities` | rentang ukur & U terbaik (CMC) per kategori | Validasi: alat di luar rentang lab nggak boleh dikalibrasi. Sumbernya [[Data Kemampuan Kalibrasi]] |
| `equipments` | alat milik pelanggan (UUC) | Objek yang dikalibrasi; `next_calibration_date` jadi sumber notifikasi jatuh tempo |
| `standards` | alat standar milik lab | Ketidakpastian standar = komponen Type B terbesar. Wajib ada demi ketertelusuran (ISO 17025) |
| `calibration_sessions` | satu event kalibrasi 1 alat | Pusat pipeline; `status` yang jalan di alur approval |
| `raw_measurements` | angka mentah per titik & per pengulangan | Bukti audit. Manual & OCR masuk ke tabel yang **sama** |
| `uncertainty_calculations` | hasil hitung per titik ukur | Output GUM + keputusan ILAC-G8 |
| `certificates` | sertifikat terbit | Immutable; revisi = baris baru yang nunjuk ke `revision_of` |

## Alur Status Sesi Kalibrasi

```mermaid
stateDiagram-v2
    [*] --> draft: teknisi mulai input (manual/OCR)
    draft --> submitted: submit, perhitungan GUM jalan
    submitted --> revision: admin minta revisi
    revision --> submitted: teknisi perbaiki & submit ulang
    submitted --> approved: admin setuju
    approved --> certified: sertifikat & PDF terbit
    certified --> [*]
```

Catatan: `result` (PASS/FAIL) **terpisah** dari `status`. Sesi FAIL tetap bisa lanjut ke `approved` → `certified` — sertifikat FAIL itu sah, cuma isinya beda (lihat [[Aturan Bisnis Inti]]).

## Keputusan Desain (& kenapa)

**1. `raw_measurements` = satu baris per pembacaan, bukan JSON array per titik.**
Type A butuh standar deviasi dari tiap pengulangan, dan tiap angka hasil OCR punya `ocr_confidence` + foto sendiri. Kalau ditumpuk jadi JSON, dua-duanya susah diaudit dan susah di-query ("angka mana yang keyakinan OCR-nya rendah?").

**2. Komponen Type B disimpan sebagai JSON di `uncertainty_calculations.type_b_components`.**
Bentuknya: `[{source, value, distribution, divisor, sensitivity, u_i}]` — contoh source: ketidakpastian standar, resolusi alat, drift, kondisi lingkungan. Dipilih JSON dulu karena jumlah komponennya beda-beda per kategori alat. **Kalau nanti butuh query per komponen** (misal laporan "kontribusi resolusi vs standar"), pecah jadi tabel `uncertainty_components` — migrasinya gampang karena datanya sudah terstruktur.

**3. Nilai hasil hitung disimpan, bukan dihitung on-the-fly saat baca.**
Sertifikat yang sudah terbit harus tetap menunjukkan angka yang sama 5 tahun lagi, walaupun rumus/konstantanya berubah. Hitung sekali saat submit, simpan hasilnya.

**4. `organization_id` ikut ditempel di tabel anak (`equipments`, `calibration_sessions`, `certificates`).**
Sedikit denormalisasi, tapi bikin global scope multi-tenant jadi satu `where` aja — nggak perlu join berantai cuma buat mastiin data nggak bocor antar-PT.

**5. `certificates.verification_token` (UUID), bukan `id`, yang dipakai di URL verifikasi publik.**
Endpoint verifikasi QR itu tanpa login. Kalau pakai `id` berurutan, orang bisa nebak-nebak sertifikat lain.

**6. Soft delete (`deleted_at`) di master data; data kalibrasi & sertifikat nggak boleh dihapus.**
Retensi audit 5 tahun. Alat "dihapus" harus tetap bisa ditelusuri dari sertifikat lama.

## Catatan Buat yang Bikin Migration ([[2026-07-15]])

- **Awas nama tabel `equipments`.** Inflector Laravel nganggep "equipment" itu uncountable, jadi model `Equipment` bakal nyari tabel `equipment` (tanpa `s`). Karena rencananya pakai `equipments`, set eksplisit di model: `protected $table = 'equipments';`
- Tabel `users` sudah ada dari Laravel bawaan → hari ini tinggal `ALTER` (tambah `organization_id`, `role`, `phone`, `signature_path`, `is_active`, `deleted_at`), bukan bikin baru.
- Urutan migration ngikutin arah FK: `organizations` → `users` → `customers` → `equipment_categories` → `calibration_capabilities` → `standards` → `equipments` → `calibration_sessions` → `raw_measurements` → `uncertainty_calculations` → `certificates`.
- `certificate_number` cukup `unique(organization_id, certificate_number)`. Tapi ingat: **anti-dobel nomornya tetap wajib pakai transaction locking**, unique constraint cuma jaring pengaman terakhir.
- Semua kolom nilai ukur pakai `decimal`, **jangan `float`** — hasil kalibrasi nggak boleh kena error pembulatan biner.
- Notifikasi jatuh tempo (Minggu 09) rencananya pakai tabel `notifications` bawaan Laravel + scheduled job yang nyisir `equipments.next_calibration_date`. Belum masuk ERD ini.
- Template sertifikat yang bisa dikustom admin (Minggu 08) → nanti tabel `certificate_templates`. Belum masuk ERD ini.

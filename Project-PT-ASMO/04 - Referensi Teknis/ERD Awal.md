---
aliases: [ERD, ERD Awal, Skema Database]
---

# ERD — Skema Database ASMO

🏠 [[Dashboard]] · Dirancang [[2026-07-14]], **dieksekusi jadi migration di hari yang sama** · PIC Backend: **Raihan**

Skema untuk pipeline kalibrasi: **alat → sesi kalibrasi → data mentah → perhitungan ketidakpastian → sertifikat**. Dokumen ini **sudah disamain dengan migration yang beneran jalan** — kalau beda, migration yang bener.

> **Nama kolom & nilai enum ngikutin `docs/kontrak-api.md` (repo mobile), bukan istilah Inggris di draft awal.** Alasannya: mobile ngoding persis string itu (`status: "menunggu_approval"`, `keputusan: "PASS"`). Kalau backend pakai istilah sendiri, tiap response harus diterjemahin dulu — dan penerjemahan itu tempat bug diam-diam lahir.

Aturan yang dipegang: [[Aturan Bisnis Inti]]. Batas rentang & ketidakpastian: [[Data Kemampuan Kalibrasi]].

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
        string nama "PT / laboratorium"
        string alamat
        string telepon
        string email
        string no_akreditasi "no. akreditasi KAN, dicetak di kop sertifikat"
        string logo_path
        json settings "prefix nomor sertifikat, masa berlaku default, k default"
    }

    USERS {
        bigint id PK
        bigint organization_id FK
        string employee_id UK "ID pegawai, dipakai buat login"
        string name
        string department
        string email UK
        string password
        enum role "admin | teknisi | viewer"
        enum status "aktif | pending | nonaktif"
    }

    CUSTOMERS {
        bigint id PK
        bigint organization_id FK
        string nama "pelanggan pemilik alat"
        string alamat
        string contact_person
        string telepon
        string email
        timestamp deleted_at "soft delete"
    }

    EQUIPMENT_CATEGORIES {
        bigint id PK
        bigint organization_id FK
        string kode UK "slug kelompok pengukuran, contoh: suhu-dan-kelembapan"
        string nama "contoh: Suhu dan Kelembapan"
        json worksheet_schema "kolom baku worksheet per kategori, diisi nanti"
    }

    CALIBRATION_CAPABILITIES {
        bigint id PK
        bigint equipment_category_id FK
        string nama_alat "contoh: Temperature Indicator tanpa Sensor"
        string parameter "nullable, contoh: Thermocouple sensor type K"
        decimal range_min "NULLABLE - banyak kemampuan cuma titik tunggal"
        decimal range_max
        string range_note "teks asli kalau non-numerik, contoh: ambient"
        string satuan
        decimal ketidakpastian_terbaik "U terbaik lab (CMC)"
        string satuan_ketidakpastian
        decimal faktor_cakupan "default 2"
        text metode "dokumen standar, contoh: SIDIK-IK-CAL-0502_Rev.3"
    }

    EQUIPMENTS {
        bigint id PK
        bigint organization_id FK
        bigint customer_id FK
        bigint equipment_category_id FK
        string nama_alat
        string merk
        string model
        string serial_number UK "unik per organisasi"
        string no_identifikasi "no. inventaris pelanggan"
        decimal range_min
        decimal range_max
        string satuan
        decimal resolusi "komponen Type B"
        decimal toleransi "batas keberterimaan buat ILAC-G8"
        string lokasi
        date tanggal_kalibrasi_terakhir
        date tanggal_jatuh_tempo "sumber notifikasi & status overdue"
        enum status "aktif | nonaktif - overdue TIDAK disimpen, dihitung"
        timestamp deleted_at
    }

    STANDARDS {
        bigint id PK
        bigint organization_id FK
        string nama "alat standar / acuan milik lab"
        string merk
        string model
        string serial_number
        string no_sertifikat "sertifikat kalibrasi si standar"
        string tertelusur_ke "SNSU-BSN / NMI lain"
        date berlaku_sampai "lewat ini, nggak boleh dipakai kalibrasi"
        decimal ketidakpastian "U standar - sumber Type B terbesar"
        string satuan_ketidakpastian
        decimal faktor_cakupan
        decimal drift "hanyutan per tahun"
    }

    CALIBRATION_SESSIONS {
        bigint id PK
        bigint organization_id FK
        bigint equipment_id FK
        bigint teknisi_id FK "users.id, yang input"
        bigint standard_id FK "nullable"
        bigint reviewed_by FK "users.id admin, nullable"
        string nomor_sesi UK "unik per organisasi"
        enum input_method "manual | ocr"
        enum status "draft | menunggu_approval | disetujui | perlu_revisi"
        enum keputusan "PASS | FAIL - nullable sebelum dihitung"
        date tanggal_kalibrasi
        enum lokasi "lab | onsite"
        decimal suhu_ruang
        decimal kelembaban
        text catatan_revisi "dari admin waktu nolak"
        timestamp submitted_at
        timestamp reviewed_at
    }

    RAW_MEASUREMENTS {
        bigint id PK
        bigint calibration_session_id FK
        int titik_ke "titik ukur ke-berapa"
        int pembacaan_ke "pengulangan ke-berapa di titik itu"
        decimal titik_ukur "nilai acuan / setpoint standar"
        decimal pembacaan "yang kebaca di alat"
        string satuan
        enum input_source "manual | ocr"
        decimal ocr_confidence "nullable, skor keyakinan ML Kit"
        text ocr_raw_text "nullable, teks mentah hasil scan"
        string photo_path "nullable, bukti foto"
        boolean is_verified "wajib true sebelum submit"
    }

    UNCERTAINTY_CALCULATIONS {
        bigint id PK
        bigint calibration_session_id FK
        int titik_ke
        decimal titik_ukur
        decimal rata_rata
        decimal error "rata_rata - titik_ukur"
        decimal koreksi "negatif dari error"
        decimal standar_deviasi
        int jumlah_pengulangan
        decimal type_a "u_a = s / akar(n)"
        json type_b_components "rincian tiap komponen Type B"
        decimal type_b "u_b gabungan"
        decimal ketidakpastian_gabungan "u_c"
        decimal faktor_cakupan_k "k, default 2"
        decimal derajat_kebebasan_efektif "v_eff Welch-Satterthwaite"
        decimal ketidakpastian_diperluas "U = k dikali u_c"
        decimal toleransi
        enum keputusan "PASS | FAIL - per titik ukur (ILAC-G8)"
        timestamp calculated_at
    }

    CERTIFICATES {
        bigint id PK
        bigint organization_id FK
        bigint calibration_session_id FK
        bigint issued_by FK "users.id admin yang approve"
        bigint revision_of FK "certificates.id, nullable"
        string nomor UK "CAL/2026/07/0001, unik per organisasi"
        string qr_token UK "dipakai di URL verifikasi publik"
        text qr_payload "terenkripsi AES-256"
        string pdf_path
        date diterbitkan_pada
        date berlaku_sampai
        enum status "menunggu_generate | terbit | gagal"
        text alasan_revisi
    }
```

## Alur Status Sesi Kalibrasi

```mermaid
stateDiagram-v2
    [*] --> draft: teknisi mulai input (manual/OCR)
    draft --> menunggu_approval: submit, perhitungan GUM jalan
    menunggu_approval --> perlu_revisi: admin nolak + isi catatan
    perlu_revisi --> menunggu_approval: teknisi perbaiki, submit ulang
    menunggu_approval --> disetujui: admin setuju
    disetujui --> [*]: sertifikat digenerate (queue)
```

`keputusan` (PASS/FAIL) **terpisah** dari `status`. Sesi FAIL tetap bisa `disetujui` dan terbit sertifikat — isinya aja beda. Jangan diblokir di backend (lihat [[Aturan Bisnis Inti]]).

## Keputusan Desain (& kenapa)

**1. `raw_measurements` = satu baris per pembacaan, bukan JSON array per titik.**
Type A butuh standar deviasi dari tiap pengulangan, dan tiap angka hasil OCR bawa `ocr_confidence` + foto sendiri. Kalau ditumpuk jadi JSON, dua-duanya nggak bisa diaudit ("angka mana yang OCR-nya ragu?").

**2. Komponen Type B disimpan sebagai JSON di `uncertainty_calculations.type_b_components`.**
Bentuknya `[{source, value, distribution, divisor, sensitivity, u_i}]` — sumbernya: ketidakpastian standar, resolusi alat, drift, kondisi lingkungan. JSON karena jumlah komponennya beda-beda per kategori alat. Kalau nanti perlu laporan "kontribusi resolusi vs standar", pecah jadi tabel `uncertainty_components`.

**3. Hasil hitung DISIMPAN, bukan dihitung ulang tiap dibaca.**
Sertifikat yang terbit 5 tahun lalu harus tetap nunjukin angka yang sama walau rumus/konstantanya udah berubah.

**4. `status: overdue` di alat SENGAJA nggak disimpen di DB.**
Dia turunan dari `tanggal_jatuh_tempo`. Kalau disimpen, nilainya basi tiap ganti hari dan butuh cron buat nyegerin. Di DB cuma ada `aktif`/`nonaktif`; `overdue` dihitung waktu dibaca. Mobile tetap nerima 3 nilai persis kayak di kontrak.

**5. `organization_id` ikut ditempel di tabel anak (`equipments`, `calibration_sessions`, `certificates`).**
Sedikit denormalisasi, tapi scoping multi-tenant jadi satu `where` — nggak perlu join berantai cuma buat mastiin data nggak bocor antar-PT.

**6. `certificates.qr_token` (acak), bukan `id`, yang dipakai di URL verifikasi publik.**
Endpoint verifikasi QR itu tanpa login. Kalau pakai `id` berurutan, orang bisa nebak-nebak sertifikat lain.

**7. Soft delete di master data; data kalibrasi & sertifikat nggak boleh dihapus.**
Retensi audit 5 tahun. Alat yang "dihapus" harus tetap bisa ditelusuri dari sertifikat lama.

**8. `calibration_capabilities.range_min` nullable + ada `range_note`.**
Dari 151 rentang di lampiran akreditasi, **59 nggak punya batas bawah numerik**: ada yang kemampuan titik tunggal (buret "25 mL"), ada yang batasnya kata-kata (oven "ambient ~ 300 °C"). Teks aslinya disimpen di `range_note` biar nggak ilang.

## Jebakan yang Udah Kena (jangan diulang)

**Nama tabel `equipments` berantem sama inflector Laravel.** Laravel nganggep "equipment" itu uncountable, jadi:
- Model `Equipment` nyari tabel `equipment` (tanpa `s`) → **wajib** `protected $table = 'equipments';`
- `$table->foreignId('equipment_id')->constrained()` juga nebak tabel `equipment` → **wajib** `constrained('equipments')`

Yang kedua ini bikin migration gagal total waktu pertama dijalanin (`Failed to open the referenced table 'equipment'`).

## Urutan Migration

`organizations` → `users` (alter) → `customers` → `equipment_categories` → `calibration_capabilities` → `standards` → `equipments` → `calibration_sessions` → `raw_measurements` → `uncertainty_calculations` → `certificates` → FK `users.organization_id`.

FK dari `users` ke `organizations` sengaja ditaruh paling akhir: kolomnya udah kebikin duluan waktu bikin auth (sebelum tabel `organizations` ada).

## Seeder

`php artisan migrate:fresh --seed` ngisi:
- **1 organisasi** — PT Sidik (dari lampiran akreditasi)
- **10 kategori + 151 kemampuan kalibrasi** — diimpor dari `data-kemampuan-kalibrasi.json`
- **4 akun**: `ASM-0001` admin · `ASM-0002` teknisi · `ASM-0003` viewer · `ASM-0099` sengaja `pending` (buat nyoba layar "belum disetujui"). Password semua `rahasia123`
- **2 pelanggan + 5 alat + 1 standar** — 2 di antaranya sengaja lewat jatuh tempo, biar status `overdue` & angka dashboard kebukti jalan

## Yang Belum Masuk ERD Ini

- `notifications` (Minggu 09) — rencananya pakai tabel bawaan Laravel + scheduled job yang nyisir `equipments.tanggal_jatuh_tempo`
- `certificate_templates` (Minggu 08) — template sertifikat yang bisa dikustom admin

# Handoff Frontend — Dashboard & Tambah Alat

Dokumen buat tim frontend/mobile (`asmo_mobile`). Isinya: perubahan yang diminta + kontrak API-nya.

> **Batasan dokumen ini:** poin UI (1, 2, 4) ditulis dari permintaan Zainul — penulis dokumen ini **belum lihat kode Flutter-nya**, jadi nama widget/file nggak disebut. Silakan sesuaikan ke struktur yang ada. Bagian **kontrak API-nya sudah diverifikasi langsung ke server** (bukan asumsi).

---

## A. Perubahan yang diminta

### 1. Rapikan tombol di dashboard
- Tombol yang ada **di bawah "Alat Kalibrasi"** → **dihapus**, fungsinya **digabung ke tombol "Kalibrasi"**.
- Alasan: yang kemarin itu cuma contoh/placeholder, bukan rancangan final.

### 2. "Tambah Alat" dibikin berfungsi
Tombol "Tambah Alat" (di bawahnya) sekarang harus **beneran jalan** — API-nya sudah siap, lihat §C.

### 3. Kartu atas: tambah total sertifikat + angka ringkas
Kartu di bagian atas dashboard nampilin **angka-angka penting** yang langsung kebaca sekilas. **`total_sertifikat` sudah ditambahkan di backend** (lihat §B).

### 4. Redesign UI/UX dashboard
Rombak tampilan biar lebih enak dilihat & informatif. Saran susunan ada di §D (silakan diubah sesuai selera desain).

---

## B. API Dashboard — `GET /api/dashboard`

Butuh `Authorization: Bearer <token>`. Satu endpoint buat seluruh layar (nggak perlu nembak banyak endpoint).

**Contoh respons nyata dari server:**

```jsonc
{
  "data": {
    "total_alat": 6,
    "alat_overdue": 3,
    "kalibrasi_draft": 0,
    "menunggu_approval": 0,
    "kalibrasi_selesai": 3,
    "menunggu_proses": 0,
    "total_sertifikat": 3,        // ← BARU
    "sertifikat_bulan_ini": 1,
    "grafik_pekerjaan": [
      { "bulan": "2026-02", "label": "Feb 2026", "masuk": 0, "selesai": 0 }
      // ... 6 bulan terakhir, termasuk bulan berjalan
    ]
  }
}
```

### Arti tiap angka

| Field | Arti |
|---|---|
| `total_alat` | Jumlah alat terdaftar |
| `alat_overdue` | Alat yang **lewat jatuh tempo** kalibrasi — cocok jadi kartu peringatan (merah) |
| `kalibrasi_draft` | Sesi yang masih disimpan sebagai draft |
| `menunggu_approval` | Sesi yang nunggu di-approve admin |
| `kalibrasi_selesai` | Sesi yang sudah disetujui |
| `menunggu_proses` | Semua sesi yang belum disetujui (draft + nunggu approval + perlu revisi) |
| `total_sertifikat` | **Total sertifikat terbit sepanjang waktu** |
| `sertifikat_bulan_ini` | Sertifikat terbit bulan berjalan |
| `grafik_pekerjaan` | Data batang 6 bulan: `masuk` vs `selesai`. `label` sudah siap pakai buat sumbu X |

### ⚠️ Perbedaan cakupan per role — penting buat UI

Backend nyaring otomatis dari token (frontend nggak usah kirim role):

- **Angka SESI** (`kalibrasi_draft`, `menunggu_approval`, `kalibrasi_selesai`, `menunggu_proses`, `grafik_pekerjaan`) → buat **teknisi = punya dia sendiri**; admin/viewer = seluruh lab.
- **Angka ALAT & SERTIFIKAT** (`total_alat`, `alat_overdue`, `total_sertifikat`, `sertifikat_bulan_ini`) → **selalu se-lab**, termasuk buat teknisi.

Konsekuensinya: di layar teknisi, "Kalibrasi selesai: 2" itu punya dia, tapi "Total sertifikat: 15" itu punya lab. Kalau nggak dikasih label yang jelas, gampang salah baca. **Saran:** kasih sub-label, mis. *"Kalibrasi Saya"* vs *"Sertifikat Lab"*.

### Grafik dengan rentang bebas (opsional)
`GET /api/dashboard/tren?dari=2026-01-01&sampai=2026-07-31&satuan=bulan`
`satuan`: `hari` | `minggu` | `bulan` (default `bulan`, default rentang 6 bulan terakhir).
Maksimum 400 titik — lebih dari itu dibalas `422` dengan pesan yang bisa langsung ditampilin.

---

## C. "Tambah Alat" — `POST /api/equipments`

Role: **admin & teknisi** (viewer ditolak 403).

```jsonc
{
  "nama_alat": "pH Meter",          // wajib
  "serial_number": "B628755900",    // wajib, unik per organisasi
  "kategori": "instrumen-analitik", // wajib — KODE kategori, dari GET /api/categories
  "pelanggan_id": 3,                // wajib — lihat catatan penting di bawah

  // opsional:
  "nama_alat_kemampuan": "pH Meter", // biar perhitungan pakai CMC yang tepat (lihat catatan)
  "merk": "Mettler Toledo",
  "model": "Five Easy",
  "no_identifikasi": "INV-001",
  "range_min": 0, "range_max": 14, "satuan": "pH",
  "resolusi": 0.01,
  "toleransi": 0.2,                  // lihat catatan
  "lokasi": "Lab. Uji A",
  "tanggal_kalibrasi_terakhir": "2024-05-26",
  "tanggal_jatuh_tempo": "2025-05-26",
  "status": "aktif",                 // "aktif" | "nonaktif"
  "catatan": "..."
}
```

### Data pendukung buat form
| Field | Ambil dari |
|---|---|
| `kategori` | `GET /api/categories` → pakai `kode`-nya |
| `nama_alat_kemampuan` | `GET /api/categories/{kode}` → daftar jenis alat di kategori itu |
| `pelanggan_id` | **lihat di bawah** |

### ⚠️ Catatan penting

1. **Pemilihan pelanggan buat teknisi.**
   `GET /api/customers` itu **admin-only**, padahal `POST /equipments` boleh teknisi. Jadi kalau dropdown pelanggan pakai endpoint itu, form "Tambah Alat" bakal **gagal di akun teknisi**.
   **Pakai ini buat dropdown pelanggan** (kebuka buat semua role, mendukung `?search=`):
   ```
   GET /api/arsip/perusahaan?search=<nama>
   ```
   Balikannya `data[].id` & `data[].nama` — `id`-nya itu yang dipakai sebagai `pelanggan_id`.

2. **`toleransi` sebaiknya diisi.** Alat tanpa `toleransi` **nggak bisa dikalibrasi** — submit kalibrasi bakal ditolak `422` ("Alat ini belum punya nilai toleransi"), karena PASS/FAIL nggak bisa diputuskan. Idealnya jadi field wajib di UI walaupun backend masih ngebolehin kosong.

3. **`status` nggak boleh `overdue`.** Cuma `aktif`/`nonaktif`. `overdue` dihitung otomatis dari `tanggal_jatuh_tempo` dan cuma muncul di **respons** (`GET /equipments` → field `status`).

4. **Error validasi** balik `422` dengan `errors` per field, pesannya sudah berbahasa Indonesia & siap ditampilin apa adanya. Contoh: *"Nomor seri sudah dipakai alat lain."*

---

## D. Saran susunan dashboard (silakan diubah)

Prinsipnya: yang **butuh tindakan** ditaruh paling atas, yang cuma informasi di bawah.

**Baris 1 — perlu tindakan (paling menonjol):**
- `alat_overdue` → **merah**, tap → daftar alat overdue (`GET /api/equipments?status=overdue`)
- `menunggu_approval` → **oranye** (buat admin: antrean approve; buat teknisi: kerjaan yang nunggu diperiksa)

**Baris 2 — ringkasan:**
- `total_alat`
- `total_sertifikat`
- `sertifikat_bulan_ini` (bisa jadi sub-teks kecil di kartu total sertifikat)

**Baris 3 — grafik:** `grafik_pekerjaan` (batang, masuk vs selesai, 6 bulan).

**Aksi cepat:** tombol **Kalibrasi** (sudah termasuk fungsi tombol yang dihapus di poin 1) + **Tambah Alat**.

Catatan UX: angka `0` tetap ditampilin (jangan disembunyikan) — kalau kartunya ilang-timbul, layoutnya loncat-loncat dan user bingung.

---

## E. Endpoint lain yang mungkin kepakai

| Kebutuhan | Endpoint |
|---|---|
| Daftar alat (+filter) | `GET /api/equipments?search=&category=&status=` (`status`: `aktif`/`nonaktif`/`overdue`) |
| Daftar kalibrasi | `GET /api/calibrations?status=&mine=true` |
| Daftar sertifikat | `GET /api/certificates?status=` |
| Unduh PDF sertifikat | `GET /api/certificates/{id}/download` |
| Arsip folder | `GET /api/arsip/perusahaan` → `/arsip/perusahaan/{customer}` → `/arsip/alat/{equipment}` |

Semua daftar berpaginasi 15/halaman (`?page=`), formatnya standar Laravel (`data`, `links`, `meta`).

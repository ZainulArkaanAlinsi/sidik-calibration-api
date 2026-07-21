# Handoff Frontend — Kalibrasi pH (100%)

Dokumen kontrak buat tim frontend/mobile. Semua di bawah **sudah jalan di backend & ada test**-nya (reproduksi sertifikat asli `012-CAL-524`). Base URL API: `/api`. Semua butuh header `Authorization: Bearer <token>` kecuali disebut publik.

> Ringkas: pH sekarang lengkap — input 2 halaman, foto/OCR ATAU manual, **auto-hitung penuh** (budget ketidakpastian + U95% lingkungan + before/after), sampai cetak sertifikat. Backend yang ngitung; frontend cukup kirim angka mentah.

---

## 1. Alur & pembagian halaman

Satu sesi kalibrasi = **satu submit** ke `POST /api/calibrations` (bisa disimpan draft dulu, lanjut pakai `PUT`). Pemetaan ke worksheet:

- **Halaman 1** — Identitas Alat, Identitas Customer, Kondisi Lingkungan, Pengerjaan.
- **Halaman 2** — Data Hasil Kalibrasi (Before + After Adjustment).
- Tombol **Lanjutkan** di UI = pindah halaman; datanya tetap dikumpulin lalu dikirim sekali di akhir (atau simpan `status: "draft"` di Halaman 1, `PUT` lengkap di Halaman 2).

Foto/OCR: upload foto worksheet ke `POST /api/calibrations/photos` → dapat `photo_path`; OCR jalan **di device**, hasil bacanya diisi ke field (boleh dicampur manual). Detail OCR sama seperti sebelum (lihat §6).

---

## 2. Input pH — `POST /api/calibrations` (role: admin/teknisi)

Contoh payload LENGKAP (persis yang dites & reproduksi sertifikat 012-CAL-524):

```jsonc
{
  "equipment_id": 6,               // Identitas Alat (pilih dari GET /equipments)
  "standard_id": 12,               // standar acuan default sesi (buffer pH 7)
  "tanggal_kalibrasi": "2024-05-26T00:00:00Z",
  "tanggal_terima": "2024-05-26T00:00:00Z",
  "lokasi": "lab",                 // "lab" | "onsite"
  "input_method": "manual",        // "manual" | "ocr"
  "client_request_id": "uuid-v4",  // opsional, anti-dobel kalau sinyal putus

  // ---- KONDISI LINGKUNGAN (Halaman 1) ----
  // Kirim Awal & Akhir; server hitung sendiri rata-rata + U95%.
  "suhu_ruang_awal": 21.3,
  "suhu_ruang_akhir": 21.5,
  "kelembaban_awal": 53,
  "kelembaban_akhir": 56,
  "suhu_ruang_koreksi": -0.43,     // koreksi dari sertifikat thermohygro (dibaca dari worksheet)
  "kelembaban_koreksi": -2.55,
  "suhu_ruang_u_std": 1.7,         // U95% sertifikat thermohygro (suhu) → dipakai hitung U95% lingkungan
  "kelembaban_u_std": 4.8,         // U95% sertifikat thermohygro (kelembaban)
  "thermohygro": "TH-3",           // label alat pemantau ruangan

  // ---- DATA HASIL KALIBRASI (Halaman 2) — banyak titik ----
  "measurements": [
    {
      "titik_ukur": 4.009244572,   // nilai standar (buffer) terkoreksi suhu — sesudah adjustment
      "satuan": "pH",
      "standard_id": 10,           // buffer khusus titik ini (pH 4). Kosongin kalau sama dgn standard_id sesi
      // Sesudah adjustment (yang DISERTIFIKASI):
      "pembacaan": [4.00, 4.00, 4.00, 4.00, 4.00],   // min 3 pengulangan
      "suhu":      [22.2, 22.2, 22.1, 22.2, 22.2],   // suhu larutan tiap baca (pasangan pH/°C), opsional & sejajar index
      // Sebelum adjustment (as-found, dokumentasi):
      "titik_ukur_sebelum": 4.0092252,               // nilai standar sebelum (bisa beda tipis)
      "pembacaan_sebelum": [4.04, 4.04, 4.04, 5.00, 4.04],
      "suhu_sebelum":      [22.2, 22.2, 22.2, 22.2, 22.2],
      "ocr": []                    // opsional, lihat §6
    }
    // ... titik pH 7 & pH 10 (struktur sama)
  ],

  "status": "menunggu_approval"    // atau "draft" (lanjut nanti)
}
```

### Aturan field
- `measurements[].pembacaan` wajib, **min 3** angka. `suhu`, `pembacaan_sebelum`, `suhu_sebelum` opsional; kalau ada, `suhu` sejajar index dengan `pembacaan`.
- Kondisi lingkungan **semua opsional** — alat non-pH cukup kirim `suhu_ruang`/`kelembaban` (satu angka) seperti dulu.
- `kelembaban_*` divalidasi 0–100.
- Alternatif U95% lingkungan: kalau nggak mau server hitung, kirim `suhu_ruang_u95` / `kelembaban_u95` langsung (gantiin `*_u_std`).

### Yang DIHITUNG server otomatis (frontend TIDAK usah hitung)
| Hasil | Rumus (grounded ke workbook PT Sidik) |
|---|---|
| Rata-rata suhu/kelembaban | (awal + akhir) / 2 |
| U95% lingkungan | `2·√((U_TH/2)² + (\|awal−akhir\|/2)²)` |
| Rata-rata, error, koreksi, STDEV per titik | dari `pembacaan` |
| Budget ketidakpastian penuh + `k` (t-student) | 5 komponen → Welch-Satterthwaite |
| **U95% dilaporkan** | `max(U_hitung, CMC lab)` |
| PASS/FAIL | guarded acceptance ILAC-G8: `\|error\| + U ≤ toleransi` |
| Ringkasan Before Adjustment | dari `pembacaan_sebelum` |

Prasyarat data master (sekali setup, biasanya admin): alat harus `nama_alat_kemampuan = "pH Meter"` + kategori `instrumen-analitik`, dan ada `CalibrationCapability` pH 4/7/10 (CMC + konstanta budget) — sudah di-seed.

---

## 3. Response detail — `GET /api/calibrations/{id}`

Field baru (superset; yang lama tetap ada):

```jsonc
{
  "data": {
    "id": 3, "nomor_sesi": "...", "status": "disetujui", "keputusan": "PASS",
    "suhu_ruang": 21.4, "kelembaban": 54.5,       // rata-rata (kompat lama)

    "kondisi_lingkungan": {
      "suhu": { "awal": 21.3, "akhir": 21.5, "rata_rata": 21.4,
                "koreksi": -0.43, "nilai_terkoreksi": 20.97, "u95": 1.7117, "satuan": "°C" },
      "kelembaban": { "awal": 53, "akhir": 56, "rata_rata": 54.5,
                "koreksi": -2.55, "nilai_terkoreksi": 51.95, "u95": 5.6604, "satuan": "%RH" },
      "thermohygro": "TH-3"
    },

    "hasil": { "rata_rata": ..., "error": ..., "ketidakpastian_diperluas": ..., "keputusan": "PASS" },

    // Sesudah adjustment (disertifikasi) — per titik:
    "titik": [
      { "titik_ke": 1, "titik_ukur": 4.0092, "rata_rata": 4.0, "error": -0.0092,
        "koreksi": 0.0092, "standar_deviasi": 0, "ketidakpastian_diperluas": 0.023432,
        "faktor_cakupan_k": 1.968535, "keputusan": "PASS", "metode": "SIDIK-IK-CAL-0506",
        "type_b_components": [ /* rincian 5 komponen budget + baris perbandingan CMC */ ] }
    ],

    // Sebelum adjustment (as-found) — ringkasan per titik:
    "titik_sebelum": [
      { "titik_ke": 1, "titik_ukur": 4.0092252, "rata_rata": 4.232,
        "koreksi": 0.2228, "standar_deviasi": 0.4293, "jumlah_pengulangan": 5 }
    ],

    // Pembacaan mentah (whenLoaded di detail): tiap baris bawa tahap + suhu.
    "pembacaan_mentah": [
      { "id": 1, "titik_ke": 1, "pembacaan_ke": 1, "tahap": "sesudah_adjustment",
        "pembacaan": 4.0, "suhu": 22.2, "input_source": "manual", "is_verified": true, ... }
    ],
    "perlu_verifikasi": false,
    "sertifikat": { "id": 3, "nomor": "012-CAL-524", "status": "terbit", "pdf_url": "..." }
  }
}
```

`tahap` = `"sebelum_adjustment"` | `"sesudah_adjustment"`. Untuk tabel Before/After di UI, kelompokkan `pembacaan_mentah` per `tahap` + `titik_ke`, atau pakai `titik` (after) & `titik_sebelum` (ringkasan before).

---

## 4. Approve → Sertifikat

Sama seperti sebelum: `POST /api/calibrations/{id}/approve` (admin) → job bikin PDF. PDF-nya sekarang **lebih lengkap** (section Kondisi Lingkungan + tabel Before/After + Hasil Kalibrasi). Ambil via `GET /api/certificates` & `GET /api/certificates/{id}/download`. Sesi ada pembacaan OCR belum diverifikasi nggak bisa di-approve (§6).

---

## 5. Arsip / File Manager (folder perusahaan → alat → berkas)

- `GET /api/arsip/perusahaan` — daftar folder perusahaan (`?search=`).
- `GET /api/arsip/perusahaan/{customer}` — folder alat di dalamnya (+ breadcrumb `folder`).
- `GET /api/arsip/alat/{equipment}` — berkas (sesi + sertifikat) di dalamnya (+ breadcrumb).

Sertifikat otomatis masuk folder perusahaan yang benar (lewat `equipment.customer_id`). Detail bentuk response lihat commit fitur Arsip.

---

## 6. Foto/OCR & verifikasi (recap, tidak berubah)

1. `POST /api/calibrations/photos` (multipart `photo`, ≤8MB) → `{ "photo_path": "measurements/xxx.jpg" }`.
2. OCR jalan di device; hasil baca diisi ke `pembacaan`. Metadata OCR opsional per titik, sejajar index:
   `measurements[].ocr[] = { photo_path, confidence (0..1), raw_text }`.
3. Angka via OCR mulai `is_verified=false`; konfirmasi lewat `POST /api/calibrations/{id}/measurements/verify` (`{ "measurement_ids": [...] }` opsional). Wajib sebelum approve.

---

## 7. Catatan / keputusan
- **Backend nggak menjalankan OCR** — hanya simpan foto + hasil baca + wajib verifikasi manusia. OCR = tanggung jawab device.
- Angka `k` disimpan per titik presisi penuh (mis. 1.968535). Beda ~3e-5 vs `TINV` Excel itu artefak Excel; U pada presisi sertifikat (6 desimal) identik.
- pH 10 contoh: U hitung 0.030327 < CMC 0.031 → yang dilaporkan **0.031** (lab nggak boleh klaim lebih teliti dari akreditasi).
- Alat non-pH: semua field baru opsional; perilaku lama tidak berubah.
```

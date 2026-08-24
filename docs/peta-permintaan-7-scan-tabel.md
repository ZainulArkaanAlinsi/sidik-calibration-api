# Permintaan 7 (Scan Tabel) — peta ke yang SUDAH ADA

> **Kenapa berkas ini ada sebelum satu baris kode pun ditulis.**
> §12 spesifikasi Scan Tabel mengikat: *"Tunjukkan rencana file yang akan dibuat/diubah lebih
> dulu, tunggu saya setujui, baru eksekusi."* Berkas ini rencananya. Belum ada yang dikerjakan.

Perkiraan pemilik proyek untuk permintaan ini **~14,5 hari kerja**. Angka itu masuk akal untuk
membangun dari nol. Tapi sebagian besarnya **sudah terbangun**, dengan nama lain, dan sudah
jalan di produksi sejak 13 Agustus 2026.

Jadi pertanyaan yang benar bukan "bagaimana membangunnya", tapi **"apa yang masih kurang"** —
dan jawabannya jauh lebih kecil dari 14,5 hari.

---

## Bahaya yang paling nyata: dua tabel staging yang saling menyaingi

Spesifikasi menyebut tabel `ocr_scans` dan `ocr_scan_cells`. Yang sudah ada namanya
`worksheet_scans` dan `worksheet_scan_cells`.

Membuat tabel baru sesuai nama di spesifikasi berarti **dua tabel staging hidup berdampingan
selamanya**: dua tempat menyimpan hal yang sama, dua jalur koreksi, dua sumber kebenaran untuk
pertanyaan "sel ini sudah diverifikasi belum". Yang seperti itu tidak pernah dibereskan
belakangan — dia cuma bertambah tua.

**Usulan: pakai tabel yang sudah ada, dan anggap perbedaan namanya kosmetik.**

---

## Yang SUDAH ada

### Basis data

| Spesifikasi | Yang ada sekarang | Catatan |
|---|---|---|
| `ocr_scans` | `worksheet_scans` | 25 kolom, termasuk `organization_id` (batas antar-lab), versi template/pipeline/aturan, ringkasan lampu, `kualitas`/`geometri`/`perangkat` JSON, path citra asli & hasil warp |
| `ocr_scan_cells` | `worksheet_scan_cells` | 24 kolom, termasuk `confidence_ocr` & `confidence_akhir` terpisah, `teks_mentah` vs `teks_normal`, `nilai_final`, jejak koreksi (`dikoreksi_oleh` + `dikoreksi_pada`), `crop_path`, dan `unique(scan, kunci)` |

### Backend

- `app/Services/Ocr/PemrosesScanLembarKerja.php` — pipeline lengkap: periksa template →
  periksa kualitas → periksa geometri → periksa jangkar → petakan sel → periksa antar-repeat →
  ringkas per tabel. Enam status keluaran: `ok`, `perlu_review`, `ditolak_kualitas`,
  `template_tidak_dikenali`, `geometri_meragukan`, `mapping_gagal`.
- `app/Services/Ocr/ValidasiSel.php` — lampu per sel: **hijau / kuning / merah / kosong**.
- `app/Services/WorksheetVisionExtractor.php` — jalur AI cloud (alternatif dari OCR lokal).
- `config/ocr.php` — retensi citra ditegakkan `php artisan ocr:bersihkan-citra`, terjadwal
  harian 02:30. Default `umur_citra_hari=90`, `umur_crop_hari=365`.
- Endpoint: `POST /worksheet-scans`, `GET /worksheet-scans/{id}`,
  `GET /worksheet-scans/{id}/sel/{kunci}/crop`, `POST /worksheet-scans/{id}/koreksi`, plus
  konfirmasi pembacaan sebagai syarat sebelum approve.

### Mobile

- `lib/services/pindai_lembar.dart` — deteksi marker + warp perspektif di HP (477 baris).
- `lib/services/jalankan_pindai.dart`, `lib/services/worksheet_scan_service.dart`
- `lib/screens/calibration/pindai_review_screen.dart` — layar review per sel.
- `lib/models/worksheet_scan.dart`, `lib/providers/worksheet_scan_provider.dart`
- `google_mlkit_text_recognition: ^0.15.0` — OCR di perangkat, model tidak keluar HP.

---

## Yang BELUM ada, dan ini seluruh sisanya

### 1. UI-nya sedang sengaja dimatikan

Permintaan **3** meminta UI pindai dicabut *untuk sekarang*. Yang dilakukan: disembunyikan di
balik saklar, bukan dihapus — `AppConfig.pindaiLembarAktif` =
`bool.fromEnvironment('PINDAI_LEMBAR')`, default **mati**.

Jadi permintaan 3 dan permintaan 7 **saling bertentangan langsung**. Menyalakannya kembali:
`--dart-define=PINDAI_LEMBAR=true`, nol baris kode.

**Ini keputusan yang bukan hak saya:** permintaan 3 masih berlaku sampai pemilik proyek
bilang sebaliknya.

### 2. Yang belum pernah diperiksa

Perlu diadu ke spesifikasi §-per-§ sebelum bisa disebut selesai:

- Apakah daftar template yang didukung sudah memuat lembar yang benar-benar dipakai? Lembar
  Enclosure bentuknya GRID (9 termokopel × 5 pembacaan), dan `pindai_foto.didukung` untuk TIDS
  sudah **`false`** karena kertasnya bukan "titik × Repeat".
- Apakah ambang lampu kuning/merah cocok dengan yang diminta spesifikasi.
- Apakah alur "sel merah wajib dikoreksi manusia sebelum approve" sudah ditegakkan di backend,
  bukan cuma ditampilkan di layar.

### 3. Yang tidak akan saya kerjakan tanpa persetujuan

Membuat `ocr_scans` / `ocr_scan_cells` baru. Alasannya di bagian atas berkas ini.

---

## Yang saya minta diputuskan

| # | Pertanyaan | Kenapa menahan |
|---|---|---|
| S1 | Permintaan 3 (cabut UI pindai) masih berlaku, atau sudah boleh dinyalakan lagi? | Dua permintaan ini bertentangan langsung. Selama 3 berlaku, permintaan 7 tidak punya pintu masuk di layar |
| S2 | Nama tabel: pakai `worksheet_scans` yang sudah ada, atau tetap ingin `ocr_scans`? | Kalau tetap `ocr_scans`, akan ada dua tabel staging selamanya — dan saya perlu dengar itu memang yang diinginkan |
| S3 | Lembar mana yang wajib bisa dipindai? | Enclosure (grid) dan TIDS (dua tabel interval waktu) bentuknya jauh berbeda dari "titik × Repeat"; keduanya butuh kerja tersendiri, bukan template tambahan |

---

## Perkiraan setelah dipetakan

Bukan ~14,5 hari. Kalau S1 dijawab "nyalakan lagi" dan S2 dijawab "pakai yang sudah ada",
sisanya tinggal **memverifikasi yang sudah jalan dan menambal yang kurang** — bukan membangun.

Angka pastinya baru bisa saya berikan sesudah pemeriksaan §-per-§ di bagian *"Yang belum pernah
diperiksa"*, dan pemeriksaan itu sendiri tidak mengubah satu baris kode pun.

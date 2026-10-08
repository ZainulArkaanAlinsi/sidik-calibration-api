# 00 — Change Request & Impact Analysis

| | |
|---|---|
| Nomor | CR-48 (lanjutan nomor § di `docs/permintaan-user-7.md`; PRD-nya nanti jadi §48 di sana) |
| Judul | Studio Data Acuan — penyunting data olah data ber-versi di panel desktop |
| Pengaju | Pemilik proyek (Zainul Arkaan), 8 Okt 2026 |
| Kelas | **Besar** — menyentuh data, API, izin, dan perhitungan. Butuh dokumen 00–08 lengkap |
| Hubungan | Memperluas F1 "Pembaruan Data Acuan dari Excel" + Gelombang 9 di `perintah-perbaikan-30sep` (Project doc). F1 = unggah workbook; CR-48 = unggah **dan** sunting langsung di grid, untuk semua alat |
| Dasar aturan | `AGENTS.md §Olah data — aturan keras` butir 1–5; `docs/pelanggan/09-Adendum-Olah-Data-Peran.md` §2.1, §3; `docs/arsitektur-desktop-database.md` Keputusan 1, 4, 5 |

---

## 1. Apa yang diminta

Sebuah UI di laptop/PC yang berfungsi seperti workbook Excel kedua:

1. Tiap alat punya tampilannya sendiri, mengikuti sheet workbook masternya — bukan satu form
   seragam. (Micrometer punya `Standar_GB`; Timbangan punya `STANDAR_AT` + lima sheet `Drift AT`;
   suhu punya `Interpolasi`, `SENSOR PT100`, `TERMOCOUPLE TYPE K/N`, dst. — Lampiran A.)
2. Data di dalamnya bisa diubah: nilai standar hasil rekalibrasi, koreksi, U standar, drift,
   pita CMC, tabel MPE, konstanta metode, dan bentuk lembar kerja.
3. Begitu perubahan disahkan, **semua HP & perangkat** yang memakai sistem langsung menghitung
   dan menampilkan dengan data baru.
4. Benar-benar dipakai, bukan gimik: tidak boleh salah satu angka pun.

## 2. Kenapa

Sumber nyata perubahan di lab: standar direkalibrasi (sertifikat baru, koreksi baru, tanggal baru),
pita CMC diperbarui di lampiran akreditasi, master workbook direvisi, lembar kerja kertas direvisi
(`Rev.N`). Hari ini satu perubahan seperti itu menempuh rantai:

```
workbook .xlsm di mesin pemilik → docs/skrip/gen-tabel-standar-*.py
→ database/data/tabel-*.json (commit) → TabelStandar*.php membaca JSON
→ test master → push ke main → CI → deploy Render (≤25 menit verifikasi)
```

Rantai ini akurat tapi hanya bisa dijalankan developer, dan tidak meninggalkan jejak "siapa di lab
yang memutuskan nilai ini, atas dasar sertifikat nomor berapa" di dalam sistem — jejaknya ada di
commit message. Untuk ISO/IEC 17025 7.11 (kendali data & manajemen informasi) jejak itu harus
terbaca oleh orang lab, bukan cuma developer.

## 3. Yang ditemukan di repo sebelum merancang (terverifikasi 8 Okt 2026)

Diperiksa pada `sidik-calibration-api@abdc80a` dan `sidik-calibration-mobile@91a5279`.

| Temuan | Bukti | Arti untuk CR ini |
|---|---|---|
| Data acuan lapis 1 sudah dipisah dari kode untuk 19 keluarga alat, tapi sebagai **berkas JSON di repo**, bukan data | `database/data/tabel-standar-*.json` (16), `tabel-kalibrator-*.json` (2), `tabel-master-suhu-3alat.json`, `thermohygro-lab.json`; dibaca kelas `App\Services\Calibration\TabelStandar*` / `TabelKalibrator*` | Pondasi terbaik: isi JSON hari ini = versi 1 tiap paket. Kelas `TabelStandar*` cukup diganti sumber bacanya |
| Profil analitik (pH, Conductivity, Chlorine, DO, Turbidimeter, Refractometer, Spektro, Viscometer, Gas Detector, Autoclave) menyimpan nilai acuannya sebagai **konstanta di dalam PHP**; Hydrometer punya kelas `TabelStandarHydrometer` tapi tanpa berkas JSON | `grep TabelStandar` tidak menemukan apa pun di sepuluh profil itu; `database/data/` tidak memuat tabel hydrometer | Perlu ekstraksi dulu (refactor tanpa ubah perilaku) sebelum bisa disunting. Fase 4 |
| CMC dari lampiran LK-285-IDN sudah jadi data | `database/data/kemampuan-kalibrasi.json` → seeder → tabel `calibration_capabilities` (+ resource Filament) | Pita CMC per alat sebagian sudah di DB; sebagian lain (mis. pita CMC Micrometer) masih di JSON tabel alat |
| Stempel versi rumus sudah ada | migrasi `2026_07_27_110000_create_formulas_table.php`; `uncertainty_calculations.formula_version_id`; `App\Services\RumusKalibrasi::versiUntukSesi()` mencari versi **per tanggal kalibrasi** | Data acuan butuh stempel kedua yang setara; pola pencariannya ditiru |
| Evaluator ekspresi rumus **belum ada** dan sengaja ditolak | `FormulaVersion::SUMBER_DATABASE` → 422; `BACA-DULU-BACKEND.md` baris 1081 | Konsisten dengan keputusan: lapis 2 tidak ikut CR ini |
| Bentuk lembar kerja sudah **dikirim server**, HP merender dinamis | `GET /api/calibrations/lembar-kerja` → `CalibrationProfile::bentukLembarKerja()`; HP: `lib/widgets/dinamis/form_dinamis.dart` | Perubahan lapis 3 di server otomatis sampai ke HP tanpa rilis APK — syaratnya bentuk baru tetap dalam kosakata yang dikenal `form_dinamis` |
| Panel desktop sudah ada di aplikasi Flutter yang sama | `lib/providers/platform_provider.dart` (`pakaiPanelDesktopProvider`: Windows/macOS), `lib/screens/shell/desktop_shell.dart` dengan menu ber-izin, layar `rumus_list_screen.dart` | Studio = menu/seksi baru di `DesktopShell`, bukan aplikasi baru |
| Sinyal antar-perangkat ada, tapi **realtime mati di produksi** | `PerubahanDataOrganisasi::siarkanAman()`; `docs/realtime-sync.md`: `BROADCAST_CONNECTION=log` (plan Render free); HP menarik ulang tiap 3 menit (§40.2) | "Langsung terasa di HP" = paling lambat 3 menit / saat aplikasi dibuka lagi, sampai plan berbayar. Ini harus jujur di UI |
| Workbook `.xlsm` asli **tidak ada di repo** | `.gitignore` baris 44–253; yang ada CSV ekspor nilai (473 CSV di 46 folder) | CSV kehilangan rumus. Peta sel & sidik jari rumus harus dibuat dari `.xlsm` di mesin pemilik |
| Tata letak CSV antar-varian tidak seragam | Cek silang `Standar_GB.csv` Micrometer: blok balok ukur ada di kolom 1 & 9 untuk 0-25/75-100, tapi kolom 1, **2**, & 9 di 25-50/50-75 (satu blok tergeser satu kolom) | Importer tidak boleh membaca alamat kolom tetap; harus pakai peta sel per versi workbook + validasi isi |
| Nilai balok ukur Micrometer di JSON cocok dengan keempat workbook | 32/32 nominal & nilai terkoreksi identik di keempat CSV `Standar_GB` vs `tabel-standar-micrometer.json` (skrip cek, 8 Okt) | Versi 1 paket Micrometer bisa dibuktikan setara master sebelum apa pun disentuh |

## 4. Dampak

### 4.1 Database (additive saja)

| Objek | Jenis | Catatan |
|---|---|---|
| `paket_acuan` | tabel baru | satu baris per (organisasi, kode profil) |
| `paket_acuan_versi` | tabel baru | isi JSON kanonik + sha256, status, rentang berlaku, pengaju/pengesah |
| `paket_acuan_suntingan` | tabel baru | jejak per sel di draf: lama → baru, alasan, rujukan |
| `simulasi_acuan` + `simulasi_acuan_sesi` | tabel baru | hasil simulasi dampak |
| `unggahan_workbook` | tabel baru | berkas `.xlsm` asli + sha256 + peta sel yang dipakai (F1) |
| `uncertainty_calculations.paket_acuan_versi_id` | kolom baru, nullable FK `nullOnDelete` | stempel kedua, setara `formula_version_id` |
| `calibration_sessions.bentuk_versi_id` | kolom baru, nullable | versi bentuk lembar yang dipakai teknisi saat mengisi |

Kolom baru itu pilihan terakhir (AGENTS.md §Alur Kerja 4). Dua kolom ini tidak bisa dihindari:
tanpa stempel, versi data acuan yang menghasilkan sebuah sertifikat tidak bisa dibuktikan — dan
itu justru alasan fitur ini boleh ada. Tidak ada kolom yang diubah atau dihapus.

### 4.2 API

- **Baru**: grup `/api/data-acuan/*` (03 §3). Semua di `routes/api.php`, bukan `api_pelanggan.php`.
- **Diubah, kompatibel**: `GET /api/calibrations/lembar-kerja` menambah blok `versi_acuan`
  (`{paket, nomor_versi, sha256, bentuk_versi}`) dan header `ETag`. Field lama tidak berubah.
- **Diubah, kompatibel**: respons perhitungan (`/calibrations/{id}/perhitungan`, `/validasi`)
  menambah `paket_acuan_versi` di jejak. Snapshot sertifikat menyimpan nomor versi + sha256.
- **Tidak ada breaking change.** APK lama mengabaikan field baru; perilakunya tetap.

### 4.3 Peran & izin

Izin baru di `App\Services\MatriksIzin::PETA`: `data-acuan.lihat`, `data-acuan.sunting`,
`data-acuan.impor`, `data-acuan.ajukan`, `data-acuan.sahkan`, `bentuk-lembar.sunting`.
`super_admin` perlu grup rute tulis saudara seperti `sahkan` sertifikat (pola §40), **tanpa**
melonggarkan `lolosBacaSuperAdmin`. Pemisahan wewenang diperluas: penyunting/pengaju sebuah versi
tidak boleh mengesahkannya (03 ADR-05). Rincian: 02 §4.

### 4.4 Perhitungan

- Kelas `TabelStandar*` berhenti membaca berkas JSON dan membaca **versi paket yang berlaku pada
  tanggal kalibrasi sesi**. Rumus (lapis 2) **tidak berubah satu baris pun.**
- `CalibrationValidator` dan `HitungUlangSesi` wajib memakai versi yang sama dengan yang
  distempel, bukan versi aktif hari ini (aturan "sertifikat 5 tahun lalu tetap sama angkanya").
- Penahan terbit baru: sesi yang dihitung dengan versi yang statusnya kemudian dicabut
  (`ditarik`) ditahan sampai diputuskan.

### 4.5 Data lama

- Produksi 2 Okt 2026: 2 sesi nyata, **nol** `uncertainty_calculations` (AGENTS.md §Olah data 2).
  Jadi belum ada hasil hitung yang perlu distempel mundur. Kalau saat rilis sudah ada, baris
  lama distempel **versi 1** hanya bila versi 1 dibuktikan identik byte dengan JSON yang dipakai
  saat itu (sha256 kanonik); kalau tidak bisa dibuktikan, dibiarkan `null` dan dilaporkan — menebak
  lebih buruk dari mengaku tidak tahu (pola migrasi `formula_version_id`).
- Berkas JSON di `database/data/` tetap ada sebagai fixture test dan sumber versi 1, sampai rollout
  tahap 4 selesai (07 §3).

### 4.6 Aplikasi versi lama

| Klien | Efek | Tindakan |
|---|---|---|
| APK teknisi lama | Tetap jalan. Bentuk lembar tetap dari server; angka tetap dihitung server | Tidak ada |
| APK lama + perubahan **lapis 3** yang memakai jenis field baru | Field tak dikenal bisa tidak tampil | Editor bentuk hanya menawarkan jenis field yang sudah dikenal `form_dinamis`; server menolak bentuk yang memakai kosakata di atas `min_versi_klien` |
| Draf yang sedang dibuka saat versi berganti | Angka di pratinjau berubah saat dihitung ulang | Banner "data acuan berubah sejak draf dibuat" + versi lama/baru (04 §6) |
| Desktop lama tanpa menu Studio | Tidak bisa menyunting; tidak rusak | Rilis desktop bersama tahap 2 |
| Aplikasi pelanggan | Tidak tersentuh | — |

## 5. Pilot: Micrometer, bukan pH — dan kenapa ini perlu diputuskan (K-48-02)

Rencana Gelombang 9 (30 Sep) memilih pH. Untuk Studio, Micrometer lebih aman sebagai alat pertama:

| | Micrometer | pH |
|---|---|---|
| Data acuan sudah terpisah dari kode | Ya — `tabel-standar-micrometer.json` | Tidak — konstanta di `PhMeterProfile` |
| Generator dari workbook | Ada, mengadu 4 workbook | Tidak ada |
| Fixture sesi master | `sesi-master-micrometer.json` + `MicrometerSesiTest`, `MicrometerSertifikatTest`, `AuditMicrometerCmcTest` | `kalibrasi-ph-meter.json` (satu record) + `PhMeterMasterTest` |
| Kertas resmi | 4 PDF `SIDIK-FM-CAL-0522.A–D_Rev.1` | 1 PDF |
| Pertanyaan lab terbuka yang menyentuh acuan | sedikit (`keputusan-lab-micrometer.md`) | ada dua master (`pertanyaan-lab-ph-dua-master.md`) |
| Bukti setara hari ini | 32/32 balok ukur cocok di 4 CSV (8 Okt) | belum diperiksa di CR ini |

pH tetap layak sebagai alat **kedua** — justru karena membuktikan langkah "ekstrak konstanta PHP
ke paket" yang dibutuhkan sepuluh profil analitik.

## 6. Risiko utama

| Risiko | Level | Penahan |
|---|---|---|
| Salah ketik satu angka di grid lolos ke sertifikat | 🔴 | validasi tipe/rentang per kolom, diff wajib dibaca, simulasi wajib, pengesah orang kedua, uji pembanding |
| Versi berlaku mundur mengubah sertifikat terbit | 🔴 | sertifikat terbit tidak pernah dihitung ulang; `berlaku_mulai` tidak boleh sebelum hari pengesahan kecuali dengan alasan + penanda ketidaksesuaian |
| Editor bentuk menghapus input yang dibutuhkan rumus | 🔴 | kontrak input per profil (03 ADR-06); server menolak |
| Ekspektasi "langsung ke HP" tidak terpenuhi karena realtime mati | 🟠 | UI menyebut "berlaku di perangkat lain ≤3 menit"; tarik ulang saat buka lembar |
| Peta sel workbook bergeser antar-revisi (terbukti di CSV Micrometer) | 🟠 | sidik jari sel + validasi isi; mismatch = `struktur_berubah` (F1-03) |
| Orang mengira Studio bisa mengubah rumus | 🟠 | tab "Rumus" baca-saja dengan label jelas + jalur usulan |
| Dua admin menyunting draf yang sama | 🟡 | optimistic lock `lock_versi` + kunci draf per orang |

## 7. Dokumen yang perlu diperbarui saat CR ini disetujui

`AGENTS.md` (§Olah data: rujuk Studio; daftar izin baru), `docs/kontrak-api.md` (§ baru),
`docs/BACA-DULU-BACKEND.md` (status), `docs/permintaan-user-7.md` (§48 + Gelombang),
`docs/arsitektur-desktop-database.md` (tandai Keputusan 5 "sebagian dijawab oleh CR-48"),
mobile `docs/` (layar baru), `docs/Rekap-Data-Kemampuan-Kalibrasi.md` (CMC kini ber-versi).

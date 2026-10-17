# 05 — Task Breakdown Studio Data Acuan

Owner: **Z** = Zainul (pemilik/BE), **M** = rekan full-stack mobile/desktop, **CC** = Claude Code
di bawah arahan Z, **Lab** = Technical Manager / Master Data, **SA** = pemegang akun super admin.
Estimasi dalam hari kerja orang. Semua tugas backend: DoD umum = test SQLite **dan** MySQL hijau,
`pint` hanya pada berkas yang disentuh, tidak ada push tanpa perintah (AGENTS.md §Git).

## Gelombang 0 — Keputusan & bahan (blokir semua)

| ID | Tugas | DoD | Est. | Dep. | Owner | Rujukan |
|---|---|---|---|---|---|---|
| T0.1 | Jawab K-48-01…07 | jawaban tertulis di `permintaan-user-7.md` §48 | 0,5 | — | Z, Lab | README |
| T0.2 | Serahkan 4 workbook Micrometer `.xlsm` asli + sha256 (mesin pemilik) | manifest seperti `manifest-workbook-tekanan-piston.json` | 0,25 | — | Z | 00 §3 |
| T0.3 | Lab menetapkan toleransi uji pembanding Micrometer per besaran, dengan dasar resolusi | tabel di 06 §3 terisi, ditandatangani Lab | 0,5 | — | Lab | FR-14 |
| T0.4 | Satu data rekalibrasi standar nyata (atau sertifikat GB terbaru) untuk uji end-to-end | berkas di tangan, nama pelanggan diredaksi | 0,25 | — | Lab | PRD §6 |

## Gelombang 1 — Fondasi server (tanpa UI)

| ID | Tugas | DoD | Est. | Dep. | Owner | Rujukan |
|---|---|---|---|---|---|---|
| T1.1 | Migrasi 5 tabel + 2 kolom stempel (additive, nama index pendek) | `migrate` + `migrate:rollback` bersih di SQLite & MySQL | 1 | T0.1 | CC | 03 §2 |
| T1.2 | Model + trait `Diaudit` + model suntingan append-only | test: update/delete suntingan ditolak | 0,5 | T1.1 | CC | NFR-05 |
| T1.3 | `JsonKanonik` + sha256 (kunci terurut, angka string) | test: sha256 stabil lintas SQLite/MySQL & urutan kunci | 0,5 | — | CC | NFR-03, ADR-08 |
| T1.4 | `skemaAcuan()` di `CalibrationProfile` (bawaan: null) + skema Micrometer | skema mencakup seluruh kunci `tabel-standar-micrometer.json` | 1 | T0.1 | CC | ADR-03 |
| T1.5 | Perintah `php artisan acuan:impor-awal {profil?}` → versi 1 `aktif` dari JSON, `berlaku_mulai` = tanggal sesi tertua / tanggal konfigurasi | idempoten; menolak bila sudah ada versi; laporan sha256 | 1 | T1.3 | CC | 07 §3 |
| T1.6 | `SumberAcuan` + cache per (versi, sha256); `TabelStandarMicrometer` membaca darinya | **seluruh test Micrometer hijau tanpa diubah**; `TabelAcuanSetaraJsonTest` | 1,5 | T1.5 | CC | ADR-04 |
| T1.7 | `PenentuVersiAcuan` (pola `RumusKalibrasi`) + stempel di semua jalur simpan hasil | test: sesi bertanggal sebelum v2 tetap v1 | 1 | T1.6 | CC | FR-20, BR-10 |
| T1.8 | `CalibrationValidator` & `HitungUlangSesi` memakai versi stempel | test: aktifkan v2 → hitung-ulang sesi lama tetap angka v1 | 1 | T1.7 | CC | §4.4 CR |
| T1.9 | Izin baru di `MatriksIzin::PETA` + `gen-nama-izin-mobile.php` | test peta izin; berkas Dart ter-generate | 0,5 | T1.1 | CC | 02 §4 |
| T1.10 | API baca: paket, versi, berlaku, banding, riwayat sel | test izin per peran; teknisi hanya aktif; org lain 404 | 1,5 | T1.6, T1.9 | CC | 03 §3 |
| T1.11 | `versi_acuan` + ETag di `lembar-kerja`; stempel di perhitungan & snapshot | test kontrak lama tidak berubah (field lama identik) | 1 | T1.7 | CC | FR-21 |

## Gelombang 2 — Alur versi + Studio pilot (Micrometer)

| ID | Tugas | DoD | Est. | Dep. | Owner | Rujukan |
|---|---|---|---|---|---|---|
| T2.1 | Draf, sunting sel (validasi skema, BR-04/07), tambah/hapus baris, lock | test per kode error | 2 | T1.10 | CC | FR-05…09 |
| T2.2 | `JobSimulasiAcuan` (sesi + fixture), angka cetak via aturan desimal profil | test: ubah satu balok → laporan menyebut titik & kolom tepat | 2 | T2.1 | CC | FR-12/13 |
| T2.3 | Ajukan / sahkan / tolak / kembalikan / tarik + `PemisahanWewenang` + grup rute SA | test transisi lengkap 02 §3; BR-08 | 1,5 | T2.2 | CC | FR-15…19 |
| T2.4 | Scheduler versi terjadwal + siaran `data_acuan` + pengingat standar | test waktu tiruan | 0,5 | T2.3 | CC | FR-22, FR-29 |
| T2.5 | Flutter: model, service (+mock), provider, seksi `DesktopShell` | widget test menu ber-izin | 1 | T1.10 | M | 03 §6 |
| T2.6 | Grid acuan (virtualisasi, keyboard, salin/tempel TSV, sorot ubahan, presisi penuh) | uji 2.000 baris; parser angka 20 kasus | 3 | T2.5 | M | S2, NFR-01 |
| T2.7 | S1, S2, S3, S5, S6, S7 + semua state 04 §5 | golden test terang/gelap; uji tanpa izin & offline | 3 | T2.6, T2.3 | M | 04 |
| T2.8 | S4 Simulasi | ringkasan + daftar + vektor | 1 | T2.2 | M | FR-12 |
| T2.9 | HP: kirim `If-None-Match`, banner draf terdampak, label versi | uji HP nyata | 1 | T1.11 | M | FR-21/23 |
| T2.10 | Uji end-to-end staging dengan data T0.4 | catatan uji + tangkapan layar di `docs/` | 1 | T2.7…T2.9 | Z, Lab, SA | PRD §6 |

## Gelombang 3 — Uji pembanding di UI, unggah workbook, bentuk lembar, alat berikutnya

| ID | Tugas | DoD | Est. | Dep. | Owner | Rujukan |
|---|---|---|---|---|---|---|
| T3.1 | Vektor uji dari workbook **dihitung ulang** (LibreOffice headless recalculation sebelum dibaca) untuk Micrometer | vektor ber-sha256 workbook; beda dengan nilai cache dilaporkan | 1,5 | T0.2 | CC | FR-14 |
| T3.2 | Peta sel Micrometer + pengurai workbook (PhpSpreadsheet **[dep baru, perlu persetujuan]**) di worker | test: workbook rumus diubah → `struktur_berubah` | 2,5 | T3.1 | CC | FR-10 |
| T3.3 | S10 Unggah Workbook | seret-lepas + laporan | 1 | T3.2 | M | S10 |
| T3.4 | `kontrakInput()` + `batasBentuk()` Micrometer; override bentuk; pratinjau | test: hapus field kontrak → 422 | 2 | T2.3 | CC | ADR-06 |
| T3.4a | Header `X-Versi-Klien` dari HP + catat versi terakhir per akun (server belum tahu versi APK pemanggil per 8 Okt) | test: header tercatat; tanpa header tetap 200 | 1 | — | CC, M | 07 §5 |
| T3.5 | S9 Editor Bentuk | pratinjau sama dengan render HP | 2 | T3.4 | M | S9 |
| T3.6 | S8 Peta Rumus + usulan rumus (Micrometer) | deklarasi rantai dari kode, bukan teks tangan | 1,5 | T2.7 | CC, M | FR-26/27 |
| T3.7 | Per alat berikutnya (Height Gauge, Dial Indicator, Jangka Sorong, Timbangan, …): skema + `SumberAcuan` + versi 1 + test setara | satu PR per alat; test master alat itu hijau tanpa diubah | 1–2 /alat | T1.6 | CC | Lampiran A |

## Gelombang 4 — Profil analitik (konstanta di PHP)

| ID | Tugas | DoD | Est. | Dep. | Owner | Rujukan |
|---|---|---|---|---|---|---|
| T4.1 | pH: ekstrak konstanta `PhMeterProfile` ke paket (refactor perilaku tetap) | `PhMeterMasterTest` hijau tanpa diubah | 2 | T1.6 | CC | 00 §5 |
| T4.2 | Sembilan profil analitik lain + Hydrometer, satu per PR | idem per alat | 1–2 /alat | T4.1 | CC | Lampiran A |

## Gelombang 5 — Bersyarat K-48-01 = ya

Tidak dirinci di sini. Butuh CR sendiri (mesin ekspresi + uji bayangan, ADR-07).

## Total kasar

G0 1,5 · G1 10,5 · G2 16 · G3 11,5 + per alat · G4 per alat. Fase 2 (pilot berjalan di
staging) = G1 + G2 ≈ 26,5 hari-orang sesudah G0 terjawab; dengan dua orang paralel (CC/Z di
server, M di desktop) kira-kira 3–4 minggu kalender.

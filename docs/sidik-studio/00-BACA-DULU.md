# Sidik Studio — baca dulu

Paket dokumen rancangan **Sidik Studio**, ditulis 9 Okt 2026 atas permintaan pemilik ("bikin
sedetail-detailnya"). Status: **rancangan, belum ada kode Studio di `main`.** Status kerja yang
boleh dipercaya tetap `docs/BACA-DULU-BACKEND.md`.

## 1. Ringkasan satu halaman

**Apa.** Sidik Studio adalah aplikasi Flutter **terpisah** (Windows + tablet Android) yang menjadi
pusat kendali database dan olah data PT Sidik. Ia menggantikan dua hal yang hari ini hanya bisa
dipegang developer: workbook Excel master (rumus) dan akses MySQL langsung (data). Studio tersambung
ke API yang sama dengan aplikasi lapangan; tidak ada database lokal.

**Tiga modul.**
1. **Kelola Data** — jelajah, saring, sunting, arsip, riwayat, ekspor untuk 56 tabel lewat registry
   daftar putih. Setiap perubahan tercatat (pelaku, waktu, lama → baru, alasan). Tidak ada hapus
   permanen. Tabel yang dijaga invarian (audit, sertifikat terbit, hasil hitung, versi) baca saja.
2. **Data Acuan** — lapis 1 (pita CMC, tabel koreksi, konstanta) berversi, dari paket CR-48: draf →
   simulasi → ajukan → sah orang kedua → berlaku menurut tanggal kalibrasi.
3. **Lembar Hitung** — lapis 2 (rumus) yang kini boleh disunting di Studio (K-49-02) dalam bentuk
   sheet + sel + rumus mirip Excel. Server (PhpSpreadsheet) satu-satunya yang menghitung. Versi
   pertama tiap alat wajib melewati **bayangan → paritas → aktif** terhadap profil PHP hari ini;
   versi berikutnya wajib simulasi ke semua sesi tersimpan dan disahkan `super_admin` orang kedua.

**Prinsip yang tidak bisa ditawar.** Server menghitung, Studio menyunting. Agregasi (u_c,
Welch–Satterthwaite, k), lantai CMC, dan PASS/FAIL boleh disunting per alat di Lembar Hitung
(K-49-07), tetapi setiap lembar lahir dengan blok agregasi bawaan setara `GumCalculator`, dan
`GumCalculator` tetap menghitung ulang setiap sesi sebagai **pengawas**: beda selalu muncul sebagai
PERINGATAN `agregasi_beda_dari_gum` di jejak sesi dan wajib bercatatan sumber. Nilai teknisi tidak
pernah dihapus. Kerusakan salin-tempel master (`#REF!`,
tautan luar `[n]`, rujukan meleset) **tidak ditiru**: dihitung benar dan selisihnya dicatat di jejak
sesi (`AGENTS.md` §Aturan yang Lahir dari Kesalahan Nyata, §Olah data butir 4).

**Paritas 100%** (permintaan pemilik): nilai antara identik bit atau relatif ≤ 1·10⁻¹², angka cetak
identik, keputusan identik, titik tertahan identik, kode jejak identik — pada semua sesi tersimpan,
vektor master, dan sesi seeder. Definisi lengkap `01-PRD.md` §5.

**Ukuran.** S0–S5 ≈ 178 hari-orang; S6 (41 profil sisanya) ≈ 184,5 hari-orang. Jalur kritis di mesin
rumus (S4). Rinci di `05-Task-Breakdown.md`.

## 2. Peta dokumen

| No | Berkas | Isi | Untuk |
|---|---|---|---|
| 0 | `00-BACA-DULU.md` | ringkasan, keputusan, urutan berlaku, status | semua |
| 1 | [`01-PRD.md`](01-PRD.md) | masalah, tujuan terukur, peran, user story, **definisi paritas**, lingkup S0–S6, risiko | pemilik, manajer teknis |
| 2 | [`02-SRS.md`](02-SRS.md) | FR-STU-001…196 (122 FR) per modul, NFR-STU-01…14, OWASP ASVS, matriks izin, **registry 56 tabel**, mesin status, 26 skenario penerimaan, keterlacakan FR → tahap | pembangun, peninjau |
| 3 | [`03-SDD.md`](03-SDD.md) | arsitektur, **ADR-49-01…10**, model data, **format kanonik Lembar Hitung + contoh lengkap Volumetric Fixed**, alur evaluasi, kontrak API + contoh JSON, keamanan, kinerja, migrasi per alat, struktur app Flutter, ringkasan spike S0 dan yang masih menunggu (§13) | pembangun, peninjau |
| 4 | [`04-UI-UX-Flow.md`](04-UI-UX-Flow.md) | layar ST-01…ST-90, matriks keadaan per layar, peta keyboard grid, token desain Studio, bahasa layar, alur utama | pembangun mobile, pemilik |
| 5 | [`05-Task-Breakdown.md`](05-Task-Breakdown.md) | 85 tugas ST-T-xxx dengan berkas, DoD, estimasi, dependensi, owner, rujukan | orkestrator, pemilik |
| 6 | [`06-Matriks-Paritas-Alat.md`](06-Matriks-Paritas-Alat.md) | 52 folder master (hitungan rumus/`#REF!`/tautan luar), 48 profil × status paritas & gelombang | penjaga-angka, orkestrator |
| — | `docs/pertanyaan-lab-studio-acuan.md` §Q-49 | pertanyaan terbuka Q-49-01…16 (Q-49-15/16 dari spike S0a, 13 Okt) | pemilik, Lab |

## 3. Keputusan pemilik §49 (9 Okt 2026)

| Kode | Keputusan |
|---|---|
| K-49-01 | Aplikasi Flutter **terpisah** (nama, ikon, identitas, desain beda total), Windows + tablet Android |
| K-49-02 | Rumus (lapis 2) **boleh disunting di Studio** lewat editor mirip Excel + mesin di server, dengan versi, simulasi, pengesahan. Mengubah §48 dan `AGENTS.md` §Olah data butir 1 |
| K-49-03 | Cakupan **semua tabel database**; invarian tetap |
| K-49-04 | Mesin rumus `phpoffice/phpspreadsheet` |
| K-49-05 | Pengesah versi rumus & data acuan: `super_admin`, **orang kedua** (`PemisahanWewenang`) |
| K-49-06 | **Tanpa hapus permanen** dari Studio; arsip/soft delete dengan riwayat |
| K-49-07 | **Agregasi (u_c, veff, k), lantai CMC, PASS/FAIL ikut bisa disunting per alat** di Lembar Hitung; blok bawaan setara `GumCalculator`, `GumCalculator` jadi pengawas (PERINGATAN `agregasi_beda_dari_gum`), versi pertama tetap lewat paritas, kesalahan master tidak ditiru otomatis. Mengubah `AGENTS.md` §Profil kalibrasi; menjawab Q-49-10 |

Sumber: `docs/permintaan-user-7.md` §49 dan `_agents_workspace/studio-data-acuan/06-rencana-rescope.md`
"KEPUTUSAN PEMILIK 9 Okt 2026".

## 4. Urutan berlaku bila bertentangan

1. **`AGENTS.md`** (terutama §Olah data, §Profil kalibrasi, §Peran, §Keadaan nyata `super_admin`,
   §Akun, `docs/aturan-akses-database.md`).
2. **`docs/permintaan-user-7.md` §49**, lalu bagian §48 yang tidak diubah §49.
3. **Paket ini** (`docs/sidik-studio/`).
4. Paket CR-48 `docs/studio-data-acuan/` untuk bagian yang masih berlaku (§5 di bawah).

Contoh nyata urutan itu: versi pertama paket ini menahan agregasi di kelas bersama karena
`AGENTS.md` §Profil kalibrasi melarang memindahkannya. Pemilik lalu memutuskan K-49-07 (menjawab
Q-49-10), dan `AGENTS.md` §Profil kalibrasi ikut diubah untuk alat ber-Lembar Hitung dengan syarat
blok bawaan + pengawas `GumCalculator`. ADR-49-08 ditulis ulang mengikuti urutan itu: aturan di
`AGENTS.md` diubah dulu, baru rancangan.

## 5. Yang dari paket CR-48 tetap berlaku dan yang diganti §49

| Bagian CR-48 | Status |
|---|---|
| K-48-01 (lapis 2 tidak diubah dari UI) | **Diganti** K-49-02 |
| README "Tiga kalimat" butir 1 (Studio hanya lapis 1 & 3) | **Diganti** K-49-02 |
| ADR-01 (Studio = seksi di `DesktopShell` aplikasi yang sama) | **Diganti** K-49-01 / ADR-49-06 |
| ADR-07 (lapis 2 tetap kode, menunggu K-48-01) | **Diganti** ADR-49-02, 03, 04, 08 |
| NFR-06 (Studio hanya desktop Windows/macOS) | **Diganti**: Windows + tablet Android; macOS bukan target |
| FR-26/FR-27 (peta rumus baca saja + usulan rumus ke PR) | **Diganti** modul Lembar Hitung (FR-STU-100…133) |
| 04 UI: ubahan `DesktopShell`, `rumus_list_screen.dart` | **Diganti** aplikasi Studio terpisah |
| 05 T2.5 (seksi `DesktopShell`) | **Diganti** ST-T-011/014 |
| Rancangan `simulasi_acuan` + `simulasi_acuan_sesi` (belum dibuat) | **Diganti** `simulasi_versi` + `simulasi_versi_sesi` bersama (ADR-49-09) |
| T3.2 "PhpSpreadsheet [dep baru, perlu persetujuan]" | **Terjawab** K-49-04 |
| K-48-02…07, BR-01…13, FR-01…25, FR-28/29, mesin status 02 §3, ADR-02/03/04/05/06/08/09, Test Plan, Migrasi-Rollout | **Tetap berlaku**, dirujuk dari `02-SRS.md` modul D |
| Lampiran A (46 folder) | **Tetap**, dikoreksi: CSV memuat rumus di 13 dari 52 folder (`06-Matriks-Paritas-Alat.md` §2), bukan nol |

## 6. Status per 13 Okt 2026

| Tahap | Status | Bukti |
|---|---|---|
| P1 CR-48 (fondasi Data Acuan) | selesai di branch, commit lokal `f3b1b7b` + `f18ff75` (13 Okt, belum push), belum di `main`; SQLite 5410 (5408 lulus, 2 dilewati), MySQL 5410/5410 | `BACA-DULU-BACKEND.md` bagian 9 Okt |
| §49 + perubahan `AGENTS.md` §Olah data butir 1 | commit lokal `afea890` (13 Okt, belum push) | `git show afea890` |
| S0a spike mesin | **selesai 10 Okt**: test spike 6/6, Volumetric 41/41; suite SQLite sesudah S0 5416 test (5414 lulus, 2 dilewati); suite MySQL sesudah S0 belum; `composer.json:18` = `"^5.10"`, lock 5.10.0; commit lokal `11f83f5` (13 Okt, belum push) | `tests/Unit/Spike/MesinRumusSpikeTest.php`; `03-SDD.md` §6.4 |
| S0b app `studio/` | **selesai di tingkat build, 13 Okt**: exe + APK debug (10 Okt), `flutter analyze` 0 isu, `flutter test` 28/28; belum diuji di perangkat (ST-T-020); belum commit | `03-SDD.md` ADR-49-06 |
| S0c pembaca `.xlsm` | belum dimulai; menunggu `.xlsm` asli Volumetric + Micrometer dari pemilik (ST-T-008) | §49 "Butuh dari pemilik" |
| Paket dokumen ini | ditulis 9 Okt; direvisi 13 Okt sesudah spike (ST-T-010); belum ditinjau, belum commit | — |
| S1–S6 | S1 dibuka 13 Okt (ST-T-011, 012 diserahkan ke pembangun mobile; ST-T-013 ditahan orkestrator); S2–S6 belum mulai | `_agents_workspace/studio-data-acuan/00-status.md` |

**Hasil spike** (rinci `03-SDD.md` §6.4 dan §13). S0a: dengan fungsi terdaftar `SIDIK.STDEV` dan
`SIDIK.KFAKTOR`, 116 sel keluaran × 3 sesi uji Volumetric Fixed identik bit dengan PHP, angka cetak
dan keputusan sama; `STDEV`/`STDEV.S` dan `TINV`/`T.INV.2T` bawaan mesin ditolak di lembar; satu
lembar 1 titik ±120 ms (≈26× PHP), jadi simulasi wajib lewat antrean dan pratinjau 1 sesi tetap
sinkron. S0b: build Studio dua platform dengan identitas sendiri, tanpa Firebase.

**Yang masih menunggu** (bertanda ⏳, masing-masing dengan tugas tindak lanjut): cara dekripsi
`.xlsm` (S0c, ST-T-006), perbandingan teks dan bentuk sel kosong/galat di keluaran (ST-T-090, 094,
095), siklus dan cache (ST-T-090), batas ukuran lembar (ST-T-093, 098), uji Studio di perangkat
(ST-T-020), widget dan tinggi baris grid (ST-T-015).

**Pertanyaan yang paling menahan:** Q-49-01 (siapa pembuat draf rumus), Q-49-02 (pemeriksa
independen paritas), Q-49-03 (lama bayangan), Q-49-11 (`.xlsm` per alat).

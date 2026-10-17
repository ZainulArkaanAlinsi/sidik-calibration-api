# 01 — PRD Sidik Studio

| | |
|---|---|
| Dokumen | Product Requirements Document, Sidik Studio |
| Dasar | `docs/permintaan-user-7.md` §49 (K-49-01 s.d. K-49-07), §48 (K-48), `AGENTS.md` §Olah data, §Peran, §Akun |
| Ditulis | 9 Okt 2026, atas permintaan pemilik "bikin sedetail-detailnya" |
| Status | **Rancangan.** Belum ada baris kode Studio di `main`. Lihat `00-BACA-DULU.md` §6 |
| Urutan berlaku | `AGENTS.md` → §49 → §48 (yang tidak diubah §49) → paket ini |

Istilah yang dipakai di seluruh paket:

| Istilah | Arti |
|---|---|
| **Studio** | Aplikasi Flutter terpisah "Sidik Studio" (Windows + tablet Android), bukan menu di aplikasi lapangan |
| **Aplikasi lapangan** | Aplikasi `sidik_calibration` yang sudah dipakai teknisi (HP, tablet, panel desktop) |
| **Kelola Data** | Modul Studio untuk menjelajah dan membetulkan isi tabel database lewat API ber-daftar-putih |
| **Data Acuan** | Lapis 1 olah data: nilai standar, koreksi, pita CMC, tabel MPE, konstanta metode (paket CR-48) |
| **Lembar Hitung** | Lapis 2 olah data yang disimpan sebagai data: sheet, sel, rumus, named range, pengikat input dan output. Dievaluasi server |
| **Versi** | Satu isi Data Acuan atau Lembar Hitung yang dibekukan sesudah diajukan, berstempel sha256 |
| **Gerbang paritas** | Syarat sebelum Lembar Hitung pertama sebuah alat boleh aktif: hasilnya sama dengan profil PHP hari ini (definisi persis di §5) |
| **Profil PHP** | Kelas `app/Services/Calibration/Profiles/*Profile.php` beserta kalkulatornya, jalur hitung hari ini |
| **Master** | Workbook Excel olah data lab per alat (`Project-PT-Sidik/alat-alat-Pt-Sidik/`), kebenaran rumus |

---

## 1. Masalah

1. **Hanya dua pintu untuk mengubah cara olah data, dan keduanya bukan milik orang lab.**
   Rumus tinggal di workbook Excel master di mesin pemilik dan di kode PHP (48 profil di
   `CalibrationProfileRegistry::daftarProfil()`, `app/Services/Calibration/CalibrationProfileRegistry.php:95-266`).
   Perubahan sekecil apa pun menempuh rantai developer: generator → JSON/PHP → test → push →
   deploy (`docs/studio-data-acuan/00-CR-Impact-Analysis.md` §2).
2. **Data di database hanya bisa dibetulkan lewat jalur sempit.** Panel Filament `/admin` hanya
   punya 10 resource; tidak ada API generik untuk tabel lain (`01-riset-rescope-tabel.md`). Akses
   MySQL langsung (phpMyAdmin) tidak tercatat siapa mengubah apa, dan itu temuan audit untuk lab
   ISO/IEC 17025 (`docs/arsitektur-desktop-database.md` Keputusan 4, baris 137-167).
3. **Excel tidak memberi tahu apa yang ikut berubah.** Dua kesalahan workbook lolos bertahun-tahun:
   rujukan sel meleset dan pita CMC terbaca dari baris salah (`AGENTS.md` §Olah data, paragraf
   pembuka). Ekspor CSV master hari ini memuat 272 rumus bertautan luar `[n]`, 764 sel bernilai
   `#REF!`, dan 64 rumus yang memuat `#REF!` (dua hitungan terakhir bisa beririsan) di 52 folder
   (hitungan ulang 9 Okt 2026, lihat `06-Matriks-Paritas-Alat.md` §2).
4. **Orang lab berpikir dalam lembar Excel, bukan dalam form.** Contoh pemilik 9 Okt: lembar
   "CALIBRATION BUDGET UNCERTAINTY" Volumetric Glassware (Pipet Ukur). Yang mereka butuhkan
   adalah grid sel dengan rumus yang terlihat, bukan daftar parameter.

## 2. Tujuan

| ID | Tujuan | Ukuran berhasil (terukur) |
|---|---|---|
| G1 | Lab memegang olah data tanpa developer | Satu perubahan rumus nyata (versi ke-2 Lembar Hitung pilot) melewati draf → simulasi → ajukan → sah tanpa satu commit kode |
| G2 | Paritas 100% sebelum mesin baru dipakai | Untuk tiap alat yang Lembar Hitung-nya aktif: laporan gerbang paritas LULUS 100% (definisi §5), ditandatangani pemeriksa independen |
| G3 | Nol angka bergeser diam-diam | 100% versi aktif (Data Acuan dan Lembar Hitung) punya laporan simulasi yang ditinjau; `GET /api/health` melaporkan `versi_aktif_tanpa_simulasi = 0` |
| G4 | Database dibetulkan lewat pintu yang tercatat | 100% perubahan dari Kelola Data menghasilkan baris `audit_logs` dengan pelaku, waktu, nilai lama, nilai baru, alasan. Nol jalur SQL mentah |
| G5 | Satu sumber kebenaran untuk semua perangkat | Sesudah versi sah, lembar kerja berikutnya di aplikasi lapangan memakai versi itu paling lambat 3 menit (realtime mati di produksi, `docs/studio-data-acuan/03-SDD-ADR.md` ADR-09), ≤ 5 detik bila realtime nyala |
| G6 | Terasa seperti Excel milik lab | Lembar Hitung Volumetric Fixed pilot memuat rantai yang sama dengan lembar pemilik (Utimb, Urepeat, Umassa, Utherm, Usensor, Ut-water, Utemp, meniskus, tabel komponen), bisa dinavigasi dengan keyboard ala Excel di Windows |
| G7 | Jejak terbaca tanpa membuka kode | Dari satu sertifikat, auditor bisa membuka versi Lembar Hitung + versi Data Acuan + sumber metode (IK, sel master, keputusan siapa dan kapan) dalam ≤ 3 klik |

**Bukan tujuan:** database lokal di laptop atau tablet (Keputusan 1 `arsitektur-desktop-database.md`);
mengganti panel Filament; aplikasi pelanggan; AI yang menghitung atau mengusulkan angka sertifikat.

## 3. Pengguna dan peran

Kunci peran di database tetap (`AGENTS.md` §Peran butir 1). Studio tidak menambah peran baru.
Yang boleh apa ditegakkan **server** (`role:` middleware, `MatriksIzin::PETA`, `PemisahanWewenang`,
registry Kelola Data). Menyembunyikan tombol di Studio hanya kenyamanan.

| Peran (kunci) | Label tampilan | Persona | Kebutuhan utama di Studio |
|---|---|---|---|
| `admin` | **Master Data** | Rina, ±5 orang | Membetulkan data master (alat, pelanggan, standar, ruangan, metode), menyunting draf Data Acuan dan (usulan, Q-49-01) draf Lembar Hitung, menjalankan simulasi, mengajukan |
| `super_admin` | Super Admin / pengesah | Bu Sari (nama fiktif), Manajer Teknis | Membaca beda dan laporan simulasi/paritas, **mengesahkan sebagai orang kedua** (K-49-05), menarik versi bermasalah. Di luar pengesahan tetap **baca saja** (`AGENTS.md` §Keadaan nyata `super_admin`) |
| `viewer` | Viewer / auditor | Auditor KAN atau internal | Menelusuri angka sertifikat ke versi dan sumbernya; menjelajah tabel tanpa mengubah |
| `teknisi` | Teknisi | Dimas | Belum jelas apakah masuk Studio (Q-49-04). Yang pasti: teknisi boleh melihat Data Acuan **aktif** saja (K-48-06), dan lembar kerjanya tetap di aplikasi lapangan |
| `pelanggan` | — | — | **Tidak bisa masuk Studio.** Rute Studio ada di `routes/api.php` dengan `aplikasi:internal`, bukan `api_pelanggan.php` |

### 3.1 Siapa boleh apa (ringkas; rinci di `02-SRS.md` §4)

| Aksi | admin | super_admin | viewer | teknisi |
|---|---|---|---|---|
| Masuk Studio | ✓ | ✓ | ✓ | Q-49-04 |
| Kelola Data: jelajah, detail, riwayat | ✓ | ✓ (baca) | ✓ | Q-49-04 |
| Kelola Data: ubah, arsip, pulihkan | ✓ | — | — | — |
| Kelola Data: ekspor CSV | ✓ | Q-49-05 | Q-49-05 | — |
| Koreksi nilai teknisi (lama + baru + alasan) | ✓ (jalur yang sudah ada) | — | — | — |
| Data Acuan: lihat versi aktif | ✓ | ✓ | ✓ | ✓ (K-48-06) |
| Data Acuan: draf, sunting, simulasi, ajukan | ✓ | — (K-48-03: pengesah tidak menyunting) | — | — |
| Data Acuan: sahkan, tolak, kembalikan, tarik | — | ✓ orang kedua | — | — |
| Lembar Hitung: lihat | ✓ | ✓ | ✓ | Q-49-04 |
| Lembar Hitung: draf, sunting, pratinjau, simulasi, ajukan | usulan ✓ (Q-49-01) | — | — | — |
| Lembar Hitung: sahkan, tolak, kembalikan, tarik | — | ✓ orang kedua | — | — |
| Impor `.xlsm` | ✓ | — | — | — |

"Orang kedua" artinya `PemisahanWewenang` menolak pengesah yang sama dengan pembuat, penyunting,
pengaju, atau pengunggah workbook versi itu. **Tanpa pengecualian**, termasuk `super_admin`
(K-30-03, `AGENTS.md` §Peran butir 4). Akibatnya disengaja: lab wajib punya minimal satu
`super_admin` yang bukan penyunting, kalau tidak versi tertahan.

## 4. User story

| ID | Sebagai | Saya ingin | Supaya |
|---|---|---|---|
| US-01 | Master Data | membuka tabel Alat, menyaring "jatuh tempo bulan ini", lalu membetulkan nomor seri dengan alasan | data bersih tanpa phpMyAdmin dan tercatat |
| US-02 | Master Data | melihat riwayat satu baris pelanggan (siapa mengubah apa, kapan, nilai lama → baru) | saya bisa menjawab pertanyaan auditor |
| US-03 | Master Data | mengarsipkan ruangan yang tidak dipakai dan memulihkannya bila keliru | daftar pilihan teknisi bersih tanpa kehilangan data |
| US-04 | Master Data | mengoreksi pembacaan teknisi pada sesi yang belum disetujui, dengan nilai lama tetap tersimpan | ISO/IEC 17025 7.5.2 terpenuhi |
| US-05 | Master Data | menyunting draf Data Acuan Micrometer, menempel dari sertifikat standar, mensimulasikan, dan mengajukan | rekalibrasi standar masuk sistem tanpa developer |
| US-06 | Master Data | membuka Lembar Hitung Volumetric Fixed dan melihat rantai Utimb → Umassa → budget seperti di Excel | saya paham dari mana U datang |
| US-07 | Master Data | mengklik sel `uc` dan melihat semua sel hulu yang memengaruhinya | saya bisa menelusuri rujukan tanpa menebak |
| US-08 | Master Data | mempratinjau draf Lembar Hitung pada satu sesi tersimpan dan membandingkannya dengan angka tersimpan | saya tahu akibat suntingan sebelum mengajukan |
| US-09 | Master Data | menjalankan simulasi draf ke semua sesi tersimpan dan melihat sesi mana yang **angka cetaknya** berubah | perubahan yang mengenai sertifikat terlihat sebelum berlaku |
| US-10 | Master Data | mengimpor workbook `.xlsm` master dan mendapat draf Lembar Hitung beserta daftar sel yang tidak didukung | migrasi alat tidak dimulai dari nol |
| US-11 | Pengesah | membaca beda, laporan simulasi, laporan paritas (bila versi pertama), dan rujukan sumber di satu layar | keputusan cepat dan tercatat |
| US-12 | Pengesah | menjadwalkan versi berlaku mulai tanggal kalibrasi tertentu | perubahan metode berlaku sejak IK baru, bukan hari ini |
| US-13 | Pengesah | menarik versi aktif yang terbukti salah | sesi baru kembali ke versi sebelumnya, sesi belum terbit ditahan |
| US-14 | Auditor | dari nomor sertifikat, membuka versi Lembar Hitung dan Data Acuan yang distempel | ketertelusuran terbukti |
| US-15 | Teknisi | lembar kerja di HP saya otomatis memakai versi yang sah tanpa pasang ulang | saya tidak perlu tahu ada Studio |
| US-16 | Master Data (tablet) | meninjau dan mengesahkan dari tablet di lab | pengesahan tidak menunggu laptop |

## 5. Metrik sukses: definisi "paritas 100%"

Permintaan pemilik 9 Okt: "target paritas 100% sama Excel, bisa diubah". Di sistem ini ada dua
perbandingan yang **tidak boleh dicampur**:

| Perbandingan | Yang diadu | Toleransi | Siapa yang menetapkan |
|---|---|---|---|
| **Gerbang paritas** (§49 syarat 3) | Lembar Hitung versi pertama sebuah alat lawan profil PHP hari ini, pada input yang sama | Definisi di bawah (rencana yang disetujui pemilik 9 Okt, `06-rencana-rescope.md` butir 3) | Pemilik (sudah), pemeriksa independen menandatangani hasilnya (Q-49-02) |
| **Uji pembanding** (K-48-07) | Hasil server lawan workbook master yang dihitung ulang | Ditetapkan Lab per alat. **Belum ada yang diisi** (`docs/pertanyaan-lab-studio-acuan.md` T-1 s.d. T-9) | Lab |

Gerbang paritas membuktikan **mesin baru sama dengan mesin lama**. Bahwa mesin lama sama dengan
master sudah dibuktikan test rekonsiliasi per alat (mis. `VolumetricGlasswareMasterTest`,
`MicrometerMasterTest`), dengan selisih 5·10⁻⁶ yang dipilih developer, bukan Lab
(`pertanyaan-lab-studio-acuan.md` baris "Selisih yang dipakai test hari ini"). Paket ini tidak
mengubah status itu.

**Definisi lulus gerbang paritas.** Untuk versi Lembar Hitung *L* dan profil PHP *P*, pada
himpunan input *S* = semua sesi tersimpan profil itu di semua status + semua vektor master
(fixture `database/data/sesi-*.json` dan test rekonsiliasi) + sesi seeder:

1. **Nilai antara identik bit atau relatif ≤ 1·10⁻¹².** Untuk setiap angka *x* yang dihasilkan
   kedua jalur (tiap komponen budget `u`, `ci`, `vi`, `u_diperluas`, `pembagi`; `rata_rata`,
   `error`, `koreksi`, `standar_deviasi`, `type_a`, `type_b`; `ketidakpastian_gabungan`,
   `derajat_kebebasan_efektif`, `faktor_cakupan_k`, U hitung, CMC, U95 terbit):
   `x_L === x_P`, **atau** `|x_L − x_P| ≤ 1·10⁻¹² · |x_P|`. Bila `x_P = 0`, `x_L` wajib `0`.
   `null` hanya sama dengan `null`. NaN dan tak hingga selalu gagal. Laporan memisahkan berapa
   yang identik bit dan berapa yang hanya dalam toleransi.
2. **Angka cetak identik.** Setiap angka yang tercetak di sertifikat, sesudah pembulatan per
   alat (`desimalSertifikat`, `desimalU95`, `desimalFaktorCakupan`), sama persis sebagai string.
   Satu digit beda = gagal, walau syarat 1 lulus.
3. **Keputusan identik.** `PASS`, `FAIL`, atau `null` per titik sama persis.
4. **Titik yang ditahan identik.** Himpunan `titik_ke` yang tidak menghasilkan baris hitung sama.
   Teks alasan boleh beda (dilaporkan sebagai informasi).
5. **Kode jejak identik.** Daftar `sumber` komponen budget dan kode catatan penyimpangan di
   `type_b_components` sama, termasuk urutannya.

Lulus = 100% sesi dan vektor memenuhi kelima syarat. Satu gagal = gerbang tertutup.

**Kenapa 1·10⁻¹², bukan selalu identik bit.** Sejak K-49-07 agregasi (u_c, Welch–Satterthwaite,
k), lantai CMC, dan keputusan ikut dihitung di lembar lewat blok agregasi bawaan yang urutan
operasinya disalin dari `GumCalculator` (ADR-49-08). Selisih yang mungkin tersisa datang dari
urutan penjumlahan di fungsi lembar (`AVERAGE`, `STDEV`, `SUM`) dan dari `TINV` mesin
(Newton–Raphson) lawan `StudentTDistribution` repo (biseksi). Spike S0a (10 Okt 2026,
`03-SDD.md` §6.4) mengukurnya pada 3 sesi uji Volumetric Fixed (1 contoh seeder + 2 rekaan):
`AVERAGE`, `SUM`, dan `^` identik bit; `STDEV` mesin beda s.d. 6,7·10⁻⁸ relatif (rumus satu
lintasan) dan `TINV` mesin ≤ 3,1·10⁻¹³ relatif tetapi 2.162,91 ms pada df 10⁶, jadi keduanya ditolak
dan lembar memakai fungsi terdaftar `SIDIK.STDEV` dan `SIDIK.KFAKTOR` (di blok agregasi) yang
memanggil kode PHP yang sama. Dengan itu **116 sel keluaran × 3 sesi identik bit**, angka cetak dan keputusan sama.
Bukti itu baru untuk satu keluarga alat dan sesi uji, bukan semua sesi tersimpan; karena itu
toleransi 1·10⁻¹² tetap menjadi definisi syarat 1 sampai pemilik menjawab Q-49-16 (perketat menjadi
identik bit), dan laporan paritas tetap memisahkan yang identik bit dari yang hanya dalam
toleransi. Setelah versi pertama, beda
agregat lembar dari `GumCalculator` tidak lagi menggagalkan apa pun, tetapi selalu tercatat sebagai
PERINGATAN `agregasi_beda_dari_gum` (FR-STU-134).

**Metrik lain:**

| Metrik | Target | Cara ukur |
|---|---|---|
| Waktu rekalibrasi standar (pilot Micrometer) dari layar | ≤ 30 menit draf → sah, nol commit | catatan uji staging S3 |
| Perubahan Kelola Data yang tercatat | 100% | test: setiap rute tulis Kelola Data menghasilkan `audit_logs` |
| Rute Studio yang lolos tanpa izin | 0 | `RuteInternalMenolakRoleLainTest`, `SapuRuteIsolasiOrganisasiTest` ikut menyapu rute baru |
| Data organisasi lain terbaca lewat Studio | 0 | test 404 per rute ber-parameter |
| Sertifikat terbit yang angkanya berubah karena versi baru | 0 | BR-11 CR-48; simulasi menandai, sertifikat tidak dihitung ulang |

## 6. Lingkup per tahap

Tahap mengikuti §49. Rinci tugas di `05-Task-Breakdown.md`.

| Tahap | Isi | Keluar dengan | Alat |
|---|---|---|---|
| **S0** Fondasi | Tutup P1 (dua suite sudah hijau 9 Okt); spike S0a mesin PhpSpreadsheet; spike S0b aplikasi `studio/` (identitas sendiri, putus kopling FCM); spike S0c pembaca `.xlsm`; paket dokumen ini | Laporan spike + keputusan desain yang tertunda di SDD terisi | Volumetric Fixed (spike) |
| **S1** Kerangka aplikasi | Login, cangkang Windows + tablet, desain sistem Studio, layar Data Acuan M2 pindah ke Studio, menu Data Acuan di aplikasi lapangan dicabut dari PR | APK + exe Studio terpasang di perangkat uji | — |
| **S2** Kelola Data | Registry 56 tabel, API, layar jelajah/sunting/arsip/riwayat/ekspor; mulai 10 tabel kelas ubah/arsip, lalu baca saja untuk sisanya | Master Data membetulkan data nyata di staging | — |
| **S3** Data Acuan tulis | P2 CR-48 di Studio: draf, sunting sel, tempel, tambah baris, simulasi, ajukan, sahkan orang kedua, jadwal, tarik; butir "dibawa ke P2" §48 | Satu rekalibrasi standar Micrometer nyata sah di staging | Micrometer |
| **S4** Mesin rumus + Lembar Hitung | Antarmuka `MesinRumus` + PhpSpreadsheet, format kanonik, pengikat, evaluator, gerbang paritas, mode bayangan; Lembar Hitung pilot ditulis dari PHP | Gerbang paritas LULUS untuk pilot, diperiksa penjaga-angka | Volumetric (Fixed + Graduated), Micrometer |
| **S5** Editor rumus | Grid rumus mirip Excel, bilah rumus, inspektur sel, named range, jejak ketergantungan, pengikat, pratinjau, banding, simulasi, pengesahan, impor `.xlsm` | Versi ke-2 Lembar Hitung pilot sah lewat Studio | Pilot |
| **S6** Migrasi per alat | Gelombang per keluarga alat, tiap alat dengan bukti paritas | Matriks paritas: semua alat "aktif" | 41 profil sisanya dari 48 (lihat `06-Matriks-Paritas-Alat.md`) |

## 7. Di luar lingkup

- Database lokal, sinkronisasi dua arah, atau penyimpanan tulis offline di Studio.
- Hapus permanen dari Studio (K-49-06). Hapus permanen tetap lewat `docs/aturan-akses-database.md`
  butir 4.
- Mengubah kunci peran, menambah peran, atau melonggarkan `lolosBacaSuperAdmin`.
- Membaca lintas organisasi lewat API (tetap ditunda, `AGENTS.md` §Keadaan nyata `super_admin`).
- Mematikan pengawas agregasi `GumCalculator` untuk alat mana pun. Agregasi boleh disunting per
  alat di Lembar Hitung (K-49-07), tetapi pengawasnya tidak bisa dimatikan dari layar (ADR-49-08).
- Ubah massal baris di Kelola Data (dibahas sesudah S6, butuh pratinjau per baris).
- Aplikasi pelanggan dan rute `api/pelanggan/v1`.
- macOS sebagai target Studio (K-49-01 menyebut Windows dan tablet Android).
- Mengubah bentuk lembar kerja lapis 3 dari Studio (tetap rancangan CR-48 fase 3, tidak dijadwalkan
  di S0–S6).
- AI/OCR yang mengisi rumus, angka, atau keputusan. Impor `.xlsm` membaca rumus apa adanya;
  setiap pengikat dikonfirmasi manusia.

## 8. Risiko

| ID | Risiko | Level | Penahan |
|---|---|---|---|
| R-01 | Mesin PhpSpreadsheet menghitung beda tipis dari PHP (urutan operasi, `STDEV`, `TINV`) dan selisihnya menggeser angka cetak | 🔴 | Gerbang paritas syarat 2 (angka cetak identik) termasuk blok agregasi bawaan; pengawas `GumCalculator` di setiap evaluasi (ADR-49-08); spike S0a (10 Okt): `STDEV`/`TINV` mesin ditolak, diganti `SIDIK.STDEV`/`SIDIK.KFAKTOR` → identik bit di 3 sesi uji (`03-SDD.md` §6.4) |
| R-02 | Versi PhpSpreadsheet berganti diam-diam lewat `composer update` dan angka ikut bergeser | 🔴 | `composer.json:18` sejak 10 Okt `"phpoffice/phpspreadsheet": "^5.10"` (ST-T-004; terpasang 5.10.0 di `composer.lock`), yang masih mengizinkan 5.x lebih baru lewat `composer update`. Wajib dipatok persis dan dijaga test versi mesin (ADR-49-02) |
| R-03 | Editor rumus melahirkan kesalahan kelas yang sama dengan Excel, hanya lebih cepat | 🔴 | Simulasi wajib, angka cetak berubah disorot, pengesah orang kedua, log metode berisi sumber (AGENTS §Olah data butir 3-4) |
| R-04 | Lembar Hitung meniru kerusakan master (`#REF!`, tautan luar, rujukan meleset, `IFERROR(…,"")`) | 🔴 | Validator menolak `#REF!`, rujukan luar, sel kosong yang dibaca nol; impor melaporkan; kerusakan dihitung benar + selisih dicatat (`AGENTS.md` §Aturan yang Lahir dari Kesalahan Nyata) |
| R-05 | Kelola Data menjadi pintu belakang yang melewati aturan domain (mis. mengubah `nama_alat_kemampuan` membelokkan profil) | 🔴 | Registry daftar-putih per kolom; validasi sama dengan API yang ada; tabel berinvarian hanya baca; tulis lewat Eloquent (event + `Diaudit` jalan) |
| R-06 | Satu-satunya `super_admin` juga penyunting, versi tidak pernah bisa sah | 🟠 | Disengaja (ADR-05 CR-48). Pemilik memastikan ≥ 1 pengesah yang tidak menyunting |
| R-07 | Data pelanggan bocor dari ekspor CSV atau cache tablet | 🟠 | Q-49-05, Q-49-13; bawaan: ekspor admin saja, tercatat; tanpa cache disk untuk baris berisi data pribadi |
| R-08 | Workbook `.xlsm` asli belum diserahkan untuk 39 dari 52 folder yang CSV-nya tanpa rumus | 🟠 | Gelombang S6 diurutkan menurut ketersediaan berkas (Q-49-11). Lembar Hitung pertama tetap boleh ditulis dari PHP untuk paritas |
| R-09 | Realtime mati di produksi, pengguna mengira versi langsung sampai | 🟡 | UI menulis "paling lambat 3 menit" selama `realtime.nyala = false` (ADR-09 CR-48) |
| R-10 | Beban evaluasi PhpSpreadsheet berat di server Render | 🟠 | Simulasi wajib di antrean; pratinjau 1 sesi sinkron dibatasi ukuran. Spike S0a (10 Okt, laptop kerja): lembar Volumetric Fixed 1 titik bangun 11,82 ms + evaluasi 107,98 ms median, ≈26× jalur PHP (4,148 ms), puncak memori ±314 KiB; target kinerja disetel di NFR-STU-03. Angka di Render diukur saat bayangan pilot (ST-T-103) |
| R-11 | Celah lama `isiUlangPengukuran()` menghapus `raw_measurements` dan `uncertainty_calculations` (`app/Http/Controllers/Api/CalibrationController.php:1601-1602`) | 🟠 | Di luar paket ini (temuan §49). Kelola Data **tidak** memakai jalur hapus itu; koreksi lewat `PUT /calibrations/{c}` yang mencatat lama + baru (`CalibrationController.php:945-970`) |
| R-12 | Lab mengubah agregasi per alat (K-49-07) dan U/keputusan menyimpang dari GUM tanpa disadari pembaca sertifikat | 🔴 | Blok bawaan setara `GumCalculator`; pengawas di setiap evaluasi; simulasi menampilkan selisih; PERINGATAN `agregasi_beda_dari_gum` di jejak sesi; ajukan ditolak tanpa catatan penyimpangan bersumber (FR-STU-134, 135) |

## 9. Dokumen terkait

- `02-SRS.md` kebutuhan fungsional FR-STU dan non-fungsional NFR-STU.
- `03-SDD.md` arsitektur, ADR-49-01 s.d. ADR-49-10, model data, format kanonik, kontrak API.
- `04-UI-UX-Flow.md` layar ST-xx.
- `05-Task-Breakdown.md` tugas ST-T-xxx.
- `06-Matriks-Paritas-Alat.md` per alat.
- Pertanyaan terbuka: `docs/pertanyaan-lab-studio-acuan.md` bagian Q-49.

# Pertanyaan Lab — Studio Data Acuan (CR-48)

Konteks: `docs/permintaan-user-7.md` §48 dan `docs/studio-data-acuan/06-Test-Plan.md` §3.

Studio Data Acuan memindahkan data acuan olah data (lapis 1) dari berkas di repo ke versi yang
disahkan di server. Setiap alat yang dipindah wajib lolos **uji pembanding**: hasil hitung
server diadu ke workbook master. Batas selisih yang diterima hanya boleh ditetapkan Lab
(K-48-07). Selama batas itu belum ada, sebuah alat tidak boleh disebut selesai.

Berkas ini **tidak mengusulkan angka toleransi**. Yang ditulis hanya keadaan kode hari ini,
sebagai bahan Lab memutuskan.

Status: semua pertanyaan **[PERLU JAWABAN]**. Yang tertahan: status "Micrometer selesai" di P5.
P1–P4 tetap bisa dikerjakan.

---

## Bahan: Micrometer hari ini

| Hal | Keadaan di kode | Rujukan |
|---|---|---|
| Satuan nilai standar tumpukan, rata-rata, koreksi | mm | `MicrometerCalculator::totalNominal()` |
| Satuan komponen ketidakpastian, uc, U95 | µm (diubah ke mm hanya saat dicetak) | `MicrometerCalculator::budget()`, `hitungSesi()` |
| Desimal angka cetak hasil (standar, UUT, koreksi) | 5 desimal mm | `MicrometerProfile::desimalSertifikat()` |
| Desimal angka cetak U95 | 5 desimal mm | `MicrometerProfile::desimalU95()` |
| Desimal faktor cakupan k | tidak disetel di profil (bawaan) | `CalibrationProfile::desimalFaktorCakupan()` |
| Resolusi alat (UUT) | **diisi teknisi per sesi**, bukan angka tetap: `spesifikasi_alat.micrometer.resolusi_mm` | `MicrometerProfile` blok identitas alat |
| Resolusi di sesi contoh master | 0,001 mm (sesi 25–50, 50–75, 75–100) dan 0,000254 mm (sesi 0–25, yang satuannya terisi `inch` — cacat data di `pertanyaan-lab-micrometer.md` §3) | `database/data/sesi-master-micrometer.json` |
| U95 yang terbit | MAX(U95 hitung, CMC pita rentangnya). Di luar keempat pita CMC (0–100 mm), U95 tidak terbit | `MicrometerCalculator::hitungSesi()` |
| Selisih yang dipakai test hari ini | 5·10⁻⁶ dalam satuan besarannya (mm atau µm). **Angka ini dipilih developer untuk mengadu kode ke master, bukan keputusan Lab, dan bukan usulan** | `tests/Unit/MicrometerMasterTest.php` |

Komponen budget Micrometer (sembilan, semuanya per sesi):

| `sumber` | Keterangan |
|---|---|
| `pengulangan` | Repeatability (pra-evaluasi), t-student |
| `resolusi_uut` | Resolusi alat |
| `ketidakpastian_standar` | Standar balok ukur (tumpukan) |
| `suhu_ruang` | Perubahan suhu terhadap suhu acuan 20 °C |
| `koefisien_muai` | Koefisien muai termal |
| `drift_standar` | Drift standar |
| `wringing` | Lapisan wringing |
| `geometri` | Kesalahan geometri |
| `selisih_suhu` | Selisih suhu mikrometer dengan balok ukur |

---

## Toleransi uji pembanding Micrometer

Untuk tiap pertanyaan, mohon sebutkan juga **bentuknya**: selisih mutlak (dalam satuan apa),
selisih relatif, atau "harus identik sampai digit ke-N".

**T-1 — Nilai standar tumpukan balok ukur (mm).** Berapa selisih maksimum antara total nilai
terkoreksi tumpukan hasil server dan nilai yang sama di workbook?

**T-2 — Ketidakpastian tumpukan balok ukur.** Berapa selisih maksimum untuk ketidakpastian
tumpukan (gabungan U keping-kepingnya)?

**T-3 — Rata-rata pembacaan dan koreksi per titik (mm).** Berapa selisih maksimum per titik?

**T-4 — Tiap komponen ketidakpastian.** Apakah satu toleransi berlaku untuk kesembilan komponen
di atas, atau per komponen? Apakah toleransi yang sama berlaku untuk koefisien sensitivitas
(`ci`) dan derajat kebebasan (`vi`)?

**T-5 — uc, νeff, dan k.** Berapa selisih maksimum untuk ketidakpastian gabungan, derajat
kebebasan efektif, dan faktor cakupan?

**T-6 — U95.** Berapa selisih maksimum untuk U95 hasil hitung (sebelum lantai CMC), dan apakah
batasnya sama untuk U95 yang terbit (sesudah MAX dengan CMC)?

**T-7 — Angka cetak.** Apakah angka cetak sertifikat (5 desimal mm) wajib identik dengan cetak
workbook? Kalau nilai antara jatuh tepat di batas pembulatan sehingga digit terakhir berbeda,
apakah itu dihitung gagal?

**T-8 — Keempat rentang.** Apakah toleransi T-1 sampai T-7 berlaku sama untuk 0–25, 25–50, 50–75,
dan 75–100 mm?

**T-9 — Data pembanding.** Sesi mana yang dipakai sebagai pembanding: sesi contoh yang sudah ada
di workbook master (sudah dipakai `sesi-master-micrometer.json`), atau Lab menyediakan sesi
lain? Data pelanggan dari sheet `DATABASE` tidak akan disalin ke repo.

---

## Konfirmasi keputusan K-48

Pemilik proyek memilih jawaban default untuk ketujuhnya pada 8 Okt 2026 (§48). Mohon
konfirmasi, terutama K-48-01 dan K-48-03.

| ID | Keputusan yang tercatat | Perlu dikonfirmasi oleh | Pertanyaan |
|---|---|---|---|
| **K-48-01** | Rumus (lapis 2) **tidak** bisa diubah dari layar. Revisi rumus di Excel tetap lewat PR kode + test rekonsiliasi master (`P9`) | Pemilik proyek, Manajer Teknis | Setuju rumus hanya berubah lewat jalur `P9`, bukan dari Studio? |
| K-48-02 | Alat pilot: **Micrometer**. pH jadi alat kedua | Pemilik proyek | Setuju? |
| **K-48-03** | Pengesah versi data acuan: **`super_admin`**, dan tidak pernah orang yang menyunting atau mengajukan versi itu | Pemilik proyek, Manajer Teknis | Setuju? Catatan: K4 (siapa yang mengesahkan *sertifikat*) belum dijawab Manajer Teknis. K-48-03 hanya untuk *data acuan* dan tidak menjawab K4 |
| K-48-04 | Versi baru berlaku menurut **`tanggal_kalibrasi` sesi** | Manajer Teknis | Setuju sesi dihitung dengan versi yang berlaku pada tanggal kalibrasinya, bukan tanggal input? |
| K-48-05 | Master Data **boleh** menyimpan draf pribadi tanpa mengajukan. Draf tidak berpengaruh ke perhitungan | Pemilik proyek | Setuju? |
| K-48-06 | Teknisi **boleh** melihat tabel acuan yang aktif, baca saja, tanpa riwayat draf | Pemilik proyek | Setuju? |
| K-48-07 | Toleransi uji pembanding **ditetapkan Lab per alat** | Lab | Dijawab lewat T-1 sampai T-9 untuk Micrometer. Alat berikutnya menyusul dengan berkas pertanyaannya sendiri |

## Pilot Lembar Hitung Volumetric (Sidik Studio, §49) — 9 Okt 2026

Diangkat dari pemeriksaan angka 9 Okt 2026. Pembanding: lembar "CALIBRATION BUDGET UNCERTAINTY" yang
ditunjukkan pemilik (Pipet Ukur 3 ml), ekspor CSV master `Volumetric_Glassware_2026` (21–23 Sep 2026),
dan `VolumetricGlasswareCalculator`. Kode sama dengan cache CSV master (V20 dan uc, selisih 0). Yang
ditanyakan di bawah adalah beda antara lembar pemilik, master, dan ISO 4787. Pertanyaan yang sudah ada
di `pertanyaan-lab-volumetric.md` tidak diulang: `Veff` dibagi satu baris ada di no. 1 di sana.

| No | Pertanyaan | Angka | Untuk |
|---|---|---|---|
| V-1 | ρ anak timbangan: lembar pemilik menampilkan **8**, master CSV (Fixed `PERHITUNGAN!H58`, Graduated baris 68) dan kode memakai **7,95**. Nilai asli sel itu berapa? Bisa jadi sel berformat 0 desimal | V20 beda 2,8e-6 ml, U beda 6e-8 ml (Pipet Ukur 3 ml) | Lab |
| V-2 | Rumus V20 master meletakkan suku muai **di dalam** `(1−ρA/ρB·(1−γ(t−20)))`, sedangkan ISO 4787 memakainya sebagai faktor terpisah. Disengaja atau salah salin? Kode meniru master | V20 beda 1,6e-4 ml (γ 9,9e-6) s.d. 2,5e-4 ml (γ 1,5e-5); bisa menggeser angka cetak | Manajer Teknis |
| V-3 | ρ air suling di lembar pemilik 0,99691538 g/ml pada 25,56 °C. Persamaan Tanaka di kode memberi 0,99690186 (lembar setara 25,508 °C). Dari mana angka itu: rata-rata per ulangan atau tabel lain? | ρ beda 1,35e-5 g/ml, V20 beda ≈ 4e-5 ml | Lab |
| V-4 | Pipet Ukur memakai budget struktur **Fixed** di lembar pemilik, sedangkan kode memetakannya ke **Graduated** (u_massa = U95/2, meniskus resolusi/(2√3), tanda ci muai −1). Pipet Ukur resminya keluarga yang mana? | Struktur budget berbeda | Manajer Teknis |

Sampai V-1 s.d. V-4 dijawab, Lembar Hitung Volumetric pertama meniru kode/master saat ini (gerbang
paritas §49). Perubahan setelah jawaban masuk sebagai versi baru dengan simulasi dan pengesahan.

## Sidik Studio (§49) — Q-49 — 9 Okt 2026

Diangkat saat menulis paket rancangan `docs/sidik-studio/`. Keputusan yang **sudah** diambil
(K-49-01 s.d. K-49-06) tidak ditanyakan ulang. Kolom "Usulan sementara" adalah yang dipakai paket
selama belum dijawab — **bukan keputusan**, dan setiap usulan itu tidak boleh dibuka di produksi
sebelum dijawab kecuali disebut lain. Status semua butir: **[PERLU JAWABAN]**, kecuali Q-49-10
(**[TERJAWAB]** oleh K-49-07, 9 Okt 2026).

| No | Pertanyaan | Kenapa menentukan | Usulan sementara | Untuk | Menahan |
|---|---|---|---|---|---|
| Q-49-01 | Siapa yang boleh **membuat dan menyunting draf Lembar Hitung** (rumus, lapis 2) dan mengimpor `.xlsm`: `admin` (Master Data) saja, `super_admin` saja, atau keduanya? K-49-05 hanya menjawab siapa pengesahnya | Menentukan grup rute dan izin `lembar-hitung.sunting`/`impor`/`ajukan`. `super_admin` hari ini baca saja di luar pengecualian tercatat; membukanya menulis draf butuh grup rute saudara baru | Mengikuti pola Data Acuan K-48-05: `admin` menyunting dan mengajukan, `super_admin` mengesahkan | Pemilik, Manajer Teknis | rute tulis S4/S5 dibuka di produksi |
| Q-49-02 | Siapa **pemeriksa independen** gerbang paritas selain agen penjaga-angka, dan bagaimana persetujuannya dicatat (nama orang Lab di laporan paritas? tanda tangan di Studio?) | `AGENTS.md` §Olah data butir 1: "Pemeriksanya independen". Agen saja belum tentu memenuhi ISO/IEC 17025 7.2.1.5 untuk validasi metode | Penjaga-angka menghitung ulang dari master + satu orang Lab (Manajer Teknis) dicatat namanya di laporan paritas | Manajer Teknis | status `paritas` pilot |
| Q-49-03 | Berapa lama atau berapa sesi nyata **tahap bayangan** wajib berjalan sebelum versi Lembar Hitung pertama boleh diajukan? | Produksi punya sedikit sesi tersimpan; paritas atas sesi tersimpan saja tipis buktinya. Terlalu lama menunda, terlalu singkat tidak bermakna | Minimal N sesi nyata per alat **atau** M minggu, mana yang lebih lama — angka N dan M diputuskan Lab | Manajer Teknis | status `paritas` dan `aktif` semua alat |
| Q-49-04 | **Teknisi** dan **viewer** di Studio: teknisi boleh masuk? Melihat apa (Kelola Data baca? Lembar Hitung baca?) | Menentukan isi `role:` grup baca dan menu. K-48-06 hanya mengizinkan teknisi melihat Data Acuan aktif | Teknisi: hanya Data Acuan aktif (K-48-06), tidak Kelola Data/Lembar Hitung. Viewer: baca semua modul tanpa ekspor | Pemilik | izin S2 & S4 |
| Q-49-05 | **Ekspor CSV** Kelola Data yang memuat data pribadi pelanggan (nama, alamat, kontak): siapa yang boleh, perlu alasan, dan apakah kolom pribadi disamarkan untuk viewer? | UU PDP; ekspor berkas keluar dari kendali sistem | Hanya `admin`, setiap ekspor tercatat dengan saringan dan jumlah baris; viewer & `super_admin` tidak bisa mengekspor sampai dijawab | Pemilik | izin `kelola-data.ekspor` untuk selain admin |
| Q-49-06 | Impor `.xlsm`: **berkas workbook asli disimpan** di server (disk privat) atau hanya sha256 + laporan + draf hasil? Workbook memuat sheet `DATABASE` berisi data pelanggan | Menyimpan memberi bukti asal yang lengkap tetapi menyimpan data pelanggan dan berkas terenkripsi | Tidak disimpan; yang tersimpan sha256, nama, ukuran, laporan, draf | Pemilik | kolom `berkas_path` di `unggahan_workbook` |
| Q-49-07 | Orang lab mengetik rumus di **Excel berbahasa apa**: nama fungsi Inggris (`IF`, `VLOOKUP`) atau lokal, pemisah argumen `,` atau `;`, desimal `.` atau `,`? | Menentukan apakah editor menerima rumus yang ditempel dari Excel lab apa adanya | Editor memakai nama fungsi Inggris, pemisah `,`, desimal `.` di dalam rumus (sama dengan mesin); tempel dari Excel berlokal lain diterjemahkan bila jawabannya "lokal" | Pemilik, Lab | ST-T-131 (bilah rumus) |
| Q-49-08 | **Editor rumus di tablet**: penuh (sunting rumus) atau hanya tinjau, pratinjau, dan pengesahan? | Menyunting rumus panjang dengan jari rawan salah ketik; menentukan ST-T-139 | Tablet bisa menyunting, dengan konfirmasi tambahan sebelum Ajukan | Pemilik | ST-T-139 |
| Q-49-09 | **Distribusi dan pembaruan Studio**: Windows (installer? pembaru sendiri dengan URL manifest terpisah?) dan tablet Android (APK sideload; HyperOS memblokir `adb install`, `_agents_workspace/…/00-status.md`) | Studio tidak boleh memakai pembaru aplikasi lapangan; tanpa jalur pembaruan, versi lama tertinggal di perangkat | Pasang manual sampai dijawab; server menolak versi Studio yang terlalu lama (`426 klien_kedaluwarsa`) | Pemilik | rilis S1 ke luar pemilik |
| Q-49-10 **[TERJAWAB]** | Agregasi budget (u_c, Welch–Satterthwaite, k), lantai CMC, dan PASS/FAIL tetap di kelas bersama (`AGENTS.md` §Profil kalibrasi). Apakah Lab perlu **mengubah bagian itu per alat dari Studio** (contoh: penyebut veff master Volumetric hanya `K43`, k tetap 2 seperti Viscometer)? | Kalau ya, itu perubahan aturan `AGENTS.md` yang butuh keputusan baru pemilik; kalau tidak, Studio cukup menyetor komponen + parameter `k_tetap` | **Jawaban pemilik (K-49-07, 9 Okt 2026): ya.** Agregasi, lantai CMC, dan PASS/FAIL ikut bisa disunting per alat di Lembar Hitung; blok bawaan setara `GumCalculator`, `GumCalculator` jadi pengawas (PERINGATAN `agregasi_beda_dari_gum`), versi pertama tetap lewat paritas, kesalahan master tidak ditiru otomatis. `AGENTS.md` §Profil kalibrasi diubah; ADR-49-08 ditulis ulang. Usulan lama (agregasi tetap di kelas bersama) dicabut | Pemilik, Manajer Teknis | — (terjawab) |
| Q-49-11 | Mohon **`.xlsm` asli per alat** untuk 39 dari 52 folder yang CSV ekspornya tidak memuat rumus, dan urutan penyerahannya (gelombang S6). Minimal Volumetric (Fixed + Graduated) untuk pilot | Peta sel Lembar Hitung yang akurat butuh `.xlsm` asli; alamat CSV bergeser 1–3 baris | Lembar Hitung v1 tetap boleh ditulis dari profil PHP untuk paritas; `.xlsm` dipakai untuk peta sel dan impor | Pemilik | S0c, S5 impor, S6 per alat |
| Q-49-12 | Tabel `calibration_capabilities` (pita CMC lampiran LK-285-IDN) hari ini bisa ditambah/diubah langsung di panel Filament (`CalibrationCapabilityResource.php:121-122`). Di Studio dibuat **baca saja** dan perubahan lewat alur versi (lapis 1, `AGENTS.md` §Olah data butir 1 & 3). Setuju? Panel Filament ikut dikunci? | Perubahan pita CMC tanpa simulasi melanggar butir 3 dan bisa menggeser angka cetak | Studio baca saja; panel tidak diubah oleh paket ini (keputusan terpisah) | Pemilik, Manajer Teknis | kelas `calibration_capabilities` di registry |
| Q-49-13 | **Cache tablet**: boleh menyimpan baris berisi data pribadi (pelanggan, pengguna) di penyimpanan tablet untuk baca offline, atau hanya di memori selama aplikasi terbuka? | Tablet bisa hilang; data pelanggan di disk perangkat bergerak | Hanya isi Data Acuan dan Lembar Hitung (tanpa data pribadi) yang di-cache di disk; baris Kelola Data hanya di memori | Pemilik | NFR-STU-08 |
| Q-49-14 | Saat impor atau menyunting, siapa yang memutuskan sebuah kejanggalan master termasuk **kerusakan salin-tempel** (dihitung benar + catatan selisih) atau **kejanggalan metode** (ditiru + pertanyaan lab)? | `AGENTS.md` §Aturan yang Lahir dari Kesalahan Nyata membedakan keduanya; salah golongan menggeser angka cetak atau meniru kesalahan | Master Data mengusulkan golongannya di draf (catatan penyimpangan); pengesah memutuskan saat mengesahkan; kasus ragu → pertanyaan lab bernomor | Manajer Teknis | pengajuan versi yang membawa catatan penyimpangan |
| Q-49-15 | (Diangkat 13 Okt 2026 dari spike S0a.) Spike menolak `STDEV`/`STDEV.S` dan `TINV`/`T.INV.2T` bawaan mesin di Lembar Hitung dan menggantinya dengan `SIDIK.STDEV` dan `SIDIK.KFAKTOR` (`docs/sidik-studio/03-SDD.md` §6.4 butir 1 dan 3). Rumus master yang **ditempel atau diimpor dari Excel** memakai fungsi itu (21 sel `TINV` di CSV master, `06-Matriks-Paritas-Alat.md` baris 26). Saat tempel/impor: **diterjemahkan otomatis** (tercatat) atau **ditolak** dan disunting tangan? | PRD menyebut impor membaca rumus "apa adanya"; terjemahan otomatis mengubah teks rumus master, penolakan menambah kerja per alat dan membuka salah ketik | Terjemahkan otomatis hanya bentuk yang setara: `STDEV(r)`/`STDEV.S(r)` → `SIDIK.STDEV(r)`, `TINV(0.05; x)`/`T.INV.2T(0.05; x)` → `SIDIK.KFAKTOR(x)` (setara untuk x ≥ 1); setiap terjemahan tercatat per sel di laporan impor beserta rumus aslinya; bentuk lain ditolak dengan pesan | Pemilik | ST-T-131 (tempel), impor S5 (FR-STU-160–165) |
| Q-49-16 | (Diangkat 13 Okt 2026 dari spike S0a.) Dengan `SIDIK.STDEV` + `SIDIK.KFAKTOR`, 116 sel keluaran × 3 sesi uji Volumetric Fixed **identik bit** dengan PHP. `docs/sidik-studio/01-PRD.md` §5 menyebut syarat 1 paritas diperketat menjadi identik bit saja bila spike menunjukkan semua identik bit. Diperketat sekarang untuk semua alat, hanya untuk Volumetric Fixed, atau tetap ≤ 1·10⁻¹² relatif sampai lebih banyak alat terbukti? | Bukti baru dari satu keluarga alat dan sesi uji (1 contoh seeder + 2 rekaan), bukan semua sesi tersimpan; daftar putih memuat fungsi dan pola yang belum diuji spike (`SUMPRODUCT`, `HLOOKUP`, larik `MATCH(TRUE,…)`). Gerbang longgar bisa menyembunyikan selisih; gerbang ketat bisa menahan alat karena bit terakhir urutan penjumlahan | Tetap ≤ 1·10⁻¹² untuk semua alat; laporan paritas tetap memisahkan identik bit dari yang hanya dalam toleransi, dan untuk Volumetric Fixed v1 (ST-T-100) setiap sel yang tidak identik bit dijelaskan di laporan | Pemilik, Manajer Teknis | definisi gerbang paritas ST-T-099, ST-T-100 |

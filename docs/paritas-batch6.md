# Paritas aplikasi lawan Excel: batch alat ke-6 (Height Gauge, Differential Pressure, Vacuum Gauge)

Dibuat 26 Nov 2026 (cabang `feat/paritas-batch6`, dari `main` 6361f281). Tidak ada kode aplikasi (`app/`) yang diubah. Dokumen ini hanya melaporkan; selisih = KONFLIK untuk Manajer Teknis (TM), tidak diperbaiki dan tidak dipilih agar tes lulus. Pola dan istilah sama dengan `docs/paritas-analitik-batch2.md` dan `docs/paritas-micrometer-ph.md`.

Label: **SAMA** = aplikasi = Excel dalam toleransi bertulis, dibuktikan tes yang dijalankan. **SELISIH** = beda yang terukur (nomor `B6-n`, tes memakai `#[Group('menunggu-keputusan-tm')]` dan mengunci perilaku sekarang). **MENUNGGU** = butuh keputusan orang atau bukti yang belum ada. Status "TERBUKTI" per alat bukan diputuskan di sini.

## 1. Ringkasan

| Alat | Hasil | SAMA | SELISIH | MENUNGGU |
|---|---|---|---|---|
| Height Gauge (mm, 10 titik, 600 mm) | Semua tahap SAMA kecuali komponen drift; U95 aplikasi 0,0154996 lawan Excel 0,0156680 mm, tercetak `0.015` lawan `0.016`, dan selisih itu habis dijelaskan B6-1 | 17 | 3 (B6-1..B6-3) | 5 (M-1..M-5) |
| Differential Pressure | DILEWATI: tidak ada bukti tambahan yang tidak menggandakan (bagian 5) | - | - | - |
| Vacuum Gauge | DILEWATI: tidak punya workbook sendiri, tercakup lewat DRUCK13G jenis vakum (bagian 6) | - | - | - |

Dua alat terakhir dilewati dengan alasan di bagian 5 dan 6; tidak ada tes baru untuk keduanya.

B6-1 menggeser angka cetak U dan v_eff, dan aplikasi TIDAK menahan sertifikat karenanya. Faktanya ditulis di bagian 3, "Status B6-1 terhadap sertifikat"; keputusan menahan atau menarik ada pada pemilik proyek dan Manajer Teknis, bukan di dokumen ini.


## 2. Cara membaca, menjalankan, dan menghasilkan ulang

- Tes: `tests/Feature/Paritas/ParitasHeightGaugeTest.php` (18 kasus, 856 assertion di SQLite, dijalankan 26 Nov 2026 setelah perbaikan temuan peninjau). Jalankan: `php vendor/phpunit/phpunit/phpunit --configuration=phpunit.xml tests/Feature/Paritas/ParitasHeightGaugeTest.php`. Gate MySQL penuh BELUM dijalankan di sini; CI yang menjalankan.
- Fixture: `tests/Fixtures/paritas/height-gauge.json`, dibangun `docs/skrip/gen-fixture-paritas-batch6.py` (jangan disunting tangan). Tiap nilai Excel membawa alamat sel (`SHEET!SEL`); pesan galat tes menyebut sel itu. Workbook dibuka HANYA di memori dengan sandi dari pemilik (env `SIDIK_MASTER_SANDI`); fixture tanpa nama/alamat pelanggan, nomor seri, nomor sertifikat, nomor order, atau nama teknisi.
- Workbook: `Master olda Height Gauge 600 mm 2026.xlsm`, SHA-256 `9192f2da5b742a3c1bb986b1b3ffbfc63b7c41c58f2f30505c59b5333f3c9ff6` (folder `Downloads\EXEL CALIBRATION SIDIK`). Rekonstruksi rantai `PERHITUNGAN U95%` dari sel komponen mereproduksi nilai-cache dengan selisih relatif maksimum 2,2e-14. Generator berhenti bila rekonstruksi meleset di atas 1e-11 (uc, v_eff, ui, titik, sel dasar), dan di atas 1e-9 untuk `k`, U, angka cetak U, dan komponen drift `K10` (`TINV` Excel mendekati kuantil t; X11 terbaca pada resolusi mikrodetik).
- Toleransi tes: dari `tests/Concerns/MenegakkanParitas.php` (relatif 1e-11, lantai mutlak 1e-12; `k` mutlak 1e-9; U relatif 1e-9). Tidak ada yang dilonggarkan untuk batch ini.
- Titik panggil (tanpa HTTP): `HeightGaugeCalculator::hitungSesi()` (budget aplikasi privat, jadi dibaca dari hasilnya) dan `HeightGaugeProfile::hitungPerGrup()` (fungsi yang sama dengan preview, simpan, dan `HitungUlangSesi`). Masukan disusun lewat `HeightGaugeMentah::blokSesi/keMm/rataSuhuRuang`, seperti jalur produksi.
- Angka cetak: renderer produksi `TataLetakSertifikat::formatAngka` dengan format dari `HeightGaugeProfile::formatKolomSertifikat`; teks sisi Excel adalah EMULASI generator dari format sel workbook, bukan tampilan Excel (lihat Batas bukti).

### Jebakan `NOW()` pada komponen drift

`PERHITUNGAN U95%!K10` memakai `DATABASE!X11 = NOW()`, jadi nilai-cache U95 di workbook tumbuh tiap dihitung ulang. Acuan yang sah hanya nilai-cache pada saat X11 itu tersimpan (`2026-06-11T15:55:46,48`, umur 153,66373240740742 hari sejak 2026-01-09). Aplikasi diberi tanggal sama dengan X11. `umurStandarHari()` memakai `getTimestamp()` (detik bulat), jadi pecahan 0,48 s terpotong: umur aplikasi = umur Excel - 0,48/86400 hari, dan tes memakai ekspektasi itu tanpa melonggarkan toleransi. Jalur profil menerima tanggal `Y-m-d`, jadi U95-nya dibandingkan ke `hitungSesi()` pada tanggal yang sama, bukan ke Excel.

## 3. Height Gauge, rincian per butir

Sesi contoh workbook: 10 titik 25-600 mm (satu nominal Caliper Checker per titik, tiga pembacaan), blok Evaluation 10 pembacaan di 600 mm (STDEV 0,0193 mm, jadi rumus keterulangan TERBUKTI di sini, tidak seperti Dial), suhu ruang 20,2 / 20,3 C (rata-rata 20,25), paralelisme tiga bacaan 0 / 0,001 / 0,002 mm.

| # | Butir | Sel Excel | Status | Bukti dan catatan |
|---|---|---|---|---|
| H-1 | Suhu ruang rata-rata, kapasitas, resolusi | masukan `INPUT DATA!H21`, `E15`, `E16`; acuan `PERHITUNGAN!G14`, `G8`, `G9` | SAMA | `test_suhu_pra_evaluasi_kapasitas_dan_resolusi_cocok_excel` (kapasitas dan resolusi diketik dari `E15`/`E16`, dibanding ke `G8`/`G9`) |
| H-2 | Blok Evaluation 10 bacaan dan STDEV sampel | `PERHITUNGAN!C30:M30`, `N30` | SAMA | idem |
| H-3 | Bacaan paralelisme dan Max, Min | `PERHITUNGAN!I23`, `I24` | SAMA | idem dan B6-2 |
| H-4 | Panjang sensitivitas = nominal terbesar sesi (600 mm) | `PERHITUNGAN!C65` | SAMA | idem (berbeda dari Dial Indicator, P-32) |
| H-5 | Konstanta: geometri 0,0005, meja granit 0,0044, vi rect dan normal, theta (alpha, delta alpha, dan pembagi muai sqrt 6 dipindah ke M-3, MENUNGGU) | `PERHITUNGAN U95%!K11`, `K12`, `PERHITUNGAN!O35` | SAMA | `test_konstanta_tabel_standar_dan_caliper_checker_cocok_sel_workbook` |
| H-6 | Konstanta drift (0,02 mm dan 0,00025 mm/mm) tertulis di rumus `K10` | `PERHITUNGAN U95%!K10` | SAMA | idem (dicocokkan ke teks rumus; konstanta tidak punya sel sendiri) |
| H-7 | U95 Caliper Checker 4,1 um, u standar = U95/2, tanggal kalibrasi standar 2026-01-09 | `Std_CaliperCek!J19`, `DATABASE!W13` | SAMA | idem |
| H-8 | Nilai terkoreksi sepuluh nominal Caliper Checker | `Std_CaliperCek!F10:F19` | SAMA | idem |
| H-9 | Per titik (10): nominal, total nominal, standar terkoreksi, rata-rata, koreksi, STDEV, tiga pembacaan | `PERHITUNGAN!H`, `Y`, `Z`, `AA` | SAMA | `test_titik_total_nominal_rata_rata_standar_koreksi_dan_stdev_cocok_excel` |
| H-10 | Sembilan komponen: urutan, `ci`, `vi`, serta `ui` dan `ui*ci` untuk delapan komponen non-drift | `PERHITUNGAN U95%` baris 5-13 | SAMA | `test_sembilan_komponen_budget_cocok_excel_kecuali_ui_drift` |
| H-11 | Umur standar pada X11 | `DATABASE!X11 - W13` | SAMA | `test_umur_standar_pada_x11_cocok_hitung_ulang_cache_excel` (pecahan detik terpotong dihitung terbuka) |
| H-12 | Agregat uc, v_eff, k, U (tanpa lantai CMC; `AA19` kosong) dari komponen Excel | `AA14:AA20` | SAMA | `test_agregasi_uc_veff_k_dan_u95_cocok_excel_dari_komponen_excel` |
| H-13 | Jalur profil `hitungPerGrup()`: titik ukur, rata-rata, koreksi, error, STDEV, uc, v_eff, k, U (U terhadap `hitungSesi()` pada tanggal sama) | | SAMA | `test_jalur_profil_hitung_per_grup_cocok_excel_dan_hitung_sesi` |
| H-14 | Titik yang tercetak: 1, 6, 10. Excel menyembunyikan baris `25:28` dan `30:32` lembar SERTIFIKAT | `SERTIFIKAT!24:33` | SAMA | `test_titik_yang_dicetak_sama_dengan_baris_yang_tampil_di_sertifikat_excel` |
| H-15 | Teks cetak standar, UUT, koreksi (10 baris x 3 kolom, format sel dan teks) | `SERTIFIKAT!D24:L33` | SAMA (teks sisi Excel = emulasi generator, lihat Batas bukti) | `test_angka_cetak_titik_sama_dengan_sertifikat_excel`; tampil: 25,00/24,99/0,01, 300,00/299,98/0,02, 600,00/599,96/0,03 |
| H-16 | Vonis paralelisme "Good" di kaki sertifikat | `SERTIFIKAT!H38`, `PERHITUNGAN!K25` | SAMA | `test_vonis_paralelisme_tercetak_sama_dengan_sertifikat_excel` |
| H-17 | Format U95 `0.000` | `SERTIFIKAT!J34` | SAMA (teks `0.016` sisi Excel = emulasi generator, lihat Batas bukti) | `test_selisih_b6_1_...` (format) dan tes angka cetak |
| B6-1 | **Pembagi umur drift**: aplikasi /365, Excel `K10` /12 | `PERHITUNGAN U95%!K10`, `U10` | SELISIH | `test_selisih_b6_1_drift_pembagi_365_aplikasi_12_excel`. ui drift Excel 0,0012568; aplikasi 4,13e-5 (rasio 365/12 x umur Excel/umur aplikasi). Efek pada agregat bila komponen lain dari Excel: uc 0,0075111 -> 0,0074053; v_eff 20,474 -> 19,350 (floor 20 -> 19); k 2,08596 -> 2,09302; U 0,0156680 -> 0,0154996 mm; cetak `0.016` -> `0.015`. Arah TIDAK AMAN (U lebih kecil) dan alat ini tidak punya lantai CMC. Kode: `HeightGaugeCalculator::PEMBAGI_UMUR_HARI`, "paket keputusan butir 5, 16 Sep 2026, disetujui pemilik proyek; paraf Manajer Teknis menyusul". Status terhadap sertifikat: lihat "Status B6-1 terhadap sertifikat" di bawah tabel |
| B6-2 | **Paralelisme**: aplikasi `Max - Min` = 0,002 mm; Excel `J24 = STDEV(Max; Min)` = 0,0014142 mm | `PERHITUNGAN!J24` | SELISIH | `test_selisih_b6_2_...`. Vonis sama ("Good"; batas 0,01 mm tertulis di rumus `K25`, dicocokkan ke teks rumus). Sengaja di kode, mengikuti jawaban lab §5 (16 Sep 2026). Sertifikat hanya mencetak vonis. Jejak audit (`jejak_titik`) hanya mencetak angka aplikasi 0,002 (Max - Min) dan vonis; angka master 0,001414 dan alasan bedanya TIDAK tercatat di jejak (lihat M-2) |
| B6-3 | **Suku termal standar terkoreksi**: Excel hanya baris titik 1 (`M35:T35`), aplikasi sepuluh titik | `PERHITUNGAN!M35:T35`, `Y` | SELISIH (beda struktur, tanpa efek angka di data ini) | `test_selisih_b6_3_...`. Pada data ini delta theta (`T35`) dan delta alpha (`R35`) nol, dan empat suku deformasi `U:X` nol, jadi Y = H di kedua sisi. Sisi aplikasi didemonstrasikan: suhu UUT 0,5 C di atas suhu standar menggeser semua titik. Sisi Excel tidak punya sesi seperti itu |
| B6-4 | Rantai penuh: aplikasi U95 lawan Excel | `AA14:AA20` | Konsekuensi B6-1, bukan selisih baru | `test_selisih_b6_4_rantai_penuh_u95_dijelaskan_oleh_b6_1`: agregasi komponen Excel dengan hanya drift diganti = hasil `hitungSesi()` (uc, v_eff, k, U). Bila merah sementara B6-1 hijau, ada selisih lain yang belum bernomor |
| M-1 | **Teks cetak `k`**: aplikasi mencetak `2` (format `0`, bersandar pada render PDF master); Excel `SERTIFIKAT!O35` berformat `General` dan cache workbook tidak menyimpan teks tampilan | `SERTIFIKAT!O35` | MENUNGGU | `test_menunggu_teks_cetak_k_...`. Nilai sel = k hitung (SAMA). Yang tidak terbukti dari workbook: teks tampilannya |
| M-2 | **Jejak audit tidak menyebut selisih dari master**, dua tempat. (a) B6-1, pembagi drift: docblok `HeightGaugeCalculator::budget` menulis "Pembagi master tetap dicetak di jejak audit", tetapi baris jejak (`barisAudit` + `jejak_titik`) hanya memuat `Drift standard` tanpa K10, /12, atau /365. (b) B6-2, paralelisme: `jejak_titik` hanya mencetak `paralelisme sesi 0.002 mm (Good)` (Max - Min), tanpa angka master 0,001414 (`STDEV(Max; Min)`) dan tanpa alasan bedanya. Orang yang membuka jejak sesi tidak bisa tahu dari jejak saja bahwa dua angka itu beda dari master | AGENTS.md Olah data butir 4 | MENUNGGU | (a) `test_menunggu_jejak_audit_tidak_menyebut_pembagi_master_drift`; (b) `test_menunggu_jejak_audit_paralelisme_hanya_max_min_tanpa_angka_master`. Keduanya mengunci keadaan sekarang. Bila catatannya ditambahkan, tes yang bersangkutan merah dan dokumen ini diperbarui |
| M-3 | **Pembagi muai `sqrt(6)` dan dua nilai alpha**, pertanyaan lab terbuka. Pembagi komponen koefisien muai `N9 = SQRT(6)` (menurut `docs/pertanyaan-lab-height-gauge.md` §2 distribusinya berlabel `rect.`; komponen rectangular lain, `N11`, memakai sqrt 3). Alpha 1,2e-6 /C di `P35`/`Q35` (suku koreksi titik) lawan delta alpha 2e-6 /C di `S24` = 2 x `R24` (budget komponen muai, ci suhu). Dulu bagian dari H-5 berlabel SAMA; dipisah supaya "nilai sama dengan sel" tidak terbaca sebagai "metode terbukti benar" | `PERHITUNGAN U95%!N9`, `N11`; `PERHITUNGAN!P35`, `Q35`, `R24`, `S24` | MENUNGGU | `test_menunggu_m3_pembagi_muai_sqrt6_dan_dua_nilai_alpha_dikunci_apa_adanya`. Aplikasi menyalin keduanya persis (nilai = sel). Yang belum terjawab: mana yang mengikat (`docs/pertanyaan-lab-height-gauge.md` §2 dan §3). Efek ke U pada data ini kecil: komponen muai menyumbang 1,5e-8 (`PERHITUNGAN U95%!AA9`) dari jumlah (ui*ci)^2 5,6417e-5 (`AA14`), 0,027 %, dan suku koreksi muai titik bernilai nol (B6-3, Batas bukti). Pada sesi lain angkanya belum diukur |
| M-4 | **Jalur `HitungUlangSesi` dan `CalibrationValidator` untuk Height Gauge** | - | MENUNGGU | tidak diuji di batch ini: tes memanggil kalkulator dan profil langsung (tanpa HTTP, tanpa menyimpan sesi ke DB). Itu ranah tes yang butuh DB dan gate MySQL. Bahwa preview, simpan, dan hitung ulang memakai fungsi yang sama (`hitungPerGrup()`) diadu di H-13; bahwa validator dan `HitungUlangSesi` benar-benar memanggilnya untuk Height Gauge tidak diuji di sini |
| M-5 | **Tanggal terbit dan kondisi lingkungan yang tercetak di sertifikat** | `SERTIFIKAT` kepala dan kaki | MENUNGGU | tidak diuji di batch ini; hanya tabel hasil, U95, `k`, satuan, dan vonis paralelisme yang diadu (H-14..H-17, M-1) |

### Status B6-1 terhadap sertifikat (FAKTA, bukan keputusan)

Ditulis atas temuan peninjau PR #312. Dokumen ini tidak memutuskan apa pun dan tidak mengubah perilaku aplikasi.

- Aplikasi TIDAK menahan penerbitan karena B6-1. `test_selisih_b6_4_rantai_penuh_u95_dijelaskan_oleh_b6_1` mengunci `boleh_terbit === true` untuk sesi master yang sah. Pelepasan itu mengikuti paket keputusan butir 5, 16 Sep 2026 (disetujui pemilik proyek; paraf Manajer Teknis menyusul).- Efek B6-1 pada sesi contoh: U tercetak `0.015` (aplikasi) lawan `0.016` (Excel, `SERTIFIKAT!J34`); v_eff 20,474 menjadi 19,350, jadi floor 20 menjadi 19; k 2,08596 menjadi 2,09302. Arah U lebih kecil, tanpa lantai CMC. Pada sesi lain, selisihnya tergantung umur standar dan komponen lain; belum diukur.
- Sertifikat Height Gauge yang SUDAH terbit lewat jalur ini BELUM diperiksa: sesi ini tidak punya akses produksi. Jumlah dan lingkupnya tidak diketahui dari dokumen ini.
- Menahan penerbitan baru, menarik atau menerbitkan ulang sertifikat yang sudah terbit, atau mempertahankan keadaan sekarang adalah keputusan pemilik proyek dan Manajer Teknis.
### Batas bukti (jangan dibaca lebih dari ini)

- Teks cetak sisi Excel (H-15 `SERTIFIKAT!D24:L33`, H-17 `J34`) di fixture adalah EMULASI generator (`teks_excel()`: pembulatan ROUND_HALF_UP pada 15 angka penting dari nilai-cache, dengan format sel), bukan tampilan Excel: cache workbook tidak menyimpan teks tampilan. Kesetaraan "teks cetak aplikasi = teks cetak Excel" karena itu terbukti sejauh emulasi itu setia pada pembulatan Excel. Format `General` (teks `k`, M-1) sengaja ditolak generator, jadi teks itu tidak diemulasikan sama sekali.
- Satu sesi, satu workbook; satu nominal Caliper Checker per titik. Sesi dengan beberapa slot nominal per titik tidak terbukti oleh data ini.
- Suku koreksi muai (delta theta, delta alpha titik) dan empat suku deformasi `U35:X35` bernilai nol pada data ini, jadi jalurnya TIDAK terbukti (hanya konstanta nol di kedua sisi).
- Tanggal kalibrasi `INPUT DATA!O15/O16` berisi TEKS ("05 Mei 2026"), bukan tanggal (`D76` menjadi #VALUE!); umur drift karena itu diadu pada `DATABASE!X11`, bukan pada tanggal sesi.
- `SERTIFIKAT!F18:M20` (tabel kesejajaran muka ukur) adalah #REF! di workbook; aplikasi tidak mencetak tabel itu, jadi tidak ada yang diadu.
- Tujuh dari sepuluh baris tabel hasil (`SERTIFIKAT!25:28`, `30:32`) tersembunyi di Excel; angka cetaknya ikut diadu (bukti pembulatan sama), tetapi yang tercetak hanya titik 1, 6, 10.
- Pembulatan MySQL (`decimal(20,8)`) tidak disimulasikan: yang diuji adalah jalur hitung dan render dengan float penuh.

### Temuan di luar tugas (tidak diubah)

1. Docblok kelas `HeightGaugeCalculator` (bagian "Yang DITIRU walau janggal", sekitar baris 87-102) usang: menulis pembagi drift `/12` dipertahankan dan paralelisme `STDEV(Max; Min)` ditiru, padahal kode sekarang `/365` dan `Max - Min` (16 Sep 2026). Angka di docblok itu sendiri (0,0156680 -> 0,0154996) cocok dengan tes ini.
2. `umurStandarHari()` memotong pecahan detik; beda 5,6e-6 hari dari Excel, tanpa efek angka cetak.
3. Vendor `main` tidak sinkron dengan `composer.lock` (kelas `PhpOffice\PhpSpreadsheet\Cell\Coordinate` tidak ditemukan; tiga kasus Micrometer error di vendor itu). Worktree ini memakai `vendor` hasil salinan dari worktree `sidik-api-paritas-b5` dengan `composer.lock` identik.

## 4. Uji mutasi

Tiga mutasi sementara pada `app/` (dipulihkan dari `HEAD`; `git diff -- app` kosong sesudah tiap putaran, tidak ada yang di-commit), Height Gauge:

- `HeightGaugeCalculator::PEMBAGI_UMUR_HARI` 365 -> 12: tes B6-1 dan B6-4 merah (pembagi aplikasi; floor v_eff 20 lawan 19).
- Komponen resolusi dibagi 2 bukan akar 3: tes komponen budget dan tes agregat/profil merah (uc 0,00726 lawan 0,00741; selisih 1,4e-4).
- `TITIK_DICETAK_SERTIFIKAT` [1, 6, 10] -> [1, 5, 10]: tes titik tercetak merah.

## 5. Differential Pressure: dilewati

Workbook ada (`Master Olda Pressure Differential.xlsm`, varian `Tabel::DIFFERENTIAL`), tetapi bukti app-lawan-Excel untuk varian ini SUDAH ada dan tes baru hanya akan menggandakannya:

- Rantai `PERHITUNGAN FC` dan `PERHITUNGAN U95%` (962 sel keempat master, toleransi 1e-12, mode MASTER dan BENAR): `tests/Unit/TekananMasterTest.php`, di `main`. Nilai harapannya dari `database/data/sesi-master-tekanan.json`, yang dibangun `docs/skrip/gen-tabel-standar-tekanan.py` dari cache workbook yang sama.
- CMC lampiran (no. 26, 0,0093 mbar) lawan sel master: `tests/Feature/TekananCmcTest.php`, di `main`.
- Lembar `SERTIFIKAT` langsung (tabel hasil, U95 `P35`, k `U36`, nilai dan teks tercetak, mode MASTER dan BENAR, termasuk selisih T-2 yang ditahan): `tests/Feature/Paritas/ParitasTekananTest.php`, di `main` (PR #309, MERGED 26 Nov 2026 03:35Z), dengan fixture `tests/Fixtures/paritas/tekanan.json`.
- Jalur sesi ujung ke ujung (preview, simpan, tabel sertifikat): `tests/Feature/TekananSesiTest.php` (varian Differential ada di sana).
- `DifferentialPressureProfile` hanya subclass identitas dari `TekananProfile` (kode, nama CMC, alias, varian, jenis non-vakum, kode IK); semua hitungannya di `TekananCalculator`/`TekananProfile`, yang sudah diadu di atas.

Membuat fixture lembar `SERTIFIKAT` Differential di cabang ini berarti mengulang fixture #309 (`tekanan.json`, sudah di `main`; cabang ini hanya akan menggandakannya). Yang BELUM ada dan tidak dikerjakan di sini: jalur `HitungUlangSesi`/validator untuk Tekanan (butir PR-9 di `docs/paritas-batch4.md`, butuh DB, ranah gate MySQL).

## 6. Vacuum Gauge: dilewati

Tidak ada workbook sendiri. `VacuumGaugeProfile` memakai varian yang sama dengan Pressure Gauge (DRUCK07G -0,8~2 bar dan DRUCK13G 0~20 bar; `database/data/sesi-master-tekanan.json` hanya memuat empat sesi: `druck07g`, `druck13g`, `spmk`, `differential`). Jenis vakum diadu lewat sesi `druck13g` (`jenis_tekanan = vakum`): rantai di `TekananMasterTest` (termasuk `test_t11_druck13g_vakum_membaca_kolom_u95_hanya_di_mode_master`) dan lembar `SERTIFIKAT` di `main` lewat PR #309 (MERGED 26 Nov 2026; butir PR-6, selisih ditahan T-2 dan T-11). CMC vakum diadu `TekananCmcTest` (kasus `07G vakum` dan `13G vakum` memakai `VacuumGaugeProfile`). Batas bukti: sesi tersimpan DRUCK07G di `sesi-master-tekanan.json` berjenis `non_vakum`, jadi 07G sebagai Vacuum Gauge hanya punya bukti CMC, tidak punya sesi hitung acuan dari workbook. Tes baru di sini akan menggandakan, jadi dilewati.

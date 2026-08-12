# Audit Sumber — Conductivity & Refractometer

**TAHAP 1 (inventaris & error sumber) + status TAHAP 2.**
Tanggal: 11 Agustus 2026. Belum ada kode produksi yang ditulis dari audit ini.

> **Revisi 12 Agustus 2026** — isi versi pertama dicek ulang lawan repo. Tiga
> temuan: master `.xlsm` Refractometer tidak ada di repo (§2.4), `formulaVersion`
> ternyata **sudah** terpasang dan versi lama salah menyebutnya belum ada (§3),
> dan hitungan `#REF!` Refractometer dikoreksi 23 → 22 (§2.2).
>
> **Susulan sore hari** — dua koreksi atas revisi pagi, keduanya karena revisi itu
> berhenti bertanya terlalu cepat: (a) "16 baris tanpa versi rumus" bukan
> keputusan yang perlu diambil lab, melainkan bug seeder, dan sudah diperbaiki
> (§3); (b) hilangnya `.xlsm` Refractometer sempat ditulis seolah buktinya ikut
> hilang — padahal alamat sel & ekspresi Excel-nya tercatat di
> `RefractometerProfile.php` (§2.2, §2.4).

Audit ini **audit selisih**, bukan audit dari nol. Kedua alat sudah punya modul
jalan (`ConductivityProfile`, `RefractometerProfile`) dengan golden test yang
diadu ke master. Yang diperiksa di sini: mana yang sudah punya bukti formula,
mana yang masih inferensi, dan mana yang error sumber.

---

## 1. Inventaris berkas

### Conductivity — `Project-PT-Sidik/Master Data Conductivity/`

`.xlsm` ada langsung di folder itu; kedelapan CSV-nya ada di subfolder
`conductivity_csv 2/`.

| Berkas | Tipe | Fungsi | Source of Truth? | Memuat Formula Asli? | Catatan / Error |
|---|---|---|---|---|---|
| `Master Olah Data_Conductivity.xlsm` | Excel master | Seluruh olah data | **YA** | Ya | **Terenkripsi** (`CDFV2 Encrypted`). Pernah dibuka 10 Agt (commit `3d1ce00`) |
| `01 - FORM VALIDASI.csv` | CSV nilai | Checklist validasi | Tidak | Tidak | — |
| `02 - DATABASE.csv` | Tabel referensi | Larutan standar, koef. suhu, CMC | Pendukung | Tidak | Dipakai sebagai tabel lookup |
| `03 - INPUT DATA.csv` | CSV input | Isian operator | Pendukung | Tidak | Sumber blok **Resolusi Alat** |
| `04 - PERHITUNGAN.csv` | CSV hasil | Nilai antara | Tidak | Tidak | Ekspor NILAI |
| `05 - nilai koefisien sensitifitas.csv` | Tabel referensi | ci per komponen | Pendukung | Tidak | — |
| `06 - PERHITUNGAN U95%.csv` | CSV hasil | Budget ketidakpastian | Tidak | Tidak | Bersih dari error |
| `07 - SERTIFIKAT STYLE 1.csv` | Sertifikat | Keluaran µS·µS·mS | Tidak | Tidak | **3× `#REF!`** — lihat §2.1 |
| `08 - SERTIFIKAT STYLE 2.csv` | Sertifikat | Keluaran µS·mS·mS | Tidak | Tidak | **3× `#REF!`** — lihat §2.1 |

### Refractometer — `Project-PT-Sidik/Refractometer_CSV 2/`

| Berkas | Tipe | Fungsi | Source of Truth? | Memuat Formula Asli? | Catatan / Error |
|---|---|---|---|---|---|
| `Master Olah Data_Refractometer.xlsm` | Excel master | Seluruh olah data | **YA** | Ya | **TIDAK ADA DI REPO** — lihat §2.4 |
| `INPUT DATA.csv` | CSV input | Isian operator | Pendukung | Tidak | — |
| `DATABASE.csv` | Tabel referensi | Standar & thermohygro | Pendukung | Tidak | — |
| `Tab Konversi Temperatur.csv` | Tabel lookup | Koreksi suhu n20D | Pendukung | Tidak | — |
| `PERHITUNGAN.csv` | CSV hasil | Nilai antara | Tidak | Tidak | — |
| `PERHITUNGAN U95%.csv` | CSV hasil | Budget ketidakpastian | Tidak | Tidak | **22× `#REF!` + 18× `#DIV/0!`** — lihat §2.2 |
| `SERTIFIKAT.csv` | Sertifikat | Keluaran akhir | Tidak | Tidak | **1× `#REF!`** — sudah terjawab, §2.3 |
| `FORM VALIDASI.csv` | CSV nilai | Checklist validasi | Tidak | Tidak | — |

**Catatan lintas alat:** `.xlsm` yang ada di repo terenkripsi (Conductivity
terverifikasi `CDFV2 Encrypted`; Refractometer tidak bisa diperiksa, §2.4).
Semua CSV adalah ekspor **nilai** — tidak satu pun menyimpan formula Excel.
Sesuai aturan anti-halusinasi, rumus apa pun yang diturunkan dari CSV berstatus
`INFERENSI — BELUM DIVERIFIKASI` sampai master-nya dibuka.

---

## 2. Error sumber yang ditemukan

### 2.1 Conductivity — baris satuan sertifikat rusak di KEDUA style

`07 - SERTIFIKAT STYLE 1.csv` **baris 24** dan `08 - SERTIFIKAT STYLE 2.csv`
**baris 24**, tiga sel:

```
Standard Value | Unit Under Test | Correction | U95%
    #REF!      |      #REF!      |   #REF!    | ± mS
```

Baris itu adalah **baris satuan** di bawah header tabel sertifikat. Tiga dari
empat kolomnya putus; hanya U95% yang satuannya selamat.

**Dampak — dan ini bukan kosmetik.** Master-nya sendiri tidak bisa menyatakan
satuan per kolom untuk style mana pun. Jadi pertanyaan "style 1 itu µS atau
mS" **tidak bisa dijawab dari berkas ini** — dan itulah sebabnya lab harus
menjelaskannya lisan, lalu menyatakan bahwa label di file-nya sendiri salah.

**Status:** `ERROR SUMBER`. Konvensi yang dipakai sistem sekarang berasal dari
arahan lisan lab 11 Agt 2026 (opsi 1 = µS·µS·mS, opsi 2 = µS·mS·mS), bukan
dari sel Excel. Sudah direkam di docblock `ConductivityProfile::styleSertifikat()`.

### 2.2 Refractometer — seluruh blok U95% tidak menghasilkan angka

`PERHITUNGAN U95%.csv`, baris 38–51 dan 97:

| Baris | Isi | Error |
|---|---|---|
| 38 | Baris header (Component / U / Divisor / vi / ui / ci) | `#REF!` |
| 39 | Ketidakpastian Baku Berat Sukrosa (Timbangan) | `#REF!` |
| 41 | Ketidakpastian Berat Molekul Sukrosa | `#REF!` |
| 44 | Ketidakpastian Baku Pengulangan Pembacaan | `#DIV/0!` |
| 45 | Jumlah | `#REF!` |
| 46 | Ketidakpastian Baku Gabungan, Uc | `#REF!` |
| 47 | Derajat Kebebasan, v_eff | `#REF!` |
| 48 | Faktor Cakupan, k | `#REF!` |
| 49 | Ketidakpastian Bentangan, U = k·Uc | `#REF!` |
| 50 | CMC Laboratory | `#REF!` |
| 51 | Uncertainty sertifikat | `#REF!` |

Total 22 sel `#REF!` di 11 baris (sebagian baris memuat lebih dari satu),
ditambah 18 sel `#DIV/0!`.

**Dampak:** tidak satu pun angka agregat ketidakpastian Refractometer bisa
dibuktikan dari CSV ini. `#DIV/0!` di baris 44 wajar untuk sheet kosong (tidak
ada pengulangan → pembagi nol), tapi `#REF!` di baris lainnya berarti tautan
putus, bukan sheet kosong.

**Status:** `ERROR SUMBER` untuk CSV. Commit `b55585e` (10 Agt) mencatat rumusnya
sudah dibaca langsung dari `.xlsm` — tujuh rumus inti dengan alamat sel, termasuk
`TINV(0.05, veff)` (bukan k=2 bulat), lantai CMC, dan vi per komponen; plus dua
penyimpangan yang dulu ditandai "aneh tapi ikut sheet" (divisor 1 di `U95%!Q9`,
ci = 0,0001 di `U95%!X12`).

**Buktinya ada di repo, bukan cuma di catatan commit.** Ketujuh rumus itu
tercatat lengkap dengan alamat sel DAN ekspresi Excel-nya di docblock
`app/Services/Calibration/Profiles/RefractometerProfile.php` (bagian "Status
pembuktian"), mis. `U95%!AC17 =TINV(0.05,AC16)` dan `U95%!AC20 =MAX(AC18:AF19)`.
Siapa pun bisa baca tanpa buka Excel.

Yang hilang cuma kemampuan **mencocokkan ulang** ke workbook sumbernya (§2.4).

### 2.3 Refractometer — `#REF!` titik ketiga: SUDAH TERJAWAB

`SERTIFIKAT.csv` baris 97. `SERTIFIKAT!C20` menunjuk
`'[3]Input Data Mentah'!T27` — **workbook luar yang tidak ikut terkirim**.

Jadi itu sisa tautan, bukan titik ukur yang hilang. Keputusan lama memakai dua
titik: **benar**, dan sekarang ada buktinya.

**Status:** `TERVERIFIKASI DARI FORMULA EXCEL`. Ditutup.

### 2.4 Refractometer — master `.xlsm`-nya tidak ada di repo

`Master Olah Data_Refractometer.xlsm` **tidak ada di working tree dan tidak
pernah masuk git** (`git log --all --diff-filter=A` untuk pola `*Refractometer*.xlsm`
tidak mengembalikan apa pun). Yang ada di repo cuma dua `.xlsm`: Conductivity dan
pH.

Perlu diletakkan pada porsinya: **hasil bacaannya tidak hilang.** Alamat sel dan
ekspresi Excel ketujuh rumus tercatat di docblock `RefractometerProfile.php`
(§2.2), dan angkanya dikunci golden test lawan sertifikat kertas. Yang tidak bisa
dilakukan sekarang hanyalah **mencocokkan ulang** catatan itu ke workbook
sumbernya.

Berbeda dengan §2.1 dan §2.2 yang errornya ada **di dalam** sumber, yang ini
error **ketersediaan** sumber — dan paling gampang dibereskan: berkasnya tinggal
dimasukkan.

**Status:** `SUMBER TIDAK TERSEDIA` (bukan bukti yang hilang). Perlu dicek ke
pemegang berkas apakah `.xlsm`-nya memang belum pernah dikirim, atau ada tapi
tidak ikut ter-commit — kemungkinan besar karena ukurannya, seperti `.xlsm`
Conductivity yang ada di disk tapi juga belum ter-track git.

---

## 3. Selisih terhadap yang sudah terpasang

| Hal | Status |
|---|---|
| Modul per alat, shared bebas rumus | **Sudah ada** — `app/Services/Calibration/Profiles/` |
| `sourceReference` (alamat sel) | **Sebagian** — ada di docblock, belum jadi field terstruktur |
| `formulaVersion` | **Sudah ada, belum rata** — lihat di bawah |
| `calculationTrace` | **Sebagian** — `type_b_components` menyimpan rincian per komponen |
| Golden test lawan master | **Sudah ada** — `ConductivityBudgetTest`, `SertifikatCocokMasterTest` |
| Presisi desimal | **Sudah ada** — desimal per titik, nilai asli & tampilan terpisah |
| Audit log (siapa input apa) | **Sebagian** — sesi punya teknisi & waktu; versi rumus belum ikut |

**Koreksi 12 Agt 2026.** Versi pertama dokumen ini menulis `formulaVersion`
**"Belum ada"**. Itu salah, dan koreksinya dicatat di sini — bukan ditimpa
diam-diam — karena kesimpulan di bawahnya sempat menyuruh membangun yang sudah
berdiri. Yang sebenarnya terpasang:

- `app/Models/FormulaVersion.php` + tabel `formula_versions` — 5 versi berstatus
  `aktif`, lengkap dengan `nomor_versi`, `ekspresi`, `effective_from/until`
- `uncertainty_calculations.formula_version_id` — **48 dari 64 baris (75%)**
  sudah terpaut ke versi rumus yang menghasilkannya
- `tests/Feature/RumusBerversiTest.php` — 20 test

**Susulan, 12 Agt (sore) — 16 baris itu sudah terjawab, dan bukan keputusan lab.**
Revisi pagi menduga baris tersebut hasil hitungan lama dari sebelum kolomnya
dipasang, lalu menyerahkan pilihan backfill-vs-null ke lab. Dugaan itu salah:
barisnya dibuat **10–11 Agt**, sedangkan versi rumusnya sudah ada sejak **5 Agt**
(`effective_from` 2000-01-01). Jadi bukan data lama.

Sebabnya: kelima seeder (`PhMeter`, `Turbidimeter`, `Chlorine`, `Refractometer`,
`Conductivity`) membuat baris `uncertainty_calculations` **tanpa** menyetel
`formula_version_id`, sementara jalur API sudah menyetelnya. Keenam sesi yang
terdampak semuanya sesi demo bikinan seeder — satu per jenis alat. Tidak ada
satu pun data kalibrasi nyata yang kehilangan ketertelusuran.

Sudah diperbaiki lewat `Database\Seeders\Concerns\MenstempelVersiRumus`.
Diverifikasi dengan menjalankan kelima seeder di dalam transaksi: 16 baris tanpa
versi → **0 dari 64 (100% terpaut)**, lalu di-rollback.

Pelajarannya bukan soal kolom bolong: data demo yang bohong soal kelemahannya
sendiri bikin audit ini menghabiskan satu putaran penuh mengejar kebocoran
ketertelusuran yang tidak pernah ada.

Yang benar-benar belum ada dan layak digarap: **`sourceReference` terstruktur**
(alamat sel Excel). Kolom `formula_versions.sumber` yang ada sekarang isinya
provenance kasar (`"kode"`), bukan alamat sel.

---

## 4. Yang menghalangi, dan pertanyaan yang perlu dijawab

1. **Password `.xlsm` Conductivity.** Master-nya ada tapi terenkripsi
   (`CDFV2 Encrypted`). `msoffcrypto` sudah terpasang, tinggal passwordnya —
   dikirim terpisah, jangan ditempel di repo, log, atau pesan commit. Tanpa itu,
   verifikasi ulang formula Conductivity tidak bisa dilanjutkan.

2. **Berkas `.xlsm` Refractometer (§2.4).** Bukan soal password: berkasnya
   memang tidak ada di repo. Ini **tidak memblokir pekerjaan** — hasil bacaan
   formulanya sudah tercatat di `RefractometerProfile.php` — tapi memblokir
   pencocokan ulang. Perlu dikirim/di-commit kalau pencocokan itu diperlukan
   asesor.

3. **Satuan kolom sertifikat Conductivity (§2.1).** Karena master-nya `#REF!`,
   perlu konfirmasi tertulis dari lab bahwa konvensi lisan 11 Agt itu yang
   dipakai — supaya tidak bergantung pada ingatan percakapan.

4. **Rumus M33 titik 111.** Lab menyatakan "dibikin fix dulu, nanti saya cari
   rumusnya". Belum disentuh, dan sengaja tetap fix.

5. **U95 kelembaban per titik vs per unit.** Tabel sumbernya identik di tiga
   workbook; yang beda satu sel formula (`Refractometer O15` per unit,
   Turbidimeter & Chlorine per titik). Sistem memakai **per titik** — dua dari
   tiga master, dan dua itu yang diadu ke sertifikat kertas dan cocok karakter
   per karakter. Perlu ditegaskan lab mana yang benar.

---

## 5. Yang TIDAK dilakukan

Tidak ada kode produksi yang ditulis dari audit ini, sesuai TAHAP 4. Tidak ada
rumus yang diubah, ditebak, atau diambil dari sumber luar. Error sumber di §2
tidak ditambal dengan asumsi.

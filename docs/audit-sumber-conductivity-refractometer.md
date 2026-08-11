# Audit Sumber — Conductivity & Refractometer

**TAHAP 1 (inventaris & error sumber) + status TAHAP 2.**
Tanggal: 11 Agustus 2026. Belum ada kode produksi yang ditulis dari audit ini.

Audit ini **audit selisih**, bukan audit dari nol. Kedua alat sudah punya modul
jalan (`ConductivityProfile`, `RefractometerProfile`) dengan golden test yang
diadu ke master. Yang diperiksa di sini: mana yang sudah punya bukti formula,
mana yang masih inferensi, dan mana yang error sumber.

---

## 1. Inventaris berkas

### Conductivity — `Project-PT-Sidik/Master Data Conductivity/`

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
| `Master Olah Data_Refractometer.xlsm` | Excel master | Seluruh olah data | **YA** | Ya | **Terenkripsi**. Sudah dibaca sel-per-sel 10 Agt (commit `b55585e`) |
| `INPUT DATA.csv` | CSV input | Isian operator | Pendukung | Tidak | — |
| `DATABASE.csv` | Tabel referensi | Standar & thermohygro | Pendukung | Tidak | — |
| `Tab Konversi Temperatur.csv` | Tabel lookup | Koreksi suhu n20D | Pendukung | Tidak | — |
| `PERHITUNGAN.csv` | CSV hasil | Nilai antara | Tidak | Tidak | — |
| `PERHITUNGAN U95%.csv` | CSV hasil | Budget ketidakpastian | Tidak | Tidak | **23× `#REF!` + 18× `#DIV/0!`** — lihat §2.2 |
| `SERTIFIKAT.csv` | Sertifikat | Keluaran akhir | Tidak | Tidak | **1× `#REF!`** — sudah terjawab, §2.3 |
| `FORM VALIDASI.csv` | CSV nilai | Checklist validasi | Tidak | Tidak | — |

**Catatan lintas alat:** setiap `.xlsm` di sini terenkripsi. Semua CSV adalah
ekspor **nilai** — tidak satu pun menyimpan formula Excel. Sesuai aturan
anti-halusinasi, rumus apa pun yang diturunkan dari CSV berstatus
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

**Dampak:** tidak satu pun angka agregat ketidakpastian Refractometer bisa
dibuktikan dari CSV ini. `#DIV/0!` di baris 44 wajar untuk sheet kosong (tidak
ada pengulangan → pembagi nol), tapi `#REF!` di sepuluh baris lain berarti
tautan putus, bukan sheet kosong.

**Status:** `ERROR SUMBER` untuk CSV. **Namun** rumusnya sudah diverifikasi
langsung dari `.xlsm` pada 10 Agt (commit `b55585e`): tujuh rumus inti punya
alamat sel, termasuk `TINV(0.05, veff)` (bukan k=2 bulat), lantai CMC, dan vi
per komponen. Dua penyimpangan yang dulu ditandai "aneh tapi ikut sheet" ikut
terbukti benar: divisor 1 di `U95%!Q9` dan ci = 0,0001 di `U95%!X12`.

### 2.3 Refractometer — `#REF!` titik ketiga: SUDAH TERJAWAB

`SERTIFIKAT.csv` baris 97. `SERTIFIKAT!C20` menunjuk
`'[3]Input Data Mentah'!T27` — **workbook luar yang tidak ikut terkirim**.

Jadi itu sisa tautan, bukan titik ukur yang hilang. Keputusan lama memakai dua
titik: **benar**, dan sekarang ada buktinya.

**Status:** `TERVERIFIKASI DARI FORMULA EXCEL`. Ditutup.

---

## 3. Selisih terhadap yang sudah terpasang

| Hal | Status |
|---|---|
| Modul per alat, shared bebas rumus | **Sudah ada** — `app/Services/Calibration/Profiles/` |
| `sourceReference` (alamat sel) | **Sebagian** — ada di docblock, belum jadi field terstruktur |
| `formulaVersion` | **Belum ada** |
| `calculationTrace` | **Sebagian** — `type_b_components` menyimpan rincian per komponen |
| Golden test lawan master | **Sudah ada** — `ConductivityBudgetTest`, `SertifikatCocokMasterTest` |
| Presisi desimal | **Sudah ada** — desimal per titik, nilai asli & tampilan terpisah |
| Audit log (siapa input apa) | **Sebagian** — sesi punya teknisi & waktu; versi rumus belum ikut |

Yang diminta prompt tapi belum ada, dan layak digarap: **`formulaVersion`** dan
**`sourceReference` terstruktur**. Sisanya sudah berdiri.

---

## 4. Yang menghalangi, dan pertanyaan yang perlu dijawab

1. **Password `.xlsm`.** Dua master terenkripsi. `msoffcrypto` sudah terpasang,
   tinggal passwordnya — dikirim terpisah, jangan ditempel di repo, log, atau
   pesan commit. Tanpa itu, verifikasi ulang formula Conductivity tidak bisa
   dilanjutkan.

2. **Satuan kolom sertifikat Conductivity (§2.1).** Karena master-nya `#REF!`,
   perlu konfirmasi tertulis dari lab bahwa konvensi lisan 11 Agt itu yang
   dipakai — supaya tidak bergantung pada ingatan percakapan.

3. **Rumus M33 titik 111.** Lab menyatakan "dibikin fix dulu, nanti saya cari
   rumusnya". Belum disentuh, dan sengaja tetap fix.

4. **U95 kelembaban per titik vs per unit.** Tabel sumbernya identik di tiga
   workbook; yang beda satu sel formula (`Refractometer O15` per unit,
   Turbidimeter & Chlorine per titik). Sistem memakai **per titik** — dua dari
   tiga master, dan dua itu yang diadu ke sertifikat kertas dan cocok karakter
   per karakter. Perlu ditegaskan lab mana yang benar.

---

## 5. Yang TIDAK dilakukan

Tidak ada kode produksi yang ditulis dari audit ini, sesuai TAHAP 4. Tidak ada
rumus yang diubah, ditebak, atau diambil dari sumber luar. Error sumber di §2
tidak ditambal dengan asumsi.

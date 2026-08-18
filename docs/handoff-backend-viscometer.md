# Handoff Backend — Viscometer (alat ke-7)

Tanggal: 18 Agustus 2026 · Branch: `feat/r2-spektro` ·
Formulir: `SIDIK-FM-CAL-0524_Rev.3` · Metode: `SIDIK-IK-CAL-0517_Rev.3`

Spesifikasi hitungnya di `docs/PRD-viscometer.md`. Dokumen ini yang teknis:
apa yang dibangun, di berkas mana, dan bagaimana membuktikannya jalan.

---

## 1. Yang khas dari alat ini

Tiga hal yang tidak ada di enam alat sebelumnya, dan ketiganya masuk ke angka:

**Nilai acuan bergerak mengikuti suhu.** Larutan standar viskositas berubah
tajam: larutan 60000 cP itu 95192 cP pada 20 °C dan 19259 cP pada 37,78 °C —
turun 80 % dalam 18 °C. Jadi nilai acuan tiap titik **diinterpolasi linier**
dari tabel sertifikat larutan pada suhu rata-rata titik itu, bukan diambil dari
nominal botolnya.

> Jangan pakai persamaan kubik yang tercetak di bawah tabel
> (`y = -0,0007x³ + …`). Itu trendline grafik: pada 26,52 °C hasilnya 97,16 cP
> sementara master memakai 93,88 cP. Beda 3,5 % — cukup untuk membalik vonis.

**Batas keberterimaan lahir per titik.** Bukan satu angka di
`equipments.toleransi` (kolom itu sengaja `NULL`), tapi MPE yang dihitung dari
spindle & RPM titik itu:

```
Fullscale = TK × SMC × 10000 / RPM
MPE       = 1 % × Fullscale + 1 % × rata-rata pembacaan
```

`TK` dari model alat (Tabel D-2, 12 model), `SMC` dari spindle (Tabel D-1,
63 spindle), `RPM` dari kecepatan putar. Satu sesi bisa memakai tiga spindle
berbeda dan dua RPM berbeda — sesi master persis begitu.

**Satu lembar memuat tiga orde magnitudo.** 96 cP, 918 cP, 63181 cP, ditulis
tangan, difoto pakai HP. Ini yang menentukan bentuk lembar cetak dan pita
validasi OCR-nya (bagian 4 & 5).

## 2. Berkas

| Berkas | Peran |
|---|---|
| `app/Services/Calibration/Profiles/ViscometerProfile.php` | Budget 4 komponen, `TABEL_TK`, `TABEL_SMC`, `fullscale()`, `toleransiTitik()`, `pitaPembacaan()`, bentuk lembar kerja |
| `database/migrations/2026_08_18_100000_tambah_spindle_rpm_ke_raw_measurements.php` | Kolom `spindle` & `rpm` per baris pengukuran |
| `database/seeders/ViscometerCapabilitySeeder.php` | Empat baris CMC — tiga terakreditasi, satu "di luar lingkup" |
| `database/seeders/ViscometerSeeder.php` | Tiga larutan Paragon, alat Brookfield DV-11, sesi contoh `DEMO-VISCO-BROOKFIELD` |
| `database/ocr-templates/viscometer-v1.json` | Geometri lembar pindai — **lanskap** 2339×1654 @200 dpi |
| `tests/Unit/ViscometerBudgetTest.php` | Angkanya diadu ke workbook master |
| `tests/Feature/ViscometerApiTest.php` | Perjalanannya lewat API, bolak-balik DB |
| `tests/Feature/PindaiViscometerTest.php` | Penjaga angka hasil foto |

Yang ikut berubah di luar Viscometer (dan kenapa) ada di bagian 6.

## 3. Baris CMC: empat, bukan tiga

Lampiran KAN LK-285-IDN no. 44 menyatakan CMC 0,2 / 2,1 cP dan 1,4 **P**
(= 140 cP) pada 102 / 1028 / 58021 cP. Yang diseed:

| Parameter | Rentang (cP) | CMC |
|---|---|---|
| Viskositas — Std 100 cP | 51,1 – 102 | 0,2 cP |
| Viskositas — Std 1000 cP | 419,5 – 1028 | 2,1 cP |
| Viskositas — Std 60000 cP | 19259 – 58021 | 140 cP |
| Viskositas — Std 60000 cP (di luar lingkup KAN) | 58021 – 95192 | **0** |

Diseed sebagai **rentang**, bukan titik tunggal, karena nilai acuannya bergeser
mengikuti suhu — titik tunggal 102 cP tidak akan pernah ketemu titik ukur
93,88 cP (ambang pencocokannya `max(0,1 ; 0,5 %)` = 0,51, jaraknya 8,12).

Baris keempat `ketidakpastian_terbaik = 0` artinya **tidak ada klaim CMC** untuk
rentang itu, bukan klaim nol. Kolomnya `NOT NULL`, jadi nol yang jadi penandanya.
Baris itu ada supaya titik di luar lingkup tetap dapat budget **empat komponen**
— tanpa baris itu, `GumCalculator::hitungTitik()` tidak menemukan kemampuan apa
pun dan jatuh ke jalur cadangan dua komponen yang **membuang pengaruh suhu**:
`uc` mengecil dari 72,858 ke 72,005 dan sertifikatnya mengklaim lebih baik dari
yang bisa dibuktikan, tanpa error apa pun.

## 4. Lembar cetak: lanskap

Satu-satunya lembar lanskap dari tujuh alat, dan alasannya satu angka. Baris
60000 cP menulis tujuh karakter (`63181.3`). Di grid potret kotaknya 124 px =
15,7 mm — **2,2 mm per digit tulisan tangan**. Lanskap memberi 230 px = 29,2 mm
(4,2 mm per digit). Kotak suhu sengaja lebih sempit (138 px = 17,5 mm), isinya
cuma empat karakter.

```bash
php artisan ocr:cetak-lembar viscometer --versi=1 --keluar=storage/app/visco.pdf
```

Kotak Spindle, RPM, dan Resolusi UUT per titik ikut tercetak sebagai garis
isian, tapi **bukan sel pindai**: kode spindle bentuknya `HA7` /
`CPE-51 or CPA-51Z`, sementara pembaca angka murni digit — sel semacam itu akan
selalu merah. Keduanya diisi di layar dari daftar pilihan tertutup.

`terverifikasi` masih `false`. Selama false, semua hasil pindai wajib lewat layar
review. Setel `true` hanya setelah kertas hasil perintah di atas dicetak, diisi
tangan, difoto, dan angkanya terbukti mendarat di sel yang benar.

## 5. Pita validasi OCR: per baris, bukan per lembar

Aturan umum (`nominal ± 10 %`) **tidak bisa dipakai** di sini, dan ini penjaga
yang paling gampang jebol tanpa ketahuan. Pita bawaan untuk baris 1000 cP itu
916,2 – 1119,8 cP — sementara pembacaan master yang paling kecil 916,3, cuma
0,1 cP di atas batasnya. Sesi yang sama diukur pada 30 °C akan ditolak seluruh
barisnya, dengan pesan yang terbaca seperti kamera gagal padahal angkanya benar.

`ViscometerProfile::pitaPembacaan()` menggantinya dengan jangkauan tabel
sertifikat larutan pada suhu kerja 20–37,78 °C, dilonggarkan 20 %:

| Baris | Nominal | Pita |
|---|---|---|
| 100 cP | 99,65 | 42,58 – 160,80 |
| 1000 cP | 1018 | 349,58 – 1804,80 |
| 60000 cP | 59003 | 16049,17 – 114230,40 |

Penjaga rasio ikut dilonggarkan ke 0,25–2,0×. Geseran titik desimal tetap
tertangkap: 631813 itu 10,7× dan 6318 itu 0,107×.

Enam alat lain tidak tersentuh — `pitaPembacaan()` bawaannya `null`.

## 6. Perubahan di luar Viscometer

Semuanya menutup lubang yang baru kelihatan gara-gara alat ini, dan semuanya
tetap berperilaku sama untuk enam alat sebelumnya.

| Berkas | Yang berubah | Kenapa |
|---|---|---|
| `CalibrationProfile` | `+ toleransiDariKolomAlat()`, `+ pitaPembacaan()` | Dua hook, bawaannya sama dengan perilaku lama |
| `CalibrationController` | Penjaga "toleransi alat kosong" nanya profil dulu | Tanpa ini **tidak ada satu pun sesi Viscometer yang bisa dihitung**: sesinya tersimpan, pengukurannya tersimpan, tapi nol titik dihitung |
| `GumCalculator` | `toleransi` null tidak lagi di-cast jadi `0.0` | `0` di kolom itu terbaca "batas keberterimaannya nol cP" — pernyataan salah di jejak audit sesi terakreditasi. Vonisnya sendiri sudah benar dari dulu |
| `GumCalculator` | Jejak `perbandingan_cmc` waktu CMC `0` | "vs CMC 0.00000000" terbaca seperti lab mengklaim ketidakpastian sempurna |
| `TataLetakLembar` | Konstanta tipografi tidak lagi diskalakan ikut ukuran kertas | Di lanskap, kotak label melar 41 % sementara jarak barisnya menyusut 29 % sampai blok isian menimpa judul tabel. A4 potret faktor skalanya persis 1,0 — enam geometri terverifikasi **byte-identik** setelah diregenerasi |
| `TataLetakLembar` | `atasGrid()` punya lantai dari batas bawah blok kepala | Grid tidak pernah mulai di atas isian yang masih menulis |

## 7. Membuktikannya jalan

```bash
php artisan migrate
php artisan db:seed --class=CalibrationCapabilitySeeder   # WAJIB duluan
php artisan db:seed --class=ViscometerCapabilitySeeder
php artisan db:seed --class=ViscometerSeeder
php artisan test
```

Urutan seeder bukan formalitas: `CalibrationCapabilitySeeder` menghapus seluruh
baris kemampuan kategori itu sebelum menulis ulang. Dijalankan setelah
`ViscometerCapabilitySeeder`, tiga baris CMC Viscometer ikut terhapus dan
seluruh titik diam-diam jatuh ke jalur generik.

Hasil sesi `DEMO-VISCO-BROOKFIELD` di MySQL, diadu ke `SERTIFIKAT.csv` dan
`PERHITUNGAN U95%.csv`:

| Titik | Acuan (cP) | UUT (cP) | Koreksi | `uc` | `veff` | `k` | `U95` | MPE | Vonis |
|---|---|---|---|---|---|---|---|---|---|
| 1 | 93,8756651 | 96,72 | −2,8443349 | 0,24649577 | 5,376 | 2,5706 | 0,63363755 | 4,14180317 | PASS |
| 2 | 910,28873239 | 917,66 | −7,37126761 | 1,35600158 | 60,620 | 2,0003 | 2,712407 | 22,07982581 | PASS |
| 3 | 61898,12 | 63151,85 | −1253,73 | 72,85796479 | 128,850 | 1,9787 | 144,1619311 | 1921,84108065 | PASS |

Tiga selisih dari master, **semuanya disengaja** dan di-assert eksplisit sebagai
selisih di `ViscometerBudgetTest`:

1. `k` titik 1 — master menulis 2, mesin memakai t-student (2,5706). Lihat
   `docs/pertanyaan-lab-viscometer.md` §2.
2. `n = 4` di titik 3 — sel ke-5 master rusak (`631.74.2`). §1.
3. Titik 3 tanpa lantai CMC — di luar batas ruang lingkup 58021 cP. §4.

`uc` dan `veff` cocok persis dengan master di ketiga titik. MPE cocok persis di
ketiganya.

## 8. Bentuk respons API

`GET /api/calibrations/lembar-kerja?profil=viscometer&equipment_id={id}` →
delapan bagian: `identitas`, `pemilik`, `usage_check`, `data_kalibrasi`,
`model_visco` (12 pilihan), `hasil` (13 field + 2 tabel), `standar_30000`
(blok kosong bertanda `sumber_belum_ada`), `penutup`.

Dua tabel (`sebelum_adjustment`, `sesudah_adjustment`), masing-masing 3 baris ×
5 pengulangan, kolom `pembacaan` (cP) + `suhu` (°C).

`POST /api/calibrations` — yang khas Viscometer:

```json
{
  "spesifikasi_alat": { "model_visco": "DV2THA" },
  "measurements": [
    {
      "titik_ukur": 99.65,
      "standard_id": 28,
      "satuan": "cP",
      "spindle": "HA1",
      "rpm": 63,
      "pembacaan": [97.3, 96.9, 96.8, 95.9, 96.7],
      "suhu": [26.6, 26.5, 26.5, 26.6, 26.4]
    }
  ]
}
```

`spindle` & `rpm` boleh juga dikirim lewat `spesifikasi_alat.spindle_titik_1`
dst. (bentuk yang ditulis lembar kerja); yang per baris menang. Tanpa keduanya
titiknya tetap dihitung, tapi `toleransi` dan `keputusan` keluar `null` — bukan
PASS diam-diam terhadap batas yang tidak pernah ada.

Potongan respons sesi tersimpan (`DEMO-VISCO-BROOKFIELD`, titik 1):

```json
{
  "titik_ke": 1,
  "titik_ukur": 93.8756651,
  "desimal": 2,
  "rata_rata": 96.72,
  "koreksi": -2.8443349,
  "standar_deviasi": 0.51185936,
  "jumlah_pengulangan": 5,
  "type_b_components": [
    { "sumber": "ketidakpastian_standar", "nilai": 0.0847025,
      "keterangan": "Sertifikat kalibrator Viscosity Standard Solution 100 cP (U=0.169405 cP, k=2) pada 25 °C" },
    { "sumber": "resolusi_alat", "nilai": 0.02886751345948129,
      "keterangan": "Daya baca alat 0.1 cP" },
    { "sumber": "ketidakpastian_temperature", "nilai": 0.01877012662240105,
      "keterangan": "UTemperature 0.36124784 °C (÷√3), ci (0.36124784/400)·99.65" },
    { "sumber": "pengulangan_pembacaan", "nilai": 0.22891046284519068,
      "keterangan": "Pengulangan 5 pembacaan (Type A)" },
    { "sumber": "perbandingan_cmc", "nilai": 0.2,
      "keterangan": "U hitung 0.63363755 vs CMC 0.20000000 → dilaporkan hitung" }
  ],
  "ketidakpastian_gabungan": 0.24649577,
  "faktor_cakupan_k": 2.57058184,
  "derajat_kebebasan_efektif": 5.37614444,
  "ketidakpastian_diperluas": 0.63363755,
  "toleransi": 4.14180317,
  "keputusan": "PASS"
}
```

Sertifikat mencetak dua desimal: `93,88` / `910,29` / `61898,12` cP. Yang
dibulatkan hanya bentuk cetaknya — kolom `uncertainty_calculations` tetap
menyimpan presisi penuh. Lihat `docs/pertanyaan-lab-viscometer.md` §5.

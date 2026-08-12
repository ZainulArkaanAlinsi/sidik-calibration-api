# Handoff Backend — Kalibrasi Spectrophotometer

Modul kalibrasi UV-Vis / Visible Spectrophotometer. Metode `SIDIK-IK-CAL-0508_Rev.4`.

Semua angka di dokumen ini diturunkan dari master Excel lab
(`Master Olah Data_Spectrofotometer.xlsm`), bukan dari asumsi. Master itu yang
jadi sumber kebenaran; kalau ada beda antara dokumen ini dan workbook, workbook
yang menang dan dokumen ini yang salah.

> Workbook aslinya terenkripsi dan tidak disimpan di repo, begitu juga
> password-nya. Yang masuk repo hanya angka hasil audit yang dikutip di sini dan
> di test golden.

---

## 1. Yang bikin alat ini beda dari lima alat sebelumnya

pH, Turbidimeter, Chlorine, Refractometer, dan Conductivity semuanya menghitung
**satu U95 per titik** — tiap titik punya budget ketidakpastiannya sendiri.

Spectrophotometer tidak. Sheet `PERHITUNGAN U95%` hanya punya **tiga tabel
budget**, satu per **kelompok pengukuran**, dan tiap tabel diberi makan
`MAX. STDEV` dari seluruh titik di kelompoknya:

| Kelompok             | Titik | Filter            | Satuan | Sel MAX STDEV     |
| -------------------- | ----- | ----------------- | ------ | ----------------- |
| `holmium`            | 10    | Filter Standard 1 | nm     | `PERHITUNGAN!M36` |
| `didynium`           | 9     | Filter Standard 2 | nm     | `PERHITUNGAN!M49` |
| `akurasi_transmitan` | 5     | Filter Standard 3 | %T     | `PERHITUNGAN!M67` |

Akibatnya: **sepuluh titik Holmium keluar dengan U95 yang sama persis**, dan
angka itu tidak bisa diturunkan dari satu titik saja.

Karena `GumCalculator::hitungTitik()` hanya melihat satu titik dan tidak punya
cara melihat titik saudaranya, dipasang hook opsional baru di kelas dasar:

```php
// app/Services/Calibration/Profiles/CalibrationProfile.php
public function hitungPerGrup(array $titik, Equipment $equipment): ?array
{
    return null;   // default: alat ini pakai jalur per-titik
}
```

`SpectrophotometerProfile` adalah satu-satunya yang meng-override-nya. Lima alat
lain menerima `null` dan jalannya **tidak berubah sama sekali** —
`CalibrationController::susunPengukuran()` mengumpulkan dulu semua titik, baru
memilih jalur:

```php
$perGrup = $this->profil->untukAlat($alat)->hitungPerGrup($siapHitung, $alat);

if ($perGrup !== null) {
    $hitungan = $perGrup['hitungan'];
    $belumDihitung = [...$belumDihitung, ...$perGrup['belum_dihitung']];
} else {
    foreach ($siapHitung as $t) {
        $hitungan[] = $this->gum->hitungTitik(/* jalur lama, per titik */);
    }
}
```

Agregasi GUM-nya sendiri (uc, Welch–Satterthwaite, k dari t-student, lantai CMC)
**tetap meminjam `GumCalculator::agregasiBudget()`** — supaya di repo ini hanya
ada satu implementasi aturan GUM. Yang dipisah cuma daftar & sumber komponennya.

---

## 2. Berkas

| Berkas                                                        | Peran                                                              |
| ------------------------------------------------------------- | ------------------------------------------------------------------ |
| `app/Services/Calibration/SpectrophotometerCalculator.php`    | Mesin hitung murni. Masuk array, keluar array. Tidak menyentuh DB. |
| `app/Services/Calibration/Profiles/SpectrophotometerProfile.php` | Bentuk lembar kerja, pemetaan titik↔kelompok↔standar, CMC.      |
| `app/Services/Calibration/CalibrationProfileRegistry.php`     | Pendaftaran profil.                                                |
| `app/Models/Formula.php`                                      | Konstanta `KODE_GUM_SPECTRO = 'gum-spectro'`.                      |
| `database/seeders/SpectrophotometerCapabilitySeeder.php`      | Tiga baris CMC.                                                    |
| `database/seeders/SpectrophotometerSeeder.php`                | Sesi demo 24 titik, data asli PT LDC Indonesia.                    |
| `tests/Feature/SpectrophotometerBudgetTest.php`               | Golden test hitungan murni (toleransi `1e-12`).                    |
| `tests/Feature/SpectrophotometerApiTest.php`                  | Test jalur API end-to-end (toleransi `1e-8`).                      |

---

## 3. Alur data

```
GET  /api/calibrations/lembar-kerja?equipment_id={id}
        └─ bentuk lembar: 3 tabel + kop + kondisi lingkungan + blok SRE (berstatus)

POST /api/calibrations/preview        ← dipanggil tiap teknisi selesai isi 1 baris
        └─ hitung tanpa simpan, body identik dengan POST /calibrations

POST /api/calibrations                ← simpan sesi + hasil hitung
        └─ raw_measurements + uncertainty_calculations

POST /api/calibrations/{id}/approve   ← admin
        └─ CertificateSnapshotBuilder membekukan hasil ke certificates.snapshot
```

Sertifikat **memakai** hasil yang sudah tersimpan, tidak menghitung ulang.
Ini dikunci `SpectrophotometerApiTest::test_payload_sertifikat_makai_hasil_yang_udah_dihitung`,
yang membandingkan tiap baris snapshot ke isi `uncertainty_calculations`.

---

## 4. Endpoint

Semua di bawah `auth:sanctum`. Menulis sesi butuh `role:admin,teknisi`;
approve butuh `role:admin`.

### 4.1 `GET /api/calibrations/lembar-kerja`

Query: `equipment_id` (atau `profil=spectrophotometer`).

Respons (dipangkas):

```jsonc
{
  "data": {
    "kode_dokumen": "SIDIK-IK-CAL-0508_Rev.4",
    "bagian": [
      { "kode": "hasil", "tabel": [
        {
          "judul": "Wave Length ( λ ) - Filter Holmium",
          "satuan": "nm",
          "pengulangan": ["X1", "X2", "X3"],
          "baris": [
            { "label": "279.60", "titik_ukur": 279.6, "standard_id": 12 }
            // … 10 baris
          ]
        },
        { "judul": "Wave Length ( λ ) - Filter Didynium", "satuan": "nm", "…": "9 baris" },
        { "judul": "Accuracy %T and Linierity at λ = 560nm", "satuan": "%T",
          "pengulangan": ["X1","X2","X3","X4","X5","X6"], "…": "5 baris" }
      ]},
      { "kode": "sre", "status": "sumber_belum_ada", "field": [], "catatan": "… #REF! …" }
    ]
  }
}
```

Tiap baris **sudah membawa `standard_id`-nya sendiri** — teknisi tidak memilih
filter per titik. Ini disengaja: rentang Holmium (283–641 nm) dan Didynium
(474–810 nm) tumpang tindih 167 nm, jadi pemilihan manual gampang salah dan
salahnya tidak kelihatan dari dokumen hasilnya.

Blok %T mendapat **enam** kolom pengulangan, bukan tiga — master mencetak dua
baris X1..X3 per nilai standar %T dan `PERHITUNGAN` merata-rata keenamnya
(`F47 = SQRT(6)`, `G47 = 6-1`).

### 4.2 `POST /api/calibrations` dan `POST /api/calibrations/preview`

Body-nya identik. `preview` menghitung tanpa menyimpan.

```jsonc
{
  "equipment_id": 7,
  "standard_id": 12,
  "input_method": "manual",
  "tanggal_kalibrasi": "2023-07-21T00:00:00Z",
  "suhu_awal": 22.0, "suhu_akhir": 22.0,
  "kelembaban_awal": 57.0, "kelembaban_akhir": 58.0,
  "measurements": [
    { "titik_ukur": 279.6, "satuan": "nm", "standard_id": 12,
      "pembacaan": [280, 280, 280] },
    { "titik_ukur": 9.9, "satuan": "%T", "standard_id": 14,
      "pembacaan": [9.668, 9.661, 9.666, 9.668, 9.661, 9.666] }
  ]
}
```

Respons memisahkan **input mentah**, **hasil hitung**, dan **titik yang belum
bisa dihitung**:

```jsonc
{
  "data": {
    "mentah": [ /* apa yang diketik teknisi, apa adanya */ ],
    "titik": [
      {
        "titik_ke": 1,
        "titik_ukur": 279.6,
        "satuan": "nm",
        "rata_rata": 280.0,
        "koreksi": -0.4,
        "ketidakpastian_gabungan": 0.13591968,
        "derajat_kebebasan_efektif": 3.46426187,
        "faktor_cakupan_k": 3.18244631,
        "ketidakpastian_diperluas": 0.43255708,
        "toleransi": null,
        "keputusan": null,
        "type_b_components": [ /* budget + catatan audit */ ]
      }
    ],
    "belum_dihitung": [
      { "titik_ke": 3, "alasan": "Butuh minimal 2 pembacaan terisi" }
    ]
  }
}
```

`toleransi` dan `keputusan` **selalu `null`** untuk alat ini: master tidak punya
batas keberterimaan, jadi tidak ada vonis PASS/FAIL yang bisa dibuat. Mengarang
batasnya berarti mencetak vonis yang tidak punya dasar.

### 4.3 `POST /api/calibrations/{id}/approve`

Body: `{"abaikan_peringatan": true}` bila ada standar berstatus WARNING.

Snapshot sertifikat, bagian `hasil`, satu baris per titik:

```jsonc
{
  "titik_ke": 1,
  "standard_value": 279.6,
  "unit_under_test": 280.0,
  "correction": -0.4,
  "u95": 0.43255708,
  "satuan": "nm",
  "desimal": 2,
  "remark": "Wave Length ( λ ) - Filter Holmium"
}
```

`remark` itu yang memisahkan tiga blok di dokumen cetak — tanpa itu, pembaca
sertifikat tidak punya cara tahu U95 0,4 nm itu punya Didynium, bukan Holmium.

`satuan` dan `desimal` dibekukan **per titik**, bukan per alat: satu lembar ini
mencampur nm (2 desimal) dan %T (3 desimal), jadi kolom `equipments.satuan` yang
tunggal tidak bisa menjawabnya.

---

## 5. Rumus

### 5.1 Per titik

| Besaran      | Rumus                            | Catatan                              |
| ------------ | -------------------------------- | ------------------------------------ |
| Rata-rata    | `AVERAGE(pembacaan)`             | hanya kotak yang benar-benar terisi  |
| Standar dev. | `STDEV.S` (pembagi `n−1`)        | sampel, bukan populasi               |
| Koreksi      | `nilai standar − rata-rata UUT`  | tanda mengikuti master               |

### 5.2 Per kelompok

STDEV terbesar di kelompok itu (`MAX`) yang masuk budget, lalu:

| Komponen                   | Distribusi | u                       | vi        |
| -------------------------- | ---------- | ----------------------- | --------- |
| Keterulangan (Type A)      | t-Student  | `stdevMaks / 3^0.25`    | `n − 1`   |
| Ketidakpastian gelas filter| Normal     | `U / k`                 | 200       |
| Resolusi alat              | Persegi    | `(resolusi / 2) / √3`   | 1 000 000 |
| Drift standar              | Normal     | `(U × 0,1) / √3`        | 50        |
| Spectral bandwidth         | Persegi    | `celah / √3`            | 50        |

Lalu, persis seperti alat lain:

```
uici        = ui · ci
uc          = √( Σ uici² )
veff        = uc⁴ / Σ( uici⁴ / vi )
k           = TINV(0,05, veff)          ← Excel; df dipotong ke bilangan bulat
U95         = uc · k
U sertifikat = MAX(U95, CMC)
```

`k` mengikuti Excel `TINV`, yang **memotong** derajat kebebasan ke bilangan
bulat. Di kode: `floor($veff)` lalu `StudentTDistribution::quantile(0.975, …)`.
Ini bukan pembulatan kosmetik — untuk Holmium, `veff = 3,4643` dipotong jadi 3
dan menghasilkan `k = 3,1824` (bukan ±2,9 kalau tidak dipotong). Dikunci
`test_k_ngikut_tinv_excel_yang_motong_derajat_kebebasan`.

Celah spectral bandwidth = `ABS(AVERAGE(P10:P27))` = **0,07555555555555789 nm**,
dari 18 pasang `[acuan, terbaca]` di sel `N10:O27`. Sumbernya tercetak di sel
N31: **SNSU PK.F-01:2020 Panduan Kalibrasi Spektrofotometer UV-Vis**. Disalin
sebagai pasangan angkanya, bukan sebagai hasil rata-ratanya, supaya tiap baris
tetap bisa ditelusuri balik ke panduannya.

---

## 6. CMC

Seeder: `database/seeders/SpectrophotometerCapabilitySeeder.php`.

| Parameter                         | Rentang     | CMC     |
| --------------------------------- | ----------- | ------- |
| `panjang gelombang (nm)-Holmium`  | 283–641 nm  | 0,4 nm  |
| `panjang gelombang (nm)-Didynium` | 474–810 nm  | 0,4 nm  |
| `akurasi (%T)`                    | 10–30,5 %T  | 0,5 %T  |

Pencocokannya lewat kolom **`parameter`**, bukan lewat angka rentang — karena
rentang Holmium dan Didynium tumpang tindih 167 nm dan pencocokan numerik akan
salah pilih. Ini juga alasan `range_min != range_max` di sini, beda dari pH /
Turbidimeter / Conductivity yang mem-CMC-kan per titik.

---

## 7. Aturan validasi

| Aturan                              | Perilaku                                                     |
| ----------------------------------- | ------------------------------------------------------------ |
| Pembacaan terisi < 2                | Titik masuk `belum_dihitung`, titik lain **tetap dihitung**  |
| Lembar kosong seluruhnya            | `titik: []`, tidak ada angka karangan, sesi tetap bisa disimpan |
| Titik di luar daftar `TITIK`        | Masuk `belum_dihitung` dengan alasan yang terbaca            |
| Titik di luar rentang CMC           | Tetap dihitung, tapi dicatat di jejak audit                  |
| Standar EXPIRED                     | `POST /calibrations` ditolak `422`                           |
| Standar WARNING                     | Diterima; approve butuh `abaikan_peringatan: true`           |

Toleransi pencocokan titik `TOLERANSI_TITIK = 0,05 nm` — **absolut**, bukan
relatif 2% seperti `CalibrationProfile::TOLERANSI_PASANGAN_TITIK`. 2% dari
536,3 nm itu ±10,7 nm, dan titik Didynium 529,7 masuk ke dalam jendela itu, jadi
baris Didynium akan dipasangkan ke Filter Standard 1 dan U95 kelompok yang salah
tercetak di sertifikat. Standar spektro tidak punya kurva suhu, jadi nilainya
tidak pernah bergeser dan jendela seketat ini aman. Dikunci
`test_titik_berdekatan_beda_kelompok_nggak_ketuker`.

### Status standar

`Standard::statusKalibrasi(int $ambangHari)`; ambangnya
`Organization::DEFAULT_AMBANG_HARI` = 30, ditentukan **backend**, bukan mobile.
Kalau HP menentukan sendiri, dia bisa menampilkan VALID untuk standar yang akan
ditolak backend saat approve, dan teknisi baru tahu setelah pekerjaannya selesai.

| Sisa hari  | Status    |
| ---------- | --------- |
| ≥ 31       | `valid`   |
| 1 – 30     | `warning` |
| hari ini   | `expired` |
| < 0        | `expired` |

> **Perlu diputuskan lab.** Docblock `Standard::hariMenujuKadaluarsa()` menulis
> "`0` = habis hari ini" yang terbacanya masih berlaku, tapi `masihBerlaku()`
> memakai `berlaku_sampai->isFuture()` dan kolomnya di-cast `date` (jam 00:00),
> sehingga standar yang berlaku **sampai hari ini** dinilai `expired`.
> `SpectrophotometerApiTest::test_status_standar_valid_warning_expired` mengunci
> perilaku yang benar-benar berjalan sekarang. Kalau lab menghendaki sertifikat
> berlaku sampai akhir tanggalnya, yang diubah `masihBerlaku()`, dan test itu
> yang akan memberi tahu. Ini menyentuh **semua** alat, bukan cuma spektro.

---

## 8. Golden values

Dari sesi master (PT LDC Indonesia, alat Perkin Elmer Lambda 25 s/n
`501S13102801`, 21 Juli 2023). Dikunci di
`tests/Feature/SpectrophotometerBudgetTest.php`.

### 8.1 Per kelompok

| Kelompok   | MAX STDEV           | uc                  | veff             | k                | U95                 | Sertifikat          |
| ---------- | ------------------- | ------------------- | ---------------- | ---------------- | ------------------- | ------------------- |
| Holmium    | 0,15588457268125408 | 0,1359196779955751  | 3,4642618700507275 | 3,182446305283709 | 0,43255707705236945 | 0,43255707705236945 |
| Didynium   | 0,15011106998929746 | 0,15828510160732645 | 7,367683516139811  | 2,364624251592785 | 0,3742847899265122  | **0,4** (lantai CMC) |
| Akurasi %T | 0,00409878030638399 | 0,028917411133548367| 50,340915292002045 | 2,008559112100761 | 0,058082329630652574| **0,5** (lantai CMC) |

Holmium satu-satunya yang U hitungnya menang atas CMC.

### 8.2 Per titik (yang tercetak di `SERTIFIKAT`)

| Kelompok   | Titik   | Rata-rata           | Koreksi                |
| ---------- | ------- | ------------------- | ---------------------- |
| Holmium    | 637,9   | 637,27              | 0,63                   |
| Didynium   | 475,2   | 477,9066666666667   | −2,706666666666706     |
| Didynium   | 806,1   | 806,3966666666666   | −0,2966666666666242    |
| Akurasi %T | 0       | 0,0006666666666666666 | −0,0006666666666666666 |
| Akurasi %T | 9,9     | 9,665               | 0,235                  |
| Akurasi %T | 100     | 100,00266666666668  | −0,0026666666666841365 |

### 8.3 Toleransi test

| Test suite                     | Toleransi | Alasan                                          |
| ------------------------------ | --------- | ----------------------------------------------- |
| `SpectrophotometerBudgetTest`  | `1e-12`   | Hitungan murni, tidak lewat DB — batas double.  |
| `SpectrophotometerApiTest`     | `1e-8`    | Bolak-balik kolom `decimal(20,8)`.              |

---

## 9. Penyimpangan master yang SENGAJA ditiru

Tiga kekeliruan di Excel yang **terbawa ke angka sertifikat yang sudah terbit**.
Ditiru persis supaya backend menghasilkan angka yang sama dengan dokumen yang
sudah beredar — tapi masing-masing mencetak baris `catatan_audit` yang menyebut
berapa hasilnya kalau dibetulkan, supaya yang memilih mana yang benar itu
manajer teknis lab, bukan diam-diam kode ini.

### 9.1 Pembagi Type A panjang gelombang: `3^0,25`, bukan `√n`

Sel `H10`/`H26` menulis `H = E/SQRT(F)` dengan `F = SQRT(3)`, sehingga
pembaginya `3^0,25 = 1,3160740…`. Konvensi GUM untuk rata-rata n pembacaan
adalah `s/√n`.

Blok %T memakai `H = E/F` yang **benar** (`H47`, `F = SQRT(6)`), jadi
kekeliruannya hanya di dua tabel panjang gelombang. Ini yang menghasilkan
U95 Holmium `0,43255707705…`.

### 9.2 Jangkauan `SUM` blok %T melewati dua baris

Sel `J52`/`K52`/`L52` menulis `SUM(J47:J49)` — melewati baris 46
(Ketidakpastian Standar, penyumbang **terbesar**: uici 0,25) dan baris 50
(Spectral Bandwidth).

Kalau keduanya diikutkan, U naik dari `0,0580823…` ke ±`0,5058`, dan
sertifikatnya berubah dari `0,5` (lantai CMC) menjadi `0,5058`. **Bukan
kekeliruan kosmetik.** Dua komponen itu tetap muncul di budget dengan
`disertakan: false` + `alasan_dikecualikan`, jadi terlihat, bukan hilang.

### 9.3 Label distribusi blok %T tertukar — ini TIDAK ditiru

Sel `D46` menulis `t-Student` untuk ketidakpastian sertifikat standar dan `D47`
menulis `Normal` untuk keterulangan — tertukar. Yang ini **tidak ditiru**:
`distribusi` dipakai untuk memisahkan Type A dari Type B, jadi meniru label yang
tertukar akan membuat RSS Type B salah isi. Angkanya tidak tersentuh sama
sekali; hanya penamaannya yang dibetulkan.

---

## 10. Kesenjangan (gap) yang belum tertutup

### 10.1 SRE — tidak diimplementasikan, dan itu keputusan sadar

Master punya blok keempat, `%Transmitan (SRE)` — Stray Radiant Energy. Blok itu
**rusak di sumbernya**, dan tidak ada satu pun angka di dalamnya yang bisa
dipercaya:

| Sel                            | Isi                                                          |
| ------------------------------ | ------------------------------------------------------------ |
| `SERTIFIKAT!C57`               | `='[3]Input Data Mentah'!#REF!` → `#REF!`                    |
| `SERTIFIKAT!O57`               | `#REF!` (koreksinya)                                          |
| `PERHITUNGAN U95%!AA65`, `AA66`| `#DIV/0!` — AVERAGE atas rentang kosong                       |
| `PERHITUNGAN U95%!E67`         | `0` — nol mati, bukan hasil hitung                            |
| `PERHITUNGAN U95%!K75`         | `= J74` — "CMC"-nya menunjuk balik ke U hitungnya sendiri     |
| `SERTIFIKAT!M58`               | `='[3]CMC SPECTRO UD'!I85` — external reference, file tak ada |

`k`-nya juga bukan t-student melainkan rumus tempel
`=((2,35746×1,099)+(veff×1,9599999))/veff` yang tidak punya padanan di GUM. Dan
karena `K75 = J74`, `MAX(J74,K75)` selalu benar secara aritmetika dan tidak
memeriksa apa pun.

**Status: not implemented / source missing.** Mengarang penggantinya berarti
mencetak angka ketidakpastian bikinan di sertifikat terakreditasi. SRE muncul di
lembar kerja sebagai bagian berstatus `sumber_belum_ada` yang tidak menerima
input, dan tidak pernah masuk hitungan mana pun.

**Cara menutupnya** begitu lab menyediakan lembar SRE yang sah (nilai standar +
ketidakpastian standarnya): tambah satu kelompok di `SpectrophotometerProfile::TITIK`
+ satu baris CMC di seeder-nya. Tidak ada perubahan lain yang dibutuhkan.

### 10.2 CMC Didynium: master 0,40 nm vs KAN 0,38 nm

| Kelompok   | Rentang    | Master (`DATABASE!S5–S7`) | KAN LK-285-IDN no.47 |
| ---------- | ---------- | ------------------------- | -------------------- |
| Holmium    | 283–641 nm | 0,40 nm                   | 0,40 nm ✓            |
| Didynium   | 474–810 nm | **0,40 nm**               | **0,38 nm** ✗        |
| %T @ 560nm | 10–30,5 %T | 0,50 %T                   | 0,50 %T ✓            |

**Yang dipakai backend: 0,40 nm (angka master).** Dua alasan:

1. Sel `'PERHITUNGAN U95%'!J37` membaca `DATABASE!S6` = 0,4, dan
   `J38 = MAX(J36,J37)` memakainya untuk mencetak U95% sertifikat Didynium =
   0,4 nm. Itu angka yang benar-benar keluar di sertifikat lab selama ini.
2. 0,40 > 0,38, jadi memilih angka master adalah arah **aman** — lab mengklaim
   ketidakpastian lebih besar dari yang diakreditasi, bukan lebih kecil.
   Kebalikannya (memakai 0,38 padahal sertifikat lama mencetak 0,4) membuat
   hasil backend berbeda dari dokumen yang sudah terbit.

**Perlu diberesin lab**: entah lampiran akreditasinya diperbarui, atau master
Excel-nya diturunkan ke 0,38.

### 10.3 Ejaan `nama_alat` terbelah dua

Master Excel & `DATABASE.csv` baris 8 menulis **`Spectrophotometer`**;
`kemampuan-kalibrasi.json` menulis **`Spektrofotometer`**. Backend memakai ejaan
master. Tiga baris dari JSON itu tetap ada di tabel dan **tidak dipakai** jalur
ini — parameternya dua-duanya cuma "Panjang Gelombang", sehingga Holmium dan
Didynium tidak bisa dibedakan dari situ. Itulah sebabnya baris CMC di seeder
memakai label parameter sendiri yang eksplisit.

### 10.4 Titik yang disertifikasi di luar rentang CMC-nya sendiri

Master menyertifikasi titik yang keluar dari rentang CMC-nya: Holmium 279,6 nm
(di bawah 283) dan %T 0 / 9,9 / 100 (di luar 10–30,5). Backend mengikuti, tapi
mencatat — lewat komponen `titik_luar_rentang_cmc` di jejak audit tiap titik.
Dikunci `test_titik_di_luar_rentang_cmc_dicatat_di_jejak_audit`.

---

## 11. Seeder demo

`database/seeders/SpectrophotometerSeeder.php` — sesi `DEMO-SPECTRO-LDC`, data
asli: PT LDC Indonesia, Perkin Elmer Lambda 25 s/n `501S13102801`, 21 Juli 2023,
onsite, 24 titik.

Kondisi lingkungan hasil koreksi thermohygro: suhu ruang **21,61 °C**
(`PERHITUNGAN!G14+N14`), kelembaban **56,5 %RH** (`G15+N15`),
`suhu_ketidakpastian` 1,70, `kelembaban_ketidakpastian` 4,90.

Urutan run penting: `SpectrophotometerCapabilitySeeder` harus **sesudah**
`CalibrationCapabilitySeeder`, yang menghapus semua kemampuan kategori
`instrumen-analitik` sebelum menulis ulang dari JSON.

Seeder memanggil `hitungPerGrup()` **sekali** untuk semua titik — bukan per
titik dalam loop — karena itulah bentuk perhitungan yang sebenarnya.

---

## 12. Menjalankan test

```bash
php artisan test tests/Feature/SpectrophotometerBudgetTest.php   # 24 test
php artisan test tests/Feature/SpectrophotometerApiTest.php      # 12 test
```

> `--parallel` tidak jalan di repo ini: butuh `brianium/paratest` 7.x yang belum
> terpasang.

**Wajib diverifikasi juga terhadap MySQL, bukan cuma SQLite.** Test lokal jalan
di SQLite, produksi di MySQL, dan keduanya memberi tipe PHP yang berbeda untuk
kolom `decimal` yang sama: SQLite mengembalikan float `279.6`, MySQL
mengembalikan string `"279.60000000"`. `SpectrophotometerApiTest::titikTersimpan()`
menormalkan kuncinya lewat `round()` justru karena itu — versi pertama test ini
memakai `keyBy('titik_ukur')` mentah dan hanya lolos di satu dari dua DB.

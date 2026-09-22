# Volumetric Glassware — yang sudah, yang belum, dan yang perlu diriset

**Dibuat:** 22 September 2026
**Tujuan:** satu tempat untuk melanjutkan pekerjaan Volumetric tanpa membedah
ulang master dari nol. Baca ini dulu, lalu `docs/pertanyaan-lab-volumetric.md`.

**Master:** `Project-PT-Sidik/alat-alat-Pt-Sidik/Volumetric_Glassware_2026/`
(CSV sudah terekstrak; workbook aslinya ber-password — tanyakan ke pemilik
proyek. Folder ini ter-gitignore karena memuat nama & alamat pelanggan —
**jangan pernah di-`git add`**).

---

## 1. Yang SUDAH selesai dan ada di repo

| Berkas | Isi | Bukti |
|---|---|---|
| `app/Services/Calibration/VolumetricGlasswareCalculator.php` | ρ udara, ρ air (Tanaka), V20, γ dari kelas | live di produksi sejak `69d4c29` |
| `tests/Unit/VolumetricGlasswareMasterTest.php` | 6 test, 21 assertion — adu ke cache Excel + 3 penjaga struktural | hijau |
| `docs/skrip/gen-tabel-standar-volumetric.py` | generator tabel referensi | dijalankan, dicek balik ke master |
| `database/data/tabel-standar-volumetric.json` | 6 tabel CMC, diameter ISO 4787 (45), koefisien muai (14), neraca per workbook | CMC Pipet Volume 1 mL = 0,003, Gelas Ukur 100 mL = 0,34 — cocok master |
| `docs/pertanyaan-lab-volumetric.md` | 10 pertanyaan bernomor untuk manajer teknis | siap dibawa ke lab |
| `VolumetricGlasswareCalculator::komponenBudget()` + `meniskusFixed()`/`meniskusGraduated()` | 8 komponen budget; agregasi lewat `GumCalculator::agregasiBudget()` (otomatis membetulkan `Veff` Fixed) | `tests/Unit/VolumetricGlasswareBudgetTest.php`: Graduated cocok master sampai U; Fixed cocok ci & uc, U > master |
| `app/Services/Calibration/TabelStandarVolumetric.php` | pembaca JSON: CMC (nominal terdekat, seri → baris pertama), diameter (persis), neraca per keluarga | `tests/Unit/TabelStandarVolumetricTest.php`, 7 test hijau |

**Yang sudah terbukti cocok ke cache Excel (selisih nol):** V20 Fixed & Graduated,
delapan koefisien sensitivitas Fixed, `uc` Fixed & Graduated. `k` Graduated cocok
9·10⁻¹⁶ **setelah `veff` dipotong ke bilangan bulat** — `GumCalculator::agregasiBudget()`
sudah melakukan ini, jangan bikin mesin agregasi kedua.

---

## 2. Keputusan yang SUDAH diambil — jangan ditanyakan ulang

| # | Keputusan | Oleh / kapan |
|---|---|---|
| K1 | **Dua mesin, enam pintu.** Dua kelas dasar (`FixedVolumetricGlasswareProfile`, `GraduatedVolumetricGlasswareProfile`) + enam profil konkret sesuai nama lampiran akreditasi | pemilik proyek, 21 Sep |
| K2 | Fixed → Labu Ukur, Pipet Volume, **Picnometer** · Graduated → Gelas Ukur, Buret, Pipet Ukur | Picnometer = dugaan pengembang, masih pertanyaan lab no. 6 |
| K3 | Nol hantu `IFERROR` di keterulangan Graduated: **hitung benar** (STDEV nilai nyata saja), **simpan angka master sebagai pembanding** di `type_b_components` | pemilik proyek, 21 Sep |
| K4 | `Veff` Fixed yang membagi `K43`: **hitung benar** (bagi SUM), simpan angka master sebagai pembanding | aturan AGENTS.md "kerusakan salin-tempel" |
| K5 | γ hanya Class A/B; nilai lain **ditolak**, bukan dipetakan ke bawaan | sudah diterapkan di kalkulator |
| K7 | Perbedaan metode antar workbook (u timbang, u densitas air, sumber meniskus, tanda ci muai) **ditiru per keluarga**, tidak diseragamkan | pertanyaan lab no. 5, 9, 10 |
| K6 | Tabel CMC **boleh dibagi** (identik di kedua workbook); tabel neraca **tidak boleh** (neraca ke-3 beda fisik) | diterapkan di generator |

---

## 3. Yang BELUM — urutan yang disarankan

Urutan mengikuti playbook `.claude/skills/sidik-alat-baru-dari-master`.

### 3.1 `TabelStandarVolumetric.php` — ✅ SELESAI (22 Sep)
Pembaca `database/data/tabel-standar-volumetric.json`. Master memilih baris CMC
lewat `INDEX/MATCH(MIN(ABS(...)))` — **nilai terdekat**, bukan pencocokan persis.
Tiru perilaku itu, dan tulis di docblock bahwa itu pencocokan terdekat, supaya
tidak disangka bug oleh pembaca berikutnya.

### 3.2 Dua kelas dasar profil
- `FixedVolumetricGlasswareProfile` — satu titik, 3 ulangan, budget 8 komponen biasa.
- `GraduatedVolumetricGlasswareProfile` — sampai 5 titik, **SATU budget gabungan**
  untuk semua titik (bukan budget per titik seperti Sieve Mesh). Agregasi input budget:

  | Input budget | Agregasi | Sel master |
  |---|---|---|
  | massa air | **MAX** rata-rata massa semua titik terisi | `R38` |
  | ρ air suling | **MAX** | `R85` |
  | suhu air | **AVERAGE** seluruh pembacaan terkoreksi, semua titik × ulangan | `Z49` |
  | Tmax/Tmin (Ut-water) | **MAX/MIN** gabungan | `Z47`/`Z48` |

  **Titik kosong tidak boleh ikut dihitung sebagai nol** di MAX/MIN/AVERAGE mana pun.
  Satu U95 + satu k hasil budget ini dipakai untuk **semua baris** sertifikat.

### 3.3 Enam profil konkret
Masing-masing cuma menyumbang `namaAlatKemampuan()` (persis nama lampiran) dan
kunci tabel CMC-nya. Nama lampiran: **Buret, Gelas Ukur, Labu Ukur, Pipet Ukur,
Pipet Volume, Picnometer**.

### 3.4 Registry + `*Mentah` + hitung ulang
- Enam baris di `CalibrationProfileRegistry::daftarProfil()`.
- `app/Support/VolumetricGlasswareMentah.php` — **wajib lahir bareng profilnya**,
  disambung ke `CalibrationValidator` **dan** `HitungUlangSesi`. Pola ini sudah
  menggigit tujuh kali di repo ini.

### 3.5 Seeder
- Seeder CMC untuk enam alat (nilai dari JSON, bukan diketik).
- Seeder sesi contoh — angkanya **dihitung**, bukan ditempel. Pakai data contoh
  dari master (Pipet Volume 1 mL; Gelas Ukur 10/50/100 mL).
- ⚠️ Sesi contoh memakai nomor sertifikat resmi kalau diterbitkan — lihat §6 no. 5.

### 3.6 Komponen meniskus — dua sumber berbeda
- **Fixed:** dari tabel diameter ISO 4787 (dicari lewat toleransi kelas alat).
  Contoh master: ø 4,7 mm → U = 0,000867 mL.
- **Graduated:** dari **resolusi alat**, bukan tabel diameter. Contoh master:
  U = 0,2887 mL. Komponen ini mendominasi `uc` Graduated.

### 3.7 Presisi tampilan — beda per keluarga
| | Fixed | Graduated |
|---|---|---|
| Nominal / Actual / Correction | 4 desimal | 4 desimal |
| U95 | **4 desimal** | **2 desimal** |
| k | 0 desimal | 0 desimal |

Tidak ada verdict PASS/FAIL — laporan data murni.

### 3.8 Sapuan test registry yang PASTI merah
Menambah enam profil membuat sapuan registry ikut menguji semuanya. Siapkan
jawabannya (rincian di playbook §4):

`CmcSemuaProfilTest` · `SemuaProfilLembarKerjaTest` · `ThermohygroSemuaLembarTest` ·
`StandarTidakBocorAntarLabTest` · `CetakLembarKerjaOcrTest` (butuh
`database/ocr-templates/<kode>-v1.json`) · `PerintahUjiKesiapanTest` ·
`ProfilDariNamaAlatTest` · `HitungUlangSemuaSesiTest`

Plus: **cabut enam nama alat ini dari daftar acak `EquipmentFactory`** — kalau
tidak, fixture acak mendarat di lembar Volumetric dan yang merah test lain,
bergantian tiap jalan.

### 3.9 Dokumen penutup
- `docs/perintah-frontend-volumetric.md` — serah-terima ke repo mobile
  (cabang di `lembar_kerja_service.dart`, fixture dari respons API).
- §baru di `docs/permintaan-user-7.md` + baris Gelombang.

---

## 3b. Rancangan sambungan — dipelajari dari Hydrometer (22 Sep)

Hydrometer adalah analog terdekat (gravimetri, beberapa deret per titik, tiga
ulangan). Dia menyentuh 11 tempat; Volumetric menyentuh tempat yang sama.

### Jalur simpan di controller
- `CalibrationProfile` punya cabang `butuhBlokX()` bawaan `false`. Tambahkan
  **`butuhBlokVolumetric(): bool`** (bawaan `false`), override `true` di kedua
  kelas dasar.
- `CalibrationController` ±baris 1242 (tepat setelah cabang Hydrometer):
  `if ($this->profil->untukAlat($alat)->butuhBlokVolumetric()) return $this->susunBlokVolumetric($request, $alat, $standarDefault);`
- **Satu** `susunBlokVolumetric()` untuk kedua keluarga — tiru
  `susunBlokHydrometer()` (±baris 2807). Bentuk mentah identik, beda keluarga
  cuma di `hitungPerGrup()` profil.
- Bentuk `measurements[]` dari HP: `{titik_ukur, vol_kosong: [3], vol_isi: [3], vol_suhu: [3]}`.
  Tiap angka jadi satu baris `raw_measurements` dengan `peran_sensor` = konstanta
  di `VolumetricGlasswareMentah` (`PERAN_KOSONG`, `PERAN_ISI`, `PERAN_SUHU`),
  `pembacaan_ke`/`sensor_ke` = urutan 1..3, `tahap` = `sesudah_adjustment`.
  **Nol kolom baru.**
- Penolakan eksplisit (masuk `belum_dihitung` dengan `alasan` yang kebaca):
  titik melebihi batas (**Fixed 1, Graduated 5**), deret tidak tepat 3 angka, deret
  tidak sinkron. Baris yang seluruhnya kosong dilewati diam-diam (Graduated boleh
  menyisakan titik 4–5).
- `$siapHitung[]` per titik: `titik_ke`, `titik_ukur`, `pembacaan => []`,
  `standard`, `konteks` berisi ketiga deret + `spesifikasi_alat` +
  `tanggal_kalibrasi` + `suhu/kelembaban/tekanan _awal/_akhir` **dari request**
  (bukan dari relasi sesi — sesi belum tersimpan saat jalur simpan jalan).
  Tekanan WAJIB: ρ udara lahir darinya. Kolom `tekanan_awal`/`tekanan_akhir`
  sudah ada di sesi.
- Pulangkan `['mentah' => …, 'hitungan' => array_map(bulatkanHitungan, …), 'belum_dihitung' => …]`.

### Kolom yang WAJIB dipulangkan `hitungPerGrup()` per titik
(kolom nyata `uncertainty_calculations`, disalin dari `HydrometerProfile`)

`standard_id`, `titik_ke`, `titik_ukur` (nominal), `rata_rata` (V20 rata-rata),
`error`, `koreksi`, `standar_deviasi`, `jumlah_pengulangan`, `type_a`,
`type_b_components` (jejak audit — tempat angka master pembanding K3/K4 disimpan),
`type_b`, `ketidakpastian_gabungan`, `faktor_cakupan_k`,
`derajat_kebebasan_efektif`, `ketidakpastian_diperluas` (= `MAX(U, CMC)`),
`toleransi` (null), `keputusan` (null — tidak ada PASS/FAIL), `metode`,
`calculated_at`.

⚠️ **Tanda koreksi belum diperiksa.** Sheet budget menulis "Correction =
Equipment Nominal − V20", tapi `PERHITUNGAN` menghitung "Deviation = V20 −
Nominal". Periksa `SERTIFIKAT.csv` kedua workbook untuk tanda yang tercetak
sebelum mengisi `error`/`koreksi`.

### Masukan budget per keluarga (sudah terbukti di `VolumetricGlasswareBudgetTest`)
| Masukan | Fixed | Graduated |
|---|---|---|
| `massa` | rata-rata massa titik | **MAX** rata-rata massa titik terisi |
| `rho_air` | ρ air dari suhu rata-rata | **MAX** ρ air rata-rata titik |
| `suhu_air` | rata-rata suhu terkoreksi | rata-rata gabungan semua titik × ulangan |
| `u_massa` | LOP neraca ÷ √3, digabung akar-kuadrat dengan stdev/√10 | U95 neraca ÷ 2, digabung dengan stdev/√10 |
| `u_suhu` | √((U95 termometer/2)² + (U95 sensor/2)² + ((Tmax−Tmin)/(2√3))²) | sama |
| `u_meniskus` | `meniskusFixed(diameter dari toleransi)` | `meniskusGraduated(resolusi)` |
| `u_rho_air` | 5e-05 | 5e-08 |
| `u_keterulangan` | STDEV V20 3 ulangan ÷ √3 | STDEV nilai nyata dari stdev per titik ÷ √3 (**tanpa** 5 nol hantu) |
| `tanda_ci_muai` | +1 | −1 |

U95 termometer Yokogawa 0,72 °C dan sensor PRT 0,08 °C (k=2) — baca dari
master standar yang tertaut, jangan diketik.

### Sambungan lain (daftar dari jejak Hydrometer)
`CalibrationRequest` (aturan validasi `measurements.*.vol_*`, `spesifikasi_alat.volumetric.*`) ·
`CalibrationProfileRegistry` · `CalibrationValidator` · `HitungUlangSesi` ·
`UjiProfilKalibrasi` · `CertificateSnapshotBuilder` · `CertificateExcelExporter` ·
`DatabaseSeeder`. Hitung jejak Hydrometer di tiap berkas itu
(`grep -ci hydrometer <berkas>`) sebagai daftar periksa.


## 4. Jebakan yang SUDAH terbukti — jangan diulang

| Jebakan | Kenapa berbahaya | Penjaga |
|---|---|---|
| Memakai `densitasAirSuling()` Hydrometer | Tidak setara: selisih hingga 5·10⁻⁶, persis di ambang toleransi — lolos di sebagian suhu, gagal di yang lain | test `densitas_air_hydrometer_bukan_pengganti` |
| Memakai ρ anak timbangan Hydrometer (8,0) | Volumetric **7,95**. Tertukar = seluruh V20 bergeser tanpa error | test `densitas_anak_timbangan_bukan_milik_hydrometer` |
| Menulis ulang V20 dari ingatan ISO 4787 | Suku muai termal **bersarang di dalam** suku daya apung. Salah susun = ~0,0001 mL, signifikan untuk CMC 0,002 mL | vektor uji V20 selisih 0 |
| Menghitung densitas air sekali dari suhu rata-rata (Graduated) | Master menghitung **per ulangan**. Rata-rata dulu = angka mirip tapi sebaran hilang | test `graduated_rantai_v20` |
| Membaca dokumen analisis sebagai kebenaran | Sudah terbukti keliru di 4 tempat (lihat `pertanyaan-lab-volumetric.md` §Koreksi) | selalu adu ke CSV |
| `k` dari `TINV(veff)` tanpa memotong `veff` | Meleset 3·10⁻⁴ di digit keempat | pakai `GumCalculator` |

---

## 5. Vektor uji

Sudah dipakai di `VolumetricGlasswareMasterTest`:

```
Fixed, nominal 1 mL, Class B
  T ruang 20,75 · RH 47,5 · P 933,15 · t air terkoreksi 27,32502900705911
  ρ udara  = 0.001101287184161609
  ρ air    = 0.9964251596993278
  V20      = 1.0042575664928188    (massa rata-rata 0,9997)

Graduated, titik 10 mL, Class B, ρ udara 0.0011008519333332652
  V20 rep-1      = 10.588560085479635
  V20 rata-rata  = 10.62484944968544
  STDEV 3 rep    = 0.053476335084273664
```

**Belum** dijadikan test — perlu saat kelas dasar ditulis:

```
Fixed   uc   = 0.000508808998503895
        Veff master (/K43) = 11846.497219116   Veff benar (/SUM) = 53.119
        ci: 1.004448542532581 · 0.8825394640041497 · -0.8825394640041497 ·
            1.749942902146706e-05 · -0.0001103432353970456 · 1.004257551205218 · 1 · 1
Graduated  uc = 0.16801586265445564
           veff = 51.34909756723706  ->  dipotong 51  ->  k = 2.007583770315835
           H55 master (5 nol hantu) = 0.03537981705907074
           H55 benar (3 nilai nyata) = 0.03339744467165117
```

---

## 6. Perlu diriset / diputuskan sebelum atau selama implementasi

1. **Delapan pertanyaan lab** di `docs/pertanyaan-lab-volumetric.md` — terutama
   no. 3 (Rev.6 vs Rev.7) karena menentukan string metode yang dicetak, dan no. 6
   (Picnometer) karena menentukan kelas dasar mana yang dia warisi.
2. **Aturan pilih neraca berdasarkan volume.** `INPUT_DATA` Fixed memuat panduan:
   100 µL–10 mL → Analytical (Mettler, 0,0001 g); 11–1000 mL → Electronic
   (Fujitsu, 0,001 g); > 1000 mL → Electronic (Excellent, 0,01 g). Apakah sistem
   perlu **menegakkan** aturan ini (menolak neraca yang salah untuk volumenya),
   atau cukup menyarankan? Belum ditanyakan ke siapa pun.
3. **Bentuk kontrak `lembar_kerja`** untuk Graduated dengan 1–5 titik dinamis
   (`titik_bisa_diubah`). Perlu dicocokkan dengan pola alat multi-titik yang sudah
   ada di mobile sebelum ditulis.
4. **Koreksi termometer & sensor suhu air** — master memakai tabel koreksi
   Yokogawa + PRT (`Koreksi_Meter_Suhu`, `Koreksi_sensor_suhu`) dengan indeks
   terdekat. Periksa apakah repo sudah punya pembaca tabel ini dari alat suhu lain
   sebelum menulis yang baru.
5. **Data contoh di produksi.** Produksi masih memegang 37 sesi contoh + 25
   sertifikat yang memakai nomor resmi. Perintah `sertifikat:sapu-data-contoh`
   sudah ada; eksekusinya **ditunda atas keputusan pemilik proyek** (21 Sep).
   Seeder sesi contoh Volumetric akan menambah masalah yang sama kalau jalan di
   produksi.

---

## 7. Hal di luar Volumetric yang ketemu sambil jalan (untuk konteks)

- Suite MySQL sekarang jalan ke **localhost**, bukan Aiven — lewat
  `jalankan-test-mysql.ps1` (gitignored). Suite penuh ±3 jam.
- `SertifikatSemuaAlatSatuHalamanTest` **lolos sendirian, gagal di suite MySQL
  penuh** — bergantung keadaan, belum ditelusuri. Tersangka: `AUTO_INCREMENT`
  MySQL yang terus maju lintas test (dugaan, belum dibuktikan).
- Relasi `CalibrationSession::rawMeasurements()` membawa `ORDER BY titik_ke,
  pembacaan_ke, id`. Menyambung `distinct()` ke relasi itu = error 3065 di MySQL,
  lolos di SQLite. Pakai `->reorder()` dulu. Relevan kalau `VolumetricGlasswareMentah`
  perlu daftar titik unik.

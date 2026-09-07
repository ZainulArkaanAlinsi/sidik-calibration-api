# Pertanyaan lab — Height Gauge 600 mm

**Sumber:** `Master_olda_Height_Gauge_600_mm_2026.xlsm` (sesi contoh `001-UBLK-05.26`,
PT GE Nusantara Turbine Services, kalibrasi 5 Mei 2026).
**Untuk:** Manajer Teknis Lab.
**Dari:** tim backend SIDIK, 7 September 2026.

Sepuluh butir di bawah ini **tidak menahan** alat ke-26 masuk sistem — semuanya sudah
diimplementasikan mengikuti masternya apa adanya, dan angka sesi contohnya sudah
direproduksi sampai 5·10⁻⁶. Yang diminta di sini keputusan METODE, yang bukan hak
kami untuk mengambil.

Tiap butir menyertakan angkanya, jadi bisa diputuskan **tanpa membuka Excel**.

Urutannya bukan urutan penting. Yang paling mendesak **§6**.

---

## §1 — Pembagi drift `/12` padahal selisihnya HARI · **prioritas tinggi**

`PERHITUNGAN U95%!K10`:

```
(0,02 + 0,00025 × Lmaks) × 1/1000 × ((DATABASE!X11 − DATABASE!W13) / 12)
```

`X11 − W13` menghasilkan **hari** (153,66 di sesi contoh), tapi dibagi 12 — angka yang
cuma masuk akal kalau selisihnya BULAN. Satuan komponennya sendiri ditulis `mm/th`,
dan master **Micrometer** memakai `/365` untuk komponen yang sebangun.

**Yang kami lakukan:** ditiru apa adanya (`/12`).

**Kenapa ditiru, bukan dibetulkan:** membetulkannya ke `/365` membuat drift ~30× lebih
kecil dan **U yang terbit LEBIH KECIL**. Aturan proyek melarang penyimpangan yang
diam-diam mengecilkan ketidakpastian, jadi yang dipertahankan versi konservatifnya.

**Angkanya** (pada umur drift master 153,66 hari):

| Pembagi | u(drift) | U yang terbit |
|---|---|---|
| `/12` (sekarang) | 0,00217690 mm | **0,0156680 mm** |
| `/365` | 0,00007157 mm | 0,0154996 mm |

Selisih U-nya −1,1 %.

> **Pertanyaan:** pembagi mana yang mengikat — `/12` atau `/365`? Kalau `/12`, apa
> satuan `mm/th` di kolom Satuan perlu dikoreksi jadi lain?

---

## §2 — Pembagi `√6` pada komponen yang berlabel `rect.`

`PERHITUNGAN U95%!N9 = SQRT(6)`, sementara `J9` menulis distribusinya `rect.` —
yang pembaginya `√3`. Kedelapan komponen lain konsisten (`rect.` → `√3`,
`normal` → `2`, `t-student` → `√n`); cuma komponen muai yang tidak.

**Yang kami lakukan:** ditiru (`√6`).

**Angkanya:** `u = Δα / √6 = 2·10⁻⁶ / 2,4495 = 8,165·10⁻⁷`. Dengan `√3` dia jadi
1,155·10⁻⁶ — naik 41 %, tapi sumbangannya ke `Σ(ui·ci)²` tetap ~1,5·10⁻⁸ dari total
5,64·10⁻⁵, jadi **U yang terbit praktis tidak bergeser**.

> **Pertanyaan:** `√6` itu disengaja (mis. distribusi segitiga / U-shaped), atau
> labelnya `rect.` yang benar dan pembaginya salah ketik?

---

## §3 — Dua nilai koefisien muai di satu workbook

Dua besaran yang menggambarkan hal yang sama, beda **67 %**:

| Sel | Nilai | Dipakai untuk |
|---|---|---|
| `PERHITUNGAN!P35` = `Q35` | 1,2·10⁻⁶ /°C | αs dan αt — suku koreksi `Y` tiap titik |
| `INPUT DATA!S24` (= 2 × `R24`) | 2,0·10⁻⁶ /°C | Δα — budget komponen #5, dan `ci` komponen #4 & #9 |

**Yang kami lakukan:** ditiru keduanya, masing-masing di tempatnya. Menyamakannya
akan menggeser suku koreksi tiap titik.

> **Pertanyaan:** mana yang mengikat? Kalau keduanya benar (mis. 1,2·10⁻⁶ = koefisien
> muai baja, 2·10⁻⁶ = rentang ketidakpastian antar dua benda), tolong konfirmasi
> supaya bisa kami tulis di dokumentasi — sekarang keduanya cuma angka telanjang di
> dalam rumus.

---

## §4 — Tabel "Pengukuran Kesejajaran Muka Ukur" seluruhnya `#REF!`

`SERTIFIKAT` baris 18–20 (posisi **Atas / Tengah / Bawah**, kolom `F18:M20`) —
**sembilan sel `#REF!`** yang ikut terbit di sertifikat pelanggan. Seluruh rumusnya
menunjuk `PERHITUNGAN!#REF!`.

Sheet `PERHITUNGAN` sekarang **tidak punya blok tiga-posisi itu sama sekali**. Yang
ada cuma Blok 1 (Paralelisme Ujung Scriber — tiga pembacaan, tanpa label posisi).

**Yang kami lakukan:** bagian ini **tidak dicetak**. Kami tidak mengarang isinya.
Sertifikat kami mencetak Blok 1 apa adanya (Max, Min, Hasil, Result), yang memang
punya jalur hidup.

> **Pertanyaan:** apakah tabel Atas/Tengah/Bawah masih bagian dari metode? Kalau ya,
> dari blok mana angkanya, dan bagaimana bentuk pengambilannya di lapangan?

---

## §5 — Paralelisme dihitung `STDEV(Max; Min)`, bukan `Max − Min`

`INPUT DATA`:

```
Max    = MAX(C31:F31)        = 0,002
Min    = MIN(C31:F31)        = 0
Hasil  = STDEV(Max; Min)     = 0,0014142135623731
Result = IF(Hasil <= 0,01; "Good"; "Not Good")
```

`STDEV` atas **dua** angka itu `|Max − Min| / √2` — bukan `Max − Min`.

**Yang kami lakukan:** ditiru (`0,0014142`).

**Angkanya:** 0,0014142 vs 0,002 di sesi contoh. Keduanya lulus batas 0,01 mm, jadi
hari ini vonisnya sama. Tapi keduanya melewati batas pada sebaran yang **berbeda**:
`Max − Min` menyatakan "Not Good" mulai sebaran 0,010 mm, `STDEV` baru mulai 0,0141 mm
— jadi ada pita sebaran 0,010–0,014 mm yang divonis **berbeda** oleh dua rumus itu.

> **Pertanyaan:** yang mengikat `STDEV(Max; Min)` atau `Max − Min`? Ini menentukan
> vonis kelulusan, bukan cuma angka cetak.

---

## §6 — Status akreditasi · **PRIORITAS SATU**

**Height Gauge TIDAK ada di lampiran akreditasi LK-285-IDN.** Kelompok Panjang di
`database/data/kemampuan-kalibrasi.json` cuma memuat Sieve, Micrometer, Vernier
Caliper, dan Dial Indicator.

Masternya sendiri mengakuinya: sel lantai CMC (`PERHITUNGAN U95%!AA19`) **KOSONG**,
jadi `AA20 = MAX(AA18:AA19)` selalu memulangkan U hitung telanjang. `DATABASE!S5:T5`
memang memuat `CMC 0-300mm = 15 µm` (defined name `CMC_UTM`), tapi (a) tidak
tersambung ke sheet U95 mana pun, dan (b) alatnya 600 mm — di luar pita itu sendiri.
Kami **tidak** memungutnya.

### Yang sudah DIPERBAIKI (7 Sep 2026)

Sampai 7 Sep 2026 sertifikat yang terbit **membawa klaim akreditasi**, dan bukan cuma
milik Height Gauge:

- `CertificateSnapshotBuilder` membekukan `organization.no_akreditasi` ke SETIAP
  snapshot **tanpa satu pun pemeriksaan alat**.
- `resources/views/sertifikat/pdf.blade.php` mencetaknya — atau memakai
  `public/images/kop-surat.png`, yang memuat `LK-285-IDN` **di dalam gambarnya**.

**Gas Detector** sudah di luar lampiran sejak alat ke-10 dan sertifikatnya terbit
dengan klaim yang sama sejak saat itu.

Sekarang klaimnya **bersyarat**: `CalibrationProfile::dalamLingkupAkreditasi()`
dipatok per profil (bawaan `true`; `false` untuk Gas Detector & Height Gauge),
dibekukan ke snapshot, dan kop banner ikut disetop untuk sesi di luar lingkup —
menyembunyikan kop teks saja tidak cukup, karena nomornya ada di dalam gambar.

Nama & alamat lab tetap tercetak; keduanya bukan klaim akreditasi. Dijaga
`KlaimAkreditasiIkutLingkupTest` (5 test), yang juga mengadu daftarnya ke
`CmcSemuaProfilTest::DILUAR_LAMPIRAN` supaya dua daftar itu tidak menyimpang.

### Yang MASIH perlu diputuskan lab

Snapshot itu **beku**, dan cetak ulang membaca snapshot. Jadi sertifikat Height Gauge
& Gas Detector yang **sudah terbit** tetap membawa klaim lamanya. Itu keputusan mutu,
bukan keputusan kode — kami tidak mengubah dokumen yang sudah di tangan pelanggan.

> **Pertanyaan (dua, dan keduanya perlu jawaban):**
> 1. Bagaimana perlakuan sertifikat Height Gauge & Gas Detector yang **sudah terbit**
>    dengan klaim akreditasi? Ditarik, diterbitkan ulang, atau dibiarkan?
>    (Perintah `sertifikat:bangun-ulang-snapshot` bisa menerbitkan ulang snapshot-nya
>    kalau itu yang diputuskan — tapi itu MENGUBAH dokumen yang sudah beredar.)
> 2. Perlu kop pengganti untuk lingkup non-akreditasi? Sekarang sesi di luar lingkup
>    jatuh ke **kop teks** (nama + alamat lab, tanpa baris akreditasi) karena
>    `kop-surat.png` memuat nomornya di dalam gambar. Kalau lab mau banner sendiri
>    tanpa nomor akreditasi, kirimkan berkasnya.

---

## §7 — Jeda terbit: 4 hari atau 9 hari?

`INPUT DATA!D76 = O16 + 4` — Issuance Date = tanggal kalibrasi **+ 4 hari**.

Sel itu sendiri `#VALUE!`, karena `O16` berisi **teks** `'05 Mei 2026'` dan bukan
tanggal. Jadi Issuance Date tidak pernah benar-benar terhitung di masternya.

Di sistem kami tanggal itu kolom `date` sungguhan, jadi masalah `#VALUE!`-nya hilang
sendiri. Yang tersisa aturannya.

Catatan teknisi di `SERTIFIKAT!Y10:Y12` menunjukkan angka lain:

| Catatan | Tanggal |
|---|---|
| sidik | 05 Mei 2026 |
| Presisi | 11 Mei 2026 |
| terbit | 14 Mei 2026 |

Itu **9 hari**, bukan 4.

> **Pertanyaan:** jeda terbit yang mengikat berapa hari, dan dihitung dari tanggal
> kalibrasi atau dari tanggal selesai analisis?

---

## §8 — Empat catatan lepas di `PERHITUNGAN U95%!B20:D21`

Empat teks tanpa penjelasan, di bawah tabel budget:

| Sel | Isi |
|---|---|
| `B20` | `global 1.7 um` |
| `D20` | `bblm 4.4 um` |
| `B21` | `gmi 2.5 mm/m` |
| `D21` | `gis 0.57 mm` |

Dua di antaranya jelas jejak sumber komponen:

- `bblm 4.4 um` → komponen **Meja Granit (meja rata)**, `u = 0,0044 mm`. Cocok persis.
- `global 1.7 um` → cocok dengan `U95` sertifikat thermohygro TH-1 (1,7 °C), tapi
  satuannya ditulis `um`.

Dua sisanya (`gmi 2.5 mm/m`, `gis 0.57 mm`) **tidak cocok dengan satu pun komponen**
yang dipakai. Komponen Kesalahan Geometri memakai `0,5/1000 = 0,0005 mm` — bukan 2,5
maupun 0,57.

> **Pertanyaan:** keempatnya singkatan apa, dan mana yang MENGIKAT komponen Geometri
> serta Meja Granit? Kalau `gis 0.57 mm` yang mengikat geometri, komponennya berubah
> 1140× dan U yang terbit ikut berubah besar.

---

## §9 — Tabel **Inside** yang tidak pernah dipakai

`Std_CaliperCek!C23:J32` memuat tabel Inside lengkap (sepuluh nominal 25..600 mm,
koreksi +0,3 sampai −1,1 µm), dan defined name `Nom_Inside` menunjuk ke sana.

Tapi **tidak ada satu pun rumus jalur Height Gauge yang memakainya** — kesepuluh
`VLOOKUP` titik memakai `Nom_Outside`.

**Yang kami lakukan:** tabelnya ikut disalin ke
`database/data/tabel-standar-height-gauge.json` (supaya pembaca berikutnya tidak
mengira lab kehilangan angkanya), tapi **tidak disambungkan** ke mesin hitung.

> **Pertanyaan:** tabel Inside memang tidak dipakai untuk Height Gauge, atau ada mode
> kalibrasi (mis. pengukuran dalam / rahang dalam) yang belum masuk master?

---

## §10 — Suhu UUT vs suhu Caliper Checker

`PERHITUNGAN!M35` (suhu Caliper Checker) dan `N35` (suhu UUT) **dua-duanya 20,25 °C**,
dan itu persis `(20,2 + 20,3) / 2` — rata-rata suhu ruangan. Akibatnya `δϴ = T35 = 0`,
dan komponen budget #9 ("Selisih suhu Height Gauge dengan Caliper Checker") selalu nol
menurut konstruksi.

**Yang kami lakukan:** keduanya diturunkan dari rata-rata suhu ruangan, sama seperti
Micrometer — lembar kerjanya karena itu tidak punya kotak untuk keduanya. Jalur
suhu-UUT-terpisah tetap hidup di kode dan sudah diuji, jadi kalau jawabannya "diukur
terpisah", yang berubah cuma sisi pemanggil.

**Kenapa ini penting:** jawabannya menentukan apakah kejanggalan berikut berdampak
nyata. Master mengisi kolom termal (`M`..`T`) **cuma di baris 35** — titik 25 mm;
baris 38..62 kosong dan rumus `Y`-nya membaca sel kosong sebagai nol. Selama `δϴ = 0`
itu tidak menggeser apa pun. Begitu lab mulai mencatat suhu UUT ≠ suhu standar, di
master **cuma titik pertama yang terkoreksi** dan sembilan lainnya diam-diam salah.

Kami **tidak meniru** yang itu: suku termal kami hidup di kesepuluh titik, dan arahnya
ditegakkan
`HeightGaugeMasterTest::test_dengan_delta_suhu_bukan_nol_koreksi_titik_terakhir_ikut_berubah`.

> **Pertanyaan:** suhu UUT memang diturunkan dari suhu ruangan, atau diukur terpisah
> tapi belum dicatat di lembar? Kalau diukur terpisah, tolong tambahkan kotaknya di
> revisi kertas berikutnya.

---

## Lampiran — angka sesi contoh yang sudah direproduksi

Kesepuluh koreksi dan kelima agregat cocok master sampai 5·10⁻⁶
(`HeightGaugeMasterTest`, 17 test / 87 assertion):

```
Σ(ui·ci)²  = 5,6417135376875704e-05
uc         = 0,0075111340939218825   mm
veff       = 20,474220669021
k          = 2,085963447265865
U          = 0,01566795116743346     mm
```

**Satu angka yang SENGAJA berbeda:** sesi contoh yang ditanam sistem menerbitkan
**U = 0,0156260 mm**, bukan 0,0156680 mm. Sebabnya cuma tanggal — master menghitung
umur drift dari `NOW()` (`DATABASE!X11` = 11 Juni 2026, 153,66 hari), sedangkan kami
dari **tanggal kalibrasi sesi** (5 Mei 2026, 116 hari) supaya angkanya bisa diulang.
Selisihnya 0,27 %, dan seluruhnya berasal dari tanggal — bukan dari pengukuran.

`NOW()` di master berarti U95 sesi yang sama **berubah tiap kali berkasnya dibuka**.
Kalau lab ingin angka masternya yang mengikat, tolong beri tahu — tapi angka itu tidak
bisa direproduksi tahun depan.

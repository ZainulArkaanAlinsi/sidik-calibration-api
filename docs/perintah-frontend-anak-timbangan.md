# Anak Timbangan (OIML R111) — alat ke-29, serah terima ke mobile

Dokumen ini berdiri sendiri: semua yang dibutuhkan repo
`sidik-calibration-mobile` untuk menampilkan lembar Anak Timbangan ada di sini.
Tidak perlu membuka workbook master maupun kode server.

- **Kode profil:** `anak_timbangan`
- **Nomor formulir:** `SIDIK-FM-CAL-0541_Rev.0`
- **Nomor Instruksi Kerja:** `SIDIK-IK-CAL-0535_Rev.0`
- **Satuan:** gram (`g`) di seluruh lembar dan seluruh sertifikat
- **Akreditasi:** **TIDAK** — lembar ini `(Non KAN)`, jadi `nomor_lingkup`
  memang tidak dikirim dan sertifikatnya terbit tanpa logo/nomor KAN

---

## 1. Di mana alat ini dibuat di server

Supaya jelas dan bisa ditelusuri, ini daftar lengkap berkas yang lahir atau
berubah untuk alat ke-29.

**Baru:**

| Berkas | Isi |
|---|---|
| `app/Services/Calibration/Profiles/AnakTimbanganProfile.php` | bentuk lembar + `hitungPerGrup()` |
| `app/Services/Calibration/AnakTimbanganCalculator.php` | mesin hitung (ABBA, apung, budget) |
| `app/Services/Calibration/TabelStandarAnakTimbangan.php` | pembaca tabel standar |
| `app/Support/AnakTimbanganMentah.php` | penyusun ulang baris mentah + blok sesi |
| `database/data/tabel-standar-anak-timbangan.json` | tabel standar (**digenerate**) |
| `database/data/sesi-master-anak-timbangan.json` | masukan sesi contoh (**digenerate**) |
| `database/ocr-templates/anak_timbangan-v1.json` | rangka geometri OCR (belum terverifikasi) |
| `database/seeders/AnakTimbanganSeeder.php` | sesi contoh `DEMO-AT-001` |
| `database/seeders/AnakTimbanganCapabilitySeeder.php` | baris kemampuan, CMC nol |
| `docs/skrip/gen-tabel-standar-anak-timbangan.py` | generator tabel standar |
| `docs/skrip/gen-sesi-anak-timbangan.py` | generator masukan sesi contoh |
| `tests/Unit/AnakTimbanganMasterTest.php` | adu ke master, 1011 pengaduan |
| `tests/Fixtures/anak-timbangan-master.json` | 20 titik master + nilai harapannya |
| `docs/pertanyaan-lab-anak-timbangan.md` | 23 pertanyaan bernomor |

**Diubah:**

| Berkas | Perubahan |
|---|---|
| `app/Services/Calibration/CalibrationProfileRegistry.php` | satu baris `new AnakTimbanganProfile` |
| `app/Services/CalibrationValidator.php` | menyambung `AnakTimbanganMentah::dari()` |
| `app/Console/Commands/HitungUlangSesi.php` | cabang + konteks anak timbangan |
| `database/seeders/DatabaseSeeder.php` | memanggil `AnakTimbanganSeeder` |
| `database/seeders/KemampuanKalibrasiSeeder.php` | memanggil seeder kemampuannya |

---

## 2. Bentuk lembar kerja

Lima bagian, urutannya mengikat:

```
identitas_alat > pemilik > usage_check > hasil > penutup
```

### 2.1 `identitas_alat` — 21 field

Yang **baru** dan tidak ada di alat lain:

| Kode field | Label | Tipe | Catatan |
|---|---|---|---|
| `spesifikasi_alat.anak_timbangan.kelas_uut` | Class (UUT) | pilihan | E1/E2/F1/F2/M1/M2/M3 |
| `spesifikasi_alat.anak_timbangan.kelas_standar` | Class (Standar) | pilihan | idem |
| `spesifikasi_alat.anak_timbangan.kapasitas_g` | Kapasitas Alat | angka | g |
| `spesifikasi_alat.anak_timbangan.timbangan` | Timbangan yang Dipakai | pilihan | 5 neraca, label memuat kapasitas & resolusi |
| `spesifikasi_alat.anak_timbangan.meter_lingkungan` | TH Used | pilihan | `Thermobarometer` + `TH-1`…`TH-7` |
| `spesifikasi_alat.anak_timbangan.suhu_awal` / `_akhir` | Suhu Ruangan | angka | °C |
| `spesifikasi_alat.anak_timbangan.kelembaban_awal` / `_akhir` | Kelembapan | angka | %RH |
| `spesifikasi_alat.anak_timbangan.tekanan_awal` / `_akhir` | Tekanan Udara | angka | hPa |

> **`kelas_uut` dan `timbangan` MENGUBAH ANGKA.** Keduanya harus masuk daftar
> pemicu hitung ulang di mobile (`_kodePenentuAngka` di
> `lib/screens/calibration/lembar_kerja_state.dart`), sejajar dengan
> `spesifikasi_alat.flowmeter.varian_metode` dan `…kode_timbangan`:
>
> ```dart
> 'spesifikasi_alat.anak_timbangan.kelas_uut',
> 'spesifikasi_alat.anak_timbangan.kelas_standar',
> 'spesifikasi_alat.anak_timbangan.timbangan',
> ```
>
> `kelas_uut` menentukan kolom mana yang dibaca di tabel densitas DAN baris mana
> di tabel MPE; `timbangan` memasok dua dari enam komponen budget. Kalau
> ketiganya tidak memicu hitung ulang, teknisi mengganti kelas dan angkanya diam
> saja.

Field lingkungan **tidak** memakai `suhu_awal`/`kelembaban_awal` tingkat sesi
seperti alat lain — dia punya salinannya sendiri di dalam blok, karena
**selisih** kedua ujungnya dipakai (`U95 = √(U95_meter² + Δ²)`), bukan cuma
rata-ratanya.

### 2.2 `usage_check` — 12 baris tercetak

Lima neraca dan tujuh set anak timbangan standar, lengkap dengan merk/tipe.
Semuanya sudah diseed sebagai baris `Standard`, jadi tidak ada lagi yang tampil
"belum terdaftar di master standar".

### 2.3 `hasil` — EMPAT tabel, bukan satu

Ini yang paling berbeda dari alat lain dan yang paling gampang salah render:

| `grup` | `offset_kunci` | Judul |
|---|---|---|
| `at_s1` | 0 | Standard (S1) — penimbangan standar, pertama |
| `at_t1` | 1000 | UUT (T1) — penimbangan alat, pertama |
| `at_t2` | 2000 | UUT (T2) — penimbangan alat, kedua |
| `at_s2` | 3000 | Standard (S2) — penimbangan standar, kedua |

Masing-masing **10 baris keping × 3 pengulangan** (`X1 X2 X3` di kertas),
`titik_bisa_diubah = true` (nominal keping diketik teknisi), satuan `g`.

Keempatnya ber-`tahap` **sama** (`sesudah_adjustment`) dan berbaris nominal
sama. Tanpa `offset_kunci`, kunci barisnya bertabrakan di layar dan angka yang
diketik di kotak `S1` muncul di kotak `T1` — sudah nyata di Timbangan dan Height
Gauge.

**Urutan keempatnya MENGIKAT.** Rumusnya

```
de = (T1 − S1 − S2 + T2) / 2
```

punya tanda yang berbeda tiap suku. Baris yang tertukar membalik **arah** koreksi
kepingnya, tanpa satu pun error.

### 2.4 `no_identitas` — belum ada field-nya, dan itu disengaja

Server **mewajibkan** `spesifikasi_alat.anak_timbangan.identitas[<titik_ke>]`
untuk keping yang nominalnya kembar (dua 200 g, dua 20 g, dua 2 g, dua 0,2 g,
dua 0,02 g). Titik yang tidak punya penanda **tidak diterbitkan**, dengan alasan
yang kebaca.

Bentuknya peta `{ "<titik_ke>": "<teks>" }`. Kertasnya sudah punya kotak
`No Identitas :` di tiap blok keping; mobile perlu memasangnya sebagai satu
kotak teks per baris tabel. **Ini pekerjaan mobile yang tersisa** — lihat §5.

---

## 3. Yang dicetak di sertifikat

| Kolom | Isi |
|---|---|
| `titik_ukur` | nominal keping (g) |
| `rata_rata` | **massa konvensional** `mT` (g) — bukan rata-rata pembacaan neraca |
| `ketidakpastian_diperluas` | U95 (g) |
| `keputusan` | **null** — sesi ini tidak divonis PASS/FAIL |

`judulKolomUut()` = `Conventional Mass`. Desimal sertifikat dan U95 dua-duanya
**8**.

> **Satuan U95 beda dari sertifikat master, angkanya sama.** Master mencetak
> massa dalam gram dan ketidakpastian dalam **miligram** di baris yang sama
> (`0,09884965 g` … `0,1223 mg`). Di sini keduanya gram (`0,00012231 g`), supaya
> satu baris tidak memuat dua satuan. Kalau lab meminta tampilan mg, itu
> perubahan penyajian di blade sertifikat — bukan perubahan angka.

---

## 4. Kenapa sebagian titik tidak terbit

Server **menolak** titik, bukan menerbitkannya dengan angka yang salah. Mobile
perlu menampilkan alasannya apa adanya — semuanya sudah berbahasa manusia:

| Sebab | Contoh alasan |
|---|---|
| Salah satu peran ABBA kosong | *"Titik 3 belum punya pembacaan at_t2…"* |
| Nominal tidak ada di tabel keping standar | *"nominal 0,3 g nggak ada di tabel keping standar"* |
| Densitas kelas itu tidak ditabelkan | *"tabel densitas nggak punya baris 0,02 g untuk kelas F1"* |
| Keping kembar tanpa penanda | *"`no_identitas` wajib diisi"* |
| `\|de\|` > 10 × MPE (pola salah ketik satu digit) | *"…lebih dari 10× MPE kelas F1 pada 10 g (0,2 mg)"* |
| Blok sesi belum lengkap | *"…suhu, kelembaban, dan tekanan wajib punya nilai awal DAN akhir"* |

Keenam gerbang itu lahir dari cacat nyata di sertifikat master: lima keping
terbit sebagai `#VALUE!`, dan satu keping 10 g terbit sebagai **5,500163 g** —
meleset 45 % — karena satu digit hilang saat mengetik.

Ada juga tiga **peringatan** sesi (boleh dilewati admin, tidak menahan):
`anak_timbangan_diluar_akreditasi`, `anak_timbangan_densitas_disengketakan`,
`anak_timbangan_neraca_terlalu_kasar`.

---

## 5. Yang tersisa di sisi mobile

1. **Tiga kode pemicu hitung ulang** di `_kodePenentuAngka` (§2.1).
2. **Kotak `No Identitas` per baris tabel**, menulis ke
   `spesifikasi_alat.anak_timbangan.identitas[<titik_ke>]` (§2.4).
3. **Regenerasi `contoh_lembar_kerja_*.dart`** dari server hidup —
   `php docs/skrip/gen-contoh-lembar-kerja.php`.
4. **Render empat tabel berdampingan** kalau layarnya muat; kalau tidak,
   berurutan dengan judul perannya jelas. Yang tidak boleh: menampilkannya
   sebagai satu tabel dengan empat kolom tanpa label peran.
5. **Pindai foto masih `didukung = false`.** Geometri di
   `anak_timbangan-v1.json` masih tebakan generator dan belum diadu ke foto
   formulir asli. Di lembar ini akibatnya lebih buruk daripada di alat lain:
   empat peran ABBA yang tertukar membalik **tanda** koreksi kepingnya.

---

## 6. Yang masih menunggu jawaban lab

23 butir bernomor di `docs/pertanyaan-lab-anak-timbangan.md`. Yang paling
menentukan untuk angka yang tercetak:

| § | Butir | Kalau dijawab lain, yang berubah |
|---|---|---|
| 1 | Keterulangan neraca: sebaran antar-hari atau gabungan harian | **U95 seluruh sertifikat naik ~1,9×** |
| 2 | Nasib sertifikat `001-CAL-126` yang sudah terbit | — |
| 6 | Tabel densitas → OIML R111 B.7 lengkap | lima titik nominal kecil jadi bisa terbit |
| 10 | Vonis lulus/tidak lulus dicetak | kolom baru di sertifikat |
| 20 | Dimensi `ci` apung | U95 naik 0,35 % |

Sampai §1 dan §6 dijawab, **angka yang keluar server sah dan bisa
dipertanggungjawabkan** — dia sama persis dengan yang dikeluarkan master, kecuali
di tempat yang masternya rusak rujukan dan sudah dibetulkan (§2, §5, §7).

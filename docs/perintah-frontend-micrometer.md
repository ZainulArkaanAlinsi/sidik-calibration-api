# Perintah frontend — lembar **Micrometer** (alat ke-25, kelompok Panjang)

Dokumen berdiri sendiri. Tempel ke sesi kerja `sidik-calibration-mobile`; tidak perlu membaca
percakapan backend.

**Status backend:** BERES 4 Sep 2026, lalu **disetel ulang ke kertas resmi** hari yang sama.
Profil, lembar kerja, mesin hitung, jalur simpan, jalur hitung ulang, **tiga** sesi contoh
ter-seed (dari empat rentang — yang keempat sengaja tidak, §1), template OCR, dan
`MicrometerMasterTest` (diadu ke empat workbook master) hijau.

**Status HP:** BERES 4 Sep 2026 — mock lembar, cabang mode mock, pertanyaan satuan, dan `test/micrometer_lembar_test.dart` (9 test) hijau; `flutter test` penuh 1.530 test hijau, `flutter analyze` bersih.

> ⚠️ **Kalau kamu pernah membaca revisi dokumen ini yang lama, buang isinya.** Kertas lembar
> kerja resmi (`SIDIK-FM-CAL-0522.{A,B,C,D}_Rev.1`) turun setelah backend selesai, dan bentuk
> lembarnya berubah banyak: bagian `pra_evaluasi` dan `pemeriksaan_muka` **hilang**, tabel
> `mikro_balok` **hilang**, dua field suhu balok/UUT **hilang**, dan nominal titiknya sekarang
> **pra-cetak** — tidak bisa diubah teknisi. Yang di bawah ini yang berlaku.

---

## 1. Yang berubah buat HP

| | |
|---|---|
| Kategori | `panjang` — **sudah ada**, tidak ada kategori baru. Lihat §1a |
| Kode profil | `micrometer` |
| Nama kemampuan | `Micrometer` (ejaan lampiran akreditasi no. 34) |
| Alias yang dikenal | `Mikrometer`, `Outside Micrometer`, `Micrometer Outside`, `Mikrometer Luar` |
| Endpoint bentuk | `GET /api/worksheet-schema?equipment_id=…` — sama seperti 24 alat lain |
| Nomor formulir | **empat, dipilih dari kapasitas alat** — lihat §2 |
| Satuan simpan | **selalu `mm`**, apa pun skala alatnya. Lihat §5 — ini yang paling gampang salah |

**TIGA** sesi contoh ter-seed, dari empat workbook master yang dikirim lab:

| Sesi | Alat | Serial | Kapasitas | Varian | Ter-seed? |
|---|---|---|---|---|---|
| `0106-CAL-1023` | Micrometer Digital (Mitutoyo IP65) | `ZQ-100` | 50 mm | B | ya, terbit |
| `002-UB.P-11-20` | Outside Micrometer (Mahr) | `61010481` | 75 mm | C | ya, terbit |
| `003-UB.P-11-20` | Digital Outside Micrometer (Mitutoyo) | `67426681` | 100 mm | D | ya, terbit |
| `095-CAL-324` | Micrometer (Mitutoyo Analog) | `IMTE-FQS-015` | 25 mm | A | **tidak**, lihat di bawah |

Pakai `ZQ-100` sebagai sumber mock (§9).

**Kenapa varian A tidak ditanam.** Datanya ada utuh di
`database/data/sesi-master-micrometer.json`, jadi bukan soal berkas yang hilang. Yang bikin
dia tidak layak jadi sesi contoh: blok pra-evaluasinya berisi **635,0 sepuluh kali** — nilai
kapasitas hasil salah pilih satuan (`inch`) yang bocor ke sana. Simpangan bakunya NOL, jadi
sesi itu bakal terbit dengan komponen keterulangan nol yang lalu ditutupi lantai CMC dan
tampak wajar (§7 sebab no. 2). Membetulkannya berarti MENGARANG data keterulangan, dan
keterulangan itu yang jadi dasar seluruh budget.

Jalur blokirnya tetap terjaga tanpa sesi ini: `MicrometerMasterTest` dan `MicrometerSesiTest`
menegakkan ketiga sebab §7 dari fixture, bukan dari sesi ter-seed. Buat HP artinya cuma satu
hal: **jangan berharap ada sesi berstatus DIBLOKIR di database contoh** — status itu lahir dari
data yang dikirim teknisi, bukan dari sesi bawaan.

## 1a. TIDAK ada kategori baru — Micrometer sudah ada di **Panjang**

Kalau kamu membaca revisi lama dokumen ini: dia menyuruh menambah kategori `dimensi`. **Jangan.**

Lampiran akreditasi LK-285-IDN punya **sepuluh** kelompok pengukuran, dan Micrometer baris
no. 34 ada di **Panjang** — bareng Sieve, Vernier Caliper, dan Dial Indicator. Baris CMC-nya
sudah ter-seed di situ sejak dulu oleh `CalibrationCapabilitySeeder`, dan
`MockCategoryService` di HP pun sudah mencantumkannya di bawah `panjang`.

Backend sempat membuat kategori `Dimensi` sendiri (4 Sep 2026, dibetulkan hari yang sama).
Yang lahir dari situ kategori HANTU: kartu kesebelas di layar pilih kategori, kosong waktu
dibuka, sementara alat contohnya justru duduk di sana. Nol error di kedua sisi. Sekarang
dijaga `KategoriAlatIkutLampiranTest`.

**Yang perlu dilakukan HP soal kategori: tidak ada.** Kartu `Panjang` sudah tampil, dan
Micrometer sudah ada di dalamnya. Yang belum ada cuma LEMBARnya.

## 2. Nomor formulirnya EMPAT — dan dipilih server, bukan HP

Kertasnya empat, satu per rentang. Server memilihnya dari `equipments.range_max`, lalu
mencetaknya di `kode_dokumen` dan menyusulkan rentangnya ke `judul`:

| Kapasitas alat | `kode_dokumen` | `judul` | Lantai CMC |
|---|---|---|---|
| 0 < x ≤ 25 mm | `SIDIK-FM-CAL-0522.A_Rev.1` | `Calibration Work Sheet - Micrometer (0-25 mm)` | 0,83 µm |
| 25 < x ≤ 50 mm | `SIDIK-FM-CAL-0522.B_Rev.1` | `… (25-50 mm)` | 0,87 µm |
| 50 < x ≤ 75 mm | `SIDIK-FM-CAL-0522.C_Rev.1` | `… (50-75 mm)` | 0,91 µm |
| 75 < x ≤ 100 mm | `SIDIK-FM-CAL-0522.D_Rev.1` | `… (75-100 mm)` | 0,91 µm |
| di luar itu | **`null`** | `Calibration Work Sheet - Micrometer` | — (sesi diblokir) |

`kode_dokumen` juga **`null`** waktu bentuk lembar diminta tanpa `equipment_id`. Itu bukan
error — varian belum bisa ditentukan, dan menebak salah satunya berarti mencetak nomor
formulir yang bukan miliknya di kop lembar terakreditasi. UI: sembunyikan barisnya kalau
`null`, jangan cetak strip.

## 3. Bentuk lembarnya — ENAM bagian

Yang jadi baris titik di sertifikat cuma `hasil`:

| Bagian (`kode`) | Judul | Bentuk |
|---|---|---|
| `identitas_alat` | Identitas Alat dan Data Customer | field — termasuk dropdown **Satuan Alat**, lihat §5 |
| `pemilik` | Data Customer | field |
| `usage_check` | Standard Used | 1 baris centang (Gauge Block Standard) |
| `hasil` | Data Kalibrasi | **1 `tabel`** — 11 baris pra-cetak × 5 pembacaan, lihat §4 |
| `evaluasi` | Evaluasi | **1 `tabel`** — 1 baris × 10 pembacaan, lihat §6 |
| `penutup` | Catatan & Tanda Tangan | field |

Tidak ada bagian `pra_evaluasi`, tidak ada `pemeriksaan_muka`, dan tidak ada kotak suhu balok
ukur maupun suhu UUT. Ketiganya ada di sheet `INPUT DATA` Excel tapi **tidak** di kertas.

Ketiadaan kotak suhu itu bukan kelalaian kertas: di keempat workbook master
`suhu_balok = suhu_uut = (suhu_awal + suhu_akhir) / 2`, jadi keduanya **diturunkan** server
dari suhu ruangan yang memang dipungut kertas (`suhu_awal` + `suhu_akhir` di
`identitas_alat`). Jangan minta ulang di HP.

## 4. Tabel `hasil`: nominalnya PRA-CETAK, teknisi cuma mengisi pembacaan

Ini beda paling penting dari 24 lembar sebelumnya, dan yang paling gampang salah kalau
penggambar lembarnya menganggap semua tabel sama.

```jsonc
{
  "tahap": "sesudah_adjustment",
  "grup": "mikro_pembacaan",
  "judul": "Pembacaan Alat",
  "judul_nilai": "Nominal Balok Ukur",
  "judul_pengulangan": "Pembacaan Alat",
  "titik_bisa_diubah": false,          // ← ini
  "baris": [ /* 11 baris, titik_ukur SUDAH TERISI */ ],
  "kolom": [ { "kode": "pembacaan", "tipe": "angka", "satuan": "mm" } ],
  "pengulangan": [1, 2, 3, 4, 5]
}
```

`titik_bisa_diubah: false` berarti: **kotak `titik_ukur` dirender read-only, tombol
tambah/hapus baris tidak muncul.** Tumpukan keping balok ukur yang membentuk tiap nominal
ditentukan Instruksi Kerja, bukan dipilih di lapangan — dan server menyusunnya sendiri dari
varian, jadi HP tidak pernah mengirim nominal balok ukur.

Sebelas nominalnya per varian (mm):

| Varian | Titik 1 … 11 |
|---|---|
| A (0-25) | 0 · 2,5 · 5,1 · 7,7 · 10,3 · 12,9 · 15 · 17,6 · 20,2 · 22,8 · 25 |
| B (25-50) | 25 · 27,5 · **31** · 32,7 · 35,3 · 37,9 · 40 · 42,6 · 45,2 · 47,8 · 50 |
| C (50-75) | 50 · 52,5 · **51** · 57,7 · 60,3 · 62,9 · 65 · 67,6 · 70,2 · 72,8 · 75 |
| D (75-100) | 75 · 77,5 · 80,1 · 82,7 · 85,3 · 87,9 · 90 · 92,6 · 95,2 · 97,8 · 100 |

**Yang ditebalkan itu BENAR, jangan "dibetulkan".** Titik 3 varian B dan C memang keluar dari
pola +2,6 mm — dan itu sudah diadu ke master: totalnya 30,99997 mm dan 51,00025 mm. Yang
menentukan nominal adalah tumpukan keping yang tersedia di set balok ukur, bukan deret
aritmetika. Kalau UI menormalkannya jadi 32,9 dan 55,1, seluruh koreksi titik itu meleset ~2 mm.

Waktu bentuk lembar diminta tanpa `equipment_id`, barisnya tetap sebelas tapi `titik_ukur`-nya
`null` dan `label`-nya `"Titik 1"`…`"Titik 11"`. Bentuk lembarnya benar, angkanya menyusul
begitu alatnya dipilih.

Payload `measurements` per titik:

```jsonc
{
  "titik_ukur": 25.0,        // dikirim (validasi dasar menuntutnya), TAPI server memakai
                             // nominal varian — kiriman HP kalah kalau beda
  "pembacaan": [25.001, 25.0, 25.001, 25.0, 25.001]  // satuan ALAT, dikonversi server ke mm
}
```

Kuncinya **`pembacaan`** — jalur datar yang sama dengan dua puluh empat lembar lain, karena
tabelnya memang satu kolom. Tidak ada kosakata `mikro_*` di payload; nama itu cuma hidup
sebagai `peran_sensor` di `raw_measurements` sisi server, tempat dia membedakan deret pembacaan
dari nominal balok ukur.

Server sempat menengok `measurements[].mikro_pembacaan` di sini — lihat §9a. Kunci itu tidak
pernah dikirim siapa pun, dan akibatnya nol baris tersimpan tanpa satu pun error.

Yang **tidak** dikirim HP sama sekali: tumpukan keping balok ukur. Server menyusunnya dari
varian dan menyimpannya ke `raw_measurements` di sana. Titik ke-12 dan seterusnya dibuang
diam-diam — barisnya terkunci sebelas, jadi kelebihan baris cuma bisa datang dari payload salah
bentuk.

## 5. Satuan: satu dropdown yang mengubah arti seluruh lembar

`spesifikasi_alat.micrometer.satuan` — `mm` (×1), `inch` (×25,4), atau `µm` (×0,001).

**Isi ini LEBIH DULU.** Server mengonversi pembacaan alat ke mm memakai faktor ini, sekali, di
ujung masuk. Nominal balok ukur tidak ikut dikonversi — sertifikat balok ukur selalu mm apa pun
skala mikrometernya.

Kenapa ini ditonjolkan: master lab sendiri salah di sini, dan sesi `IMTE-FQS-015` di §1 adalah
salinannya. Satuannya tersetel `inch` sementara angkanya diketik dalam milimeter; kapasitas 25
dikali 25,4 jadi 635 mm, jatuh di luar keempat pita CMC, dan koreksi yang tercetak
**−61 mm pada balok ukur 2,5 mm**. Tidak ada satu pun sel yang memprotes. Rinciannya di
`docs/pertanyaan-lab-micrometer.md` §1 dan §3.

Saran UI: taruh dropdown satuan di ATAS field kapasitas/resolusi, dan tampilkan nilai mm hasil
konversinya sebagai teks bantu di bawah kotaknya.

## 6. Baris `Evaluasi` WAJIB diisi — dari situ ketidakpastiannya lahir

**Pengulangan (Type A) TIDAK datang dari lima pembacaan tiap titik.** Dia datang dari satu
baris `Evaluasi` di kaki kertas: sepuluh pembacaan berulang di SATU titik.

```jsonc
{
  "tahap": "sesudah_adjustment",
  "grup": "pra_pembacaan",
  "judul": "Evaluasi (pembacaan berulang)",
  "titik_bisa_diubah": false,
  "offset_kunci": 1000,                                      // ← lihat §3 mock HP
  "simpan_ke": "spesifikasi_alat.micrometer.pra_evaluasi",   // ← BUKAN measurements
  "baris": [ { "nomor": 1, "titik_ukur": null, "label": "Evaluasi" } ],
  "kolom": [ { "kode": "pembacaan", "tipe": "angka", "satuan": "mm" } ],
  "pengulangan": [1, …, 10]
}
```

Perhatikan `simpan_ke`: isinya masuk **`spesifikasi_alat.micrometer.pra_evaluasi`**, **bukan**
`measurements`. Blok tingkat-sesi yang dipaksa jadi titik ukur lahir sebagai titik hantu yang
selalu gagal hitung ulang.

Bentuk yang dikirim HP sama seperti blok keterulangan Timbangan — cerminan tabelnya, bukan
larik datar. `LembarKerjaState._tanamTabelSpesifikasi()` sudah menghasilkannya sendiri, jadi
tidak ada yang perlu ditulis khusus:

```jsonc
"spesifikasi_alat": {
  "micrometer": {
    "satuan": "mm",
    "kapasitas_mm": 50.0,
    "resolusi_mm": 0.001,
    "pra_evaluasi": {
      "baris": [
        { "titik_ukur": 1.0, "pembacaan": [50.0, 50.0, 50.0, 49.999, /* … 10 angka */] }
      ]
    }
  }
}
```

Server meratakannya jadi sepuluh angka **dan mengonversinya ke mm** memakai faktor `satuan`
(§5) sebelum apa pun disimpan — sama seperti pembacaan tiap titik. Larik datar juga diterima
dan dikonversi dengan faktor yang sama; dua bentuk yang membawa angka sama tidak boleh berarti
beda.

Balok ukur yang dipakai baris ini ditentukan varian juga (A: 20 + 5 mm; B: 50; C: 75; D: 100),
jadi tidak ada tabel keping untuk diisi — kertasnya memang tidak punya.

**Kalau baris ini kosong ATAU isinya kurang dari dua angka, seluruh titik pulang sebagai
`belum_dihitung`** dengan alasan yang kebaca — bukan dihitung dengan pengulangan nol. Jadi UI
jangan memperlakukan bagian ini sebagai opsional walau `semua_kolom_opsional` bernilai `true`.

Yang satu pembacaan itu bentuk paling licin: simpangan bakunya jatuh ke nol, komponen
pengulangan hilang dari budget, U95 mendarat di lantai CMC — dan hasilnya kelihatan **wajar**.
Makanya server menolak menerbitkannya sama sekali, bukan menerbitkan angka yang lebih kecil.

## 7. Sesi bisa DIBLOKIR, dan itu disengaja

Ada **tiga** sebabnya, dan server memperlakukan ketiganya sama:

1. Kapasitas alat jatuh di luar keempat pita CMC terakreditasi (§2) — tidak ada lantai
   ketidakpastian yang bisa dipertanggungjawabkan.
2. Baris Evaluasi berisi kurang dari dua pembacaan (§6) — tidak ada simpangan baku, jadi
   komponen pengulangan tidak punya dasar.
3. **Resolusi alat belum diisi.** Kotaknya opsional di lembar, dan yang kosong terbaca nol —
   komponen resolusi budget ikut jadi nol. Pada sesi 25-50 mm U95 turun dari 0,8722 µm ke
   0,6638 µm, lalu **ditutupi lantai CMC 0,87 µm** sehingga yang tercetak 0,8700 dan tampak
   wajar. Selisihnya 0,25 %. Jadi UI sebaiknya menyorot kotak resolusi yang kosong sebelum
   kirim, sama seperti dropdown satuan (§5).

Yang **TIDAK** memblokir, walau ikut muncul di `belum_dihitung`: tanggal kalibrasi sesi lebih
awal dari sertifikat balok ukur standar yang tersimpan. Itu sesi historis — driftnya dianggap
nol, alasannya dicatat, dan sesinya tetap terbit di atas lantai CMC. Bedanya prinsipil:
pengulangan mengukur alat pelanggan itu sendiri, drift itu sifat standarnya.

Dalam ketiga sebab itu, server:

- memulangkan **`hitungan` KOSONG** — nol baris, bukan baris ber-U95 nol,
- memindahkan **semua** titik ke `belum_dihitung`, masing-masing dengan alasannya.

Khusus sebab no. 1, ada tambahan: temuan peringatan sesi berkode `micrometer_di_luar_cmc` yang
menyebut dugaan penyebabnya (satuan salah pilih). Sebab no. 2 dan no. 3 tidak punya peringatan
sesi tersendiri — alasannya cuma ada di `belum_dihitung`, jadi UI **harus** menampilkan daftar
itu, bukan cuma mengandalkan panel peringatan.

**Kenapa nol baris, bukan U95 = 0.** Baris ber-`ketidakpastian_diperluas` nol tetap tercetak di
sertifikat sebagai `± 0,000` — klaim pengukuran **sempurna**, yang lebih buruk daripada angka
0,735 µm yang sedang diperbaiki. Dan peringatannya sendiri tidak menahan apa pun: server
membungkusnya jadi temuan tingkat PERINGATAN yang boleh dilewati admin. Jadi yang menahan
ketiadaan barisnya.

UI harus menampilkan `belum_dihitung` itu menonjol dan **tidak** menampilkan tabel hasil kosong
seolah-olah sesinya cuma belum diisi.

## 8. Ketidakpastian terbit SEKALI per sesi, bukan per titik

Sertifikat master mencetak satu baris `Uncertainty U95% = ±` di bawah sebelas titik. Server
memulangkan angka yang sama di `ketidakpastian_diperluas` **tiap** baris hitungan — jadi UI
boleh menampilkannya satu kali di bawah tabel, bukan satu kolom per baris.

Sembilan komponen budget (semuanya µm): repeatability, resolusi, standard balok ukur, perubahan
suhu terhadap 20 °C, koefisien muai thermal, drift standard, lapisan wringing, kesalahan
geometri, selisih suhu mikrometer–balok ukur.

Komponen terakhir itu **selalu nol menurut konstruksi** — dia `|suhu_uut − suhu_balok|` dari dua
angka yang sama (§3). Dipertahankan di budget supaya susunannya cocok baris demi baris dengan
master; jangan disembunyikan di UI.

## 9. Yang sudah dikerjakan di HP — dan yang ternyata tidak perlu

Penggambar lembarnya sudah jauh lebih data-driven daripada yang diduga. Yang **sudah ada dan
tidak perlu disentuh**:

| | |
|---|---|
| Kategori | `Panjang` sudah tampil, Micrometer sudah di dalamnya (§1a) |
| `titik_bisa_diubah` | sudah didukung, dan bawaannya `false` — baris terkunci jalan sendiri |
| `simpan_ke` | sudah didukung generik; blok keterulangan Timbangan sudah memakainya |
| `offset_kunci` | sudah didukung generik |
| `belum_dihitung` | sudah dirender apa adanya di panel pratinjau |

Yang **dikerjakan**:

1. `lib/services/contoh_lembar_kerja_panjang.dart` — **digenerate** dari respons server alat
   `ZQ-100`, bukan diketik.
2. Cabang `'micrometer'` di `lembar_kerja_service.dart`. Tanpa itu mode mock memajang lembar
   pH tiga titik buffer untuk lembar Micrometer — nol error, cuma lembar yang salah.
3. `spesifikasi_alat.micrometer.satuan` masuk `_kodePenentuAngka`, jadi teknisi **ditanya**
   kalau satuannya belum dipilih. Bukan ditahan — `wajib: false` itu kontrak backend.
4. `test/micrometer_lembar_test.dart` — 9 test.

## 9a. Tiga cacat yang ketahuan justru waktu HP-nya dikerjakan

Ketiganya di sisi SERVER, ketiganya sudah ditambal, dan tidak satu pun menghasilkan error.
Ditulis di sini karena inilah alasan mock lembar wajib digenerate dari respons asli:

1. **Kolom tabel bernama `nilai`.** Dua puluh empat lembar lain memakai `pembacaan`, dan itu
   satu-satunya kode kolom yang dibaca jalur datar HP. Server menengok
   `measurements[].mikro_pembacaan` yang tidak pernah dikirim siapa pun — **nol baris
   tersimpan, nol hitungan**, tanpa error di kedua sisi.
2. **Blok Evaluasi bentuk tabel.** HP mengirim setiap tabel ber-`simpan_ke` sebagai cerminan
   tabelnya (`{baris: […]}`), bukan larik datar. Server menuntut larik datar → **422 di setiap
   sesi**, dengan keluhan yang menunjuk sepuluh angka yang sudah benar diisi teknisi.
3. **Pembacaan Evaluasi tidak dikonversi satuan.** Sekarang dikonversi, dan di **kedua**
   bentuk — dua bentuk yang membawa angka sama tidak boleh berarti beda.

Yang menangkap ketiganya: payload HP diadu ke bentuk lembarnya, bukan test yang merah.

## 10. Yang JANGAN dilakukan

- **Jangan** merender `titik_ukur` sebagai kotak isian di tabel `hasil`. `titik_bisa_diubah`
  bernilai `false`, dan nominal yang diketik ulang teknisi tetap kalah di server — jadi yang
  dilihat teknisi beda dari yang tercetak di sertifikat, tanpa satu pun error.
- **Jangan** "membetulkan" nominal titik 3 varian B (31) dan C (51). Lihat §4.
- **Jangan** mengonversi apa pun di HP. Yang dikirim angka MENTAH yang diketik teknisi; server
  yang mengubahnya ke mm memakai `spesifikasi_alat.micrometer.satuan`, dan dia melakukannya di
  tempat pakai — bukan waktu menyimpan. Kalau HP ikut mengonversi, angkanya dikali dua kali.
  (Server sempat mengonversi waktu menyimpan, dan itu bikin simpan-draft-lalu-simpan-lagi
  mengalikan 25,4 tiap kali: 1 inch → 25,4 → 645,16 mm. Sekarang tidak lagi.)
- **Jangan** mengirim baris Evaluasi sebagai `measurements` ber-`titik_ke`. Dia
  `spesifikasi_alat.micrometer.pra_evaluasi`, lihat `simpan_ke` (§6).
- **Jangan** mengganti kode kolom tabelnya jadi selain `pembacaan`. Jalur datar HP
  (`TitikState.toSubmission()`) cuma membaca kolom bernama itu; kode lain bikin seluruh isian
  teknisi hilang di antara layar dan server, tanpa error. Lihat §9a no. 1.
- **Jangan** menampilkan kotak suhu balok ukur / suhu UUT. Server menurunkannya dari suhu
  ruangan (§3); kotak kembar yang bisa diisi beda melahirkan komponen suhu yang tidak
  bersumber.
- **Jangan** memakai kunci `peran` untuk tabel-tabelnya — pakai `grup`. `peran` di HP berarti
  lembar pasangan standar/UUT dan membelokkan seluruh jalur kirim.

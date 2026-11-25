# Parameter keputusan metode

Dibuat 24 Nov 2026. Pemilik (Zainul) memutuskan: pertanyaan metode yang masih
menunggu Manajer Teknis (TM) **tidak boleh menghentikan pekerjaan**. Tiap
pertanyaan dijadikan PARAMETER yang bisa disetel kemudian, dengan bawaan =
perilaku kode PADA `79280fc2`, sehingga tidak ada angka sertifikat yang berubah
diam-diam. Akurat dan terbukti lebih penting daripada cepat.

Ini data **lapis 1** (AGENTS.md §Olah data butir 1): pilihan di antara
perilaku yang sudah ada, bukan rumus baru. Struktur rumus (lapis 2) tidak
disentuh.

## Status implementasi (24 Nov 2026, PR #298)

| Bagian | Status |
|---|---|
| Registri 14 parameter (7 awal PR #298 + 7 saklar keputusan default 25 Nov 2026), pembaca satu pintu, ledger append-only | SELESAI, `ParameterKeputusanMetodeTest` |
| Saklar keputusan default (vonis PASS/FAIL 4 alat, tanda minus nol turbidimeter, sumber U koreksi timbangan kg, sumber resolusi refractometer) | SELESAI: bawaan = perilaku kode sebelumnya, pilihan lain diuji (`ParameterMetodeVonisTest`, `ParameterMetodeTurbidimeterSesiTest`, `ParameterMetodeTimbanganTest`, `ParameterMetodeSumberResolusiTest`) |
| Lingkup tanggal di pintu hitung (`PenentuVersiAcuan::jalankan/jalankanDengan`: simpan, update, preview, `kalibrasi:hitung-ulang`, validator, simulasi) | SELESAI |
| Refractometer#24, Spectrophotometer#15, Conductivity#18 (parameter `hitung`) | SELESAI: tes vs sel workbook + bawaan identik terhadap rekaman kode lama |
| Tanggal terbit ph_meter#28, conductivity#36, spectrophotometer#4, turbidimeter#4 (parameter `cetak`) | SELESAI: tes vs sel workbook; nilai beku di snapshot, versi penetapan TIDAK distempel di snapshot |
| Jejak sesi (`type_b_components`, `sumber: parameter_metode`) | SELESAI untuk parameter `hitung` |
| Jalur penuh lewat DB (simpan/preview/validator/hitung-ulang, sesi lama, penetapan dicabut) | `ParameterMetodeSesiSpektroTest` (profil per kelompok); Refractometer dan Conductivity (per titik) diuji di tingkat unit |
| Penetapan lewat Studio Web | spesifikasi saja (`docs/perintah-frontend-parameter-metode.md`) |
| Suite MySQL penuh | belum dijalankan lokal (3 jam); gerbang CI |

## Yang ada sekarang

| Bagian | Tempat |
|---|---|
| Registri (data, append-only) | `database/data/parameter-keputusan-metode.json` |
| Pintu baca TUNGGAL | `App\Services\Acuan\ParameterKeputusanMetode` (singleton), jawabannya `PutusanMetode` |
| Lingkup tanggal | dibuka `PenentuVersiAcuan::jalankan()/jalankanDengan()` (simpan, preview, hitung ulang, validator, simulasi) dan `CalibrationProfile::tataLetakSertifikat($sesi)` (cetak) |
| Jejak sesi | baris `parameter_metode` di `uncertainty_calculations.type_b_components` untuk parameter berlingkup `hitung`; untuk `cetak`, nilai beku di snapshot (`tata_letak.tanggal_master.tambah_hari`) |
| Penjaga | `ParameterKeputusanMetodeTest`, `ParameterMetodeBawaanIdentikTest`, `ParameterMetode{Refractometer,Spektro,Konduktivitas,TanggalTerbit,Sesi}Test`, ledger `tests/Fixtures/parameter-metode-ledger.json`, golden `tests/Fixtures/parameter-metode-golden-bawaan.json` |

Pola yang dipakai ulang, bukan penyimpanan baru: `log-metode-tekanan-piston.json`
(log append-only + pembaca `App\Support\LogMetodeTekananPiston`), ledger berhash
`VersiTataLetakTemplatTest`, pintu satu-lingkup `SumberAcuan`, dan stempel jejak
`type_b_components` (aturan Olah data butir 4).

## Empat belas parameter

Semua `status: menunggu_tm` dan `penetapan: []` pada saat dibuat. Nilai
bawaan tujuh parameter pertama dibuktikan identik dengan sebelum perubahan lewat
`tests/Fixtures/parameter-metode-golden-bawaan.json` (direkam dari kode
`79280fc2` sebelum satu baris profil diubah, diadu `assertSame`).

| Kunci | Butir TM | Bawaan (= kode sekarang) | Pilihan lain (= workbook) | Dampak angka |
|---|---|---|---|---|
| `refractometer.sumber_deviasi_suhu` | refractometer#24 | `suhu_ruang` | `baris_tetap_21c`: sel tetap `'Tab Konversi Temperatur'!H24/E24` (B24 = 21,0 C) | U cetak sampai ~3 kali pada suhu ruang 23 C |
| `spectrophotometer.max_stdev_holmium` | spectrophotometer#15 (ikat #12) | `semua_titik` | `tanpa_titik_pertama`: `PERHITUNGAN!M36 =MAX(M27:M35)` | hanya bila STDEV 279,6 nm yang terbesar |
| `conductivity.nilai_acuan_larutan_1412` | conductivity_meter#18 | `konstanta_1412` | `polinomial_suhu`: `PERHITUNGAN!J26`, 1413,0 pada 25 C | nilai acuan titik tengah, Correction, U95 |
| `sertifikat.tambah_hari.ph_meter` | ph_meter#28 | `terbit_sistem` | `tambah_3`, `tambah_4` | tanggal tercetak saja |
| `sertifikat.tambah_hari.conductivity_meter` | conductivity_meter#36 | `terbit_sistem` | `tambah_2`, `tambah_4` | tanggal tercetak saja |
| `sertifikat.tambah_hari.spectrophotometer` | spectrophotometer#4 | `tambah_1` | `tambah_4`, `terbit_sistem` | tanggal tercetak saja |
| `sertifikat.tambah_hari.turbidimeter` | turbidimeter#4 | `terbit_sistem` | `tambah_1`, `tambah_4` | tanggal tercetak saja |
| `turbidimeter.tanda_minus_nol` | turbidimeter#29 | `dipertahankan` (koreksi yang membulat ke nol dicetak `-0,00`) | `tanpa_minus` (PDF master `0.00`) | tanda minus tercetak saja; nilai beku per baris snapshot (`baris[].tanda_nol`) |
| `chlorine_meter.vonis_pass_fail` | chlorine_meter#31 | `dengan_toleransi` (PASS/FAIL terhadap `equipments.toleransi`; kosong = titik ditahan) | `tanpa_vonis` (master tanpa sel toleransi/PASS-FAIL) | tanpa angka hitung; hanya `toleransi`, `keputusan` kosong dan gerbang toleransi lepas |
| `ph_meter.vonis_pass_fail` | ph_meter#26 | `dengan_toleransi` | `tanpa_vonis` | sama |
| `refractometer.vonis_pass_fail` | refractometer#31 | `dengan_toleransi` | `tanpa_vonis` | sama |
| `turbidimeter.vonis_pass_fail` | turbidimeter#27 | `dengan_toleransi` | `tanpa_vonis` | sama |
| `timbangan.sumber_ui_koreksi_kg` | timbangan B1 | `bagi_k` (`ui = U/k`, keputusan 16 Sep 2026) | `master` (`ui = U` mentah, hanya varian kg; `_budget.penimbangan` master kg) | budget Weighing (uc, veff, k, U penimbangan) varian kg; koreksi dan U koreksi tidak berubah. Penahanan sertifikat timbangan (`profil_ditahan`) TIDAK dilepas |
| `refractometer.sumber_resolusi` | refractometer#15 | `konstanta` (`resolusiTitik()` = 0,0001) | `isian_teknisi` (`equipments.resolusi`; kosong/nol jatuh ke konstanta), `lebih_besar` (maks konstanta dan isian) | komponen Daya baca dan `ci` komponen suhu |

Bukti per pilihan (sel workbook + `path:baris @79280fc2`) ada di registri
(`pilihan[].bukti`), dan ringkasnya ikut ke jejak sesi (`bukti_singkat`).

### Yang SENGAJA tidak dijadikan parameter

| Butir | Alasan (bukti) |
|---|---|
| spectrophotometer#12 (10 atau 9 titik Holmium, nilai standar berbeda) | Bukan parameter kecil. Daftar titik dan nilai standarnya konstanta `SpectrophotometerProfile::TITIK` (`SpectrophotometerProfile.php:209`, `:223`; lapis 1 di kode), dan mengubahnya mengubah jumlah baris lembar kerja, baris sertifikat, dan nilai standar (master 287,7 lawan kertas 287,4). Itu bentuk lembar (lapis 3) + data standar, dengan validasi "perhitungan tidak kehilangan input". Perlu paket data acuan sendiri. |
| spectrophotometer#43 (MAX STDEV tampilan gabungan) | Bukan keputusan metode: tampilan admin yang salah (`PerhitunganBuilder.php:216-219`). Memperbaikinya mengubah perilaku BAWAAN, jadi bukan tempatnya parameter. Perbaikan terpisah dengan tes. |
| U95 termometer 0,72 atau 0,5 (chlorine#23, conductivity#27, ph_meter#19, refractometer#26) | Nilainya SUDAH data per organisasi (`calibration_capabilities.u_temperature`, `PhMeterCapabilitySeeder.php:111-168`; `PhMeterProfile.php:132-135` hanya membaca kolom itu). "Ikut termometer sesi" adalah logika baru (cari standar suhu sesi, ambil U95-nya), bukan data. Menyetel kolom itu tanpa versi mengubah hasil hitung ulang sesi lama; butuh versi kemampuan, langkah terpisah. |
| Nilai toleransi alat (angka 0,15/0,2/0,005/24 pada seed demo) | Tetap data PER ALAT (`equipments.toleransi`, `GumCalculator::hitungTitik`: `toleransiTitik() ?? $equipment->toleransi`; seed demo `ChlorineSeeder.php:159`, `RefractometerSeeder.php:190`, `TurbidimeterSeeder.php:123`). Yang dijadikan parameter hanya APAKAH titik divonis (`*.vonis_pass_fail`), bukan angka toleransinya. Catatan: `CategoryController` (`punya_toleransi` di form alat mobile) membaca `punyaToleransi()` tanpa lingkup tanggal, jadi form itu tetap menjawab bawaan sampai ada keputusan. |
| `spectrophotometer.sumber_resolusi` (butir TM resolusi spektrofotometer) | Tidak ada sumber resolusi numerik yang aman dibaca: `spesifikasi_alat.resolusi_*` teks bebas, dan `equipments.resolusi` satu angka untuk dua satuan (nm dan %T). Memilih salah satunya berarti mengarang angka. Perlu kolom/field resolusi per besaran dari lab lebih dulu. |

## Cara kerja

1. **Bawaan.** `putusan($kunci)` tanpa lingkup tanggal dan tanpa argumen
   memulangkan `bawaan` registri (nomor penetapan 0). Pembaca yang lupa membuka
   lingkup salah ke arah perilaku lama, tidak pernah ke arah angka yang bergeser.
2. **Penetapan** (satu entri di `penetapan[]`): `nomor` (1, 2, 3 tanpa celah),
   `tanggal_keputusan`, `dicatat_pada`, `berlaku_mulai`, `nilai` (kode pilihan),
   `diputuskan_oleh`, `alasan`, `rujukan` (min. 10 karakter). Registri menegakkan
   tiga hal saat dibaca (kalau salah satu dilanggar, registri gagal keras):
   - `tanggal_keputusan <= dicatat_pada <= berlaku_mulai`. `dicatat_pada` = hari
     entri itu masuk registri (hari PR penetapannya dibuat).
   - `berlaku_mulai` tidak boleh turun antar nomor penetapan satu parameter
     (nomor 2 tidak boleh berlaku lebih awal dari nomor 1; sama boleh, nomor
     terbesar menang).
   - Setiap entri wajib punya ketiga tanggal itu (tanggal Y-m-d yang sah).

   **Batasnya, supaya tidak dibaca lebih dari yang sebenarnya:** registri hanya
   menjaga urutan antar tanggal yang tertulis di berkas. `dicatat_pada` diketik
   penulis PR, jadi registri TIDAK bisa membuktikan bahwa tanggal itu sama dengan
   hari PR dibuat atau hari deploy; PR yang menulis `dicatat_pada` lebih awal dari
   kenyataan lolos tes. Penutup celah itu dua hal di luar kode: peninjau PR
   mencocokkan `dicatat_pada` dengan hari PR dibuat dan `berlaku_mulai` dengan hari
   deploy, dan ledger hash (butir 4) mengunci tanggal itu setelah masuk.
3. **Tanggal.** Pilihan yang berlaku = penetapan dengan `berlaku_mulai <=
   tanggal_kalibrasi` yang `berlaku_mulai`-nya paling akhir (seri: `nomor`
   terbesar). Sesi dengan `tanggal_kalibrasi` sebelumnya tidak bergeser.
4. **Append-only.** Entri lama tidak diubah/dihapus. Tes membaca
   `tests/Fixtures/parameter-metode-ledger.json` (hash tiap entri): entri baru
   WAJIB ditambahkan ke ledger di PR yang sama, entri lama yang berubah atau
   hilang = tes merah.
5. **Jejak.** Tiap hasil hitung profil ber-parameter `hitung` membawa satu baris
   `sumber: parameter_metode` dengan `parameter`, `pilihan`, `penetapan`,
   `berlaku_mulai`, `status`, `tanggal_acuan`, dan kalimat yang menyebut apakah
   perilaku itu SAMA/BERBEDA/SEBAGIAN dari workbook master beserta sel dasarnya.
   Hasil lama (tanpa baris itu) dibaca sebagai bawaan pada skema registri 1.

### Cara menetapkan jawaban TM (sekarang)

PR kecil yang menambah SATU entri `penetapan` di registri, plus satu baris di
ledger. Contoh isi (nilai contoh, bukan keputusan):

```json
{
  "nomor": 1,
  "tanggal_keputusan": "2026-12-04",
  "dicatat_pada": "2026-12-05",
  "berlaku_mulai": "2026-12-08",
  "nilai": "baris_tetap_21c",
  "diputuskan_oleh": "Manajer Teknis, nama lengkap",
  "alasan": "Workbook tervalidasi memakai sel tetap baris 24; keputusan batch 4 Des.",
  "rujukan": "LEMBAR-CENTANG-MANAJER-TEKNIS.md C1 refractometer#24, dicentang B"
}
```

`berlaku_mulai` harus hari deploy PR itu atau sesudahnya; entri yang berlaku
sebelum deploy menggeser hitung ulang sesi yang sudah dihitung dengan bawaan
(validator akan melaporkan `ketidakpastian_beda`). Registri menolak
`berlaku_mulai` yang lebih awal dari `dicatat_pada` (dan `dicatat_pada` yang lebih
awal dari `tanggal_keputusan`), tetapi tidak membandingkannya dengan tanggal
deploy yang sebenarnya: itu tetap pemeriksaan peninjau PR (lihat butir 2 di
atas). `dicatat_pada` isi dengan hari PR dibuat, bukan hari keputusan.

## Langkah berikut: penetapan lewat Studio Web (BELUM dibangun)

Pola Data Acuan versi (`paket_acuan` + `AlurVersiAcuan`: draf, ajukan,
simulasi, sahkan oleh `super_admin` lain, alasan, pelaku, log append-only)
adalah tujuan yang tepat untuk menulis penetapan dari Studio Web. Tidak
dibangun sekarang karena, dengan bukti:

1. **Sumbernya masih berkas.** `config('acuan.sumber')` dipatok `json`
   (`config/acuan.php:23`, `render.yaml`); versi DB baru dibaca setelah rollout
   tahap 3, yang bukan efek samping deploy. Menulis penetapan ke DB sekarang
   tidak akan dibaca produksi.
2. **Stempel versi satu kolom.** `uncertainty_calculations.paket_acuan_versi_id`
   menunjuk SATU versi per hasil; parameter lintas profil (refractometer,
   spectro, conductivity) tidak bisa distempel di kolom itu tanpa kolom baru
   (pilihan terakhir menurut AGENTS.md).
3. **Dua dari tiga profil bukan paket.** `conductivity_meter` dan `ph_meter`
   tidak ada di `SkemaAcuanProfilTest::BERSKEMA`; paketnya belum ada.
4. **Berkas tabel acuan digenerate.** `tabel-standar-*.json` tidak boleh diedit
   tangan (AGENTS.md §Generator); menambah lembar `parameter_metode` ke sana
   berarti mengubah generator yang membaca workbook terenkripsi.

Rancangan yang diusulkan untuk langkah itu: satu paket virtual
`parameter_metode` per organisasi (bukan per profil), isi = daftar penetapan
yang sama bentuknya dengan registri, versi lewat `AlurVersiAcuan` yang sama,
pembaca `ParameterKeputusanMetode` menimpa `penetapan[]` berkas dengan versi DB
yang berlaku pada `tanggal_kalibrasi` bila `acuan.sumber = db`, dan stempel
`paket_acuan_versi_id` tetap milik tabel acuan (jejak parameter tetap di
`type_b_components`). Spesifikasi endpoint dan layar:
`docs/perintah-frontend-parameter-metode.md`. Butuh keputusan pemilik soal
rollout `DATA_ACUAN_SUMBER=db` sebelum dibangun.

## Risiko yang diketahui

- **Hasil lama tanpa baris `parameter_metode`.** Dibaca sebagai bawaan pada
  skema 1. Benar selama tidak ada penetapan berlaku surut. Registri dan ledger
  hanya menjaga urutan tanggal yang tertulis (`tanggal_keputusan <= dicatat_pada
  <= berlaku_mulai`, `berlaku_mulai` tidak turun antar nomor); tanggal
  `dicatat_pada` sendiri diketik penulis PR. Jadi salah bila seseorang menulis
  `dicatat_pada` dan `berlaku_mulai` lebih awal dari hari deploy sebenarnya lalu
  menghitung ulang sesi lama; yang menahannya peninjau PR, bukan kode.
- **Jalur hitung yang belum membuka lingkup.** Empat pintu sudah membukanya
  (store/update/preview, `kalibrasi:hitung-ulang`, `CalibrationValidator`,
  simulasi versi acuan) lewat `PenentuVersiAcuan`. Jalur baru yang memanggil
  profil langsung tanpa lingkup memakai bawaan.
- **Parameter `cetak` tidak berbaris jejak.** Nilainya beku di snapshot
  sertifikat saat terbit; nomor penetapannya tidak ikut distempel di sana.
- **Conductivity, polinomial pada dua varian.** Excel memakai konstanta pada
  varian uS/cm dan polinomial pada varian mS/cm; pilihan `polinomial_suhu`
  diterapkan ke keduanya karena pertanyaan TM menyangkut nilai tersertifikasi
  larutan. TM wajib mengetahui ini sebelum menjawab.
- **Gerbang MySQL** suite penuh belum dijalankan (3 jam); CI yang menjalankan.

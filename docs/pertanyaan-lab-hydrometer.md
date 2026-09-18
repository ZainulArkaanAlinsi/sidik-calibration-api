# Pertanyaan lab — Hydrometer

**Alat:** Hydrometer — lampiran akreditasi LK-285-IDN no. **25**, Lab. Volumetrik
**Metode:** SIDIK-IK-CAL-0525_Rev.3 · **Kertas:** SIDIK-FM-CAL-0533_Rev.2 · **Sertifikat:** SIDIK-FM-CAL-2403_Rev.0
**Sumber:** dua workbook master (pw `spirit285`)

| Berkas | Terbit | Rentang | Beban tambahan |
|---|---|---|---|
| `Master Olah Data Hydrometer 0.600-0.650` | 8 Sep 2025 | 0,600-0,650 g/ml | **ada** (Sl = 54,0052 g) |
| `Master Olah Data Hydrometer 1.800-2.000` | 7 Nov 2025 | 1,800-2,000 g/ml | tidak ada |

**Disiapkan:** 18 September 2026

Rantai hitungnya dibuktikan lebih dulu di luar PHP dan baru sesudah itu ditulis:
keenam densitas terbitnya, keempat belas koefisien sensitivitasnya, serta `uc`, `v_eff`,
`k`, dan `U` tiap skala cocok sampai **≤3·10⁻¹⁷**. Dijaga
`tests/Unit/HydrometerMasterTest.php` dengan toleransi 1·10⁻¹².

Klasifikasi tiap temuan mengikuti aturan proyek:

- **METODE** — kejanggalan cara hitung. Sistem MENIRU master dan menunggu keputusan manajer teknis.
- **KERUSAKAN** — rujukan sel meleset, tautan ke workbook lain, satuan campur. Sistem MENIRU juga
  selama hasilnya sudah tercetak di sertifikat terbit, dan menulis selisihnya di sini.
- **SEL KOSONG** — nilai kosong yang terbaca nol. Sistem MEMBLOKIR.

## Ringkasan

| § | Temuan | Jenis | Menggeser angka terbit? |
|---|---|---|---|
| §1 | `π` = 3,14, bukan π | Metode | Ya — ditiru |
| §2 | Satuan tekanan campur: `κ` ber-1/Pa, selisihnya ber-hPa | Kerusakan | Ya — ditiru |
| §3 | `fta`/`ftl` di budget memakai faktor TEKANAN, bukan α | Kerusakan | Ya — ditiru |
| §4 | Faktor koreksi suhu diambil dari titik PERTAMA saja | Metode | Ya — ditiru |
| §5 | `TINV` memotong derajat kebebasan ke bilangan bulat | Metode | Ya — ditiru (dan sesuai GUM G.4.1) |
| §6 | Suhu acuan faktor koreksi bukan `tr`, tapi kotak `Temperature` | Metode | Ya — ditiru |
| §7 | **CMC 0,0007 diketik literal; pita `CMC_UTM` 1,1-1,7 g/ml tidak memuat satu pun contoh** | Sel kosong | **Ya — menentukan U95% file ringan** |
| §8 | Koefisien 7.9, 7.10b & 7.12 memakai penyebut SKALA 1 untuk semua skala | Kerusakan | Tidak pada kedua file terbit |
| §9 | Kertas Rev.2 tidak punya kotak tekanan udara, rumusnya butuh | Sel kosong | **Ya — sistem MEMBLOKIR tanpa tekanan** |
| §10 | Monitor `EXPIRED` cuma label; kedua file terbit dengan status menyala | Keputusan sistem | Tidak — sistem MEMBLOKIR |
| §11 | Kertas menulis `SI`, Excel menulis `Sl` | Kosmetik | Tidak |
| §12 | Kertas Rev.2 masih mencetak `Temp. Kalibrator Victor`, yang dicabut lab 2024 | Kerusakan | Tidak — lembar ikut workbook |
| §13 | Neraca `Fujitsu FS-AR210` belum pernah ada di master `standards` | Sel kosong | **Ya — sempat menaut ke neraca yang salah** |

---

## §1 — `π` = 3,14 [DITIRU]

`PERHITUNGAN!J89 = 3,14`, dipakai di `πDγx/g = (π·D·γx)/g` dan `πDγL/g`. Keduanya masuk ke
pembilang dan penyebut rumus Cuckow, jadi ikut menentukan densitas terbit.

Kalau `π` yang sebenarnya dipakai, `πDγx/g` bergeser dari 0,039727684785 jadi 0,039747541…
— selisih di digit ke-5, dan kolom `Actual Value` sertifikat dicetak 3 desimal sementara
`U95%` 5 desimal.

**Pertanyaan:** apakah 3,14 memang ketetapan Instruksi Kerja, atau pembulatan yang terbawa
sejak lembar kertas?

## §2 — Satuan tekanan campur [DITIRU]

`PERHITUNGAN!AD27` menamai `κ` "Isothermal compressibility coefficient of the fluid **(1/Pa)**",
tapi `AD31 = 1 − (κ·(AD28 − AD29))` mengurangkan dua angka ber-**hPa** (933,15 − 1013,25).

Sheet `PERHITUNGAN (2)` — versi sebelumnya yang tidak lagi tersambung ke hasil — melakukannya
dalam Pa (101350 − 101325) dan menghasilkan 0,999999999375. Yang tersambung ke sertifikat
versi hPa: **1,0000000020025**.

**Pertanyaan:** mana yang benar menurut Instruksi Kerja? Efeknya kecil (≈2·10⁻⁹) tapi arahnya
berlawanan, dan sheet lama membuktikan pernah ada dua pendapat.

## §3 — `fta`/`ftl` memakai faktor tekanan, bukan α [DITIRU]

`NILAI U95%!Q49 = 1 + (PERHITUNGAN!$J$85 · (G15 − 20))`.

`J85` itu **faktor koreksi tekanan** (≈1,000000002). Yang secara bentuk seharusnya ada di situ
`J83` = α = 1·10⁻⁵ — satu sel di atasnya, dan itulah yang dipakai faktor koreksi suhu di
`AD41`/`AD77`.

Akibatnya `fta = 1,45` dan `ftl = 1,60` di file ringan, padahal faktor suhu yang sebenarnya
1,000006. Kelima setengah digit itu masuk ke `H44 = ρ_air·ftl` dan `H45 = ρ_udara·fta`, yang
dipakai **ketujuh** koefisien sensitivitas.

**Pertanyaan:** `J85` atau `J83`? Ini yang paling besar efeknya di antara §1-§6.

## §4 — Faktor koreksi suhu dari titik pertama saja [DITIRU]

`AD38 = 'PERHITUNGAN (2)'!G42` — suhu rata-rata **titik skala pertama**. Faktor yang lahir dari
situ (`AD41`) dipakai untuk SEMUA titik, termasuk titik yang suhu airnya berbeda.

Di file ringan titik 3 diukur pada 20,7 °C sementara faktornya dihitung dari 20,6 °C.

**Pertanyaan:** apakah ini disengaja (satu faktor per sesi) atau seharusnya per titik?

## §5 — `TINV` memotong derajat kebebasan [DITIRU]

`NILAI U95%!K77 = TINV(0,05; K76)` dengan `K76` = 125,29969882563776. Excel memotong
`deg_freedom` ke bilangan bulat, jadi yang dihitung `TINV(0,05; 125)` = **1,9791241094237992**.
Kuantil-t yang tepat pada 125,2997 adalah 1,9790778438503545.

Selisihnya masuk desimal ke-5 `U95%` — desimal yang memang dicetak sertifikat.

Ini **ditiru dan memang benar**: GUM G.4.1 meminta `v_eff` dibulatkan ke bawah, dan
`GumCalculator::agregasiBudget()` sudah melakukannya untuk semua alat sejak awal. Dicatat di
sini supaya tidak ada yang "memperbaikinya" jadi kuantil tepat di kemudian hari.

## §6 — Suhu acuan faktor koreksi bukan `tr` [DITIRU]

`AD39` dan `AD75` menunjuk `E10` = `'INPUT DATA'!E17` — kotak **Temperature** di blok identitas
alat. Isinya 20 di kedua master.

Kotak `tr` (Suhu Acuan Hydrometer) ada di tempat lain (`E34`) dan isinya **15** di file ringan.
Jadi hydrometer yang acuannya 15 °C tetap dikoreksi terhadap 20 °C.

**Pertanyaan:** `tr` dipakai untuk apa kalau bukan ini? Sertifikat mencetaknya sebagai "Suhu
acuan Hydrometer" (`SERTIFIKAT!J14`) — dan yang tercetak di situ pun `E17`, bukan `E34`.

## §7 — CMC: angka literal tanpa rujukan [MEMBLOKIR kalau pita tidak ditemukan]

Ini yang paling mendesak.

`NILAI U95%!L79`, `L113`, `L147` ketiganya berisi **`0.0007` yang diketik langsung** — bukan
rumus, bukan `VLOOKUP` ke tabel mana pun. Sementara tabel `CMC_UTM` di workbook yang sama
menyebut pita densitas **1,1-1,7 g/ml**, dan:

- hydrometer file ringan: 0,600-0,650 g/ml — **di luar pita**
- hydrometer file berat: 1,800-2,000 g/ml — **di luar pita**

Untuk file ringan ini bukan soal akademis: `U` hitung ketiga skalanya 0,000482 / 0,000483 /
0,000494 — semuanya **di bawah** 0,0007, jadi yang tercetak di sertifikat terbit angka CMC itu
sendiri, bukan budget. Sertifikat 8 Sep 2025 mencetak `U95% = 0,0007 g/ml` tiga kali, dan angka
itu datang dari sel yang tidak punya rujukan.

(File berat sebaliknya: `U` hitungnya 0,000867 / 0,000877 / 0,000901, semuanya menang atas CMC.)

**Pertanyaan untuk Technical Manager / Pak Rohman:**

1. Berapa pita CMC hydrometer yang sah, dan sampai densitas berapa?
2. Kalau pita `CMC_UTM` 1,1-1,7 g/ml itu yang berlaku, atas dasar apa kedua hydrometer di luar
   pita itu diterbitkan?
3. Kalau 0,0007 berlaku untuk seluruh rentang, di dokumen mana angka itu ditetapkan?

**Sikap sistem sementara:** `TabelStandarHydrometer::PITA_CMC` dibuat **ber-rentang dan
ber-versi** (versi `master-2025-11-07-belum-dikonfirmasi`), berisi satu pita 0 → tak berbatas
dengan nilai 0,0007 supaya kedua sertifikat terbit bisa direproduksi. Begitu jawabannya turun,
yang disunting datanya — satu baris array — bukan rumusnya. Titik di luar semua pita **menahan
seluruh sesi**, tidak cuma titiknya.

## §8 — Penyebut koefisien sensitivitas: skala 1 untuk semua skala [DITIRU]

Tujuh koefisien sensitivitas per skala. Lima di antaranya memakai penyebut
`(M_udara − M_cairan_skala + E44)`, tapi **tiga** (`7.9` massa di udara, `7.10b` massa di
cairan, `7.12` tegangan permukaan) mengalikannya dengan `$O$55` — penyebut **skala 1**,
di-absolut-kan — sementara `7.10a` (diameter stem) dan `7.11` (gravitasi) memakai penyebut
skalanya sendiri.

Kedua master juga tidak sepakat: file berat (7 Nov 2025) memperbaiki `7.12` jadi per-skala,
file ringan (8 Sep 2025) belum.

Ada satu lagi yang sejenis: koefisien `7.11` memakai `(−E44)` di skala 1 dan `(−L45)` di skala
2 dan seterusnya. Sama di kedua master.

**Yang dipakai sistem:** bentuk master **RINGAN** (`$O$55` untuk ketiganya). Alasannya bisa
diperiksa: di file berat sebaran tegangan permukaannya **nol** (ketiga titik diukur pada
20,4 °C), jadi komponen itu tidak menyumbang apa pun dan kedua bentuk memberi angka yang sama
persis. Di file ringan, bentuk per-skala menggeser `uc` skala 2 dari 0,00024390 jadi 0,00024459
— dan U95% tercetaknya **tetap 0,0007** karena keduanya di bawah lantai CMC.

Jadi pilihan ini tidak menggeser satu pun angka di kedua sertifikat yang sudah terbit. Dia akan
menggeser angka hydrometer BERIKUTNYA yang sebaran suhunya tidak nol dan `U`-nya di atas CMC.

**Pertanyaan:** mana yang sah — penyebut skala 1 untuk ketiganya, atau per-skala seperti
perbaikan di file berat?

## §9 — Kertas tidak punya kotak tekanan udara [MEMBLOKIR]

Rumus densitas udara `PERHITUNGAN!J78` membaca tekanan ruangan, dan `'INPUT DATA'!E25:F25`
menyediakan kotak awal & akhir ber-satuan **hPa**. Kertas `SIDIK-FM-CAL-0533_Rev.2` cuma
mencetak suhu dan kelembaban.

Tanpa tekanan, `ρ_udara` tidak bisa dihitung sama sekali — dan dia masuk ke rumus Cuckow
di tiga tempat.

**Sikap sistem:** kotak tekanan udara (hPa) DITAMBAHKAN ke lembar kerja, memakai kolom
`calibration_sessions.tekanan_awal`/`tekanan_akhir` yang sudah ada sejak Gas Detector. Sesi
tanpa tekanan tidak menerbitkan satu pun baris.

**Pertanyaan:** perlu revisi kertas (Rev.3) supaya lembar cetak dan lembar aplikasi sama?

## §10 — Monitor standar `EXPIRED` cuma label [TIDAK DITIRU]

`'INPUT DATA'!K3` menulis `"ONE OR MORE STANDARD EXPIRED"` dan lembarnya tetap menghitung
sampai selesai. **Kedua file contoh terbit dengan status itu menyala** — file ringan
`EXPIRED` di ketiga standar, file berat `EXPIRED` di dua dan `WARNING` di caliper.

**Sikap sistem:** standar yang lewat masa berlaku sudah memblokir penerbitan untuk SEMUA alat
lewat temuan `standar_kadaluarsa` tingkat `error` di `CalibrationValidator` — itu bukan sesuatu
yang perlu dibangun ulang untuk hydrometer, dan `abaikan_peringatan` tidak bisa melewatinya.

**Pertanyaan:** kedua sertifikat itu perlu ditinjau ulang, atau tanggal due di `DATABASE`
workbook memang sudah kedaluwarsa dari sononya (mis. workbook disalin dari template lama)?

## §11 — `SI` lawan `Sl` [KOSMETIK]

Kertas Rev.2 mencetak `SI` (huruf i besar) untuk beban tambahan; Excel menulis `Sl` (huruf L
kecil), dan `Sl` yang cocok dengan literatur Cuckow (*sinker load*). Lembar aplikasi memakai
`Sl`.

**Pertanyaan:** perlu dibetulkan di kertas revisi berikutnya?

## §12 — Kertas masih mencetak standar yang sudah dicabut [TIDAK DITIRU]

Kop kertas `SIDIK-FM-CAL-0533_Rev.2` mencetak baris `Temp. Kalibrator **Victor**`.

Victor dicabut lab **24 Mei 2024** — `FORM VALIDASI` TITS rev. 11 menulis
"Remove std. Victor / Add std kalibrator yokogawa", dan tabel koreksinya di
workbook-workbook lain sudah `#REF!` semua.

Kedua workbook hydrometer sendiri — Sep DAN Nov 2025, jadi setahun lebih
sesudah pencabutan — memang sudah memakai penggantinya:

- `DATABASE!E11:J11` → `Temperature Calibrator / Yokogawa / CA 150 Handy / 23P1005`
- `SERTIFIKAT!B30:N30` → `Termometer & Sensor Std. / Yokogawa/CA 150 Handy Cal / 23P1005`

Jadi yang basi barisnya di KERTAS, bukan di data. Lembar aplikasi mengikuti
workbook: barisnya berbunyi `Temp. Kalibrator` dan tertaut ke Yokogawa.

**Pertanyaan:** perlu revisi kertas (Rev.3) supaya baris itu berhenti menyebut
alat yang tidak ada lagi?

## §13 — Neraca Fujitsu FS-AR210 belum terdaftar [DIPERBAIKI]

`NILAI U95%!E9:J9` menyebut neraca yang menimbang hydrometer-nya:
**Fujitsu FS-AR210, S/N INS-N1600555**. Master `standards` belum pernah
memuatnya.

Yang membuat ini berbahaya bukan ketiadaannya, tapi apa yang terjadi waktu
barisnya dicocokkan dengan nama telanjang `Analytical Balance`: master punya
baris dengan nama PERSIS itu — **Mettler Toledo XS204**, neraca milik lembar
Anak Timbangan. Barisnya ketemu, hijau di lembar kerja, dan sertifikatnya
terbit mencetak nomor sertifikat dan ketertelusuran **neraca yang salah**.
Nol error di mana pun.

Seluruh komponen `U massa aquadest` budget hydrometer (dua dari sebelas
komponen, di kedua baris massa) lahir dari sertifikat neraca ini.

**Sikap sistem:** `HydrometerSeeder` mendaftarkannya ke master, dan baris
tercetaknya dicocokkan lewat **nama penuh** `Analytical Balance Fujitsu
FS-AR210` — bukan nama telanjang. Angka ketidakpastiannya diambil dari blok
`Utimb` master (0,00074 g, k = 2), sama dengan yang dipakai
`TabelStandarHydrometer::uMassaAquadest()`.

**Pertanyaan:** apakah S/N `INS-N1600555` dan sertifikatnya sudah benar, dan
kapan masa berlakunya habis? Yang di-seed sekarang memakai tanggal demo.

---

## Yang TIDAK jadi pertanyaan

Supaya jelas apa yang sudah selesai:

- **Rantai 13 langkah §3** — cocok sampai ≤3·10⁻¹⁷ di kedua varian, tidak ada yang perlu ditanyakan.
- **Tabel tegangan permukaan** — konstanta fisika, `FORECAST` dua titik = interpolasi linear, ditiru persis.
- **Polinomial densitas air orde-5** — cocok digit demi digit.
- **Tepat 3 ulangan & 3 ukuran diameter stem** — pembagi `√3` dan `vi = n−1 = 2` mengandaikannya;
  sistem menolak yang bukan 3, bukan menghitungnya dengan pembagi yang salah.

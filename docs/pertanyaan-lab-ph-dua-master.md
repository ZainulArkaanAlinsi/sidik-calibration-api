# Pertanyaan ke lab — pH Meter punya DUA master, dan keduanya asli

Ditemukan 9 September 2026 waktu seluruh isi `Project-PT-Sidik/alat-alat-Pt-Sidik`
diaudit ulang lawan yang sudah dibangun.

Sistem **belum mengubah satu angka pun** karena temuan ini. Yang berubah cuma
komentar yang salah menjelaskannya, dan satu test baru yang mengunci kedua
master. Yang dibutuhkan sekarang pernyataan lab tentang mana yang berlaku untuk
sesi baru.

Pesan siap kirim ada di bagian paling bawah.

---

## Ringkas: yang beda bukan revisi, tapi termometernya

Ada dua workbook olah data pH, dan tak satu pun "versi lama" dari yang lain:

| | `Master Olah Data_pH for trial` | `pH_meter_IMTE-WQ-129` |
|---|---|---|
| Termometer standar | Yokogawa CA 150 Handy Cal | Constant/SH 10 (S/N 99875850/20) |
| U95 termometer | 0,72 °C | 0,5 °C |
| U95 sensor | 0,06 °C | 0,06 °C |
| **UTemperature** | **0,36124783736376886** | **0,25179356624028343** |
| Resolusi alat yang dikalibrasi | 0,01 pH | 0,001 pH |
| Metode | `SIDIK-IK-CAL-0506_Rev.6` | `SIDIK-IK-CAL-0506_Rev.6` |

Sisa konstanta budgetnya **sama persis** di kedua workbook, dan itu sudah diadu
baris demi baris:

| Titik | `ci` suhu | `U` pengaruh perbedaan suhu | `ci` perbedaan suhu |
|---|---|---|---|
| pH 4 | 0,00077 | 0,01 | 1 |
| pH 7 | 0,00352 | 0,02 | 0,00304 |
| pH 10 | 0,01021 | 0,05 | 0,00949 |

Jadi yang ikut perangkat cuma `UTemperature` (dan resolusi, yang memang sudah
diambil per alat). Keenam titik dari kedua workbook reproduksi di 5·10⁻⁶ lewat
mesin yang sama — `tests/Unit/PhMeterMasterTest.php`.

## Yang sempat salah dicatat di sistem

`database/seeders/PhMeterCapabilitySeeder.php` menyimpan yang Yokogawa
(0,36124783736376886), dan komentarnya dulu menyebut angka satunya sebagai
**salah baca**:

> *"Sebelumnya di sini kesimpen 0.25179356624028343 = sqrt(0.25² + 0.03²) —
> angka termometernya kebaca 0.25, bukan 0.36."*

Itu keliru. Workbook IMTE-WQ-129 menulis `U95% Thermometer : 0.5` dan
`UTemperature : 0.25179356624028343` di kepala sheet `PERHITUNGAN U95%`-nya
sendiri. Dua angka sah dari dua alat berbeda, bukan satu angka yang salah ketik.
Komentarnya sudah dibetulkan; nilainya sengaja **tidak** ditukar, karena
menukarnya cuma memindahkan ketidakcocokannya ke master satunya.

## §1. UTemperature mestinya ikut standar suhu sesi — belum ada jalurnya

Sekarang `u_temperature` disimpan satu nilai per baris kemampuan alat, seolah
sifat "pH Meter". Padahal dia sifat **termometer standar yang dipakai sesi itu**,
dan lab punya lebih dari satu.

**Dampaknya hari ini: nol.** Untuk alat resolusi 0,001, ketiga titiknya jatuh di
bawah CMC dengan UTemperature mana pun, jadi yang tercetak tetap CMC:

| Titik | U pakai 0,72 °C | U pakai 0,5 °C | CMC | Yang tercetak |
|---|---|---|---|---|
| pH 4 | 0,022757 | 0,022757 | 0,023 | 0,023 |
| pH 7 | 0,019772 | 0,019752 | 0,021 | 0,021 |
| pH 10 | 0,029842 | 0,029730 | 0,031 | 0,031 |

Arahnya juga aman: yang dipakai sistem (Yokogawa) selalu yang **lebih besar**,
jadi sistem tidak pernah melaporkan U lebih kecil dari yang lab hitung sendiri.

Tapi itu kebetulan, bukan sifat. Master yang LAMA membuktikannya: di sana
resolusinya 0,01 dan hasil hitungnya **menembus CMC** di dua titik —
sertifikatnya mencetak 0,02343221 dan 0,02110895, bukan 0,023 dan 0,021. Begitu
alat resolusi kasar masuk lagi, pilihan UTemperature langsung menentukan angka
yang dicetak.

**Pertanyaannya:**

1. Betul bahwa `UTemperature` harus ikut termometer standar yang dipakai sesi,
   bukan dipatok per jenis alat?
2. Kalau ya, dari mana sistem membacanya — dari `thermohygro_standard_id` yang
   sudah dipilih teknisi di lembar kerja, atau dari daftar standar suhu
   terpisah? (Nilai yang dibutuhkan cuma U95 dan `k` sertifikat termometernya
   plus sensornya; sistem sudah menyimpan dua-duanya untuk standar lain.)
3. Sambil menunggu: benar sesi baru tetap dihitung pakai 0,72 °C (yang lebih
   besar, arah aman), atau ada alat yang seharusnya sudah pakai Constant/SH 10?

## §2. Baris suhu berlabel `rect.` tapi dibagi 2

Di kedua workbook, komponen `Ketidakpastian Temperature` ditulis distribusinya
**`rect.`** tapi kolom `Divisor`-nya **2**, bukan √3. Empat komponen lain
konsisten (normal → 2, rect. → √3, t-student → √n).

Sistem **meniru masternya** — dibagi 2 — karena itu yang bisa diadu ke
sertifikat yang sudah beredar. Kalau yang benar √3, angka U di seluruh
sertifikat pH yang pernah terbit ikut bergeser, jadi ini bukan hal yang boleh
"dirapikan" tanpa keputusan lab.

**Pertanyaannya:** labelnya yang salah ketik (harusnya `normal`, k=2), atau
pembaginya yang salah (harusnya √3)?

## §3. `ci` perbedaan suhu di pH 4 bernilai 1, dua titik lain pecahan

Bukan temuan baru — sudah tercatat di `PhMeterCapabilitySeeder` sejak awal —
tapi sekarang diketahui muncul **identik di kedua workbook**, jadi bukan salah
ketik satu lembar.

Di pH 7 dan pH 10 `ci`-nya 0,00304 dan 0,00949 (seorde dengan `ci` suhu di titik
yang sama). Di pH 4 nilainya persis 1, sekitar 1.300× lebih besar dari pola dua
titik lain — dan komponen itu jadi penyumbang terbesar kedua di titik tersebut.

**Pertanyaannya:** 1 itu memang nilai fisiknya, atau sel yang tidak pernah diisi
sehingga terbaca sebagai koefisien netral?

---

## Pesan siap kirim

> Halo, ada tiga hal di lembar olah data pH yang perlu dipastikan sebelum kami
> kunci di aplikasi.
>
> 1. Kami menemukan dua workbook pH: satu pakai termometer standar Yokogawa
>    CA 150 (U95 0,72 °C), satu pakai Constant/SH 10 (U95 0,5 °C). Keduanya
>    menghasilkan UTemperature yang berbeda (0,3612 vs 0,2518). Aplikasi
>    sekarang selalu memakai yang 0,72. Apakah UTemperature seharusnya mengikuti
>    termometer yang dipakai di sesi itu? Kalau ya, kami perlu tahu dari mana
>    teknisi memilihnya di lembar kerja.
> 2. Di baris "Ketidakpastian Temperature", distribusinya tertulis `rect.` tapi
>    pembaginya 2 (bukan √3). Yang mana yang benar? Kami ikut masternya dulu
>    (dibagi 2) supaya sertifikat lama tetap bisa direproduksi.
> 3. Di baris "Ketidakpastian Pengaruh Perbedaan Suhu", koefisien `ci` untuk
>    titik pH 4 nilainya 1, sementara pH 7 dan pH 10 nilainya 0,00304 dan
>    0,00949. Apakah 1 itu memang nilainya, atau selnya belum terisi?
>
> Tidak ada angka sertifikat yang berubah karena ketiga hal ini untuk alat
> resolusi 0,001 — semuanya masih di bawah CMC. Tapi untuk alat resolusi 0,01,
> poin 1 sudah pernah menentukan angka yang tercetak, jadi kami ingin
> memastikan dulu. Terima kasih.

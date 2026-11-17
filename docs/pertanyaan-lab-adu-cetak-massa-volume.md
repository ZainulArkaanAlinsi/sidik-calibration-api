# Pertanyaan lab — adu ANGKA CETAK kelompok Massa & Volume (PK-4)

**Disusun:** 17 November 2026. **Untuk:** Manajer Teknis dan pemilik proyek.
**Cakupan:** Timbangan (kg, gram, substitusi), Anak Timbangan (F1, F2 hal 1–3), Volumetric
Glassware (Pipet Volume, Labu Ukur, Gelas Ukur, Buret, Pipet Ukur), Piston Volume (Piston
Pipette, Dispensett, Buret Digital). Picnometer dan Bath ditahan (PK-3).

Yang diadu: teks yang TERCETAK di `sertifikat.pdf` (renderer asli, sesi diterbitkan lewat
`POST /approve`) lawan teks yang dicetak lembar `SERTIFIKAT` workbook master — angka sel
master + number_format, dicocokkan ke `Range.Text` Excel sendiri (476 sel; 473 identik, 3 hanya
berbeda tanda bintang nominal kembar `200*`/`20*` yang dibuang dari pencocokan angka — selisih
terbuka PK4-8, bukan diabaikan; hitung ulang Excel atas keenam
`.xlsm` juga tidak menggeser satu pun sel `SERTIFIKAT`). Pengujinya
`tests/Feature/*SesiContohCocokMasterTest.php`; angka masternya
`tests/Fixtures/cetak-master/massa-volume.json` (generator
`docs/skrip/gen-cetak-master-massa-volume.py`).

Pola baku `docs/pertanyaan-lab-*.md`: kejanggalan master ditiru dan ditanyakan; yang berikut
**tidak dibetulkan diam-diam** — tiap butir dikunci di test sebagai selisih yang dicatat. PK4-1..8 dan
PK4-10 untuk lab/pemilik; **PK4-9 pertanyaan KODE** (pembulatan jalur cetak Timbangan).

---

## PK4-1 — Sesi contoh Timbangan tidak membawa Effect of Tare dan beban Loading Influence

Master mencetak `2. EFFECT OF TARE = 0.00 kg` (kg), `0.0001 g` (gram), `0.0 kg` (substitusi) dan
`Weight mass 20 kg was moved…` (gram `10 g`, substitusi `200 kg`). Sumbernya `INPUT DATA!K40/O40/O41`
(substitusi `K39/O39/O40`) dan `I67` (substitusi `I69`). Sesi contoh seeder
(`database/data/sesi-master-timbangan.json`, `eksentrisitas.beban = null`, tanpa `effect_of_tare`)
tidak membawanya, sehingga sertifikat contoh mencetak `= — kg` dan kalimat tanpa angka.

Bukan salah hitung: aplikasi menahan tanda pisah bila kotaknya kosong (nol di situ terbaca
"tare sempurna"). Test melengkapi kedua masukan dari master dan terbukti mencetak persis master.

**Ditanyakan:** apakah kertas lapangan Timbangan selalu mengisi m1/m2 tare dan beban eksentrisitas?
Kalau ya, kosongnya isian itu sebaiknya PERINGATAN validator (sekarang diam). Seeder contoh
dilengkapi sebagai pekerjaan terpisah (menyentuh data demo yang dipakai test lain).

## PK4-2 — Timbangan kg: U95 bagian 7 (Weighing) tercetak 0,033 kg, master 0,043–0,053 kg

Sel `SERTIFIKAT!H49:H53` dan `W49:W53` master kg: 0,0425 · 0,0447 · 0,0447 · 0,0468 · 0,0468 /
0,0488 · 0,0488 · 0,0508 · 0,0508 · 0,0527 kg (tercetak 0,043 … 0,053). Aplikasi mencetak
**0,033 kg di sepuluh titik** (lantai CMC pita 12 menang). Sebabnya keputusan pemilik 16 Sep 2026
(butir 3 / T2): `ui` "Uncertainty of Correction" di budget Weighing dibagi `k` (GUM 4.3.3),
bukan dipakai mentah seperti master kg. Selisih cetak sampai −37 % (0,033 lawan 0,0527), arah TIDAK konservatif
terhadap master. Master gram dan substitusi tidak terkena: lantai CMC menang di dua sisi.

Bagian 3 (Accuracy, U95 koreksi) sama persis dengan master di ketiga varian.

**Ditanyakan:** TM menandatangani bahwa nilai cetak yang lebih kecil dari master kg ini sah
(ISO/IEC 17025 7.2.1.5)? Adakah sertifikat Timbangan kg yang sudah terbit dari master dan perlu
ditinjau (U95 bagian 7 di sertifikat itu 0,043–0,053 kg)?

## PK4-3 — Jejak sesi Timbangan tidak menulis catatan penyimpangan master

Aturan repo (§Olah data butir 4): penyimpangan dari master wajib terbaca di `type_b_components`
sesi — nomor IK / sel master / selisih. Tiga penyimpangan Timbangan tidak punya catatan itu:

| Penyimpangan | Dampak (sesi contoh) | Catatan di jejak |
|---|---|---|
| `Uncertainty of Correction` ÷ `k` (kg, substitusi) | 10 komponen/sesi: `ui` 0,01003 vs master 0,01967 (kg titik 1) | tidak ada |
| gram titik 9: Mass 2 & 3 dibaca benar (master `FC!B80/B81` meleset) — T6 | komponen drift 5,5·10⁻⁶ vs master 0; U95 +0,03 % | tidak ada |
| substitusi titik 9: `Weight Standard` dibaca dari berkasnya sendiri (master `[3]` tautan luar) — T7 | `ui` 0,00674 vs master 2,4·10⁻⁵ | tidak ada |

Hanya baris `varian_master` yang menunjuk "lihat T1 & T2" tanpa angka. Usul perbaikan (kode,
tanpa mengubah angka): baris `penyimpangan_master` per titik terdampak dengan sumber sel dan
selisihnya, pola `pengulangan_standar_dibagi_n`. Tidak dikerjakan di PK-4 (menyentuh profil
Timbangan dan jumlah baris jejak yang dibaca test lain) — butuh keputusan pemilik.

**Dampak (diperkuat 18 Nov 2026, tinjauan independen PR #272).** Penyimpangan T2 bukan sekadar
`ui` di dalam budget: ia mengubah angka CETAK. U95 bagian 7 Timbangan kg tercetak **0,033 kg**
padahal master **0,043–0,053 kg** (selisih sampai **−37 %**, arah **tidak konservatif**). Orang
yang menyetujui sesi hanya melihat 0,033 di sertifikat dan satu baris `varian_master` tanpa angka —
tidak ada yang menyebut master mencetak lebih besar, atau atas dasar apa. Paraf TM untuk T2 (butir 3,
16 Sep 2026) **masih menyusul**. Perlu PR lanjutan (kode): baris `penyimpangan_master` per titik
terdampak (T2/T6/T7: sumber sel, nilai master, nilai kita, alasan) **sebelum sertifikat kg diterbitkan**;
angkanya tidak berubah, hanya jejaknya. Sampai itu mendarat, sertifikat Timbangan kg dari sistem
ini menyimpang dari master tanpa catatan yang terbaca penyetuju (AGENTS.md §Olah data butir 4).

## PK4-4 — Master kg & substitusi mencetak baris ketiga "Penuh" (0 · 0 · 0)

`SERTIFIKAT!I21/L21/W21` mencetak `0 | 0.000000 | 0.00` (kg) dan `0 | 0.000000 | 0.0`
(substitusi): rujukannya menunjuk kolom yang tidak ada di blok Repeatability — kerusakan
salin-tempel (blade `TimbanganProfile::bagianSertifikatKeterulangan`). Aplikasi mencetak dua
baris. Dikunci sebagai selisih sengaja; mohon konfirmasi master bisa dibetulkan.

## PK4-5 — Anak Timbangan F1: angka cetak keping bergantung NOMOR KOTAK

Tiga belas dari empat belas keping sesi contoh F1 mencetak massa konvensional dan U (mg) persis
master. Keping 0,1 g tidak: master `I25` 0,0988 g, aplikasi 0,1000 g. Aplikasi meniru rumus sel
master per nomor kotak (kotak 2–4 koreksi apung 0, kotak 5+ memakai massa keping kotak 1;
keputusan pemilik 8 Okt 2026, pertanyaan §2 dan §5). Di master 0,1 g adalah kotak 9; di sesi
yang melewatkan lima keping `#VALUE!` ia menjadi kotak 4.

Konsekuensi nyata: **urutan baris di lembar kerja mengubah angka cetak keping**. Itu sifat
workbook master yang ditiru, bukan kesalahan kode — tetapi teknisi tidak mengetahuinya.

**Ditanyakan:** (a) apakah koreksi apung kotak 2–4 = 0 (§5) dan faktor "massa keping kotak 1"
(§2) tetap diinginkan, mengingat membuat hasil tergantung urutan? (b) kalau ya, apakah
lembar kerja perlu memaksa urutan kotak seperti master?

## PK4-6 — Pipet Volume & Labu Ukur: V20 sampel master 2026 tercetak 1,0039, master 1,0043

Sampel master Fixed 2026 (Pipet Volume 1 mL, `SERTIFIKAT!N21/S21`): aktual 1,0043 mL, koreksi
0,0043 mL. Aplikasi mencetak 1,0039 / 0,0039 — koreksi 9 % lebih kecil, dari selisih 0,4 µL.
Sebabnya keputusan pemilik 8 Okt 2026: Fixed mengikuti tujuh workbook lab Rev.7 (LU-200/250/500,
PV 0,5/2/3/4) yang mengetik `PERHITUNGAN!H40/J40` = 25,5 °C (§14), sedangkan template 2026
berumus. Cabang "suhu terukur" tetap dihitung dan tercatat di jejak sesi — mencetak **1,0043,
persis master** (dikunci di test). Peringatan `volumetric_suhu_25_5_menggeser_cetak` memang
dirancang untuk kasus ini.

Tambahan **Labu Ukur**: U95 tercetak 0,0067 mL, master 0,0030 mL. Aplikasi memakai baris Labu
Ukur lampiran LK-285-IDN (0,0067 mL), master Fixed satu pita 0,003 mL untuk semua jenis.

**Ditanyakan:** template mana yang berlaku untuk sertifikat baru — Rev.7 (angka mati 25,5 °C)
atau template 2026 (berumus)? Dan pita CMC Labu Ukur 0,0067 mL itu yang benar untuk 1 mL?

## PK4-7 — Buret dan Pipet Ukur: tidak ada sampel master yang sah untuk U95

Workbook Graduated hanya memuat sampel Gelas Ukur 100 mL. Sesi contoh Buret dan Pipet Ukur
meminjam masukannya (dua titik, resolusi 0,1 mL): volume aktual dan koreksi (10,6248 / 50,9279 mL;
0,6248 / 0,9279) tercetak persis master dan `k` tercetak 2. U95 tercetak 0,06 mL (U hitung 0,0566,
`k` 2,365).

Hitung ulang Excel (COM) atas salinan master dengan dua titik dan resolusi 0,1 mL memberi U hitung
**0,0683 mL** (`k` 2,447) dan lantai 0,17 mL — tetapi master Graduated dirancang untuk TIGA titik
terisi: mengosongkan titik 3 melonjakkan komponen keterulangan 20× (`PERHITUNGAN U95%!D40` 0,37 →
7,38), jadi angka itu bukan bukti dan tidak dijadikan acuan. U95 Buret/Pipet Ukur karenanya
**ditahan**: tidak ada pembanding master yang sah.

**Ditanyakan:** mohon workbook Buret dan Pipet Ukur sungguhan (atau contoh dua titik yang
disahkan lab) untuk mengadu U95 dan k.

---

## PK4-8 — Anak Timbangan: master mencetak nominal kembar berbintang, sertifikat aplikasi tidak

Master mencetak nominal keping KEMBAR sebagai teks berbintang: F1 `SERTIFIKAT!F19` `200*`; F2 hal 1
`F23` `20*`; F2 hal 2 `F19` `200*` (terbaca `Range.Text` Excel; sel lain yang kembar mencetak angka
tanpa bintang). Sertifikat aplikasi **tidak mencetak bintang sama sekali**: `snapshot['hasil']` tidak
membawa penandanya (`bintang` hanya hidup di blok sesi dan di hasil kalkulator, tidak dibaca view
`sertifikat.pdf`), dan sesi F2 yang kehilangan `ID Number` mencetak dua baris `- | 2 | …` tanpa pembeda.

Selisih ini sudah terbuka sebagai `docs/pertanyaan-lab-anak-timbangan.md` **§26** ("No. Seri keping &
bintang belum tercetak"); ditulis ulang di sini karena adu angka PK-4 sempat menyembunyikannya:
generator (`gen-cetak-master-massa-volume.py`, `ANGKA_TEKS` dan `cetak`) dan regex token
`PembandingCetakMaster::token` membuang `*` supaya baris cocok per angka. Sekarang `*` tetap dibuang
dari pencocokan TETAPI tercatat (`bintang: true` di fixture) dan keadaan aplikasi dikunci di
`AnakTimbanganSesiContohCocokMasterTest::assertBintangKembarTidakDicetak` — begitu §26 dijawab dan
bintang dicetak, assert itu membalik.

Keputusan pemilik 6 Okt 2026 menjadikan bintang di nominal pembeda keping kembar yang SAH (menggantikan
"kembar wajib No. Identitas"); tanpa bintang tercetak, pembeda itu tidak sampai ke pelanggan.

**Ditanyakan:** (a) bintang di nominal wajib tercetak seperti master? (b) kalau tidak, apakah
`ID Number` wajib untuk keping kembar? (jawaban §26 butir 1).

## PK4-9 — (pertanyaan KODE) jalur cetak Timbangan membulatkan lain dari profil lain

`TimbanganProfile::teksAngkaCetak` memakai `Angka::id` (`number_format`, nilai biner eksak);
profil lain memakai `Angka::tampilanExcel` (15 angka penting lalu setengah-menjauhi-nol — cara Excel
menampilkan). Nilai yang desimal-tepat di tengah tetapi tersimpan sedikit di bawahnya dicetak
berbeda. Dibaca dari Excel sendiri (`Range.Text`, sel baru berformat `0.0` / `0.00`, 18 Nov 2026):

| Nilai | Format | Excel | Timbangan sekarang |
|---|---|---|---|
| 0,1499999999999996 | `0.0` | `0.2` | `0,1` |
| 0,2549999999999999 | `0.00` | `0.26` | `0,25` |

Kontrol (di luar titik seri keduanya sama): 0,07953615325627225 → `0,08`; 0,7549999999999955 → `0,75`.

Mengapa lolos: tidak satu pun nilai sampel tiga master Timbangan jatuh di titik seri, jadi
`TimbanganSesiContohCocokMasterTest` hijau tanpa menyentuh jalur ini; mutan pembulatan `HALF_EVEN`
pada `teksAngkaCetak` **selamat** (tinjauan independen PR #272). Ini mirip seri S4-Q11 (`tids`,
0.755) yang ditangani PK-1 untuk profil lain.

**Tidak diperbaiki di PK-4** (aplikasi tidak diubah). Penandanya:
`tests/Unit/TimbanganTitikSeriCetakTest.php` — mengunci selisih sebagai "diketahui" dan gagal begitu
jalur Timbangan disamakan dengan Excel. **Ditanyakan ke pemilik/TM:** apakah sertifikat Timbangan wajib
mencetak persis tampilan Excel master di titik seri? Kalau ya, alihkan jalur cetak ke
`Angka::tampilanExcel` (PR kode terpisah; snapshot lama tidak boleh bergeser — cetak dari nilai yang
sama dibekukan di snapshot).

## PK4-10 — Lingkup klaim Timbangan GRAM: hanya master lama

"Gram cocok 23/23" hanya terhadap master gram LAMA (`New Master Olda Timbangan gram.xlsm`, sha256
`ce1a3936…`, yang dipakai seeder dan `TimbanganMasterTest`). Master gram revisi 29 Mei 2026
(`…gram.xlsm2.xlsm`, sha256 `7b288cdb…`) **tidak disentuh** dan tetap **ditahan** menunggu keputusan
lab (T9/T11/T14; audit 16 Nov §3 G4(c)). Terhadap xlsm2, **12 dari 68 sel gram tercetak berbeda**: `U29`
0,00058 → 0,00057, `M46` (LOP) 0,00077 → 0,00030, serta `H49:H53` dan `W49:W53` 0,00060 → 0,00057.

Jadi tes gram yang hijau TIDAK membuktikan sertifikat gram sesuai master yang sekarang dipegang lab.
**Ditanyakan:** master gram mana yang berlaku untuk sertifikat baru? Selama belum dijawab, jangan
menerbitkan sertifikat gram yang diklaim "persis master" tanpa menyebut revisinya.

---

## Yang bersih (tidak ada pertanyaan)

- **Piston Volume** (Piston Pipette, Dispensett, Buret Digital): semua baris cocok — volume aktual,
  koreksi, U95, `k`, suhu dasar; U95 dan `k` tersimpan sama dengan sel master.
- **Anak Timbangan F2** (tiga neraca, dua belas keping dibangun lewat API dari `INPUT DATA`
  master): dua belas keping mencetak massa konvensional dan U persis master hal 1–3, `k` = 2.
  Baris master di bawahnya (hal 1 baris 25–36 dst.) salinan sesi lain (§30), tidak diadu.
- **Gelas Ukur 100 mL**: tiga titik, U95 0,34, `k` 2 sama persis; U hitung 0,33703 vs master
  0,33731 (lima nol, pertanyaan volumetric no. 2) tidak menggeser angka cetak.
- **Timbangan gram — terhadap master LAMA saja** (PK4-10): seluruh baris cocok (setelah tare dan beban dilengkapi, PK4-1). Master gram xlsm2 (29 Mei 2026) tidak disentuh, ditahan; 12 dari 68 sel tercetak berbeda di sana.
- **Timbangan** budget koreksi tersimpan: 261 komponen (`ui × ci`, `vi`) sama dengan master,
  plus `uc`, `veff`, `k`, U95 tiap titik — kecuali dua titik master rusak (gram 9, substitusi 9).

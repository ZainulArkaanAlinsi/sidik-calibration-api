# Pertanyaan lab — adu ANGKA CETAK kelompok Massa & Volume (PK-4)

**Disusun:** 17 November 2026. **Untuk:** Manajer Teknis dan pemilik proyek.
**Cakupan:** Timbangan (kg, gram, substitusi), Anak Timbangan (F1, F2 hal 1–3), Volumetric
Glassware (Pipet Volume, Labu Ukur, Gelas Ukur, Buret, Pipet Ukur), Piston Volume (Piston
Pipette, Dispensett, Buret Digital). Picnometer dan Bath ditahan (PK-3).

Yang diadu: teks yang TERCETAK di `sertifikat.pdf` (renderer asli, sesi diterbitkan lewat
`POST /approve`) lawan teks yang dicetak lembar `SERTIFIKAT` workbook master — angka sel
master + number_format, dicocokkan ke `Range.Text` Excel sendiri (476 sel; 473 identik, 3 hanya
berbeda tanda bintang `200*`/`20*` yang sengaja dibuang; hitung ulang Excel atas keenam
`.xlsm` juga tidak menggeser satu pun sel `SERTIFIKAT`). Pengujinya
`tests/Feature/*SesiContohCocokMasterTest.php`; angka masternya
`tests/Fixtures/cetak-master/massa-volume.json` (generator
`docs/skrip/gen-cetak-master-massa-volume.py`).

Pola baku `docs/pertanyaan-lab-*.md`: kejanggalan master ditiru dan ditanyakan; yang berikut
**tidak dibetulkan diam-diam** — tiap butir dikunci di test sebagai selisih yang dicatat.

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

## Yang bersih (tidak ada pertanyaan)

- **Piston Volume** (Piston Pipette, Dispensett, Buret Digital): semua baris cocok — volume aktual,
  koreksi, U95, `k`, suhu dasar; U95 dan `k` tersimpan sama dengan sel master.
- **Anak Timbangan F2** (tiga neraca, dua belas keping dibangun lewat API dari `INPUT DATA`
  master): dua belas keping mencetak massa konvensional dan U persis master hal 1–3, `k` = 2.
  Baris master di bawahnya (hal 1 baris 25–36 dst.) salinan sesi lain (§30), tidak diadu.
- **Gelas Ukur 100 mL**: tiga titik, U95 0,34, `k` 2 sama persis; U hitung 0,33703 vs master
  0,33731 (lima nol, pertanyaan volumetric no. 2) tidak menggeser angka cetak.
- **Timbangan gram**: seluruh baris cocok (setelah tare dan beban dilengkapi, PK4-1).
- **Timbangan** budget koreksi tersimpan: 261 komponen (`ui × ci`, `vi`) sama dengan master,
  plus `uc`, `veff`, `k`, U95 tiap titik — kecuali dua titik master rusak (gram 9, substitusi 9).

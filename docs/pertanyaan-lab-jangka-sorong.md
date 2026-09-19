# Pertanyaan lab — Jangka Sorong (Vernier Caliper)

**Alat:** Vernier Caliper / Jangka Sorong — lampiran akreditasi LK-285-IDN no. 35 (0-300 mm, CMC 0,015 mm)
**Metode:** SIDIK-IK-CAL-0520_Rev.2 · **Kertas:** SIDIK-FM-CAL-0527_Rev.2
**Sumber:** `Master Olah Data Caliper 2026 (std caliper checker+gb).xlsm` (ber-password), sesi contoh `001-CAL-126`
**Disiapkan:** 15 September 2026

Rumus master sudah dibuktikan sel demi sel (311 sel cocok, toleransi 5·10⁻⁶) sebelum satu baris PHP
ditulis, dan dijaga `tests/Unit/JangkaSorongMasterTest.php`. Semua angka di bawah bisa diulang dari
`database/data/sesi-master-jangka-sorong.json`.

Klasifikasi tiap temuan mengikuti aturan proyek:

- **METODE** — kejanggalan cara hitung. Sistem MENIRU master dan menunggu keputusan manajer teknis.
- **KERUSAKAN** — rujukan sel meleset, tautan ke workbook lain, `NOW()`. Sistem MENGHITUNG BENAR dan
  menulis selisihnya.
- **SEL KOSONG** — nilai kosong yang terbaca nol. Sistem MEMBLOKIR.

## Ringkasan

| § | Temuan | Jenis | Menggeser angka terbit? |
|---|---|---|---|
| §1 | Drift `/12` padahal selisihnya HARI; pembagi `√6` pada komponen `rect.` | Metode | Ya — ditiru |
| §2 | Tidak ada lantai CMC, padahal alatnya di lampiran | Sel kosong | **Ya — sistem memasang lantai 0,015 mm** |
| §3 | Repeatability Depth menunjuk sel kosong (`AD121`) | Sel kosong | Ya bila ada sebaran |
| §4 | Bacaan Depth dibaca dari workbook lain | Kerusakan | Ya — koreksi Depth |
| §5 | Sertifikat: tabel Inside & Depth menunjuk kolom lama | Kerusakan | Ya — tercetak 0 |
| §6 | Depth: ci suhu dari balok 100 mm, ci muai 50 diketik, drift tanpa umur | Metode | Kecil — ditiru |
| §7 | Inside & Depth: STDEV 5 kolom, rata-rata 10 kolom | Metode | Tidak (STDEV per titik tidak masuk budget) |
| §8 | Evaluation seragam: peringatan, bukan penahan | Keputusan sistem | Tidak |
| §9 | Kertas cuma punya satu baris Evaluasi; master dua | Metode | Ya — Inside |
| §10 | Kerataan muka ukur: dua checkbox tidak saling meniadakan | Kerusakan | Tidak (cuma catatan) |
| §11 | Tumpukan balok ukur Depth dipatok | Keputusan sistem | Tidak |
| §12 | Inside memakai Lmaks, ϴ milik Outside | Metode | Tidak hari ini |
| §13 | Status standar cuma memantau Gauge Block | Kerusakan | Tidak |

---

## §1 — Drift `/12` dan pembagi `√6` [DITIRU]

`PERHITUNGAN U95%!K10 = (0,02 + 0,00025·Lmaks)/1000 · ((NOW() − tgl CC)/12)`. Selisih tanggal di Excel
satuannya **hari**, satuan komponennya ditulis `mm/th`. Micrometer memakai `/365` untuk komponen yang sama.
Pembagi komponen muai `N9 = √6` padahal distribusinya ditulis `rect.` (pembaginya `√3`).

Keduanya persis temuan Height Gauge §1-§2 (master Height Gauge turunan master ini).

**Dampak sesi contoh** (umur di `NOW()` master 146,5 hari): drift 0,0020750 mm; dengan `/365` → 6,8·10⁻⁵ mm;
U Outside 0,0332767 → 0,0331962 mm. Membetulkannya MENGECILKAN U, jadi sistem tidak melakukannya tanpa
keputusan lab.

> **Pertanyaan:** pembagi drift yang dimaksud `/12` (bulan) atau `/365` (tahun)? Pembagi komponen muai `√3` atau `√6`?

## §2 — Lantai CMC hilang [SISTEM MEMASANG LANTAI]

`AA20`, `AA39`, `AA59` kosong, jadi `U95 = MAX(U; kosong) = U`. Padahal Vernier Caliper **ada** di lampiran
(0-300 mm, 0,015 mm), dan angka 15 µm sudah tersedia di `DATABASE!T5` (`CMC_UTM`) — cuma tidak tersambung.

Ini beda dari Height Gauge, yang memang di luar lampiran.

**Dampak:** caliper digital resolusi 0,01 mm dengan sebaran Evaluation ±0,005 mm (L 150 mm) terbit
**≈ 0,0077 mm** di master — separuh CMC terakreditasi. Resolusi 0,02 → ≈ 0,0136 mm, masih di bawah.

**Yang dilakukan sistem:** `U95 = max(U, 0,015 mm)` di ketiga budget untuk kapasitas ≤ 300 mm. Kapasitas
**kosong** ditahan. Kapasitas **> 300 mm** (sesi contoh master: 600 mm) **terbit dengan U telanjang — persis
master — tetapi TANPA logo maupun nomor akreditasi** (`JangkaSorongProfile::dalamLingkupAkreditasiSesi()`,
dibekukan ke snapshot sertifikat), plus peringatan `jangka_sorong_diluar_akreditasi` ke admin. Preseden
Height Gauge, tapi per sesi karena lampiran membatasi RENTANG.

**Konsekuensi yang perlu diketahui:** master menerbitkan sesi 600 mm itu **dengan** logo LK-285-IDN.

> **DIPUTUSKAN 15 Sep 2026 (atas arahan pemilik proyek, "pake keputusan mu"):** jangka sorong > 300 mm
> **tetap diterbitkan, tanpa klaim akreditasi dan tanpa lantai CMC.** Lab memang mengkalibrasi alat itu
> (sesi contoh master 600 mm); menolak menerbitkan merugikan pelanggan, sementara yang dilarang KAN
> hanyalah klaim akreditasi di luar lingkup. Manajer teknis tetap boleh membaliknya.
>
> **Pertanyaan yang tersisa:**
> 1. Setuju lantai 0,015 mm dipasang di ketiga tabel (Outside, Inside, Depth)?
> 3. Sertifikat 600 mm yang sudah terbit dengan logo KAN — perlu ditinjau sebagai pekerjaan tidak sesuai?

## §3 — Repeatability Depth menunjuk sel kosong [DIHITUNG DARI SEL YANG DIMAKSUD]

`K43 = PERHITUNGAN!AD121` — sel kosong, jadi repeatability Depth **selalu 0**. Sel di sebelahnya, `AI121`,
berisi "Max STDEV" titik Depth, dan hampir pasti itu yang dimaksud.

**Dampak** (budget Depth sesi contoh): rep 0 → U 0,0289647 mm; `AI121` (0,005477) → 0,0291637 mm.

**Yang dilakukan sistem:** repeatability Depth = STDEV terbesar titik Depth, dibagi `√10`, vi 9 (pembagi &
vi ditiru). Pada sesi contoh bacaan Depth lokal identik sehingga hasilnya tetap 0 — begitu ada sebaran, U
Depth naik.

> **Pertanyaan:** benar `AI121` yang dimaksud? Pembagi `√10` dan vi 9 dipertahankan walau STDEV-nya dari 5 bacaan?

## §4 — Bacaan Depth dari workbook lain [DIHITUNG BENAR]

`PERHITUNGAN!I106:S118 = '[3]INPUT DATA'!…`, dengan `[3]` = `Master Olah Data_Jangka Sorong draft.xlsm`.
**Teknisi yang mengisi Depth di berkas ini tidak mengubah apa pun.** Di 4 dari 5 titik isinya berbeda:

| Nominal | Koreksi tercetak master (cache [3]) | Dari INPUT DATA berkas ini |
|---|---|---|
| 20 mm | −0,00195 | +0,00005 |
| 30 mm | −0,00322 | −0,00022 |
| 40 mm | +0,00114 | +0,00014 |
| 50 mm | −0,00410 | −0,00010 |

**Yang dilakukan sistem:** bacaan dari lembar sesi itu sendiri.

## §5 — Sertifikat Inside & Depth menunjuk kolom lama [TIDAK DIPAKAI]

`SERTIFIKAT` tabel Inside/Depth menunjuk `AC` (δϴ), `N` (satu bacaan), `AE` (lw) alih-alih `AH`/`U`/`AJ`.
Tercetak Inside: **standar 0, koreksi 0**, UUT = satu bacaan (400 mm → 400,5). Seharusnya 400 mm: standar
399,99885, rata-rata 400,15, koreksi −0,15115. `SERTIFIKAT (2)` bergeser satu baris lagi. Cabang inch
`L28` menunjuk kolom suhu.

Sertifikat sistem dibangun dari hasil hitung, bukan dari sel sertifikat master.

> **Pertanyaan:** sertifikat jangka sorong yang sudah terbit dengan tabel Inside/Depth bernilai 0 — perlu ditinjau?

## §6 — Depth: tiga angka yang tidak berasal dari sesinya [DITIRU]

- ci suhu = `'Perhitungan koef. Sensitivitas'!F7` = 2·10⁻⁵ × **99,9999** mm (balok 100 mm, dari tautan luar),
  padahal Depth ≤ 50 mm. Dengan L 50 mm: U 0,0289647 → 0,0289606 mm (lebih kecil — tidak dibetulkan diam-diam).
- ci muai `V48 = 50` **diketik**, bukan rumus (Outside memakai `Lmaks·ϴ`).
- Drift Depth tanpa faktor umur; Outside & Inside dengan umur. Dengan umur balok ukur `/12` (862,5 hari):
  U Depth 0,0289647 → 0,0290851 mm.

> **Pertanyaan:** panjang acuan ci suhu & ci muai Depth berapa? Drift Depth memakai umur balok ukur atau tidak?

## §7 — Inside & Depth: STDEV lima kolom [DITIRU]

`AI72 = STDEV(I72:M74)` (5 kolom), `U72 = AVERAGE(I72:T74)` (10 kolom). Kertas memang lima bacaan, jadi dengan
lima bacaan keduanya sama. Sesi contoh mengisi sepuluh: Inside 400 mm STDEV 5 kolom 0,02236 vs 10 kolom
0,12472 (bacaan 400,5 jatuh di kolom ke-6). STDEV per titik tidak masuk budget, jadi U tidak bergeser.

> **Pertanyaan:** Inside & Depth lima bacaan (kertas) atau sepuluh (sesi contoh)?

## §8 — Evaluation seragam: peringatan, bukan penahan [KEPUTUSAN SISTEM]

Di Micrometer dan Height Gauge sepuluh pembacaan Evaluation yang identik **menahan** sesi (di sana terbukti
data rusak, dan Height Gauge tidak punya lantai CMC). Di jangka sorong resolusi 0,05 mm pembacaan identik
wajar, komponen resolusi sudah menampung sebaran di bawah resolusi, dan lantai CMC 0,015 mm menjaga U95.
Jadi sistem **memperingatkan**. Kurang dari dua pembacaan tetap menahan.

> **Pertanyaan:** setuju?

## §9 — Satu baris Evaluasi di kertas, dua di master

Kertas `0527_Rev.2` punya satu baris Evaluasi. Master punya Evaluation Outside (`C31:M31`) dan Inside
(`C36:M36`) — di sesi contoh isinya salinan — dan budget Inside membaca yang kedua.

Lembar sistem meminta keduanya. Inside tanpa Evaluation-nya sendiri **ditahan**, bukan diam-diam memakai
Evaluation Outside.

> **Pertanyaan:** Evaluation Inside diukur terpisah (pakai rahang dalam)? Kalau ya, kertas perlu baris kedua.

## §10 — Kerataan muka ukur: dua checkbox

`Y22` (Baik) dan `Y23` (Buruk) dua CheckBox independen; `Z22 = IF(Y22=TRUE;"Baik";"Buruk")`, `Y23` tidak dibaca
apa pun. Keduanya bisa dicentang. Lembar sistem memakai **satu pilihan** Baik/Buruk.

## §11 — Tumpukan balok ukur Depth dipatok [KEPUTUSAN SISTEM]

Lima baris Depth memakai tumpukan sesi contoh: 7,5+2,5 · 11+9 · 21+9 · 40 · 50 mm. Aplikasi HP belum punya
jalur mengirim tumpukan per baris di tabel ini, jadi tumpukannya dipatok server.

> **Pertanyaan:** kelima tumpukan itu ketetapan Instruksi Kerja, atau pilihan teknisi per sesi?

## §12 — Inside memakai Lmaks, ϴ milik Outside [DITIRU SEBAGIAN]

`V27 = V8`, `V29 = V9` — ci Inside dari `C67` (nominal terbesar Outside). Sistem memakai nominal terbesar
Outside ∪ Inside: tidak pernah lebih kecil dari master. Suhu satu per sesi, jadi ϴ sama.

## §13 — Status standar cuma memantau Gauge Block

`S23 ← Y25 = DATABASE!Z13` (Gauge Block). Caliper Checker (`Z14`) — standar utama Outside & Inside — tidak
dipantau. Sistem memeriksa masa berlaku standar yang tertaut di sesi lewat jalur validator bersama.

---

## Lampiran — angka sesi contoh yang direproduksi

| Budget | uc (mm) | veff | k | U (mm) |
|---|---|---|---|---|
| Outside (10 komponen) | 0,016841260 | 150,04 | 1,975905 | 0,033276735 |
| Inside (10 komponen) | 0,016841260 | 150,04 | 1,975905 | 0,033276735 |
| Depth (11 komponen) | 0,014777606 | 30965,6 | 1,960041 | 0,028964708 |

Dengan umur drift dari tanggal sesi (2026-01-15, 6 hari sesudah sertifikat Caliper Checker): U Outside &
Inside 0,0331962 mm; Depth tidak berubah (tanpa faktor umur).

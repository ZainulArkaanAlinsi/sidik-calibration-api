# Pertanyaan lab — isi sertifikat Tahap B2 (blok khas master)

**Untuk:** Manajer Teknis, Lab Kalibrasi PT Sidik
**Sumber:** lembar `SERTIFIKAT` workbook master (ekstraksi audit 9–11 Nov 2026, R4)
**Disiapkan:** 12 November 2026

Tahap B2 menambah lima blok yang dicetak lembar `SERTIFIKAT` master tetapi belum
ada di sertifikat. Prinsip: ikut master, TANPA mengarang angka — nilai yang di
master diketik tangan hanya tercetak bila teknisi mengisinya di sesi. Hanya
sertifikat BARU yang memuatnya; sertifikat terbit tidak berubah.

| § | Blok | Sumber master | Sistem sekarang |
|---|---|---|---|
| 1 | Baris Tekanan Udara glassware Graduated | `144!V13:Y13` = `#REF!` | Dicetak `hPa ± U hPa` seperti master Fixed; BELUM final (menunggu konfirmasi lab) |
| 2 | Class/Permitted Error piston/dispensett/buret digital | `160!T14`, `164!T14` diketik | Isian teknisi; kosong = `- /± -` (bawaan sel master) |
| 3 | Kerataan/Kesejajaran Muka Ukur micrometer | `165:167!G32:G33` dicentang | Hanya dari isian; kosong = tidak dicetak |
| 4 | Measured Flowrate During Calibration | `148!K22:K23` diketik | DITAHAN (tidak dicetak) |
| 5 | Minimum Number of Apertures sieve | `169!F16` | Kolom tipe yang benar (Calibration ≠ master); selisih tercatat di jejak sesi |

## 1. Tekanan Udara pada master Graduated (buret, gelas ukur, pipet ukur) — salin-tempel rusak

`144!V13`, `W13`, `Y13` (satuan tekanan, `±`, satuan ketidakpastian) berisi
`=#REF!`; nilai `U13` (`933`) dan `X13` (`2.0`) sehat. Master Fixed `143!V13:Y13`
mengisinya `hPa`, `±`, `hPa` dari `PERHITUNGAN!J19/O19/Q19`, dan `PERHITUNGAN`
Graduated punya sel yang sama (`J19` `hPa`, `O19` `±`, `Q19` `hPa`).

Sistem mencetak `Tekanan Udara 933 hPa ± 2.0 hPa` (nilai = rata-rata tekanan
BACAAN awal/akhir tanpa koreksi, format `0`, persis master Fixed/Rev.7).
**Pertanyaan:** benar demikian maksud lab? Atau sertifikat Graduated memang tidak
mencantumkan tekanan?

**Status:** perbaikan ini BELUM final. Sikapnya sama dengan `docs/pertanyaan-lab-volumetric.md` #4 ("tidak mereplikasi `#REF!`, dan tidak menganggap perbaikannya final sebelum dikonfirmasi"): jawaban lab atas #4 di sana berlaku juga di sini.

## 2. Class/Permitted Error piston pipette, dispensett, buret digital

`T14` = `'INPUT DATA'!I16` (Fixed `160`) / `I17` (Graduated `164`): TEKS yang
diketik lab (`Class/Tolerance :`), isi bawaan selnya `- /± -`, plus satuan
kapasitas (`U14`). Sesi alat ini tidak punya field kelas/toleransi, sehingga kotak
sebelumnya ditahan. Sekarang kotak dicetak; isinya `spesifikasi_alat.piston.
kelas_toleransi` (teks bebas, maks. 40 karakter) atau `- /± -` bila kosong.

**Pertanyaan:** (a) format yang dipakai lab saat mengetik (`A/±0,5`?
`±60 µl`?) — adakah tabel MPE yang bisa dijadikan isian otomatis (master punya
`Tabel MPE` dan `MPE (±)` di `AC10:AF10`, di luar print area)? (b) Bolehkah
sertifikat mencetak `- /± - ml` bila lab tidak mengetik apa pun, seperti sel
master?

## 3. Note Kerataan / Kesejajaran Muka Ukur micrometer

`G32 = 'INPUT DATA'!W25`, `G33 = W30`: hasil centang `Baik`/`Buruk` (pemeriksaan
optical flat dan gauge block). Sesi micrometer belum menyimpannya; sekarang ada
dua pilihan lembar `spesifikasi_alat.micrometer.kerataan_muka_ukur` /
`kesejajaran_muka_ukur`. Kosong → barisnya tidak dicetak (tidak ada "Baik"
karangan).

**Pertanyaan:** apakah kedua pemeriksaan WAJIB diisi sebelum sertifikat
micrometer terbit? Jika ya, sistem perlu menahan persetujuan sesi tanpa isian
itu (keputusan produk, tidak dilakukan di B2).

## 4. Measured Flowrate During Calibration (flowmeter totalizer gravimetri, `148!K20:K25`) — DITAHAN

Kolom ini TIDAK dicetak. Pertanyaan lab `docs/pertanyaan-lab-flowmeter-gravimetri.md` §7 (0,77/0,78 literal diketik, `K24`/`K25` = 0 dari blok `Flowrate on Software (kg/s)` yang kosong; satuan header `m3/h` vs label sumber `kg/s`) belum dijawab dan sesi tidak punya field lembar untuk angkanya. Dicetak sekarang berarti SEMUA sertifikat totalizer gravimetri baru memuat kolom penuh `—`. Dilepas: kolom, aturan request `flowmeter.laju_alir_terukur`, dan tesnya. Dilanjutkan setelah §7 dijawab DAN field lembar (tabel per titik) dibuat.

## 5. Minimum Number of Apertures (sieve)

`L25 = 'INPUT DATA'!F16`; untuk tipe Calibration master memilih kolom Tabel MPE
Inspection (`F16` mengulang kolom 6) — sudah tercatat §5 `pertanyaan-lab-sieve.md`
dan dihitung benar (kolom tipenya). Angka yang dicetak sertifikat memakai fungsi
yang SAMA dengan penegakan minimum (`SieveCalculator::minimumOpening`), jadi
tidak mungkin berbeda dari yang ditolak/diterima sistem. Tipe Compliance/
Inspection sama dengan master. Untuk tipe Calibration yang dicetak kolom Calibration (mis. 19 mm: 30, master 15); selisihnya ditulis di jejak sesi dan ditanyakan di `pertanyaan-lab-sieve.md` §5.

## Tidak dikerjakan di B2 (butuh jawaban atau di luar cakupan)

- Isian blok nomor 2 dan 3 (Class piston, Note micrometer) belum ada di lembar kerja kertas FM-0529/0522
  dan formulir HP; perlu keputusan lab apakah ditambahkan ke kertas. Sampai itu
  terjadi, isian hanya lewat API/field lembar server.
- Master Picnometer tidak ada: dikerjakan mengikuti master Fixed (`143`)
  sesuai kelas profilnya. Tekanan Udara picnometer ikut §1 di atas.

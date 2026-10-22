# Pertanyaan Lab — Format cetak sertifikat (K-50-15)

Keputusan pemilik K-50-15: sertifikat = lembar `SERTIFIKAT` master persis,
termasuk jumlah desimal tiap sel. Sejak 22 Okt 2026 format sel master dibaca
dari `.xlsm` asli ke `format_sertifikat` peta sel (`database/data/peta-sel/*.json`)
dan **per sel** dibekukan di `tests/Fixtures/peta-sel-xlsm/format-sertifikat.json`
(`docs/skrip/bekukan-format-sertifikat-xlsm.py`; alamat + kode format saja, tanpa
nilai). `StudioFormatSertifikatTest` mengadu tiap sel peta ke fixture itu dan
peta ke render.

Butir di bawah lahir dari audit paritas format independen 22 Okt 2026 (commit
`d86fc69`). Semuanya **sudah dicetak persis master** kecuali #5 (ditahan) dan
#6 (bukan soal format). Yang ditanyakan: apakah perilaku master itu memang
disengaja. Jawaban "tidak disengaja" berarti master yang diperbaiki dulu, lalu
peta dibekukan ulang — bukan kode yang menyimpang diam-diam.

## 1. Micrometer 0–25 mm: U95 4 desimal, kolom lain 3

`SERTIFIKAT!J29` master 0–25 berformat `0.0000` (statis, tanpa conditional
format), sedangkan Standar/UUT/Koreksi (`D/I/L18:28`) `0.000`. Contoh: U95
0,000735 mm tercetak `0,0007` (format lama `0.000` → `0,001`). Sistem kini
mencetak 4 desimal untuk varian 0–25.

**Pertanyaan:** U95 sengaja satu desimal lebih teliti dari kolom hasil? Tetap?

## 2. Enclosure Constant/Yokogawa: indikator set point 5–6 `0.00`

`SERTIFIKAT!B25`, `B27`, `B29`, `B31` (Pembacaan Indikator Enklosur set point
1–4) berformat `0.0`, tetapi `B33` dan `B35` (set point 5–6) `0.00`. Polanya
sama dengan Suhu Setting `C46:C47` (General, sementara `C42:C45` `0.0`). Sistem
kini mencetak indikator set point 5–6 dua desimal
(`EnclosureProfileBase::FORMAT_INDIKATOR_PER_BARIS`).

**Pertanyaan:** format `0.00` di B33/B35 (dan General di C46/C47) disengaja,
atau sisa salin-tempel yang seharusnya `0.0` seperti baris di atasnya?

## 3. Micrometer 25–50 / 50–75 / 75–100: U95 `0.000` membulatkan naik

`SERTIFIKAT!J29` varian B/C/D berformat `0.000`. U95 0,00087 mm dan 0,00091 mm
tercetak `0,001` — naik 15 % dan 10 % dari nilai hitung. Varian A (#1) justru
4 desimal.

**Pertanyaan:** pembulatan ke `0,001` ini disengaja (U dibulatkan ke resolusi
alat), atau B/C/D seharusnya ikut `0.0000` seperti 0–25?

## 4. TIDS: UUT lebih halus dari format master tercetak lebih kasar

Format kolom Standard/UUT/Correction (`E/J/L20:33`) master ditentukan keluarga
standar, bukan resolusi UUT: Constant/Yokogawa `0.0`, Recorder `0.00`
(resolusi UUT `INPUT DATA!E16` hanya tercetak apa adanya di `SERTIFIKAT!I14`).
Akibatnya UUT beresolusi 0,01 yang dikalibrasi dengan standar
Constant/Yokogawa tercetak 1 desimal (`35,5`), dan UUT beresolusi 1 dengan
Recorder tercetak 2 desimal (`35,00`). Sistem kini mengikuti master.

**Pertanyaan:** diterima bahwa hasil tercetak lebih kasar (atau lebih halus)
dari resolusi UUT-nya?

## 5. Tekanan: format baris titik ke-7 dst. (DITAHAN, belum dicetak)

Master DRUCK07G, DRUCK13G, dan Differential memformat baris 28–34 (titik ke-7
s.d. ke-13) lain dari baris 22–27:

| Kolom | Baris 22–27 | Baris 28 | Baris 29–34 |
|---|---|---|---|
| UUT `E` (07G/13G) | `0` | `0.0` | `0.000` |
| UUT `E` (Differential) | `0.0` | `0.0` | `0.000` |
| Correction `N`, `P` (ketiganya) | `0.0` | `0.000` | `0.00` |

SPMK seragam di semua baris. Kode lama menilainya salin-tempel ("satu titik
tidak boleh tercetak lebih teliti hanya karena urutannya ke-7"), jadi render
**belum** diubah: semua baris tetap dicetak dengan format baris 22–27. Peta
mencatatnya per baris (`uut_baris_7`, `uut_baris_8_13`, `koreksi_baris_7`,
`koreksi_baris_8_13`) dan bedanya dipatok
`StudioFormatSertifikatTest::BEDA_DIKETAHUI`. Hanya berdampak pada sesi
tekanan 7 titik atau lebih (contoh master 3–6 titik).

**Pertanyaan:** titik ke-7 dst. ditiru persis master (format berganti di
tengah tabel), atau seragam mengikuti baris 22–27?

## 6. Timbangan: batas band Q 2000,001 kg vs master ≤ 2000 kg

Master `INPUT DATA!E4` menutup band kapasitas terakhir di `<= 2000` kg (gram
`<= 2000000`); di atas itu master menampilkan "ISI KAPASITAS". Tabel acuan
sistem (`tabel-standar-timbangan.json`) memakai `maks_kg` 2000,001, jadi
kapasitas 2000,0001–2000,001 kg masuk band Q di sistem tetapi ditolak master.
Praktis mustahil terjadi; dicatat supaya tidak ditemukan ulang.

**Pertanyaan:** batas atas band Q = tepat 2000 kg (ikut master)?

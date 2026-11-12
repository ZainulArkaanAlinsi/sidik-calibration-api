# Pertanyaan ke Lab — Sertifikat persis master, tahap B1

Status: MENUNGGU jawaban · Dibuat 12 Nov 2026 · Sumber: audit ulang 48 profil
(`_agents_workspace/audit-sertifikat-20261109/06-status-akhir-48-profil.md`),
butir R2 (baris Standard Used), R7 (Capacity/Graduation), R8 (minus nol).

Aturan: baris/teks yang datanya tidak ada di input app TIDAK ditebak — dicetak
`-` atau dilewati, lalu ditanyakan di sini.

---

## 1. Autoclave — kapasitas tekanan (`SERTIFIKAT!H15`)

Master mencetak dua baris Capacity/Graduation: `121 oC / 0.01 oC` dan
`0.2 MPa / 0.001 MPa`. Angka `0.2` = `INPUT DATA!H15` ("Range Pressure").
Lembar kerja app menampilkan kotak *Range Pressure* tetapi API tidak menyimpan
nilainya (`AutoclaveStoreRequest` hanya menerima `tekanan.satuan`,
`tekanan.resolusi_alat`, dst.). Akibatnya baris kedua tercetak
`- MPa / 0.001 MPa` (satuan dan resolusi sesuai master; kapasitas `-`).

**Yang ditanyakan:** apakah kapasitas tekanan boleh disimpan sebagai isian sesi
baru (`tekanan.kapasitas`)? Itu menambah satu field kontrak API + mobile, jadi
tidak dikerjakan di B1.

## 2. Autoclave — kapasitas suhu: nilai maksimum alat atau suhu sterilisasi?

Master mengetik `121` di `INPUT DATA!E15`. App mencetak `equipments.range_max`
(rentang terdaftar alat, mis. 135). Untuk alat yang rentang terdaftarnya bukan
121 hasilnya beda dari master.

**Yang ditanyakan:** `E15` itu kapasitas maksimum alat (ikut `range_max`) atau
suhu sterilisasi yang diketik per sesi?

## 3. Viscometer — Capacity selalu `-`?

Kedua workbook (`5. Viscometer 86068360 terbaru`, `Master Olah Data_Viscometer`)
mencetak `Capacity : - cP` (`INPUT DATA!E15` = `-`). App sekarang mencetak `-`
tetap, bukan `range_max` alat. Graduation: `Variable` (workbook terbaru, resolusi
berubah menurut spindle/rentang) atau `0.1` (workbook lama). App mencetak
`Variable` bila resolusi per titik yang ditulis teknisi
(`spesifikasi_alat.resolusi_titik_N`) tidak seragam, selain itu resolusi alat.

**Yang ditanyakan:** apakah aturan itu benar, atau `Variable` selalu dipakai
untuk DV Plus? (Terkait pertanyaan 9 di `pertanyaan-lab-viscometer.md`.)

## 4. Hydrometer — dua kalibrator suhu di tabel Standard Used

Master hydrometer selalu mencetak tiga baris: Analytical Balance,
`Termometer & Sensor Std.` (Yokogawa CA 150, 23P1005) dan `Caliper`
(Tesa Cal-IP67, LPI-0368). Lembar kerja app juga menyediakan centang
`Temperature Calibrator Yokogawa CA 150 Handy Cal` dan `PRT Pt-100`. Bila
teknisi mencentangnya, sertifikat baru mencetak baris itu SELAIN
`Termometer & Sensor Std.` — master hanya punya satu baris termometer.

**Yang ditanyakan:** apakah baris `Temperature Calibrator Yokogawa…` /
`PRT Pt-100` yang dicentang perlu disembunyikan dari tabel Standard Used
(tetap dipakai di budget), atau tetap dicetak?

## 5. Nama baris sensor/kalibrator di master standar lab

Baris `Thermocouple Type K/N` dan `PRT PT100` dicocokkan lewat nama lalu serial
(`TC-01,02`, `TCN-06,11`, `SH1/20`). Master mencetak kolom Merk/Type
`-/Type K`; app mencetak `Type K` bila kolom `merk` baris standar itu kosong.

**Yang ditanyakan:** untuk baris-baris itu apakah kolom `merk` di master
standar lab perlu diisi `-` agar cetakan identik dengan master?

## 6. Spectrophotometer & thermohygro — isian Capacity wajib?

Dua baris Capacity/Graduation dibaca dari isian teknisi
(`rentang_ukur_*`/`resolusi_*` spectro; `kapasitas`/`resolusi`/
`kapasitas_kelembaban`/`resolusi_kelembaban` thermohygro). Sesi yang belum
mengisi keempatnya jatuh ke bentuk lama (satu rentang dari data alat).

**Yang ditanyakan:** apakah keempat isian itu boleh dijadikan wajib sebelum
sesi dikirim?

## 7. Minus nol jangka sorong

Perubahan "Depth `-0.00` → `0.00`" memakai pola keputusan pemilik 10 Nov
(PDF master). Workbook caliper contoh tidak memuat koreksi negatif yang
membulat ke nol, jadi bukti langsungnya tidak ada di lembar contoh.

**Yang ditanyakan:** adakah sertifikat kertas asli jangka sorong dengan
koreksi `-0.00`? (Turbidimeter tetap ditahan.)

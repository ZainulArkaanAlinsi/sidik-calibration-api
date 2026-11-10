# G0-b — templat sertifikat persis master (10 Nov 2026)

Keputusan pemilik 10 Nov 2026: semua keputusan format P1–P7 ikut PERSIS lembar
`SERTIFIKAT` workbook master Excel. Berlaku untuk sertifikat BARU; snapshot lama
beku (dijaga `SertifikatBekuTidakBerubahTest`). Data: `app/Support/FormatMasterSertifikat.php`.

| Kunci `tata_letak` (baru) | Arti | Butir |
|---|---|---|
| `kop_pracetak` (cm) | kop gambar/teks, judul "CALIBRATION CERTIFICATE", kode dokumen kaki tidak dicetak; diganti ruang kosong = margin atas master (inci x 2,54) - 0,85 cm. QR & tanda tangan tidak tersentuh | P1 |
| `tanggal_master` | bahasa bulan kepala & Issuance Date per lembar (`[$-421]` id `dd mmmm yyyy`, `[$-809]` en `d mmmm yyyy`); `tambah_hari` = rumus `=<tgl kalibrasi>+N` | P2 |
| `kapasitas_master` | Capacity/Graduation = satu angka kapasitas diketik + graduasi (`250 ml / - ml`) | P3 |
| `tanda_nol` (per baris, sudah ada) | profil `tandaNolDicetak()=false` mencetak `0.0` — centrifuge, dial indicator, TITS, micrometer, tachometer (PDF master terbukti tanpa minus). Load cell, pressure, timbangan: belum terbukti dari PDF, tidak diubah. **Turbidimeter DITAHAN (tetap `true`, lihat bawah)** | P4 |

## Sakelar kop pracetak (P1) — bawaan MATI

`config('sertifikat.kop_pracetak')` (`config/sertifikat.php`, env `SERTIFIKAT_KOP_PRACETAK`) menentukan
apakah snapshot BARU membekukan `tata_letak.kop_pracetak` (`CalibrationProfile::tataLetakSertifikat`).

- **`false` (bawaan; `.env.example` dan `render.yaml` memakai `"false"` eksplisit):** kop/logo KAN, judul
  "CALIBRATION CERTIFICATE", dan kode dokumen kaki tercetak persis seperti sebelum G0-b. Kunci
  `kop_pracetak` tidak dibekukan (bernilai `null`).
- **`true`:** perilaku G0-b penuh — kop, judul, dan kode dokumen diganti ruang kosong setinggi margin atas
  master, dibatasi `RUANG_PRACETAK_MAKS_CM` (4,5 cm).
- **Bagian G0-b lain tidak punya sakelar** dan tetap aktif: tanggal per lembar (P2), Capacity/Graduation
  diketik (P3), minus nol (P4).
- Hanya sertifikat yang terbit SESUDAH nilainya berubah yang terpengaruh; snapshot lama beku.

**Kenapa mati dulu (keputusan koordinator atas delegasi pemilik, 10 Nov 2026):** sertifikat digital sampai ke
pelanggan lewat aplikasi/QR sebagai PDF; tanpa kop/logo KAN/judul PDF-nya kehilangan identitas lab. Ruang
kosong yang dipotong ke 4,5 cm juga bisa menimpa kop kertas pracetak FISIK bila kop itu lebih tinggi, dan
jenis kertas fisiknya belum diketahui. Diukur 10 Nov 2026: dengan sakelar mati `BUTUH_PADAT` sama dengan
sebelum G0-b (ditambah Viscometer dari G1/G3) dan `SertifikatPunyaMarginTest` hijau; dengan sakelar menyala
Viscometer muat di mode normal hanya dengan sisa 6 px (lebih mepet dari juara 8 px) sehingga tes margin merah.

**Cara menyalakan:** setelah pemilik memastikan jenis kertas (berkop pracetak, tinggi kop <= ruang yang
dikosongkan), ubah `SERTIFIKAT_KOP_PRACETAK` di `render.yaml` menjadi `"true"` lewat commit yang ditinjau
(blueprint menimpa setelan dashboard tiap deploy), lalu ukur ulang `BUTUH_PADAT` di
`SertifikatSemuaAlatSatuHalamanTest` dan `SertifikatPunyaMarginTest` — dengan kop pracetak daftar itu
memendek dan Viscometer sisanya hanya 6 px (diukur 10 Nov 2026). Tes G0-b yang menguji kop pracetak
menyetel sakelar `true` sendiri; tes `test_sakelar_kop_pracetak_mati_...` menjaga bawaan `false`.

Batas ruang pracetak 4,5 cm (`RUANG_PRACETAK_MAKS_CM`): margin master yang lebih lebar dipotong, karena sertifikat yang tumbuh lebih tinggi dari sebelum G0-b memaksa huruf mengecil lewat aturan satu halaman (Viscometer 5,9 cm masuk `BUTUH_PADAT` pada percobaan pertama). Profil terpotong: lihat laporan G0-b (viscometer, centrifuge, thermohygro, load cell, anak timbangan, tachometer, dst.).

## P6 — workbook baku bila >1 (alasan)
- conductivity: dua lembar (STYLE 1 `+4`, STYLE 2 `+2`), belum ada pemilih -> margin STYLE 1, tanggal terbit TIDAK ditebak (`tambah_hari` null).
- jangka_sorong: `SERTIFIKAT` (bukan `SERTIFIKAT (2)`/Depth) -> margin 1,97; Issuance Date = tanggal kalibrasi +0 (terbukti penjaga-angka).
- turbidimeter: DITAHAN dari G0-b (keputusan koordinator 10 Nov 2026) — `tambah_hari` null (tanggal terbit sistem) dan `tandaNolDicetak()` tetap `true` (tanda minus dipertahankan, perilaku lama). Alasan: master lama `+1` berlawanan dengan job Jun 2026 `+4`, dan bukti kertas lama (`0189-CAL-624` mencetak `-0,00`) bertentangan dengan PDF master (`0.00`). Menunggu konfirmasi pemilik.
- flowmeter_flowrate (`2-1` +4 vs UFM tanpa +N), flowmeter_totalizer (+4 vs +1; koreksi dari catatan awal "+3"), ph_meter (trial +4 vs IMTE +3), timbangan (gram/kg +4 vs substitusi +1): workbook berbeda untuk satu kode profil, nilai bentrok -> `tambah_hari` null (tanggal terbit sistem) sampai pemilik memilih. Conductivity, tids, enclosure (inkubator/oven/furnace/refrigerator), vacuum_gauge: tak terbukti -> null.
- piston_pipette dan dispensett: **+4** (Fixed Piston `D62` = `Q16+4`; koreksi dari catatan awal "fixed tanpa +N"). thermocouple dan thermometer_glass: **+1** (`INPUT DATA!E68`/`E67` = `P16+1`). gas_detector, jangka_sorong, height_gauge: **+0**.
- margin atas: dari workbook pertama yang tercantum di tabel (anak timbangan F2, 3 micrometer sama, enclosure Recorder untuk oven/furnace/refrigerator).

## P7 — master tidak ada (tidak ditebak)
bath, buret, buret_digital, picnometer: tanpa baris di `FormatMasterSertifikat` -> bentuk lama (kop tetap). Master 13G/SPMK/Differential ada di folder ekstraksi tetapi profilnya "ditahan"; margin dipakai dari Druck 13G/Differential. TIDS memakai master Recorder Graptech (Yokogawa K N sama +N-nya kosong).

## Issuance Date dibatasi hari penerbitan

Issuance Date yang dibekukan = `min(tanggal kalibrasi + N, hari penerbitan)`
(`CertificateSnapshotBuilder::tanggalTerbitMaster`). Hari penerbitan = `certificates.diterbitkan_pada`
(sumber yang sama dengan footer lama; bila belum ada, hari ini). Alasan: sertifikat tidak boleh mengaku
terbit SESUDAH hari ia benar-benar diterbitkan — kalibrasi tanggal 1 yang diterbitkan tanggal 1 dengan
N=4 tercetak tanggal 1 (bukan 5), sementara layar pelanggan menampilkan tanggal terbit asli. Terbit
lambat: tanggal kalibrasi + N. Dijaga `test_tanggal_terbit_dibatasi_hari_penerbitan_sebenarnya`.

## Capacity/Graduation — pipet ukur dan kapasitas non-bulat

`pipet_ukur` ikut `kapasitas_master` dengan bentuk `N ml / - ml` (master `pipet-ukur-pv-*` memakai
Fixed: graduasi `-`). **Penyimpangan sengaja dari master (`SERTIFIKAT!F11`)**: sel itu berformat `0`
sehingga kapasitas 0,5 ml tercetak `1`; sertifikat mencetak nilai SEBENARNYA (`0,5 ml / - ml`) karena
nilai yang tercetak tidak boleh menyalahi alatnya. Berlaku untuk semua alat `kapasitas_master`.

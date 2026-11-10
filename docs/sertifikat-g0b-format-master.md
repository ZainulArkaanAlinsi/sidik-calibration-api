# G0-b — templat sertifikat persis master (10 Nov 2026)

Keputusan pemilik 10 Nov 2026: semua keputusan format P1–P7 ikut PERSIS lembar
`SERTIFIKAT` workbook master Excel. Berlaku untuk sertifikat BARU; snapshot lama
beku (dijaga `SertifikatBekuTidakBerubahTest`). Data: `app/Support/FormatMasterSertifikat.php`.

| Kunci `tata_letak` (baru) | Arti | Butir |
|---|---|---|
| `kop_pracetak` (cm) | kop gambar/teks, judul "CALIBRATION CERTIFICATE", kode dokumen kaki tidak dicetak; diganti ruang kosong = margin atas master (inci x 2,54) - 0,85 cm. QR & tanda tangan tidak tersentuh | P1 |
| `tanggal_master` | bahasa bulan kepala & Issuance Date per lembar (`[$-421]` id `dd mmmm yyyy`, `[$-809]` en `d mmmm yyyy`); `tambah_hari` = rumus `=<tgl kalibrasi>+N` | P2 |
| `kapasitas_master` | Capacity/Graduation = satu angka kapasitas diketik + graduasi (`250 ml / - ml`) | P3 |
| `tanda_nol` (per baris, sudah ada) | profil `tandaNolDicetak()=false` mencetak `0.0` | P4 |

Batas ruang pracetak 4,5 cm (`RUANG_PRACETAK_MAKS_CM`): margin master yang lebih lebar dipotong, karena sertifikat yang tumbuh lebih tinggi dari sebelum G0-b memaksa huruf mengecil lewat aturan satu halaman (Viscometer 5,9 cm masuk `BUTUH_PADAT` pada percobaan pertama). Profil terpotong: lihat laporan G0-b (viscometer, centrifuge, thermohygro, load cell, anak timbangan, tachometer, dst.).

## P6 — workbook baku bila >1 (alasan)
- conductivity: dua lembar (STYLE 1 `+4`, STYLE 2 `+2`), belum ada pemilih -> margin STYLE 1, tanggal terbit TIDAK ditebak (`tambah_hari` null).
- jangka_sorong: `SERTIFIKAT` (bukan `SERTIFIKAT (2)`/Depth) -> margin 1,97; tanggal tanpa rumus +N.
- turbidimeter: `master-olah-data-turbidimeter` (+1), yang diikuti app (bandingkan T45-5); `turbidimeter-22120d000010` (+4) tidak dipakai.
- flowmeter_flowrate (`2-1` +4 vs UFM tanpa +N), flowmeter_totalizer (+3 vs tanpa), ph_meter (trial +4 vs IMTE +3), piston/dispensett (fixed tanpa +N vs graduated +4), timbangan (gram/kg +4 vs substitusi +1): workbook berbeda untuk satu kode profil, nilai bentrok -> `tambah_hari` null (tanggal terbit sistem) sampai pemilik memilih.
- margin atas: dari workbook pertama yang tercantum di tabel (anak timbangan F2, 3 micrometer sama, enclosure Recorder untuk oven/furnace/refrigerator).

## P7 — master tidak ada (tidak ditebak)
bath, buret, buret_digital, picnometer: tanpa baris di `FormatMasterSertifikat` -> bentuk lama (kop tetap). Master 13G/SPMK/Differential ada di folder ekstraksi tetapi profilnya "ditahan"; margin dipakai dari Druck 13G/Differential. TIDS memakai master Recorder Graptech (Yokogawa K N sama +N-nya kosong).

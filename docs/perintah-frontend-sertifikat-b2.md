# Sertifikat Tahap B2 — field lembar baru, serah terima ke mobile

Dokumen ini berdiri sendiri. Tahap B2 (12 Nov 2026) menambah blok sertifikat yang
dicetak lembar `SERTIFIKAT` master. Semua field baru **opsional**: HP lama yang
tidak mengirimnya tetap diterima, dan sertifikatnya tercetak seperti sebelumnya
(tanpa blok yang bersangkutan). Tidak ada kolom database baru; semuanya di
`spesifikasi_alat`. Server memvalidasinya di `CalibrationRequest`.

Lembar kerja (`bentukLembarKerja`) sudah memuat field-nya; `docs/skrip/gen-contoh-lembar-kerja.php`
perlu dijalankan ulang untuk `contoh_lembar_kerja_*.dart` bila mobile memakainya.

## Field baru

| Profil | Kunci (`spesifikasi_alat.…`) | Tipe | Nilai | Dicetak di sertifikat |
|---|---|---|---|---|
| `micrometer` | `micrometer.kerataan_muka_ukur` | pilihan, opsional | `baik` \| `buruk` (selain itu 422) | `Note :` → `Kerataan Muka Ukur dalam Kondisi : Baik/Buruk` |
| `micrometer` | `micrometer.kesejajaran_muka_ukur` | pilihan, opsional | `baik` \| `buruk` (selain itu 422) | `Kesejajaran Muka Ukur dalam Kondisi : Baik/Buruk` |
| `piston_pipette`, `dispensett`, `buret_digital` | `piston.kelas_toleransi` | teks, opsional, maks 40 karakter | bebas, mis. `A/±0.5` | kotak kepala `Class/Permitted Error : <teks> <satuan>` |

Aturan cetak:
- Micrometer: kosong → baris itu tidak dicetak; keduanya kosong → tanpa blok `Note`. Server tidak pernah mengisi "Baik" sendiri.
- Piston/dispensett/buret digital: kosong → kotak tercetak `- /± - ml` (isi bawaan sel master, bukan karangan). Satuan = `piston.satuan` (bawaan `ml`).
- Koma desimal pada teks kelas dibakukan menjadi titik di sertifikat.

## Tidak ada perubahan untuk mobile

- Baris `Tekanan Udara <P> hPa ± <U> hPa` (buret, gelas ukur, pipet ukur, picnometer) dan `Minimum Number of Apertures Examined` (sieve) dihitung server dari data yang sudah ada. Tidak ada field baru.

## Ditahan

- Kolom `Measured Flowrate During Calibration` (flowmeter totalizer gravimetri, `148!K20:K25`)
  **tidak** dicetak dan **tidak ada** field untuknya, sampai pertanyaan lab
  `docs/pertanyaan-lab-flowmeter-gravimetri.md` §7 dijawab dan tabel per titik
  dibuat di lembar. Rujukan: `docs/pertanyaan-lab-sertifikat-b2.md` §4.

## Rujukan server

`MicrometerProfile::catatanSertifikat`, `PistonVolumeProfile::tataLetakSertifikat` / `isiTambahanKepala`,
`SieveProfile::catatanSertifikat`, `VolumetricGlasswareProfile::tekananEnvSertifikat`;
tes `tests/Feature/SertifikatTahapB2Test.php`, `MicrometerSesiTest`.

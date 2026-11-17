<?php

namespace App\Services\Calibration\Profiles;

/**
 * **Picnometer** — lampiran LK-285-IDN no. 21, keluarga Fixed.
 *
 * Mengikuti workbook lab **Rev.7** seperti Labu Ukur & Pipet Volume. Picnometer
 * ternyata TIPE ALAT di dalam master Fixed 12 Nov 2026 (`INPUT DATA!E6 = 4`,
 * `DATABASE!U34:V41` "4 Picnometer" -> `Type_Alat`): satu rantai hitung, satu
 * lembar `SERTIFIKAT`, tanpa budget khusus picnometer. Satu-satunya
 * percabangan tipe di master ada di CMC — `PERHITUNGAN U95%!B48` (nominal
 * terdekat) dan `J48` (`CMC_picno` = `DATABASE!AB5:AC7`: 25/50/100 mL =
 * 0,018/0,019/0,022 mL, sama dengan lampiran no. 21 di
 * `database/data/kemampuan-kalibrasi.json`). Master itu menjawab pertanyaan
 * lab no. 6 (keluarga picnometer) di `docs/pertanyaan-lab-volumetric.md`.
 *
 * Yang ikut berubah dari bentuk Fixed lama: tujuh komponen budget tanpa
 * keterulangan, `veff` memakai `SUM` semua baris (bukan baris terakhir),
 * ρ air ulangan 1 & 2 pada 25,5 °C (`PERHITUNGAN!H40`/`J40`), rentang suhu
 * `O35` yang melewatkan `M39`, bacaan suhu air 3 ATAU 6, neraca Fujitsu +
 * termometer Yokogawa + thermobarometer Lutron di lembar. Semua kejanggalan
 * master ditiru dan diberi catatan jejak/peringatan oleh
 * [FixedVolumetricGlasswareRev7Profile].
 *
 * Kode metode picnometer (0510_Rev.7 lawan 0513_Rev.4) tetap dipilih lab per
 * sesi — master tidak memetakannya per tipe (pertanyaan lab Picnometer no. 8).
 */
class PicnometerProfile extends FixedVolumetricGlasswareRev7Profile
{
    public function kode(): string
    {
        return 'picnometer';
    }

    /** PERSIS nama lampiran no. 21. */
    public function namaAlatKemampuan(): string
    {
        return 'Picnometer';
    }

    public function aliasNama(): array
    {
        return ['Piknometer', 'Pycnometer'];
    }
}

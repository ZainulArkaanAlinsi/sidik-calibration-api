<?php

/*
|--------------------------------------------------------------------------
| Resolusi alat Autoklaf (cadangan)
|--------------------------------------------------------------------------
|
| Tabel koreksi kalibrator + CMC (master `STANDAR KALIBRATOR` & `DATABASE`
| `Master Olah Data_Autoclave.xlsm`, LK-285-IDN) PINDAH 22 Okt 2026 ke data
| acuan lapis 1 `database/data/tabel-standar-autoclave.json`
| (`App\Services\Calibration\TabelStandarAutoclave`, generator
| `docs/skrip/gen-tabel-standar-analitik.py autoclave`, disunting lewat Studio
| Data Acuan). Rekalibrasi Temperature Calibrator / Pressure Disk = versi data
| acuan baru, bukan sunting berkas ini.
|
| Yang tersisa di sini cuma resolusi alat UUT bawaan — milik alat pelanggan,
| bukan data acuan.
|
*/

return [

    // Resolusi alat UUT default (INPUT DATA E16 / H16). Bisa dioverride per sesi
    // lewat payload; ini cuma cadangan kalau teknisi nggak ngisi.
    'resolusi_alat' => [
        'suhu' => 0.01,       // °C
        'tekanan' => 0.001,   // dalam satuan display alat
    ],

];

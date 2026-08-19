<?php

/*
|--------------------------------------------------------------------------
| Data standar & CMC Autoklaf
|--------------------------------------------------------------------------
|
| Tabel koreksi kalibrator + CMC diambil dari master `STANDAR KALIBRATOR` &
| `DATABASE` di `Master Olah Data_Autoclave.xlsm` (LK-285-IDN). Ditaruh di config,
| bukan database, dengan alasan yang sama kayak `config/kalibrasi.php`:
| pengaturan yang cuma hidup di DB gampang ketinggalan waktu deploy ke server
| baru, dan sertifikat yang diam-diam beda isi antar server itu temuan audit.
|
| Angka ini = kondisi kalibrator PER 2025-2026. Waktu ketiga Temperature
| Calibrator / Pressure Disk direkalibrasi, PERBARUI tabel di sini (dan nomor
| sertifikat + due date-nya) — nilainya masuk langsung ke budget ketidakpastian.
|
| Bentuk tabel:
|   suhu.<n>.tabel  = list {set (°C), koreksi (°C), u95 (°C)}
|   tekanan.tabel   = list {set (bar), koreksi_up (bar), u95 (bar)}
|
*/

return [

    // CMC PT. SIDIK — DATABASE!S5 (suhu) & S6 (tekanan, bar).
    'cmc' => [
        'suhu' => 0.34,
        'tekanan' => 0.059,
    ],

    // Resolusi alat UUT default (INPUT DATA E16 / H16). Bisa dioverride per sesi
    // lewat payload; ini cuma cadangan kalau teknisi nggak ngisi.
    'resolusi_alat' => [
        'suhu' => 0.01,       // °C
        'tekanan' => 0.001,   // dalam satuan display alat
    ],

    // Tiga Temperature Calibrator (Tecnosoft SterilDisk). Resolusi 0,01 °C.
    'suhu' => [
        [
            'nama' => 'Temperature Calibrator 1',
            'serial' => '1011001961',
            'resolusi' => 0.01,
            'tabel' => [
                ['set' => 21, 'koreksi' => -0.18, 'u95' => 0.43],
                ['set' => 50, 'koreksi' => -0.10, 'u95' => 0.43],
                ['set' => 75, 'koreksi' => -0.05, 'u95' => 0.43],
                ['set' => 100, 'koreksi' => -0.08, 'u95' => 0.43],
                ['set' => 120, 'koreksi' => 0.13, 'u95' => 0.43],
                ['set' => 140, 'koreksi' => 0.19, 'u95' => 0.43],
            ],
        ],
        [
            'nama' => 'Temperature Calibrator 2',
            'serial' => '1011004038',
            'resolusi' => 0.01,
            'tabel' => [
                ['set' => 23, 'koreksi' => -0.16, 'u95' => 0.44],
                ['set' => 50, 'koreksi' => -0.15, 'u95' => 0.44],
                ['set' => 75, 'koreksi' => -0.10, 'u95' => 0.44],
                ['set' => 100, 'koreksi' => 0.11, 'u95' => 0.44],
                ['set' => 120, 'koreksi' => 0.20, 'u95' => 0.44],
                ['set' => 140, 'koreksi' => 0.23, 'u95' => 0.44],
            ],
        ],
        [
            'nama' => 'Temperature Calibrator 3',
            'serial' => '1011004063',
            'resolusi' => 0.01,
            'tabel' => [
                ['set' => 23, 'koreksi' => -0.23, 'u95' => 0.44],
                ['set' => 50, 'koreksi' => -0.11, 'u95' => 0.44],
                ['set' => 75, 'koreksi' => -0.07, 'u95' => 0.44],
                ['set' => 100, 'koreksi' => 0.10, 'u95' => 0.44],
                ['set' => 120, 'koreksi' => 0.15, 'u95' => 0.44],
                ['set' => 140, 'koreksi' => 0.18, 'u95' => 0.44],
            ],
        ],
    ],

    // Pressure Disk Logger (Tecnosoft PressureDisk 05). Resolusi 0,001 bar.
    // Koreksi arah UP dipakai di master (kolom E `Correction UP`).
    'tekanan' => [
        'nama' => 'Pressure Disk Logger',
        'serial' => '3501009550',
        'resolusi' => 0.001,
        'tabel' => [
            ['set' => 1.5, 'koreksi_up' => 0.0, 'u95' => 0.0046],
            ['set' => 2.0, 'koreksi_up' => -0.0006, 'u95' => 0.0046],
            ['set' => 2.5, 'koreksi_up' => -0.001145618125302228, 'u95' => 0.0046],
            ['set' => 3.0, 'koreksi_up' => -0.0014, 'u95' => 0.0046],
            ['set' => 3.5, 'koreksi_up' => -0.0015, 'u95' => 0.0046],
            ['set' => 4.0, 'koreksi_up' => -0.0018, 'u95' => 0.0046],
            ['set' => 4.5, 'koreksi_up' => -0.0013, 'u95' => 0.0046],
        ],
    ],
];

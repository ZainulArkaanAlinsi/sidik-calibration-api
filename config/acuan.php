<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Sumber data acuan (Studio Data Acuan)
    |--------------------------------------------------------------------------
    |
    | Dari mana tabel acuan lapis 1 (tabel standar, pita CMC, konstanta metode)
    | dibaca.
    |
    |   'json'  berkas `database/data/*.json` — perilaku hari ini. (bawaan)
    |   'db'    versi `paket_acuan_versi` yang berlaku.
    |
    | Pindah ke 'db' = rollout tahap 3, perintah terpisah SESUDAH impor awal
    | paket di produksi — bukan efek samping deploy. Dipatok 'json' di
    | render.yaml dan kedua phpunit*.xml supaya setelan lokal/dashboard tidak
    | diam-diam mengganti sumber angka.
    |
    */

    'sumber' => env('DATA_ACUAN_SUMBER', 'json'),

];

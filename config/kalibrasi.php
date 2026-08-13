<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kolom R² blok %T (Spectrophotometer)
    |--------------------------------------------------------------------------
    |
    | Sertifikat master punya satu kolom `R2` di blok `Accuracy %T and Linierity
    | at λ = 560nm`. Sistem BELUM nyetak kolom itu, dan sebabnya bukan teknis:
    | angka di master (0,9359) nggak bisa ditelusuri dari data blok itu sendiri,
    | sementara sertifikat cetak yang beredar nulis `1`. Dua angka, satu kolom.
    |
    | Riwayat penelusuran & tiga pertanyaan yang nunggu jawaban lab ada di
    | `docs/pertanyaan-lab-r2-spektro.md`. Selama belum dijawab, nilainya
    | `off` — kolomnya nggak muncul sama sekali, bukan muncul kosong dan bukan
    | muncul berisi angka hitungan sendiri.
    |
    | Kenapa di config, bukan di kode: yang belum diputus itu DEFINISINYA, dan
    | jawaban lab bakal milih satu dari daftar di bawah. Kenapa nggak di
    | database: pengaturan yang cuma hidup di DB gampang ketinggalan waktu
    | deploy ke server baru, dan sertifikat yang diam-diam beda isi antar server
    | itu temuan audit.
    |
    | Pilihan yang dikenal:
    |
    |   'off'                 Kolom R² nggak dicetak. (bawaan)
    |
    |   'rsq_standar_uut'     R² = RSQ(Standard %T, UUT %T) atas SELURUH titik
    |                         blok %T. Ini satu-satunya pasangan yang punya arti
    |                         fisika buat linieritas fotometrik: sumbu X nilai
    |                         benar filternya, sumbu Y yang dibaca alat.
    |                         Dengan data master hasilnya 0,999922 — BEDA dari
    |                         0,9359 yang tertulis di master. Jangan dinyalain
    |                         sebelum lab mbenerin bedanya secara tertulis.
    |
    | Nilai yang nggak dikenal diperlakukan sama kayak `off`: salah ketik di
    | `.env` nggak boleh bisa ngubah isi sertifikat terakreditasi.
    |
    */

    'r2_spektro' => env('SPEKTRO_R2', 'off'),

];

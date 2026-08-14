<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kolom R² blok %T (Spectrophotometer)
    |--------------------------------------------------------------------------
    |
    | Sertifikat master punya satu kolom `R2` di blok `Accuracy %T and Linierity
    | at λ = 560nm`. Kolomnya sempat dimatiin karena angkanya kelihatan dobel:
    | sel masternya nyimpen 0,9359, sementara sertifikat cetak nulis `1`.
    |
    | Yang mbukain: sel itu diformat NOL DESIMAL. `0,9359` dan hasil hitung
    | ulang dari data blok (`RSQ` = 0,999922) dua-duanya tercetak `1`. Jadi
    | pertanyaan "0,9359 itu dari mana" masih nggantung, tapi dia nggak lagi
    | ngalangin: apa pun jawabannya, yang KECETAK di sertifikat sama. Kolomnya
    | dinyalain, cetaknya 0 desimal. Riwayat lengkapnya di
    | `docs/pertanyaan-lab-r2-spektro.md`.
    |
    | Kenapa tetap di config, bukan dikunci di kode: definisinya masih bisa
    | diganti lab, dan sakelarnya kepake buat mbandingin. Kenapa nggak di
    | database: pengaturan yang cuma hidup di DB gampang ketinggalan waktu
    | deploy ke server baru, dan sertifikat yang diam-diam beda isi antar server
    | itu temuan audit.
    |
    | Pilihan yang dikenal:
    |
    |   'rsq_standar_uut'     R² = RSQ(Standard %T, UUT %T) atas SELURUH titik
    |                         blok %T. (bawaan) Ini satu-satunya pasangan yang
    |                         punya arti fisika buat linieritas fotometrik:
    |                         sumbu X nilai benar filternya, sumbu Y yang dibaca
    |                         alat. Dengan data master hasilnya 0,999922, dan di
    |                         0 desimal itu `1` — sama kayak masternya.
    |
    |   'off'                 Kolom R² nggak dicetak sama sekali.
    |
    | Nilai yang nggak dikenal diperlakukan sama kayak `off`: salah ketik di
    | `.env` nggak boleh bisa ngubah isi sertifikat terakreditasi.
    |
    */

    'r2_spektro' => env('SPEKTRO_R2', 'rsq_standar_uut'),

];

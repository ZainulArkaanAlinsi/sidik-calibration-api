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

    /*
    |--------------------------------------------------------------------------
    | Gerbang pengesahan sertifikat
    |--------------------------------------------------------------------------
    |
    | Keputusan 26 Sep 2026: sertifikat tidak lagi lahir waktu admin mencet
    | Setujui. Admin "Setujui & ajukan terbit" → `menunggu_pengesahan` → super
    | admin "Sahkan & terbitkan" → nomor sertifikat dialokasikan.
    |
    | Kenapa di config, bukan langsung dinyalakan: perubahan ini mengubah
    | perilaku endpoint yang dipakai ratusan test. Sakelar mati = approve()
    | menulis & menerbitkan persis seperti sebelumnya, jadi berkas-berkas
    | barunya bisa mendarat hijau dalam satu PR, lalu perilakunya dinyalakan di
    | PR kedua bersama pembaruan test lama.
    |
    | Satu-satunya beda saat sakelar mati: dua field OPSIONAL baru
    | (`penandatangan_user_id`, `catatan_pengajuan`) ikut divalidasi di
    | approve(). Klien lama tidak mengirimnya, jadi tidak tersentuh; nilai yang
    | dikirim dan valid diabaikan selama sakelar mati.
    |
    | Kenapa nggak di database: sakelar yang cuma hidup di DB gampang
    | ketinggalan waktu deploy ke server baru, dan dua server yang diam-diam
    | beda alur penerbitan sertifikat itu temuan audit.
    |
    | Sebulan sesudah PR kedua berjalan, hapus kunci ini beserta semua cabang
    | `$gerbangAktif`. Sakelar yang tidak pernah dimatikan lagi cuma dua jalur
    | kode yang harus diuji dua kali.
    |
    */
    'gerbang_pengesahan' => (bool) env('GERBANG_PENGESAHAN', false),

    /*
    |--------------------------------------------------------------------------
    | Pemisahan wewenang: memblokir atau memperingatkan
    |--------------------------------------------------------------------------
    |
    | false (default) → pengesah yang juga mengisi/memeriksa lembar kerja dapat
    | peringatan yang harus diakui, boleh lanjut, dan pelanggarannya tercatat di
    | `audit_logs`. true → 422, pengesahan ditahan.
    |
    | Default false bukan kelonggaran yang dilupakan: PT SIDIK hari ini punya
    | satu super admin. Memblokir berarti sertifikat berhenti terbit, dan yang
    | terjadi berikutnya bukan kepatuhan tapi saling pinjam akun — jejak audit
    | yang bohong jauh lebih buruk daripada satu peringatan yang tercatat.
    | Naikkan ke true begitu ada dua orang yang berwenang mengesahkan.
    |
    */
    'pemisahan_wewenang_memblokir' => (bool) env('PEMISAHAN_WEWENANG_MEMBLOKIR', false),

];

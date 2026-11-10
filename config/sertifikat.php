<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kertas berkop PRACETAK (G0-b P1)
    |--------------------------------------------------------------------------
    |
    | `false` (bawaan): sertifikat BARU tercetak seperti biasa — gambar kop /
    | logo KAN, judul "CALIBRATION CERTIFICATE", dan kode dokumen di kaki.
    | Snapshot baru TIDAK membekukan `tata_letak.kop_pracetak`.
    |
    | `true`: snapshot baru membekukan `kop_pracetak` (`FormatMasterSertifikat::
    | ruangPracetakCm`, dibatasi `RUANG_PRACETAK_MAKS_CM`): kop, judul, dan kode
    | dokumen tidak dicetak dan diganti ruang kosong setinggi margin atas master,
    | seperti lembar `SERTIFIKAT` master yang dicetak di atas kertas berkop.
    |
    | Kenapa bawaannya mati: sertifikat digital sampai ke pelanggan lewat
    | aplikasi/QR sebagai PDF. Tanpa kop/logo KAN/judul, PDF-nya kehilangan
    | identitas lab. Ruang kosong yang dipotong ke 4,5 cm juga bisa menimpa kop
    | kertas pracetak fisik bila kop itu lebih tinggi, dan jenis kertas fisiknya
    | belum dipastikan pemilik. Nyalakan hanya sesudah itu jelas.
    |
    | Hanya memengaruhi sertifikat yang terbit SESUDAH nilainya berubah: snapshot
    | lama beku dan tidak ikut berubah. Bagian G0-b lain (tanggal per lembar,
    | Capacity/Graduation, tanda minus nol) tidak punya sakelar.
    |
    | Bagian G0-b lain yang tidak punya sakelar: Issuance Date seperti rumus master
    | (`tanggal kalibrasi + N`) selalu DIBATASI hari penerbitan sebenarnya —
    | sertifikat tidak pernah mengaku terbit sesudah hari ia diterbitkan
    | (`CertificateSnapshotBuilder::tanggalTerbitMaster`). Turbidimeter ditahan
    | dari G0-b (tanggal terbit sistem, tanda minus nol tetap) sampai pemilik
    | memastikan.
    |
    | Nilai yang bukan boolean diperlakukan `false` (`filter_var`), supaya salah
    | ketik di `.env` tidak diam-diam menghapus kop dari sertifikat terakreditasi.
    |
    */

    'kop_pracetak' => filter_var(env('SERTIFIKAT_KOP_PRACETAK', false), FILTER_VALIDATE_BOOLEAN),

];

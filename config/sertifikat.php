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

    /*
    |--------------------------------------------------------------------------
    | Gerbang pra-approve "muat satu halaman" (PK-6)
    |--------------------------------------------------------------------------
    |
    | `true` (bawaan): `approve()` API dan tombol Setujui panel merender sertifikat
    | sementara (`App\Services\PraPeriksaSatuHalaman`) dan MENOLAK sesi yang
    | terbukti tidak akan terbit satu halaman — sebelum status berubah dan sebelum
    | nomor sertifikat terpakai. Ongkosnya SATU render dompdf di dalam request
    | approve (jalur terbit merender lagi di job). Di server kecil itu bisa terasa;
    | sakelar ini ada supaya gerbangnya bisa dimatikan tanpa mengubah kode bila
    | latensi approve ternyata terlalu mahal. Mati = perilaku sebelum PK-6:
    | sesi yang meluap disetujui lalu sertifikatnya `gagal` di job.
    |
    | HANYA `false`/`0`/`off`/`no` yang mematikannya. Nilai lain — kosong, salah
    | ketik, `maybe` — membiarkan gerbang nyala: kebalikan dari `kop_pracetak`
    | di atas, karena yang dijaga di sini penjagaan, bukan fitur opsional, dan
    | salah ketik tidak boleh diam-diam melepasnya. (`env()` sudah mengubah teks
    | `false` menjadi boolean `false`, makanya dicek `=== false` lebih dulu.)
    |
    */

    'pra_approve_satu_halaman' => ! (
        env('SERTIFIKAT_PRA_APPROVE_SATU_HALAMAN', true) === false
        || in_array(strtolower(trim((string) env('SERTIFIKAT_PRA_APPROVE_SATU_HALAMAN', true))), ['0', 'off', 'no'], true)
    ),

];

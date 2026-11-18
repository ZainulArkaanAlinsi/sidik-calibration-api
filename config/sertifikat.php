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
    | `false` (BAWAAN, opt-in): `approve()` berperilaku persis seperti sebelum PK-6 —
    | tidak ada render tambahan. Sesi yang tak muat satu halaman disetujui lalu
    | sertifikatnya `gagal` di job (nomor terpakai); itu pintu kedua yang tetap ada.
    |
    | `true`: `approve()` API dan tombol Setujui panel merender sertifikat sementara
    | (`App\Services\PraPeriksaSatuHalaman`) dan MENOLAK sesi yang terbukti tidak akan
    | terbit satu halaman — sebelum status berubah dan sebelum nomor sertifikat
    | terpakai. Ongkosnya SATU render dompdf di dalam request approve (jalur terbit
    | merender lagi di job): lokal median 2 s, maks 2,5 s, sesi tak-muat sampai 17 s;
    | di server produksi (0,1 CPU, 512 MB bersama worker, klien mobile menunggu
    | approve 60 s) BELUM TERUKUR. Karena itu mati dulu: ukur DUA approve di
    | produksi (satu sesi yang muat DAN satu yang tidak muat: kasus terburuk, seluruh
    | tangga render; timeout PHP itu fatal dan tidak ditolong fail-open), baru nyalakan.
    |
    | Menyalakan = pemilik menyetel env `SERTIFIKAT_PRA_APPROVE_SATU_HALAMAN=true`
    | di Render. HANYA `true`/`1`/`on`/`yes` yang menyalakannya (tanpa spasi di sekitar
    | nilai); kosong, salah ketik, atau `maybe` tetap mati (bawaan aman = perilaku
    | lama). Kunci ini sengaja belum
    | didaftarkan di `render.yaml`/`.env.example`.
    |
    */

    'pra_approve_satu_halaman' => in_array(
        strtolower(trim((string) env('SERTIFIKAT_PRA_APPROVE_SATU_HALAMAN', false))),
        ['1', 'on', 'yes'],
        true,
    ),

    /*
    |--------------------------------------------------------------------------
    | Jenis alat yang PENERBITAN sertifikatnya ditahan (keputusan pemilik 18 Nov 2026)
    |--------------------------------------------------------------------------
    |
    | Satu-satunya daftar. Sertifikat yang salah di lab terakreditasi lebih
    | berbahaya daripada sertifikat yang tertunda, jadi jenis alat di bawah ini
    | TIDAK bisa disetujui sampai Technical Manager (TM) menjawab butir yang
    | disebut (dokumen pertanyaan TM, Downloads\SIDIK-PERTANYAAN-UNTUK-TECHNICAL-
    | MANAGER.md). Yang ditahan CUMA penerbitan: lembar tetap bisa diisi,
    | dipratinjau, dan dihitung, dan angkanya tidak diubah sedikit pun.
    |
    | Kunci = `CalibrationProfile::kode()` (BUKAN nama alat). Salah ketik kode
    | berarti penahanan tidak pernah menyala dan tidak ada galat apa pun —
    | `ProfilDitahanTest` menolak kode yang tidak terdaftar di registry.
    |
    | Isi tiap entri:
    |   alasan  teks singkat yang dibaca Master Data (muncul di 422 `profil_ditahan`,
    |           layar aplikasi, dan jejak audit). Tulis angkanya, bukan "ada masalah".
    |   tm      rujukan butir di dokumen pertanyaan TM (TM-1 ...).
    |   varian  (opsional) hanya sesi bervarian ini yang ditahan; tanpa kunci ini
    |           seluruh jenis alat ditahan. Sekarang cuma Timbangan: varian KG saja
    |           (`VarianMasterTimbangan`), gram dan substitusi tidak.
    |
    | Satu hook untuk semuanya: `CalibrationProfile::penahanTerbit()`. Kelas dasarnya
    | membaca daftar ini; Tekanan (`pressure_gauge`, `vacuum_gauge`,
    | `differential_pressure`, TM-3) dan Piston sudah memakai hook yang sama untuk
    | penahanan dari LOG METODE (`database/data/log-metode-tekanan-piston.json`) dan
    | SENGAJA tidak ditulis ulang di sini: penahanan Tekanan bersyarat (hanya sesi
    | yang memicu cacat master P-1/P-2/P-11; sesi SPMK tidak ditahan, keputusan
    | pemilik 28 Sep 2026) dan lepasnya lewat jawaban TM di log, bukan lewat daftar
    | ini. Menulis `pressure_gauge` di sini akan menahan SEMUA varian termasuk SPMK.
    | Tegaknya di tiga pintu yang memanggil `App\Services\PenahananProfil`:
    | `approve()` API, tombol Setujui panel, dan job penerbitan; ketujuh jenis alat
    | (empat di sini + tiga Tekanan) lewat pintu yang sama.
    |
    | MELEPAS = hapus satu entri di sini lewat PR kecil (jangan lewat env Render:
    | angka sertifikat tidak boleh bergantung pada setelan dashboard). Sesi yang
    | sudah `disetujui` dan sertifikat yang sudah `terbit` tidak berubah; lihat
    | `docs/tahan-penerbitan-profil.md`.
    |
    */

    'profil_ditahan' => [

        'timbangan' => [
            'varian' => ['kg'],
            'tm' => 'TM-1',
            'alasan' => 'Timbangan kg: U95 bagian 7 (Weighing) tercetak 0,033 kg, sedangkan master 0,043 sampai 0,053 kg '
                .'(sampai 37% lebih kecil dan tidak konservatif). Paraf TM untuk butir T2 belum ada.',
        ],

        'proving_ring' => [
            'tm' => 'TM-4',
            'alasan' => 'Proving Ring: U95 tercetak 2,3 kgf (pita 0 sampai 500 kgf lampiran LK-285-IDN), sedangkan master 22,4 kgf '
                .'(pita 10 sampai 88 kN), sekitar 10 kali lebih besar daripada sertifikat yang sudah pernah terbit. '
                .'Pita CMC mana yang benar (G14) belum diputuskan.',
        ],

        'bath' => [
            'tm' => 'TM-8',
            'alasan' => 'Bath: aturan master baru (radiasi dan pembebanan nol) belum masuk, dan tanpa data standar enclosure 2026 '
                .'U95 tercetak di bawah master pada 7 dari 16 baris Yokogawa/Constant. Boleh terbit dengan data standar lama '
                .'dan SP6 memakai M151 atau M180 belum dijawab TM.',
        ],

        'picnometer' => [
            'tm' => 'TM-7',
            'alasan' => 'Picnometer: angka cetaknya belum pernah diadu dengan master (profilnya masih memakai Fixed non-Rev.7), '
                .'dan empat pertanyaan PK-3 belum dijawab TM: angka mati 25,5 C di sel V20, U95 termometer 0,058 atau 0,72, '
                .'template Rev.7 atau 2026, serta Picnometer Fixed atau Graduated.',
        ],

    ],

];

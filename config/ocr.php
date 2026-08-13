<?php

/*
 * Pipeline OCR LOKAL (template-based) buat lembar kerja yang difoto teknisi.
 *
 * Beda tajam sama `services.vision` yang lama: di sini NGGAK ada panggilan ke
 * layanan AI mana pun. Fotonya dibaca di HP (CameraX + OpenCV + OCR on-device),
 * yang nyampe ke server cuma teks per sel + geometri + skor kualitas. Server
 * yang megang artinya: normalisasi angka, validasi, pemetaan ke sel lembar
 * kerja, dan jejak auditnya.
 *
 * Alasan angka-angka ambang ada DI SINI, bukan di HP: satu lembar kerja bisa
 * dipindai dari lima versi APK yang beredar bareng. Kalau ambangnya ikut APK,
 * dua teknisi bisa dapat vonis beda buat foto yang sama, dan nggak ada test di
 * repo ini yang bisa jaga itu.
 *
 * Semua nilai di bawah PERKIRAAN AWAL. Wajib dikalibrasi ulang dari dataset
 * foto asli sebelum dipakai produksi — lihat docs/SPEC-ocr-template-lokal.md §7.
 * Tiap kali angkanya berubah, naikin `aturan_versi`: itu yang bikin hasil scan
 * lama masih bisa dijelasin waktu ditelusuri.
 */

return [

    /*
     * Versi pipeline & aturan, dicatat di TIAP scan.
     *
     * Dipisah karena umurnya beda: `pipeline` berubah kalau cara bacanya
     * berubah (preprocessing/model di HP), `aturan` berubah kalau ambang di
     * file ini yang digeser. Waktu akurasi turun, yang pertama ditanya adalah
     * "yang berubah yang mana" — dan satu nomor gabungan nggak bisa jawab itu.
     */
    'pipeline_versi' => env('OCR_PIPELINE_VERSI', 'ocr-1.0.0'),
    'aturan_versi' => env('OCR_ATURAN_VERSI', 'val-1.0.0'),

    /*
     * Gerbang kualitas foto. Foto yang nggak lolos DITOLAK sebelum satu sel pun
     * dipetakan — bukan diterima dengan confidence rendah.
     *
     * Alasannya: sel merah yang banyak keliatan kayak "OCR-nya jelek", dan
     * teknisi bakal ngoreksi 60 sel satu-satu daripada motret ulang sekali.
     * Nolak di depan lebih murah buat semua orang.
     */
    'kualitas' => [
        // Varians Laplacian — ukuran ketajaman. Di bawah ini = blur.
        'blur_min' => (float) env('OCR_BLUR_MIN', 90),
        // Kecerahan rata-rata (0–255). Terlalu gelap & terlalu terang sama-sama
        // bikin digit tulisan tangan ilang.
        'kecerahan_min' => (float) env('OCR_KECERAHAN_MIN', 60),
        'kecerahan_maks' => (float) env('OCR_KECERAHAN_MAKS', 225),
        // Rasio piksel yang kebakar cahaya (pantulan lampu/plastik map).
        'glare_maks' => (float) env('OCR_GLARE_MAKS', 0.05),
        // Sisa kemiringan SESUDAH perspective correction. Kalau masih miring
        // segini, homography-nya patut dicurigai.
        'kemiringan_maks_deg' => (float) env('OCR_KEMIRINGAN_MAKS', 8),
        // Tinggi satu sel dalam piksel. Di bawah ini angkanya nggak cukup
        // piksel buat dibedain — 6 vs 8, 3 vs 9.
        'px_per_sel_min' => (int) env('OCR_PX_PER_SEL_MIN', 24),
    ],

    /*
     * Gerbang geometri. Ini penjaga utama "angka nggak boleh masuk sel lain".
     */
    'geometri' => [
        // Sisa error waktu 4 marker diproyeksikan balik pakai homography yang
        // kepilih. Kecil = pemetaan kotak sel bisa dipercaya. Besar = seluruh
        // grid bisa geser satu baris tanpa keliatan.
        'residual_maks_px' => (float) env('OCR_RESIDUAL_MAKS_PX', 2.0),
        // Marker minimum yang harus kebaca dari 4. Tiga masih bisa nentuin
        // homography; dua nggak.
        'marker_min' => (int) env('OCR_MARKER_MIN', 3),
        // Kotak teks hasil OCR harus duduk di dalam poligon selnya, dengan
        // margin aman segini (rasio terhadap ukuran sel). Angka yang meluber
        // dari sel tetangga ketangkep di sini.
        'margin_dalam_sel' => (float) env('OCR_MARGIN_DALAM_SEL', 0.08),
    ],

    /*
     * Ambang status sel. Di ATAS `hijau` = boleh keisi otomatis; di antara
     * `kuning` dan `hijau` = keisi tapi wajib dilihat; di bawah `kuning` =
     * merah, teknisi yang ngetik.
     */
    'ambang' => [
        'hijau' => (float) env('OCR_AMBANG_HIJAU', 0.85),
        'kuning' => (float) env('OCR_AMBANG_KUNING', 0.60),
    ],

    /*
     * Potongan skor. Tiap koreksi yang dilakukan mesin itu tebakan — kecil,
     * masuk akal, tapi tetap tebakan. Yang ditebak harus lebih gampang jatuh ke
     * kuning daripada yang dibaca apa adanya.
     */
    'penalti' => [
        // Per karakter yang disubstitusi (O→0, S→5, ...).
        'substitusi' => (float) env('OCR_PENALTI_SUBSTITUSI', 0.15),
        // Lebih dari sekian substitusi dalam satu sel = langsung merah. Sel yang
        // butuh tiga tebakan buat jadi angka itu bukan angka yang kebaca.
        'maks_substitusi' => (int) env('OCR_MAKS_SUBSTITUSI', 2),
        // Foto lolos gerbang tapi pas-pasan → semua sel turun skornya.
        'kualitas_pas_pasan' => (float) env('OCR_PENALTI_KUALITAS', 0.10),
    ],

    /*
     * TULISAN TANGAN.
     *
     * Lembar kerja lab ini diisi tangan (lihat komentar `services.gemini.model`
     * — angka tulisan tangan yang dibaca di situ masuk sertifikat resmi). OCR
     * on-device dilatih buat teks CETAK; digit tulisan tangan akurasinya jauh
     * di bawah itu.
     *
     * Jadi: sel tulisan tangan NGGAK PERNAH hijau. Setinggi apa pun skornya, dia
     * mentok kuning — keisi otomatis, tapi mata teknisi tetap harus lewat. Yang
     * dihemat tetap besar (ngetik 60 sel jadi ngecek 60 sel), yang nggak dijual
     * cuma janji palsunya.
     */
    'tulisan_tangan' => [
        'aktif' => (bool) env('OCR_TULISAN_TANGAN', true),
        'status_maks' => 'kuning',
    ],

    /*
     * Aturan angka bawaan. Bisa ditimpa per template lewat file geometri.
     */
    'angka' => [
        // Karakter yang boleh muncul di sel angka. Selain ini = sel merah.
        'whitelist' => '0123456789,.-',
        // Sebanyak-banyaknya digit (tanpa koma/minus) dalam satu sel. Lembar
        // kerja lab nggak punya angka sepanjang itu; yang lebih panjang hampir
        // pasti dua sel kebaca jadi satu.
        'maks_digit' => (int) env('OCR_MAKS_DIGIT', 9),
        /*
         * Pita rentang pembacaan di sekitar nilai nominal titiknya.
         *
         * Dipakai yang paling longgar dari dua: kelipatan resolusi (buat titik
         * kecil, mis. pH 4,00 res 0,01) atau persentase (buat titik besar, mis.
         * 1000 NTU). Satu angka aja nggak muat dua-duanya — persentase doang
         * bikin pH 4,00 cuma boleh ±0,2 padahal alat rusak bisa baca 4,6;
         * kelipatan resolusi doang bikin 1000 NTU cuma boleh ±10.
         */
        'pita_kelipatan_resolusi' => (float) env('OCR_PITA_RESOLUSI', 20),
        'pita_persen' => (float) env('OCR_PITA_PERSEN', 0.10),
        /*
         * Rasio nilai terhadap nominal. Ini yang nangkep KOMA KEGESER —
         * kesalahan OCR paling berbahaya, karena hasilnya angka yang bentuknya
         * sah: 1,33659 kebaca 133659, atau 4,01 kebaca 40,1.
         *
         * Sengaja longgar (setengah sampai dua kali): alat rusak berat pun masih
         * kebaca di rentang ini, sementara koma kegeser selalu meleset 10x.
         */
        'rasio_min' => (float) env('OCR_RASIO_MIN', 0.5),
        'rasio_maks' => (float) env('OCR_RASIO_MAKS', 2.0),
    ],

    /*
     * Suhu larutan (kolom °C di tiap tabel hasil).
     *
     * Batasnya batas RUANGAN KERJA, bukan batas sensor — sama semangatnya kayak
     * `CalibrationValidator::KELEMBABAN_MIN/MAKS`. Di luar ini hampir pasti
     * salah baca, bukan kondisi lapangan.
     */
    'suhu' => [
        'min' => (float) env('OCR_SUHU_MIN', 5),
        'maks' => (float) env('OCR_SUHU_MAKS', 45),
        'desimal' => (int) env('OCR_SUHU_DESIMAL', 1),
        'resolusi' => (float) env('OCR_SUHU_RESOLUSI', 0.1),
        // Selisih suhu antar-Repeat dalam satu titik. Larutan nggak lompat
        // sebanyak ini dalam hitungan menit.
        'delta_wajar' => (float) env('OCR_SUHU_DELTA', 2.0),
    ],

    /*
     * Kewajaran antar-Repeat: nilai yang jauh dari saudara-saudaranya ditandai
     * kuning, BUKAN dibuang. Anomali asli itu data — yang boleh dilakukan mesin
     * cuma nunjuk, bukan mutusin.
     */
    'antar_repeat' => [
        // Minimal sekian Repeat kebaca sebelum uji ini berarti. Di bawah ini
        // ujinya dilewat dan dicatat `tidak_diuji` — bukan diam-diam lulus.
        'min_sampel' => (int) env('OCR_REPEAT_MIN_SAMPEL', 3),
        // Ambang sebar: maks(k1 × resolusi, k2 × MAD) dari median Repeat lain.
        'k_resolusi' => (float) env('OCR_REPEAT_K_RESOLUSI', 3.0),
        'k_mad' => (float) env('OCR_REPEAT_K_MAD', 4.0),
    ],

    /*
     * Penyimpanan citra buat audit & dataset regresi.
     *
     * PERINGATAN: disk `local` di Render plan `free` itu EPHEMERAL — hilang tiap
     * deploy. Buat produksi setel `OCR_DISK` ke object storage (S3/R2/MinIO
     * milik sendiri). Fotonya data pelanggan, jadi jangan ke layanan pihak
     * ketiga yang nggak dikontrol lab.
     */
    'penyimpanan' => [
        'disk' => env('OCR_DISK', 'local'),
        'folder' => env('OCR_FOLDER', 'worksheet-scans'),
        // Umur citra sebelum boleh dibersihin. Crop per sel disimpan lebih lama:
        // dia yang jadi bahan dataset regresi, ukurannya kecil, dan nggak
        // nampilin identitas pelanggan.
        'umur_citra_hari' => (int) env('OCR_UMUR_CITRA_HARI', 90),
        'umur_crop_hari' => (int) env('OCR_UMUR_CROP_HARI', 365),
    ],

    /*
     * Folder definisi geometri template (koordinat sel di kertas). Satu file per
     * template per versi: `{template_id}-v{versi}.json`.
     *
     * Sengaja file, bukan tabel DB: koordinatnya ikut revisi FORMULIR, dan
     * formulirnya dokumen mutu yang berubahnya lewat persetujuan — bukan data
     * yang diedit orang lewat panel. Di file, dia ikut git, ikut code review,
     * dan ikut test.
     */
    'folder_template' => env('OCR_FOLDER_TEMPLATE', 'ocr-templates'),

];

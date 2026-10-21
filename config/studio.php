<?php

/*
 * Sidik Studio — setelan sisi server (docs/sidik-studio/03-SDD.md).
 *
 * Lewat config, bukan `env()` langsung: entrypoint produksi menjalankan
 * `config:cache`, dan sesudah itu `.env` tidak dibaca lagi (alasan lengkap di
 * config/pelanggan.php).
 */

return [

    /*
     * ST-T-013 / FR-STU-003. Request ber-header `X-Klien: sidik-studio` dengan
     * `X-Versi-Klien` di bawah angka ini dijawab 426 `klien_kedaluwarsa`.
     *
     * Bawaan "0.0.0" = tidak pernah menolak. Dinaikkan hanya bila kontrak API
     * berubah dengan cara yang membuat Studio lama salah membaca. Request tanpa
     * header (aplikasi lapangan) tidak pernah terkena.
     */
    'versi_minimum' => (string) env('STUDIO_VERSI_MINIMUM', '0.0.0'),

    /*
     * K-50-12 (15 Okt 2026) — Studio dipakai SATU orang: email akun itu di sini
     * (disimpan huruf kecil). Tidak ada peran/tim Studio.
     *
     * KOSONG = Studio tertutup total (login Studio dan semua rute `/api/studio/*`
     * dijawab 403), bukan terbuka. Diganti = token Studio akun lama mati di
     * request berikutnya (email diadu ulang di server tiap request, lihat
     * `PastikanAkunStudio`).
     */
    'akun' => strtolower(trim((string) env('STUDIO_AKUN', ''))),

    /*
     * Disk penyimpanan berkas asli workbook (+ gambar lembarnya). `arsip` =
     * disk awet yang sama dengan PDF sertifikat: R2 (`ARSIP_DRIVER=s3`) di
     * produksi. Disk container Render terhapus tiap deploy, dan tanpa berkas
     * asli `/hitung` tidak punya apa-apa untuk dihitung ulang. Baris lama
     * membaca dari kolom `berkas_disk`-nya sendiri, jadi mengganti nilai ini
     * tidak memutus baris yang sudah ada.
     */
    'disk_berkas' => (string) env('STUDIO_DISK_BERKAS', 'arsip'),

    /*
     * Peta sel per profil (rencana studio-kendali-olah-data, 22 Okt 2026):
     * `<direktori>/<kode_profil>.json`. Master yang profilnya punya peta
     * divalidasi strukturnya saat impor (ditolak bila berubah) dan
     * diekstrak jadi draf `paket_acuan_versi`. SENGAJA tanpa env(): sumber
     * kebenarannya berkas di repo, bukan setelan dashboard.
     */
    'peta_sel' => [
        'direktori' => database_path('data/peta-sel'),
    ],

    /*
     * `POST /api/studio/workbook/impor` (unggah master lewat API; Render tanpa
     * shell). Pagar: ukuran berkas, memori & waktu request web (impor .xlsm
     * nyata ±0,3-1 GB RAM di CLI — di web dipatok supaya ledakan jadi PHP
     * Fatal satu request, bukan OOM-kill container), dan jatah per menit.
     */
    'impor' => [
        'maks_kb' => 30720,
        'memori' => (string) env('STUDIO_IMPOR_MEMORI', '448M'),
        'batas_detik' => 120,
    ],

    /*
     * Akar temp privat untuk impor `.xlsm` terenkripsi (S1b, ADR-49-10 revisi).
     *
     * Tiap impor membuat subfolder acak di sini, mendekripsi paketnya ke situ,
     * lalu menghapus subfolder itu di `finally` sesudah reader dilepas. Sisa
     * dari proses yang mati di tengah jalan disapu `studio:sapu-temp-workbook`
     * (terjadwal tiap jam, juga dipanggil di awal tiap impor).
     *
     * Sengaja di dalam storage/app/private (tidak pernah disajikan web), bukan
     * temp sistem yang dibagi dengan proses lain.
     */
    'temp_workbook' => storage_path('app/private/tmp-workbook'),

    /* Umur (menit) sisa temp workbook yang dianggap yatim oleh penyapu. */
    'umur_sapu_temp_menit' => 60,

    /*
     * Lembar Hitung interaktif "persis Excel" (tahap C, `POST
     * /api/studio/workbook/{workbook}/hitung`, App\Services\Studio\Hitung).
     *
     *  - batas_detik: batas keras satu hitung (kooperatif, diperiksa per sel)
     *    → 504 `hitung_terlalu_lama`.
     *  - maks_edit / maks_panjang_rumus: pagar request (422).
     *  - memori (env STUDIO_HITUNG_MEMORI): memory_limit request hitung —
     *    pagar KERAS, harus di bawah RAM container supaya request yang meledak
     *    jadi PHP Fatal satu request, bukan OOM-kill container (tinjau C-3 #1).
     *    Diukur 15 Okt 2026 pada 4 workbook AT nyata (7.741 sel model, 2.683
     *    rumus; proses PHP 8.4 termasuk boot Laravel 42 MB): puncak 62–66 MB
     *    untuk 19 skenario sunting + mode penuh (semua rumus), 78 MB bila
     *    model hitung harus dibangun ulang dari berkas asli (cache kosong);
     *    lewat HTTP (lembar snapshot + jawaban) lihat laporan tahap ini.
     *    Render free = 512 MB untuk SELURUH container (FrankenPHP + queue:work
     *    + schedule:work), jadi 384M: ±5× puncak terukur, tetap di bawah RAM.
     *  - ambang_memori: pagar LUNAK (`PagarMemori`) — di atas rasio ini dari
     *    memory_limit, evaluasi diputus → 422 `hitung_terlalu_berat`
     *    (diperiksa per sel, per baca rentang, per unsur fungsi teks).
     *  - maks_larik_teks_rumus_edit: pagar rumus EDIT (`PagarRumus`, 422
     *    `rumus_terlalu_berat`) untuk operasi teks per unsur (`&`, LEFT/MID/
     *    UPPER/REPT/…) × larik terbesar: tiap unsur bisa 32.767 huruf ≈ 36 KB
     *    (terukur: 10.000 unsur = +366 MB), dibangun PhpSpreadsheet dalam SATU
     *    operasi yang tidak bisa diputus pagar lunak. 4.000 ≈ 146 MB terburuk
     *    — di bawah ambang lunak 307 MB dikurangi puncak terukur. Workbook AT
     *    tidak memakai `&` maupun fungsi teks sama sekali (dipindai 15 Okt).
     *  - zona_waktu: jam dinding NOW()/TODAY() bila klien tidak mengirim
     *    `waktu_acuan` (lab di WIB; Excel memakai jam lokal komputernya).
     *  - umur_cache_hari: model hitung tersimpan (`storage/app/private/
     *    studio-hitung`) yang tidak dipakai selama ini disapu
     *    `studio:sapu-temp-workbook`.
     *  - maks_sel_rumus_edit / maks_pasangan_rumus_edit (tinjau C #1): pagar
     *    rumus EDIT sebelum dievaluasi → 422 `rumus_terlalu_berat`
     *    (`PagarRumus`). `sel` = jumlah sel yang dirujuk (rentang + nama;
     *    kolom/baris penuh dipotong ke area terpakai lembar) — biaya linear;
     *    `pasangan` = hasil kali dua rentang terbesar — biaya kuadratik fungsi
     *    larik/pencarian & siaran larik, yang TIDAK bisa diputus batas waktu
     *    di tengah sel. Diukur 15 Okt 2026 di 4 workbook AT (2.682–2.684
     *    rumus): rumus terberat 2.010 sel (27 VLOOKUP ke tabel bernama),
     *    pasangan terbesar 33.124 (182×182), lembar terpanjang 345 baris.
     *    Biaya kuadratik terukur: MATCH/VLOOKUP larik 2.000×2.000 = 61 s /
     *    103 s dalam SATU sel, siaran `A1:A2000*TRANSPOSE(B1:B2000)` 25 s.
     *    Batas 10 rb sel (≈5× rumus terberat, ±0,5 s baca) dan 250 rb
     *    pasangan (≈7× pasangan terbesar, paling buruk ±4–7 s per sel).
     *  - maks_sel_rentang: pagar mesin untuk SEMUA rumus (termasuk rumus asli
     *    dengan INDIRECT/OFFSET): rentang lebih besar tidak dirinci, sel itu
     *    masuk `galat` (nilai cache Excel). 100 rb ≈ 9× lembar AT terbesar
     *    (10.695 sel); merinci 100 rb alamat ±2–5 s, ±50 MB. Tanpa pagar ini
     *    `A1:XFD1048576` = 17 miliar alamat → PHP Fatal memori.
     */
    'hitung' => [
        'batas_detik' => 20.0,
        'maks_edit' => 300,
        'maks_panjang_rumus' => 8192,
        'maks_sel_rumus_edit' => 10000,
        'maks_pasangan_rumus_edit' => 250000,
        'maks_sel_rentang' => 100000,
        'maks_larik_teks_rumus_edit' => 4000,
        'memori' => (string) env('STUDIO_HITUNG_MEMORI', '384M'),
        'ambang_memori' => 0.8,
        'zona_waktu' => 'Asia/Jakarta',
        'umur_cache_hari' => 30,
    ],

];

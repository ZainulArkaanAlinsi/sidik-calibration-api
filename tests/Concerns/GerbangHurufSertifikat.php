<?php

namespace Tests\Concerns;

use App\Support\FormatMasterSertifikat;

/**
 * Aturan gerbang ukuran huruf sertifikat: rasio huruf app terhadap median huruf
 * lembar `SERTIFIKAT` master, per profil, dengan ratchet DUA ARAH.
 *
 * ## Kenapa ini ada
 *
 * 13 Nov 2026 baris "Minimum Apertures" (B2, PR #251) membuat Sieve Mesh jatuh dari
 * tingkat `longgar -1` (7,9 pt = 0,93 x master) ke `longgar -2` (7,1 pt = 0,83 x),
 * dan TIDAK ADA tes yang merah: gerbang satu halaman cuma menghitung halaman, dan
 * `FONT_MEDIAN_PT` hanya memagari profil yang tercantum di sana. Huruf yang
 * mengecil diam-diam tidak menghasilkan error apa pun — persis kelas kegagalan
 * yang repo ini tulis di §Aturan yang Lahir dari Kesalahan Nyata.
 *
 * ## Yang dijaga (tiap profil terdaftar, bukan cuma yang di `FONT_MEDIAN_PT`)
 *
 * 1. Rasio dalam [AMBANG_BAWAH, AMBANG_ATAS] — kecuali profil di `pengecualian`
 *    yang wajib punya alasan tertulis (Thermohygro: master dicetak skala 50 %).
 * 2. Rasio SAMA dengan baseline (toleransi pembulatan):
 *    - TURUN  -> gagal: huruf mengecil.
 *    - NAIK   -> gagal juga, dengan pesan "perbarui baseline". Baseline yang
 *      tertinggal di bawah kenyataan adalah ruang kosong yang bisa dipakai
 *      perubahan lain untuk menyelipkan penurunan.
 * 3. Baseline, daftar profil terdaftar, dan profil yang terukur identik — profil
 *    yang dikeluarkan dari salah satunya gagal, bukan dilewati.
 *
 * 4. Tiap SESI dikunci sendiri (`sesi` di baseline), bukan hanya yang terburuk per
 *    profil: sesi lain di profil bersesi banyak tidak bisa mengecil diam-diam
 *    selama masih di atas yang terburuk.
 *
 * Baseline TIDAK bisa dipakai untuk menerima rasio di luar [0,90 ; 1,10]: itu
 * cuma bisa lewat `pengecualian` beralasan, yang terlihat di diff.
 *
 * Ukuran huruf app diukur `PengukurHurufCanvas` (median per karakter bukan-spasi,
 * pembulatan sama dengan yang ditulis dompdf ke PDF); ukuran master dari
 * `master_pt` (ekstraksi audit 11-13 Nov 2026), BUKAN dari `FONT_MEDIAN_PT` —
 * supaya mengubah `FONT_MEDIAN_PT` tidak bisa menggeser tolok ukurnya sendiri.
 *
 * ## Tolok ukur master tidak bisa disunting diam-diam
 *
 * `master_pt` ADA di dua tempat: baseline (untuk dibaca) dan `master-pt.json`
 * (sumber: median huruf PDF master + lebar halamannya). Tanpa pengikat, huruf
 * yang sungguh mengecil bisa disembunyikan dengan menyunting `master_pt`,
 * `app_pt`, dan `rasio` bersama (mutan Jangka Sorong 5,7/5,4/0,95 lolos 25/25).
 * `periksaMasterPt` mengikat baseline ke fixture dan fixture ke rumusnya;
 * `GerbangHurufSertifikatTest` mengikat fixture ke hash sha256 yang ditulis di
 * tes itu — mengubah satu `master_pt` jadi keputusan sadar yang terlihat di
 * tiga berkas.
 */
final class GerbangHurufSertifikat
{
    /** Audit: 0,90 - 1,10 x master = SEPADAN. */
    public const AMBANG_BAWAH = 0.90;

    public const AMBANG_ATAS = 1.10;

    /** Rasio dibulatkan dua desimal (sama dengan audit); selisih di bawah ini = sama. */
    public const TOLERANSI = 0.004;

    /** Selisih terbesar `master_pt` baseline terhadap `FONT_MEDIAN_PT` (pt). */
    public const TOLERANSI_FONT_MEDIAN_PT = 0.05;

    public const BERKAS = 'tests/Fixtures/sertifikat-huruf/baseline-huruf-master.json';

    /** Ekstraksi master: median huruf + lebar halaman PDF master per profil (sumber `master_pt`). */
    public const BERKAS_MASTER_PT = 'tests/Fixtures/sertifikat-huruf/master-pt.json';

    /** Lebar A4 (pt) tempat median huruf master diskala. */
    public const LEBAR_A4_PT = 595.3;

    public const PERINTAH_TULIS = 'SIDIK_TULIS_BASELINE=1 php artisan test --filter=SertifikatSemuaAlatSatuHalamanTest';

    /** @return array<string, mixed> */
    public static function baseline(): array
    {
        $isi = file_get_contents(base_path(self::BERKAS));

        return json_decode((string) $isi, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public static function masterPt(): array
    {
        $isi = file_get_contents(base_path(self::BERKAS_MASTER_PT));

        return json_decode((string) $isi, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Ikat `master_pt` baseline ke ekstraksi masternya: (1) tiap profil ada di
     * kedua berkas, (2) `master_pt` fixture = median x 595,3 / lebar halaman
     * (dua desimal), (3) `master_pt` baseline = fixture.
     *
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $fixture
     * @return list<string>
     */
    public static function periksaMasterPt(array $baseline, array $fixture): array
    {
        $masalah = [];
        $profil = (array) ($baseline['profil'] ?? []);
        $acuan = (array) ($fixture['profil'] ?? []);

        foreach (array_diff(array_keys($profil), array_keys($acuan)) as $kode) {
            $masalah[] = "{$kode}: master_pt baseline tidak punya sumber di master-pt.json.";
        }
        foreach (array_diff(array_keys($acuan), array_keys($profil)) as $kode) {
            $masalah[] = "{$kode}: ada di master-pt.json tetapi tidak di baseline huruf.";
        }

        foreach ($acuan as $kode => $butir) {
            $median = (float) ($butir['median_pdf_pt'] ?? 0);
            $lebar = (float) ($butir['lebar_halaman_pt'] ?? 0);

            if ($median <= 0 || $lebar <= 0) {
                $masalah[] = "{$kode}: median_pdf_pt dan lebar_halaman_pt di master-pt.json harus angka positif.";

                continue;
            }

            $hitung = round($median * self::LEBAR_A4_PT / $lebar, 2);

            if (abs($hitung - (float) ($butir['master_pt'] ?? 0)) > 0.0049) {
                $masalah[] = "{$kode}: master_pt {$butir['master_pt']} di master-pt.json tidak sama dengan median {$median} x "
                    .self::LEBAR_A4_PT." / lebar {$lebar} = {$hitung}.";
            }

            $dasar = $profil[$kode]['master_pt'] ?? null;

            if ($dasar !== null && abs((float) $dasar - (float) ($butir['master_pt'] ?? 0)) > 0.0049) {
                $masalah[] = "{$kode}: master_pt baseline {$dasar} berbeda dari master-pt.json {$butir['master_pt']} "
                    .'— tolok ukur master tidak boleh disunting di baseline saja.';
            }
        }

        return $masalah;
    }

    public static function rasio(float $appPt, float $masterPt): float
    {
        return round($appPt / $masterPt, 2);
    }

    /**
     * Periksa hasil ukur lawan baseline.
     *
     * @param  array<string, array{app_pt: float, sesi: string}>  $terukur  kode profil => ukuran TERBURUK antar sesinya
     * @param  list<string>  $terdaftar  kode profil di registry
     * @param  array<string, mixed>  $baseline  isi berkas baseline
     * @param  array<string, list<array{app_pt: float, sesi: string}>>  $perSesi  SEMUA sesi terukur per profil; kosong = tidak memeriksa per sesi
     * @return list<string> pelanggaran, kosong = lolos
     */
    public static function periksa(array $terukur, array $terdaftar, array $baseline, array $perSesi = []): array
    {
        $masalah = [];
        $profil = (array) ($baseline['profil'] ?? []);
        $pengecualian = (array) ($baseline['pengecualian'] ?? []);

        sort($terdaftar);

        foreach (array_diff($terdaftar, array_keys($profil)) as $kode) {
            $masalah[] = "{$kode}: terdaftar di registry tetapi TIDAK ada di baseline huruf. Tambahkan entrinya "
                .'(master_pt dari ekstraksi lembar SERTIFIKAT master), jangan dilewati.';
        }
        foreach (array_diff(array_keys($profil), $terdaftar) as $kode) {
            $masalah[] = "{$kode}: ada di baseline huruf tetapi tidak terdaftar di registry. Hapus entrinya bila profilnya memang dicabut.";
        }
        foreach (array_diff($terdaftar, array_keys($terukur)) as $kode) {
            $masalah[] = "{$kode}: terdaftar tetapi TIDAK terukur — tidak ada sertifikat terbit/terbangun untuk profil ini di sapuan. "
                .'Profil yang dikeluarkan dari gerbang berarti hurufnya bisa mengecil tanpa ketahuan.';
        }

        foreach ($pengecualian as $kode => $butir) {
            if (! is_array($butir) || mb_strlen(trim((string) ($butir['alasan'] ?? ''))) < 20) {
                $masalah[] = "{$kode}: pengecualian huruf wajib punya `alasan` tertulis (minimal 20 karakter).";
            }
            if (! array_key_exists($kode, $profil)) {
                $masalah[] = "{$kode}: pengecualian huruf menunjuk profil yang tidak ada di baseline.";
            }
        }

        foreach ($terdaftar as $kode) {
            $entri = $profil[$kode] ?? null;
            $ukur = $terukur[$kode] ?? null;

            if (! is_array($entri) || $ukur === null) {
                continue; // sudah dilaporkan di atas
            }

            $master = (float) ($entri['master_pt'] ?? 0);

            if ($master <= 0) {
                $masalah[] = "{$kode}: master_pt di baseline harus angka positif.";

                continue;
            }

            $rasio = self::rasio((float) $ukur['app_pt'], $master);
            $tanda = "{$kode}: huruf {$ukur['app_pt']} pt / master {$master} pt = {$rasio} (sesi {$ukur['sesi']})";

            if (! array_key_exists($kode, $pengecualian)) {
                if ($rasio < self::AMBANG_BAWAH - self::TOLERANSI) {
                    $masalah[] = "{$tanda} MENYUSUT di bawah ".self::AMBANG_BAWAH.' x master. Kembalikan tata letak/ukuran huruf profil ini; '
                        .'baseline tidak boleh dipakai untuk menerima nilai di bawah ambang.';
                } elseif ($rasio > self::AMBANG_ATAS + self::TOLERANSI) {
                    $masalah[] = "{$tanda} MEMBENGKAK di atas ".self::AMBANG_ATAS.' x master. Bila disengaja, catat sebagai `pengecualian` beralasan di baseline.';
                }
            }

            $dasar = $entri['rasio'] ?? null;

            if (! is_numeric($dasar)) {
                $masalah[] = "{$tanda}: BELUM dibekukan di baseline. Jalankan `".self::PERINTAH_TULIS.'` lalu periksa diff berkasnya.';

                continue;
            }

            if ($rasio < (float) $dasar - self::TOLERANSI) {
                $masalah[] = "{$tanda} TURUN dari baseline {$dasar}: huruf sertifikat profil ini mengecil. Cari perubahan tata letak yang menambah tinggi lembar "
                    .'(baris/blok baru, margin, tinggi ruang TTD); jangan menurunkan baseline.';
            } elseif ($rasio > (float) $dasar + self::TOLERANSI) {
                $masalah[] = "{$tanda} NAIK di atas baseline {$dasar} tanpa baseline baru. Perbaikan itu bagus — kunci dengan `".self::PERINTAH_TULIS.'` '
                    .'dan sebut kenaikannya di deskripsi PR.';
            }

            if ($perSesi !== []) {
                array_push($masalah, ...self::periksaPerSesi($kode, $entri, $master, $perSesi[$kode] ?? []));
            }
        }

        return $masalah;
    }

    /**
     * Ratchet per sesi: tiap sesi profil ini dibandingkan dengan sesinya sendiri di baseline.
     *
     * @param  array<string, mixed>  $entri
     * @param  list<array{app_pt: float, sesi: string}>  $daftar
     * @return list<string>
     */
    private static function periksaPerSesi(string $kode, array $entri, float $master, array $daftar): array
    {
        $ukur = [];

        foreach ($daftar as $baris) {
            $ukur[(string) $baris['sesi']] = (float) $baris['app_pt'];
        }

        $dasar = $entri['sesi'] ?? null;

        if (! is_array($dasar)) {
            return ["{$kode}: sesi-sesinya BELUM dibekukan per sesi di baseline. Jalankan `".self::PERINTAH_TULIS.'` lalu periksa diff berkasnya.'];
        }

        $masalah = [];

        foreach (array_diff(array_map('strval', array_keys($dasar)), array_map('strval', array_keys($ukur))) as $nomor) {
            $masalah[] = "{$kode}: sesi {$nomor} ada di baseline tetapi TIDAK terukur lagi.";
        }
        foreach (array_diff(array_map('strval', array_keys($ukur)), array_map('strval', array_keys($dasar))) as $nomor) {
            $masalah[] = "{$kode}: sesi {$nomor} terukur tetapi BELUM dibekukan di baseline (`".self::PERINTAH_TULIS.'`).';
        }

        foreach ($ukur as $nomor => $pt) {
            if (! array_key_exists($nomor, $dasar)) {
                continue;
            }

            $rasio = self::rasio($pt, $master);
            $rasioDasar = self::rasio((float) $dasar[$nomor], $master);
            $tanda = "{$kode}: sesi {$nomor} huruf {$pt} pt / master {$master} pt = {$rasio}";

            if ($rasio < $rasioDasar - self::TOLERANSI) {
                $masalah[] = "{$tanda} TURUN dari baseline sesi ini {$rasioDasar}: huruf sertifikat sesi ini mengecil walau sesi terburuk profilnya belum.";
            } elseif ($rasio > $rasioDasar + self::TOLERANSI) {
                $masalah[] = "{$tanda} NAIK di atas baseline sesi ini {$rasioDasar} tanpa baseline baru; kunci dengan `".self::PERINTAH_TULIS.'`.';
            }
        }

        return $masalah;
    }

    /**
     * `FONT_MEDIAN_PT` dan `master_pt` baseline menyebut master yang SAMA, jadi
     * tidak boleh berselisih jauh. Gerbang memakai `master_pt` (bukan
     * `FONT_MEDIAN_PT`) sebagai tolok ukur supaya mengubah `FONT_MEDIAN_PT`
     * tidak bisa menggeser tolok ukurnya sendiri; tes ini yang mengikat keduanya.
     *
     * @param  array<string, mixed>  $baseline
     * @param  array<string, float>  $fontMedian
     * @return list<string>
     */
    public static function selarasDenganFontMedian(array $baseline, array $fontMedian = FormatMasterSertifikat::FONT_MEDIAN_PT): array
    {
        $masalah = [];
        $profil = (array) ($baseline['profil'] ?? []);

        foreach ($fontMedian as $kode => $pt) {
            $master = $profil[$kode]['master_pt'] ?? null;

            if ($master === null) {
                $masalah[] = "{$kode}: ada di FONT_MEDIAN_PT tetapi tidak di baseline huruf.";
            } elseif (abs((float) $master - $pt) > self::TOLERANSI_FONT_MEDIAN_PT) {
                $masalah[] = "{$kode}: master_pt baseline {$master} berbeda dari FONT_MEDIAN_PT {$pt} lebih dari "
                    .self::TOLERANSI_FONT_MEDIAN_PT.' pt — dua angka itu menyebut master yang SAMA.';
            }
        }

        return $masalah;
    }

    /**
     * Hasil ukur terburuk per profil: ukuran huruf app TERKECIL antar sesinya.
     *
     * @param  array<string, list<array{app_pt: float, sesi: string}>>  $perSesi
     * @return array<string, array{app_pt: float, sesi: string}>
     */
    public static function terburuk(array $perSesi): array
    {
        $hasil = [];

        foreach ($perSesi as $kode => $daftar) {
            usort($daftar, fn (array $a, array $b): int => [$a['app_pt'], $a['sesi']] <=> [$b['app_pt'], $b['sesi']]);
            $hasil[$kode] = $daftar[0];
        }

        ksort($hasil);

        return $hasil;
    }

    /**
     * Tulis rasio terukur ke baseline (mode `SIDIK_TULIS_BASELINE=1`).
     * MENOLAK bila ada profil yang rasionya turun/keluar ambang atau belum punya
     * entri — yang boleh dibekukan cuma yang sama atau membaik, plus entri yang
     * belum dibekukan.
     *
     * @param  array<string, array{app_pt: float, sesi: string}>  $terukur
     * @param  list<string>  $terdaftar
     * @param  string|null  $berkas  tujuan tulis; bawaan berkas baseline repo
     * @param  array<string, list<array{app_pt: float, sesi: string}>>  $perSesi  SEMUA sesi terukur per profil; kosong = `sesi` tidak disentuh
     * @return list<string> alasan penolakan; kosong = berkas ditulis
     */
    public static function tulis(array $terukur, array $terdaftar, array $baseline, ?string $berkas = null, array $perSesi = []): array
    {
        $profil = (array) ($baseline['profil'] ?? []);
        $pengecualian = (array) ($baseline['pengecualian'] ?? []);
        $tolak = [];

        foreach ($terdaftar as $kode) {
            if (! isset($profil[$kode])) {
                $tolak[] = "{$kode}: belum punya entri (master_pt harus ditulis tangan dari ekstraksi master).";
            } elseif (! isset($terukur[$kode])) {
                $tolak[] = "{$kode}: tidak terukur.";
            }
        }

        foreach ($terukur as $kode => $ukur) {
            $entri = $profil[$kode] ?? null;

            if (! is_array($entri) || ! in_array($kode, $terdaftar, true)) {
                continue;
            }

            $rasio = self::rasio((float) $ukur['app_pt'], (float) $entri['master_pt']);

            if (! array_key_exists($kode, $pengecualian) && ($rasio < self::AMBANG_BAWAH - self::TOLERANSI || $rasio > self::AMBANG_ATAS + self::TOLERANSI)) {
                $tolak[] = "{$kode}: rasio {$rasio} di luar ".self::AMBANG_BAWAH.'-'.self::AMBANG_ATAS.' dan tanpa pengecualian beralasan.';
            }
            if (is_numeric($entri['rasio'] ?? null) && $rasio < (float) $entri['rasio'] - self::TOLERANSI) {
                $tolak[] = "{$kode}: rasio {$rasio} TURUN dari baseline {$entri['rasio']} — memperbaiki hurufnya, bukan menurunkan baseline.";
            }
        }

        foreach ($perSesi as $kode => $daftar) {
            $entri = $profil[$kode] ?? null;

            if (! is_array($entri)) {
                continue;
            }

            $dasar = is_array($entri['sesi'] ?? null) ? $entri['sesi'] : [];
            $ukur = [];

            foreach ($daftar as $baris) {
                $ukur[(string) $baris['sesi']] = (float) $baris['app_pt'];
            }

            foreach (array_diff(array_map('strval', array_keys($dasar)), array_map('strval', array_keys($ukur))) as $nomor) {
                $tolak[] = "{$kode}: sesi {$nomor} ada di baseline tetapi tidak terukur — hapus dengan sadar dari berkas bila sesinya memang dicabut.";
            }

            foreach ($ukur as $nomor => $pt) {
                if (array_key_exists($nomor, $dasar)
                    && self::rasio($pt, (float) $entri['master_pt']) < self::rasio((float) $dasar[$nomor], (float) $entri['master_pt']) - self::TOLERANSI) {
                    $tolak[] = "{$kode}: sesi {$nomor} TURUN dari baseline sesinya — memperbaiki hurufnya, bukan menurunkan baseline.";
                }
            }
        }

        if ($tolak !== []) {
            return $tolak;
        }

        foreach ($terukur as $kode => $ukur) {
            if (! isset($profil[$kode])) {
                continue;
            }

            $profil[$kode]['app_pt'] = (float) $ukur['app_pt'];
            $profil[$kode]['rasio'] = self::rasio((float) $ukur['app_pt'], (float) $profil[$kode]['master_pt']);
        }

        foreach ($perSesi as $kode => $daftar) {
            if (! isset($profil[$kode])) {
                continue;
            }

            $sesi = [];

            foreach ($daftar as $baris) {
                $sesi[(string) $baris['sesi']] = (float) $baris['app_pt'];
            }

            ksort($sesi);
            $profil[$kode]['sesi'] = $sesi;
        }

        ksort($profil);
        $baseline['profil'] = $profil;

        file_put_contents(
            $berkas ?? base_path(self::BERKAS),
            json_encode($baseline, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n",
        );

        return [];
    }
}

<?php

namespace App\Support;

/**
 * Catatan "angka ini berbeda dari master" yang terbaca di jejak sesi.
 *
 * ## Kenapa kelas ini ada
 *
 * AGENTS.md §Olah data butir 4: penyimpangan dari master WAJIB punya catatan
 * audit di `type_b_components` yang menyebut SUMBER (sel master, nomor
 * pertanyaan lab, keputusan pemilik), nilai master lawan nilai sistem, dan
 * arahnya — supaya orang yang menyetujui sesi bisa tahu "angka ini berbeda dari
 * master, berapa bedanya, atas dasar apa" tanpa membuka kode. Komentar di kode
 * tidak sampai ke penyetuju; baris jejak sampai.
 *
 * Pola baris sudah ada di tiap profil (`pengulangan_standar_dibagi_n`,
 * `hydrometer_*`, `peta_kolom_master_rusak`). Yang disatukan di sini cuma
 * BENTUKNYA, supaya kunci, susunan kalimat, dan istilah arahnya seragam di
 * belasan profil dan bisa ditegakkan satu test.
 *
 * ## Yang kelas ini TIDAK boleh lakukan
 *
 * Tidak menyentuh satu angka hitung pun. Ia cuma merakit baris tambahan
 * (`u_baku`, `ci`, `vi` = 0 / tidak ada, sehingga tak pernah ikut agregasi
 * budget) dari nilai yang SUDAH dihitung profil. Pembanding "nilai master"
 * yang dihitung ulang (mis. U95 Weighing bila perlakuan master dipakai) dihitung
 * profil lewat `GumCalculator` pada salinan komponen dan hanya DICETAK di sini —
 * tidak pernah dipakai balik.
 *
 * ## Dua bentuk
 *
 * - [baris]: profil yang `type_b_components`-nya DAFTAR baris
 *   (`sumber`/`keterangan`/`distribusi`/`nilai`) — Timbangan, Micrometer,
 *   Flowmeter, Timer, dst. Kunci stabilnya `sumber` =
 *   `penyimpangan_master.<profil>.<butir>`.
 * - [teks]: profil yang `type_b_components`-nya PETA bernama dengan kunci
 *   `penyimpangan_master` (Gaya, Load Cell, UTM, Proving Ring). Kunci stabilnya
 *   `<butir>` di dalam peta itu.
 */
final class PenyimpanganMaster
{
    /** Awalan `sumber` baris jejak — satu-satunya tanda untuk pembaca dan test. */
    public const AWALAN = 'penyimpangan_master.';

    /** Sistem LEBIH BESAR/aman dari master (U lebih besar, jarak lebih longgar). */
    public const ARAH_KONSERVATIF = 'konservatif';

    /** Sistem LEBIH KECIL dari master: U mengecil, klaim lebih berani. */
    public const ARAH_TIDAK_KONSERVATIF = 'tidak_konservatif';

    /** Tidak menggeser angka yang dicetak/dilaporkan (tertutup lantai CMC, dsb). */
    public const ARAH_NETRAL = 'netral';

    /** Bisa ke dua arah tergantung sesi, atau belum diukur. */
    public const ARAH_TIDAK_TENTU = 'tidak_tentu';

    private const LABEL_ARAH = [
        self::ARAH_KONSERVATIF => 'KONSERVATIF (angka sistem lebih besar/aman dari master)',
        self::ARAH_TIDAK_KONSERVATIF => 'TIDAK konservatif (angka sistem lebih kecil dari master)',
        self::ARAH_NETRAL => 'tidak menggeser angka yang dicetak',
        self::ARAH_TIDAK_TENTU => 'ke dua arah, tergantung data sesi',
    ];

    /**
     * Kunci stabil baris: `penyimpangan_master.<profil>.<butir>`.
     */
    public static function kode(string $profil, string $butir): string
    {
        return self::AWALAN.$profil.'.'.$butir;
    }

    /**
     * Satu baris untuk `type_b_components` berbentuk daftar.
     *
     * @param  string  $ringkas  satu-dua kalimat: apa yang beda dan kenapa
     * @param  string  $sumber  sel master / nomor pertanyaan lab / keputusan pemilik
     * @param  string  $nilaiMaster  nilai (atau perlakuan) master, siap tampil
     * @param  string  $nilaiSistem  nilai (atau perlakuan) sistem, siap tampil
     * @param  float|null  $nilai  angka sistem yang relevan (kolom `nilai` baris jejak)
     * @param  array<string, mixed>  $angka  angka pembanding mentah (master/sistem) untuk
     *                                       pembaca mesin dan test; teks tetap di `keterangan`
     * @return array<string, mixed>
     */
    public static function baris(
        string $profil,
        string $butir,
        string $ringkas,
        string $sumber,
        string $nilaiMaster,
        string $nilaiSistem,
        string $arah,
        ?float $nilai = null,
        array $angka = [],
    ): array {
        return [
            'sumber' => self::kode($profil, $butir),
            'keterangan' => self::teks($ringkas, $sumber, $nilaiMaster, $nilaiSistem, $arah),
            'distribusi' => '-',
            'nilai' => $nilai,
            // Bukan komponen budget: tak pernah ikut uc/veff, dan pembaca yang
            // menyapu `ci`/`u_baku` melihat nol.
            'u_baku' => 0.0,
            'ci' => 0.0,
            // Bentuk terstruktur untuk pembaca mesin dan test. Teks `keterangan`
            // tetap satu-satunya yang ditampilkan ke penyetuju.
            'penyimpangan' => [
                'profil' => $profil,
                'butir' => $butir,
                'sumber' => $sumber,
                'master' => $nilaiMaster,
                'sistem' => $nilaiSistem,
                'arah' => $arah,
                'angka' => $angka,
            ],
        ];
    }

    /**
     * Kalimat catatan yang SAMA untuk kedua bentuk.
     */
    public static function teks(
        string $ringkas,
        string $sumber,
        string $nilaiMaster,
        string $nilaiSistem,
        string $arah,
    ): string {
        return sprintf(
            'PENYIMPANGAN DARI MASTER. %s. Sumber: %s. Master: %s. Sistem: %s. Arah: %s.',
            rtrim($ringkas, ". \t\n"),
            rtrim($sumber, ". \t\n"),
            rtrim($nilaiMaster, ". \t\n"),
            rtrim($nilaiSistem, ". \t\n"),
            self::LABEL_ARAH[$arah] ?? $arah,
        );
    }

    /**
     * Baris penyimpangan dalam satu `type_b_components` berbentuk daftar —
     * untuk test dan pembaca yang ingin menyaringnya.
     *
     * @return list<array<string, mixed>>
     */
    public static function dari(mixed $jejak): array
    {
        if (! is_array($jejak)) {
            return [];
        }

        $hasil = [];

        foreach ($jejak as $baris) {
            if (is_array($baris) && str_starts_with((string) ($baris['sumber'] ?? ''), self::AWALAN)) {
                $hasil[] = $baris;
            }
        }

        return $hasil;
    }

    /**
     * Angka siap-tampil gaya Indonesia (koma desimal), 6 angka penting, tanpa
     * nol sisa. Pembulatan HANYA di sini — untuk teks catatan, bukan untuk
     * nilai yang disimpan.
     */
    public static function angka(float $x): string
    {
        if ($x === 0.0) {
            return '0';
        }

        return str_replace('.', ',', sprintf('%.6g', $x));
    }
}

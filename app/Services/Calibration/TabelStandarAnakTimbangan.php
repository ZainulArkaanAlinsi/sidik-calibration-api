<?php

namespace App\Services\Calibration;

use RuntimeException;

/**
 * Tabel keping standar, neraca, densitas, dan MPE untuk lembar **Anak
 * Timbangan** (OIML R111), dibaca dari
 * `database/data/tabel-standar-anak-timbangan.json`.
 *
 * Berkas itu **digenerate** `docs/skrip/gen-tabel-standar-anak-timbangan.py`
 * dari `1.1 Anak Timbangan F1 1mg-500 g 202501022 imp.xlsx`. Jangan disunting
 * tangan — generatornya menurunkan ulang tiap besaran turunan dan menolak
 * menulis kalau ada yang menyimpang, dan suntingan tangan melewati penjagaan
 * itu diam-diam.
 *
 * ## Empat penjagaan yang ada di kelas ini, dan kenapa
 *
 * **1. `null` = "tidak ada", bukan nol.** Tiap `VLOOKUP` master dibungkus
 * `IFERROR(…;"tdk ada di tabel")`, dan teks itu masuk `1/ρ` lalu meledak jadi
 * `#VALUE!` yang **tercetak di sertifikat pelanggan** — lima dari dua puluh
 * baris di sesi contoh. Pemanggil WAJIB memblokir titiknya dengan alasan yang
 * kebaca, bukan menganggapnya nol.
 *
 * **2. Nominal ber-bintang tidak pernah menang.** `0.02*`, `0.2*`, `2*`, `20*`,
 * `200*` adalah keping KEDUA bernominal sama. Pencarian exact master selalu
 * mendarat di baris pertama, jadi yang ber-bintang tidak pernah terpilih —
 * ditiru di [cariKeping]. Keduanya tetap tersimpan supaya jejaknya tidak
 * hilang, dan [kepingKembar] menyebutkan mana saja yang punya kembaran supaya
 * profil bisa mewajibkan `no_identitas`. Pertanyaan lab §8.
 *
 * **3. Densitas DISENGKETAKAN.** Tabelnya memuat nilai yang mustahil untuk anak
 * timbangan — 10650 kg/m³ itu densitas timbal, 14400 kg/m³ tidak dimiliki logam
 * mana pun yang dipakai anak timbangan — dan kolom E2/F1-nya tertukar antara
 * 0,1 g dan 0,2 g. Disalin apa adanya supaya sesi lama bisa dihitung ulang jadi
 * angka yang sama dengan kertas yang menerbitkannya, tapi
 * [densitasDisengketakan] balik `true` supaya profil menaikkan peringatan sesi
 * selama lab belum menjawab. Pertanyaan lab §6.
 *
 * **4. Nominal ≥ 100 g memakai baris 100 g.** Bukan tebakan: masternya sendiri
 * menulis `Note : Diatas 100 g nilainya =` di atas baris itu, dan sesi contoh
 * membuktikannya — kedua keping 200 g memungut densitas 8060/8010 milik baris
 * 100 g. Nominal di ATAS 100 g yang jatuh ke baris lain akan menggeser koreksi
 * apungnya tanpa satu pun error.
 */
class TabelStandarAnakTimbangan
{
    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * Keping standar untuk sebuah nominal (gram), atau `null` kalau tidak ada.
     *
     * Baris PERTAMA yang cocok menang — lihat penjagaan 2 di docblock kelas.
     *
     * @return array{kelas: string, nominal_teks: string, nominal_g: float, konvensional_g: float, u_mg: float, drift_mg: float}|null
     */
    public static function cariKeping(float $nominalG): ?array
    {
        foreach (self::data()['standar_at']['keping'] as $baris) {
            if (self::samaAngka((float) $baris['nominal_g'], $nominalG)) {
                return [
                    'kelas' => (string) $baris['kelas'],
                    'nominal_teks' => (string) $baris['nominal_teks'],
                    'nominal_g' => (float) $baris['nominal_g'],
                    'konvensional_g' => (float) $baris['konvensional_g'],
                    'u_mg' => (float) $baris['u_mg'],
                    'drift_mg' => (float) $baris['drift_mg'],
                ];
            }
        }

        return null;
    }

    /**
     * Nominal (gram) yang punya lebih dari satu keping fisik di tabel standar.
     *
     * Dipakai profil untuk mewajibkan `no_identitas`: dua baris sertifikat
     * bernominal sama tanpa penanda tidak bisa dipetakan pelanggan ke kepingnya.
     *
     * @return list<float>
     */
    public static function kepingKembar(): array
    {
        $hitung = [];

        foreach (self::data()['standar_at']['keping'] as $baris) {
            $kunci = (string) $baris['nominal_g'];
            $hitung[$kunci] = ($hitung[$kunci] ?? 0) + 1;
        }

        return array_values(array_map(
            'floatval',
            array_keys(array_filter($hitung, static fn (int $n): bool => $n > 1)),
        ));
    }

    /**
     * Densitas (kg/m³) satu nominal untuk satu kelas OIML, atau `null` kalau
     * tabelnya tidak memuatnya.
     *
     * `null` WAJIB memblokir titiknya — lihat penjagaan 1 di docblock kelas.
     */
    public static function densitas(float $nominalG, string $kelas): ?float
    {
        // Lihat penjagaan 4 di docblock kelas.
        $cari = $nominalG >= 100.0 ? 100.0 : $nominalG;

        foreach (self::data()['densitas']['baris'] as $baris) {
            if (! self::samaAngka((float) $baris['nominal_g'], $cari)) {
                continue;
            }

            $nilai = $baris[$kelas] ?? null;

            return $nilai === null ? null : (float) $nilai;
        }

        return null;
    }

    /** Tabel densitas belum disahkan lab — lihat penjagaan 3 di docblock kelas. */
    public static function densitasDisengketakan(): bool
    {
        return (self::data()['densitas']['status'] ?? '') === 'disengketakan';
    }

    /**
     * Batas kesalahan yang diizinkan (mg) untuk satu nominal & kelas, dari
     * OIML R111 Tabel 1, atau `null` kalau kelas itu tidak didefinisikan untuk
     * nominal tersebut.
     *
     * Master memuat tabel ini lengkap tapi sertifikatnya terbit **tanpa vonis
     * lulus/tidak lulus** sama sekali. Di sini dia dipakai untuk gerbang
     * penolakan `de` (lihat `AnakTimbanganCalculator`). Pertanyaan lab §3 & §10.
     */
    public static function mpe(float $nominalG, string $kelas): ?float
    {
        foreach (self::data()['mpe']['baris'] as $baris) {
            if (! self::samaAngka((float) $baris['nominal_g'], $nominalG)) {
                continue;
            }

            $nilai = $baris[$kelas] ?? null;

            return $nilai === null ? null : (float) $nilai;
        }

        return null;
    }

    /**
     * Neraca standar berdasarkan namanya, atau `null` kalau tidak terdaftar.
     *
     * `std_dev_mg` di sini nilai master apa adanya, dan master menurunkannya
     * dengan cara yang dipertanyakan: sel berlabel `Rata-rata STDev` sebenarnya
     * berisi SIMPANGAN BAKU dari keenam simpangan baku harian, bukan
     * rata-ratanya. Nilainya lebih kecil daripada keterulangan hari mana pun.
     * `gabungan_harian_mg` ikut dibawa supaya dampaknya bisa dihitung tanpa
     * menjalankan generator lagi — kalau lab menjawab "pakai gabungan harian",
     * U95 seluruh sertifikat naik sekitar 1,9x. Pertanyaan lab §1.
     *
     * @return array{nama: string, kapasitas_g: float, resolusi_g: float, std_dev_mg: float, gabungan_harian_mg: float, u_sens_mg: float, merk_tipe: string, no_seri: string, tertelusur: string}|null
     */
    public static function timbangan(string $nama): ?array
    {
        foreach (self::data()['timbangan'] as $baris) {
            if (self::samaTeks((string) $baris['nama'], $nama)) {
                return [
                    'nama' => (string) $baris['nama'],
                    'kapasitas_g' => (float) $baris['kapasitas_g'],
                    'resolusi_g' => (float) $baris['resolusi_g'],
                    'std_dev_mg' => (float) $baris['std_dev_mg'],
                    'gabungan_harian_mg' => (float) $baris['gabungan_harian_mg'],
                    'u_sens_mg' => (float) $baris['u_sens_mg'],
                    'merk_tipe' => (string) $baris['merk_tipe'],
                    'no_seri' => (string) $baris['no_seri'],
                    'tertelusur' => (string) $baris['tertelusur'],
                ];
            }
        }

        return null;
    }

    /**
     * Neraca TERKECIL yang masih sanggup memikul sebuah nominal, atau `null`
     * kalau tidak ada yang cukup.
     *
     * Dipakai profil untuk memberi tahu admin waktu neraca yang dipilih jauh
     * lebih kasar daripada yang tersedia. Di sesi contoh master, dua puluh
     * keping — termasuk yang 5 mg — ditimbang di Analytical Balance, padahal
     * Semi Micro Balance (keterulangan 24x lebih baik, kapasitas 80 g) ada di
     * daftar dan cukup untuk delapan belas di antaranya. Pertanyaan lab §12.
     *
     * @return array<string, mixed>|null
     */
    public static function timbanganTerbaikUntuk(float $nominalG): ?array
    {
        $cocok = null;

        foreach (self::data()['timbangan'] as $baris) {
            if ((float) $baris['kapasitas_g'] < $nominalG) {
                continue;
            }

            if ($cocok === null || (float) $baris['kapasitas_g'] < (float) $cocok['kapasitas_g']) {
                $cocok = $baris;
            }
        }

        return $cocok === null ? null : self::timbangan((string) $cocok['nama']);
    }

    /** @return list<array<string, mixed>> */
    public static function semuaTimbangan(): array
    {
        return array_values(self::data()['timbangan']);
    }

    /** @return list<array<string, mixed>> */
    public static function semuaKeping(): array
    {
        return array_values(self::data()['standar_at']['keping']);
    }

    /** @return list<array<string, mixed>> */
    public static function setStandar(): array
    {
        return array_values(self::data()['standar_at']['set']);
    }

    /**
     * Meter lingkungan (thermobarometer / termohigrometer) berdasarkan namanya.
     *
     * Cuma `Thermobarometer` yang tersedia. Meter kedua di master (`TH-7`)
     * SENGAJA tidak disalin: kolom koreksinya tidak rekonsiliasi dengan pasangan
     * indikasinya sendiri — offsetnya tetap (0,04 °C untuk suhu, 0,40 %RH untuk
     * kelembaban) dan meleset di empat dari lima baris. Menyalin tabel yang
     * tidak rekonsiliasi berarti menaruh angka yang belum terverifikasi di
     * server dengan tampang berwenang. Pertanyaan lab §19.
     *
     * @return array<string, mixed>|null
     */
    public static function meterLingkungan(string $nama): ?array
    {
        foreach (self::data()['meter_lingkungan'] as $baris) {
            if (self::samaTeks((string) $baris['nama'], $nama)) {
                return $baris;
            }
        }

        return null;
    }

    /**
     * Titik indeks TERDEKAT di tabel koreksi meter, beserta koreksinya.
     *
     * Aturan pemilihannya diverifikasi dari sesi contoh di ketiga besaran:
     * suhu 23,05 → 20,1; kelembaban 55,5 → 59,2; tekanan 933,15 → 931.
     *
     * **Seri memilih yang lebih RENDAH**, dan itu bukan pilihan kosmetik. Di
     * sesi contoh suhu 23,05 berjarak persis sama (2,95) dari 20,1 dan 26;
     * memilih yang lebih tinggi mengubah koreksinya dari +0,1 °C jadi +1,0 °C.
     * Selama koreksi belum dipakai densitas udara (pertanyaan lab §13) dampaknya
     * nol, tapi aturannya ditulis sekarang supaya tidak ditebak nanti.
     *
     * @return array{standar: float, instrumen: float, koreksi: float, u95: float}|null
     */
    public static function titikIndeks(string $namaMeter, string $besaran, float $nilai): ?array
    {
        $meter = self::meterLingkungan($namaMeter);
        $blok = is_array($meter) ? ($meter[$besaran] ?? null) : null;

        if (! is_array($blok) || ! is_array($blok['titik'] ?? null)) {
            return null;
        }

        $terdekat = null;
        $jarakTerdekat = null;

        foreach ($blok['titik'] as [$standar, $instrumen, $koreksi]) {
            $jarak = abs((float) $standar - $nilai);

            // `<` bukan `<=`: baris yang lebih awal (nilai lebih rendah di
            // ketiga tabel) menang waktu seri. Lihat docblock.
            if ($jarakTerdekat === null || $jarak < $jarakTerdekat) {
                $jarakTerdekat = $jarak;
                $terdekat = [
                    'standar' => (float) $standar,
                    'instrumen' => (float) $instrumen,
                    'koreksi' => (float) $koreksi,
                    'u95' => (float) $blok['u95'],
                ];
            }
        }

        return $terdekat;
    }

    /** @return array<string, mixed> */
    public static function konstanta(): array
    {
        return self::data()['konstanta'];
    }

    /** @return array<string, mixed> */
    public static function sumber(): array
    {
        return self::data()['sumber'];
    }

    /**
     * Dua nominal dianggap sama kalau selisihnya di bawah satu bagian per
     * sejuta. `VLOOKUP` Excel membandingkan double apa adanya; di PHP nominal
     * yang datang dari JSON request tidak selalu identik bit-nya dengan yang
     * tersimpan, dan perbandingan `===` menolaknya diam-diam.
     */
    private static function samaAngka(float $a, float $b): bool
    {
        return abs($a - $b) <= 1e-12 + 1e-6 * max(abs($a), abs($b));
    }

    private static function samaTeks(string $a, string $b): bool
    {
        $rapi = static fn (string $x): string => strtolower(
            trim((string) preg_replace('/\s+/u', ' ', $x)),
        );

        return $rapi($a) === $rapi($b);
    }

    /** @return array<string, mixed> */
    private static function data(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $berkas = database_path('data/tabel-standar-anak-timbangan.json');

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel standar Anak Timbangan nggak ketemu: {$berkas}");
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        if (! is_array($isi) || ! isset($isi['standar_at'], $isi['timbangan'], $isi['densitas'], $isi['mpe'])) {
            throw new RuntimeException("Tabel standar Anak Timbangan rusak: {$berkas}");
        }

        return self::$cache = $isi;
    }

    /** Buat test yang menukar isi berkas. */
    public static function lupakanCache(): void
    {
        self::$cache = null;
    }
}

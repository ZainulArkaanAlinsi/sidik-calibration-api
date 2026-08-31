<?php

namespace App\Services\Calibration;

use RuntimeException;

/**
 * Tabel anak timbangan standar, CMC, & drift untuk lembar **Timbangan**,
 * dibaca dari `database/data/tabel-standar-timbangan.json`.
 *
 * Diekstrak dari tiga workbook master ber-password yang turun dari lab
 * 31 Agt 2026 (sheet `STANDAR_AT` & `DATABASE`):
 *
 *   `New_Master_Olda_Timbangan_kg.xlsm`                   basis kg
 *   `New_Master_Olda_Timbangan_gram.xlsm`                 basis g
 *   `TERBARU_Master_Olda_Timbangan_Subtitusi_291025.xlsm` basis kg
 *
 * ## Tiga tabel, bukan satu — dan ini temuan, bukan kerapian
 *
 * Ketiga workbook memuat **snapshot sertifikat anak timbangan yang BERBEDA
 * untuk keping fisik yang sama**. Bukan cuma beda satuan:
 *
 *  - keping E2 100 g: `100,0004 g` di master kg, `100,000033 g` di master
 *    gram — selisih 0,37 mg, enam belas kali ketidakpastian keping itu sendiri;
 *  - SELURUH blok E2 master substitusi memakai angka lain lagi (kalibrasi
 *    ulang, berkasnya bertanggal 29 Okt 2025);
 *  - kolom ketidakpastian F1 0,1 g–500 g di master **kg** seribu kali lebih
 *    kecil daripada baris yang sama di master gram, sementara baris 1 kg ke
 *    atas di berkas yang sama cocok persis. Satu kolom, dua satuan.
 *
 * Ketiganya disimpan apa adanya dan dipilih per SESI lewat [varian], supaya
 * tiap sesi bisa dihitung ulang jadi angka yang sama dengan kertas yang
 * menerbitkannya. Memilih salah satu sebagai "yang benar" berarti diam-diam
 * menggeser angka yang sudah tercetak di sertifikat pelanggan — dan itu
 * keputusan manajer teknis lab, bukan keputusan kode. Pertanyaannya **T1** di
 * `docs/pertanyaan-lab-timbangan.md`.
 *
 * ## Dua tabel massa per varian, dan yang MENANG kalau nominalnya kembar
 *
 * Tiap varian punya dua blok: `e2` (`N5:R19`, anak timbangan kelas E2) dan
 * `f1` (`N20:R45`, kelas F1/F2/M2). Sembilan nominal muncul di KEDUANYA
 * dengan massa konvensional yang beda — dua keping fisik yang berbeda.
 *
 * Yang memilih blok itu **Tipe Timbangan**, bukan nominalnya: master gram
 * membaca `INPUT DATA!AC17` (`Analytical` → E2, `Non-Analytical` → F1),
 * sementara master kg & substitusi selalu menyebut `Tabel_F1`. Ditiru di
 * [cariMassa]. Kalau tertukar, timbangan analitik 20 g memakai massa
 * konvensional keping F1 20 g dan koreksinya meleset di digit yang justru
 * dilaporkan — tanpa satu pun error.
 *
 * ## Nominal ber-bintang (`0.2*`, `0.02*`, `2*`)
 *
 * Lab punya DUA keping bernominal sama (dipakai bareng, mis. 20 + 20 kg).
 * Di master bedanya cuma tanda bintang di kolom nominal, dan `VLOOKUP` exact
 * selalu mendarat di yang PERTAMA. Ditiru: [cariMassa] mengabaikan bintang dan
 * memulangkan baris pertama yang cocok. Keping kedua tetap tersimpan di
 * [semuaMassa] supaya jejaknya tidak hilang, tapi tidak pernah dipilih —
 * persis perilaku masternya.
 *
 * ## CMC: ketujuh belas pita SEMUANYA terakreditasi
 *
 * `DATABASE!R5:T21` memuat 17 pita A..Q sampai 2000 kg, dan ketujuh belasnya
 * ada di lampiran akreditasi LK-285-IDN no. 12 — termasuk sembilan pita di
 * atas 200 kg yang dipakai sesi bermetode substitusi. Dicocokkan baris demi
 * baris ke `database/data/kemampuan-kalibrasi.json` oleh
 * `TimbanganCmcCocokAkreditasiTest`, jadi pita yang digeser di salah satu
 * berkas langsung merah alih-alih diam.
 *
 * Yang benar-benar tidak punya lantai cuma kapasitas **di atas 2000 kg**:
 * [pitaCmc] memulangkan `null`, dan `TimbanganProfile::peringatanSesi()` wajib
 * menandainya. Tanpa itu U95 terbit rapi dan nomor sertifikat terbit rapi,
 * tanpa satu pun error yang memberi tahu pembacanya bahwa sesi itu di luar
 * ruang lingkup — bahaya yang bentuknya sama dengan §1 daftar permintaan.
 */
class TabelStandarTimbangan
{
    public const ANALYTICAL = 'Analytical';

    public const NON_ANALYTICAL = 'Non-Analytical';

    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /**
     * Baris massa untuk satu nominal, dibaca dari tabel milik `$varian` dan
     * dipulangkan dalam SATUAN BASIS varian itu.
     *
     * Balik `null` kalau nominalnya tidak ada di blok yang dipilih. Pemanggil
     * WAJIB memblokir titiknya, bukan menganggapnya nol: tiap `VLOOKUP` master
     * dibungkus `IFERROR(…,"")`, dan kosong ikut dijumlah sebagai nol — jadi
     * anak timbangan yang salah ketik nominalnya menghasilkan sertifikat
     * dengan massa standar yang HILANG, bukan error.
     *
     * Nominal ber-bintang (`0.2*`, `2*`) = keping KEDUA bernominal sama. Di
     * master bedanya cuma tanda bintang, dan `VLOOKUP` exact selalu mendarat
     * di yang pertama. Ditiru: bintang diabaikan, baris pertama yang menang.
     *
     * @return array{nominal: float, konvensional: float, koreksi: float, u: float, u_drift: float|null, basis: string}|null
     */
    public static function cariMassa(
        float $nominal,
        string $varian,
        string $tipeTimbangan = self::NON_ANALYTICAL,
    ): ?array {
        $tabel = self::varian($varian);
        $blok = self::samaTeks($tipeTimbangan, self::ANALYTICAL) ? 'e2' : 'f1';

        foreach ($tabel[$blok] as $baris) {
            if (self::samaAngka((float) $baris['nominal'], $nominal)) {
                return [
                    'nominal' => (float) $baris['nominal'],
                    'konvensional' => (float) $baris['konvensional'],
                    'koreksi' => (float) $baris['koreksi'],
                    'u' => (float) $baris['u'],
                    'u_drift' => $baris['u_drift'] === null ? null : (float) $baris['u_drift'],
                    'basis' => (string) $tabel['basis'],
                ];
            }
        }

        return null;
    }

    /**
     * Satuan basis tabel milik sebuah varian (`kg` atau `g`).
     *
     * Dipakai pemanggil buat memastikan sesi & tabelnya bicara satuan yang
     * sama. Master gram bekerja penuh dalam gram — nominal, massa konvensional,
     * DAN kolom drift — jadi tidak ada konversi di mana pun di sheet itu.
     * Menyamakan paksa ke kg berarti satu pembagian 1000 yang harus benar di
     * lima tempat sekaligus.
     */
    public static function basis(string $varian): string
    {
        return (string) self::varian($varian)['basis'];
    }

    /** @return array<string, mixed> */
    private static function varian(string $varian): array
    {
        $data = self::data();

        if (! isset($data['varian'][$varian])) {
            throw new RuntimeException(
                "Tabel standar Timbangan nggak punya varian '{$varian}'. Yang ada: "
                .implode(', ', array_keys($data['varian'])).'.',
            );
        }

        return $data['varian'][$varian];
    }

    private static function samaTeks(string $a, string $b): bool
    {
        $rapi = static fn (string $x): string => strtolower(
            (string) preg_replace('/[^a-z]/i', '', $x),
        );

        return $rapi($a) === $rapi($b);
    }

    /**
     * Pita CMC untuk sebuah KAPASITAS alat (kg), meniru rumus `INPUT DATA!E4`:
     * rantai `IF(kapasitas <= ambang, "A", …)` dengan ambang yang sengaja
     * dilebihkan seperseribu (1,201 / 2,001 / 12,001 …) supaya kapasitas yang
     * jatuh persis di batas ikut pita di BAWAHNYA.
     *
     * @return array{kode: string, rentang: string, cmc_gram: float}|null
     */
    public static function pitaCmc(float $kapasitasKg): ?array
    {
        foreach (self::data()['cmc'] as $pita) {
            if ($kapasitasKg <= (float) $pita['maks_kg']) {
                return [
                    'kode' => (string) $pita['kode'],
                    'rentang' => (string) $pita['rentang'],
                    'cmc_gram' => (float) $pita['cmc_gram'],
                ];
            }
        }

        return null;
    }

    /**
     * Ketidakpastian drift keluarga anak timbangan yang dipakai sesi, dipilih
     * dari nominal TERBESAR yang dipakai (`STANDAR_AT!N51:P55`, dibaca master
     * lewat `Index AT used`).
     *
     * @return array{maks_kg: float, standar: string, u_drift: float}|null
     */
    public static function driftKeluarga(float $nominalMaksKg): ?array
    {
        $cocok = null;

        foreach (self::data()['drift_keluarga'] as $baris) {
            if ($nominalMaksKg <= (float) $baris['maks_kg'] && $cocok === null) {
                $cocok = $baris;
            }
        }

        // Di atas 20 kg master tetap memakai baris terakhir (M2 20 kg) — anak
        // timbangan yang lebih besar memang tidak dimiliki lab.
        $cocok ??= self::data()['drift_keluarga'][count(self::data()['drift_keluarga']) - 1] ?? null;

        return $cocok === null ? null : [
            'maks_kg' => (float) $cocok['maks_kg'],
            'standar' => (string) $cocok['standar'],
            'u_drift' => (float) $cocok['u_drift'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function semuaMassa(string $varian): array
    {
        $t = self::varian($varian);

        return [...$t['f1'], ...$t['e2']];
    }

    /** @return list<string> */
    public static function varianTersedia(): array
    {
        return array_keys(self::data()['varian']);
    }

    /** @return list<array<string, mixed>> */
    public static function semuaPitaCmc(): array
    {
        return self::data()['cmc'];
    }

    /**
     * Dua nominal dianggap sama kalau selisihnya di bawah satu bagian per
     * sejuta. `VLOOKUP` Excel membandingkan double apa adanya; di PHP nominal
     * yang datang dari JSON request (`0.1 + 0.2`) tidak selalu identik bit-nya
     * dengan yang tersimpan, dan perbandingan `===` menolaknya diam-diam.
     */
    private static function samaAngka(float $a, float $b): bool
    {
        return abs($a - $b) <= 1e-9 + 1e-6 * max(abs($a), abs($b));
    }

    /** @return array<string, mixed> */
    private static function data(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $berkas = database_path('data/tabel-standar-timbangan.json');

        if (! is_file($berkas)) {
            throw new RuntimeException("Tabel standar Timbangan nggak ketemu: {$berkas}");
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        if (! is_array($isi) || ! isset($isi['varian'], $isi['cmc'])) {
            throw new RuntimeException("Tabel standar Timbangan rusak: {$berkas}");
        }

        return self::$cache = $isi;
    }

    /** Buat test yang menukar isi berkas. */
    public static function lupakanCache(): void
    {
        self::$cache = null;
    }
}

<?php

namespace App\Support;

use App\Models\RawMeasurement;
use Illuminate\Support\Collection;

/**
 * Susun ulang satu titik lembar **Jangka Sorong** dari baris `raw_measurements`,
 * plus blok tingkat-sesinya dari `spesifikasi_alat`.
 *
 * Saudaranya [HeightGaugeMentah], [MicrometerMentah], dan [AnakTimbanganMentah]
 * sudah menjelaskan pola besarnya: alat yang satu titiknya bukan satu deret
 * datar harus disusun ULANG tiap kali sesi tersimpan dihitung lagi, dan lupa
 * melakukannya tidak menghasilkan error — cuma `hitung_ulang_gagal` di tiap
 * titik. Kelas ini ditulis BERSAMAAN dengan profilnya.
 *
 * ## Tiga tabel, satu kosakata per tabel
 *
 *   peran_sensor           sensor_ke  titik_ke   arti
 *   js_outside_nominal     1..3       1..99      slot nominal Caliper Checker Outside, SELALU mm
 *   js_outside_pembacaan   1..10      1..99      X1, X1', …, X5, X5' — ikut satuan alat
 *   js_inside_nominal      1..3       101..199   slot nominal Caliper Checker Inside
 *   js_inside_pembacaan    1..5       101..199   X1..X5
 *   js_depth_nominal       1..3       201..299   tumpukan balok ukur
 *   js_depth_pembacaan     1..5       201..299   X1..X5
 *
 * Grupnya ditulis DI `peran_sensor`, bukan di `tahap`: kolom `tahap` itu enum
 * `sebelum/sesudah_adjustment`, dan menyimpan `outside` di sana ditolak
 * database. Rentang `titik_ke` yang terpisah per tabel menjaga satu `titik_ke`
 * tidak pernah berisi dua tabel — jalur hitung ulang mengelompokkan per
 * `titik_ke`, dan Outside 50 mm yang bercampur dengan Inside 50 mm
 * menghasilkan rata-rata dua sisi rahang yang tidak berarti apa-apa.
 *
 * ## NOL kolom baru
 *
 * Blok tingkat-SESI — kedua Evaluation, kesejajaran, kapasitas, resolusi,
 * kerataan muka ukur — hidup di `spesifikasi_alat.jangka_sorong`.
 */
class JangkaSorongMentah
{
    public const KUNCI_SESI = 'jangka_sorong';

    public const GRUP = ['outside', 'inside', 'depth'];

    /** Awal `titik_ke` tiap tabel. */
    public const OFFSET_TITIK = ['outside' => 0, 'inside' => 100, 'depth' => 200];

    /** Kunci konteks yang dibaca `JangkaSorongProfile::hitungPerGrup()`. */
    public const KONTEKS_GRUP = 'js_grup';

    public const KONTEKS_NOMINAL = 'js_nominal';

    public const KONTEKS_PEMBACAAN = 'js_pembacaan';

    /** Nominal SELALU mm — nilai sertifikat Caliper Checker / balok ukur. */
    public const SATUAN_NOMINAL = 'mm';

    /**
     * Faktor satuan alat ke mm. Master cuma menawarkan mm & inch
     * (`Satuan_Caliper`); µm tidak ada untuk jangka sorong.
     */
    public const FAKTOR_KE_MM = ['mm' => 1.0, 'inch' => 25.4];

    public static function peranNominal(string $grup): string
    {
        return "js_{$grup}_nominal";
    }

    public static function peranPembacaan(string $grup): string
    {
        return "js_{$grup}_pembacaan";
    }

    /** Grup pemilik satu `titik_ke`, atau `null` di luar ketiga rentang. */
    public static function grupDariTitik(int $titikKe): ?string
    {
        return match (true) {
            $titikKe >= 1 && $titikKe < 100 => 'outside',
            $titikKe > 100 && $titikKe < 200 => 'inside',
            $titikKe > 200 && $titikKe < 300 => 'depth',
            default => null,
        };
    }

    /**
     * Ubah satu penunjukan ke mm DI TEMPAT PAKAI — alasan tidak di ujung masuk
     * sama dengan [HeightGaugeMentah::keMm]: jalur draft tidak idempoten.
     * Satuan tak dikenal dibaca mm.
     */
    public static function keMm(mixed $nilai, ?string $satuan): float
    {
        return (float) $nilai * (self::FAKTOR_KE_MM[(string) $satuan] ?? 1.0);
    }

    /**
     * @param  Collection<int, RawMeasurement>  $baris  baris satu `titik_ke`
     * @return array{js_grup: string, js_nominal: list<float>, js_pembacaan: list<float>}|array{}
     */
    public static function dari(Collection $baris): array
    {
        foreach (self::GRUP as $grup) {
            $milikGrup = $baris->filter(static fn ($b): bool => in_array(
                (string) $b->peran_sensor,
                [self::peranNominal($grup), self::peranPembacaan($grup)],
                true,
            ));

            if ($milikGrup->isNotEmpty()) {
                return [
                    self::KONTEKS_GRUP => $grup,
                    self::KONTEKS_NOMINAL => self::deret($milikGrup, self::peranNominal($grup)),
                    self::KONTEKS_PEMBACAAN => self::deret($milikGrup, self::peranPembacaan($grup)),
                ];
            }
        }

        return [];
    }

    /**
     * Blok tingkat-SESI dari `spesifikasi_alat`, dinormalkan ke bentuk yang
     * diterima `JangkaSorongCalculator::hitungSesi()`.
     *
     * Menerima DUA bentuk untuk tabel tingkat-sesi: deret datar (seeder, test)
     * dan cerminan tabel HP (`{baris: [{pembacaan: [...]}]}`). Membaca keduanya
     * di sini — bukan cuma di `CalibrationRequest` — membuat jalur hitung ulang
     * tetap benar untuk sesi yang tersimpan sebelum perataan di request ada.
     *
     * Balik `null` kalau bloknya belum ada.
     *
     * @param  array<string, mixed>|null  $spesifikasiAlat
     * @return array{satuan: string, kapasitas_mm: float, resolusi_mm: float, pra_evaluasi_outside: list<float>, pra_evaluasi_inside: list<float>, kesejajaran: list<array{posisi: string, nominal: float, pembacaan: float}>, kerataan_muka_ukur: string|null}|null
     */
    public static function blokSesi(?array $spesifikasiAlat): ?array
    {
        $blok = is_array($spesifikasiAlat) ? ($spesifikasiAlat[self::KUNCI_SESI] ?? null) : null;

        if (! is_array($blok)) {
            return null;
        }

        $satuan = (string) ($blok['satuan'] ?? 'mm');
        $angka = static fn (mixed $x): float => is_numeric($x) ? (float) $x : 0.0;

        return [
            'satuan' => $satuan,
            'kapasitas_mm' => $angka($blok['kapasitas_mm'] ?? null),
            'resolusi_mm' => $angka($blok['resolusi_mm'] ?? null),
            // Dibaca pada jangka sorong itu sendiri — ikut satuan alat.
            'pra_evaluasi_outside' => array_map(
                static fn (float $v): float => self::keMm($v, $satuan),
                self::ratakan($blok['pra_evaluasi_outside'] ?? null),
            ),
            'pra_evaluasi_inside' => array_map(
                static fn (float $v): float => self::keMm($v, $satuan),
                self::ratakan($blok['pra_evaluasi_inside'] ?? null),
            ),
            'kesejajaran' => self::kesejajaran($blok['kesejajaran'] ?? null),
            'kerataan_muka_ukur' => self::kerataan($blok['kerataan_muka_ukur'] ?? null),
        ];
    }

    /**
     * Rata-rata suhu ruangan mentah — sumber suhu Caliper Checker & suhu UUT
     * (`PERHITUNGAN!V37 = W37 = G14`). Ujung kosong dilewati, bukan dibaca nol.
     */
    public static function rataSuhuRuang(mixed $awal, mixed $akhir): float
    {
        $terisi = array_values(array_filter([$awal, $akhir], static fn ($s): bool => is_numeric($s)));

        return $terisi === [] ? 0.0 : array_sum(array_map('floatval', $terisi)) / count($terisi);
    }

    /**
     * Deret angka dari deret datar ATAU cerminan tabel HP. Yang bukan angka
     * dilewati — nol yang ikut masuk menggelembungkan simpangan baku Evaluation.
     *
     * @return list<float>
     */
    private static function ratakan(mixed $nilai): array
    {
        if (! is_array($nilai)) {
            return [];
        }

        $mentah = isset($nilai['baris'])
            ? array_merge([], ...array_map(
                static fn ($b): array => array_values((array) (is_array($b) ? ($b['pembacaan'] ?? []) : [])),
                array_values((array) $nilai['baris']),
            ))
            : array_values($nilai);

        return array_values(array_map('floatval', array_filter($mentah, static fn ($v): bool => is_numeric($v))));
    }

    /**
     * Kesejajaran muka ukur — tiga posisi (Atas/Tengah/Bawah), dari daftar
     * `{posisi, nominal, pembacaan}` atau cerminan tabel HP
     * `{baris: [{nominal: [x], pembacaan: [y]}]}`.
     *
     * TIDAK dikonversi satuan: master menulis `H126 = D130 − F130` apa adanya.
     *
     * @return list<array{posisi: string, nominal: float, pembacaan: float}>
     */
    private static function kesejajaran(mixed $nilai): array
    {
        if (! is_array($nilai)) {
            return [];
        }

        $posisi = ['Atas', 'Tengah', 'Bawah'];
        $baris = isset($nilai['baris']) ? array_values((array) $nilai['baris']) : array_values($nilai);
        $hasil = [];

        foreach ($baris as $i => $b) {
            if (! is_array($b)) {
                continue;
            }

            $ambil = static function (mixed $x): mixed {
                if (is_array($x)) {
                    $x = array_values(array_filter($x, static fn ($v): bool => is_numeric($v)))[0] ?? null;
                }

                return is_numeric($x) ? (float) $x : null;
            };

            $nominal = $ambil($b['nominal'] ?? null);
            $pembacaan = $ambil($b['pembacaan'] ?? null);

            if ($nominal === null || $pembacaan === null) {
                continue;
            }

            $hasil[] = [
                'posisi' => (string) ($b['posisi'] ?? $posisi[$i] ?? ('Posisi '.($i + 1))),
                'nominal' => $nominal,
                'pembacaan' => $pembacaan,
            ];
        }

        return $hasil;
    }

    /**
     * Kerataan muka ukur — SATU pilihan. Master memakai dua checkbox yang
     * tidak saling meniadakan (`INPUT DATA!Y22`, `Y23`) dan cuma membaca yang
     * pertama. Nilai di luar `baik`/`buruk` balik `null`, bukan ditebak.
     */
    private static function kerataan(mixed $nilai): ?string
    {
        $bersih = is_string($nilai) ? strtolower(trim($nilai)) : null;

        return in_array($bersih, ['baik', 'buruk'], true) ? $bersih : null;
    }

    /**
     * @param  Collection<int, RawMeasurement>  $baris
     * @return list<float>
     */
    private static function deret(Collection $baris, string $peran): array
    {
        return $baris
            ->filter(static fn ($b): bool => (string) $b->peran_sensor === $peran)
            ->sortBy(static fn ($b): int => (int) ($b->sensor_ke ?? $b->pembacaan_ke ?? 0))
            ->map(static fn ($b): float => self::keMm($b->pembacaan, $b->satuan))
            ->values()
            ->all();
    }
}

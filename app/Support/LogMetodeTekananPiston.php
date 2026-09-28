<?php

namespace App\Support;

use RuntimeException;

/**
 * Pembaca `database/data/log-metode-tekanan-piston.json` — catatan kejadian
 * setiap perbedaan antara aplikasi dan workbook master acuan (manifest sha256)
 * untuk enam alat tekanan & piston volume.
 *
 * Log itu APPEND-ONLY. Entri versi terakhir per keluarga (`tekanan`/`piston`)
 * adalah versi rumus yang sedang dijalankan kode. Versi itu:
 *
 *  - distempelkan ke `parameter.versi_rumus` versi formula pertama tiap profil
 *    (`RumusKalibrasi`), dan
 *  - ditulis ke jejak tiap hasil hitung (`type_b_components.versi_rumus`),
 *
 * lalu `CalibrationValidator` mengadu keduanya. Kode yang sudah menghitung
 * dengan versi baru sementara versi formula yang berlaku masih menyebut versi
 * lama = sertifikat yang tidak bisa dijelaskan dengan versi yang tercetak.
 * Itu ERROR, bukan peringatan (ISO/IEC 17025 7.2.1.5).
 */
class LogMetodeTekananPiston
{
    public const BERKAS = 'database/data/log-metode-tekanan-piston.json';

    public const TEKANAN = 'tekanan';

    public const PISTON = 'piston';

    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** Versi rumus terakhir satu keluarga, mis. `TEKANAN-2026.09.28-1`. */
    public static function versiTerakhir(string $keluarga): string
    {
        return (string) self::entriTerakhir($keluarga)['versi_rumus'];
    }

    /**
     * Isi yang dicatat ke jejak hasil hitung: versinya, di mana log-nya, dan
     * workbook acuan mana (sha256) yang menjadi pembandingnya.
     *
     * @return array{versi: string, log: string, sha256_acuan: list<string>}
     */
    public static function stempel(string $keluarga): array
    {
        $entri = self::entriTerakhir($keluarga);

        return [
            'versi' => (string) $entri['versi_rumus'],
            'log' => self::BERKAS,
            'sha256_acuan' => array_values($entri['sha256_acuan']),
        ];
    }

    /** @return array<string, mixed> */
    public static function entriTerakhir(string $keluarga): array
    {
        $terakhir = null;

        foreach (self::muat()['versi'] as $entri) {
            if (($entri['keluarga'] ?? null) === $keluarga) {
                $terakhir = $entri;
            }
        }

        if ($terakhir === null) {
            throw new RuntimeException("Log metode tidak punya versi untuk keluarga `{$keluarga}`.");
        }

        return $terakhir;
    }

    /**
     * Perubahan di versi TERAKHIR satu keluarga yang masih menahan penerbitan
     * (`tahan_terbit: true`), dibatasi ke kode yang DIPICU sesi ini.
     *
     * Sumbernya log, bukan konstanta di kode: begitu Technical Manager
     * menjawab, entri versi baru mencatat jawabannya (dan `tahan_terbit`
     * berubah) — penahanannya lepas lewat jalur yang sama dengan perubahan
     * metode lain, bukan lewat suntingan diam-diam di profil.
     *
     * @param  list<string>  $terpicu
     * @return list<array<string, mixed>>
     */
    public static function menahanTerbit(string $keluarga, array $terpicu): array
    {
        return array_values(array_filter(
            self::entriTerakhir($keluarga)['perubahan'],
            static fn (array $p): bool => ($p['tahan_terbit'] ?? false) === true
                && in_array($p['kode'], $terpicu, true),
        ));
    }

    /**
     * Satu butir untuk `CalibrationProfile::penahanTerbit()`: kalimat yang
     * menyebut cacatnya, sel master, pertanyaan lab, dan KEDUA angka
     * berdampingan — admin yang membaca temuan validator harus bisa melihat
     * apa yang ditahan tanpa membuka kode atau jejak JSON.
     *
     * @param  array<string, mixed>  $p  entri `perubahan` dari log
     * @param  array<string, mixed>  $konteks  angka master & benar
     * @return array{penyimpangan: string, pesan: string, konteks: array<string, mixed>}
     */
    public static function butirTahan(array $p, string $angka, array $konteks): array
    {
        $pertanyaan = preg_match('/\b[PV]-\d+\b/', (string) $p['status_tm'], $c) === 1 ? $c[0] : (string) $p['status_tm'];

        return [
            'penyimpangan' => (string) $p['kode'],
            'pesan' => sprintf(
                'Ditahan menunggu keputusan Technical Manager (%s) — cacat master %s di `%s`. Master: %s. '
                .'Aplikasi: %s. %s Sertifikat tidak terbit sampai %s dijawab (keputusan pemilik proyek '
                .'28 Sep 2026; catatannya di %s).',
                $pertanyaan,
                $p['kode'],
                $p['sel'],
                $p['master'],
                $p['aplikasi'],
                $angka,
                $pertanyaan,
                self::BERKAS,
            ),
            'konteks' => ['pertanyaan' => $pertanyaan, ...$konteks],
        ];
    }

    /**
     * Angka untuk kalimat penahanan: koma desimal, tanpa nol buntut, sampai
     * enam desimal. `$tanda` menulis `+` untuk koreksi positif — T-11 justru
     * soal tanda yang terbalik, jadi tandanya harus terbaca.
     */
    public static function angka(float $x, bool $tanda = false): string
    {
        $s = rtrim(rtrim(number_format(abs($x), 6, ',', ''), '0'), ',');

        if ($s === '' || $s === '0') {
            return '0';
        }

        return $x < 0 ? '-'.$s : ($tanda ? '+'.$s : $s);
    }

    /** @return array<string, mixed> */
    public static function semua(): array
    {
        return self::muat();
    }

    /** Buang cache — dipakai test yang menyunting berkasnya. */
    public static function lupakan(): void
    {
        self::$data = null;
    }

    /**
     * HANYA untuk test: pakai isi log ini alih-alih berkasnya — mensimulasikan
     * entri versi baru (mis. jawaban Technical Manager) tanpa menyentuh berkas
     * yang append-only. Panggil `lupakan()` sesudahnya.
     *
     * @param  array<string, mixed>  $isi
     */
    public static function pakai(array $isi): void
    {
        self::$data = $isi;
    }

    /** @return array<string, mixed> */
    private static function muat(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $berkas = base_path(self::BERKAS);

        if (! is_file($berkas)) {
            throw new RuntimeException("Log metode tekanan & piston nggak ketemu: {$berkas}");
        }

        $isi = json_decode((string) file_get_contents($berkas), true);

        if (! is_array($isi) || ! isset($isi['acuan'], $isi['versi']) || ! is_array($isi['versi'])) {
            throw new RuntimeException("Log metode tekanan & piston rusak: {$berkas}");
        }

        return self::$data = $isi;
    }
}

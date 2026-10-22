<?php

namespace App\Services\Acuan;

use App\Services\Studio\PetaSel\PetaSel;
use App\Support\TataLetakSertifikat;

/**
 * Format sel lembar SERTIFIKAT master per paket data acuan (K-50-15:
 * sertifikat = lembar SERTIFIKAT master persis) — SATU-SATUNYA jembatan dari
 * jalur sertifikat ke peta sel, dan yang lewat hanya FORMAT TAMPIL (kode
 * number format `0.0`, `General`, …), tidak pernah angka.
 *
 * Urutan sumber:
 *  1. `format_sertifikat` di isi versi data acuan yang SEDANG dalam lingkup
 *     ([SumberAcuan::denganVersi]) bila versi itu membawa kunci tersebut;
 *  2. `format_sertifikat` peta sel paket (`database/data/peta-sel/<paket>.json`)
 *     — number format sel .xlsm asli, diverifikasi
 *     `docs/skrip/verifikasi-peta-xlsm.py` dan diadu `StudioFormatSertifikatTest`.
 *
 * Penjaga `StudioPetaSelMicrometerTest::test_jalur_hitung_tidak_membaca_master_studio_maupun_draf`
 * tetap melarang jalur hitung/sertifikat menyentuh peta sel atau master Studio
 * langsung; kelas ini pengecualian yang disengaja dan sempit (lihat test itu).
 * Yang dibekukan ke snapshot sertifikat adalah jawaban waktu terbit, jadi
 * sertifikat terbit tidak berubah bila peta berubah.
 */
final class FormatSertifikatAcuan
{
    /** @var array<string, list<array<string, mixed>>> kode paket => entri peta */
    private static array $entriPeta = [];

    /**
     * `kolom => kode format` untuk varian master `$varian` di titik ini; hanya
     * kolom [TataLetakSertifikat::KOLOM_FORMAT]. Entri ber-`varian` hanya
     * berlaku untuk varian itu; `titik_min`/`titik_di_bawah` membatasi titik.
     *
     * @return array<string, string>
     */
    public static function untuk(string $paket, ?string $varian, float $titikUkur): array
    {
        $hasil = [];

        foreach (PetaSel::untukVarian(self::entri($paket), $varian) as $f) {
            if ((isset($f['titik_min']) && $titikUkur < (float) $f['titik_min'])
                || (isset($f['titik_di_bawah']) && $titikUkur >= (float) $f['titik_di_bawah'])
                || ! in_array($f['kolom'], TataLetakSertifikat::KOLOM_FORMAT, true)) {
                continue;
            }

            $hasil[(string) $f['kolom']] = (string) $f['format'];
        }

        return $hasil;
    }

    /** @return list<array<string, mixed>> */
    private static function entri(string $paket): array
    {
        $versi = app(SumberAcuan::class)->versiDalamLingkup($paket);
        $dariVersi = $versi?->isiTerurai()['format_sertifikat']['entri'] ?? null;

        if (is_array($dariVersi)) {
            return array_values($dariVersi);
        }

        return self::$entriPeta[$paket] ??= array_values(PetaSel::untuk($paket)?->isi['format_sertifikat']['entri'] ?? []);
    }

    /** Untuk test yang menukar peta sel. */
    public static function lupakan(): void
    {
        self::$entriPeta = [];
    }
}

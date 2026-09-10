<?php

namespace App\Services\Calibration;

/**
 * Dua METODE untuk besaran yang sama — bukan dua alat.
 *
 * `FlowmeterTotalizerProfile` dan `FlowmeterFlowrateProfile` (alat ke-27 & ke-28)
 * lahir dari dua workbook **UFM**: perbandingan langsung dengan Ultrasonic
 * Flowmeter Krohne UFC300. Dua workbook yang turun berikutnya mengukur alat yang
 * sama, besaran yang sama, dan pita CMC yang sama — tapi dengan **penimbangan
 * statis gravimetri menurut ISO 4185**, memakai timbangan digital sebagai
 * standar.
 *
 * Memecahnya jadi profil ketiga & keempat tidak bisa: `CalibrationProfileRegistry`
 * melempar `LogicException` begitu dua profil mengaku ejaan nama alat yang sama,
 * dan `namaAlatKemampuan()`-nya memang harus sama — lampiran akreditasi
 * LK-285-IDN cuma punya SATU baris untuk `Flow Meter Cairan (Totalizer)`.
 *
 * Jadi varian jadi sumbu di dalam profil, persis presedennya:
 * `TimbanganProfile` (kg / gram / substitusi), TITS (Measure / Source), TIDS
 * (Recorder / Constant-Yokogawa).
 *
 * ## Kenapa GRAVIMETRI yang jadi bawaan
 *
 * | | UFM | Gravimetri |
 * |---|---|---|
 * | Umur master | dibuat Jan 2026 | dibuat Apr 2023, 23 revisi |
 * | Kolom VALIDATION | **kosong** | **AM, 21 Mei 2026** |
 * | Standar | UFM Krohne UFC300 | Timbangan digital (4 pilihan) |
 * | Metode di lampiran akreditasi | tidak disebut | **ISO 4185 / NIST SP 250** |
 *
 * Itu **bukan** alasan mencabut jalur UFM: sertifikat yang sudah terbit dari
 * workbook UFM wajib tetap bisa dihitung ulang dengan angka yang sama persis.
 * Yang berubah cuma bawaannya — dan sesi yang memilih UFM melahirkan peringatan
 * yang menyebut status validasinya, supaya admin tahu apa yang dia setujui.
 *
 * Butir ini menggugurkan `docs/pertanyaan-lab-flowmeter.md` §1 dan §16.
 */
enum VarianMetodeFlowmeter: string
{
    /** Perbandingan langsung dengan Ultrasonic Flowmeter Krohne UFC300. */
    case UFM = 'ufm';

    /** Penimbangan statis ISO 4185 dengan timbangan digital sebagai standar. */
    case GRAVIMETRI = 'gravimetri';

    /** Varian yang dipakai sesi BARU yang tidak menyebut variannya. */
    public static function bawaan(): self
    {
        return self::GRAVIMETRI;
    }

    /**
     * Baca varian dari blok `spesifikasi_alat.flowmeter`.
     *
     * Kunci yang memang TIDAK ADA pulang [UFM], bukan bawaan: sesi yang
     * ter-seed sebelum 10 Sep 2026 angkanya lahir dari jalur UFM, dan
     * memindahkannya ke gravimetri akan mengubah sertifikat yang sudah terbit.
     *
     * Nilai yang ADA tapi tidak dikenal pulang `null` — TIDAK jatuh diam-diam
     * ke bawaan. Salah ketik `'gravimetrik'` yang diam-diam jadi gravimetri
     * memilih 9 komponen budget alih-alih 11, dan angkanya tetap terbit tanpa
     * satu pun error.
     *
     * @param  array<string, mixed>|null  $blok
     */
    public static function dariBlok(?array $blok): ?self
    {
        $mentah = $blok['varian_metode'] ?? null;

        if ($mentah === null || $mentah === '') {
            return self::UFM;
        }

        return self::tryFrom(mb_strtolower(trim((string) $mentah)));
    }

    public function label(): string
    {
        return match ($this) {
            self::UFM => 'Perbandingan langsung UFM (Krohne UFC300)',
            self::GRAVIMETRI => 'Penimbangan statis gravimetri (ISO 4185)',
        };
    }

    /**
     * Sudah lolos validasi manajer teknis?
     *
     * Dipakai `FlowmeterProfile::peringatanSesi()` — sesi yang memilih varian
     * yang belum tervalidasi tetap boleh jalan, tapi tidak boleh jalan diam-diam.
     */
    public function tervalidasi(): bool
    {
        return $this === self::GRAVIMETRI;
    }
}

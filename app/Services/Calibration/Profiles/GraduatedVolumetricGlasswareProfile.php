<?php

namespace App\Services\Calibration\Profiles;

use App\Services\Calibration\VolumetricGlasswareCalculator as V;

/**
 * Keluarga **Graduated** (berskala) — workbook `Graduated_Volumetric_Glassware_2026`,
 * kertas `SIDIK-FM-CAL-0514_Rev.4` "Lembar Kerja Volumetrik Majemuk".
 *
 * Dua sampai lima titik skala, SATU budget untuk seluruh titik (MAX massa, MAX
 * ρ air, rata-rata semua suhu) dan satu U95 untuk tiap baris sertifikat.
 * Yang membedakannya dari Fixed — ditiru apa adanya, pertanyaan lab no. 5, 9, 10:
 *
 *  - u timbang = U95 sertifikat neraca ÷ 2
 *  - u densitas air 5·10⁻⁸ g/mL (angka mati)
 *  - meniskus dari RESOLUSI alat ÷ 2√3
 *  - ci muai bertanda −
 *
 * U95 dicetak 2 desimal (contoh master: Gelas Ukur 100 mL → 0,34).
 */
abstract class GraduatedVolumetricGlasswareProfile extends VolumetricGlasswareProfile
{
    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0514_Rev.4';

    public function keluarga(): string
    {
        return V::KELUARGA_GRADUATED;
    }

    protected function kodeDokumen(): string
    {
        return self::KODE_DOKUMEN;
    }

    protected function judulLembar(): string
    {
        return 'Calibration Worksheet - Graduated Volumetric Glassware';
    }

    /** Resolusi skala — sumber komponen meniskus yang mendominasi budget ini. */
    protected function fieldKeluarga(string $kunci): array
    {
        return [$this->field("{$kunci}.resolusi_ml", 'Resolution', 'angka', satuan: self::SATUAN)];
    }

    public function desimalU95(): ?int
    {
        return 2;
    }

    /**
     * K3 — keterulangan master `H55 = STDEV(IFERROR(H54:Q54, ""))` ikut
     * menjumlah lima sel kosong sebagai nol. Yang terbit memakai simpangan
     * baku nilai nyata saja; angka master dan U yang dihasilkannya ditulis di
     * sini (keputusan pemilik proyek 21 Sep: hitung benar + simpan pembanding).
     */
    protected function catatanAudit(array $h, float $uHitung): array
    {
        $p = $h['pembanding_master'] ?? [];

        if (! isset($p['stdev_keterulangan_nol_hantu'], $p['u95_nol_hantu'])) {
            return [];
        }

        return [[
            'kode' => 'volumetric_keterulangan_tanpa_nol_hantu',
            'pesan' => sprintf(
                'Keterulangan dihitung dari simpangan baku %d titik nyata: STDEV = %.12g → U hitung %.12g mL. '
                .'Workbook Graduated `PERHITUNGAN!H55 = STDEV(IFERROR(H54:Q54,""))` ikut membaca %d sel '
                .'kosong sebagai nol: STDEV = %.12g → U %.12g mL. Nol itu bukan pengukuran '
                .'(docs/pertanyaan-lab-volumetric.md no. 2); selisih U %.12g mL.',
                $p['jumlah_titik_budget'],
                $p['stdev_keterulangan_benar'],
                $uHitung,
                V::NOL_HANTU_MASTER,
                $p['stdev_keterulangan_nol_hantu'],
                $p['u95_nol_hantu'],
                $p['u95_nol_hantu'] - $uHitung,
            ),
            'nilai' => $p['stdev_keterulangan_nol_hantu'],
        ]];
    }
}

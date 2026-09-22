<?php

namespace App\Services\Calibration\Profiles;

use App\Services\Calibration\VolumetricGlasswareCalculator as V;

/**
 * Keluarga **Fixed** (satu tanda) — workbook `Fixed_Volumetric_Glassware_2026`,
 * kertas `SIDIK-FM-CAL-0513_Rev.4` "Lembar Kerja Volumetrik Tunggal".
 *
 * Satu titik (nominal = kapasitas), budget per titik. Yang membedakannya dari
 * Graduated — ditiru apa adanya, pertanyaan lab no. 5, 9, 10:
 *
 *  - u timbang = resolusi neraca ÷ √3
 *  - u densitas air 5·10⁻⁵ g/mL
 *  - meniskus dari tabel diameter ISO 4787 lewat toleransi kelas
 *  - ci muai bertanda +
 *
 * U95 dicetak 4 desimal (contoh master: Pipet Volume 1 mL → 0,003 = CMC).
 */
abstract class FixedVolumetricGlasswareProfile extends VolumetricGlasswareProfile
{
    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0513_Rev.4';

    public function keluarga(): string
    {
        return V::KELUARGA_FIXED;
    }

    protected function kodeDokumen(): string
    {
        return self::KODE_DOKUMEN;
    }

    protected function judulLembar(): string
    {
        return 'Calibration Worksheet - One Mark Volumetric Glassware';
    }

    /** Kertas Fixed mencetak Resolution "-" — alat bertanda satu tidak punya skala. */
    protected function fieldKeluarga(string $kunci): array
    {
        return [];
    }

    public function desimalU95(): ?int
    {
        return 4;
    }

    /**
     * Alat bertanda satu: nominal = kapasitas (`SERTIFIKAT!E17 = INPUT DATA!E15`),
     * jadi kotak Capacity yang dibiarkan kosong tidak menahan sesi.
     */
    protected function kapasitasUntukCmc(array $blok, array $masukan): ?float
    {
        return $blok['kapasitas_ml'] ?? ($masukan[0]['nominal'] ?? null);
    }

    /**
     * K4 — `Veff` master membagi dengan baris TERAKHIR budget (`K43`), bukan
     * jumlahnya (`K44`). Yang terbit memakai jumlahnya; angka master ditulis
     * di sini supaya selisihnya terbaca tanpa membuka kode.
     */
    protected function catatanAudit(array $h, float $uHitung): array
    {
        $veffMaster = $h['pembanding_master']['veff_dibagi_baris_akhir'] ?? null;

        if ($veffMaster === null) {
            return [];
        }

        return [[
            'kode' => 'volumetric_veff_dibagi_jumlah',
            'pesan' => sprintf(
                'Derajat kebebasan efektif dihitung %.12g (Welch–Satterthwaite, penyebut = JUMLAH '
                .'(ui·ci)⁴/vi seluruh komponen). Workbook Fixed `PERHITUNGAN U95%%!K45` membagi dengan '
                .'`K43` — baris terakhir saja — sehingga menulis %.12g dan k-nya mendekati 1,96. '
                .'Kerusakan salin-tempel (docs/pertanyaan-lab-volumetric.md no. 1); dihitung benar, '
                .'k %.12g, U hitung %.12g mL — lebih besar dari angka master.',
                $h['agregat']['derajat_kebebasan_efektif'],
                $veffMaster,
                $h['agregat']['faktor_cakupan_k'],
                $uHitung,
            ),
            'nilai' => $veffMaster,
        ]];
    }
}

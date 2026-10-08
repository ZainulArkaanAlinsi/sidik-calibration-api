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
        $catatan = $this->catatanRev7($h);
        $veffMaster = $h['pembanding_master']['veff_dibagi_baris_akhir'] ?? null;

        if ($veffMaster === null) {
            return $catatan;
        }

        return [...$catatan, [
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

    /**
     * Catatan audit profil yang mengikuti workbook Rev.7 (`parameterRev7()`):
     * keterulangan yang tidak masuk budget, dan sakelar suhu densitas air
     * (butir D). Kosong untuk profil yang tidak memakai Rev.7.
     *
     * @param  array<string, mixed>  $h
     * @return list<array{kode: string, pesan: string, nilai: float|null}>
     */
    private function catatanRev7(array $h): array
    {
        $r = $h['pembanding_master']['rev7'] ?? null;

        if ($r === null) {
            return [];
        }

        $pakaiMaster = $r['suhu_densitas_air'] === V::SUHU_DENSITAS_AIR_MASTER;

        return [
            [
                'kode' => 'volumetric_rev7_keterulangan_tidak_masuk_budget',
                'pesan' => sprintf(
                    'Keterulangan (stdev V20 per ulangan ÷ √3 = %.12g mL, ν = 2) TIDAK masuk budget: workbook '
                    .'Rev.7 `PERHITUNGAN U95%%!I36:I42` hanya tujuh komponen. Kalau dimasukkan, U hitung %.12g mL. '
                    .'Ditiru sesuai keputusan pemilik 8 Okt 2026 (docs/pertanyaan-lab-volumetric.md no. 20).',
                    $r['u_keterulangan'],
                    $r['u95_dengan_keterulangan'],
                ),
                'nilai' => $r['u95_dengan_keterulangan'],
            ],
            [
                'kode' => 'volumetric_rev7_suhu_densitas_air',
                'pesan' => $pakaiMaster
                    ? sprintf(
                        'ρ air ulangan 1 & 2 dihitung pada 25,5 °C, meniru angka mati `PERHITUNGAN!H40`/`J40` '
                        .'workbook Rev.7 (hanya `L40` berumus). Dari suhu terkoreksi tiap ulangan: V20 %.12g mL '
                        .'(selisih %.12g mL), U hitung %.12g mL. Pertanyaan lab no. 14.',
                        $r['v20_lain'], $r['v20_lain'] - $h['v20'], $r['u95_lain'],
                    )
                    : sprintf(
                        'ρ air tiap ulangan dihitung dari suhu terkoreksinya. Workbook Rev.7 `PERHITUNGAN!H40`/`J40` '
                        .'berisi angka mati 25,5 °C (hanya `L40` berumus); kalau ditiru: V20 %.12g mL (selisih '
                        .'%.12g mL), U hitung %.12g mL. Menunggu keputusan pemilik; pertanyaan lab no. 14.',
                        $r['v20_lain'], $r['v20_lain'] - $h['v20'], $r['u95_lain'],
                    ),
                'nilai' => $r['v20_lain'],
            ],
        ];
    }
}

<?php

namespace Tests\Support;

use App\Services\Acuan\ParameterKeputusanMetode;

/**
 * Menyimulasikan jawaban Manajer Teknis tanpa menyentuh registri append-only:
 * isi berkas asli ditambah SATU penetapan, dipakai lewat
 * `ParameterKeputusanMetode::pakai()` (divalidasi sama ketat dengan berkasnya).
 */
trait PenetapanParameterUji
{
    protected function pakaiPenetapan(string $kunci, string $nilai, string $berlakuMulai = '2026-12-01'): void
    {
        $isi = json_decode((string) file_get_contents(base_path(ParameterKeputusanMetode::BERKAS)), true, 512, JSON_THROW_ON_ERROR);

        foreach ($isi['parameter'] as &$p) {
            if ($p['kunci'] === $kunci) {
                $p['penetapan'][] = [
                    'nomor' => count($p['penetapan']) + 1,
                    'berlaku_mulai' => $berlakuMulai,
                    'tanggal_keputusan' => $berlakuMulai,
                    'nilai' => $nilai,
                    'diputuskan_oleh' => 'Manajer Teknis (uji, bukan keputusan nyata)',
                    'alasan' => 'simulasi jawaban TM untuk menguji pilihan non-bawaan',
                    'rujukan' => 'tes otomatis, bukan lembar centang',
                ];
            }
        }

        unset($p);

        ParameterKeputusanMetode::pakai($isi);
    }
}

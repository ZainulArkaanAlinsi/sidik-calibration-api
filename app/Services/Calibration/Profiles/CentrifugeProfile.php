<?php

namespace App\Services\Calibration\Profiles;

use App\Models\CalibrationSession;

/**
 * Profil **Centrifuge** — lampiran akreditasi LK-285-IDN kelompok "Waktu dan
 * Frekuensi" no. 38, pita CMC 60–200 rpm → 1,5 rpm dan 200–9000 rpm → 1,6 rpm.
 *
 * Seluruh mesin hitungnya di [ProfilPutaran]. Yang membedakannya dari
 * Tachometer cuma pita CMC di atas dan judul lembarnya — sheet `PERHITUNGAN`
 * kedua workbook master identik baris demi baris.
 *
 * ## Blok 1 masternya memakai satu sel, bukan MAX
 *
 * Empat dari lima blok budget di `Master Olda Centrifuge.xlsm` cocok dengan
 * hitungan kita sampai 5·10⁻⁶. Yang meleset cuma blok 1, dan penyebabnya
 * tunggal: komponen pengulangannya menunjuk `PERHITUNGAN!G34` — SATU sel —
 * sementara sepuluh blok lain di kedua workbook memakai `MAX(...)` sebaris
 * penuh. Workbook Tachometer memakai `MAX(G34:L34)` di blok yang sama persis,
 * jadi bentuk yang benar terbukti dari master itu sendiri.
 *
 * Akibatnya kecil tapi searah: U95 blok 1 naik dari 2,617 ke 2,618 rpm.
 */
class CentrifugeProfile extends ProfilPutaran
{
    /** Batas atas pita CMC tertinggi Centrifuge di lampiran akreditasi. */
    private const BATAS_AKREDITASI_RPM = 9000.0;

    public function kode(): string
    {
        return 'centrifuge';
    }

    public function namaAlatKemampuan(): string
    {
        return 'Centrifuge';
    }

    /**
     * @return list<string>
     */
    public function aliasNama(): array
    {
        // `Centrifuge Machine` & `Sentrifus` — ejaan yang muncul di kolom
        // `nama_alat` alat pelanggan untuk mesin yang sama.
        return ['Centrifuge Machine', 'Sentrifus'];
    }

    protected function judulLembar(): string
    {
        return 'Calibration Work Sheet - Centrifuge';
    }

    /**
     * Peringatkan admin kalau sesi ini memuat set point DI LUAR pita akreditasi
     * Centrifuge (60–9000 rpm).
     *
     * Bukan kehati-hatian berlebih: blok 5 `Master Olda Centrifuge.xlsm`
     * mengukur 15000, 20000, dan 25000 rpm — ketiganya di atas 9000 — dan tetap
     * memakai CMC 1,6 rpm dari pita `200–9000`. Angka itu lalu tercetak sebagai
     * ketidakpastian terakreditasi untuk putaran yang lampirannya tidak pernah
     * mencakup.
     *
     * Peringatan, bukan penolakan: lab boleh saja mengkalibrasi di luar lingkup
     * asal sertifikatnya tidak mengaku terakreditasi di titik itu — dan yang
     * berhak memutuskan manajer teknis, bukan kode ini. Yang tidak boleh adalah
     * terbit diam-diam.
     *
     * @return list<array<string, mixed>>
     */
    public function peringatanSesi(CalibrationSession $sesi): array
    {
        $diLuar = $sesi->uncertaintyCalculations
            ->filter(fn ($u): bool => (float) $u->titik_ukur > self::BATAS_AKREDITASI_RPM)
            ->map(static fn ($u): string => rtrim(rtrim(number_format((float) $u->titik_ukur, 2, ',', '.'), '0'), ','))
            ->values()
            ->all();

        if ($diLuar === []) {
            return [];
        }

        return [[
            'kode' => 'centrifuge_di_luar_akreditasi',
            'pesan' => sprintf(
                'Sesi ini memuat %d set point di atas %s rpm (%s) — di luar pita akreditasi '
                .'Centrifuge LK-285-IDN no. 38 yang berhenti di %s rpm. Lantai CMC yang terpasang '
                .'diambil dari pita tertinggi yang ada, jadi ketidakpastian di titik-titik itu '
                .'TIDAK didukung lampiran akreditasi. Pastikan sertifikatnya tidak mengaku '
                .'terakreditasi di titik tersebut.',
                count($diLuar),
                number_format(self::BATAS_AKREDITASI_RPM, 0, ',', '.'),
                implode(', ', $diLuar),
                number_format(self::BATAS_AKREDITASI_RPM, 0, ',', '.'),
            ),
        ]];
    }
}

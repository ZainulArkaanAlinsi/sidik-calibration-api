<?php

namespace Tests\Unit;

use App\Services\Calibration\TabelStandarTimbangan;
use App\Services\Calibration\VarianMasterTimbangan;
use Tests\TestCase;

/**
 * Adu tabel CMC workbook master ke lampiran akreditasi — dua berkas yang
 * HARUS setuju, dan yang selama ini tidak pernah dibandingkan.
 *
 * `database/data/tabel-standar-timbangan.json` (pita A..Q, dari
 * `DATABASE!R5:T21` workbook lab) dan `database/data/kemampuan-kalibrasi.json`
 * (lampiran LK-285-IDN no. 12) menyatakan hal yang sama dengan angka yang
 * berbeda satuannya. Kalau salah satu digeser tanpa yang lain — lab menambah
 * pita, atau reakreditasi mengubah CMC — sertifikat terbit dengan lantai U95
 * yang tidak lagi ada di ruang lingkup, dan tidak ada satu pun error di
 * sepanjang jalurnya.
 *
 * Yang ditegakkan JUMLAH pita dan ANGKA tiap pita, bukan cuma "ada".
 */
class TimbanganCmcCocokAkreditasiTest extends TestCase
{
    /** @return list<array{min: float, max: float, cmc_gram: float}> */
    private function rentangAkreditasi(): array
    {
        $data = json_decode((string) file_get_contents(database_path('data/kemampuan-kalibrasi.json')), true);

        foreach ($data['kelompok_pengukuran'] as $kelompok) {
            if (($kelompok['kelompok'] ?? null) !== 'Massa') {
                continue;
            }

            foreach ($kelompok['alat'] as $alat) {
                if (! str_contains(strtolower((string) $alat['nama_alat']), 'timbangan')) {
                    continue;
                }

                return array_map(
                    fn (array $r): array => [
                        'min' => (float) $r['min'],
                        'max' => (float) $r['max'],
                        // Lampiran menulis U dalam mg / g / kg; tabel master
                        // selalu gram. Disamakan ke gram di sini, bukan di
                        // salah satu berkasnya — dua-duanya sumber apa adanya.
                        'cmc_gram' => $this->keGram((float) $r['ketidakpastian'], (string) $r['satuan_u']),
                    ],
                    $alat['rentang'],
                );
            }
        }

        $this->fail('Baris akreditasi Massa / Timbangan nggak ketemu di kemampuan-kalibrasi.json.');
    }

    public function test_jumlah_pita_cmc_sama_dengan_lampiran(): void
    {
        $this->assertCount(
            count($this->rentangAkreditasi()),
            TabelStandarTimbangan::semuaPitaCmc(),
            'Jumlah pita CMC tabel master beda dari lampiran akreditasi. Salah satu berkas digeser '
            .'tanpa yang lain.',
        );
    }

    public function test_tiap_pita_cmc_cocok_angka_lampiran(): void
    {
        $lampiran = $this->rentangAkreditasi();

        foreach (TabelStandarTimbangan::semuaPitaCmc() as $i => $pita) {
            $this->assertEqualsWithDelta(
                $lampiran[$i]['cmc_gram'],
                (float) $pita['cmc_gram'],
                1e-9,
                sprintf(
                    'Pita %s (%s) berbunyi %s g di tabel master tapi %s g di lampiran akreditasi.',
                    $pita['kode'],
                    $pita['rentang'],
                    $pita['cmc_gram'],
                    $lampiran[$i]['cmc_gram'],
                ),
            );
        }
    }

    /**
     * Ambang pita master (`INPUT DATA!E4`) sengaja dilebihkan seperseribu —
     * 1,201 / 2,001 / 12,001 — supaya kapasitas yang jatuh PERSIS di batas
     * ikut pita di bawahnya. Ditegakkan di sini karena satu ambang yang
     * kelewat memindahkan seluruh kelas alat ke lantai CMC yang salah.
     */
    public function test_kapasitas_tepat_di_batas_ikut_pita_bawah(): void
    {
        $this->assertSame('A', TabelStandarTimbangan::pitaCmc(0.2)['kode'], '200 g harus pita A.');
        $this->assertSame('B', TabelStandarTimbangan::pitaCmc(1.2)['kode'], '1,2 kg harus pita B.');
        $this->assertSame('C', TabelStandarTimbangan::pitaCmc(2.0)['kode'], '2 kg harus pita C.');
        $this->assertSame('H', TabelStandarTimbangan::pitaCmc(200.0)['kode'], '200 kg harus pita H.');
        $this->assertSame('Q', TabelStandarTimbangan::pitaCmc(2000.0)['kode'], '2000 kg harus pita Q.');
    }

    /** Di atas pita terakhir TIDAK ada lantai — dan itu wajib kelihatan. */
    public function test_di_atas_dua_ton_nggak_punya_lantai_cmc(): void
    {
        $this->assertNull(
            TabelStandarTimbangan::pitaCmc(2500.0),
            'Kapasitas di atas 2000 kg harusnya nggak punya pita — kalau ada, lantainya dikarang.',
        );
    }

    /** Ketiga varian ada, dan basis satuannya sesuai workbook masing-masing. */
    public function test_ketiga_varian_terdaftar_dengan_basisnya(): void
    {
        $this->assertSame(
            VarianMasterTimbangan::semua(),
            TabelStandarTimbangan::varianTersedia(),
        );

        $this->assertSame('kg', TabelStandarTimbangan::basis(VarianMasterTimbangan::KG));
        $this->assertSame('g', TabelStandarTimbangan::basis(VarianMasterTimbangan::GRAM));
        $this->assertSame('kg', TabelStandarTimbangan::basis(VarianMasterTimbangan::SUBSTITUSI));
    }

    private function keGram(float $nilai, string $satuan): float
    {
        return match (strtolower(trim($satuan))) {
            'mg' => $nilai / 1000.0,
            'kg' => $nilai * 1000.0,
            default => $nilai,
        };
    }
}

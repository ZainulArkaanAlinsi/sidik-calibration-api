<?php

namespace Tests\Unit;

use App\Models\CalibrationCapability;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\Acuan\ParameterKeputusanMetode;
use App\Services\Calibration\Profiles\ConductivityProfile;
use App\Services\Calibration\Profiles\RefractometerProfile;
use App\Services\Calibration\SpectrophotometerCalculator;
use App\Services\GumCalculator;
use App\Support\FormatMasterSertifikat;
use Tests\TestCase;

/**
 * BAWAAN IDENTIK: selama tidak ada penetapan, parameter keputusan metode tidak
 * boleh menggeser satu bit pun angka yang dihitung.
 *
 * `tests/Fixtures/parameter-metode-golden-bawaan.json` DIREKAM dari kode
 * `origin/main` 79280fc2 SEBELUM satu baris profil diubah (skrip perekamnya
 * memanggil kode lama langsung: `deviasiSuhu`, `komponenBudget`, `hitungGrup`,
 * `Standard::nilaiPadaSuhu`, `FormatMasterSertifikat::tanggalUntukProfil`).
 * Di sini kode BARU diadu `assertSame` (float persis, bukan toleransi) ke rekaman
 * itu, pada masukan yang sama. Kalau seseorang "merapikan" jalur bawaan dan
 * menggeser sebuah angka, tes ini yang pertama merah.
 */
class ParameterMetodeBawaanIdentikTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $golden;

    protected function setUp(): void
    {
        parent::setUp();

        ParameterKeputusanMetode::lupakan();
        $this->golden = json_decode((string) file_get_contents(base_path('tests/Fixtures/parameter-metode-golden-bawaan.json')), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        ParameterKeputusanMetode::lupakan();
        parent::tearDown();
    }

    public function test_refractometer_deviasi_suhu_bawaan_identik_dengan_kode_lama(): void
    {
        $profil = new RefractometerProfile;

        foreach ($this->golden['refractometer']['deviasi'] as $satuan => $perSuhu) {
            foreach ($perSuhu as $suhu => $deviasi) {
                $this->assertSame($deviasi, $profil->deviasiSuhu((float) $suhu, $satuan), "{$satuan} @ {$suhu} C");
            }
        }
    }

    public function test_refractometer_budget_bawaan_identik_dengan_kode_lama(): void
    {
        $profil = new RefractometerProfile;
        $gum = new GumCalculator;
        $kem = new CalibrationCapability(['nama_alat' => 'Refractometer', 'ketidakpastian_terbaik' => 6.2e-05, 'u_temperature' => sqrt((0.07 / 2) ** 2 + (0.036 / 2) ** 2)]);
        $alat = new Equipment(['satuan' => 'n20D', 'resolusi' => 0.0001]);
        $std = new Standard(['nama' => 'Refractometer Std Solution 1.33659 n20D', 'ketidakpastian' => 2.6e-05, 'faktor_cakupan' => 2]);

        foreach ($this->golden['refractometer']['budget'] as $suhuRuang => $rekaman) {
            $komponen = $profil->komponenBudget($kem, $alat, $std, 1.33659, 0.00001234, 5, (float) $suhuRuang);
            $agregasi = $gum->agregasiBudget(array_map(
                static fn (array $k): array => ['u' => $k['u'], 'ci' => $k['ci'], 'vi' => $k['vi']],
                $komponen,
            ));

            $this->assertSame($rekaman['komponen'], $komponen, "komponen @ {$suhuRuang} C (termasuk teks keterangan)");
            $this->assertSame($rekaman['agregasi'], $agregasi, "agregasi @ {$suhuRuang} C");
        }
    }

    public function test_spectrophotometer_holmium_bawaan_identik_dengan_kode_lama(): void
    {
        $kalkulator = new SpectrophotometerCalculator;
        $spek = $this->golden['spectrophotometer']['_spek'];

        foreach (['titik1_nol', 'titik1_terbesar', 'titik1_menengah'] as $skenario) {
            $h = $kalkulator->hitungGrup($this->golden['spectrophotometer']['_masukan'][$skenario], $spek);
            $rekaman = $this->golden['spectrophotometer'][$skenario];

            foreach (['standar_deviasi_maks', 'jumlah_pengulangan', 'ketidakpastian_gabungan', 'derajat_kebebasan_efektif', 'faktor_cakupan_k', 'ketidakpastian_diperluas', 'u95_sertifikat'] as $kunci) {
                $this->assertSame($rekaman[$kunci], $h[$kunci], "{$skenario}.{$kunci}");
            }

            $this->assertSame(
                $rekaman['budget'],
                array_map(static fn (array $k): array => array_intersect_key($k, array_flip(['sumber', 'u', 'ci', 'vi', 'disertakan'])), $h['budget']),
                "{$skenario}.budget",
            );
            $this->assertSame($rekaman['kode_catatan_audit'], array_column($h['catatan_audit'], 'kode'), "{$skenario}: catatan audit bawaan tidak bertambah");
        }
    }

    public function test_conductivity_nilai_acuan_bawaan_identik_dengan_kode_lama(): void
    {
        $profil = new ConductivityProfile;

        foreach ($this->golden['conductivity']['standar'] as $nama => $perSuhu) {
            $kurva = ConductivityProfile::KOEF_SUHU[$nama];
            $standar = new Standard(['nama' => "Conductivity Std Solution {$nama}", 'koefisien_suhu' => $kurva]);
            $titik = (float) $nama;

            foreach ($perSuhu as $suhu => $nilai) {
                $this->assertSame(
                    $nilai,
                    $profil->nilaiAcuanStandar($standar, $suhu === 'null' ? null : (float) $suhu, $titik),
                    "larutan {$nama} @ {$suhu} C",
                );
            }
        }
    }

    public function test_tambah_hari_bawaan_registri_sama_dengan_tabel_format_master(): void
    {
        $gerbang = app(ParameterKeputusanMetode::class);

        foreach ($this->golden['tambah_hari'] as $kode => $rekaman) {
            $this->assertSame($rekaman, FormatMasterSertifikat::tanggalUntukProfil($kode), "tabel lama {$kode}");

            $putusan = $gerbang->putusan("sertifikat.tambah_hari.{$kode}");
            $this->assertTrue($putusan->bawaan);
            $this->assertSame($rekaman['tambah_hari'], $putusan->data['tambah_hari'], "bawaan registri {$kode} harus sama dengan tabel FormatMasterSertifikat");
        }
    }
}

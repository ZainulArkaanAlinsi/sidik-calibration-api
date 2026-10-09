<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\SpectrophotometerProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lembar kerja kelompok kimia/optik/waktu/putaran IKUT KERTAS formulir lab
 * (W1, 9 Okt 2026) — dan perubahan itu TAMPILAN SAJA.
 *
 * Dua hal yang dijaga, dan yang kedua yang menentukan:
 *
 *  1. Tulisan kunci di layar = tulisan kertas `Project-PT-Sidik/
 *     worksheet_alat_calibration/SIDIK-FM-CAL-05xx` (judul blok, label isian,
 *     kepala tabel), plus penanda kartu per titik.
 *  2. KUNCI DATA tidak bergeser satu pun: kode field, sumber, tipe, satuan,
 *     pilihan, tabel (`tahap|grup|peran`), kolom, baris (`titik_ukur`),
 *     pengulangan. Sidik jarinya direkam dari kode SEBELUM perubahan
 *     (commit 19c7b1a) ke `tests/Fixtures/lembar-kerja-kimia-kunci-data.json`.
 *     Label boleh berubah; kunci yang dibaca payload, `HitungUlangSesi`, dan
 *     sertifikat tidak.
 *
 * Kalau test kedua merah karena kunci data MEMANG sengaja diubah, itu bukan
 * pekerjaan tampilan lagi — rekam ulang fixture-nya hanya bersama perubahan
 * jalur hitung & test rekonsiliasi master yang menyertainya.
 */
class LembarKerjaIkutKertasKimiaTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = 'tests/Fixtures/lembar-kerja-kimia-kunci-data.json';

    /** @return array<string, array{string}> */
    public static function profil(): array
    {
        $hasil = [];

        foreach (['ph_meter', 'turbidimeter', 'chlorine_meter', 'do_meter', 'conductivity_meter', 'spectrophotometer',
            'refractometer', 'viscometer', 'timer_stopwatch', 'centrifuge', 'tachometer'] as $kode) {
            $hasil[$kode] = [$kode];
        }

        return $hasil;
    }

    #[DataProvider('profil')]
    public function test_kunci_data_identik_dengan_sebelum_ikut_kertas(string $kode): void
    {
        Organization::factory()->create();

        $harap = json_decode((string) File::get(base_path(self::FIXTURE)), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey($kode, $harap, "Fixture tidak memuat {$kode}.");

        $profil = app(CalibrationProfileRegistry::class)->untukKode($kode);

        foreach (['teknisi' => false, 'admin' => true] as $mode => $untukAdmin) {
            $sekarang = json_decode(
                json_encode(self::sidikJari($profil->bentukLembarKerja($untukAdmin)), JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR),
                true,
            );

            $this->assertSame(
                $harap[$kode][$mode],
                $sekarang,
                "Kunci data lembar {$kode} ({$mode}) berubah — perubahan tampilan tidak boleh menyentuhnya.",
            );
        }
    }

    /**
     * Bagian lembar yang DIBACA mesin: kode, tipe, sumber, satuan, pilihan,
     * tabel & kolom & baris. Label, judul, urutan, dan penanda tampilan
     * sengaja tidak ikut.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    public static function sidikJari(array $bentuk): array
    {
        $field = [];
        $tabel = [];
        $standar = [];

        foreach ($bentuk['bagian'] ?? [] as $bagian) {
            foreach ([...($bagian['field'] ?? []), ...($bagian['field_di_luar_kertas'] ?? [])] as $f) {
                $field[$f['kode']] = [
                    'tipe' => $f['tipe'] ?? null,
                    'sumber' => $f['sumber'] ?? null,
                    'satuan' => $f['satuan'] ?? null,
                    'hanya_admin' => $f['hanya_admin'] ?? false,
                    'tampil_kalau' => $f['tampil_kalau'] ?? null,
                    'pilihan' => array_map(static fn (array $p): mixed => $p['nilai'] ?? null, $f['pilihan'] ?? []),
                ];
            }

            foreach ($bagian['tabel'] ?? [] as $t) {
                $tabel[implode('|', [$t['tahap'] ?? '', $t['grup'] ?? '', $t['peran'] ?? ''])] = [
                    'satuan' => $t['satuan'] ?? null,
                    'kolom' => array_map(
                        static fn (array $k): array => [$k['kode'] ?? null, $k['tipe'] ?? null, $k['satuan'] ?? null],
                        $t['kolom'] ?? [],
                    ),
                    'kolom_baris' => array_map(static fn (array $k): mixed => $k['kode'] ?? null, $t['kolom_baris'] ?? []),
                    'baris' => array_map(self::barisData(...), $t['baris'] ?? []),
                    'baris_per_satuan' => array_map(
                        static fn (array $daftar): array => array_map(self::barisData(...), $daftar),
                        $t['baris_per_satuan'] ?? [],
                    ),
                    'pengulangan' => $t['pengulangan'] ?? [],
                    'titik_bisa_diubah' => $t['titik_bisa_diubah'] ?? false,
                    'offset_kunci' => $t['offset_kunci'] ?? null,
                ];
            }

            if (($bagian['kode'] ?? null) === 'usage_check') {
                $standar = array_map(
                    static fn (array $b): array => [$b['standard_id'] ?? null, $b['terdaftar'] ?? null],
                    $bagian['baris'] ?? [],
                );
            }
        }

        ksort($field);
        ksort($tabel);

        return [
            'kode_dokumen' => $bentuk['kode_dokumen'] ?? null,
            'kode_metode' => $bentuk['kode_metode'] ?? null,
            'jumlah_pengulangan' => $bentuk['jumlah_pengulangan'] ?? null,
            'satuan' => $bentuk['satuan'] ?? null,
            'larutan_standar' => $bentuk['larutan_standar'] ?? null,
            'field' => $field,
            'tabel' => $tabel,
            'standar' => $standar,
        ];
    }

    /**
     * @param  array<string, mixed>  $b
     * @return list<mixed>
     */
    private static function barisData(array $b): array
    {
        return [
            $b['titik_ukur'] ?? null,
            $b['nomor'] ?? null,
            $b['satuan'] ?? null,
            $b['resolusi'] ?? null,
            $b['desimal'] ?? null,
            $b['eksklusif_dengan'] ?? null,
            $b['standard_id'] ?? null,
        ];
    }

    /**
     * Tulisan kertas yang dipasang W1. Dibaca dari PDF formulir dengan
     * pdfplumber, 9 Okt 2026.
     */
    public function test_tulisan_kunci_persis_kertas(): void
    {
        Organization::factory()->create();

        // 0509 pH: kepala kolom Solution Standard tercetak `4.00 7.00 10.01`.
        $ph = $this->bentuk('ph_meter');
        $this->assertSame(['4.00', '7.00', '10.01'], array_column($this->tabel($ph)[0]['baris'], 'label'));
        $this->assertSame(['Before adjustment Reading', 'After adjustment Reading'], array_column($this->tabel($ph), 'judul'));

        // 0530 Turbidimeter: "Standard Used :", "TH used:" di CALIBRATION RESULT.
        $turbi = $this->bentuk('turbidimeter');
        $this->assertSame('Standard Used', $this->field($turbi, 'standar_dicek.*.dipakai')['label']);
        $this->assertSame(['hasil', 'TH used'], $this->letakField($turbi, 'thermohygro_standard_id'));
        $this->assertSame(
            ['Before Adjustment Reading of UUT (NTU)', 'After Adjustment Reading of UUT (NTU)'],
            array_column($this->tabel($turbi), 'judul'),
        );

        // 0531 Chlorine & 0532 DO: kepala kolom `mg/l`, satuan data tetap mg/L.
        $chlorine = $this->bentuk('chlorine_meter');
        $this->assertSame(['hasil', 'Thermohygro Used'], $this->letakField($chlorine, 'thermohygro_standard_id'));
        $this->assertSame('mg/l', $this->tabel($chlorine)[0]['kolom'][0]['label']);
        $this->assertSame('mg/L', $this->tabel($chlorine)[0]['kolom'][0]['satuan']);

        $do = $this->bentuk('do_meter');
        $this->assertSame(['hasil', 'Thermohygro used'], $this->letakField($do, 'thermohygro_standard_id'));
        $this->assertSame('mg/l', $this->tabel($do)[0]['kolom'][0]['label']);

        // 0510 Conductivity.
        $ec = $this->bentuk('conductivity_meter');
        $this->assertSame('4. Serial Number', $this->field($ec, 'alat_serial_number')['label']);
        $this->assertSame(['Before Adjustment Reading', 'After Adjustment Reading'], array_column($this->tabel($ec), 'judul'));

        // 0511 Spectrophotometer.
        $spektro = $this->bentuk('spectrophotometer');
        $this->assertSame(
            ['Wavelength (nm) - Holmium', 'Wavelength (nm) - Didynium', 'Neutral Filter (%T)'],
            array_column($this->tabel($spektro), 'judul'),
        );
        $this->assertSame(['Std. Value (λ1)', 'Std. Value (λ1)', 'Std. Value'], array_column($this->tabel($spektro), 'judul_nilai'));

        // 0523 Refractometer: kolom kanan General Information.
        $refra = $this->bentuk('refractometer');
        $this->assertSame(
            ['Equipment Name', 'Manufacturer', 'Type', 'SN'],
            [
                $this->field($refra, 'equipment.nama_alat')['label'],
                $this->field($refra, 'alat_merk')['label'],
                $this->field($refra, 'alat_model')['label'],
                $this->field($refra, 'alat_serial_number')['label'],
            ],
        );
        $this->assertSame(['Before Adjustment', 'After Adjustment'], array_column($this->tabel($refra), 'judul'));
        $this->assertSame(['Standard', 'Standard'], array_column($this->tabel($refra), 'judul_nilai'));
        $this->assertSame(['UUT Reading', 'UUT Reading'], array_column($this->tabel($refra), 'judul_pengulangan'));
        $this->assertFalse($this->field($refra, 'equipment.satuan')['di_kertas']);

        // 0524 Viscometer: "Spindle used", "Rpm used", "Resolusi UUT".
        $visco = $this->bentuk('viscometer');
        $this->assertSame('Spindle used — 100 cP', $this->field($visco, 'spesifikasi_alat.spindle_titik_1')['label']);
        $this->assertSame('Rpm used — 100 cP', $this->field($visco, 'spesifikasi_alat.rpm_titik_1')['label']);
        $this->assertSame('Resolusi UUT — 100 cP', $this->field($visco, 'spesifikasi_alat.resolusi_titik_1')['label']);

        // 0512 Stopwatch & Timer.
        $timer = $this->bentuk('timer_stopwatch');
        $this->assertSame('Calibration Worksheet - Stop Watch/Timer', $timer['judul']);
        $this->assertSame(
            ['EQUIPMENT', 'OWNER', 'STANDARD', 'CALIBRATION DATA', 'CALIBRATION RESULT', 'Di luar kertas', 'Catatan & Tanda Tangan'],
            array_column($timer['bagian'], 'judul'),
        );
        $this->assertSame(['Standard', 'UUT'], array_column($this->tabel($timer), 'judul'));
        $this->assertSame(['Repeatability', 'Repeatability'], array_column($this->tabel($timer), 'judul_pengulangan'));

        // 0515 Centrifuge & Tachometer — satu kertas untuk dua alat.
        foreach (['centrifuge', 'tachometer'] as $kode) {
            $putaran = $this->bentuk($kode);
            $this->assertSame('Calibration Worksheet - Centrifuge/Tachometer', $putaran['judul']);
            $this->assertSame(
                ['EQUIPMENT IDENTITY AND CUSTOMER DATA', 'OWNER', 'STANDARD', 'CALIBRATION DATA', 'CALIBRATION RESULT', 'Di luar kertas', 'Catatan & Tanda Tangan'],
                array_column($putaran['bagian'], 'judul'),
            );
            $this->assertSame(['Before adjustment Reading', 'After adjustment Reading'], array_column($this->tabel($putaran), 'judul'));
        }
    }

    /**
     * Kotak di luar kertas TIDAK hilang: tetap ada, ditandai, dan dikumpulkan
     * di blok "Di luar kertas" sebelum tanda tangan.
     */
    public function test_kotak_di_luar_kertas_tetap_ada_dan_ditandai(): void
    {
        Organization::factory()->create();

        foreach (['timer_stopwatch', 'centrifuge', 'tachometer'] as $kode) {
            foreach ([false, true] as $untukAdmin) {
                $bentuk = app(CalibrationProfileRegistry::class)->untukKode($kode)->bentukLembarKerja($untukAdmin);
                $blok = collect($bentuk['bagian'])->firstWhere('kode', 'di_luar_kertas');

                $this->assertNotNull($blok, "{$kode}: blok di_luar_kertas hilang.");
                $this->assertSame(
                    ['spesifikasi_alat.kapasitas', 'nomor_order'],
                    array_column($blok['field'], 'kode'),
                );
                $this->assertSame([false, false], array_column($blok['field'], 'di_kertas'));
            }
        }
    }

    /**
     * Kartu per titik dipasang di tabel yang bentuknya cocok buat kartu HP
     * (`LembarKerjaKartuBaris`: tabel sebaris berbagi baris, titik tetap).
     *
     * Yang SENGAJA tidak: Conductivity (baris varian `eksklusif_dengan` yang
     * tidak dikunci kartu), Spectrophotometer (tiga tabel dengan baris beda),
     * Stopwatch & Putaran (label baris `Set point n` jadi `Set point Set point
     * n` di kepala kartu), dan pH/Turbidimeter/Chlorine/DO — kartu HP belum
     * punya pemilih/centang standar per titik, padahal kartu jadi tampilan
     * awal (tinjauan W1 9 Okt 2026 temuan 3). Tabel tetap tampilan awal mereka.
     */
    public function test_kartu_per_set_point_hanya_di_tabel_yang_cocok(): void
    {
        Organization::factory()->create();

        foreach (['refractometer', 'viscometer'] as $kode) {
            $hasil = collect($this->bentuk($kode)['bagian'])->firstWhere('kode', 'hasil');

            $this->assertSame('kartu_per_set_point', $hasil['tampilan'] ?? null, $kode);
            $this->assertFalse($hasil['kartu_sejajar'], $kode);
            $this->assertFalse($hasil['nominal_berbintang'], "{$kode}: bintang cuma Anak Timbangan");
        }

        foreach (['ph_meter', 'turbidimeter', 'chlorine_meter', 'do_meter', 'refractometer', 'viscometer'] as $kode) {
            $hasil = collect($this->bentuk($kode)['bagian'])->firstWhere('kode', 'hasil');

            // Repeat-turun belum: kuncinya ikut menggambar lembar cetak OCR v1.
            foreach ($hasil['tabel'] as $t) {
                $this->assertSame('kolom', $t['sumbu_pengulangan'] ?? 'kolom', $kode);
            }
        }

        foreach (['ph_meter', 'turbidimeter', 'chlorine_meter', 'do_meter', 'conductivity_meter', 'spectrophotometer',
            'timer_stopwatch', 'centrifuge', 'tachometer'] as $kode) {
            $hasil = collect($this->bentuk($kode)['bagian'])->firstWhere('kode', 'hasil');

            $this->assertArrayNotHasKey('tampilan', $hasil, $kode);
        }
    }

    /**
     * Judul tabel Spectrophotometer di layar ikut kertas, tapi kolom "Remark"
     * SERTIFIKAT tetap judul master — dua sumber yang sengaja dipisah.
     */
    public function test_remark_sertifikat_spektro_tidak_ikut_judul_kertas(): void
    {
        $profil = new SpectrophotometerProfile;

        $this->assertSame('Wave Length ( λ ) - Filter Holmium', $profil->remarkTitik(637.9));
        $this->assertSame('Wave Length ( λ ) - Filter Didynium', $profil->remarkTitik(475.2));
        $this->assertSame('Accuracy %T and Linierity at λ = 560nm', $profil->remarkTitik(9.9));
    }

    /** @return array<string, mixed> */
    private function bentuk(string $kode): array
    {
        return app(CalibrationProfileRegistry::class)->untukKode($kode)->bentukLembarKerja();
    }

    /**
     * @param  array<string, mixed>  $bentuk
     * @return list<array<string, mixed>>
     */
    private function tabel(array $bentuk): array
    {
        return collect($bentuk['bagian'])->firstWhere('kode', 'hasil')['tabel'];
    }

    /**
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function field(array $bentuk, string $kode): array
    {
        foreach ($bentuk['bagian'] as $bagian) {
            foreach ($bagian['field'] ?? [] as $f) {
                if ($f['kode'] === $kode) {
                    return $f;
                }
            }
        }

        $this->fail("Field {$kode} tidak ada.");
    }

    /**
     * @param  array<string, mixed>  $bentuk
     * @return array{string, string}
     */
    private function letakField(array $bentuk, string $kode): array
    {
        foreach ($bentuk['bagian'] as $bagian) {
            foreach ($bagian['field'] ?? [] as $f) {
                if ($f['kode'] === $kode) {
                    return [$bagian['kode'], $f['label']];
                }
            }
        }

        $this->fail("Field {$kode} tidak ada.");
    }
}

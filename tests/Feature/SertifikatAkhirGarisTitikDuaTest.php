<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Calibration\VarianMetodeFlowmeter;
use App\Support\AkhirSertifikatMaster;
use App\Support\TataLetakSertifikat;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * G0-a (K-50-15, sertifikat persis master): bentuk "End of Certificate"
 * garis-teks-garis (R1), titik dua kepala (R3), dan baris U95 Flowmeter UFM
 * (R11). Ketiganya dikendalikan KUNCI di `snapshot.tata_letak`; cetak ulang
 * snapshot lama dijaga `SertifikatBekuTidakBerubahTest`.
 *
 * Sumber bentuk: `<lembar>.objek.json` master (grup `grpSp` "Group 10", di
 * dalam print area, 51 lembar); titik dua: sel `:` tersendiri di 72 dari 72
 * lembar SERTIFIKAT (micrometer `E5:E15`).
 */
class SertifikatAkhirGarisTitikDuaTest extends TestCase
{
    /** Profil yang masternya SEL teks `End of Certificate` (tata_letak.akhir_sertifikat). */
    private const PROFIL_SEL = [
        'anak_timbangan', 'autoclave', 'buret', 'buret_digital', 'dispensett', 'gelas_ukur', 'labu_ukur',
        'picnometer', 'pipet_ukur', 'pipet_volume', 'piston_pipette', 'timbangan', 'tits',
    ];

    /** @return array<string, CalibrationProfile> */
    private function semuaProfil(): array
    {
        $hasil = [];

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            $hasil[$profil->kode()] ??= $profil;
        }

        return $hasil;
    }

    /** @return array<string, mixed> */
    private function tataLetak(CalibrationProfile $profil, ?CalibrationSession $sesi = null): array
    {
        $timpaan = $profil->tataLetakSertifikat($sesi ?? new CalibrationSession);
        // Kunci kepala tambahan (`kelas`, `capacity` ...) datang dari `isiTambahanKepala()` sesi nyata.
        $tambahan = array_values(array_diff(
            array_filter(array_column(array_merge(...($timpaan['kepala'] ?? [])), 'kunci')),
            TataLetakSertifikat::KUNCI_KEPALA,
        ));

        return TataLetakSertifikat::normalisasi($timpaan, $tambahan);
    }

    /** @return array<string, mixed> */
    private function fixture(string $kode): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/sertifikat-beku/{$kode}.snapshot.json")), true, 512, JSON_THROW_ON_ERROR);
    }

    private function render(array $snapshot): string
    {
        app()->setLocale('en');

        $html = view('sertifikat.pdf', [
            'sertifikat' => new Certificate, 'snapshot' => $snapshot, 'logo' => null, 'kop' => null,
            'tandaTangan' => null, 'posisiTtd' => [], 'ukuranTtd' => [], 'qr' => null, 'keputusan' => null,
            'web' => false, 'paksaPadat' => false,
        ])->render();

        // Komentar CSS kepala dokumen menyebut "End of Certificate"; yang dihitung isi <body>.
        return substr($html, (int) strpos($html, '<body'));
    }

    /** Snapshot BARU dari fixture: tata letak = jawaban hook profil waktu ini. */
    private function snapshotBaru(string $kode, ?CalibrationSession $sesi = null): array
    {
        $snapshot = $this->fixture($kode);
        $snapshot['tata_letak'] = $this->tataLetak($this->semuaProfil()[$kode], $sesi);
        unset($snapshot['tata_letak']['catatan']);

        return $snapshot;
    }

    public function test_tiap_profil_punya_tepat_satu_bentuk_end_of_certificate(): void
    {
        $profil = $this->semuaProfil();
        $this->assertCount(48, $profil, 'Registry: 48 kode profil.');

        foreach ($profil as $kode => $p) {
            $tl = $this->tataLetak($p);
            $sel = array_key_exists('akhir_sertifikat', $tl);
            $garis = array_key_exists('akhir_sertifikat_garis', $tl);

            $this->assertTrue($sel xor $garis, "{$kode}: tepat satu bentuk (sel teks XOR grup garis), 48 dari 48 profil.");
            $this->assertSame(in_array($kode, self::PROFIL_SEL, true), $sel, "{$kode}: sel teks hanya 13 profil master-sel.");
        }
    }

    public function test_bentuk_grup_persis_objek_master(): void
    {
        // micrometer: Group 10 H54:L56, run "End of Certificate" Arial 8 tebal miring (objek.json).
        $this->assertSame(
            ['teks' => 'End of Certificate', 'tebal' => true, 'miring' => true, 'ukuran' => 8, 'lebar' => 0.27],
            $this->tataLetak($this->semuaProfil()['micrometer'])['akhir_sertifikat_garis'],
        );
        // hydrometer, centrifuge, tachometer: miring tanpa tebal; spectrophotometer 10 pt polos; timer 7 pt.
        foreach (['hydrometer', 'centrifuge', 'tachometer'] as $kode) {
            $g = $this->tataLetak($this->semuaProfil()[$kode])['akhir_sertifikat_garis'];
            $this->assertFalse($g['tebal'], $kode);
            $this->assertTrue($g['miring'], $kode);
        }
        $spektro = $this->tataLetak($this->semuaProfil()['spectrophotometer'])['akhir_sertifikat_garis'];
        $this->assertSame([false, false, 10], [$spektro['tebal'], $spektro['miring'], $spektro['ukuran']]);
        $this->assertSame(7, $this->tataLetak($this->semuaProfil()['timer_stopwatch'])['akhir_sertifikat_garis']['ukuran']);

        foreach (AkhirSertifikatMaster::PER_PROFIL as $kode => $baris) {
            $this->assertSame('End of Certificate', $baris[0], "{$kode}: teks persis master, tanpa strip.");
        }
    }

    public function test_tabel_hanya_memuat_profil_terdaftar_dan_tidak_menyentuh_profil_sel(): void
    {
        $kodeProfil = array_keys($this->semuaProfil());

        foreach (array_keys(AkhirSertifikatMaster::PER_PROFIL) as $kode) {
            $this->assertContains($kode, $kodeProfil, "{$kode}: bukan kode profil terdaftar.");
            $this->assertNotContains($kode, self::PROFIL_SEL, "{$kode}: sudah bentuk sel teks.");
        }

        // Differential `J56:S57`; TIDS Recorder `H58:N60` + Yokogawa `H57:N59`: Arial 8 tebal-miring.
        $this->assertSame(['teks' => 'End of Certificate', 'tebal' => true, 'miring' => true, 'ukuran' => 8, 'lebar' => 0.31], AkhirSertifikatMaster::untukProfil('differential_pressure'));
        $this->assertSame(['teks' => 'End of Certificate', 'tebal' => true, 'miring' => true, 'ukuran' => 8, 'lebar' => 0.28], AkhirSertifikatMaster::untukProfil('tids'));
        $this->assertNull(AkhirSertifikatMaster::untukProfil('labu_ukur'));
    }

    public function test_titik_dua_kepala_dibekukan_untuk_semua_profil_snapshot_baru(): void
    {
        foreach ($this->semuaProfil() as $kode => $p) {
            $this->assertTrue($this->tataLetak($p)['kepala_titik_dua'], "{$kode}: titik dua ada di 72/72 lembar master.");
        }

        $this->assertFalse(TataLetakSertifikat::BAWAAN['kepala_titik_dua'], 'Bentuk lama tanpa titik dua.');
        $this->assertNull(TataLetakSertifikat::BAWAAN['akhir_sertifikat_garis']);
        $this->assertFalse(TataLetakSertifikat::BAWAAN['u95_relatif_menggantikan_baris']);
    }

    public function test_normalisasi_menolak_bentuk_garis_yang_salah(): void
    {
        foreach ([
            ['teks' => 'End of Certificate'],
            ['teks' => '', 'tebal' => true, 'miring' => true, 'ukuran' => 8, 'lebar' => 0.3],
            ['teks' => 'x', 'tebal' => 'ya', 'miring' => true, 'ukuran' => 8, 'lebar' => 0.3],
            ['teks' => 'x', 'tebal' => true, 'miring' => true, 'ukuran' => 40, 'lebar' => 0.3],
            ['teks' => 'x', 'tebal' => true, 'miring' => true, 'ukuran' => 8, 'lebar' => 3],
            ['teks' => 'x', 'tebal' => true, 'miring' => true, 'ukuran' => 8, 'lebar' => 0.3, 'warna' => 'merah'],
        ] as $salah) {
            try {
                TataLetakSertifikat::normalisasi(['akhir_sertifikat_garis' => $salah]);
                $this->fail('Seharusnya melempar: '.json_encode($salah));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('akhir_sertifikat_garis', $e->getMessage());
            }
        }

        $this->assertSame(['teks' => 'x', 'tebal' => true, 'miring' => false, 'ukuran' => 8, 'lebar' => 0.3], TataLetakSertifikat::normalisasi([
            'akhir_sertifikat_garis' => ['teks' => 'x', 'tebal' => true, 'miring' => false, 'ukuran' => 8, 'lebar' => 0.3],
        ])['akhir_sertifikat_garis']);
        $this->assertNull(TataLetakSertifikat::normalisasi(['akhir_sertifikat_garis' => null])['akhir_sertifikat_garis']);
    }

    public function test_snapshot_baru_mencetak_grup_garis_teks_garis_dan_titik_dua(): void
    {
        $baru = $this->snapshotBaru('micrometer');
        $html = $this->render($baru);

        // Grup: garis - teks - garis, di bawah blok tanda tangan dan di atas kode dokumen.
        $this->assertSame(1, substr_count($html, 'End of Certificate'));
        $this->assertSame(2, substr_count($html, 'border-top: 0.75pt solid #000'));
        $this->assertMatchesRegularExpression('#font-size: 8px; font-weight: bold; font-style: italic;[^>]*>End of Certificate</td>#', $html);
        $this->assertLessThan(strpos($html, 'End of Certificate'), strpos($html, 'Technical Manager'));
        $this->assertLessThan(strpos($html, 'kode-dokumen">'), strpos($html, 'End of Certificate'));

        // Titik dua: satu sel `:` tiap field kepala berisi.
        $this->assertSame(
            substr_count($html, 'class="val'),
            substr_count($html, 'class="sep"'),
            'Satu sel sep per sel val (isi maupun kosong).',
        );
        $terisi = count(array_filter(array_merge(...$baru['tata_letak']['kepala'])));
        $this->assertSame($terisi, substr_count($html, '>:</td>'), 'Satu `:` per field kepala berisi.');
        $this->assertStringContainsString('class="info kepala-master titik-dua"', $html);
    }

    public function test_tanpa_kunci_tidak_ada_elemen_baru(): void
    {
        $baru = $this->snapshotBaru('micrometer');
        unset($baru['tata_letak']['akhir_sertifikat_garis'], $baru['tata_letak']['kepala_titik_dua']);
        $html = $this->render($baru);

        $this->assertStringNotContainsString('End of Certificate', $html);
        $this->assertStringNotContainsString('class="sep"', $html);
        $this->assertStringNotContainsString('titik-dua', $html);
    }

    public function test_profil_sel_teks_tetap_satu_bentuk_dan_tanpa_grup(): void
    {
        $baru = $this->snapshotBaru('labu_ukur');
        $this->assertArrayHasKey('akhir_sertifikat', $baru['tata_letak']);
        $html = $this->render($baru);

        $this->assertSame(1, substr_count($html, 'End of Certificate'), 'Hanya strip sel master; tidak ganda dengan grup.');
        $this->assertStringContainsString('class="akhir-sertifikat"', $html);
        $this->assertStringNotContainsString('border-top: 0.75pt solid #000', $html);
    }

    public function test_u95_relatif_ufm_tanpa_baris_ringkas_dan_varian_lain_tidak_berubah(): void
    {
        $profil = $this->semuaProfil()['flowmeter_flowrate'];
        $sesiUfm = new CalibrationSession(['spesifikasi_alat' => ['flowmeter' => ['mode' => 'flowrate', 'varian_metode' => VarianMetodeFlowmeter::UFM->value]]]);
        $sesiGrav = new CalibrationSession(['spesifikasi_alat' => ['flowmeter' => ['mode' => 'flowrate', 'varian_metode' => VarianMetodeFlowmeter::GRAVIMETRI->value]]]);

        $this->assertTrue($this->tataLetak($profil, $sesiUfm)['u95_relatif_menggantikan_baris']);
        $this->assertArrayNotHasKey('u95_relatif_menggantikan_baris', $this->tataLetak($profil, $sesiGrav));
        $this->assertArrayNotHasKey('u95_relatif_menggantikan_baris', $this->tataLetak($this->semuaProfil()['micrometer']));
        $this->assertSame(
            'u95_relatif',
            collect($this->tataLetak($profil, $sesiUfm)['kolom_hasil'])->pluck('kolom')->last(),
        );

        // UFM baru (fixture flowrate UFM): kolom U95 relatif ADA, baris ringkas TIDAK ada.
        $ufm = $this->snapshotBaru('flowmeter_flowrate', $sesiUfm);
        $this->assertTrue($ufm['tata_letak']['u95_relatif_menggantikan_baris']);
        $html = $this->render($ufm);
        $this->assertStringContainsString('U95% ±', $html);
        $this->assertStringNotContainsString('<tr class="u95">', $html);
        $this->assertStringNotContainsString('Uncertainty U<sub>95%</sub>', $html);

        // UFM terbit SEBELUM perbaikan (kunci tidak ada): bentuk lama utuh, baris ringkas tetap.
        $lama = $this->fixture('flowmeter_flowrate');
        $this->assertArrayNotHasKey('u95_relatif_menggantikan_baris', $lama['tata_letak']);
        $this->assertStringContainsString('<tr class="u95">', $this->render($lama));

        // Gravimetri (kolom `u95` per baris): tidak pernah ada baris ringkas, dengan atau tanpa kunci.
        $grav = $this->fixture('flowmeter_totalizer');
        $this->assertStringNotContainsString('<tr class="u95">', $this->render($grav));
    }
}

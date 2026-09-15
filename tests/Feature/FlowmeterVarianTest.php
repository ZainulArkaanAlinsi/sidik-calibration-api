<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\UncertaintyCalculation;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\VarianMetodeFlowmeter;
use App\Support\FlowmeterMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dua varian metode hidup berdampingan tanpa saling menggeser angka.
 *
 * Yang dijaga di sini bukan "gravimetri jalan" — itu urusan
 * `FlowmeterGravimetriMasterTest`. Yang dijaga: sesi varian **UFM** yang sudah
 * ada menghasilkan angka yang **sama persis** sesudah varian kedua mendarat.
 * Sertifikat yang sudah di tangan pelanggan tidak boleh bergeser satu digit pun
 * karena metode lain ditambahkan.
 */
class FlowmeterVarianTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Sesi tanpa kunci `varian_metode` = sesi LAMA = UFM.
     *
     * Bukan bawaan. Sesi yang ter-seed sebelum 10 Sep 2026 angkanya lahir dari
     * jalur UFM, dan memindahkannya ke bawaan baru akan mengubah sertifikat yang
     * sudah terbit — diam-diam, karena kedua varian sama-sama menghasilkan angka
     * yang wajar.
     */
    public function test_sesi_tanpa_kunci_varian_jatuh_ke_ufm(): void
    {
        $this->assertSame(VarianMetodeFlowmeter::UFM, VarianMetodeFlowmeter::dariBlok([]));
        $this->assertSame(VarianMetodeFlowmeter::UFM, VarianMetodeFlowmeter::dariBlok(['varian_metode' => null]));
        $this->assertSame(VarianMetodeFlowmeter::UFM, VarianMetodeFlowmeter::dariBlok(['varian_metode' => '']));

        // Sesi BARU yang tidak menyebut variannya pakai gravimetri.
        $this->assertSame(VarianMetodeFlowmeter::GRAVIMETRI, VarianMetodeFlowmeter::bawaan());
    }

    /**
     * Nilai yang ADA tapi tidak dikenal pulang `null`, bukan bawaan.
     *
     * Salah ketik `gravimetrik` yang diam-diam jadi gravimetri memilih 9/11
     * komponen budget alih-alih 8/9, dan angkanya tetap terbit.
     */
    public function test_varian_salah_ketik_tidak_jatuh_ke_bawaan(): void
    {
        $this->assertNull(VarianMetodeFlowmeter::dariBlok(['varian_metode' => 'gravimetrik']));
        $this->assertNull(VarianMetodeFlowmeter::dariBlok(['varian_metode' => 'ufm2']));

        // Yang benar tetap kebaca, termasuk yang huruf besar & berspasi.
        $this->assertSame(
            VarianMetodeFlowmeter::GRAVIMETRI,
            VarianMetodeFlowmeter::dariBlok(['varian_metode' => '  GRAVIMETRI ']),
        );
    }

    /**
     * Kedua sesi contoh varian UFM tetap menghasilkan angka yang SAMA PERSIS.
     *
     * Angkanya sendiri dijaga `FlowmeterMasterTest` — jalur yang sudah hijau
     * sebelum varian gravimetri ada. Di sini yang dipastikan variannya tidak
     * berpindah dan barisnya tidak hilang.
     */
    public function test_sesi_ufm_lama_tidak_bergeser(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach (['DEMO-FM-TOT-001', 'DEMO-FM-FLW-001'] as $nomor) {
            $sesi = CalibrationSession::where('nomor_sesi', $nomor)->firstOrFail();
            $blok = FlowmeterMentah::blokSesi($sesi->spesifikasi_alat);

            $this->assertSame(
                VarianMetodeFlowmeter::UFM->value,
                $blok['varian_metode'],
                "Sesi {$nomor} seharusnya tetap varian UFM.",
            );

            $this->assertGreaterThan(
                0,
                UncertaintyCalculation::where('calibration_session_id', $sesi->id)->count(),
                "Sesi {$nomor} kehilangan baris hitungannya.",
            );
        }
    }

    /**
     * Sesi gravimetri ter-seed memakai variannya, dan titik yang di luar lingkup
     * DIBLOKIR — bukan terbit diam-diam.
     */
    public function test_sesi_gravimetri_terseed_dan_titik_luar_lingkup_diblokir(): void
    {
        $this->seed(DatabaseSeeder::class);

        $tot = CalibrationSession::where('nomor_sesi', 'DEMO-FM-GRAV-TOT-001')->firstOrFail();
        $blok = FlowmeterMentah::blokSesi($tot->spesifikasi_alat);

        $this->assertSame(VarianMetodeFlowmeter::GRAVIMETRI->value, $blok['varian_metode']);
        $this->assertGreaterThan(0, $blok['kode_timbangan']);
        // Volume pipa DISIMPAN walau tidak dihitung — pertanyaan lab §5.
        $this->assertNotNull($blok['volume_pipa_l']);

        // Titik 1..3 terbit, titik 4 diblokir (di luar rentang tabel timbangan
        // DAN di luar pita CMC).
        $this->assertSame(
            [1, 2, 3],
            UncertaintyCalculation::where('calibration_session_id', $tot->id)
                ->orderBy('titik_ke')->pluck('titik_ke')->all(),
        );

        // Sesi Flowrate master TIDAK di-seed: seluruh titiknya di luar pita
        // akreditasi, jadi dia pulang nol baris dan `kalibrasi:sapu-sesi`
        // menandainya ERROR `titik_kosong` — vonis yang benar, dan justru
        // karena itu tidak boleh jadi data contoh. Blokirnya dijaga
        // `FlowmeterGravimetriMasterTest`.
        $this->assertNull(
            CalibrationSession::where('nomor_sesi', 'DEMO-FM-GRAV-RATE-001')->first(),
            'Sesi Flowrate gravimetri tidak boleh ter-seed — lihat docblock FlowmeterGravimetriSeeder.',
        );
    }

    /**
     * Nomor formulir cetak BEDA per varian — dan kertasnya memang tiga berkas.
     *
     * Ada di `Project-PT-Sidik/worksheet_alat_calibration/`:
     *
     *   SIDIK-FM-CAL-0538-Rev.0   FLOWMETER (Perbandingan Langsung dengan UFM)
     *   SIDIK-FM-CAL-0538.A-Rev.3 FLOWMETER-FLOWRATE
     *   SIDIK-FM-CAL-0538.B-Rev.3 FLOWMETER-TOTALIZER
     *
     * Varian UFM berbagi SATU kertas untuk dua mode (kotak modenya dicentang
     * teknisi); varian gravimetri punya kertas SENDIRI per mode, dan isinya
     * memang beda — Totalizer tidak punya kolom durasi maupun baris `Time ( )`.
     *
     * Yang dijaga di sini: `kode_dokumen` bawaan mengikuti varian BAWAAN.
     * Kalau tidak, lembar yang dicetak tanpa memilih apa pun menyebut nomor
     * formulir kertas yang kotaknya beda isi — dan lembar tercetak yang mengaku
     * formulir yang bukan dirinya adalah temuan auditor, bukan salah ketik.
     */
    public function test_nomor_formulir_beda_per_varian(): void
    {
        $harap = [
            'flowmeter_totalizer' => 'SIDIK-FM-CAL-0538.B_Rev.3',
            'flowmeter_flowrate' => 'SIDIK-FM-CAL-0538.A_Rev.3',
        ];

        foreach ($harap as $kode => $nomorGravimetri) {
            $profil = app(CalibrationProfileRegistry::class)->untukKode($kode);
            $bentuk = $profil->bentukLembarKerja();
            $peta = $bentuk['kode_dokumen_varian'];

            $this->assertSame(
                'SIDIK-FM-CAL-0538_Rev.0',
                $peta[VarianMetodeFlowmeter::UFM->value],
                "{$kode}: kertas UFM satu untuk dua mode.",
            );
            $this->assertSame(
                $nomorGravimetri,
                $peta[VarianMetodeFlowmeter::GRAVIMETRI->value],
                "{$kode}: kertas gravimetri punya nomor SENDIRI per mode.",
            );

            // Bawaan mengikuti varian bawaan, bukan dipatok.
            $this->assertSame(
                $peta[VarianMetodeFlowmeter::bawaan()->value],
                $bentuk['kode_dokumen'],
                "{$kode}: `kode_dokumen` bawaan wajib ikut varian bawaan.",
            );
        }

        // Dua mode TIDAK boleh berbagi nomor kertas gravimetri — kertasnya
        // memang dua berkas terpisah dengan isi yang berbeda.
        $this->assertNotSame($harap['flowmeter_totalizer'], $harap['flowmeter_flowrate']);
    }

    /**
     * Kertas gravimetri FM-0538.A/.B punya kotak "Volume Pipa dari Std. ke UUT
     * (V) Liter". `FlowmeterMentah` membacanya, tapi sampai 15 Sep 2026 lembar
     * HP tidak punya kotaknya — nilainya tidak pernah bisa sampai ke server.
     */
    public function test_lembar_gravimetri_punya_kotak_volume_pipa(): void
    {
        foreach (['flowmeter_totalizer', 'flowmeter_flowrate'] as $kode) {
            $bentuk = app(CalibrationProfileRegistry::class)->untukKode($kode)->bentukLembarKerja();
            $kodeField = [];
            $field = null;

            foreach ($bentuk['bagian'] as $bagian) {
                foreach ($bagian['field'] ?? [] as $f) {
                    $kodeField[] = $f['kode'];

                    if ($f['kode'] === 'spesifikasi_alat.flowmeter.volume_pipa_l') {
                        $field = $f;
                    }
                }
            }

            $this->assertNotNull($field, "{$kode}: kotak Volume Pipa hilang dari lembar.");
            $this->assertSame('angka', $field['tipe']);
            $this->assertSame('L', $field['satuan']);
            $this->assertSame([VarianMetodeFlowmeter::GRAVIMETRI->value], $field['tampil_kalau']['nilai']);

            // Duduk tepat sesudah Timbangan Standar, seperti di kertas.
            $i = array_search('spesifikasi_alat.flowmeter.kode_timbangan', $kodeField, true);
            $this->assertSame('spesifikasi_alat.flowmeter.volume_pipa_l', $kodeField[$i + 1]);
        }
    }

    /**
     * `peringatanSesi()` memulangkan `['kode' => ..., 'pesan' => ...]`.
     *
     * Penjaga untuk bug yang HIDUP DIAM-DIAM sampai 10 Sep 2026: sebelumnya
     * method ini memulangkan deret string, dan
     * `CalibrationValidator::periksaPeringatanProfil()` memetakannya dengan
     * `fn (array $p)` — jadi begitu satu cabang peringatan menyala, seluruh
     * endpoint `/validasi` pulang **500**. Tidak pernah ketahuan karena kedua
     * sesi contoh UFM selalu punya geometri pipa DAN path configuration, dan
     * tanpa keduanya tidak ada cabang yang menyala.
     */
    public function test_peringatan_sesi_berbentuk_kode_dan_pesan(): void
    {
        $this->seed(DatabaseSeeder::class);

        $registry = app(CalibrationProfileRegistry::class);

        foreach (['DEMO-FM-GRAV-TOT-001', 'DEMO-FM-TOT-001'] as $nomor) {
            $sesi = CalibrationSession::where('nomor_sesi', $nomor)->firstOrFail();
            $profil = $registry->untukAlat($sesi->equipment);

            foreach ($profil->peringatanSesi($sesi) as $p) {
                $this->assertIsArray($p, "Peringatan sesi {$nomor} wajib array, bukan string.");
                $this->assertArrayHasKey('kode', $p);
                $this->assertArrayHasKey('pesan', $p);
                $this->assertIsString($p['kode']);
                $this->assertIsString($p['pesan']);
            }
        }
    }

    /**
     * Sesi varian UFM melahirkan peringatan yang menyebut status validasinya.
     *
     * Varian gravimetri jadi bawaan bukan karena UFM salah, tapi karena
     * masternya yang sudah divalidasi. Sesi yang tetap memilih UFM boleh jalan —
     * asal admin tahu apa yang dia setujui.
     */
    public function test_sesi_ufm_memberi_peringatan_status_validasi(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('nomor_sesi', 'DEMO-FM-TOT-001')->firstOrFail();
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment);

        $kode = array_column($profil->peringatanSesi($sesi), 'kode');

        $this->assertContains('flowmeter_varian_ufm_belum_divalidasi', $kode);

        // Dan sesi gravimetri TIDAK membawanya.
        $grav = CalibrationSession::where('nomor_sesi', 'DEMO-FM-GRAV-TOT-001')->firstOrFail();
        $profilGrav = app(CalibrationProfileRegistry::class)->untukAlat($grav->equipment);

        $this->assertNotContains(
            'flowmeter_varian_ufm_belum_divalidasi',
            array_column($profilGrav->peringatanSesi($grav), 'kode'),
        );
        // Dan tidak menuntut geometri pipa yang lembarnya memang tidak punya.
        $this->assertNotContains(
            'flowmeter_geometri_pipa_kosong',
            array_column($profilGrav->peringatanSesi($grav), 'kode'),
        );
    }
}

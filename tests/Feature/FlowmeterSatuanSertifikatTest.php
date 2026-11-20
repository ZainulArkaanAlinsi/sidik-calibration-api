<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\RawMeasurement;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\FlowmeterTotalizerProfile;
use App\Services\CertificateSnapshotBuilder;
use App\Support\FlowmeterMentah;
use App\Support\TataLetakSertifikat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Sertifikat Flowmeter dicetak dalam satuan ALAT PELANGGAN, bukan satuan
 * budget.
 *
 * ## Celah yang ditutup di sini
 *
 * Mesin hitung flowmeter hidup dalam L (Totalizer) / Lpm (Flowrate): pembacaan
 * `m3/h`, `usg/min`, `kg/h` diubah ke situ lebih dulu supaya satu mesin
 * melayani semua satuan, dan `uncertainty_calculations` menyimpan hasil yang
 * SUDAH dikonversi. `FlowmeterCalculator::konversiBalik()` ditulis untuk
 * membaliknya di jalur sertifikat — lalu tidak pernah dipanggil siapa pun.
 *
 * Akibatnya alat yang layarnya menunjukkan `3,0 m3/h` terbit dengan `50,0 Lpm`
 * di kolom Unit Under Test. Dua angka itu setara, dan JUSTRU ITU yang bikin dia
 * lolos: kolom satuannya ikut berubah, jadi tabelnya konsisten dengan dirinya
 * sendiri — cuma tidak konsisten dengan alat yang dikalibrasi, dengan kop
 * sertifikat yang membaca `raw_measurements.satuan`, dan dengan resolusi alat
 * yang melahirkan jumlah desimalnya.
 *
 * Master membagi balik dengan faktor yang sama:
 * `SERTIFIKAT!E26 = 'PERHITUNGAN FC'!D63 / DATABASE!$S$22`.
 *
 * ## Kenapa sesi contohnya tidak menangkap ini
 *
 * `DEMO-FM-TOT-001` bersatuan `L` dan `DEMO-FM-FLW-001` bersatuan `LPM` —
 * dua-duanya faktor 1,0, jadi jalur konversinya identitas dan tidak ada satu
 * pun test lama yang pernah melewatinya. Berkas ini yang memindahkan keduanya
 * ke satuan lain.
 */
class FlowmeterSatuanSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private const TOL = 5e-6;

    /** `database/data/tabel-standar-flowmeter.json` → `satuan.flowrate.m3/h`. */
    private const FAKTOR_M3H = 16.666666666666668;

    /** Densitas fluida UUT yang diketik teknisi, kg/L. Bukan air murni — sengaja. */
    private const DENSITAS = 0.9915;

    /**
     * Kolom `SERTIFIKAT!E26:K27` master Flowrate, dalam LPM — sama persis
     * dengan yang dijaga [FlowmeterSertifikatTest].
     *
     * @var array<int, array{float, float, float}>
     */
    private const FLOWRATE_MASTER = [
        1 => [101.95755555555554, 100.67866666666667, -1.278888888888872],
        2 => [310.6425555555555, 306.828, -3.8145555555555006],
    ];

    private function siap(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('role', User::ROLE_ADMIN)->firstOrFail());
    }

    /**
     * Pindahkan sesi ke satuan lain TANPA mengubah satu pun angka budget-nya.
     *
     * Pembacaan mentah dibagi `$faktor` supaya konversi majunya memulangkan
     * nilai LPM yang sama persis — jadi kalau sertifikatnya bergeser, yang
     * bergeser jalur CETAK-nya, bukan hitungannya. Resolusi blok ikut dibagi
     * karena dia juga dikonversi maju di tempat pakai.
     */
    private function pindahSatuan(CalibrationSession $sesi, string $satuan, float $faktor, ?float $densitas = null): void
    {
        $peran = [FlowmeterMentah::PERAN_UUT, FlowmeterMentah::PERAN_STD];

        foreach ($sesi->rawMeasurements()->whereIn('peran_sensor', $peran)->get() as $b) {
            $b->update([
                'pembacaan' => (float) $b->pembacaan / $faktor,
                'satuan' => $satuan,
            ]);
        }

        if ($densitas !== null) {
            // `reorder()` WAJIB, dan ini bukan kerapian.
            //
            // Relasi `rawMeasurements()` membawa pengurutan bawaan
            // `titik_ke, pembacaan_ke, id`. Disambung ke `distinct()`, query
            // yang lahir jadi `SELECT DISTINCT titik_ke ... ORDER BY titik_ke,
            // pembacaan_ke, id` - dan MySQL menolaknya dengan error 3065,
            // karena dua kolom pengurut itu tidak ada di daftar SELECT.
            //
            // SQLite menerimanya tanpa keberatan. Jadi test ini hijau
            // bertahun-tahun di suite harian dan baru merah 21 Sep 2026, di
            // jalan MySQL penuh pertama yang benar-benar selesai. Persis celah
            // yang gerbang dua-suite ada untuk menangkapnya.
            $titik = $sesi->rawMeasurements()
                ->where('peran_sensor', FlowmeterMentah::PERAN_UUT)
                ->reorder('titik_ke')
                ->distinct()
                ->pluck('titik_ke');

            $contoh = $sesi->rawMeasurements()->firstOrFail();

            foreach ($titik as $titikKe) {
                foreach ([1, 2, 3] as $ulangan) {
                    RawMeasurement::create([
                        'calibration_session_id' => $sesi->id,
                        'tahap' => $contoh->tahap,
                        'titik_ke' => (int) $titikKe,
                        'titik_ukur' => 0.0,
                        'pembacaan_ke' => $ulangan,
                        'sensor_ke' => 1,
                        'peran_sensor' => FlowmeterMentah::PERAN_DENSITAS,
                        'pembacaan' => $densitas,
                        'satuan' => 'kg/L',
                        'standard_id' => $contoh->standard_id,
                        'input_source' => 'manual',
                        'is_verified' => true,
                    ]);
                }
            }
        }

        $spesifikasi = $sesi->spesifikasi_alat;
        $spesifikasi['satuan'] = $satuan;
        $spesifikasi[FlowmeterMentah::KUNCI_SESI]['satuan'] = $satuan;
        $spesifikasi[FlowmeterMentah::KUNCI_SESI]['resolusi'] =
            (float) $spesifikasi[FlowmeterMentah::KUNCI_SESI]['resolusi'] / $faktor;

        $sesi->update(['spesifikasi_alat' => $spesifikasi]);

        // Alatnya ikut pindah — `equipments.resolusi` yang melahirkan jumlah
        // desimal kolom hasil, dan dia memang bersatuan alat, bukan satuan
        // budget.
        $sesi->equipment->update([
            'satuan' => $satuan,
            'resolusi' => (float) $sesi->equipment->resolusi / $faktor,
        ]);

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$sesi->nomor_sesi]])->assertSuccessful();
    }

    /** @return array<string, mixed> */
    private function terbitkan(CalibrationSession $sesi): array
    {
        // Sesi contoh Flowrate selalu membawa dua peringatan yang benar dan
        // sengaja tidak dihilangkan — lihat [FlowmeterSertifikatTest::terbitkan].
        $this->sebagaiPemeriksaLain($sesi)->postJson("/api/calibrations/{$sesi->id}/approve", ['nomor_sertifikat' => $this->nomorUji(), 'abaikan_peringatan' => true])
            ->assertOk();

        return $sesi->fresh()->certificate()->firstOrFail()->snapshot;
    }

    /** @return array<int, UncertaintyCalculation> */
    private function tersimpan(CalibrationSession $sesi): array
    {
        return $sesi->fresh()->uncertaintyCalculations()->orderBy('titik_ke')->get()
            ->keyBy(static fn ($b): int => (int) $b->titik_ke)
            ->all();
    }

    /**
     * Sesi `m3/h` terbit dalam `m3/h` — keempat kolom angkanya DAN labelnya.
     *
     * Diadu ke DUA acuan sekaligus, dan itu disengaja:
     *
     *  1. ke master (dibagi 16,667), yang membuktikan angkanya masih angka yang
     *     sama — kalau hitungannya ikut bergeser, yang merah di sini;
     *  2. ke `uncertainty_calculations` (dibagi 16,667), yang membuktikan
     *     SELURUH kolom pindah bareng. U95 yang ketinggalan adalah bentuk
     *     kegagalan yang paling sulit terlihat: dia jadi satu-satunya kolom
     *     bersatuan budget di tabel yang lainnya sudah pindah, dan yang terbaca
     *     bukan "bug" melainkan "alat pelanggannya jelek".
     */
    public function test_sesi_m3h_terbit_dalam_m3h_bukan_lpm(): void
    {
        $this->siap();
        $sesi = CalibrationSession::where('nomor_sesi', 'DEMO-FM-FLW-001')->firstOrFail();

        $this->pindahSatuan($sesi, 'm3/h', self::FAKTOR_M3H);

        $tersimpan = $this->tersimpan($sesi);
        $snapshot = $this->terbitkan($sesi);

        $this->assertCount(2, $snapshot['hasil']);

        foreach ($snapshot['hasil'] as $b) {
            $ke = (int) $b['titik_ke'];
            [$uut, $standar, $koreksi] = self::FLOWRATE_MASTER[$ke];
            $simpan = $tersimpan[$ke];

            $this->assertSame(
                'm3/h',
                $b['satuan'],
                "Titik {$ke} masih berlabel satuan budget. Label yang tidak ikut pindah bareng "
                .'angkanya justru lebih berbahaya daripada dua-duanya tidak pindah.',
            );

            $this->assertEqualsWithDelta($uut / self::FAKTOR_M3H, $b['unit_under_test'], abs($uut) * self::TOL);
            $this->assertEqualsWithDelta($standar / self::FAKTOR_M3H, $b['standard_value'], abs($standar) * self::TOL);
            $this->assertEqualsWithDelta($koreksi / self::FAKTOR_M3H, $b['correction'], abs($koreksi) * self::TOL);

            // Tandanya tetap negatif sesudah dikonversi — faktor satuan selalu
            // positif, jadi konversi yang membalik tanda berarti salah rumus.
            $this->assertLessThan(0, $b['correction'], "Tanda kolom Correction titik {$ke} berubah.");

            $this->assertEqualsWithDelta(
                (float) $simpan->ketidakpastian_diperluas / self::FAKTOR_M3H,
                $b['u95'],
                abs((float) $simpan->ketidakpastian_diperluas) * self::TOL,
                "U95 titik {$ke} masih bersatuan budget — 16,7x terlalu besar di lembar m3/h.",
            );
        }

        // Kop sertifikat membaca `raw_measurements.satuan`. Sebelum perbaikan
        // ini dia sudah menulis `m3/h` sementara tabelnya menulis `Lpm` — satu
        // dokumen, dua satuan untuk besaran yang sama.
        $this->assertSame('m3/h', $snapshot['satuan']);
    }

    /**
     * Sesi Totalizer `m3` — totalizer punya tabel satuan sendiri (`m3` = 1000),
     * dan mode-nya memilih tabel mana yang dibaca. Satu mode yang benar tidak
     * membuktikan yang lain benar.
     */
    public function test_sesi_totalizer_m3_terbit_dalam_m3(): void
    {
        $this->siap();
        $sesi = CalibrationSession::where('nomor_sesi', 'DEMO-FM-TOT-001')->firstOrFail();

        $this->pindahSatuan($sesi, 'm3', 1000.0);

        $tersimpan = $this->tersimpan($sesi);
        $snapshot = $this->terbitkan($sesi);

        $this->assertCount(2, $snapshot['hasil']);

        foreach ($snapshot['hasil'] as $b) {
            $ke = (int) $b['titik_ke'];
            $simpan = $tersimpan[$ke];

            $this->assertSame('m3', $b['satuan']);

            foreach ([
                'unit_under_test' => (float) $simpan->rata_rata,
                'standard_value' => (float) $simpan->titik_ukur,
                'u95' => (float) $simpan->ketidakpastian_diperluas,
            ] as $kolom => $budget) {
                $this->assertEqualsWithDelta(
                    $budget / 1000.0,
                    $b[$kolom],
                    max(abs($budget) * self::TOL, 1e-12),
                    "Kolom {$kolom} titik {$ke} tidak dibagi 1000.",
                );
            }
        }
    }

    /**
     * Satuan berbasis MASSA dibalik pakai densitas fluida yang DIKETIK teknisi,
     * bukan densitas air.
     *
     * `kg/h` tidak punya faktor di master — `DATABASE!S25` Totalizer berisi teks
     * `'perlu dibagi densitas'`, `kg/h` berisi `=S23/1000` (salah dimensi), dan
     * `kg/min` berisi `#REF!`. Jalur baliknya karena itu `nilai x ρ x 60`, dan ρ
     * hidup PER TITIK — itu sebabnya hook-nya memulangkan closure, bukan faktor.
     */
    public function test_satuan_massa_dibalik_pakai_densitas_yang_diketik(): void
    {
        $this->siap();
        $sesi = CalibrationSession::where('nomor_sesi', 'DEMO-FM-FLW-001')->firstOrFail();

        // maju: kg/h -> LPM = nilai / ρ / 60. Jadi faktornya 1/(ρ·60).
        $faktor = 1.0 / (self::DENSITAS * 60.0);

        $this->pindahSatuan($sesi, 'kg/h', $faktor, self::DENSITAS);

        $tersimpan = $this->tersimpan($sesi);
        $snapshot = $this->terbitkan($sesi);

        $this->assertCount(2, $snapshot['hasil']);

        foreach ($snapshot['hasil'] as $b) {
            $ke = (int) $b['titik_ke'];
            $simpan = $tersimpan[$ke];

            $this->assertSame('kg/h', $b['satuan']);

            foreach ([
                'unit_under_test' => (float) $simpan->rata_rata,
                'standard_value' => (float) $simpan->titik_ukur,
                'u95' => (float) $simpan->ketidakpastian_diperluas,
            ] as $kolom => $budget) {
                $this->assertEqualsWithDelta(
                    $budget * self::DENSITAS * 60.0,
                    $b[$kolom],
                    max(abs($budget * self::DENSITAS * 60.0) * self::TOL, 1e-12),
                    "Kolom {$kolom} titik {$ke} tidak dikembalikan ke kg/h.",
                );
            }
        }
    }

    /**
     * Satuan massa yang densitasnya HILANG sesudah hitungannya tersimpan
     * MEMBLOKIR sertifikatnya — bukan mencetak angka budget berlabel `kg/h`.
     *
     * Ini keadaan yang tidak bisa dicapai lewat jalur normal: `konversi()` maju
     * sudah menolak titik bersatuan massa tanpa densitas, jadi barisnya tidak
     * pernah lahir. Yang diuji di sini datanya BERGESER sesudah hitungannya
     * tersimpan — baris densitasnya dihapus, barisnya di
     * `uncertainty_calculations` tetap ada.
     *
     * Jatuh diam-diam ke angka budget di sini menghasilkan sertifikat
     * terakreditasi yang salah belasan kali lipat tanpa satu pun kolom yang
     * terlihat ganjil, dan `GenerateCertificate` menangkap lemparannya:
     * sertifikatnya berstatus `gagal` dan admin dikabari pesannya.
     */
    public function test_satuan_massa_tanpa_densitas_memblokir_sertifikatnya(): void
    {
        $this->siap();
        $sesi = CalibrationSession::where('nomor_sesi', 'DEMO-FM-FLW-001')->firstOrFail();

        $faktor = 1.0 / (self::DENSITAS * 60.0);
        $this->pindahSatuan($sesi, 'kg/h', $faktor, self::DENSITAS);

        $snapshot = $this->terbitkan($sesi);
        $this->assertNotEmpty($snapshot['hasil'], 'prasyarat: sesi kg/h ini memang bisa terbit.');

        $sertifikat = $sesi->fresh()->certificate()->firstOrFail();

        // Densitasnya lenyap SESUDAH hitungannya tersimpan.
        $sesi->rawMeasurements()->where('peran_sensor', FlowmeterMentah::PERAN_DENSITAS)->delete();

        $segar = CalibrationSession::with(['equipment', 'rawMeasurements', 'uncertaintyCalculations'])
            ->findOrFail($sesi->id);

        $this->assertGreaterThan(
            0,
            $segar->uncertaintyCalculations->count(),
            'prasyarat: barisnya masih ada, cuma bahan baliknya yang hilang.',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/nggak bisa dibalikin ke satuan alat/');

        app(CertificateSnapshotBuilder::class)->bangun($segar, $sertifikat);
    }

    /**
     * Sesi bersatuan budget (`LPM`, `L`) tidak bergeser SEDIKIT PUN — angkanya
     * maupun labelnya.
     *
     * Hook-nya memulangkan `null` untuk faktor 1,0, jadi jalurnya sama persis
     * dengan sebelum perbaikan ini ada. Yang dijaga di sini bukan angkanya
     * (`FlowmeterSertifikatTest` sudah), melainkan bahwa labelnya tetap `Lpm`
     * dari `satuanHasil()` dan tidak diam-diam berganti jadi `LPM` blok sesi —
     * beda huruf besar yang tidak dilihat siapa pun sampai ada yang mencocokkan
     * sertifikat lama dengan yang baru.
     */
    public function test_sesi_bersatuan_budget_tidak_bergeser(): void
    {
        $this->siap();

        foreach (['DEMO-FM-FLW-001' => 'Lpm', 'DEMO-FM-TOT-001' => 'L'] as $nomor => $satuan) {
            $sesi = CalibrationSession::where('nomor_sesi', $nomor)->firstOrFail();
            $profil = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment);

            $this->assertNull(
                $profil->cetakDalamSatuanAlat($sesi),
                "Sesi {$nomor} sudah bersatuan budget — tidak boleh ada konversi sama sekali.",
            );

            $snapshot = $this->terbitkan($sesi);

            foreach ($snapshot['hasil'] as $b) {
                $this->assertSame($satuan, $b['satuan']);
            }
        }

        $flowrate = CalibrationSession::where('nomor_sesi', 'DEMO-FM-FLW-001')->firstOrFail();
        $snapshot = $flowrate->fresh()->certificate()->firstOrFail()->snapshot;

        foreach ($snapshot['hasil'] as $b) {
            [$uut, , $koreksi] = self::FLOWRATE_MASTER[(int) $b['titik_ke']];
            $this->assertEqualsWithDelta($uut, $b['unit_under_test'], abs($uut) * self::TOL);
            $this->assertEqualsWithDelta($koreksi, $b['correction'], abs($koreksi) * self::TOL);
        }
    }

    /**
     * Sesi yang blok flowmeter-nya tidak sah tidak menghasilkan konversi —
     * dan itu bukan kehati-hatian kosong: `hitungPerGrup()` sudah memindahkan
     * seluruh titiknya ke `belum_dihitung`, jadi mengarang faktor di jalur
     * cetak berarti membalik angka yang tidak pernah dihitung.
     */
    public function test_blok_tidak_sah_tidak_mengonversi_apa_pun(): void
    {
        $this->siap();
        $sesi = CalibrationSession::where('nomor_sesi', 'DEMO-FM-FLW-001')->firstOrFail();
        $profil = app(CalibrationProfileRegistry::class)->untukAlat($sesi->equipment);

        $spesifikasi = $sesi->spesifikasi_alat;

        // Mode dibuang -> blokSesi() balik null.
        $tanpaMode = $spesifikasi;
        unset($tanpaMode[FlowmeterMentah::KUNCI_SESI]['mode']);
        $sesi->spesifikasi_alat = $tanpaMode;
        $this->assertNull($profil->cetakDalamSatuanAlat($sesi));

        // Mode lembar lain -> profil Flowrate tidak boleh memungut blok Totalizer.
        $modeLain = $spesifikasi;
        $modeLain[FlowmeterMentah::KUNCI_SESI]['mode'] = FlowmeterMentah::MODE_TOTALIZER;
        $sesi->spesifikasi_alat = $modeLain;
        $this->assertNull($profil->cetakDalamSatuanAlat($sesi));

        // Satuan yang tidak dikenal tabel master -> jangan mengarang faktor.
        $asing = $spesifikasi;
        $asing[FlowmeterMentah::KUNCI_SESI]['satuan'] = 'barrel/jam';
        $sesi->spesifikasi_alat = $asing;
        $this->assertNull($profil->cetakDalamSatuanAlat($sesi));

        // Satuan sah -> ADA konversinya. Tanpa baris ini ketiga assert di atas
        // bisa hijau cuma karena hook-nya selalu balik null.
        $sah = $spesifikasi;
        $sah[FlowmeterMentah::KUNCI_SESI]['satuan'] = 'm3/h';
        $sesi->spesifikasi_alat = $sah;
        $cetak = $profil->cetakDalamSatuanAlat($sesi);

        $this->assertIsArray($cetak);
        $this->assertSame('m3/h', $cetak['satuan']);
        $this->assertEqualsWithDelta(3.0, ($cetak['ubah'])(50.0, 1), 1e-9);
    }

    /**
     * F-12: sertifikat Totalizer ber-m3 mencetak `Correction -0.00` dan `U95 0.0`.
     *
     * Format sel master (`148!D22` `0.00`, `148!J22` `0.0`; `149!D22` `0.00`)
     * ditulis dalam LITER, sementara angka yang dicetak sudah dibagi 1000 oleh
     * `cetakDalamSatuanAlat()`. Dua desimal liter = lima desimal m3: koreksi
     * -0,000154 m3 dan U95 0,000088 m3 (angka nyata lembar uji, timbangan 2)
     * runtuh ke nol, dan pelanggan membaca "koreksi nol".
     *
     * Presisi MUTLAK master dijaga: setiap digit yang dibuang konversi satuan
     * dikembalikan. Satuan yang nilainya LEBIH BESAR dari liter (`ml`) tidak
     * kehilangan apa pun dan tidak disentuh; `L` persis seperti sebelumnya.
     */
    public function test_format_cetak_totalizer_gravimetri_mengikuti_presisi_master_dalam_satuan_alat(): void
    {
        $this->siap();
        $sesi = CalibrationSession::where('nomor_sesi', 'DEMO-FM-GRAV-TOT-001')->firstOrFail();
        $profil = new FlowmeterTotalizerProfile;

        $format = function (string $satuan) use ($sesi, $profil): array {
            $spek = $sesi->spesifikasi_alat;
            $spek[FlowmeterMentah::KUNCI_SESI]['satuan'] = $satuan;
            $sesi->spesifikasi_alat = $spek;

            return $profil->formatKolomSertifikat($sesi, 0.01, $satuan);
        };

        // Bentuk lama untuk satuan budget dan satuan yang lebih kecil dari liter.
        $lama = ['standar' => '0.00', 'uut' => '0.00', 'koreksi' => '0.00', 'u95' => '0.0', 'k' => '0'];
        $this->assertSame($lama, $format('L'));
        $this->assertSame($lama, $format('ml'));

        // m3 = 1000 L: tiga digit lebih banyak, k tidak ikut (`k` bukan besaran liter).
        $this->assertSame(
            ['standar' => '0.00000', 'uut' => '0.00000', 'koreksi' => '0.00000', 'u95' => '0.0000', 'k' => '0'],
            $format('m3'),
        );

        // usg = 3,78541 L: satu digit (10^-1 usg > 10^-2 L tidak cukup halus).
        $this->assertSame(
            ['standar' => '0.000', 'uut' => '0.000', 'koreksi' => '0.000', 'u95' => '0.00', 'k' => '0'],
            $format('usg'),
        );

        // Angka lembar uji yang hilang: koreksi -0,1535615 L, U95 0,0880071 L,
        // standar 9,8464385 L, UUT 10 L -> m3.
        $baris = [
            'standard_value' => 9.84643845102817 / 1000,
            'unit_under_test' => 10.0 / 1000,
            'correction' => -0.1535615489718 / 1000,
            'u95' => 0.08800707566717 / 1000,
            'format' => TataLetakSertifikat::normalisasiFormat($format('m3')),
        ];
        $cetak = static fn (string $kolom): string => str_replace(',', '.', TataLetakSertifikat::teksSel($baris, $kolom, [], 3));

        $this->assertSame('0.00985', $cetak('standar'));
        $this->assertSame('0.01000', $cetak('uut'));
        $this->assertSame('-0.00015', $cetak('koreksi'), 'Koreksi -0,000154 m3 tercetak nol.');
        $this->assertSame('0.0001', $cetak('u95'), 'U95 0,000088 m3 tercetak nol.');
    }

    /**
     * F-12, jalur penuh UFM: sesi Totalizer `m3` diterbitkan, lalu setiap sel
     * hasil dibaca balik dari snapshot. Galat pembulatan cetak tidak boleh
     * melebihi setengah langkah format master dalam liter (0,005 L = 5e-6 m3).
     *
     * Dua desimal liter di master adalah bentuk cetak yang disetujui; yang
     * ditegakkan di sini hanya bahwa pindah satuan tidak menurunkannya.
     */
    public function test_sesi_totalizer_m3_dicetak_dengan_presisi_master_bukan_dengan_nol(): void
    {
        $this->siap();
        $sesi = CalibrationSession::where('nomor_sesi', 'DEMO-FM-TOT-001')->firstOrFail();

        $this->pindahSatuan($sesi, 'm3', 1000.0);
        $snapshot = $this->terbitkan($sesi);

        $this->assertCount(2, $snapshot['hasil']);

        foreach ($snapshot['hasil'] as $baris) {
            foreach (['standar' => 'standard_value', 'uut' => 'unit_under_test', 'koreksi' => 'correction'] as $kolom => $kunci) {
                $teks = str_replace(',', '.', TataLetakSertifikat::teksSel($baris, $kolom, $snapshot, (int) $baris['desimal']));

                $this->assertEqualsWithDelta(
                    $baris[$kunci],
                    (float) $teks,
                    5.0e-6 + 1.0e-12,
                    "Titik {$baris['titik_ke']} kolom {$kolom} tercetak `{$teks}`: presisi master 0,01 L hilang di m3.",
                );
            }
        }
    }

    /**
     * Sertifikat yang SUDAH terbit tidak berubah satu angka pun.
     *
     * `certificates.snapshot` dibekukan waktu terbit dan tidak pernah dihitung
     * ulang waktu dibaca — itu yang membuat perbaikan ini aman dipasang pada
     * lab yang sudah mengirim dokumen ke pelanggan. Dijaga di sini supaya
     * kalimat itu tidak cuma ada di komentar.
     */
    public function test_snapshot_lama_tidak_ikut_berubah(): void
    {
        $this->siap();
        $sesi = CalibrationSession::where('nomor_sesi', 'DEMO-FM-FLW-001')->firstOrFail();

        $sebelum = $this->terbitkan($sesi);

        $spesifikasi = $sesi->spesifikasi_alat;
        $spesifikasi[FlowmeterMentah::KUNCI_SESI]['satuan'] = 'm3/h';
        $sesi->update(['spesifikasi_alat' => $spesifikasi]);

        $sesudah = Certificate::where('calibration_session_id', $sesi->id)->firstOrFail()->snapshot;

        $this->assertSame($sebelum['hasil'], $sesudah['hasil']);
    }
}

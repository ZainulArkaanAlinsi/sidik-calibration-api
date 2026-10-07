<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\Profiles\AnakTimbanganProfile;
use App\Services\CalibrationValidator;
use App\Support\AnakTimbanganMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Alur penuh **Anak Timbangan** (alat ke-29) dengan payload BENTUK HP.
 *
 * ## Kenapa berkas ini ada
 *
 * Alat ke-29 mendarat 10 Sep 2026 dengan tabel standar, mesin hitung, profil,
 * sesi contoh, dan 1.011 pengaduan ke workbook master — semuanya hijau. Yang
 * tidak ada: **jalur kirim dari HP.**
 *
 * Keempat tabel ABBA tidak menyatakan `simpan_ke`, dan `CalibrationController`
 * tidak punya cabang `butuhBlokAnakTimbangan()`. Akibatnya payload dari aplikasi
 * teknisi jatuh ke loop deret-datar generik, baris mentahnya lahir TANPA
 * `peran_sensor`, dan `hitungPerGrup()` menolak seluruh titik dengan alasan
 * "nggak punya baris ber-peran". Lembarnya kegambar penuh, teknisi mengisi
 * sepuluh keping × empat peran × tiga ulangan, tombol kirim jalan mulus — dan
 * yang kembali sesi tanpa satu pun titik terhitung.
 *
 * Sesi contoh `DEMO-AT-001` tidak pernah memperlihatkannya: seeder menulis baris
 * mentahnya LANGSUNG ke database, melewati controller. Jadi seluruh suite hijau
 * di atas jalur yang tidak pernah dilewati satu pun teknisi.
 *
 * Ini kejadian KELIMA dari pola yang sama — Micrometer, TIDS, Timbangan, dan
 * Flowmeter mendahuluinya, dan keempatnya ketahuan dari sisi HP, bukan dari
 * suite backend, **karena test backend memakai payload yang ditulis backend
 * sendiri**. Lihat docblock `AlurPenuhFlowmeterTest`.
 */
class AlurPenuhAnakTimbanganTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Blok tingkat-sesi, persis bentuk yang dikirim `simpan_ke:
     * 'spesifikasi_alat.anak_timbangan.…'` di sisi HP.
     *
     * @return array<string, mixed>
     */
    private static function blokSesi(): array
    {
        return [
            'kelas_uut' => 'F1',
            'kelas_standar' => 'E2',
            'timbangan' => 'Analytical Balance',
            'meter_lingkungan' => 'Thermobarometer',
            'kapasitas_g' => 200,
            'suhu_awal' => 23.1,
            'suhu_akhir' => 23.0,
            'kelembaban_awal' => 55.0,
            'kelembaban_akhir' => 56.0,
            'tekanan_awal' => 933.2,
            'tekanan_akhir' => 933.1,
        ];
    }

    /**
     * Empat deret ber-peran per KEPING — satu entri `measurements[]` membawa
     * keempatnya, bukan empat entri terpisah.
     *
     * Itu yang dihasilkan `_measurementsDeretBernama()` di HP: dia menyusuri
     * keempat tabel per indeks baris dan menggabungkan hasilnya jadi satu entri.
     *
     * Angkanya dari sesi contoh master (identitas sintetis). Keping ketiga
     * SENGAJA kehilangan `at_t2`, dan keempat sengaja kosong seluruhnya.
     *
     * @return list<array<string, mixed>>
     */
    private static function keping(): array
    {
        return [
            [
                'titik_ukur' => 100.0,
                'at_s1' => [100.0, 100.0, 100.0],
                'at_t1' => [99.9999, 99.9999, 99.9999],
                'at_t2' => [99.9999, 99.9999, 99.9999],
                'at_s2' => [100.0, 100.0, 100.0],
            ],
            [
                'titik_ukur' => 50.0,
                'at_s1' => [50.0003, 50.0003, 50.0003],
                'at_t1' => [50.0003, 50.0003, 50.0003],
                'at_t2' => [50.0003, 50.0003, 50.0003],
                'at_s2' => [50.0002, 50.0002, 50.0002],
            ],
            [
                'titik_ukur' => 5.0,
                'at_s1' => [5.0002, 5.0002, 5.0002],
                'at_t1' => [5.0003, 5.0003, 5.0003],
                // `at_t2` sengaja TIDAK ada.
                'at_s2' => [5.0003, 5.0003, 5.0003],
            ],
            // Keping yang belum disentuh sama sekali — tidak boleh jadi titik,
            // dan tidak boleh menggeser penomoran titik sesudahnya.
            [
                'titik_ukur' => 20.0,
                'at_s1' => [],
                'at_t1' => [],
                'at_t2' => [],
                'at_s2' => [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $blok  blok sesi; default [blokSesi]
     * @param  list<array<string, mixed>>|null  $keping  default [keping]
     */
    private function kirim(?array $blok = null, ?array $keping = null): CalibrationSession
    {
        $this->seed(DatabaseSeeder::class);

        $contoh = CalibrationSession::where('nomor_sesi', 'DEMO-AT-001')->firstOrFail();
        $alat = Equipment::findOrFail($contoh->equipment_id);
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $alat->id,
                'standard_id' => $contoh->standard_id,
                'thermohygro_standard_id' => $contoh->thermohygro_standard_id,
                'tanggal_kalibrasi' => '2026-09-11',
                'suhu_awal' => 23.1,
                'suhu_akhir' => 23.0,
                'kelembaban_awal' => 55.0,
                'kelembaban_akhir' => 56.0,
                'spesifikasi_alat' => [AnakTimbanganMentah::KUNCI_SESI => $blok ?? self::blokSesi()],
                'measurements' => $keping ?? self::keping(),
            ])
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    /**
     * Penjaga utamanya: pembacaan yang dikirim HP lahir dengan kosakata `at_*`.
     *
     * Kalau daftar perannya kosong, payload HP tidak pernah sampai ke jalur
     * simpan ABBA — dan seluruh sesi tidak bisa dihitung.
     */
    public function test_pembacaan_bentuk_hp_lahir_dengan_peran_abba(): void
    {
        $sesi = $this->kirim();

        $peran = $sesi->rawMeasurements->pluck('peran_sensor')->unique()->sort()->values()->all();
        $diharap = AnakTimbanganMentah::PERAN_URUT;
        sort($diharap);

        $this->assertSame(
            $diharap,
            $peran,
            'Baris mentahnya nggak lahir dengan kosakata `at_*`. Kalau kosong sama sekali, '
            .'payload HP jatuh ke loop deret-datar dan tanda tiap suku `de` ikut hilang.',
        );
    }

    /**
     * Keempat peran tersimpan UTUH, dan urutan pembacaannya tidak tertukar.
     *
     * Yang dijaga di sini bukan jumlahnya saja. `de = (T1 − S1 − S2 + T2)/2`
     * memberi tanda berbeda ke tiap suku, jadi baris yang mendarat di peran yang
     * salah membalikkan ARAH koreksi kepingnya tanpa satu pun error.
     */
    public function test_keempat_peran_tersimpan_utuh_dan_tidak_tertukar(): void
    {
        $sesi = $this->kirim();

        // Dua keping penuh × 4 peran × 3 ulangan = 24; keping ketiga cuma
        // punya 3 peran × 3 = 9. Total 33.
        $this->assertCount(33, $sesi->rawMeasurements);

        $deret = static fn (CalibrationSession $s, string $peran): array => $s->rawMeasurements
            ->where('peran_sensor', $peran)
            ->where('titik_ke', 1)
            ->sortBy('pembacaan_ke')
            ->pluck('pembacaan')
            ->map(static fn ($x): float => (float) $x)
            ->values()
            ->all();

        $this->assertEqualsWithDelta([100.0, 100.0, 100.0], $deret($sesi, AnakTimbanganMentah::PERAN_S1), 1e-9);
        $this->assertEqualsWithDelta([99.9999, 99.9999, 99.9999], $deret($sesi, AnakTimbanganMentah::PERAN_T1), 1e-9);
        $this->assertEqualsWithDelta([99.9999, 99.9999, 99.9999], $deret($sesi, AnakTimbanganMentah::PERAN_T2), 1e-9);
        $this->assertEqualsWithDelta([100.0, 100.0, 100.0], $deret($sesi, AnakTimbanganMentah::PERAN_S2), 1e-9);
    }

    /**
     * Pembacaannya TIDAK dibulatkan di jalur simpan.
     *
     * Enam jalur blok lain melewatkan tiap angka ke `bulatkanKolom()`. Di sini
     * itu fatal: `100,0000` lawan `99,9999` selisihnya 0,1 mg, dan justru
     * selisih itulah yang sedang diukur. Dibulatkan, seluruh `de` sesi ini nol
     * dan sertifikatnya mencetak koreksi sempurna di setiap keping.
     */
    public function test_pembacaan_tidak_dibulatkan_di_jalur_simpan(): void
    {
        $sesi = $this->kirim();

        $t1 = (float) $sesi->rawMeasurements
            ->where('peran_sensor', AnakTimbanganMentah::PERAN_T1)
            ->where('titik_ke', 1)
            ->first()
            ->pembacaan;

        $this->assertEqualsWithDelta(
            99.9999,
            $t1,
            1e-9,
            'Pembacaan UUT dibulatkan — selisih 0,1 mg yang jadi isi seluruh lembar ini hilang '
            .'di jalur simpan.',
        );
    }

    /** Titik yang keempat perannya lengkap benar-benar terhitung. */
    public function test_titik_lengkap_menghasilkan_baris_ketidakpastian(): void
    {
        $sesi = $this->kirim();

        $hitungan = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();

        $this->assertCount(
            2,
            $hitungan,
            'Dua keping yang perannya lengkap harus terbit; yang ketiga sengaja kurang `at_t2`.',
        );

        $this->assertEqualsWithDelta(100.0, (float) $hitungan[0]->titik_ukur, 1e-9);
        $this->assertEqualsWithDelta(50.0, (float) $hitungan[1]->titik_ukur, 1e-9);

        foreach ($hitungan as $h) {
            $this->assertGreaterThan(
                0.0,
                (float) $h->ketidakpastian_diperluas,
                'U95 nol berarti komponen budgetnya hilang — dan `± 0,000` di sertifikat itu '
                .'klaim pengukuran sempurna, bukan sekadar angka kosong.',
            );
        }
    }

    /**
     * Keping yang belum disentuh tidak jadi titik, dan tidak menggeser
     * penomoran titik sesudahnya.
     *
     * Kertasnya punya sepuluh baris dan satu set anak timbangan jarang mengisi
     * kesepuluhnya. Baris kosong yang tetap memakan satu slot `titik_ke`
     * menggeser seluruh titik sesudahnya — dan geseran itu mendarat langsung di
     * sertifikat, karena `PerhitunganBuilder` mencetak baris per `titik_ke`.
     */
    public function test_keping_kosong_tidak_menggeser_penomoran(): void
    {
        $sesi = $this->kirim();

        $this->assertSame(
            [1, 2, 3],
            $sesi->rawMeasurements->pluck('titik_ke')->unique()->sort()->values()->all(),
            'Keping keempat yang kosong ikut memakan nomor titik — atau salah satu titik terisi hilang.',
        );
    }

    /**
     * Titik yang perannya belum lengkap DITOLAK, bukan dihitung dari tiga suku.
     */
    public function test_titik_yang_perannya_belum_lengkap_tidak_terbit(): void
    {
        $sesi = $this->kirim();

        $this->assertNull(
            $sesi->uncertaintyCalculations()->where('titik_ke', 3)->first(),
            'Keping tanpa `at_t2` tetap terbit. `de` yang lahir dari tiga suku bukan sekadar '
            .'kurang teliti — dia besaran yang berbeda.',
        );
    }

    /**
     * Kondisi ruangan yang diketik dengan KOMA dari HP tetap menghasilkan titik.
     *
     * Sesi Anak Timbangan pertama di produksi (5 Okt 2026) tersimpan dengan
     * `"22,1"` dan `"936,9"`. Blok ini tidak dibakukan, `is_numeric()` menolak
     * keduanya, kondisi ruangan terbaca kosong, dan SELURUH keping ditolak —
     * dengan pesan yang menyebut pengulangan, bukan koma.
     */
    public function test_kondisi_ruangan_berkoma_dari_hp_tetap_terhitung(): void
    {
        $blok = array_map(
            static fn (mixed $v): mixed => is_float($v) || is_int($v) ? str_replace('.', ',', (string) $v) : $v,
            self::blokSesi(),
        );
        $this->assertSame('933,2', $blok['tekanan_awal'], 'Payload uji harus benar-benar berkoma.');

        $sesi = $this->kirim($blok);

        $this->assertCount(
            2,
            $sesi->uncertaintyCalculations()->get(),
            'Kondisi ruangan berkoma membuat seluruh keping ditolak.',
        );
        $this->assertSame(
            '933.2',
            $sesi->spesifikasi_alat[AnakTimbanganMentah::KUNCI_SESI]['tekanan_awal'],
            'Koma di blok Anak Timbangan tidak dibakukan sebelum disimpan.',
        );
    }

    /**
     * No. Identitas per keping dari HP (`measurements[].no_identitas`) mendarat
     * di `identitas[titik_ke]`, dan keping KEMBAR yang diberi identitas terbit.
     *
     * Sebelum ini lembar HP tidak punya kotak identitas sama sekali, jadi set
     * yang punya keping kembar (2 g + 2 g, 20 g + 20 g, 200 g + 200 g — hampir
     * semua set) tidak pernah bisa menerbitkan titik kembarnya.
     *
     * Baris kosong di tengah membawa identitas `X`: barisnya tidak jadi keping,
     * identitasnya ikut dibuang, dan keping sesudahnya TIDAK bergeser nomor.
     */
    public function test_identitas_per_keping_dipetakan_ke_titik_dan_keping_kembar_terbit(): void
    {
        $abba = static fn (float $s, float $t): array => [
            'at_s1' => [$s, $s, $s],
            'at_t1' => [$t, $t, $t],
            'at_t2' => [$t, $t, $t],
            'at_s2' => [$s, $s, $s],
        ];

        $sesi = $this->kirim(keping: [
            ['titik_ukur' => 200.0, 'no_identitas' => 'A', ...$abba(199.9999, 199.9999)],
            ['titik_ukur' => 50.0, 'no_identitas' => 'X', 'at_s1' => [], 'at_t1' => [], 'at_t2' => [], 'at_s2' => []],
            ['titik_ukur' => 200.0, 'no_identitas' => ' B* ', ...$abba(199.9999, 200.0001)],
            ['titik_ukur' => 100.0, 'no_identitas' => '', ...$abba(100.0, 99.9999)],
        ]);

        $this->assertSame(
            [1 => 'A', 2 => 'B*'],
            $sesi->spesifikasi_alat[AnakTimbanganMentah::KUNCI_SESI]['identitas'],
            'Identitas tidak mendarat di titik_ke yang benar.',
        );

        $this->assertSame(
            [1, 2, 3],
            $sesi->uncertaintyCalculations()->orderBy('titik_ke')->pluck('titik_ke')->all(),
            'Kedua keping kembar 200 g (beridentitas) dan keping 100 g harus terbit.',
        );
    }

    /**
     * Kiriman tanpa `standard_id` (neraca dipilih lewat centang), dengan
     * hasil [ubah] ditimpakan ke payload. [ubah] dipanggil SESUDAH seeder,
     * supaya bisa menunjuk baris `standards` hasil seed. Balik respons mentahnya.
     *
     * @param  \Closure(): array<string, mixed>  $ubah
     */
    private function kirimMentah(\Closure $ubah): TestResponse
    {
        $this->seed(DatabaseSeeder::class);

        $contoh = CalibrationSession::where('nomor_sesi', 'DEMO-AT-001')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        return $this->actingAs($teknisi)->postJson('/api/calibrations', array_replace([
            'equipment_id' => $contoh->equipment_id,
            'thermohygro_standard_id' => $contoh->thermohygro_standard_id,
            'tanggal_kalibrasi' => '2026-09-11',
            'suhu_awal' => 23.1,
            'suhu_akhir' => 23.0,
            'kelembaban_awal' => 55.0,
            'kelembaban_akhir' => 56.0,
            'spesifikasi_alat' => [AnakTimbanganMentah::KUNCI_SESI => self::blokSesi()],
            'measurements' => self::keping(),
        ], $ubah()));
    }

    /** @return array{standard_id: int, dipakai: bool} */
    private static function centang(string $seri): array
    {
        return ['standard_id' => Standard::where('serial_number', $seri)->firstOrFail()->id, 'dipakai' => true];
    }

    /**
     * Bintang di NOMINAL (`20*`) — cara kertas membedakan keping kedua —
     * mendarat di `bintang[titik_ke]`, dan dua keping kembar 200 g / 200* g
     * terbit tanpa No. Seri.
     */
    public function test_bintang_nominal_dipetakan_ke_titik_dan_kembar_berbintang_terbit(): void
    {
        $abba = static fn (float $s, float $t): array => [
            'at_s1' => [$s, $s, $s], 'at_t1' => [$t, $t, $t],
            'at_t2' => [$t, $t, $t], 'at_s2' => [$s, $s, $s],
        ];

        $sesi = $this->kirim(keping: [
            ['titik_ukur' => 200.0, 'bintang' => false, ...$abba(199.9999, 199.9999)],
            ['titik_ukur' => 50.0, 'bintang' => true, 'at_s1' => [], 'at_t1' => [], 'at_t2' => [], 'at_s2' => []],
            ['titik_ukur' => 200.0, 'bintang' => true, ...$abba(199.9999, 200.0001)],
        ]);

        $this->assertEquals(
            [2 => true],
            $sesi->spesifikasi_alat[AnakTimbanganMentah::KUNCI_SESI]['bintang'],
            'Bintang tidak mendarat di titik_ke yang benar (baris kosong tidak boleh memakan nomor).',
        );
        $this->assertSame(
            [1, 2],
            $sesi->uncertaintyCalculations()->orderBy('titik_ke')->pluck('titik_ke')->all(),
            'Keping 200 g dan 200* g harus terbit tanpa No. Seri.',
        );
    }

    /**
     * Neraca diturunkan dari CENTANG "Standard yang Digunakan" lewat nomor
     * seri — dan menang atas isian `timbangan` lama di blok sesi.
     */
    public function test_neraca_diambil_dari_centang_standar(): void
    {
        $blok = self::blokSesi();
        $blok['timbangan'] = 'Electronic Balance Fujitsu';

        $respons = $this->kirimMentah(fn (): array => [
            'spesifikasi_alat' => [AnakTimbanganMentah::KUNCI_SESI => $blok],
            'standar_dicek' => [self::centang('1129063525')],
        ])->assertCreated();

        $sesi = CalibrationSession::findOrFail($respons->json('data.id'));

        $this->assertSame(
            'Analytical Balance',
            $sesi->spesifikasi_alat[AnakTimbanganMentah::KUNCI_SESI]['timbangan'],
        );
        $this->assertCount(2, $sesi->uncertaintyCalculations()->get());
    }

    /**
     * Tiga neraca dicentang (kertas lapangan 5 Okt 2026): kiriman DITERIMA, dan
     * tiap keping ditimbang di neraca tercentang terkecil yang sanggup
     * memikulnya — tercatat di jejak audit keping itu.
     */
    public function test_tiga_neraca_dicentang_tiap_keping_di_neraca_yang_sanggup(): void
    {
        $respons = $this->kirimMentah(fn (): array => [
            'standar_dicek' => [
                self::centang('C543502629'),
                self::centang('1129063525'),
                self::centang('SIDIK/134/2024'),
            ],
        ])->assertCreated();

        $sesi = CalibrationSession::findOrFail($respons->json('data.id'));
        $blok = $sesi->spesifikasi_alat[AnakTimbanganMentah::KUNCI_SESI];

        $this->assertEqualsCanonicalizing(
            ['Semi Micro Balance', 'Analytical Balance', 'Electronic Balance Fujitsu'],
            $blok['timbangan_daftar'],
        );
        $this->assertNull($blok['timbangan']);

        $jejak = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get()
            ->mapWithKeys(fn ($u): array => [(int) $u->titik_ke => collect($u->type_b_components)
                ->firstWhere('sumber', 'jejak_titik')['keterangan'] ?? '']);

        // keping() = 100 g, 50 g, (5 g tanpa T2 — tidak terbit).
        $this->assertStringContainsString('ditimbang di Analytical Balance', $jejak[1]);
        $this->assertStringContainsString('ditimbang di Semi Micro Balance', $jejak[2]);
    }

    /**
     * Pemeriksa persetujuan menghitung ulang sesi AT dari HP — yang neracanya
     * dari centang, TANPA `standard_id` per titik — tanpa peringatan palsu, dan
     * angka tersimpan yang diubah sesudahnya KETAHUAN.
     *
     * Sebelum 7 Okt 2026 pemeriksa menuntut standar per titik: sesi produksi
     * KAL/2026/10/0003 pulang dengan dua belas `standar_titik_hilang` dan hitung
     * ulangnya dilewati seluruhnya.
     */
    public function test_validator_menghitung_ulang_sesi_hp_tanpa_standar_titik(): void
    {
        $id = $this->kirimMentah(fn (): array => [
            'standar_dicek' => [self::centang('1129063525')],
        ])->assertCreated()->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);
        $this->assertNull($sesi->standard_id, 'Prasyarat: kiriman tanpa standar acuan sesi, seperti dari HP.');

        $kode = fn (): Collection => collect(app(CalibrationValidator::class)->periksa($sesi->fresh())['temuan'])
            ->pluck('kode');

        $this->assertNotContains('standar_titik_hilang', $kode());
        $this->assertNotContains('hitung_ulang_gagal', $kode());
        $this->assertNotContains('hitung_ulang_beda', $kode());

        // Bukti hitung ulangnya BENAR-BENAR jalan, bukan sekadar diam.
        $sesi->uncertaintyCalculations()->where('titik_ke', 1)->update(['rata_rata' => DB::raw('rata_rata + 0.001')]);

        $this->assertContains('hitung_ulang_beda', $kode());
    }

    /**
     * Lembar 2 kertas lapangan (sesi produksi KAL/2026/10/0001): keping 20 kg &
     * 10 kg, neraca Mettler 30 kg, bacaan ditulis dalam KG. Dengan Satuan `kg`
     * kiriman terhitung — dan angkanya SAMA PERSIS dengan kiriman yang sama
     * yang ditulis dalam gram.
     */
    public function test_satuan_kg_terhitung_sama_dengan_gram(): void
    {
        $lembar = [
            [20.0, 20.00180, 19.99075, 19.99093, 20.00180],
            [20.0, 20.00180, 20.00450, 20.00457, 20.00180],
            [10.0, 10.00060, 10.00698, 10.00703, 10.00060],
        ];
        $kirim = function (string $satuan, float $faktor) use ($lembar): CalibrationSession {
            $blok = self::blokSesi();
            $blok['kelas_uut'] = 'M2';
            $blok['kelas_standar'] = 'F1';
            $blok['satuan'] = $satuan;
            unset($blok['timbangan']);

            $id = $this->kirimMentah(fn (): array => [
                'tanggal_kalibrasi' => '2025-12-01',
                'spesifikasi_alat' => [AnakTimbanganMentah::KUNCI_SESI => $blok],
                'standar_dicek' => [self::centang('3127471')],
                'measurements' => array_map(fn (array $b): array => [
                    'titik_ukur' => $b[0] * $faktor,
                    'at_s1' => [$b[1] * $faktor],
                    'at_t1' => [$b[2] * $faktor],
                    'at_t2' => [$b[3] * $faktor],
                    'at_s2' => [$b[4] * $faktor],
                ], $lembar),
            ])->assertCreated()->json('data.id');

            return CalibrationSession::findOrFail($id);
        };

        $kg = $kirim('kg', 1.0);
        $gram = $kirim('g', 1000.0);

        $hasilKg = $kg->uncertaintyCalculations()->orderBy('titik_ke')->get();
        $hasilGram = $gram->uncertaintyCalculations()->orderBy('titik_ke')->get();

        $this->assertCount(3, $hasilKg, 'Ketiga keping kilogram harus terhitung.');

        foreach ($hasilKg as $i => $u) {
            $this->assertEqualsWithDelta((float) $hasilGram[$i]->rata_rata, (float) $u->rata_rata, 1e-6);
            $this->assertEqualsWithDelta((float) $hasilGram[$i]->ketidakpastian_diperluas, (float) $u->ketidakpastian_diperluas, 1e-9);
            $this->assertEqualsWithDelta((float) $hasilGram[$i]->titik_ukur, (float) $u->titik_ukur, 1e-9);
        }

        // Tersimpan apa adanya (kg), bukan dikalikan — simpan ulang draft tidak
        // boleh melipatgandakan angkanya.
        $this->assertSame(20.0, (float) $kg->rawMeasurements()->where('titik_ke', 1)->value('titik_ukur'));
        // Labelnya satuan yang diketik — layar periksa admin tidak menulis
        // `20,0018 g` untuk bacaan kilogram.
        // Disaring di koleksi, bukan `distinct()` di query: relasinya membawa
        // ORDER BY bawaan, dan MySQL strict menolak DISTINCT + ORDER BY kolom
        // yang tidak dipilih (SQLite meloloskannya — itu sebabnya dua suite).
        $this->assertSame(['kg'], $kg->rawMeasurements()->pluck('satuan')->unique()->values()->all());
        $this->assertSame(['g'], $gram->rawMeasurements()->pluck('satuan')->unique()->values()->all());

        // Sertifikat sesi kg dicetak dalam kg; sesi gram tidak berubah.
        $profil = new AnakTimbanganProfile;
        $cetak = $profil->cetakDalamSatuanAlat($kg);
        $this->assertSame('kg', $cetak['satuan']);
        $this->assertEqualsWithDelta(20.0018, ($cetak['ubah'])(20001.8, 1), 1e-12);
        $this->assertNull($profil->cetakDalamSatuanAlat($gram));
    }

    /**
     * Kapasitas Alat itu RENTANG (dari … sampai … g): ujung bawahnya tersimpan
     * di `kapasitas_min_g`, koma desimalnya dibakukan.
     */
    public function test_kapasitas_alat_tersimpan_sebagai_rentang(): void
    {
        $blok = self::blokSesi();
        $blok['kapasitas_min_g'] = '0,001';
        $blok['kapasitas_g'] = '500';

        $sesi = $this->kirim($blok);
        $simpan = $sesi->spesifikasi_alat[AnakTimbanganMentah::KUNCI_SESI];

        $this->assertSame('0.001', $simpan['kapasitas_min_g']);
        $this->assertEqualsWithDelta(
            0.001,
            AnakTimbanganMentah::blokSesi($sesi->spesifikasi_alat)['kapasitas_min_g'],
            1e-12,
        );
    }

    /**
     * Klien lama yang tidak mengirim `no_identitas` sama sekali tidak
     * menghapus identitas yang dikirim lewat blok sesi.
     */
    public function test_tanpa_kunci_no_identitas_identitas_blok_dibiarkan(): void
    {
        $blok = self::blokSesi();
        $blok['identitas'] = ['1' => 'LAMA-1'];

        $sesi = $this->kirim($blok);

        $this->assertSame(
            ['1' => 'LAMA-1'],
            $sesi->spesifikasi_alat[AnakTimbanganMentah::KUNCI_SESI]['identitas'],
        );
    }

    /**
     * Baris LAMA yang sudah tersimpan berkoma tetap terbaca di jalur hitung.
     *
     * Hitung ulang dan penyimpanan ulang membaca blok yang sudah ada di
     * database, jadi pembakuan di jalur simpan saja tidak menyelamatkan sesi
     * yang terlanjur tersimpan sebelum perbaikan.
     */
    public function test_blok_tersimpan_berkoma_tetap_terbaca(): void
    {
        $blok = AnakTimbanganMentah::blokSesi([AnakTimbanganMentah::KUNCI_SESI => [
            'kelas_uut' => 'M2',
            'kelas_standar' => 'F1',
            'kapasitas_g' => '2000',
            'suhu_awal' => '23',
            'suhu_akhir' => '22,1',
            'kelembaban_awal' => '60,6',
            'kelembaban_akhir' => '67,3',
            'tekanan_awal' => '936,9',
            'tekanan_akhir' => '937,0',
        ]]);

        $this->assertEqualsWithDelta(22.1, $blok['suhu']['akhir'], 1e-9);
        $this->assertEqualsWithDelta(60.6, $blok['kelembaban']['awal'], 1e-9);
        $this->assertEqualsWithDelta(936.9, $blok['tekanan']['awal'], 1e-9);
        $this->assertEqualsWithDelta(63.95, AnakTimbanganMentah::rataUjung('60,6', '67,3'), 1e-9);

        // Dua pemisah tetap DITOLAK, bukan ditebak — salah tebak menggeser
        // angka seribu kali tanpa error (lihat `AngkaDesimal`).
        $this->assertNull(AnakTimbanganMentah::rataUjung('1.234,5', '1'));
    }

    /**
     * Jalur hitung ulang memulangkan angka yang SAMA dengan jalur simpan.
     *
     * `kalibrasi:hitung-ulang` membaca dari `raw_measurements`, bukan dari
     * payload. Kalau kosakata perannya tidak sepakat antara dua jalur itu,
     * bedanya baru ketahuan berbulan-bulan kemudian — dan yang berubah angka di
     * sertifikat yang sudah terbit.
     */
    public function test_hitung_ulang_memulangkan_angka_yang_sama(): void
    {
        $sesi = $this->kirim();

        $sebelum = $sesi->uncertaintyCalculations()
            ->orderBy('titik_ke')
            ->pluck('ketidakpastian_diperluas', 'titik_ke')
            ->map(static fn ($x): float => (float) $x)
            ->all();

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$sesi->nomor_sesi]])->assertSuccessful();

        $sesudah = $sesi->refresh()->uncertaintyCalculations()
            ->orderBy('titik_ke')
            ->pluck('ketidakpastian_diperluas', 'titik_ke')
            ->map(static fn ($x): float => (float) $x)
            ->all();

        $this->assertSame(array_keys($sebelum), array_keys($sesudah));

        // Toleransinya 1e-8, yaitu SATU satuan di tempat terakhir kolom
        // `decimal(20,8)` — bukan angka yang dilonggarkan supaya lolos.
        //
        // Jalur simpan membulatkan lebih dulu lewat `bulatkanHitungan()`
        // (`desimalU95()` = 8); jalur hitung ulang menulis apa adanya dan
        // membiarkan kolomnya yang membulatkan. Di MySQL keduanya mendarat
        // identik karena kolomnya memang decimal. Di SQLite presisi desimal
        // diabaikan, jadi nilai mentahnya bertahan dan selisihnya muncul —
        // 2,1e-9 pada titik 1, di BAWAH resolusi kolom yang sebenarnya.
        //
        // Menyetel toleransinya lebih ketat dari presisi penyimpanan berarti
        // menguji sesuatu yang skemanya sendiri tidak menjanjikan; yang perlu
        // dijaga di sini kedua jalur sepakat sampai digit yang benar-benar
        // tersimpan.
        foreach ($sebelum as $titikKe => $u95) {
            $this->assertEqualsWithDelta($u95, $sesudah[$titikKe], 1e-8, "Titik {$titikKe} bergeser.");
        }
    }
}

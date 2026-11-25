<?php

namespace Tests\Unit;

use App\Services\Acuan\ParameterKeputusanMetode;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Registri parameter keputusan metode (`database/data/parameter-keputusan-metode.json`)
 * dan pintu bacanya.
 *
 * Yang dijaga, dan kenapa tiap butir bukan hiasan:
 *
 *  - BAWAAN = perilaku kode. Tanpa lingkup tanggal jawabannya selalu bawaan, jadi
 *    pembaca yang lupa membuka lingkup tidak pernah menggeser angka.
 *  - Penetapan hanya berlaku mulai `berlaku_mulai` (sesi lama tidak bergeser) dan
 *    tidak boleh berlaku surut: `tanggal_keputusan <= dicatat_pada <= berlaku_mulai`,
 *    dan `berlaku_mulai` tidak turun antar nomor penetapan satu parameter.
 *  - Penetapan APPEND-ONLY: ledger hash di `tests/Fixtures/parameter-metode-ledger.json`.
 *    Entri baru WAJIB didaftarkan di sana dalam PR yang sama; entri lama yang
 *    berubah atau hilang = merah.
 */
class ParameterKeputusanMetodeTest extends TestCase
{
    private const KUNCI_WAJIB = [
        'refractometer.sumber_deviasi_suhu',
        'spectrophotometer.max_stdev_holmium',
        'conductivity.nilai_acuan_larutan_1412',
        'sertifikat.tambah_hari.ph_meter',
        'sertifikat.tambah_hari.conductivity_meter',
        'sertifikat.tambah_hari.spectrophotometer',
        'sertifikat.tambah_hari.turbidimeter',
        'turbidimeter.tanda_minus_nol',
        'chlorine_meter.vonis_pass_fail',
        'ph_meter.vonis_pass_fail',
        'refractometer.vonis_pass_fail',
        'turbidimeter.vonis_pass_fail',
        'timbangan.sumber_ui_koreksi_kg',
        'refractometer.sumber_resolusi',
    ];

    protected function tearDown(): void
    {
        ParameterKeputusanMetode::lupakan();
        parent::tearDown();
    }

    private function gerbang(?array $isi = null): ParameterKeputusanMetode
    {
        ParameterKeputusanMetode::lupakan();

        if ($isi !== null) {
            ParameterKeputusanMetode::pakai($isi);
        }

        return app(ParameterKeputusanMetode::class);
    }

    /** @return array<string, mixed> */
    private function berkas(): array
    {
        return json_decode((string) file_get_contents(base_path(ParameterKeputusanMetode::BERKAS)), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Registri kecil untuk menguji pembaca tanpa menyentuh berkas append-only.
     *
     * @param  list<array<string, mixed>>  $penetapan
     * @return array<string, mixed>
     */
    private function fiktif(array $penetapan = [], string $lingkup = 'hitung'): array
    {
        return [
            'skema_versi' => 1,
            'parameter' => [[
                'kunci' => 'uji.pilihan',
                'profil' => 'refractometer',
                'lingkup' => $lingkup,
                'judul' => 'Parameter uji',
                'pertanyaan' => 'A atau B?',
                'butir_tm' => ['uji#1'],
                'pilihan' => [
                    ['kode' => 'a', 'label' => 'A', 'perilaku' => 'perilaku A', 'setara_master' => 'tidak', 'bukti' => 'kode:1', 'bukti_singkat' => 'kode:1'],
                    ['kode' => 'b', 'label' => 'B', 'perilaku' => 'perilaku B', 'setara_master' => 'ya', 'bukti' => 'sel:B1', 'bukti_singkat' => 'sel:B1', 'data' => ['x' => 7]],
                ],
                'bawaan' => 'a',
                'bukti_bawaan' => 'kode:1',
                'dampak' => 'uji',
                'penetapan' => $penetapan,
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function entri(int $nomor, string $mulai, string $nilai = 'b', ?string $keputusan = null, ?string $dicatat = null): array
    {
        return [
            'nomor' => $nomor,
            'berlaku_mulai' => $mulai,
            'dicatat_pada' => $dicatat ?? $mulai,
            'tanggal_keputusan' => $keputusan ?? $mulai,
            'nilai' => $nilai,
            'diputuskan_oleh' => 'Manajer Teknis uji',
            'alasan' => 'alasan uji yang cukup panjang',
            'rujukan' => 'lembar centang uji butir 1',
        ];
    }

    // ------------------------------------------------------------------ berkas nyata

    public function test_berkas_registri_sah_dan_memuat_keempat_belas_parameter(): void
    {
        $terperiksa = ParameterKeputusanMetode::periksa($this->berkas());

        $this->assertSame(self::KUNCI_WAJIB, array_keys($terperiksa['parameter']));
    }

    public function test_tanpa_lingkup_jawabannya_selalu_bawaan_kode(): void
    {
        $gerbang = $this->gerbang();

        foreach ($this->berkas()['parameter'] as $p) {
            $putusan = $gerbang->putusan($p['kunci']);

            $this->assertSame($p['bawaan'], $putusan->nilai, $p['kunci']);
            $this->assertTrue($putusan->bawaan, $p['kunci']);
            $this->assertSame(0, $putusan->nomorPenetapan, $p['kunci']);
            $this->assertNull($putusan->tanggalAcuan, $p['kunci']);
        }
    }

    public function test_tiap_pilihan_menyebut_sumber_bukti_dan_apakah_sama_dengan_master(): void
    {
        foreach ($this->berkas()['parameter'] as $p) {
            foreach ($p['pilihan'] as $pil) {
                $this->assertContains($pil['setara_master'], ['ya', 'tidak', 'sebagian'], "{$p['kunci']}.{$pil['kode']}");
                $this->assertNotSame('', trim($pil['bukti']), "{$p['kunci']}.{$pil['kode']} tanpa bukti");
                $this->assertMatchesRegularExpression('/@79280fc2|\.xlsm|\.xlsx|lembar[- ]centang/i', $pil['bukti'], "{$p['kunci']}.{$pil['kode']}: bukti harus menunjuk berkas/sel/hash");
            }

            $this->assertMatchesRegularExpression('/@79280fc2/', $p['bukti_bawaan'], "{$p['kunci']}: bukti bawaan wajib ber-hash");
        }
    }

    public function test_status_mengikuti_isi_penetapan(): void
    {
        $gerbang = $this->gerbang();

        foreach ($this->berkas()['parameter'] as $p) {
            $this->assertSame(
                $p['penetapan'] === [] ? 'menunggu_tm' : 'diputuskan',
                $gerbang->putusan($p['kunci'])->status,
                $p['kunci'],
            );
        }
    }

    /**
     * Ledger append-only. Entri lama berubah/hilang, atau entri baru tidak
     * didaftarkan = merah. Menambah entri = tambah SATU baris di ledger:
     * `"<kunci>": {"<nomor>": "<hash>"}` — hash dari
     * `ParameterKeputusanMetode::hashPenetapan($entri)`.
     */
    public function test_ledger_penetapan_append_only(): void
    {
        $ledger = json_decode((string) file_get_contents(base_path('tests/Fixtures/parameter-metode-ledger.json')), true, 512, JSON_THROW_ON_ERROR);
        $ada = [];

        foreach ($this->berkas()['parameter'] as $p) {
            foreach ($p['penetapan'] as $e) {
                $hash = ParameterKeputusanMetode::hashPenetapan($e);
                $ada[$p['kunci']][(string) $e['nomor']] = $hash;

                $this->assertSame(
                    $hash,
                    $ledger['penetapan'][$p['kunci']][(string) $e['nomor']] ?? null,
                    "Penetapan {$p['kunci']} #{$e['nomor']} belum ada di ledger atau isinya berubah. Entri lama TIDAK boleh disunting; "
                    ."entri baru didaftarkan di tests/Fixtures/parameter-metode-ledger.json dengan hash {$hash}.",
                );
            }
        }

        // Dua arah sekaligus: tak ada penetapan di registri yang luput dari ledger,
        // dan tak ada baris ledger yang hilang dari registri. Juga satu-satunya
        // pernyataan saat registri masih kosong (tanpa ini tes jadi 'risky').
        $this->assertSame($ada, $ledger['penetapan'], 'Ledger dan registri penetapan tidak sama persis.');

        foreach ($ledger['penetapan'] as $kunci => $perNomor) {
            foreach ($perNomor as $nomor => $hash) {
                $this->assertSame($hash, $ada[$kunci][(string) $nomor] ?? null, "Penetapan {$kunci} #{$nomor} ada di ledger tetapi hilang/berubah di registri.");
            }
        }
    }

    // ------------------------------------------------------------------ pembaca

    public function test_penetapan_berlaku_mulai_tanggalnya_dan_tidak_menggeser_sesi_lama(): void
    {
        $gerbang = $this->gerbang($this->fiktif([$this->entri(1, '2026-12-01')]));

        $sebelum = $gerbang->putusan('uji.pilihan', '2026-11-30');
        $this->assertSame('a', $sebelum->nilai);
        $this->assertTrue($sebelum->bawaan);

        foreach (['2026-12-01', '2026-12-02', '2027-06-01'] as $tanggal) {
            $putusan = $gerbang->putusan('uji.pilihan', $tanggal);
            $this->assertSame('b', $putusan->nilai, $tanggal);
            $this->assertFalse($putusan->bawaan);
            $this->assertSame(1, $putusan->nomorPenetapan);
            $this->assertSame(['x' => 7], $putusan->data);
            $this->assertSame('diputuskan', $putusan->status);
        }
    }

    public function test_penetapan_berikutnya_menang_dari_tanggalnya_dan_nomor_besar_menang_di_hari_sama(): void
    {
        $gerbang = $this->gerbang($this->fiktif([
            $this->entri(1, '2026-12-01', 'b'),
            $this->entri(2, '2027-01-10', 'a'),
            $this->entri(3, '2027-01-10', 'b'),
        ]));

        $this->assertSame('b', $gerbang->nilai('uji.pilihan', '2027-01-09'));
        $this->assertSame(3, $gerbang->putusan('uji.pilihan', '2027-01-10')->nomorPenetapan);
        $this->assertSame('b', $gerbang->nilai('uji.pilihan', '2027-01-10'));
    }

    public function test_lingkup_tanggal_menentukan_jawaban_dan_dilepas_walau_galat(): void
    {
        $gerbang = $this->gerbang($this->fiktif([$this->entri(1, '2026-12-01')]));

        $this->assertNull($gerbang->tanggalLingkup());
        $this->assertSame('a', $gerbang->nilai('uji.pilihan'));

        $dalam = $gerbang->untukTanggal('2026-12-05', fn (): string => $gerbang->nilai('uji.pilihan'));
        $this->assertSame('b', $dalam);

        // Bersarang: yang terdalam menang; keluar dari lingkup dalam kembali ke luar.
        $gerbang->untukTanggal('2026-12-05', function () use ($gerbang): void {
            $this->assertSame('a', $gerbang->untukTanggal('2026-11-01', fn (): string => $gerbang->nilai('uji.pilihan')));
            $this->assertSame('b', $gerbang->nilai('uji.pilihan'));
        });

        // Argumen eksplisit menang atas lingkup.
        $this->assertSame('a', $gerbang->untukTanggal('2026-12-05', fn (): string => $gerbang->nilai('uji.pilihan', '2026-11-01')));

        // Lingkup tanpa tanggal = bawaan.
        $this->assertSame('a', $gerbang->untukTanggal(null, fn (): string => $gerbang->nilai('uji.pilihan')));

        try {
            $gerbang->untukTanggal('2026-12-05', function (): void {
                throw new RuntimeException('galat di tengah hitungan');
            });
            $this->fail('galat seharusnya merambat');
        } catch (RuntimeException) {
            // lingkup wajib terlepas
        }

        $this->assertNull($gerbang->tanggalLingkup());
        $this->assertSame('a', $gerbang->nilai('uji.pilihan'));
    }

    public function test_tanggal_boleh_carbon_dan_datetime(): void
    {
        $gerbang = $this->gerbang($this->fiktif([$this->entri(1, '2026-12-01')]));

        $this->assertSame('b', $gerbang->nilai('uji.pilihan', CarbonImmutable::parse('2026-12-01 23:59:59')));
        $this->assertSame('a', $gerbang->nilai('uji.pilihan', new \DateTime('2026-11-30 23:59:59')));
        $this->assertSame('b', $gerbang->nilai('uji.pilihan', '2026-12-01T10:00:00+07:00'));
    }

    public function test_kunci_tak_dikenal_gagal_keras(): void
    {
        $gerbang = $this->gerbang($this->fiktif());

        $this->expectException(RuntimeException::class);
        $gerbang->putusan('tidak.ada');
    }

    public function test_tanggal_rusak_gagal_keras(): void
    {
        $gerbang = $this->gerbang($this->fiktif());

        $this->expectException(RuntimeException::class);
        $gerbang->putusan('uji.pilihan', '31/12/2026');
    }

    public function test_baris_jejak_hanya_untuk_parameter_hitung_milik_profil_itu(): void
    {
        $gerbang = $this->gerbang();
        $kunci = static fn (string $profil): array => array_column($gerbang->barisJejakProfil($profil), 'parameter');

        $this->assertSame(
            ['refractometer.sumber_deviasi_suhu', 'refractometer.vonis_pass_fail', 'refractometer.sumber_resolusi'],
            $kunci('refractometer'),
        );
        $this->assertCount(1, $gerbang->barisJejakProfil('spectrophotometer'));
        $this->assertCount(1, $gerbang->barisJejakProfil('conductivity_meter'));
        $this->assertSame(['ph_meter.vonis_pass_fail'], $kunci('ph_meter'));
        $this->assertSame(['chlorine_meter.vonis_pass_fail'], $kunci('chlorine_meter'));
        $this->assertSame(['timbangan.sumber_ui_koreksi_kg'], $kunci('timbangan'));
        // Parameter `cetak` (tanggal terbit, tanda minus nol) tidak ikut: nilainya beku di snapshot.
        $this->assertSame(['turbidimeter.vonis_pass_fail'], $kunci('turbidimeter'));
        $this->assertSame([], $gerbang->barisJejakProfil('micrometer'));
    }

    public function test_baris_jejak_memuat_nilai_versi_dan_kalimat_master(): void
    {
        $gerbang = $this->gerbang($this->fiktif([$this->entri(1, '2026-12-01')]));

        $baris = $gerbang->untukTanggal('2026-12-02', fn (): array => $gerbang->barisJejakProfil('refractometer'))[0];

        $this->assertSame('parameter_metode', $baris['sumber']);
        $this->assertSame('-', $baris['distribusi']);
        $this->assertNull($baris['nilai']);
        $this->assertSame('uji.pilihan', $baris['parameter']);
        $this->assertSame('b', $baris['pilihan']);
        $this->assertSame(1, $baris['penetapan']);
        $this->assertSame('2026-12-01', $baris['berlaku_mulai']);
        $this->assertSame('diputuskan', $baris['status']);
        $this->assertSame('2026-12-02', $baris['tanggal_acuan']);
        $this->assertStringContainsString('SAMA dengan workbook master', $baris['keterangan']);
        $this->assertStringContainsString('sel:B1', $baris['keterangan']);

        $bawaan = $gerbang->untukTanggal('2026-11-01', fn (): array => $gerbang->barisJejakProfil('refractometer'))[0];
        $this->assertStringContainsString('BERBEDA dari workbook master', $bawaan['keterangan']);
        $this->assertStringContainsString('bawaan kode', $bawaan['keterangan']);
        $this->assertSame(0, $bawaan['penetapan']);
    }

    public function test_semua_untuk_layar_studio_memuat_pilihan_yang_berlaku(): void
    {
        $gerbang = $this->gerbang($this->fiktif([$this->entri(1, '2026-12-01')]));

        $daftar = $gerbang->semua('2026-12-03');

        $this->assertCount(1, $daftar);
        $this->assertSame('b', $daftar[0]['berlaku']['nilai']);
        $this->assertSame('diputuskan', $daftar[0]['status']);
        $this->assertSame('2026-12-03', $daftar[0]['berlaku']['tanggal']);
        $this->assertCount(1, $daftar[0]['penetapan']);
    }

    // ------------------------------------------------------------------ urutan tanggal penetapan (temuan G1 tinjauan PR #298)

    /**
     * @param  list<array<string, mixed>>  $penetapan
     * @return array<string, mixed>
     */
    private function periksaFiktif(array $penetapan): array
    {
        return ParameterKeputusanMetode::periksa($this->fiktif($penetapan));
    }

    public function test_urutan_keputusan_dicatat_berlaku_yang_sah_diterima_termasuk_hari_yang_sama(): void
    {
        // Diputuskan 1 Des, dicatat 3 Des, berlaku 8 Des.
        $terperiksa = $this->periksaFiktif([$this->entri(1, '2026-12-08', 'b', '2026-12-01', '2026-12-03')]);
        $this->assertSame('2026-12-03', $terperiksa['parameter']['uji.pilihan']['penetapan'][0]['dicatat_pada']);

        // Ketiganya hari yang sama: batas tepat, bukan pelanggaran.
        $this->assertCount(1, $this->periksaFiktif([$this->entri(1, '2026-12-08', 'b', '2026-12-08', '2026-12-08')])['parameter']['uji.pilihan']['penetapan']);

        // berlaku_mulai tepat sama dengan dicatat_pada; dicatat_pada tepat sama dengan tanggal_keputusan.
        $this->assertCount(1, $this->periksaFiktif([$this->entri(1, '2026-12-03', 'b', '2026-12-03', '2026-12-03')])['parameter']['uji.pilihan']['penetapan']);
    }

    public function test_entri_tanpa_dicatat_pada_ditolak_dengan_pesan_yang_menyebutnya(): void
    {
        $entri = $this->entri(1, '2026-12-01');
        unset($entri['dicatat_pada']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('`dicatat_pada` bukan tanggal Y-m-d yang sah');

        $this->periksaFiktif([$entri]);
    }

    public function test_dicatat_pada_yang_bukan_tanggal_sah_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('`dicatat_pada` bukan tanggal Y-m-d yang sah');

        $this->periksaFiktif([[...$this->entri(1, '2026-12-01'), 'dicatat_pada' => '2026-02-31']]);
    }

    public function test_berlaku_mulai_sebelum_dicatat_pada_ditolak_sebagai_berlaku_surut(): void
    {
        // Diputuskan 1 Des, dicatat ke registri 5 Des, tetapi berlaku "1 Des": mundur dari hari pencatatan.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('berlaku_mulai sebelum dicatat_pada');

        $this->periksaFiktif([$this->entri(1, '2026-12-01', 'b', '2026-12-01', '2026-12-05')]);
    }

    public function test_berlaku_mulai_sehari_sebelum_dicatat_pada_sudah_ditolak(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('berlaku_mulai sebelum dicatat_pada');

        $this->periksaFiktif([$this->entri(1, '2026-12-04', 'b', '2026-12-01', '2026-12-05')]);
    }

    public function test_dicatat_pada_sebelum_tanggal_keputusan_ditolak(): void
    {
        // Dicatat 3 Des untuk keputusan yang baru diambil 5 Des: tanggal keputusan atau pencatatan salah.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dicatat sebelum diputuskan');

        $this->periksaFiktif([$this->entri(1, '2026-12-10', 'b', '2026-12-05', '2026-12-03')]);
    }

    public function test_berlaku_mulai_tidak_boleh_turun_antar_nomor_penetapan(): void
    {
        // Nomor 2 sah sendiri (dicatat 9 Jan, berlaku 9 Jan) tetapi berlaku SEBELUM nomor 1 (10 Jan).
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('berlaku_mulai turun dibanding penetapan sebelumnya (2027-01-09 < 2027-01-10)');

        $this->periksaFiktif([
            $this->entri(1, '2027-01-10', 'b'),
            $this->entri(2, '2027-01-09', 'a'),
        ]);
    }

    public function test_penurunan_dideteksi_juga_antara_nomor_dua_dan_tiga(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('penetapan #3 berlaku_mulai turun');

        $this->periksaFiktif([
            $this->entri(1, '2027-01-10', 'b'),
            $this->entri(2, '2027-02-10', 'a'),
            $this->entri(3, '2027-01-20', 'b'),
        ]);
    }

    public function test_berlaku_mulai_menaik_atau_sama_antar_nomor_diterima(): void
    {
        $terperiksa = $this->periksaFiktif([
            $this->entri(1, '2026-12-01', 'b'),
            $this->entri(2, '2027-01-10', 'a'),
            $this->entri(3, '2027-01-10', 'b'),
        ]);

        $this->assertCount(3, $terperiksa['parameter']['uji.pilihan']['penetapan']);
    }

    public function test_urutan_berlaku_mulai_dinilai_per_parameter_bukan_lintas_parameter(): void
    {
        $isi = $this->fiktif([$this->entri(1, '2027-03-01', 'b')]);

        $kedua = $this->fiktif([$this->entri(1, '2026-12-01', 'b')])['parameter'][0];
        $kedua['kunci'] = 'uji.pilihan_lain';
        $isi['parameter'][] = $kedua;

        $terperiksa = ParameterKeputusanMetode::periksa($isi);

        $this->assertSame(['uji.pilihan', 'uji.pilihan_lain'], array_keys($terperiksa['parameter']));
    }

    public function test_dicatat_pada_ikut_hash_ledger_jadi_mengubahnya_memutus_ledger(): void
    {
        $asli = $this->entri(1, '2026-12-08', 'b', '2026-12-01', '2026-12-03');
        $diubah = [...$asli, 'dicatat_pada' => '2026-12-04'];

        $this->assertNotSame(ParameterKeputusanMetode::hashPenetapan($asli), ParameterKeputusanMetode::hashPenetapan($diubah));
    }

    public function test_pembaca_tetap_memakai_berlaku_mulai_bukan_dicatat_pada(): void
    {
        // Dicatat 3 Des, berlaku 8 Des: sesi bertanggal 5 Des (sesudah dicatat, sebelum berlaku) tetap bawaan.
        $gerbang = $this->gerbang($this->fiktif([$this->entri(1, '2026-12-08', 'b', '2026-12-01', '2026-12-03')]));

        $this->assertSame('a', $gerbang->nilai('uji.pilihan', '2026-12-05'));
        $this->assertSame('b', $gerbang->nilai('uji.pilihan', '2026-12-08'));
    }

    // ------------------------------------------------------------------ penolakan registri rusak

    /**
     * @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>}>
     */
    public static function registriRusak(): array
    {
        return [
            'berlaku surut' => [static function (array $r): array {
                $r['parameter'][0]['penetapan'] = [[...self::entriStatik(1, '2026-12-01'), 'dicatat_pada' => '2026-12-05', 'tanggal_keputusan' => '2026-12-01']];

                return $r;
            }],
            'dicatat sebelum diputuskan' => [static function (array $r): array {
                $r['parameter'][0]['penetapan'] = [[...self::entriStatik(1, '2026-12-10'), 'dicatat_pada' => '2026-12-03', 'tanggal_keputusan' => '2026-12-05']];

                return $r;
            }],
            'tanpa dicatat_pada' => [static function (array $r): array {
                $entri = self::entriStatik(1, '2026-12-01');
                unset($entri['dicatat_pada']);
                $r['parameter'][0]['penetapan'] = [$entri];

                return $r;
            }],
            'berlaku_mulai turun antar nomor' => [static function (array $r): array {
                $r['parameter'][0]['penetapan'] = [self::entriStatik(1, '2027-01-10'), self::entriStatik(2, '2027-01-09')];

                return $r;
            }],
            'nomor loncat' => [static function (array $r): array {
                $r['parameter'][0]['penetapan'] = [self::entriStatik(2, '2026-12-01')];

                return $r;
            }],
            'nilai bukan pilihan' => [static function (array $r): array {
                $r['parameter'][0]['penetapan'] = [self::entriStatik(1, '2026-12-01', 'z')];

                return $r;
            }],
            'alasan terlalu pendek' => [static function (array $r): array {
                $r['parameter'][0]['penetapan'] = [[...self::entriStatik(1, '2026-12-01'), 'alasan' => 'ok']];

                return $r;
            }],
            'tanpa pelaku' => [static function (array $r): array {
                $r['parameter'][0]['penetapan'] = [[...self::entriStatik(1, '2026-12-01'), 'diputuskan_oleh' => '']];

                return $r;
            }],
            'tanggal tidak sah' => [static function (array $r): array {
                $r['parameter'][0]['penetapan'] = [[...self::entriStatik(1, '2026-12-01'), 'berlaku_mulai' => '2026-02-31']];

                return $r;
            }],
            'bawaan bukan pilihan' => [static function (array $r): array {
                $r['parameter'][0]['bawaan'] = 'z';

                return $r;
            }],
            'cuma satu pilihan' => [static function (array $r): array {
                $r['parameter'][0]['pilihan'] = [$r['parameter'][0]['pilihan'][0]];

                return $r;
            }],
            'kode pilihan ganda' => [static function (array $r): array {
                $r['parameter'][0]['pilihan'][1]['kode'] = 'a';

                return $r;
            }],
            'tanpa bukti singkat' => [static function (array $r): array {
                unset($r['parameter'][0]['pilihan'][1]['bukti_singkat']);

                return $r;
            }],
            'setara master salah' => [static function (array $r): array {
                $r['parameter'][0]['pilihan'][1]['setara_master'] = 'mungkin';

                return $r;
            }],
            'lingkup salah' => [static function (array $r): array {
                $r['parameter'][0]['lingkup'] = 'lain';

                return $r;
            }],
            'tanpa butir tm' => [static function (array $r): array {
                $r['parameter'][0]['butir_tm'] = [];

                return $r;
            }],
            'kunci ganda' => [static function (array $r): array {
                $r['parameter'][] = $r['parameter'][0];

                return $r;
            }],
            'kunci tanpa titik' => [static function (array $r): array {
                $r['parameter'][0]['kunci'] = 'tanpatitik';

                return $r;
            }],
            'penetapan bukan list' => [static function (array $r): array {
                $r['parameter'][0]['penetapan'] = ['satu' => 1];

                return $r;
            }],
        ];
    }

    /** @return array<string, mixed> */
    private static function entriStatik(int $nomor, string $mulai, string $nilai = 'b'): array
    {
        return [
            'nomor' => $nomor,
            'berlaku_mulai' => $mulai,
            'dicatat_pada' => $mulai,
            'tanggal_keputusan' => $mulai,
            'nilai' => $nilai,
            'diputuskan_oleh' => 'Manajer Teknis uji',
            'alasan' => 'alasan uji yang cukup panjang',
            'rujukan' => 'lembar centang uji butir 1',
        ];
    }

    /**
     * @param  callable(array<string, mixed>): array<string, mixed>  $rusakkan
     */
    #[DataProvider('registriRusak')]
    public function test_registri_rusak_ditolak_bukan_dibaca_sebagai_kosong(callable $rusakkan): void
    {
        $this->expectException(RuntimeException::class);

        ParameterKeputusanMetode::periksa($rusakkan($this->fiktif()));
    }
}

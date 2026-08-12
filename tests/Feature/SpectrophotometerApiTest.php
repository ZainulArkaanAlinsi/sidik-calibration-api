<?php

namespace Tests\Feature;

use App\Models\CalibrationCapability;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\Calibration\Profiles\SpectrophotometerProfile;
use App\Services\Calibration\SpectrophotometerCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jalur penuh Spectrophotometer lewat API: lembar kerja → simpan → preview →
 * approve → payload sertifikat.
 *
 * `SpectrophotometerBudgetTest` ngadu ANGKA-nya ke master; yang ini ngadu
 * PERJALANAN-nya. Bedanya penting: hitungan murni nggak pernah lewat kolom
 * `decimal(20,8)`, sementara di sini semuanya bolak-balik DB — dan justru di
 * situ dulu empat bug lolos dari suite yang hijau.
 *
 * Yang dijaga di sini:
 *
 *  - satu U95 per KELOMPOK beneran nyampe ke `uncertainty_calculations`, bukan
 *    cuma bener di memori;
 *  - preview & sesi tersimpan ngasih angka yang sama persis;
 *  - sertifikat MAKAI hasil yang udah dihitung, nggak ngitung ulang;
 *  - standar kadaluarsa ditolak, warning lolos tapi keperingatin;
 *  - lembar setengah jadi nggak pernah ngasilin NaN / `#DIV/0!`.
 */
class SpectrophotometerApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Toleransi banding = resolusi penyimpanannya. Kolom hasil hitung
     * `decimal(20,8)`, jadi 1e-8 itu sehalus-halusnya yang bisa dijanjiin DB —
     * bukan angka yang enak dilihat. Lihat catatan panjang di
     * `ConductivityBudgetTest::TOLERANSI_SIMPAN`.
     */
    private const TOLERANSI_SIMPAN = 1e-8;

    private const U95_HOLMIUM = 0.43255708;   // dibulatin decimal(20,8)

    private const U95_DIDYNIUM = 0.4;         // lantai CMC

    private const U95_TRANSMITAN = 0.5;       // lantai CMC

    private User $teknisi;

    private User $admin;

    private Equipment $alat;

    /** @var array<string, Standard> */
    private array $standar = [];

    protected function setUp(): void
    {
        parent::setUp();

        $org = Organization::factory()->create();

        $this->teknisi = User::factory()->create(['organization_id' => $org->id]);
        $this->admin = User::factory()->admin()->create(['organization_id' => $org->id]);

        $kategori = EquipmentCategory::factory()->create(['organization_id' => $org->id]);

        $filter = [
            SpectrophotometerCalculator::GRUP_HOLMIUM => ['Filter Standard 1', 'SPG080982.A', 0.1, 'nm'],
            SpectrophotometerCalculator::GRUP_DIDYNIUM => ['Filter Standard 2', 'SPG080982.B', 0.2, 'nm'],
            SpectrophotometerCalculator::GRUP_TRANSMITAN => ['Filter Standard 3', 'SPG080982.C', 0.5, '%T'],
        ];

        foreach ($filter as $grup => [$nama, $serial, $u, $satuan]) {
            $this->standar[$grup] = Standard::factory()->create([
                'organization_id' => $org->id,
                'nama' => $nama,
                'merk' => 'PG.Instrument',
                'serial_number' => $serial,
                'no_sertifikat' => $serial,
                'tertelusur_ke' => 'BSN-SNSU',
                'berlaku_sampai' => now()->addYear(),
                'ketidakpastian' => $u,
                'satuan_ketidakpastian' => $satuan,
                'faktor_cakupan' => 2,
                'koefisien_suhu' => null,
                'parameter_kondisi' => null,
            ]);
        }

        $cmc = [
            ['parameter' => 'panjang gelombang (nm)-Holmium', 'min' => 283, 'max' => 641, 'satuan' => 'nm', 'u' => 0.4],
            ['parameter' => 'panjang gelombang (nm)-Didynium', 'min' => 474, 'max' => 810, 'satuan' => 'nm', 'u' => 0.4],
            ['parameter' => 'akurasi (%T)', 'min' => 10, 'max' => 30.5, 'satuan' => '%T', 'u' => 0.5],
        ];

        foreach ($cmc as $c) {
            CalibrationCapability::create([
                'equipment_category_id' => $kategori->id,
                'nama_alat' => 'Spectrophotometer',
                'parameter' => $c['parameter'],
                'range_min' => $c['min'],
                'range_max' => $c['max'],
                'satuan' => $c['satuan'],
                'ketidakpastian_terbaik' => $c['u'],
                'satuan_ketidakpastian' => $c['satuan'],
                'faktor_cakupan' => 2,
                'metode' => SpectrophotometerProfile::KODE_DOKUMEN,
            ]);
        }

        $this->alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => Customer::factory()->create(['organization_id' => $org->id])->id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => 'Visible Spectrofotometer',
            'nama_alat_kemampuan' => 'Spectrophotometer',
            'merk' => 'Perkin Elmer',
            'model' => 'Lambda 25',
            'serial_number' => '501S13102801',
            'satuan' => 'nm',
            'resolusi' => 0.01,
            'resolusi_rentang' => [
                ['titik' => 0.0, 'resolusi' => 0.001, 'satuan' => '%T'],
                ['titik' => 9.9, 'resolusi' => 0.001, 'satuan' => '%T'],
                ['titik' => 20.0, 'resolusi' => 0.001, 'satuan' => '%T'],
                ['titik' => 30.1, 'resolusi' => 0.001, 'satuan' => '%T'],
                ['titik' => 100.0, 'resolusi' => 0.001, 'satuan' => '%T'],
            ],
            'toleransi' => null,
        ]);
    }

    /**
     * Tiga titik per kelompok — cukup buat ngunci perilaku per-kelompok tanpa
     * ngirim 24 baris di tiap tes.
     *
     * Pilihan titiknya BUKAN sembarang tiga. Ketidakpastian kelompok lahir dari
     * STDEV TERBESAR di kelompok itu, jadi tiap subset wajib bawa titik yang
     * megang STDEV maks — kalau nggak, uc/veff/k-nya beda dari master dan tes
     * ini ngunci angka yang nggak pernah kecetak di sertifikat mana pun:
     *
     *   Holmium  : 360,9 nm  — STDEV maks 0,14224 (PERHITUNGAN!M31)
     *   Didynium : 513,7 nm  — STDEV maks 0,15011 (PERHITUNGAN!M49)
     *   %T       : 9,9 %T    — STDEV maks, dan dia satu-satunya yang n = 6
     *
     * Dua titik sisanya per kelompok dipilih yang nilai sertifikatnya diadu ke
     * master di `test_rata_rata_dan_koreksi_tersimpan_cocok_master`.
     *
     * @return list<array<string, mixed>>
     */
    private function measurements(): array
    {
        return [
            ['titik_ukur' => 279.6, 'satuan' => 'nm', 'pembacaan' => [280, 280, 280]],
            ['titik_ukur' => 360.9, 'satuan' => 'nm', 'pembacaan' => [360.35, 360.59, 360.6]],
            ['titik_ukur' => 637.9, 'satuan' => 'nm', 'pembacaan' => [637.45, 637.18, 637.18]],
            ['titik_ukur' => 475.2, 'satuan' => 'nm', 'pembacaan' => [477.86, 477.93, 477.93]],
            ['titik_ukur' => 513.7, 'satuan' => 'nm', 'pembacaan' => [513.32, 513.58, 513.58]],
            ['titik_ukur' => 806.1, 'satuan' => 'nm', 'pembacaan' => [806.49, 806.35, 806.35]],
            ['titik_ukur' => 9.9, 'satuan' => '%T', 'pembacaan' => [9.668, 9.661, 9.666, 9.668, 9.661, 9.666]],
            ['titik_ukur' => 30.1, 'satuan' => '%T', 'pembacaan' => [29.25, 29.249, 29.249, 29.25, 29.249, 29.249]],
            ['titik_ukur' => 100.0, 'satuan' => '%T', 'pembacaan' => [100.004, 100.003, 100.001, 100.004, 100.003, 100.001]],
        ];
    }

    /**
     * @param  array<string, mixed>  $ubah
     * @return array<string, mixed>
     */
    private function payload(array $ubah = []): array
    {
        $standar = $this->standar;

        // Filter mana yang dipakai per titik ditentuin dari DAFTAR titiknya,
        // bukan dari besar-kecil angkanya. Rentang Holmium (283-641 nm) dan
        // Didynium (474-810 nm) tumpang tindih 167 nm, jadi 513,7 nm nggak bisa
        // dibedain dari 536,3 nm cuma lewat nilainya — persis alasan
        // `SpectrophotometerProfile` nyocokin CMC lewat kolom `parameter`.
        $didynium = [475.2, 513.7, 806.1];

        $measurements = array_map(
            static function (array $m) use ($standar, $didynium): array {
                $grup = match (true) {
                    $m['satuan'] === '%T' => SpectrophotometerCalculator::GRUP_TRANSMITAN,
                    in_array($m['titik_ukur'], $didynium, true) => SpectrophotometerCalculator::GRUP_DIDYNIUM,
                    default => SpectrophotometerCalculator::GRUP_HOLMIUM,
                };

                return [...$m, 'standard_id' => $standar[$grup]->id];
            },
            $this->measurements(),
        );

        return [
            'equipment_id' => $this->alat->id,
            'standard_id' => $this->standar[SpectrophotometerCalculator::GRUP_HOLMIUM]->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'suhu_awal' => 22.0,
            'suhu_akhir' => 22.0,
            'kelembaban_awal' => 57.0,
            'kelembaban_akhir' => 58.0,
            'measurements' => $measurements,
            ...$ubah,
        ];
    }

    /**
     * Titik hasil hitung, diindeks NILAI TITIKNYA — bukan `titik_ke`, biar
     * harapan tesnya kebaca sebagai "806,1 nm dapat 0,4" ketimbang "baris ke-6".
     *
     * Kuncinya dinormalin lewat `round()`, dan itu bukan kerapian: `titik_ukur`
     * itu kolom `decimal(20,8)`, dan dua DB proyek ini ngasih tipe PHP yang
     * BEDA buat isi yang sama — SQLite (tes) balikin float `279.6`, MySQL
     * (produksi) balikin string `"279.60000000"`. Nge-`keyBy('titik_ukur')`
     * mentah bikin tes ini cuma jalan di satu dari dua, dan yang jebol justru
     * yang produksi.
     *
     * @return \Illuminate\Support\Collection<string, \App\Models\UncertaintyCalculation>
     */
    private function titikTersimpan(CalibrationSession $sesi): \Illuminate\Support\Collection
    {
        return $sesi->uncertaintyCalculations()
            ->orderBy('titik_ke')
            ->get()
            ->keyBy(static fn ($baris): string => (string) round((float) $baris->titik_ukur, 2));
    }

    private function simpanSesi(array $ubah = []): CalibrationSession
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload($ubah))
            ->assertCreated();

        return CalibrationSession::latest('id')->firstOrFail();
    }

    public function test_lembar_kerja_nyodorin_tiga_tabel_kelompok(): void
    {
        $bentuk = $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?equipment_id='.$this->alat->id)
            ->assertOk()
            ->json('data');

        $this->assertSame(SpectrophotometerProfile::KODE_DOKUMEN, $bentuk['kode_dokumen']);

        $hasil = collect($bentuk['bagian'])->firstWhere('kode', 'hasil');
        $tabel = $hasil['tabel'];

        $this->assertCount(3, $tabel);
        $this->assertSame(
            [
                'Wave Length ( λ ) - Filter Holmium',
                'Wave Length ( λ ) - Filter Didynium',
                'Accuracy %T and Linierity at λ = 560nm',
            ],
            array_column($tabel, 'judul'),
        );

        // Jumlah titik per tabel persis kayak lembar master.
        $this->assertSame([10, 9, 5], array_map(static fn (array $t): int => count($t['baris']), $tabel));

        // Blok %T dapat ENAM kolom pengulangan, dua kelompok panjang gelombang
        // tiga — master nyetak dua baris X1..X3 per nilai standar %T.
        $this->assertSame([3, 3, 6], array_map(static fn (array $t): int => count($t['pengulangan']), $tabel));

        $this->assertSame(['nm', 'nm', '%T'], array_column($tabel, 'satuan'));

        // Tiap baris kebawa standarnya sendiri — teknisi nggak perlu milih
        // filter per titik.
        foreach ($tabel as $t) {
            foreach ($t['baris'] as $baris) {
                $this->assertNotNull($baris['standard_id'], "Baris {$baris['label']} nggak ketaut standar");
            }
        }

        $this->assertSame(
            $this->standar[SpectrophotometerCalculator::GRUP_DIDYNIUM]->id,
            $tabel[1]['baris'][0]['standard_id'],
        );
    }

    /**
     * Blok SRE muncul di lembar kerja sebagai bagian BERSTATUS, bukan ilang
     * diam-diam dan bukan kotak input yang bisa diisi. Kalau suatu hari ada
     * yang nambahin field ke situ tanpa sumber angka yang sah, tes ini jatuh.
     */
    public function test_bagian_sre_ada_tapi_ditandai_sumbernya_belum_ada(): void
    {
        $bentuk = $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?equipment_id='.$this->alat->id)
            ->assertOk()
            ->json('data');

        $sre = collect($bentuk['bagian'])->firstWhere('kode', 'sre');

        $this->assertNotNull($sre, 'Blok SRE mestinya tetap kelihatan, biar nggak dikira kelupaan');
        $this->assertSame('sumber_belum_ada', $sre['status']);
        $this->assertSame([], $sre['field']);
        $this->assertStringContainsString('#REF!', $sre['catatan']);
    }

    public function test_satu_u95_per_kelompok_nyampe_ke_database(): void
    {
        $sesi = $this->simpanSesi();

        $titik = $this->titikTersimpan($sesi);

        $this->assertCount(9, $titik);

        $harapan = [
            '279.6' => self::U95_HOLMIUM,
            '360.9' => self::U95_HOLMIUM,
            '637.9' => self::U95_HOLMIUM,
            '475.2' => self::U95_DIDYNIUM,
            '513.7' => self::U95_DIDYNIUM,
            '806.1' => self::U95_DIDYNIUM,
            '9.9' => self::U95_TRANSMITAN,
            '30.1' => self::U95_TRANSMITAN,
            '100' => self::U95_TRANSMITAN,
        ];

        foreach ($harapan as $nilai => $u95) {
            $baris = $titik[$nilai];

            $this->assertEqualsWithDelta(
                $u95,
                (float) $baris->ketidakpastian_diperluas,
                self::TOLERANSI_SIMPAN,
                "U95 titik {$nilai} meleset sesudah lewat DB",
            );

            // Nggak divonis PASS/FAIL — master nggak punya batas keberterimaan.
            $this->assertNull($baris->toleransi);
            $this->assertNull($baris->keputusan);
        }

        // k-nya juga ikut per kelompok, bukan satu angka buat seisi sesi.
        $this->assertEqualsWithDelta(3.18, (float) $titik['637.9']->faktor_cakupan_k, 0.005);
        $this->assertEqualsWithDelta(2.36, (float) $titik['806.1']->faktor_cakupan_k, 0.005);
        $this->assertEqualsWithDelta(2.01, (float) $titik['9.9']->faktor_cakupan_k, 0.005);
    }

    public function test_rata_rata_dan_koreksi_tersimpan_cocok_master(): void
    {
        $titik = $this->titikTersimpan($this->simpanSesi());

        $master = [
            '637.9' => [637.27, 0.63],
            '475.2' => [477.90666667, -2.70666667],
            '806.1' => [806.39666667, -0.29666667],
            '9.9' => [9.665, 0.235],
            '100' => [100.00266667, -0.00266667],
        ];

        foreach ($master as $nilai => [$rataRata, $koreksi]) {
            $this->assertEqualsWithDelta(
                $rataRata,
                (float) $titik[$nilai]->rata_rata,
                self::TOLERANSI_SIMPAN,
                "Rata-rata titik {$nilai} meleset sesudah lewat DB",
            );

            $this->assertEqualsWithDelta(
                $koreksi,
                (float) $titik[$nilai]->koreksi,
                self::TOLERANSI_SIMPAN,
                "Koreksi titik {$nilai} meleset sesudah lewat DB",
            );
        }
    }

    /**
     * Preview dipanggil tiap teknisi selesai ngisi satu baris. Kalau angkanya
     * beda tipis dari yang tersimpan, teknisi lihat satu angka di layar dan
     * angka lain kecetak di sertifikat — buat lab terakreditasi itu temuan
     * audit, bukan cuma nggak enak dilihat.
     *
     * ## Kenapa dibandingin dua tingkat, bukan satu `assertSame` gelondongan
     *
     * Versi pertama tes ini nge-`assertSame` seluruh `data.titik` sekaligus.
     * Hijau di SQLite, MERAH di MySQL — dan bedanya nyata:
     *
     *     - 'nilai' => 0.1184466611657662     (sesudah lewat MySQL)
     *     + 'nilai' => 0.11844666116576621    (preview, langsung dari hitungan)
     *
     * Sebabnya `uncertainty_calculations.type_b_components` itu kolom `json`
     * ASLI di MySQL: angkanya di-parse jadi DOUBLE waktu disimpen, lalu
     * di-serialize ulang pakai representasi terpendek versi MySQL waktu dibaca
     * — beda dari `serialize_precision=17` punya PHP, jadi digit ke-17-nya
     * berubah. SQLite nyimpen JSON-nya sebagai TEXT apa adanya, makanya di sana
     * bit-nya balik utuh dan bedanya nggak pernah kelihatan.
     *
     * Yang penting: **angka yang dilaporin nggak kesentuh**. Rata-rata, koreksi,
     * uc, veff, k, dan U95 semuanya kolom `decimal(20,8)`, bukan JSON — itu
     * sebabnya bagian itu tetap diadu pakai `assertSame` yang seketat-ketatnya.
     * Yang dilonggarin cuma rincian budget, dan cuma sebesar 1 ULP double —
     * empat kali lipat lebih halus dari desimal paling akhir yang pernah
     * kecetak di sertifikat mana pun.
     */
    public function test_angka_preview_identik_sama_yang_tersimpan(): void
    {
        $payload = $this->payload();

        $preview = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/preview', $payload)
            ->assertOk()
            ->json('data.titik');

        $tersimpan = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertCreated()
            ->json('data.titik');

        $this->assertCount(9, $preview);
        $this->assertSameSize($tersimpan, $preview);

        // Tingkat 1 — angka yang beneran dilaporin: wajib identik, bukan mirip.
        $dilaporkan = static fn (array $titik): array => array_diff_key($titik, ['type_b_components' => null]);

        $this->assertSame(
            array_map($dilaporkan, $tersimpan),
            array_map($dilaporkan, $preview),
            'Angka yang dilaporin ke teknisi beda dari yang kesimpen',
        );

        // Tingkat 2 — rincian budget: sama sampai batas presisi double.
        foreach ($preview as $i => $titik) {
            foreach ($titik['type_b_components'] as $j => $komponen) {
                $lawan = $tersimpan[$i]['type_b_components'][$j];

                $this->assertSame($komponen['sumber'], $lawan['sumber']);

                if (! isset($komponen['nilai'])) {
                    continue;
                }

                $this->assertEqualsWithDelta(
                    (float) $lawan['nilai'],
                    (float) $komponen['nilai'],
                    1e-15,
                    "Komponen budget {$komponen['sumber']} titik ke-".($i + 1).' meleset',
                );
            }
        }
    }

    /**
     * Sertifikat MAKAI hasil yang udah dihitung, bukan ngitung ulang. Yang
     * ngebuktiin: snapshot-nya dibandingin ke isi `uncertainty_calculations`
     * baris per baris.
     */
    public function test_payload_sertifikat_makai_hasil_yang_udah_dihitung(): void
    {
        $sesi = $this->simpanSesi();

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        $snapshot = Certificate::latest('id')->firstOrFail()->snapshot;

        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }

        $this->assertCount(9, $snapshot['hasil']);

        $tersimpan = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();

        foreach ($snapshot['hasil'] as $i => $baris) {
            $sumber = $tersimpan[$i];

            $this->assertSame((int) $sumber->titik_ke, $baris['titik_ke']);
            $this->assertEqualsWithDelta((float) $sumber->titik_ukur, $baris['standard_value'], self::TOLERANSI_SIMPAN);
            $this->assertEqualsWithDelta((float) $sumber->rata_rata, $baris['unit_under_test'], self::TOLERANSI_SIMPAN);
            $this->assertEqualsWithDelta((float) $sumber->koreksi, $baris['correction'], self::TOLERANSI_SIMPAN);
            $this->assertEqualsWithDelta((float) $sumber->ketidakpastian_diperluas, $baris['u95'], self::TOLERANSI_SIMPAN);
        }
    }

    /**
     * Kolom "Remark" yang misahin tiga blok hasil di dokumen — itu yang bikin
     * pembaca sertifikat tahu U95 0,4 nm itu punya Didynium, bukan Holmium.
     */
    public function test_sertifikat_ngelompokin_baris_lewat_kolom_remark(): void
    {
        $sesi = $this->simpanSesi();

        $this->actingAs($this->admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        $snapshot = Certificate::latest('id')->firstOrFail()->snapshot;

        if (is_string($snapshot)) {
            $snapshot = json_decode($snapshot, true);
        }

        $perRemark = collect($snapshot['hasil'])->groupBy('remark');

        $this->assertSame(
            [
                'Wave Length ( λ ) - Filter Holmium',
                'Wave Length ( λ ) - Filter Didynium',
                'Accuracy %T and Linierity at λ = 560nm',
            ],
            $perRemark->keys()->all(),
        );

        // Satuan per titik ikut dibekukan ke snapshot — lembar ini nyampur
        // nm & %T, jadi satuan alat yang tunggal nggak bisa jawab.
        $this->assertSame(['nm', 'nm', 'nm'], array_column($perRemark->first()->all(), 'satuan'));
        $this->assertSame(
            ['%T', '%T', '%T'],
            array_column($perRemark['Accuracy %T and Linierity at λ = 560nm']->all(), 'satuan'),
        );

        // Desimalnya juga ngikut resolusi kelompoknya: 2 buat nm, 3 buat %T.
        $this->assertSame(2, $perRemark->first()->first()['desimal']);
        $this->assertSame(3, $perRemark['Accuracy %T and Linierity at λ = 560nm']->first()['desimal']);
    }

    public function test_standar_kadaluarsa_ditolak(): void
    {
        $this->standar[SpectrophotometerCalculator::GRUP_DIDYNIUM]
            ->update(['berlaku_sampai' => now()->subDay()]);

        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload())
            ->assertStatus(422);
    }

    /**
     * Batas VALID / WARNING / EXPIRED buat filter spektro.
     *
     * Ambangnya punya backend (`Organization::DEFAULT_AMBANG_HARI` = 30), bukan
     * mobile — kalau HP nentuin sendiri, dia bisa nampilin VALID buat standar
     * yang bakal ditolak backend waktu approve, dan teknisi baru tau setelah
     * kerjaannya kelar.
     *
     * CATATAN soal titik "habis hari ini": `Standard::hariMenujuKadaluarsa()`
     * ngasih `0`, tapi `masihBerlaku()` mutusin EXPIRED — karena `berlaku_sampai`
     * di-cast `date`, jadi jam 00:00 hari ini dan `isFuture()`-nya false.
     * Docblock `hariMenujuKadaluarsa()` nyebut "0 = habis hari ini" yang
     * kebacanya masih berlaku. Tes ini ngunci perilaku yang BENERAN jalan;
     * kalau lab maunya sertifikat berlaku sampai akhir tanggalnya, yang diubah
     * `masihBerlaku()`, dan tes ini yang bakal ngasih tau.
     */
    public function test_status_standar_valid_warning_expired(): void
    {
        $ambang = Organization::DEFAULT_AMBANG_HARI;
        $standar = $this->standar[SpectrophotometerCalculator::GRUP_HOLMIUM];

        $standar->update(['berlaku_sampai' => now()->addDays($ambang + 1)]);
        $this->assertSame(Standard::STATUS_VALID, $standar->fresh()->statusKalibrasi($ambang));

        $standar->update(['berlaku_sampai' => now()->addDays($ambang)]);
        $this->assertSame(Standard::STATUS_WARNING, $standar->fresh()->statusKalibrasi($ambang));

        $standar->update(['berlaku_sampai' => now()->addDay()]);
        $this->assertSame(Standard::STATUS_WARNING, $standar->fresh()->statusKalibrasi($ambang));

        $standar->update(['berlaku_sampai' => now()]);
        $this->assertSame(Standard::STATUS_EXPIRED, $standar->fresh()->statusKalibrasi($ambang));

        $standar->update(['berlaku_sampai' => now()->subDay()]);
        $this->assertSame(Standard::STATUS_EXPIRED, $standar->fresh()->statusKalibrasi($ambang));

        // Standar yang WARNING masih bisa dipakai nyimpen sesi — diperingatin,
        // bukan dihalangin.
        $standar->update(['berlaku_sampai' => now()->addDays(10)]);
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $this->payload())
            ->assertCreated();
    }

    /**
     * Titik yang cuma keisi satu kotak nggak bisa punya standar deviasi — itu
     * yang di Excel jadi `#DIV/0!`. Di sini dia dilaporin sebagai
     * `belum_dihitung` yang kebaca, dan titik lain TETAP kehitung.
     */
    public function test_titik_kurang_pembacaan_dilaporin_bukan_bikin_div_nol(): void
    {
        $measurements = $this->measurements();
        $measurements[2]['pembacaan'] = [637.45, null, null];

        $preview = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/preview', $this->payload(['measurements' => array_map(
                fn (array $m, int $i): array => [
                    ...$m,
                    'standard_id' => $this->payload()['measurements'][$i]['standard_id'],
                ],
                $measurements,
                array_keys($measurements),
            )]))
            ->assertOk();

        $belum = $preview->json('data.belum_dihitung');

        $this->assertCount(1, $belum);
        $this->assertSame(3, $belum[0]['titik_ke']);
        $this->assertStringContainsString('minimal', $belum[0]['alasan']);

        // Delapan titik sisanya tetap keluar angkanya.
        $this->assertCount(8, $preview->json('data.titik'));

        // Dan U95 Holmium-nya ikut berubah — STDEV maksnya sekarang dari titik
        // yang tersisa. Yang penting: angkanya tetap angka, bukan NaN.
        foreach ($preview->json('data.titik') as $titik) {
            foreach (['rata_rata', 'koreksi', 'ketidakpastian_diperluas', 'faktor_cakupan_k'] as $kolom) {
                $this->assertIsNumeric($titik[$kolom], "Kolom {$kolom} bukan angka");
                $this->assertTrue(is_finite((float) $titik[$kolom]), "Kolom {$kolom} bukan angka terhingga");
            }
        }
    }

    /**
     * Lembar kerja yang dikirim kosong melompong nggak boleh ngasilin angka
     * apa pun — dan nggak boleh meledak juga. Teknisi harus tetap bisa nyimpen
     * draft dari lapangan.
     */
    public function test_lembar_kosong_nggak_ngasilin_angka_karangan(): void
    {
        $preview = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/preview', $this->payload([
                'measurements' => [
                    ['titik_ukur' => 637.9, 'satuan' => 'nm', 'pembacaan' => [null, null, null]],
                    ['titik_ukur' => 9.9, 'satuan' => '%T', 'pembacaan' => [null, null, null, null, null, null]],
                ],
            ]))
            ->assertOk();

        $this->assertSame([], $preview->json('data.titik'));
        $this->assertNull($preview->json('data.hasil'));
    }

    /**
     * Titik yang nggak ada di daftar lembar Spectrophotometer nggak dipaksa
     * masuk kelompok terdekat — dia dilaporin, dengan alasan yang kebaca.
     * Salah kelompok artinya U95 kelompok lain kecetak di sertifikat, dan itu
     * nggak keliatan salah dari dokumennya.
     */
    public function test_titik_asing_dilaporin_bukan_dipaksa_masuk_kelompok(): void
    {
        $payload = $this->payload();
        $payload['measurements'][] = [
            'titik_ukur' => 600.0,
            'satuan' => 'nm',
            'pembacaan' => [600.1, 600.2, 600.1],
            'standard_id' => $this->standar[SpectrophotometerCalculator::GRUP_HOLMIUM]->id,
        ];

        $preview = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations/preview', $payload)
            ->assertOk();

        $this->assertCount(9, $preview->json('data.titik'));

        $belum = $preview->json('data.belum_dihitung');

        $this->assertCount(1, $belum);
        $this->assertSame(10, $belum[0]['titik_ke']);
        $this->assertStringContainsString('600', $belum[0]['alasan']);
    }
}

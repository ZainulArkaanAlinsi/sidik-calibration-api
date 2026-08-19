<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Services\Calibration\Profiles\ViscometerProfile;
use Database\Seeders\ViscometerCapabilitySeeder;
use Database\Seeders\ViscometerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Jalur penuh Viscometer lewat API: lembar kerja → simpan → hitung → approve.
 *
 * `ViscometerBudgetTest` ngadu ANGKA-nya ke master; yang ini ngadu
 * PERJALANAN-nya. Bedanya bukan formalitas: hitungan murni nggak pernah lewat
 * kolom `decimal(20,8)` dan nggak pernah lewat `susunPengukuran()`, sementara
 * di sini semuanya bolak-balik DB — dan di situ dulu empat bug lolos dari
 * suite yang hijau.
 *
 * Yang khas Viscometer dan cuma kejaga di sini:
 *
 *  - `spindle` & `rpm` per titik beneran nyampe ke `raw_measurements`, dan
 *    dari situ jadi MPE. Alat ini `equipments.toleransi`-nya NULL — kalau
 *    dua kolom itu nggak nyampe, vonisnya bukan salah, tapi NGGAK ADA;
 *  - model visco (`spesifikasi_alat.model_visco`) nentuin TK sesi;
 *  - nilai acuan tiap titik diinterpolasi dari tabel sertifikat larutan pada
 *    suhu terukur, bukan diambil dari nominal botolnya.
 */
class ViscometerApiTest extends TestCase
{
    use RefreshDatabase;

    /** Sehalus-halusnya yang bisa dijanjiin kolom `decimal(20,8)`. */
    private const TOLERANSI_SIMPAN = 1e-8;

    private User $teknisi;

    private Equipment $alat;

    /** @var array<int, Standard> */
    private array $standar = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Seeder Viscometer nulis ke `organization_id => 1` (konvensi seluruh
        // seeder proyek ini), jadi organisasi & teknisinya disiapin duluan.
        //
        // **`id` dipatok 1, bukan diserahkan ke autoincrement.** Di SQLite
        // tiap test mulai dari database baru, jadi baris pertama selalu dapat
        // id 1 dan seeder-nya cocok tanpa ada yang perlu diminta. MySQL nggak
        // begitu: `RefreshDatabase` membungkus test dalam transaksi yang
        // di-rollback, dan rollback TIDAK mengembalikan penghitung
        // AUTO_INCREMENT. Test kedua dan seterusnya dapat organisasi ber-id 2,
        // 3, 4, … sementara seeder tetap menulis `organization_id => 1` — dan
        // FK `equipment_categories.organization_id` menolaknya.
        //
        // Yang gagal karenanya cuma di MySQL, yaitu satu-satunya database yang
        // dipakai produksi. Lihat `phpunit.mysql.xml`.
        $org = Organization::factory()->create(['id' => 1]);
        User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_TEKNISI]);

        // Sisanya dibangun lewat SEEDER yang beneran dipakai produksi, bukan
        // lewat factory yang disusun ulang di sini. Kalau seedernya salah,
        // yang merah test ini — bukan ketahuan nanti di lab.
        $this->seed([ViscometerCapabilitySeeder::class, ViscometerSeeder::class]);

        $this->alat = Equipment::where('serial_number', '8535682')->firstOrFail();
        $this->teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        foreach (ViscometerProfile::TITIK as $i => $t) {
            $this->standar[$i + 1] = Standard::where('nama', $t['standar'][0])->firstOrFail();
        }
    }

    /**
     * Lembar kerjanya berbentuk seperti kertas Rev.3: dua tahap × tiga titik ×
     * lima pengulangan, dan tiap sel minta pembacaan DAN suhu larutan.
     */
    public function test_lembar_kerja_bentuknya_ikut_kertas_rev3(): void
    {
        $data = $this->actingAs($this->teknisi, 'sanctum')
            ->getJson('/api/calibrations/lembar-kerja?profil=viscometer&equipment_id='.$this->alat->id)
            ->assertOk()
            ->json('data');

        $tabel = collect($data['bagian'] ?? [])
            ->flatMap(fn (array $b): array => $b['tabel'] ?? [])
            ->values();

        $this->assertCount(2, $tabel, 'Before & After Adjustment.');

        foreach ($tabel as $t) {
            $this->assertCount(3, $t['baris'], 'Tiga larutan standar: 100, 1000, 60000 cP.');
            $this->assertCount(5, $t['pengulangan'], 'Lima kolom pengulangan, ngikut kertas.');
        }

        // Blok 30000 cP TIDAK ikut: seluruh budget-nya `#DIV/0!` di master.
        $titik = collect($tabel[0]['baris'])->pluck('titik_ukur')->map(fn ($n): float => (float) $n);
        $this->assertEqualsCanonicalizing([99.65, 1018.0, 59003.0], $titik->all());

        $this->assertSame(
            ['pembacaan', 'suhu'],
            collect($tabel[0]['kolom'])->pluck('kode')->all(),
        );
    }

    /**
     * Sesi master, dikirim lewat API. Ketiga titiknya diadu ke `SERTIFIKAT.csv`
     * & `PERHITUNGAN U95%.csv`.
     */
    public function test_sesi_master_reproduksi_angka_workbook(): void
    {
        $sesi = $this->simpanSesi();
        $titik = $this->titikTersimpan($sesi);

        // Nilai acuan = interpolasi LINIER tabel sertifikat larutan pada suhu
        // rata-rata titik itu. Bukan nominal botolnya, bukan trendline kubik
        // yang tercetak di bawah tabelnya.
        $master = [
            1 => ['titik' => 93.87566510, 'rata' => 96.72, 'koreksi' => -2.84433490, 'mpe' => 4.14180317],
            2 => ['titik' => 910.28873239, 'rata' => 917.66, 'koreksi' => -7.37126761, 'mpe' => 22.07982581],
            3 => ['titik' => 61898.12, 'rata' => 63151.85, 'koreksi' => -1253.73, 'mpe' => 1921.84108065],
        ];

        foreach ($master as $ke => $harap) {
            $t = $titik[$ke];

            $this->assertEqualsWithDelta($harap['titik'], (float) $t->titik_ukur, self::TOLERANSI_SIMPAN);
            $this->assertEqualsWithDelta($harap['rata'], (float) $t->rata_rata, self::TOLERANSI_SIMPAN);
            $this->assertEqualsWithDelta($harap['koreksi'], (float) $t->koreksi, self::TOLERANSI_SIMPAN);

            // MPE lahir dari spindle & RPM titik itu — bukan dari
            // `equipments.toleransi`, yang buat alat ini NULL.
            $this->assertNull($this->alat->toleransi);
            $this->assertEqualsWithDelta($harap['mpe'], (float) $t->toleransi, self::TOLERANSI_SIMPAN);
            $this->assertSame('PASS', $t->keputusan);
        }

        // `uc` cocok persis sama master di dua titik pertama.
        $this->assertEqualsWithDelta(0.24649577, (float) $titik[1]->ketidakpastian_gabungan, self::TOLERANSI_SIMPAN);
        $this->assertEqualsWithDelta(1.35600158, (float) $titik[2]->ketidakpastian_gabungan, self::TOLERANSI_SIMPAN);
    }

    /**
     * Titik ketiga jatuh di 61898 cP, DI ATAS batas lingkup akreditasi
     * 58021 cP. Dua hal yang harus terjadi bareng — dan yang kedua yang
     * gampang jebol diam-diam:
     *
     *  1. nggak ada lantai CMC (yang dilaporkan hasil hitung, bukan 140 cP);
     *  2. budget-nya tetap EMPAT komponen. Tanpa baris kemampuan "di luar
     *     lingkup" di seeder, titik ini nggak nemu kemampuan apa pun dan jatuh
     *     ke jalur cadangan yang MEMBUANG komponen pengaruh suhu — `uc`
     *     mengecil ke 72,0053 dan sertifikatnya ngeklaim lebih bagus dari yang
     *     bisa dibuktikan.
     */
    public function test_titik_di_luar_lingkup_tetap_budget_penuh(): void
    {
        $titik = $this->titikTersimpan($this->simpanSesi())[3];

        $sumber = collect($titik->type_b_components)->pluck('sumber');
        $this->assertContains('ketidakpastian_temperature', $sumber);

        // Toleransinya SATU TINGKAT lebih longgar dari `TOLERANSI_SIMPAN`, dan
        // itu bukan kelonggaran asal — lihat
        // [test_batas_presisi_u_temperature_nggak_nyampe_angka_cetak].
        //
        // Singkatnya: `calibration_capabilities.u_temperature` kolomnya
        // `decimal(20,8)`, jadi MySQL menyimpan `0,36124784` untuk nilai yang
        // sebenarnya `0,36124783736376886`. Jalur hitung membacanya kembali
        // dari sana, jadi `uc` di MySQL 72,85796479 sementara di SQLite —
        // yang menyimpan angka penuh — 72,85796476. Selisih 2,5e-8.
        //
        // Yang dipatok di sini nilai SQLite (= nilai presisi penuh, yang cocok
        // dengan master), dengan toleransi yang cukup untuk menampung
        // pembulatan kolomnya. Yang menjaga bahwa selisih itu tidak pernah
        // tumbuh sampai mengubah sertifikat adalah test di bawah.
        $this->assertEqualsWithDelta(72.85796476, (float) $titik->ketidakpastian_gabungan, 1e-7);
        $this->assertEqualsWithDelta(144.16193105, (float) $titik->ketidakpastian_diperluas, 1e-7);

        $cmc = collect($titik->type_b_components)->firstWhere('sumber', 'perbandingan_cmc');
        $this->assertSame(0.0, (float) $cmc['nilai'], 'Nol = nggak ada klaim, bukan klaim nol.');
    }

    /**
     * Batas presisi `u_temperature` tidak pernah sampai ke angka yang dicetak.
     *
     * ## Bug yang dijaga di sini
     *
     * `calibration_capabilities.u_temperature` kolomnya `decimal(20,8)`.
     * Nilainya `√((0,72/2)² + (0,06/2)²) = 0,36124783736376886`, dan MySQL
     * memotongnya jadi `0,36124784` saat menyimpan. Jalur hitung membaca
     * kembali dari kolom itu, jadi yang beneran dipakai di **produksi** angka
     * yang sudah terpotong — bukan angka yang diadu ke workbook master.
     *
     * SQLite tidak memotongnya, jadi seluruh suite bisa hijau sementara
     * produksi menghitung dengan angka yang sedikit berbeda. Itu persis
     * bentuk kegagalan yang tidak pernah terlihat sampai ada yang
     * membandingkan sertifikat dengan Excel.
     *
     * ## Kenapa dibiarkan, bukan diperbaiki
     *
     * Selisihnya 2,5e-8 pada `uc` dan 5e-8 pada `U95` — sekitar 3e-10 relatif.
     * Sertifikat viscometer dicetak **dua desimal** dan resolusi alatnya
     * 0,1 cP, jadi selisih itu tidak punya jalan untuk muncul di dokumen.
     * Menaikkan presisi kolom berarti migrasi tabel produksi demi angka yang
     * tidak pernah terbaca siapa pun.
     *
     * Yang tidak boleh terjadi: selisih itu TUMBUH. Test ini yang menjaganya —
     * begitu pembulatan kolom mulai menggeser angka cetak, dia merah.
     */
    public function test_batas_presisi_u_temperature_nggak_nyampe_angka_cetak(): void
    {
        $titik = $this->titikTersimpan($this->simpanSesi());

        // Yang dicetak sertifikat: dua desimal (`ViscometerProfile::DESIMAL_SERTIFIKAT`).
        $cetak = [
            1 => ['u95' => '0.63', 'titik' => '93.88'],
            2 => ['u95' => '2.71', 'titik' => '910.29'],
            3 => ['u95' => '144.16', 'titik' => '61898.12'],
        ];

        foreach ($cetak as $ke => $harap) {
            $this->assertSame(
                $harap['u95'],
                number_format((float) $titik[$ke]->ketidakpastian_diperluas, 2, '.', ''),
                "U95 titik ke-{$ke} berubah di angka cetaknya.",
            );
            $this->assertSame(
                $harap['titik'],
                number_format((float) $titik[$ke]->titik_ukur, 2, '.', ''),
                "Nilai acuan titik ke-{$ke} berubah di angka cetaknya.",
            );
        }
    }

    /** Spindle & RPM nyimpen ke tiap baris pengukuran, bukan cuma dipakai sekilas. */
    public function test_spindle_dan_rpm_tersimpan_per_baris(): void
    {
        $sesi = $this->simpanSesi();

        foreach ([1 => ['HA1', 63.0], 2 => ['HA2', 62.0], 3 => ['HA7', 62.0]] as $ke => [$spindle, $rpm]) {
            $baris = $sesi->rawMeasurements()->where('titik_ke', $ke)->get();

            $this->assertNotEmpty($baris);

            foreach ($baris as $b) {
                $this->assertSame($spindle, $b->spindle);
                $this->assertEqualsWithDelta($rpm, (float) $b->rpm, 1e-9);
            }
        }
    }

    /**
     * Tanpa spindle/RPM, MPE NGGAK BOLEH dikarang. Yang benar: titiknya tetap
     * dihitung, tapi tanpa vonis — bukan PASS diam-diam terhadap batas yang
     * nggak pernah ada.
     */
    public function test_tanpa_spindle_dan_rpm_titiknya_nggak_divonis(): void
    {
        $sesi = $this->simpanSesi(fn (array $m): array => array_map(
            static function (array $t): array {
                unset($t['spindle'], $t['rpm']);

                return $t;
            },
            $m,
        ));

        foreach ($this->titikTersimpan($sesi) as $t) {
            $this->assertNull($t->toleransi, 'MPE nggak bisa dihitung tanpa spindle & RPM.');
            $this->assertNull($t->keputusan, 'Tanpa batas, nggak ada vonis — bukan PASS diam-diam.');
        }
    }

    /**
     * @param  (callable(list<array<string, mixed>>): list<array<string, mixed>>)|null  $ubahTitik
     */
    private function simpanSesi(?callable $ubahTitik = null): CalibrationSession
    {
        $measurements = [
            [
                'titik_ukur' => 99.65,
                'standard_id' => $this->standar[1]->id,
                'satuan' => 'cP',
                'spindle' => 'HA1',
                'rpm' => 63,
                'pembacaan' => [97.3, 96.9, 96.8, 95.9, 96.7],
                'suhu' => [26.6, 26.5, 26.5, 26.6, 26.4],
            ],
            [
                'titik_ukur' => 1018.0,
                'standard_id' => $this->standar[2]->id,
                'satuan' => 'cP',
                'spindle' => 'HA2',
                'rpm' => 62,
                'pembacaan' => [919.6, 918.7, 917.4, 916.3, 916.3],
                'suhu' => [27.3, 27.4, 27.2, 27.3, 27.3],
            ],
            [
                'titik_ukur' => 59003.0,
                'standard_id' => $this->standar[3]->id,
                'satuan' => 'cP',
                'spindle' => 'HA7',
                'rpm' => 62,
                // EMPAT pembacaan: sel ke-5 master isinya teks `631.74.2`.
                'pembacaan' => [63181.3, 63079.8, 63172.1, 63174.2],
                'suhu' => [24.6, 24.6, 24.6, 24.6],
            ],
        ];

        if ($ubahTitik !== null) {
            $measurements = $ubahTitik($measurements);
        }

        $id = $this->actingAs($this->teknisi, 'sanctum')
            ->postJson('/api/calibrations', [
                'equipment_id' => $this->alat->id,
                'standard_id' => $this->standar[1]->id,
                'input_method' => 'manual',
                'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
                'suhu_awal' => 25.2,
                'suhu_akhir' => 25.3,
                'kelembaban_awal' => 57.0,
                'kelembaban_akhir' => 58.0,
                // Model badan DV2THA → layar HA → TK 2 (Tabel D-2).
                'spesifikasi_alat' => ['model_visco' => 'DV2THA'],
                'measurements' => $measurements,
            ])
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }

    /** @return Collection<int, UncertaintyCalculation> */
    private function titikTersimpan(CalibrationSession $sesi): Collection
    {
        return $sesi->uncertaintyCalculations()
            ->orderBy('titik_ke')
            ->get()
            ->keyBy(static fn ($t): int => (int) $t->titik_ke);
    }
}

<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Services\Calibration\Profiles\ViscometerProfile;
use App\Services\CalibrationValidator;
use App\Services\DataTampilanSertifikat;
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

    /** @var array<string, Standard> */
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

        $this->alat = Equipment::where('serial_number', '86068360')->firstOrFail();
        $this->teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        // Dikunci lewat LABEL titik ('100', '1000', ...), bukan urutan array.
        // Waktu master 20 Agt 2026 menyisipkan larutan 3000 cP di posisi
        // ketiga, indeks numerik bikin sesi uji ini diam-diam memakai botol
        // 3000 cP untuk titik yang datanya 60000 cP — angkanya jadi salah
        // tanpa satu pun baris test yang berubah.
        foreach (ViscometerProfile::TITIK as $t) {
            $this->standar[$t['label']] = Standard::where('nama', $t['standar'][0])->firstOrFail();
        }
    }

    /**
     * Lembar kerjanya: dua tahap × LIMA titik × lima pengulangan, dan tiap sel
     * minta pembacaan DAN suhu larutan.
     *
     * Lima, bukan tiga, sejak master 20 Agt 2026 menghidupkan blok 3000 cP
     * (yang dulu `#DIV/0!` seluruhnya dan dikira "30000") dan menambah larutan
     * 100000 cP. Kertas Rev.3 masih mencetak tiga — kertasnya yang ketinggalan.
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
            $this->assertCount(5, $t['baris'], 'Lima larutan standar: 100, 1000, 3000, 60000, 100000 cP.');
            $this->assertCount(5, $t['pengulangan'], 'Lima kolom pengulangan, ngikut kertas.');
        }

        // Nilai NOMINAL @25 °C tiap larutan (`Tabel Pengaruh Temperature`).
        // Larutan yang dulu ditulis "30000 cP" di kertas ternyata 3000 cP —
        // nilainya 3987 pada 25 °C, bukan sesuatu di sekitar 30000.
        $titik = collect($tabel[0]['baris'])->pluck('titik_ukur')->map(fn ($n): float => (float) $n);
        $this->assertEqualsCanonicalizing([99.65, 1018.0, 3987.0, 59003.0, 99613.0], $titik->all());

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
        //
        // Titik 1 memakai U95 sertifikat larutan 0,169405 cP (0,17 %). Master
        // 20 Agt 2026 menulis 0,13 % di kolom itu dan sempat diikuti, tapi
        // serial botolnya sama di kedua berkas — jadi itu bukan lot baru.
        // Lihat `ViscometerSeeder::STANDAR` dan butir 10
        // `docs/pertanyaan-lab-viscometer.md`.
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
        //
        // `145,71592952` = `uc` x 2, sesudah `ViscometerProfile::
        // faktorCakupanTetap()` ngunci k. Kenapa masternya nulis `142,34053930`
        // di titik ini — dua selisih terpisah, `k` dan pembagi Type A —
        // ditelusuri lengkap di `ViscometerBudgetTest::
        // test_titik_di_luar_lingkup_tetap_budget_penuh_tanpa_lantai_cmc`.
        $this->assertEqualsWithDelta(145.71592952, (float) $titik->ketidakpastian_diperluas, 1e-7);

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
     * Sertifikat viscometer dicetak paling banyak **dua desimal** dan resolusi
     * alatnya 0,1 cP, jadi selisih itu tidak punya jalan untuk muncul di
     * dokumen.
     * Menaikkan presisi kolom berarti migrasi tabel produksi demi angka yang
     * tidak pernah terbaca siapa pun.
     *
     * Yang tidak boleh terjadi: selisih itu TUMBUH. Test ini yang menjaganya —
     * begitu pembulatan kolom mulai menggeser angka cetak, dia merah.
     */
    public function test_batas_presisi_u_temperature_nggak_nyampe_angka_cetak(): void
    {
        $titik = $this->titikTersimpan($this->simpanSesi());

        // Desimal cetaknya PER BARIS, dari format sel `SERTIFIKAT` C23:R27
        // master terbaru — `0.00` di baris 100 cP, `0.0` di 1000 & 60000 cP.
        // Lihat `ViscometerProfile::desimalSertifikatTitik()`.
        $cetak = [
            1 => ['desimal' => 2, 'u95' => '0.49', 'titik' => '93.88'],
            2 => ['desimal' => 1, 'u95' => '2.7', 'titik' => '910.3'],
            3 => ['desimal' => 1, 'u95' => '145.7', 'titik' => '61898.1'],
        ];

        $profil = new ViscometerProfile;

        foreach ($cetak as $ke => $harap) {
            $desimal = $profil->desimalSertifikatTitik((float) $titik[$ke]->titik_ukur);

            $this->assertSame($harap['desimal'], $desimal, "Desimal cetak titik ke-{$ke} bergeser.");
            $this->assertSame(
                $harap['u95'],
                number_format((float) $titik[$ke]->ketidakpastian_diperluas, $desimal, '.', ''),
                "U95 titik ke-{$ke} berubah di angka cetaknya.",
            );
            $this->assertSame(
                $harap['titik'],
                number_format((float) $titik[$ke]->titik_ukur, $desimal, '.', ''),
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
                'standard_id' => $this->standar['100']->id,
                'satuan' => 'cP',
                'spindle' => 'HA1',
                'rpm' => 63,
                'pembacaan' => [97.3, 96.9, 96.8, 95.9, 96.7],
                'suhu' => [26.6, 26.5, 26.5, 26.6, 26.4],
            ],
            [
                'titik_ukur' => 1018.0,
                'standard_id' => $this->standar['1000']->id,
                'satuan' => 'cP',
                'spindle' => 'HA2',
                'rpm' => 62,
                'pembacaan' => [919.6, 918.7, 917.4, 916.3, 916.3],
                'suhu' => [27.3, 27.4, 27.2, 27.3, 27.3],
            ],
            [
                'titik_ukur' => 59003.0,
                'standard_id' => $this->standar['60000']->id,
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
                'standard_id' => $this->standar['100']->id,
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

    /**
     * Sesi Viscometer bisa disetujui walau `equipments.toleransi` NULL.
     *
     * ## Bug yang dijaga di sini
     *
     * `CalibrationValidator` dulu nolak tiap sesi yang kolom `toleransi`
     * alatnya kosong, kecuali profilnya bilang alat itu emang nggak divonis
     * (Conductivity). Viscometer nggak masuk dua-duanya: dia DIVONIS, tapi
     * batasnya MPE per titik — `TK x SMC x 10000 / RPM x 0,013` — jadi kolom
     * alatnya sengaja NULL dan nggak ada satu angka yang bisa ditaruh di situ
     * tanpa ngarang.
     *
     * Akibatnya paling jahat karena rapi: sesinya kekirim, kehitung, kesimpen
     * lengkap sama U95 & vonis PASS per titik — lalu MENTOK di approve dengan
     * `boleh_terbit: false`. Sertifikatnya nggak pernah terbit, dan alasan yang
     * kebaca admin nunjuk kolom yang emang sengaja dikosongin.
     */
    public function test_toleransi_alat_null_nggak_nahan_approve(): void
    {
        $sesi = $this->simpanSesi();

        $this->assertNull($sesi->equipment->toleransi, 'Kolom alatnya emang sengaja NULL — lihat ViscometerProfile::toleransiDariKolomAlat().');

        $temuan = app(CalibrationValidator::class)->periksa($sesi);

        $this->assertNotContains(
            'toleransi_kosong',
            array_column($temuan['temuan'], 'kode'),
        );
        $this->assertTrue($temuan['boleh_terbit']);

        // Batasnya emang ada — cuma tempatnya per titik, bukan di kolom alat.
        foreach ($this->titikTersimpan($sesi) as $titik) {
            $this->assertNotNull($titik->toleransi);
        }
    }

    /**
     * KETIGA U95 nyampe ke lembar yang dipegang pelanggan, satu kolom per baris.
     *
     * ## Bug yang dijaga di sini
     *
     * Tabel hasil sertifikat dulu nutup tiap kelompok dengan SATU baris
     * `Uncertainty U95% = ±`, diambil dari baris pertama kelompoknya. Itu bener
     * buat lima alat lain — U95 Spectrophotometer lahir per KELOMPOK, jadi
     * sepuluh baris Holmium emang bawa angka yang sama persis.
     *
     * Viscometer nggak punya kelompok: ketiga titiknya masuk satu kelompok
     * tanpa remark, dan U95-nya BEDA-BEDA jauh — 0,49 / 2,7 / 145,7 cP.
     * Hasilnya cuma yang pertama yang kecetak; dua angka sisanya hilang dari
     * dokumen tanpa error, tanpa sel kosong, tanpa apa pun yang kelihatan
     * salah. Master lab sendiri nyetak kolom keempat `U95%, k=2` dengan satu
     * angka per baris (`SERTIFIKAT` R23:U27).
     *
     * Jumlah desimalnya juga per baris, dan itu ikut diadu di sini: `0,00` di
     * baris 100 cP, `0,0` di dua baris lain — dari format sel masternya, lihat
     * `ViscometerProfile::desimalSertifikatTitik()`. Angka yang benar dengan
     * bentuk yang salah tetap dokumen yang beda dari yang selama ini diterima
     * pelanggan.
     *
     * Diadu ke HTML yang BENERAN dirender, bukan ke snapshot: yang bikin bug
     * ini lolos justru jarak antara "angkanya bener di DB" dan "angkanya
     * kecetak".
     */
    public function test_tiga_u95_kecetak_satu_kolom_per_baris(): void
    {
        $sesi = $this->simpanSesi();

        // Admin dibikin di sini, bukan di `setUp()` — cuma test ini yang perlu
        // nembus approve, dan nambahin user ke setUp bikin test lain ikut punya
        // penghuni yang nggak mereka pakai.
        $admin = User::factory()->create([
            'organization_id' => $this->teknisi->organization_id,
            'role' => User::ROLE_ADMIN,
        ]);

        $this->actingAs($admin)
            ->postJson("/api/calibrations/{$sesi->id}/approve", ['abaikan_peringatan' => true])
            ->assertOk();

        $sertifikat = $sesi->fresh()->certificate()->firstOrFail();
        $this->assertSame(Certificate::STATUS_TERBIT, $sertifikat->status);

        $html = view('sertifikat.pdf', app(DataTampilanSertifikat::class)->untuk($sertifikat))->render();

        // Baris tabel hasil, selnya dipisah — bukan `assertStringContainsString`
        // buat tiap angka. `0,49` bisa aja nempel di tempat lain di dokumen;
        // yang diuji di sini POSISINYA, satu baris satu U95.
        preg_match_all('/<tr[^>]*>(.*?)<\/tr>/su', $html, $baris);

        $tabel = [];

        foreach ($baris[1] as $isi) {
            preg_match_all('/<td[^>]*>(.*?)<\/td>/su', $isi, $sel);

            $teks = array_map(
                static fn (string $s): string => trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s)))),
                $sel[1],
            );

            // Baris hasil = empat sel yang SEMUANYA angka bergaya Indonesia
            // (`-1253,73`). Kepala tabel, baris identitas, dan catatan kaki
            // nggak lolos saringan ini.
            $angka = fn (string $sel): bool => preg_match('/^-?\d+,\d+$/', $sel) === 1;

            if (count($teks) === 4 && count(array_filter($teks, $angka)) === 4) {
                $tabel[] = $teks;
            }
        }

        // Dua desimal cuma di baris pertama — sisanya satu, ikut format sel
        // masternya. Yang diuji bukan cuma angkanya, tapi juga bentuknya.
        $this->assertSame([
            ['93,88', '96,72', '-2,84', '0,49'],
            ['910,3', '917,7', '-7,4', '2,7'],
            ['61898,1', '63151,9', '-1253,7', '145,7'],
        ], $tabel);

        // Baris ringkas lamanya nggak boleh ikut kegambar — kalau dua-duanya
        // ada, U95 titik pertama kecetak dobel dan yang kedua kebaca kayak U95
        // buat SELURUH tabel.
        $this->assertStringNotContainsString('Uncertainty U', $html);
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

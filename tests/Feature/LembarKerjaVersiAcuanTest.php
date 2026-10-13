<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Organization;
use App\Models\PaketAcuanVersi;
use App\Models\User;
use App\Services\Acuan\SumberAcuan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PaketAcuanBaca;
use Tests\TestCase;

/**
 * FR-21 — `GET /calibrations/lembar-kerja` membawa `versi_acuan` + `ETag`.
 *
 * Yang dijaga:
 *  - `versi_acuan` = versi data yang berlaku HARI INI untuk profil ber-skema
 *    (hanya bila sumber `db`); `null` untuk profil lain — kuncinya tetap ada.
 *  - Lembar DIBANGUN di lingkup versi itu: nominal titik pra-cetak berasal
 *    dari versi yang sama dengan yang akan menghitung sesinya.
 *  - Field lama TIDAK berubah: sumber `db` + versi 1 (isi = JSON repo) dan
 *    sumber `json` memulangkan `data` yang identik selain `versi_acuan`.
 *  - `ETag` = sha256 badan final; `If-None-Match` sama → 304 tanpa badan.
 */
class LembarKerjaVersiAcuanTest extends TestCase
{
    use PaketAcuanBaca;
    use RefreshDatabase;

    private Organization $organisasi;

    private User $teknisi;

    private User $admin;

    private Equipment $micrometer;

    private PaketAcuanVersi $v1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisasi = Organization::factory()->create();
        $this->teknisi = $this->pengguna($this->organisasi, User::ROLE_TEKNISI);
        $this->admin = $this->pengguna($this->organisasi, User::ROLE_ADMIN);

        // Kapasitas 50 mm → varian kertas B (25–50 mm).
        $this->micrometer = Equipment::factory()->create([
            'organization_id' => $this->organisasi->id,
            'nama_alat_kemampuan' => 'Micrometer',
            'range_min' => 25,
            'range_max' => 50,
        ]);

        $this->v1 = $this->imporV1($this->organisasi);
    }

    public function test_etag_304(): void
    {
        config(['acuan.sumber' => 'db']);

        $pertama = $this->lembar($this->teknisi, true)->assertOk();
        $etag = (string) $pertama->headers->get('ETag');

        $this->assertSame('"'.hash('sha256', (string) $pertama->getContent()).'"', $etag);
        $this->assertTrue($pertama->headers->hasCacheControlDirective('private'));
        $this->assertTrue($pertama->headers->hasCacheControlDirective('no-cache'));

        $this->assertSame(
            ['paket' => 'micrometer', 'data' => ['nomor' => 1, 'sha256' => $this->v1->isi_sha256], 'bentuk' => null],
            $pertama->json('data.versi_acuan'),
        );

        $tetap = $this->lembar($this->teknisi, true, ['If-None-Match' => $etag]);
        $tetap->assertStatus(304);
        $this->assertSame('', (string) $tetap->getContent());
        $this->assertSame($etag, $tetap->headers->get('ETag'));

        // ETag lain → badan penuh lagi.
        $this->lembar($this->teknisi, true, ['If-None-Match' => '"bukan-etag-ini"'])
            ->assertOk()
            ->assertJsonPath('data.versi_acuan.paket', 'micrometer');

        // Badan beda = ETag beda (tanpa alat: varian kertas belum diketahui).
        $this->assertNotSame($etag, $this->lembar($this->teknisi, false)->headers->get('ETag'));
    }

    public function test_field_lama_identik(): void
    {
        foreach ([$this->teknisi, $this->admin] as $user) {
            foreach ([true, false] as $denganAlat) {
                config(['acuan.sumber' => 'json']);
                $json = $this->lembar($user, $denganAlat)->assertOk()->json('data');

                config(['acuan.sumber' => 'db']);
                $db = $this->lembar($user, $denganAlat)->assertOk()->json('data');

                $label = "{$user->role} ".($denganAlat ? 'dengan alat' : 'tanpa alat');

                $this->assertSame(['paket' => 'micrometer', 'data' => null, 'bentuk' => null], $json['versi_acuan'], $label);
                $this->assertSame(1, $db['versi_acuan']['data']['nomor'], $label);

                unset($json['versi_acuan'], $db['versi_acuan']);
                $this->assertSame($json, $db, "data selain versi_acuan identik — {$label}");

                // Kunci lama tetap di tempatnya.
                foreach (['judul', 'kode_metode', 'jumlah_pengulangan', 'bagian', 'alat_baru', 'pindai_foto'] as $kunci) {
                    $this->assertArrayHasKey($kunci, $db, "{$kunci} — {$label}");
                }
            }
        }
    }

    public function test_profil_tanpa_skema_versi_acuan_null(): void
    {
        config(['acuan.sumber' => 'db']);

        $respons = $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?profil=ph_meter')
            ->assertOk();

        $this->assertArrayHasKey('versi_acuan', $respons->json('data'));
        $this->assertNull($respons->json('data.versi_acuan'));
        $this->assertNotEmpty($respons->headers->get('ETag'));
    }

    public function test_belum_ada_versi_berlaku_data_null(): void
    {
        config(['acuan.sumber' => 'db']);
        $this->v1->update(['berlaku_mulai' => now()->addDay()->toDateString()]);

        $this->lembar($this->teknisi, true)
            ->assertOk()
            ->assertJsonPath('data.versi_acuan', ['paket' => 'micrometer', 'data' => null, 'bentuk' => null]);
    }

    public function test_lembar_dibangun_di_lingkup_versi_dan_etag_ikut_berubah(): void
    {
        config(['acuan.sumber' => 'db']);

        $lama = $this->lembar($this->teknisi, true)->assertOk();
        $this->assertTrue($this->adaTitik($lama, 32.7));
        $this->assertFalse($this->adaTitik($lama, 32.8));

        // v2 berlaku mulai hari ini; titik ke-4 varian B 32,7 → 32,8 (angka uji).
        $this->v1->update(['status' => PaketAcuanVersi::STATUS_PENSIUN, 'berlaku_sampai' => now()->subDay()->toDateString()]);
        $v2 = $this->versiBaru($this->v1, 2, PaketAcuanVersi::STATUS_AKTIF, now()->toDateString(), static function (array $isi): array {
            $isi['cmc'][1]['titik'][3]['nominal_cetak_mm'] = 32.8;

            return $isi;
        });
        SumberAcuan::lupakanCache();

        $baru = $this->lembar($this->teknisi, true)->assertOk();
        $this->assertSame(['nomor' => 2, 'sha256' => $v2->isi_sha256], $baru->json('data.versi_acuan.data'));
        $this->assertTrue($this->adaTitik($baru, 32.8), 'nominal pra-cetak dari versi yang berlaku');
        $this->assertFalse($this->adaTitik($baru, 32.7));
        $this->assertNotSame($lama->headers->get('ETag'), $baru->headers->get('ETag'));

        // ETag lama sudah basi → badan penuh, bukan 304.
        $this->lembar($this->teknisi, true, ['If-None-Match' => (string) $lama->headers->get('ETag')])->assertOk();

        // Sumber json: berkas repo, tanpa lingkup — nominal lama lagi.
        config(['acuan.sumber' => 'json']);
        $this->assertTrue($this->adaTitik($this->lembar($this->teknisi, true)->assertOk(), 32.7));
    }

    /** @param  array<string, string>  $header */
    private function lembar(User $user, bool $denganAlat, array $header = []): TestResponse
    {
        $query = $denganAlat ? 'equipment_id='.$this->micrometer->id : 'profil=micrometer';

        return $this->actingAs($user)
            ->withHeaders($header)
            ->getJson('/api/calibrations/lembar-kerja?'.$query);
    }

    /** Ada baris lembar dengan `titik_ukur` persis nilai ini? */
    private function adaTitik(TestResponse $respons, float $titik): bool
    {
        $ada = false;

        $cari = static function (mixed $node) use (&$cari, &$ada, $titik): void {
            if (! is_array($node)) {
                return;
            }

            if (array_key_exists('titik_ukur', $node) && $node['titik_ukur'] !== null && (float) $node['titik_ukur'] === $titik) {
                $ada = true;
            }

            foreach ($node as $anak) {
                $cari($anak);
            }
        };

        $cari($respons->json('data.bagian'));

        return $ada;
    }
}

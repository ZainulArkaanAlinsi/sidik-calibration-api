<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\PaketAcuan;
use App\Models\PaketAcuanVersi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RuteTerdaftar;
use Illuminate\Support\Facades\Route as Router;
use Tests\Concerns\PaketAcuanBaca;
use Tests\TestCase;

/**
 * Matriks izin Studio Data Acuan baris "lihat" (02-SRS §4), diuji lewat HTTP —
 * bukan dengan membaca kode controller.
 *
 *  - admin, viewer, super_admin: paket, semua versi, banding, riwayat sel.
 *  - teknisi: paket & versi `aktif` saja — draf/diajukan → 404 (bukan 403),
 *    tanpa hitungan draf, banding & riwayat 404.
 *  - organisasi lain: 404 di SEMUA rute ber-parameter. Rute dibaca dari
 *    daftar rute (prefix `api/data-acuan/`), jadi rute baca yang lahir besok
 *    ikut tersapu tanpa ada yang perlu mengingat. `SapuRuteIsolasiOrganisasiTest`
 *    belum menanam baris data acuan lab 2, jadi rute ini dijaga di sini.
 *  - tanpa token: 401.
 */
class IzinDataAcuanTest extends TestCase
{
    use PaketAcuanBaca;
    use RefreshDatabase;

    private const PENANDA_LAB2 = 'BOCOR-ACUAN-LAB2';

    private Organization $lab1;

    private Organization $lab2;

    private PaketAcuanVersi $v1;

    private PaketAcuanVersi $draf;

    private PaketAcuanVersi $diajukan;

    private PaketAcuanVersi $v1Lab2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lab1 = Organization::factory()->create();
        $this->lab2 = Organization::factory()->create();

        $this->v1 = $this->imporV1($this->lab1);
        $this->draf = $this->versiBaru($this->v1, 2, PaketAcuanVersi::STATUS_DRAF, ubah: self::ubahBalok('19.0', 19.00211));
        $this->diajukan = $this->versiBaru($this->v1, 3, PaketAcuanVersi::STATUS_DIAJUKAN, ubah: self::ubahBalok('19.0', 19.00311));

        // Lab 2: paket micrometer sendiri (kode yang SAMA — lab 1 harus tetap
        // mendapat paketnya sendiri) + paket berkode yang cuma ada di lab 2.
        $this->v1Lab2 = $this->imporV1($this->lab2);
        $this->v1Lab2->update(['alasan' => self::PENANDA_LAB2]);
        PaketAcuan::query()->where('organization_id', $this->lab2->id)->update(['nama' => self::PENANDA_LAB2]);

        PaketAcuan::create([
            'organization_id' => $this->lab2->id,
            'kode_profil' => 'khusus-lab2',
            'nama' => self::PENANDA_LAB2,
            'skema_versi' => 1,
        ]);
    }

    public function test_admin_viewer_super_admin_melihat_semua(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_VIEWER, User::ROLE_SUPER_ADMIN] as $role) {
            $user = $this->pengguna($this->lab1, $role);

            $daftar = $this->actingAs($user)->getJson('/api/data-acuan/paket')->assertOk()->json('data.0');
            $this->assertSame(1, $daftar['jumlah_draf'], $role);
            $this->assertArrayHasKey('versi_terjadwal', $daftar, $role);

            $detail = $this->actingAs($user)->getJson('/api/data-acuan/paket/micrometer')->assertOk()->json('data');
            $this->assertSame(1, $detail['jumlah_diajukan'], $role);

            foreach ([$this->v1, $this->draf, $this->diajukan] as $versi) {
                $this->actingAs($user)->getJson("/api/data-acuan/versi/{$versi->id}")->assertOk();
            }

            $this->assertCount(3, $this->actingAs($user)->getJson('/api/data-acuan/paket/micrometer/versi')->assertOk()->json('data'), $role);

            $this->actingAs($user)
                ->getJson("/api/data-acuan/versi/{$this->v1->id}/banding/{$this->draf->id}")
                ->assertOk()
                ->assertJsonPath('data.ringkasan.sel_berubah', 1);

            $this->actingAs($user)
                ->getJson("/api/data-acuan/versi/{$this->draf->id}/sel/balok_ukur/19.0/nilai_terkoreksi_mm/riwayat")
                ->assertOk()
                ->assertJsonPath('data.nilai', 19.00211);
        }
    }

    public function test_teknisi_hanya_versi_aktif(): void
    {
        $teknisi = $this->pengguna($this->lab1, User::ROLE_TEKNISI);

        $daftar = $this->actingAs($teknisi)->getJson('/api/data-acuan/paket')->assertOk()->json('data.0');
        $this->assertSame($this->v1->id, $daftar['versi_aktif']['id']);
        $this->assertArrayNotHasKey('jumlah_draf', $daftar);
        $this->assertArrayNotHasKey('versi_terjadwal', $daftar);

        $detail = $this->actingAs($teknisi)->getJson('/api/data-acuan/paket/micrometer')->assertOk()->json('data');
        $this->assertSame($this->v1->id, $detail['versi_aktif']['id']);
        $this->assertArrayNotHasKey('jumlah_draf', $detail);
        $this->assertArrayNotHasKey('jumlah_diajukan', $detail);
        $this->assertArrayNotHasKey('versi_terjadwal', $detail);

        $this->actingAs($teknisi)->getJson("/api/data-acuan/versi/{$this->v1->id}")->assertOk()->assertJsonPath('data.status', 'aktif');
        $this->actingAs($teknisi)->getJson("/api/data-acuan/versi/{$this->draf->id}")->assertNotFound();
        $this->actingAs($teknisi)->getJson("/api/data-acuan/versi/{$this->diajukan->id}")->assertNotFound();

        $garis = $this->actingAs($teknisi)->getJson('/api/data-acuan/paket/micrometer/versi')->assertOk()->json('data');
        $this->assertSame([$this->v1->id], array_column($garis, 'id'));

        $this->actingAs($teknisi)->getJson("/api/data-acuan/versi/{$this->v1->id}/banding/{$this->v1->id}")->assertNotFound();
        $this->actingAs($teknisi)
            ->getJson("/api/data-acuan/versi/{$this->v1->id}/sel/balok_ukur/19.0/nilai_terkoreksi_mm/riwayat")
            ->assertNotFound();

        $this->actingAs($teknisi)
            ->getJson('/api/data-acuan/paket/micrometer/berlaku?tanggal=2025-05-02')
            ->assertOk()
            ->assertJsonPath('data.id', $this->v1->id);

        // Versi yang berlaku di tanggal lampau sudah pensiun → tidak ditampilkan.
        $this->v1->update(['status' => PaketAcuanVersi::STATUS_PENSIUN, 'berlaku_sampai' => '2025-12-31']);
        $v4 = $this->versiBaru($this->v1, 4, PaketAcuanVersi::STATUS_AKTIF, '2026-01-01');

        $lampau = $this->actingAs($teknisi)->getJson('/api/data-acuan/paket/micrometer/berlaku?tanggal=2025-05-02')->assertOk();
        $this->assertNull($lampau->json('data'));
        $this->assertNotEmpty($lampau->json('alasan'));
        $this->actingAs($teknisi)->getJson("/api/data-acuan/versi/{$this->v1->id}")->assertNotFound();
        $this->actingAs($teknisi)->getJson("/api/data-acuan/versi/{$v4->id}")->assertOk();
    }

    /**
     * Tinjau P1 S-3: `berlaku` untuk teknisi tidak membocorkan versi non-aktif
     * lewat `alasan` — tidak ada status (`pensiun`) dan tidak ada tanggal
     * berlaku versi mana pun. Tanggal di versi pensiun, sebelum versi pertama,
     * dan jenis tanpa versi sama sekali dijawab dengan kalimat yang SAMA.
     * Admin, viewer, super admin tetap mendapat alasan rinci.
     */
    public function test_berlaku_teknisi_alasan_generik_tanpa_status_dan_tanggal(): void
    {
        $this->v1->update(['status' => PaketAcuanVersi::STATUS_PENSIUN, 'berlaku_sampai' => '2025-12-31']);
        $v4 = $this->versiBaru($this->v1, 4, PaketAcuanVersi::STATUS_AKTIF, '2026-01-01');
        $this->assertSame('2024-01-01', $this->v1->berlaku_mulai->toDateString());

        $url = '/api/data-acuan/paket/micrometer/berlaku?tanggal=';
        $teknisi = $this->pengguna($this->lab1, User::ROLE_TEKNISI);

        $tanya = [
            'di versi pensiun' => '2025-05-02',
            'sebelum versi pertama' => '2023-12-31',
            'jenis tanpa versi' => '2026-03-01&jenis=bentuk',
        ];

        foreach ($tanya as $kasus => $query) {
            $respons = $this->actingAs($teknisi)->getJson($url.$query)->assertOk();
            $alasan = (string) $respons->json('alasan');

            $this->assertNull($respons->json('data'), $kasus);
            $this->assertSame('Tidak ada versi aktif yang berlaku pada tanggal itu.', $alasan, $kasus);
            $this->assertStringNotContainsStringIgnoringCase('pensiun', $alasan, $kasus);
            $this->assertDoesNotMatchRegularExpression('/\d{4}-\d{2}-\d{2}/', $alasan, $kasus);
        }

        // Versi aktif tetap terlihat.
        $this->actingAs($teknisi)->getJson($url.'2026-03-01')->assertOk()->assertJsonPath('data.id', $v4->id);

        foreach ([User::ROLE_ADMIN, User::ROLE_VIEWER, User::ROLE_SUPER_ADMIN] as $role) {
            $user = $this->pengguna($this->lab1, $role);

            // Versi pensiun tetap dipulangkan apa adanya.
            $this->actingAs($user)->getJson($url.'2025-05-02')->assertOk()
                ->assertJsonPath('data.id', $this->v1->id)
                ->assertJsonPath('data.status', PaketAcuanVersi::STATUS_PENSIUN)
                ->assertJsonPath('alasan', null);

            // Alasan rinci: tanggal versi pertama disebut.
            $sebelum = $this->actingAs($user)->getJson($url.'2023-12-31')->assertOk();
            $this->assertNull($sebelum->json('data'), $role);
            $this->assertSame('Tanggal 2023-12-31 sebelum versi data pertama berlaku (2024-01-01).', $sebelum->json('alasan'), $role);
        }
    }

    public function test_organisasi_lain_404_di_semua_rute_berparameter(): void
    {
        $nilai = [
            'kode' => 'khusus-lab2',
            'versi' => $this->v1Lab2->id,
            'pembanding' => $this->v1Lab2->id,
            'lembar' => 'balok_ukur',
            'kunci' => '1.0',
            'kolom' => 'nilai_terkoreksi_mm',
        ];

        foreach ([User::ROLE_ADMIN, User::ROLE_VIEWER, User::ROLE_TEKNISI, User::ROLE_SUPER_ADMIN] as $role) {
            $user = $this->pengguna($this->lab1, $role);
            $disapu = 0;

            foreach ($this->ruteDataAcuan() as $rute) {
                if ($rute->parameterNames() === []) {
                    continue;
                }

                $uri = $this->isi($rute, $nilai).'?tanggal=2025-05-02';
                $respons = $this->actingAs($user)->getJson($uri);
                $disapu++;

                $this->assertSame(404, $respons->getStatusCode(), "{$role} GET {$uri}");
                $this->assertStringNotContainsString(self::PENANDA_LAB2, (string) $respons->getContent(), "{$role} GET {$uri}");
            }

            // Lantai: tanpa ini, rute yang hilang dari daftar bikin test hijau palsu.
            $this->assertGreaterThanOrEqual(6, $disapu, 'rute data-acuan ber-parameter yang tersapu');
        }

        // Campuran: versi sendiri dibanding versi lab lain.
        $admin = $this->pengguna($this->lab1, User::ROLE_ADMIN);
        $this->actingAs($admin)->getJson("/api/data-acuan/versi/{$this->v1->id}/banding/{$this->v1Lab2->id}")->assertNotFound();
        $this->actingAs($admin)->getJson("/api/data-acuan/versi/{$this->v1Lab2->id}/banding/{$this->v1->id}")->assertNotFound();

        // Kode yang sama di dua lab: lab 1 mendapat paketnya SENDIRI.
        $this->actingAs($admin)
            ->getJson('/api/data-acuan/paket/micrometer/versi')
            ->assertOk()
            ->assertJsonMissing(['id' => $this->v1Lab2->id]);

        $daftar = $this->actingAs($admin)->getJson('/api/data-acuan/paket')->assertOk();
        $this->assertStringNotContainsString(self::PENANDA_LAB2, (string) $daftar->getContent());
        $this->assertSame(['micrometer'], array_column($daftar->json('data'), 'kode'));

        // Percobaan membuka versi lab lain tercatat di lab pemanggil.
        $this->assertTrue(
            AuditLog::query()
                ->where('organization_id', $this->lab1->id)
                ->where('action', AuditLog::ACTION_AKSES_LINTAS_LAB)
                ->where('entity_type', 'paket_acuan_versi')
                ->exists(),
        );
    }

    public function test_tanpa_token_401(): void
    {
        $nilai = [
            'kode' => 'micrometer',
            'versi' => $this->v1->id,
            'pembanding' => $this->v1->id,
            'lembar' => 'balok_ukur',
            'kunci' => '1.0',
            'kolom' => 'nilai_terkoreksi_mm',
        ];

        $rute = $this->ruteDataAcuan();
        $this->assertGreaterThanOrEqual(7, count($rute));

        foreach ($rute as $r) {
            $this->getJson($this->isi($r, $nilai).'?tanggal=2025-05-02')->assertUnauthorized();
        }
    }

    public function test_izin_lihat_ada_di_matriks_semua_role_lab(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER, User::ROLE_SUPER_ADMIN] as $role) {
            $boleh = $this->actingAs($this->pengguna($this->lab1, $role))
                ->getJson('/api/me/permissions')
                ->assertOk()
                ->json('data.boleh');

            $this->assertContains('data-acuan.lihat', $boleh, $role);
            // Alur versi (T2.3): ajukan/simulasi = admin & super admin; aktifkan = super admin saja.
            $tulis = array_values(array_filter($boleh, static fn (string $i): bool => str_starts_with($i, 'data-acuan.') && $i !== 'data-acuan.lihat'));
            sort($tulis);
            $this->assertSame(match ($role) {
                User::ROLE_ADMIN => ['data-acuan.ajukan', 'data-acuan.simulasi'],
                User::ROLE_SUPER_ADMIN => ['data-acuan.ajukan', 'data-acuan.aktifkan', 'data-acuan.simulasi'],
                default => [],
            }, $tulis, $role);
        }
    }

    /** @return list<RuteTerdaftar> */
    private function ruteDataAcuan(): array
    {
        $hasil = [];

        foreach (Router::getRoutes() as $rute) {
            if (str_starts_with($rute->uri(), 'api/data-acuan/') && in_array('GET', $rute->methods(), true)) {
                $hasil[] = $rute;
            }
        }

        return $hasil;
    }

    /** @param  array<string, int|string>  $nilai */
    private function isi(RuteTerdaftar $rute, array $nilai): string
    {
        $uri = $rute->uri();

        foreach ($rute->parameterNames() as $nama) {
            $this->assertArrayHasKey($nama, $nilai, "parameter {{$nama}} di {$uri} belum punya nilai uji");
            $uri = str_replace('{'.$nama.'}', (string) $nilai[$nama], $uri);
        }

        return '/'.$uri;
    }
}

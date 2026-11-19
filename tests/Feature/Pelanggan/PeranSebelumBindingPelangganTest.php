<?php

namespace Tests\Feature\Pelanggan;

use App\Http\Middleware\FiturPelanggan;
use App\Http\Middleware\KonteksPerusahaan;
use App\Http\Middleware\PastikanAkunStudio;
use App\Http\Middleware\PelangganAktif;
use App\Http\Middleware\PeranAnggota;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\UndanganPelanggan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route as RuteTerdaftar;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * QA ronde 2-B, butir 1: penjaga dunia pelanggan (dan Studio) jalan SEBELUM
 * route model binding — kelas yang sama dengan R2-7, di rute lain.
 *
 * `fitur.pelanggan`, `pelanggan.aktif`, `perusahaan`, dan `peran:pic_utama` dulu
 * berjalan sesudah `SubstituteBindings`. Akibatnya anggota non-PIC yang menembak
 * `/anggota/{anggota}/nonaktifkan` atau `/anggota/undangan/{undangan}` mendapat
 * 403 untuk ID yang ADA (milik perusahaan mana pun) dan 404 untuk yang tidak
 * ada: oracle keberadaan ID lintas perusahaan (AGENTS.md §Modul Pelanggan 4).
 *
 * Yang dijaga: pemanggil yang gagal di gerbang mana pun menerima jawaban gerbang
 * itu yang SERAGAM untuk ID mana pun — ada milik sendiri, ada milik perusahaan
 * lain, atau tidak ada. Pemanggil yang berhak tidak berubah: perusahaan lain dan
 * tidak ada tetap 404.
 */
class PeranSebelumBindingPelangganTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private const PREFIX = '/api/pelanggan/v1';

    private const TAK_ADA = 99999999;

    private User $pic;

    private User $staf;

    private Customer $perusahaanA;

    /** @var array<string, array{0: string, 1: string}> nama rute => [metode, uri] untuk tiga jenis ID */
    private array $milik = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();

        $this->pic = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $this->perusahaanA = $this->pic->keanggotaan()->first()->customer;

        $this->staf = $this->pelanggan();
        CustomerMember::create([
            'organization_id' => $this->perusahaanA->organization_id,
            'customer_id' => $this->perusahaanA->getKey(),
            'user_id' => $this->staf->getKey(),
            'peran' => CustomerMember::PERAN_STAF,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        // Perusahaan B: orang lain, lengkap dengan anggota dan undangannya.
        $picB = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $perusahaanB = $picB->keanggotaan()->first()->customer;

        $this->milik = [
            'undangan' => [
                'sendiri' => (string) UndanganPelanggan::factory()->create([
                    'organization_id' => $this->perusahaanA->organization_id,
                    'customer_id' => $this->perusahaanA->getKey(),
                ])->getKey(),
                'lain' => (string) UndanganPelanggan::factory()->create([
                    'organization_id' => $perusahaanB->organization_id,
                    'customer_id' => $perusahaanB->getKey(),
                ])->getKey(),
                'tidak_ada' => (string) self::TAK_ADA,
            ],
            'anggota' => [
                // Yang ditonaktifkan PIC = staf A (bukan dirinya: PIC utama terakhir tidak boleh mundur).
                'sendiri' => (string) $this->staf->keanggotaan()->first()->getKey(),
                'lain' => (string) $picB->keanggotaan()->first()->getKey(),
                'tidak_ada' => (string) self::TAK_ADA,
            ],
        ];
    }

    /** @return list<array{0: string, 1: string}> [metode, template uri] dua rute pelanggan yang memakai binding model */
    private function ruteBinding(): array
    {
        return [
            ['DELETE', '/anggota/undangan/{id}'],
            ['POST', '/anggota/{id}/nonaktifkan'],
        ];
    }

    private function kunciMilik(string $template): string
    {
        return str_contains($template, 'undangan') ? 'undangan' : 'anggota';
    }

    private function tembak(User $pelaku, string $metode, string $template, string $id, array $header = []): TestResponse
    {
        $this->permintaanBaru();

        return $this->withHeaders($header + $this->bearer($pelaku))
            ->json($metode, self::PREFIX.str_replace('{id}', $id, $template));
    }

    /**
     * INTI: staf (bukan PIC utama) mendapat 403 `bukan_pic_utama` untuk ID mana
     * pun — milik sendiri, milik perusahaan lain, maupun yang tidak ada. Dulu:
     * ada → 403, tidak ada → 404.
     */
    public function test_staf_dijawab_403_seragam_untuk_id_sendiri_lain_dan_tidak_ada(): void
    {
        foreach ($this->ruteBinding() as [$metode, $template]) {
            $badan = [];

            foreach ($this->milik[$this->kunciMilik($template)] as $jenis => $id) {
                $respons = $this->tembak($this->staf, $metode, $template, $id);

                $this->assertSame(403, $respons->getStatusCode(), "staf {$metode} {$template} ID {$jenis}: harus 403 seragam.");
                $respons->assertJsonPath('kode', 'bukan_pic_utama');
                $badan[$jenis] = $respons->getContent();
            }

            $this->assertCount(1, array_unique($badan), "{$metode} {$template}: badan jawaban staf berbeda antar jenis ID.");
        }

        // Tidak ada yang berubah di database.
        $this->assertDatabaseHas('customer_members', ['id' => $this->milik['anggota']['sendiri'], 'status' => CustomerMember::STATUS_AKTIF]);
        $this->assertDatabaseHas('customer_members', ['id' => $this->milik['anggota']['lain'], 'status' => CustomerMember::STATUS_AKTIF]);
        $this->assertNull(UndanganPelanggan::query()->findOrFail((int) $this->milik['undangan']['sendiri'])->dibatalkan_pada);
    }

    /** Yang BERHAK tidak berubah: milik perusahaan lain dan tidak ada → 404, milik sendiri → berhasil. */
    public function test_pic_utama_tetap_404_untuk_perusahaan_lain_dan_tidak_ada_dan_berhasil_untuk_sendiri(): void
    {
        foreach ($this->ruteBinding() as [$metode, $template]) {
            $milik = $this->milik[$this->kunciMilik($template)];

            $this->tembak($this->pic, $metode, $template, $milik['lain'])->assertNotFound();
            $this->tembak($this->pic, $metode, $template, $milik['tidak_ada'])->assertNotFound();
            $this->tembak($this->pic, $metode, $template, $milik['sendiri'])->assertOk();
        }

        $this->assertDatabaseHas('customer_members', ['id' => $this->milik['anggota']['lain'], 'status' => CustomerMember::STATUS_AKTIF]);
        $this->assertSame(CustomerMember::STATUS_NONAKTIF, CustomerMember::query()->findOrFail((int) $this->milik['anggota']['sendiri'])->status);
    }

    /**
     * Akun yang masih menunggu verifikasi (`pelanggan.aktif`) mendapat
     * `akun_belum_diverifikasi` seragam — bukan 404 untuk ID yang tidak ada.
     */
    public function test_akun_belum_diverifikasi_dijawab_seragam_untuk_semua_id(): void
    {
        $menunggu = $this->pelanggan(User::STATUS_PENDING_VERIFIKASI);

        foreach ($this->ruteBinding() as [$metode, $template]) {
            foreach ($this->milik[$this->kunciMilik($template)] as $jenis => $id) {
                $this->tembak($menunggu, $metode, $template, $id)
                    ->assertForbidden()
                    ->assertJsonPath('kode', 'akun_belum_diverifikasi');
            }
        }
    }

    /**
     * Pemegang beberapa keanggotaan tanpa `X-Perusahaan-Id` (`perusahaan`) mendapat
     * 400 `perusahaan_belum_dipilih` seragam. Dulu: ID ada → 400, tidak ada → 404.
     */
    public function test_banyak_keanggotaan_tanpa_header_dijawab_400_seragam(): void
    {
        $kedua = Customer::factory()->create([
            'organization_id' => $this->perusahaanA->organization_id,
            'nama' => 'PT Keanggotaan Kedua',
        ]);
        CustomerMember::create([
            'organization_id' => $kedua->organization_id,
            'customer_id' => $kedua->getKey(),
            'user_id' => $this->pic->getKey(),
            'peran' => CustomerMember::PERAN_PIC_UTAMA,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        foreach ($this->ruteBinding() as [$metode, $template]) {
            foreach ($this->milik[$this->kunciMilik($template)] as $jenis => $id) {
                $this->tembak($this->pic, $metode, $template, $id)
                    ->assertStatus(400)
                    ->assertJsonPath('kode', 'perusahaan_belum_dipilih');
            }
        }
    }

    /** Modul dimatikan (`fitur.pelanggan`): 503 seragam untuk ID mana pun. */
    public function test_modul_dimatikan_dijawab_503_seragam_untuk_semua_id(): void
    {
        config(['pelanggan.fitur' => false]);

        foreach ($this->ruteBinding() as [$metode, $template]) {
            foreach ($this->milik[$this->kunciMilik($template)] as $id) {
                $this->tembak($this->pic, $metode, $template, $id)
                    ->assertStatus(503)
                    ->assertJsonPath('kode', 'belum_tersedia');
            }
        }
    }

    /** Tamu tetap 401 untuk ID apa pun: autentikasi tetap paling depan. */
    public function test_tamu_tetap_401_untuk_semua_id(): void
    {
        foreach ($this->ruteBinding() as [$metode, $template]) {
            foreach ($this->milik[$this->kunciMilik($template)] as $id) {
                $this->permintaanBaru();
                $this->json($metode, self::PREFIX.str_replace('{id}', $id, $template))->assertUnauthorized();
            }
        }
    }

    /**
     * SAPUAN STRUKTURAL: di urutan efektif SELURUH rute ber-parameter di dunia
     * pelanggan dan Studio, tiap penjaga ada di depan `SubstituteBindings`.
     * Rute baru yang lahir besok ikut tersapu; mutan (prioritas dicabut)
     * memerahkan test ini walau datanya tidak diuji satu per satu.
     */
    public function test_semua_penjaga_pelanggan_dan_studio_ada_di_depan_binding(): void
    {
        $penjaga = [
            FiturPelanggan::class,
            PelangganAktif::class,
            KonteksPerusahaan::class,
            PeranAnggota::class,
            PastikanAkunStudio::class,
        ];

        $diperiksa = 0;
        $melenceng = [];
        $adaPenjaga = array_fill_keys($penjaga, 0);

        foreach (Router::getRoutes()->getRoutes() as $rute) {
            if (! $this->ruteDuniaIni($rute) || ! str_contains($rute->uri(), '{')) {
                continue;
            }

            $urutan = array_map(
                static fn ($mw): string => is_string($mw) ? (string) strtok($mw, ':') : '(closure)',
                app('router')->gatherRouteMiddleware($rute),
            );

            $posisiBinding = array_search(SubstituteBindings::class, $urutan, true);

            if ($posisiBinding === false) {
                continue;
            }

            $diperiksa++;

            foreach ($penjaga as $kelas) {
                $posisi = array_search($kelas, $urutan, true);

                if ($posisi === false) {
                    continue;
                }

                $adaPenjaga[$kelas]++;

                if ($posisi > $posisiBinding) {
                    $melenceng[] = implode('|', $rute->methods())." {$rute->uri()}: ".class_basename($kelas).' sesudah SubstituteBindings';
                }
            }
        }

        $this->assertGreaterThanOrEqual(10, $diperiksa, 'Daftar rute ber-parameter pelanggan/Studio menyusut.');

        foreach ($penjaga as $kelas) {
            $this->assertGreaterThan(0, $adaPenjaga[$kelas], class_basename($kelas).' tidak dipakai rute ber-parameter mana pun; sapuan ini tidak menguji apa-apa untuknya.');
        }

        $this->assertSame([], $melenceng, "Penjaga harus di depan binding:\n".implode("\n", $melenceng));
    }

    private function ruteDuniaIni(RuteTerdaftar $rute): bool
    {
        return str_starts_with($rute->uri(), 'api/pelanggan/v1/') || str_starts_with($rute->uri(), 'api/studio/');
    }
}

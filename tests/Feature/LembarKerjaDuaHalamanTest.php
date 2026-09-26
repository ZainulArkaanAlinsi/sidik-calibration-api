<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Organization;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEMUA lembar kerja tampil dua halaman di HP: persiapan | pengukuran.
 *
 * Permintaan pemilik proyek 25 Sep 2026 ("jadi 2 aja lebih rapih"). Sebelum
 * ini cuma ketiga lembar Gaya yang dua halaman; 39 lainnya satu gulungan.
 * Aturannya satu, di `CalibrationProfile::susunDuaHalaman()`, dipasang di
 * endpoint — jadi yang diuji di sini RESPONS endpoint untuk tiap profil di
 * registry, bukan daftar tangan: profil baru ikut teruji tanpa disentuh.
 */
class LembarKerjaDuaHalamanTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create(['id' => 1]);
    }

    public function test_tiap_lembar_dua_halaman_persiapan_lalu_pengukuran(): void
    {
        $teknisi = $this->pengguna(User::ROLE_TEKNISI);

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            $kode = $profil->kode();
            $bagian = $this->bagianDariEndpoint($teknisi, $kode);
            $halaman = array_map(fn (array $b): int => (int) $b['halaman'], $bagian);

            $this->assertSame([1, 2], array_values(array_unique($halaman)), "{$kode}: harus tepat halaman 1 lalu 2.");
            $this->assertSame($halaman, $this->urut($halaman), "{$kode}: ada bagian halaman 1 sesudah bagian halaman 2.");

            $iStandar = array_search('usage_check', array_column($bagian, 'kode'), true);
            $this->assertNotFalse($iStandar, "{$kode}: tanpa bagian usage_check.");
            $this->assertSame(1, $halaman[$iStandar], "{$kode}: standar wajib di halaman 1.");

            foreach ($bagian as $i => $b) {
                if ($i > $iStandar && ($b['tabel'] ?? []) !== []) {
                    $this->assertSame(2, $halaman[$i], "{$kode}: tabel pengukuran `{$b['kode']}` wajib di halaman 2.");
                }

                if ($b['kode'] === 'penutup') {
                    $this->assertSame(2, $halaman[$i], "{$kode}: penutup wajib di halaman 2.");
                }
            }

            // Lembar yang dibelah ATURAN ini (bukan yang menyusun halamannya
            // sendiri, seperti Gaya): batasnya tepat di bagian pengukuran
            // pertama, dan yang tertinggal di halaman 1 sesudah standar cuma
            // bagian persiapan — tanpa tabel, tanpa isian angka.
            if (! $this->menyusunSendiri($profil)) {
                $iMulai = array_search(2, $halaman, true);

                $this->assertTrue($this->pengukuran($bagian[$iMulai]), "{$kode}: halaman 2 mulai di bagian yang bukan pengukuran.");

                for ($i = $iStandar + 1; $i < $iMulai; $i++) {
                    $this->assertFalse($this->pengukuran($bagian[$i]), "{$kode}: `{$bagian[$i]['kode']}` berisi ukuran tapi tertinggal di halaman 1.");
                }
            }
        }
    }

    /**
     * Pembelahannya CUMA menyentuh `halaman`. Isi bagian lain — field, tabel,
     * urutan — identik dengan yang disusun profilnya.
     */
    public function test_pembelahan_tidak_mengubah_isi_bagian_lain(): void
    {
        $teknisi = $this->pengguna(User::ROLE_TEKNISI);
        $konteks = new Equipment(['organization_id' => $this->org->id]);

        foreach (app(CalibrationProfileRegistry::class)->semua() as $profil) {
            $mentah = json_decode((string) json_encode($profil->bentukLembarKerja(false, $konteks)['bagian']), true);
            $endpoint = $this->bagianDariEndpoint($teknisi, $profil->kode());

            $this->assertSame($this->tanpaHalaman($mentah), $this->tanpaHalaman($endpoint), "{$profil->kode()}: isi bagian berubah.");
        }
    }

    /**
     * Admin mendapat bagian administratif tambahan di ujung lembar pH; dia ikut
     * halaman 2, dan lembarnya tetap tepat dua halaman.
     */
    public function test_tampilan_admin_juga_dua_halaman(): void
    {
        $bagian = $this->bagianDariEndpoint($this->pengguna(User::ROLE_ADMIN), 'ph_meter');
        $halaman = array_column($bagian, 'halaman', 'kode');

        $this->assertSame(2, $halaman['administratif'] ?? null);
        $this->assertSame([1, 2], array_values(array_unique(array_values($halaman))));
    }

    public function test_lembar_yang_tidak_bisa_dibelah_dibiarkan(): void
    {
        $tanpaStandar = ['bagian' => [
            ['kode' => 'identitas_alat', 'halaman' => 1, 'field' => []],
            ['kode' => 'hasil', 'halaman' => 1, 'tabel' => [['tahap' => 'x']]],
        ]];
        $tanpaUkuran = ['bagian' => [
            ['kode' => 'identitas_alat', 'halaman' => 1],
            ['kode' => 'usage_check', 'halaman' => 1],
            ['kode' => 'data_kalibrasi', 'halaman' => 1, 'field' => [['kode' => 'lokasi', 'tipe' => 'pilihan']]],
        ]];
        $sudahDua = ['bagian' => [
            ['kode' => 'usage_check', 'halaman' => 1],
            ['kode' => 'misalignment', 'halaman' => 1, 'field' => [['kode' => 'm', 'tipe' => 'angka']]],
            ['kode' => 'hasil', 'halaman' => 2, 'tabel' => [['tahap' => 'x']]],
        ]];

        $this->assertSame($tanpaStandar, CalibrationProfile::susunDuaHalaman($tanpaStandar));
        $this->assertSame($tanpaUkuran, CalibrationProfile::susunDuaHalaman($tanpaUkuran));
        $this->assertSame($sudahDua, CalibrationProfile::susunDuaHalaman($sudahDua));
        $this->assertSame([], CalibrationProfile::susunDuaHalaman([]));
    }

    public function test_isian_angka_sesudah_standar_ikut_halaman_pengukuran(): void
    {
        $bentuk = ['bagian' => [
            ['kode' => 'identitas_alat', 'halaman' => 1],
            ['kode' => 'usage_check', 'halaman' => 1, 'tabel' => [['tahap' => 'preload']]],
            ['kode' => 'dryblock', 'halaman' => 1, 'field' => [['kode' => 'd', 'tipe' => 'pilihan']]],
            ['kode' => 'titik_es', 'halaman' => 1, 'field' => [['kode' => 't', 'tipe' => 'angka']]],
            ['kode' => 'sre', 'halaman' => 1],
            ['kode' => 'penutup', 'halaman' => 1],
        ]];

        $hasil = CalibrationProfile::susunDuaHalaman($bentuk);

        $this->assertSame(
            ['identitas_alat' => 1, 'usage_check' => 1, 'dryblock' => 1, 'titik_es' => 2, 'sre' => 2, 'penutup' => 2],
            array_column($hasil['bagian'], 'halaman', 'kode'),
        );
        $this->assertSame($hasil, CalibrationProfile::susunDuaHalaman($hasil), 'Dipanggil dua kali harus sama dengan sekali.');
    }

    private function pengguna(string $peran): User
    {
        return User::factory()->create(['organization_id' => $this->org->id, 'role' => $peran]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function bagianDariEndpoint(User $pengguna, string $kode): array
    {
        return $this->actingAs($pengguna, 'sanctum')
            ->getJson('/api/calibrations/lembar-kerja?profil='.$kode)
            ->assertOk()
            ->json('data.bagian');
    }

    private function menyusunSendiri(CalibrationProfile $profil): bool
    {
        foreach ($profil->bentukLembarKerja()['bagian'] ?? [] as $b) {
            if ((int) ($b['halaman'] ?? 1) > 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $bagian
     */
    private function pengukuran(array $bagian): bool
    {
        return ($bagian['tabel'] ?? []) !== []
            || in_array('angka', array_column($bagian['field'] ?? [], 'tipe'), true);
    }

    /**
     * @param  list<int>  $nilai
     * @return list<int>
     */
    private function urut(array $nilai): array
    {
        sort($nilai);

        return $nilai;
    }

    /**
     * @param  list<array<string, mixed>>  $bagian
     * @return list<array<string, mixed>>
     */
    private function tanpaHalaman(array $bagian): array
    {
        return array_map(function (array $b): array {
            unset($b['halaman']);

            return $b;
        }, $bagian);
    }
}

<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Folder;
use App\Models\FolderFile;
use App\Models\Organization;
use App\Models\User;
use App\Services\MatriksIzin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GET /api/me/permissions` (fase-2 §1).
 *
 * Gunanya buat mobile: nyembunyiin tombol yang bakal ditolak, bukan nampilin
 * tombol lalu user kena `403`.
 *
 * Yang paling dijaga di sini bukan bentuk responsnya, tapi **kejujurannya**:
 * `test_daftar_izin_cocok_sama_penjagaan_asli` manggil SEMUA endpoint yang
 * dipetakan pakai ketiga role, terus mastiin `403`-nya kejadian **persis** kalau
 * izinnya nggak ada. Endpoint izin yang bohong lebih jahat daripada nggak ada
 * endpoint-nya sama sekali: mobile bakal nyembunyiin tombol yang sebenernya boleh,
 * atau nampilin yang bakal ditolak — dan dua-duanya keliatan kayak bug di tempat
 * lain.
 */
class MeIzinTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/me/permissions';

    private MatriksIzin $matriks;

    private Customer $pelanggan;

    private EquipmentCategory $kategori;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->matriks = app(MatriksIzin::class);

        // Dipakai ulang, sengaja: `EquipmentCategoryFactory` narik nama dari
        // daftar `unique()` yang cuma 6 isinya, dan sapuan izin di bawah bikin
        // alat baru ratusan kali. Yang perlu baru tiap kali cuma record yang bisa
        // kehapus/keubah sama request yang diuji — kategori & pelanggan nggak.
        $this->pelanggan = Customer::factory()->create();
        $this->kategori = EquipmentCategory::factory()->create();
    }

    private function akun(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    // -------------------------------------------------------------- bentuk

    public function test_tanpa_token_ditolak(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    public function test_bentuk_responsnya_sesuai_kontrak(): void
    {
        $res = $this->actingAs($this->akun(User::ROLE_TEKNISI))
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.role', User::ROLE_TEKNISI)
            ->assertJsonStructure(['data' => ['role', 'boleh', 'batasan']]);

        $this->assertIsArray($res->json('data.boleh'));
        $this->assertContains('kalibrasi.buat', $res->json('data.boleh'));
    }

    public function test_tiap_role_dapat_daftarnya_sendiri(): void
    {
        $admin = $this->actingAs($this->akun(User::ROLE_ADMIN))->getJson(self::URL)->json('data.boleh');
        $teknisi = $this->actingAs($this->akun(User::ROLE_TEKNISI))->getJson(self::URL)->json('data.boleh');
        $viewer = $this->actingAs($this->akun(User::ROLE_VIEWER))->getJson(self::URL)->json('data.boleh');

        // Makin sempit rolenya, makin sedikit izinnya — dan harus jadi HIMPUNAN
        // BAGIAN. Kalau viewer punya izin yang admin nggak punya, ada aturan yang
        // kebalik di suatu tempat.
        $this->assertEmpty(array_diff($viewer, $teknisi), 'Viewer punya izin yang teknisi nggak punya.');
        $this->assertEmpty(array_diff($teknisi, $admin), 'Teknisi punya izin yang admin nggak punya.');
        $this->assertGreaterThan(count($teknisi), count($admin));
        $this->assertGreaterThan(count($viewer), count($teknisi));
    }

    /**
     * Tiga pertanyaan yang fase-2 §1 minta dijawab backend — dikunci di sini
     * biar jawabannya nggak bisa geser tanpa ada yang sadar.
     */
    public function test_jawaban_tiga_pertanyaan_matriks_peran(): void
    {
        $viewer = $this->actingAs($this->akun(User::ROLE_VIEWER))->getJson(self::URL)->json('data');
        $teknisi = $this->actingAs($this->akun(User::ROLE_TEKNISI))->getJson(self::URL)->json('data');

        // 1. Viewer BOLEH baca arsip & sertifikat, tapi nggak boleh nulis.
        $this->assertContains('arsip.lihat', $viewer['boleh']);
        $this->assertContains('sertifikat.lihat', $viewer['boleh']);
        $this->assertNotContains('arsip.folder.kelola', $viewer['boleh']);
        $this->assertNotContains('kalibrasi.buat', $viewer['boleh']);

        // 2. Teknisi NGGAK boleh lihat sesi teknisi lain.
        $this->assertSame('sendiri', $teknisi['batasan']['kalibrasi']);
        $this->assertSame('sendiri', $teknisi['batasan']['sertifikat']);

        // 3. Nggak ada role keempat — penanda tangan sertifikat diputusin jadi
        //    atribut, bukan role (keputusan 26 Jul).
        $this->assertSame(['admin', 'teknisi', 'viewer'], User::roles());
    }

    public function test_admin_dan_viewer_lihat_data_se_lab(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_VIEWER] as $role) {
            $batasan = $this->actingAs($this->akun($role))->getJson(self::URL)->json('data.batasan');

            $this->assertSame('semua', $batasan['kalibrasi'], "Role {$role} harusnya lihat semua sesi.");
        }
    }

    // ------------------------------------------------------- anti-kebohongan

    /** Peta izin nunjuk ke rute yang beneran terdaftar — bukan path yang udah basi. */
    public function test_semua_rute_di_peta_beneran_ada(): void
    {
        $this->assertSame(
            [],
            $this->matriks->petaYangNgawur(),
            'Ada izin yang nunjuk rute nggak terdaftar. Perbaiki MatriksIzin::PETA, '
                .'atau endpoint ini bakal bilang "boleh" buat endpoint yang udah nggak ada.',
        );
    }

    /**
     * INTI test ini: daftar izin harus cocok sama penjagaan yang beneran jalan.
     *
     * Buat tiap izin × tiap role, endpoint aslinya dipanggil. `403` harus kejadian
     * **persis** kalau izinnya nggak ada di daftar, dan **nggak boleh** kejadian
     * kalau ada. Status lain (`404`/`422`/`200`) dianggap "lolos penjagaan" — yang
     * diuji di sini otorisasinya, bukan validasi isi requestnya.
     *
     * Ini yang bikin `MatriksIzin` nggak bisa jadi sumber kebenaran kedua yang
     * diam-diam beda dari middleware.
     */
    public function test_daftar_izin_cocok_sama_penjagaan_asli(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER] as $role) {
            $user = $this->akun($role);
            $boleh = $this->matriks->bolehUntuk($role);

            foreach (MatriksIzin::PETA as $izin => [$method, $uri]) {
                $path = $this->isiParameter($uri, $user);
                $status = $this->panggil($user, $method, $path);
                $harusBoleh = in_array($izin, $boleh, true);

                if ($harusBoleh) {
                    $this->assertNotSame(
                        403,
                        $status,
                        "`{$izin}` ada di daftar izin role `{$role}`, tapi {$method} {$path} balik 403. "
                            .'Daftarnya bohong: mobile bakal nampilin tombol yang ditolak.',
                    );
                } else {
                    $this->assertSame(
                        403,
                        $status,
                        "`{$izin}` NGGAK ada di daftar izin role `{$role}`, tapi {$method} {$path} "
                            ."nggak balik 403 (dapat {$status}). Daftarnya bohong: mobile bakal "
                            .'nyembunyiin tombol yang sebenernya boleh dipakai.',
                    );
                }
            }
        }
    }

    private function panggil(User $user, string $method, string $path): int
    {
        return $this->actingAs($user)
            ->json($method, '/'.ltrim($path, '/'))
            ->getStatusCode();
    }

    /**
     * Ganti `{param}` di URI pakai record ASLI yang baru dibikin.
     *
     * Harus record asli: `SubstituteBindings` jalan SEBELUM middleware `role:`
     * (dia bagian dari grup `api`), jadi id ngawur bakal `404` duluan dan `403`
     * yang mau diuji nggak pernah kejadian.
     *
     * Dibikin baru tiap pemanggilan, soalnya sapuan ini termasuk `DELETE` — kalau
     * record-nya dipakai bareng, panggilan berikutnya `404` dan hasil ujinya jadi
     * nggak berarti.
     */
    private function isiParameter(string $uri, User $user): string
    {
        if (! str_contains($uri, '{')) {
            return $uri;
        }

        $ganti = [];

        if (str_contains($uri, '{equipment}')) {
            $ganti['{equipment}'] = (string) $this->alat()->id;
        }

        if (str_contains($uri, '{calibration}')) {
            // Sesinya MILIK user yang lagi diuji — teknisi yang buka sesi orang
            // lain dapat 404, dan itu bikin hasil ujinya nggak jelas.
            $ganti['{calibration}'] = (string) $this->sesi($user)->id;
        }

        if (str_contains($uri, '{certificate}')) {
            $ganti['{certificate}'] = (string) Certificate::factory()->create([
                'calibration_session_id' => $this->sesi($user)->id,
            ])->id;
        }

        if (str_contains($uri, '{folderFile}')) {
            // Nggak ada factory buat model ini — dibikin manual, diunggah user
            // yang lagi diuji biar teknisi nggak kena saring kepemilikan.
            $ganti['{folderFile}'] = (string) FolderFile::create([
                'organization_id' => $user->organization_id,
                'folder_id' => Folder::factory()->create()->id,
                'uploaded_by' => $user->id,
                'nama' => 'berkas-uji.pdf',
                'sumber' => FolderFile::SUMBER_UNGGAHAN,
            ])->id;
        }

        if (str_contains($uri, '{user}')) {
            $ganti['{user}'] = (string) User::factory()->create()->id;
        }

        return str_replace(array_keys($ganti), array_values($ganti), $uri);
    }

    private function alat(): Equipment
    {
        return Equipment::factory()->create([
            'customer_id' => $this->pelanggan->id,
            'equipment_category_id' => $this->kategori->id,
            'satuan' => 'mm', 'toleransi' => 0.05,
        ]);
    }

    private function sesi(User $user): CalibrationSession
    {
        return CalibrationSession::factory()->create([
            'teknisi_id' => $user->id,
            'equipment_id' => $this->alat()->id,
        ]);
    }
}

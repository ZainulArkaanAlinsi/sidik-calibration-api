<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * `GET /api/health` melaporkan tiga hal tentang container yang SEDANG jalan.
 *
 * ## Kenapa ada
 *
 * Tiga pertanyaan yang selama ini cuma bisa dijawab dengan membuka dashboard
 * penyedia hosting, dan karena itu selalu jadi bolak-balik:
 *
 *   - "build-nya udah naik belum?"            → `deploy.versi`
 *   - "berkasnya masih ilang tiap deploy?"    → `deploy.arsip.awet`
 *   - "seeder masih jalan tiap boot?"         → `deploy.seed_saat_boot`
 *
 * ## Batas yang bikin dia aman dibiarkan tanpa auth
 *
 * Sama dengan `direktori_perusahaan` (lihat `HealthDirektoriTest`): yang
 * dilaporkan **status**, bukan nilai. Nol request ke penyedia, nol rahasia.
 * Test terakhir di berkas ini yang menjaganya, dan dia sengaja mengadu ke
 * nilai-nilai yang paling mahal kalau bocor — kredensial bucket dan host
 * database.
 */
class HealthDeployTest extends TestCase
{
    /** Commit yang jalan dilaporkan apa adanya. */
    public function test_versi_dilaporkan_kalau_disetel(): void
    {
        config()->set('deploy.versi', 'abc1234');

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('deploy.versi', 'abc1234');
    }

    /**
     * Di luar Render nilainya kosong, dan yang dilaporkan `null` — bukan
     * tebakan, bukan string kosong yang kelihatan seperti versi.
     */
    public function test_versi_null_kalau_nggak_ada(): void
    {
        config()->set('deploy.versi', null);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('deploy.versi', null);
    }

    /**
     * `arsip.awet` false = berkas tinggal di disk container yang kehapus tiap
     * deploy. Ini keadaan produksi sekarang, dan justru itu yang bikin dia
     * perlu kelihatan dari luar.
     */
    public function test_arsip_local_dilaporkan_nggak_awet(): void
    {
        config()->set('filesystems.disks.arsip.driver', 'local');

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('deploy.arsip.awet', false);
    }

    /** Begitu pindah ke object storage, laporannya ikut berubah. */
    public function test_arsip_s3_dilaporkan_awet(): void
    {
        config()->set('filesystems.disks.arsip.driver', 's3');

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('deploy.arsip.awet', true);
    }

    /**
     * Saklar seeder dilaporkan apa adanya — dua-duanya diadu.
     *
     * Yang `true` yang penting: dia tersangka utama deploy yang timeout, dan
     * laporan `false` yang salah mencoret tersangka itu dari daftar lalu
     * mengirim orangnya mencari ke tempat yang salah.
     */
    public function test_seed_saat_boot_dilaporkan_dua_duanya(): void
    {
        config()->set('deploy.seed_saat_boot', true);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('deploy.seed_saat_boot', true);

        config()->set('deploy.seed_saat_boot', false);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('deploy.seed_saat_boot', false);
    }

    /**
     * Endpoint ini publik, jadi yang keluar cuma status — nggak pernah nilai.
     *
     * Diadu ke yang paling mahal kalau bocor: kredensial bucket dan host
     * database. Ketiga field `deploy` di atas kelihatan sepele satu-satu, dan
     * itu persis kenapa penjagaan ini ditulis — yang berikutnya menambah field
     * di sini bakal ketemu test ini duluan.
     */
    public function test_nggak_ada_rahasia_yang_ikut_keluar(): void
    {
        config()->set('filesystems.disks.arsip.driver', 's3');
        config()->set('filesystems.disks.s3.bucket', 'nama-bucket-rahasia');
        config()->set('filesystems.disks.s3.key', 'AKIAKUNCIRAHASIA');
        config()->set('filesystems.disks.s3.secret', 'sandi-bucket-rahasia');
        config()->set('database.connections.mysql.host', 'host-database-rahasia');
        config()->set('seeding.sandi_awal', 'sandi-admin-rahasia');

        $isi = $this->getJson('/api/health')->assertOk()->getContent();

        foreach ([
            'nama-bucket-rahasia',
            'AKIAKUNCIRAHASIA',
            'sandi-bucket-rahasia',
            'host-database-rahasia',
            'sandi-admin-rahasia',
        ] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $isi);
        }
    }
}

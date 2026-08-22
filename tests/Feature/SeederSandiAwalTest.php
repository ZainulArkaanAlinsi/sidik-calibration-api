<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\Concerns\MenyetelSandiAwal;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Penjaga dua sifat seeder yang gampang balik lagi tanpa satu pun test merah.
 *
 * ## Kenapa perlu
 *
 * Sampai 23 Agt 2026, `DatabaseSeeder` & `PhMeterSeeder` nyetel
 * `'password' => 'rahasia123'` di dalam `update()`/`updateOrCreate()`. Bentuk
 * kodenya wajar — satu array atribut dipakai buat bikin DAN nambal — jadi orang
 * berikutnya yang nambah kolom ke akun seeder gampang banget nulisnya balik
 * seperti semula tanpa merasa lagi ngubah apa-apa soal keamanan.
 *
 * Yang dipertaruhkan bukan kerapian:
 *
 * - Repo ini PUBLIK. `rahasia123` tercetak di docs/kontrak-api.md dan di
 *   seeder-nya sendiri, dan nama service di render.yaml bikin alamat servernya
 *   ketebak sekali coba. Panel `/admin` bisa nerbitin, ngubah, dan ngapus
 *   sertifikat kalibrasi — buat lab terakreditasi itu temuan audit.
 * - `SEED_ON_BOOT` dimaksud dinyalain sekali doang, tapi itu janji manusia.
 *   Sekali keteken `true` lagi, sandi yang sudah diganti admin balik ke bawaan
 *   diam-diam, tanpa error, tanpa ada yang dikabarin.
 *
 * Dua-duanya nggak keliatan dari test fitur mana pun: suite tetap hijau, dan
 * yang berubah cuma isi kolom `password` di server. Makanya dijaga di sini.
 */
class SeederSandiAwalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ini yang paling mahal kalau jebol: sandi admin yang sudah diganti balik
     * ke sandi yang tercetak di dokumentasi publik.
     */
    public function test_seed_ulang_nggak_nyetel_ulang_sandi_yang_udah_diganti(): void
    {
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('employee_id', 'SDK-0001')->firstOrFail();
        $admin->update(['password' => 'sandibaru123']);

        $this->seed(DatabaseSeeder::class);

        $sesudah = $admin->fresh();

        $this->assertTrue(
            Hash::check('sandibaru123', $sesudah->password),
            'Seed ulang nimpa sandi yang sudah diganti admin.',
        );
        $this->assertFalse(
            Hash::check('rahasia123', $sesudah->password),
            'Seed ulang ngebalikin sandi ke bawaan yang tercetak di repo publik.',
        );
    }

    /**
     * Sisi sebaliknya: jangan sampai "amanin sandi" malah bikin dokumentasi,
     * skrip e2e, dan test lain bohong soal cara login di laptop.
     */
    public function test_di_lokal_sandi_bawaannya_tetap_rahasia123(): void
    {
        $this->assertSame('rahasia123', $this->sandiDariSeeder());
    }

    public function test_di_luar_lokal_sandinya_dari_environment(): void
    {
        $this->app['env'] = 'production';
        config(['seeding.sandi_awal' => 'sandi-dari-environment']);

        $this->assertSame('sandi-dari-environment', $this->sandiDariSeeder());
    }

    /**
     * `SEED_ADMIN_PASSWORD` kosong bukan alasan buat jatuh balik ke sandi
     * bawaan — justru itu keadaan yang paling sering kejadian di deploy
     * pertama, waktu belum ada yang sempat mikirin variabelnya.
     */
    public function test_di_luar_lokal_tanpa_environment_sandinya_acak(): void
    {
        $this->app['env'] = 'production';
        config(['seeding.sandi_awal' => null]);

        $sandi = $this->sandiDariSeeder();

        $this->assertNotSame('rahasia123', $sandi);
        $this->assertSame(32, strlen($sandi));
    }

    private function sandiDariSeeder(): string
    {
        $seeder = new class extends Seeder
        {
            use MenyetelSandiAwal;

            public function ambil(): string
            {
                return $this->sandiAwal();
            }
        };

        return $seeder->ambil();
    }
}

<?php

namespace Tests\Feature;

use App\Support\TriggerAppendOnlyAcuan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Lapis DATABASE append-only data acuan (§54) harus terlihat dari luar
 * (`/api/health` → `append_only_db`) dan bisa dipasang ulang
 * (`acuan:pasang-trigger`) sesudah setelan server dibetulkan.
 *
 * Kasus yang menjalankan DDL hanya di SQLite: di MySQL `CREATE/DROP TRIGGER`
 * melakukan commit implisit dan merusak transaksi `RefreshDatabase`.
 */
class PasangTriggerAcuanTest extends TestCase
{
    use RefreshDatabase;

    private function jumlahTriggerMentah(): int
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => (int) DB::selectOne("SELECT COUNT(*) AS n FROM sqlite_master WHERE type = 'trigger' "
                ."AND name IN ('pal_tolak_ubah', 'pal_tolak_hapus', 'sa_tolak_ubah', 'sa_tolak_hapus')")->n,
            default => (int) DB::selectOne('SELECT COUNT(*) AS n FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() '
                ."AND TRIGGER_NAME IN ('pal_tolak_ubah', 'pal_tolak_hapus', 'sa_tolak_ubah', 'sa_tolak_hapus')")->n,
        };
    }

    private function hanyaSqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('DDL trigger di MySQL commit implisit — merusak transaksi RefreshDatabase.');
        }
    }

    public function test_health_melaporkan_keadaan_trigger_sesudah_migrasi(): void
    {
        $harapan = $this->jumlahTriggerMentah() === 4;

        if (DB::connection()->getDriverName() === 'sqlite') {
            $this->assertTrue($harapan, 'migrasi SQLite selalu memasang keempat trigger dengan nama yang sama');
        }

        $this->getJson('/api/health')->assertOk()->assertJsonPath('append_only_db', $harapan);
    }

    public function test_health_false_kalau_satu_trigger_hilang(): void
    {
        $this->hanyaSqlite();

        DB::unprepared('DROP TRIGGER sa_tolak_hapus');
        Cache::forget(TriggerAppendOnlyAcuan::KUNCI_CACHE);

        $this->getJson('/api/health')->assertOk()->assertJsonPath('append_only_db', false);
    }

    public function test_health_dicache_dan_pasang_membuang_cachenya(): void
    {
        Cache::put(TriggerAppendOnlyAcuan::KUNCI_CACHE, false, now()->addMinutes(5));
        $this->getJson('/api/health')->assertOk()->assertJsonPath('append_only_db', false);

        Cache::forget(TriggerAppendOnlyAcuan::KUNCI_CACHE);
        $this->getJson('/api/health')->assertOk()->assertJsonPath('append_only_db', $this->jumlahTriggerMentah() === 4);
        $this->assertIsBool(Cache::get(TriggerAppendOnlyAcuan::KUNCI_CACHE));
    }

    public function test_perintah_memasang_yang_hilang_dan_idempoten(): void
    {
        $this->hanyaSqlite();

        DB::unprepared('DROP TRIGGER pal_tolak_ubah');
        DB::unprepared('DROP TRIGGER sa_tolak_hapus');
        Cache::put(TriggerAppendOnlyAcuan::KUNCI_CACHE, false, now()->addMinutes(5));
        $this->assertSame(2, $this->jumlahTriggerMentah());

        $this->artisan('acuan:pasang-trigger')
            ->expectsOutput('pal_tolak_ubah: dipasang')
            ->expectsOutput('pal_tolak_hapus: sudah_ada')
            ->expectsOutput('sa_tolak_hapus: dipasang')
            ->assertSuccessful();

        $this->assertSame(4, $this->jumlahTriggerMentah());
        $this->assertNull(Cache::get(TriggerAppendOnlyAcuan::KUNCI_CACHE), 'cache health dibuang sesudah pasang');
        $this->getJson('/api/health')->assertJsonPath('append_only_db', true);
    }

    public function test_perintah_kedua_kali_tidak_menyentuh_apa_pun(): void
    {
        $this->hanyaSqlite();

        $this->artisan('acuan:pasang-trigger')
            ->expectsOutput('pal_tolak_ubah: sudah_ada')
            ->expectsOutput('pal_tolak_hapus: sudah_ada')
            ->expectsOutput('sa_tolak_ubah: sudah_ada')
            ->expectsOutput('sa_tolak_hapus: sudah_ada')
            ->assertSuccessful();
    }
}

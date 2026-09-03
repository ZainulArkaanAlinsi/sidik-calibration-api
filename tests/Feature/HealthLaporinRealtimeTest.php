<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Keadaan realtime kelihatan dari luar, tanpa masuk dashboard hosting.
 *
 * ## Kenapa berkas ini ada
 *
 * ```
 * grep -c "REVERB\|BROADCAST" render.yaml        → 0
 * grep -c "reverb"           docker/entrypoint.sh → 0
 * composer.json:14                                 "laravel/reverb": "^1.11"   (terpasang)
 * config/broadcasting.php:17   'default' => env('BROADCAST_CONNECTION', 'log')
 * ```
 *
 * Paketnya terpasang, blueprint-nya tidak pernah menyetel apa pun, jadi produksi
 * jatuh ke bawaan `log`: event broadcast ditulis ke berkas log dan **tidak
 * pernah sampai ke klien**. Tidak ada error, tidak ada yang gagal — pengguna
 * cuma tidak pernah melihat pembaruan sampai dia menarik data manual.
 *
 * Itu bentuk kegagalan yang paling mahal dilacak: fiturnya ada di kode, ada di
 * dokumen, dan "kelihatan" terpasang. Rollout-nya berhenti di tengah —
 * dependency masuk, konfigurasi produksi dan proses server tidak menyusul.
 *
 * ## Yang berkas ini TIDAK lakukan
 *
 * Menyalakan realtime-nya. Itu butuh jawaban infrastruktur yang bukan urusan
 * kode — apakah plan Render yang dipakai sanggup menahan satu proses WebSocket
 * panjang lagi di container yang sama (`docs/pertanyaan-lab-audit-2026-09.md`
 * T3).
 *
 * Yang dibeli: keadaannya berhenti tak terlihat. Sebelum ini satu-satunya cara
 * memeriksanya adalah membuka dashboard Render dan membaca env var-nya — dan
 * karena itu tidak ada yang pernah memeriksanya.
 *
 * Batasnya sama dengan dua blok sejenis di endpoint yang sama: yang dilaporkan
 * STATUS, bukan nilai. `REVERB_APP_SECRET` tidak pernah ikut, tidak juga
 * panjangnya, dan nol request ke mana pun.
 */
class HealthLaporinRealtimeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function realtime(): array
    {
        return $this->getJson('/api/health')->assertOk()->json('realtime');
    }

    /** INTI-nya: keadaan hari ini kebaca dari luar, dan jujur. */
    public function test_driver_log_dilaporin_sebagai_nggak_nyala(): void
    {
        config(['broadcasting.default' => 'log']);

        $realtime = $this->realtime();

        $this->assertSame('log', $realtime['driver']);
        $this->assertFalse($realtime['nyala'], 'Driver `log` dilaporin nyala — itu bohong yang senyap.');
    }

    /**
     * `null` juga "nggak sampai klien", dan bedanya dari `log` cuma dia buang
     * diam-diam tanpa jejak sama sekali.
     */
    public function test_driver_null_juga_dilaporin_nggak_nyala(): void
    {
        config(['broadcasting.default' => 'null']);

        $this->assertFalse($this->realtime()['nyala']);
    }

    /** Waktu suatu hari dinyalakan, laporannya harus ikut berubah. */
    public function test_driver_reverb_dilaporin_nyala(): void
    {
        config(['broadcasting.default' => 'reverb']);

        $realtime = $this->realtime();

        $this->assertSame('reverb', $realtime['driver']);
        $this->assertTrue($realtime['nyala']);
    }

    /**
     * `paket_terpasang` dipisah dari `nyala` karena keduanya bisa salah
     * sendiri-sendiri — dan kombinasi yang paling membingungkan justru yang
     * berlaku hari ini: paketnya ada, drivernya tidak diset.
     */
    public function test_paket_terpasang_dilaporin_terpisah_dari_nyala(): void
    {
        config(['broadcasting.default' => 'log']);

        $realtime = $this->realtime();

        $this->assertArrayHasKey('paket_terpasang', $realtime);
        $this->assertTrue(
            $realtime['paket_terpasang'],
            'laravel/reverb ada di composer.json tapi dilaporin nggak terpasang.'
        );
        $this->assertFalse($realtime['nyala']);
    }

    /**
     * Batas yang sama dengan dua blok sejenis di endpoint ini: **status, bukan
     * nilai**. Endpoint-nya publik tanpa auth.
     */
    public function test_nggak_ada_rahasia_yang_ikut_kebawa(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'kunci-rahasia-banget',
            'broadcasting.connections.reverb.secret' => 'secret-rahasia-banget',
        ]);

        $isi = $this->getJson('/api/health')->assertOk()->content();

        $this->assertStringNotContainsString('kunci-rahasia-banget', $isi);
        $this->assertStringNotContainsString('secret-rahasia-banget', $isi);

        // Panjangnya pun nggak ikut — itu petunjuk yang cukup buat nebak.
        $realtime = $this->realtime();
        $this->assertSame(['driver', 'nyala', 'paket_terpasang'], array_keys($realtime));
    }

    /**
     * JANGAN kebablasan: blok lama di endpoint yang sama tetap utuh.
     *
     * Endpoint ini yang dipakai mobile buat memastikan sambungannya jalan —
     * merusaknya lebih mahal daripada bug yang lagi ditambal.
     */
    public function test_isi_health_yang_lama_nggak_ilang(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonStructure([
                'status', 'app', 'time',
                'direktori_perusahaan' => ['disetel', 'driver', 'bisa_ditagih', 'lokal'],
                'deploy' => ['versi', 'arsip', 'seed_saat_boot'],
                'realtime' => ['driver', 'nyala', 'paket_terpasang'],
            ]);
    }
}

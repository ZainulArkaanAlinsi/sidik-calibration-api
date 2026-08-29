<?php

namespace Tests\Unit;

use App\Services\Direktori\GooglePlacesDirektori;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Waktu tunggu direktori luar dijepit di konstruktornya.
 *
 * Yang dijaga di sini satu jebakan yang nggak kelihatan dari kodenya:
 * **`timeout(0)` di Guzzle artinya TANPA batas waktu, bukan "cepat".** Dan nol
 * itu justru yang keluar dari setelan yang salah — `.env` kosong, salah ketik,
 * atau memang `0`, ketiganya turun jadi 0 lewat cast `(int)`.
 *
 * Akibatnya request teknisi menggantung tanpa ujung: dia nggak pernah sampai ke
 * pesan gagal, dan nggak pernah sampai ke jalur ketik tangan yang selalu jalan.
 * Satu salah ketik di `.env` mematikan pendaftaran pelanggan di lapangan tanpa
 * satu pun error yang nunjuk ke sebabnya.
 */
class DirektoriTimeoutTest extends TestCase
{
    private function timeout(int $disetel): int
    {
        $direktori = new GooglePlacesDirektori('kunci-uji', $disetel);

        $prop = new ReflectionProperty($direktori, 'timeoutDetik');

        return $prop->getValue($direktori);
    }

    public function test_nol_tidak_pernah_jadi_tanpa_batas_waktu(): void
    {
        $this->assertSame(1, $this->timeout(0));
    }

    public function test_negatif_juga_dijepit(): void
    {
        $this->assertSame(1, $this->timeout(-5));
    }

    /**
     * Batas atasnya juga dijepit: teknisi berdiri di lapangan dengan HP di
     * tangan, dan menunggu semenit buat satu pencarian sama nggak bergunanya
     * dengan gagal.
     */
    public function test_terlalu_lama_dijepit_ke_batas_atas(): void
    {
        $this->assertSame(30, $this->timeout(600));
    }

    public function test_nilai_wajar_dipakai_apa_adanya(): void
    {
        $this->assertSame(8, $this->timeout(8));
    }
}

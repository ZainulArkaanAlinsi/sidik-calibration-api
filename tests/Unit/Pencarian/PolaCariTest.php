<?php

namespace Tests\Unit\Pencarian;

use App\Services\Pencarian\NormalisasiNomor;
use App\Services\Pencarian\PolaCari;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pola `LIKE` longgar untuk kata cari pelanggan -- murni, tanpa DB. */
class PolaCariTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function polaDiharapkan(): array
    {
        return [
            'kata tanpa pemisah tetap %kata%' => ['CAL', '%CAL%'],
            'nomor sertifikat dengan garis' => ['CAL-2026-10-0001', '%CAL%2026%10%0001%'],
            'nomor sertifikat dengan miring' => ['CAL/2026/10/0001', '%CAL%2026%10%0001%'],
            'nomor sertifikat dengan spasi' => ['cal 2026 10 0001', '%cal%2026%10%0001%'],
            'titik' => ['CAL.2026.10.0001', '%CAL%2026%10%0001%'],
            'garis bawah' => ['CAL_2026_10_0001', '%CAL%2026%10%0001%'],
            'garis miring terbalik' => ['CAL\\2026\\10\\0001', '%CAL%2026%10%0001%'],
            'pemisah campuran' => ['CAL-2026/10 0001', '%CAL%2026%10%0001%'],
            'pemisah berderet menjadi satu' => ['CAL -/ 2026 -- 10', '%CAL%2026%10%'],
            'spasi dan pemisah di ujung' => ["  - CAL/2026/ \t", '%CAL%2026%'],
            'tab dan baris baru adalah pemisah' => ["CAL\t2026\n10", '%CAL%2026%10%'],
            'persen di tengah jadi celah' => ['100%X', '%100%X%'],
            'persen di ujung' => ['100%', '%100%'],
            'huruf besar kecil tidak diubah' => ['Cal-AbC', '%Cal%AbC%'],
            'revisi ikut sebagai potongan' => ['CAL/2026/10/0001-R1', '%CAL%2026%10%0001%R1%'],
            'karakter lain tetap harfiah' => ['SN#12!A', '%SN#12!A%'],
            'UTF-8 tidak dirusak' => ['Tekanan-Ünit', '%Tekanan%Ünit%'],
        ];
    }

    #[DataProvider('polaDiharapkan')]
    public function test_pola_like(string $diketik, string $diharapkan): void
    {
        $this->assertSame($diharapkan, PolaCari::like($diketik));
    }

    /** @return array<string, array{string}> */
    public static function tanpaPotongan(): array
    {
        return [
            'kosong' => [''],
            'spasi saja' => ['   '],
            'persen saja' => ['%'],
            'banyak persen' => ['%%%'],
            'garis bawah saja' => ['_'],
            'garis bawah dan persen' => ['_%_'],
            'garis dan miring saja' => [' - / - '],
            'titik saja' => ['...'],
            'miring terbalik saja' => ['\\'],
            'semua pemisah sekaligus' => ["%_ -/.\\\t\n"],
        ];
    }

    #[DataProvider('tanpaPotongan')]
    public function test_kata_yang_hanya_pemisah_tidak_punya_pola(string $diketik): void
    {
        $this->assertNull(PolaCari::like($diketik));
        $this->assertSame([], PolaCari::token($diketik));
    }

    public function test_persen_dan_garis_bawah_tidak_pernah_lolos_ke_pola(): void
    {
        foreach (['A_B', 'A%B', '_AB_', '%AB%', 'A\\B', 'A_%\\B', '100%_!'] as $diketik) {
            $pola = PolaCari::like($diketik);

            $this->assertNotNull($pola, $diketik);
            // Satu-satunya `%` yang tersisa adalah perangkai milik helper,
            // tidak pernah yang diketik; `_` dan `\` tidak tersisa sama sekali.
            $this->assertStringNotContainsString('_', $pola, $diketik);
            $this->assertStringNotContainsString('\\', $pola, $diketik);
            $this->assertStringNotContainsString('%%', $pola, $diketik);
            $this->assertSame('%', $pola[0], $diketik);
            $this->assertSame('%', substr($pola, -1), $diketik);
        }
    }

    public function test_potongan_berlebih_dibatasi_delapan_dan_urutannya_dijaga(): void
    {
        $diketik = implode('-', ['a1', 'b2', 'c3', 'd4', 'e5', 'f6', 'g7', 'h8', 'i9', 'j10', 'k11', 'l12']);

        $this->assertSame(8, PolaCari::MAKS_TOKEN);
        $this->assertSame(['a1', 'b2', 'c3', 'd4', 'e5', 'f6', 'g7', 'h8'], PolaCari::token($diketik));
        $this->assertSame('%a1%b2%c3%d4%e5%f6%g7%h8%', PolaCari::like($diketik));
    }

    public function test_tepat_delapan_potongan_tidak_dipotong(): void
    {
        $this->assertSame('%a%b%c%d%e%f%g%h%', PolaCari::like('a b c d e f g h'));
        $this->assertSame('%a%b%c%d%e%f%g%h%', PolaCari::like('a b c d e f g h i'));
        $this->assertSame('%a%b%c%d%e%f%g%', PolaCari::like('a b c d e f g'));
    }

    public function test_seratus_karakter_tanpa_pemisah_menjadi_satu_potongan(): void
    {
        $seratus = str_repeat('x', 100);

        $this->assertSame("%{$seratus}%", PolaCari::like($seratus));
        $this->assertCount(1, PolaCari::token($seratus));
    }

    public function test_seratus_karakter_penuh_pemisah_tetap_terbatas(): void
    {
        $seratus = substr(str_repeat('ab-', 40), 0, 100);

        $this->assertSame(100, strlen($seratus));
        $this->assertCount(PolaCari::MAKS_TOKEN, PolaCari::token($seratus));
        $this->assertSame('%ab%ab%ab%ab%ab%ab%ab%ab%', PolaCari::like($seratus));
    }

    public function test_huruf_besar_kecil_dibiarkan_untuk_kolasi_db(): void
    {
        $this->assertSame('%CAL%abc%', PolaCari::like('CAL-abc'));
        $this->assertNotSame(PolaCari::like('cal-abc'), PolaCari::like('CAL-ABC'));
    }

    public function test_byte_rusak_tidak_membuat_helper_gagal(): void
    {
        // 0xC3 berdiri sendiri bukan UTF-8 yang sah. Helper tidak boleh melempar
        // atau mengembalikan false -- pemisah ASCII tetap terpecah per byte.
        $this->assertSame("%\xC3%abc%", PolaCari::like("\xC3-abc"));
    }

    public function test_pemisah_nomor_lab_juga_pemisah_pola_pelanggan(): void
    {
        // Dua jalur (lab: REPLACE bersarang; pelanggan: pecah di pemisah) harus
        // sepakat soal apa yang dianggap pemisah. Pemisah baru di
        // `NormalisasiNomor::PEMISAH` yang lupa dipasang di sini merah di sini.
        foreach (NormalisasiNomor::PEMISAH as $pemisah) {
            $this->assertSame('%A%B%', PolaCari::like("A{$pemisah}B"), "pemisah '{$pemisah}'");
            $this->assertSame('AB', NormalisasiNomor::kunci("A{$pemisah}B"), "pemisah '{$pemisah}'");
        }
    }
}

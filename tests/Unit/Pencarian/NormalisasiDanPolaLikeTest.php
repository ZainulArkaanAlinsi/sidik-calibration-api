<?php

namespace Tests\Unit\Pencarian;

use App\Services\Pencarian\NormalisasiNomor;
use App\Services\Pencarian\PolaLike;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Aturan pembanding nomor dan pelolosan karakter `LIKE` -- murni, tanpa DB. */
class NormalisasiDanPolaLikeTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function nomorSama(): array
    {
        return [
            'format asli' => ['CAL/2026/10/0001', 'CAL2026100001'],
            'tanda hubung' => ['cal-2026-10-0001', 'CAL2026100001'],
            'spasi' => ['cal 2026 10 0001', 'CAL2026100001'],
            'campuran + spasi ujung' => ['  Cal/2026-10 0001 ', 'CAL2026100001'],
            'revisi ikut' => ['CAL/2026/10/0001-R1', 'CAL2026100001R1'],
            'titik' => ['cal.2026.10.0001', 'CAL2026100001'],
            'garis bawah' => ['CAL_2026_10_0001', 'CAL2026100001'],
        ];
    }

    #[DataProvider('nomorSama')]
    public function test_kunci_menyamakan_pemisah_dan_huruf(string $diketik, string $diharapkan): void
    {
        $this->assertSame($diharapkan, NormalisasiNomor::kunci($diketik));
    }

    public function test_nomor_dasar_adalah_awalan_nomor_revisinya(): void
    {
        $this->assertStringStartsWith(
            NormalisasiNomor::kunci('CAL/2026/10/0001'),
            NormalisasiNomor::kunci('CAL/2026/10/0001-R2'),
        );
    }

    public function test_kata_yang_cuma_pemisah_tidak_punya_kunci(): void
    {
        $this->assertSame('', NormalisasiNomor::kunci(' / - '));
        $this->assertSame('', NormalisasiNomor::kunci(' . _ '));
    }

    public function test_sql_kolom_membungkus_lima_replace_sesuai_himpunan_pemisah(): void
    {
        // Lima, dalam urutan `NormalisasiNomor::PEMISAH`: tiga pertama tidak
        // berubah sejak PR #258; `.` dan `_` ditambahkan 13 Nov 2026.
        $this->assertSame(
            "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(`c`.`nomor`, '/', ''), '-', ''), ' ', ''), '.', ''), '_', '')",
            NormalisasiNomor::sqlKolom('`c`.`nomor`'),
        );
    }

    public function test_pola_like_meloloskan_persen_garis_bawah_dan_karakter_lolos_itu_sendiri(): void
    {
        $this->assertSame('%100!%!_!!%', PolaLike::mengandung('100%_!'));
        $this->assertSame('A!_B%', PolaLike::awalan('A_B'));
    }

    public function test_klausa_like_memakai_escape_eksplisit_yang_sama_di_mysql_dan_sqlite(): void
    {
        $this->assertSame("`x` LIKE ? ESCAPE '!'", PolaLike::sql('`x`'));
    }
}

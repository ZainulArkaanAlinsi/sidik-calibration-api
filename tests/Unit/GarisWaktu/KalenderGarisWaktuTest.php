<?php

namespace Tests\Unit\GarisWaktu;

use App\Services\GarisWaktu\LabelWaktu;
use App\Services\GarisWaktu\LingkupGarisWaktu;
use App\Services\GarisWaktu\PengelompokTanggal;
use App\Services\GarisWaktu\RentangTanggal;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Kalender garis waktu: pekan ISO (Senin-Minggu) dipotong per bulan, label
 * Indonesia, dan pengelompokan hitungan per hari. Tanpa DB (butuh app hanya
 * untuk menyusun `ValidationException`).
 */
class KalenderGarisWaktuTest extends TestCase
{
    private function lingkup(array $data): LingkupGarisWaktu
    {
        return LingkupGarisWaktu::dariInput($data);
    }

    public function test_label_pekan_iso_mulai_senin(): void
    {
        $rentang = LingkupGarisWaktu::rentangPekanDalamBulan(2026, 11, 46);

        $this->assertSame('2026-11-09', $rentang->mulai->toDateString(), 'Pekan ISO mulai Senin.');
        $this->assertSame('2026-11-15', $rentang->selesai->toDateString());
        $this->assertSame('Pekan 46 (9–15 Nov 2026)', LabelWaktu::pekan(46, $rentang->mulai, $rentang->selesai));
    }

    public function test_pekan_yang_melintasi_bulan_dipotong_per_bulan_dan_jumlahnya_bisa_dijumlahkan(): void
    {
        // Pekan 44/2026 = Senin 26 Okt - Minggu 1 Nov.
        $okt = LingkupGarisWaktu::rentangPekanDalamBulan(2026, 10, 44);
        $nov = LingkupGarisWaktu::rentangPekanDalamBulan(2026, 11, 44);

        $this->assertSame(['2026-10-26', '2026-10-31'], [$okt->mulai->toDateString(), $okt->selesai->toDateString()]);
        $this->assertSame(['2026-11-01', '2026-11-01'], [$nov->mulai->toDateString(), $nov->selesai->toDateString()]);
        $this->assertSame('Pekan 44 (26–31 Okt 2026)', LabelWaktu::pekan(44, $okt->mulai, $okt->selesai));
        $this->assertSame('Pekan 44 (1 Nov 2026)', LabelWaktu::pekan(44, $nov->mulai, $nov->selesai));
    }

    public function test_pekan_1_jatuh_di_akhir_desember_dan_tidak_ambigu_di_dalam_satu_bulan(): void
    {
        // 1 Jan 2026 hari Kamis -> pekan 1/2026 mulai Senin 29 Des 2025.
        $des = LingkupGarisWaktu::rentangPekanDalamBulan(2025, 12, 1);
        $jan = LingkupGarisWaktu::rentangPekanDalamBulan(2026, 1, 1);

        $this->assertSame(['2025-12-29', '2025-12-31'], [$des->mulai->toDateString(), $des->selesai->toDateString()]);
        $this->assertSame(['2026-01-01', '2026-01-04'], [$jan->mulai->toDateString(), $jan->selesai->toDateString()]);
        // Pekan 53 hanya ada di akhir 2026 (31 Des 2026 hari Kamis).
        $this->assertNotNull(LingkupGarisWaktu::rentangPekanDalamBulan(2026, 12, 53));
        $this->assertNull(LingkupGarisWaktu::rentangPekanDalamBulan(2026, 11, 1));
    }

    public function test_label_hari_dan_bulan_berbahasa_indonesia(): void
    {
        $this->assertSame('Kamis, 12 Nov 2026', LabelWaktu::hari(RentangTanggal::tanggal('2026-11-12')));
        $this->assertSame('Minggu, 1 Nov 2026', LabelWaktu::hari(RentangTanggal::tanggal('2026-11-01')));
        $this->assertSame('November 2026', LabelWaktu::bulan(RentangTanggal::tanggal('2026-11-12')));
        $this->assertSame('Agustus 2026', LabelWaktu::bulan(RentangTanggal::tanggal('2026-08-03')));
    }

    public function test_hierarki_induk_ditegakkan(): void
    {
        foreach ([
            ['tingkat' => 'bulan'],
            ['tingkat' => 'pekan', 'tahun' => 2026],
            ['tingkat' => 'hari', 'tahun' => 2026, 'bulan' => 11],
            ['tingkat' => 'tahun', 'tahun' => 2026],
            ['tingkat' => 'bulan', 'tahun' => 2026, 'bulan' => 11],
            ['tingkat' => 'pekan', 'tahun' => 2026, 'bulan' => 11, 'pekan' => 46],
        ] as $salah) {
            try {
                $this->lingkup($salah);
                $this->fail('Seharusnya ditolak: '.json_encode($salah));
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_pekan_yang_tidak_ada_di_bulan_itu_dan_tanggal_di_luar_pekan_ditolak(): void
    {
        $this->expectException(ValidationException::class);
        $this->lingkup(['tingkat' => 'hari', 'tahun' => 2026, 'bulan' => 11, 'pekan' => 20]);
    }

    public function test_tanggal_di_luar_pekan_ditolak(): void
    {
        $this->expectException(ValidationException::class);
        $this->lingkup(['tingkat' => 'hari', 'tahun' => 2026, 'bulan' => 11, 'pekan' => 46, 'tanggal' => '2026-11-20']);
    }

    public function test_rentang_per_tingkat(): void
    {
        $this->assertNull($this->lingkup([])->rentang(), 'Tingkat tahun = semua.');

        $tahun = $this->lingkup(['tingkat' => 'bulan', 'tahun' => 2026])->rentang();
        $this->assertSame(['2026-01-01', '2026-12-31'], [$tahun->mulai->toDateString(), $tahun->selesai->toDateString()]);

        $bulan = $this->lingkup(['tingkat' => 'pekan', 'tahun' => 2026, 'bulan' => 2])->rentang();
        $this->assertSame(['2026-02-01', '2026-02-28'], [$bulan->mulai->toDateString(), $bulan->selesai->toDateString()]);

        $hari = $this->lingkup(['tingkat' => 'hari', 'tahun' => 2026, 'bulan' => 11, 'pekan' => 46, 'tanggal' => '2026-11-12'])->rentang();
        $this->assertSame(['2026-11-12', '2026-11-12'], [$hari->mulai->toDateString(), $hari->selesai->toDateString()]);
    }

    public function test_pengelompokan_menjumlahkan_naik_dan_terbaru_dulu(): void
    {
        $perHari = ['2025-12-30' => 1, '2026-10-26' => 2, '2026-10-31' => 3, '2026-11-01' => 4, '2026-11-12' => 5];
        $pengelompok = new PengelompokTanggal;

        $tahun = $pengelompok->kelompokkan($perHari, $this->lingkup([]));
        $this->assertSame(['2026' => 14, '2025' => 1], array_column($tahun, 'jumlah', 'kunci'));
        $this->assertSame('2026', $tahun[0]['label']);
        $this->assertSame(['tingkat' => 'bulan', 'tahun' => 2026], $tahun[0]['telusuri']);

        $perHari2026 = array_filter($perHari, fn (string $ymd): bool => str_starts_with($ymd, '2026'), ARRAY_FILTER_USE_KEY);
        $bulan = $pengelompok->kelompokkan($perHari2026, $this->lingkup(['tingkat' => 'bulan', 'tahun' => 2026]));
        $this->assertSame(['2026-11' => 9, '2026-10' => 5], array_column($bulan, 'jumlah', 'kunci'));
        $this->assertSame('November 2026', $bulan[0]['label']);
        $this->assertSame(['tingkat' => 'pekan', 'tahun' => 2026, 'bulan' => 11], $bulan[0]['telusuri']);
    }

    public function test_pengelompokan_pekan_di_dalam_satu_bulan(): void
    {
        $perHari = ['2026-10-26' => 2, '2026-10-31' => 3, '2026-10-05' => 1];
        $pekan = (new PengelompokTanggal)->kelompokkan(
            $perHari,
            $this->lingkup(['tingkat' => 'pekan', 'tahun' => 2026, 'bulan' => 10]),
        );

        $this->assertSame(['2026-W44' => 5, '2026-W41' => 1], array_column($pekan, 'jumlah', 'kunci'));
        $this->assertSame('Pekan 44 (26–31 Okt 2026)', $pekan[0]['label']);
        $this->assertSame(['2026-10-26', '2026-10-31'], [$pekan[0]['mulai'], $pekan[0]['selesai']]);
        $this->assertSame(['tingkat' => 'hari', 'tahun' => 2026, 'bulan' => 10, 'pekan' => 44], $pekan[0]['telusuri']);
    }

    public function test_pengelompokan_hari_membawa_alamat_tanggalnya(): void
    {
        $hari = (new PengelompokTanggal)->kelompokkan(
            ['2026-11-09' => 1, '2026-11-12' => 2],
            $this->lingkup(['tingkat' => 'hari', 'tahun' => 2026, 'bulan' => 11, 'pekan' => 46]),
        );

        $this->assertSame(['2026-11-12', '2026-11-09'], array_column($hari, 'kunci'));
        $this->assertSame('Kamis, 12 Nov 2026', $hari[0]['label']);
        $this->assertSame(
            ['tahun' => 2026, 'bulan' => 11, 'pekan' => 46, 'tingkat' => 'hari', 'tanggal' => '2026-11-12'],
            $hari[0]['telusuri'],
        );
    }
}

<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Support\TataLetakSertifikat;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * S-4 (banding ulang pressure_gauge, vacuum_gauge, differential_pressure, tids,
 * bath, furnace, refrigerator): perbaikan bentuk HANYA berlaku untuk snapshot
 * BARU. Sertifikat yang sudah terbit beku di snapshot-nya dan cetak ulangnya
 * harus keluar IDENTIK byte demi byte — jadi tes ini merah = bentuk cetak
 * sertifikat terbit ikut berubah (cacat), bukan "perbarui hash".
 *
 * Fixture `tests/Fixtures/sertifikat-beku/{pressure_gauge,vacuum_gauge,
 * differential_pressure,tids,bath_yokogawa,bath_recorder}.snapshot.json` =
 * snapshot yang dibangun `CertificateSnapshotBuilder` dari origin/main
 * `006f1b68` (SEBELUM perubahan S-4; data seeder sintetis, tanpa nama
 * pelanggan asli). Sidik jari HTML-nya direkam dari renderer SEBELUM perubahan.
 *
 * Tanpa database (cuma render view dari JSON), jadi cepat.
 */
class SertifikatS4BekuTest extends TestCase
{
    /** sha256 HTML render snapshot beku, direkam sebelum perubahan S-4. */
    private const SIDIK_JARI = [
        'pressure_gauge' => '50142ef46bc537ebb8e78e89a2393e2a67aa31c7550658803492fb7858d1799b',
        'vacuum_gauge' => '2dcde1223023cb3a038b074231bc99045ff7fbd204e9c9f797ae7c9daea60bc5',
        'differential_pressure' => '880a66325b08a2df4d74a3d88ef5ff0fcf49e2c5398aace4fc758372020ba7ae',
        'tids' => '14bdaa4e9ea1ca309e564772097e30a8bcf2c4c3e4b438c40dc33a4049942ff4',
        'bath_yokogawa' => 'c31174fde296e5539676d0da2a86693f3e2a4e771df655388dbf408c9caf2072',
        'bath_recorder' => '675cf59417f2ff5f4f14f47506eb8eb81679e1aa5f8c82e37cd76ac78b5a7104',
    ];

    /** Kunci tata letak baru S-4 — tidak boleh ada di snapshot beku. */
    private const KUNCI_BARU = ['judul_kelompok_master', 'u95_dalam_tabel'];

    /** @return array<string, mixed> */
    private function fixture(string $nama): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/sertifikat-beku/{$nama}.snapshot.json")), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $snapshot */
    private function render(array $snapshot): string
    {
        app()->setLocale('en');

        return view('sertifikat.pdf', [
            'sertifikat' => new Certificate,
            'snapshot' => $snapshot,
            'logo' => null,
            'kop' => null,
            'tandaTangan' => null,
            'posisiTtd' => [],
            'ukuranTtd' => [],
            'qr' => null,
            'keputusan' => null,
            'web' => false,
            'paksaPadat' => false,
        ])->render();
    }

    public function test_cetak_ulang_snapshot_lama_identik_byte_per_byte(): void
    {
        $sekarang = [];

        foreach (array_keys(self::SIDIK_JARI) as $nama) {
            $html = $this->render($this->fixture($nama));
            $sekarang[$nama] = hash('sha256', $html);
            $this->assertSame($sekarang[$nama], hash('sha256', $this->render($this->fixture($nama))), "{$nama}: render tidak deterministik");
        }

        if (getenv('REKAM_SIDIK_JARI') === '1') {
            fwrite(STDERR, "\n".var_export($sekarang, true)."\n");
            $this->markTestSkipped('Mode rekam.');
        }

        $this->assertSame(self::SIDIK_JARI, $sekarang);
    }

    public function test_fixture_beku_tidak_memuat_kunci_baru_dan_bentuk_lama_tetap(): void
    {
        foreach (array_keys(self::SIDIK_JARI) as $nama) {
            $snapshot = $this->fixture($nama);
            $tl = $snapshot['tata_letak'] ?? [];

            foreach (self::KUNCI_BARU as $kunci) {
                $this->assertArrayNotHasKey($kunci, $tl, "{$nama}: {$kunci}");
            }

            $html = $this->render($snapshot);

            // Bentuk lama: judul kapital enclosure, U95 tekanan di bawah tabel,
            // kalimat k huruf kecil, baris `Standar: …` enclosure.
            if (str_starts_with($nama, 'bath')) {
                $this->assertStringContainsString('A) SEBARAN SUHU', $html, $nama);
                $this->assertStringContainsString('B) KINERJA ENKLOSUR', $html, $nama);
                $this->assertStringContainsString('Standar: ', (string) $snapshot['catatan_atas_hasil'], $nama);
            }

            if (str_contains($nama, 'pressure') || str_contains($nama, 'vacuum')) {
                $this->assertStringNotContainsString('u95-dalam-tabel', $html, $nama);
                $this->assertStringContainsString('The uncertainty is taken at a confidence level 95 % and coverage factor ( k ) =', $html, $nama);
            }
        }
    }

    public function test_kunci_tata_letak_baru_diterima_hanya_sebagai_boolean(): void
    {
        $this->assertSame(
            ['judul_kelompok_master' => true, 'u95_dalam_tabel' => true],
            TataLetakSertifikat::normalisasi(['judul_kelompok_master' => true, 'u95_dalam_tabel' => true]),
        );

        foreach (self::KUNCI_BARU as $kunci) {
            $this->assertFalse(TataLetakSertifikat::BAWAAN[$kunci], "{$kunci}: bawaan harus false (bentuk lama)");

            try {
                TataLetakSertifikat::normalisasi([$kunci => 'ya']);
                $this->fail("{$kunci} bukan boolean seharusnya dilempar.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

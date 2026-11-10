<?php

namespace Tests\Feature;

use App\Models\Certificate;
use Tests\TestCase;

/**
 * Sertifikat yang SUDAH terbit beku di snapshot (`pdf.blade.php` membaca
 * `tata_letak`, `catatan_blok`, `header_tambahan` dari snapshot). Perbaikan
 * G0-a (bentuk "End of Certificate", titik dua kepala, baris U95 UFM) hanya
 * boleh berlaku untuk snapshot BARU: cetak ulang snapshot lama harus keluar
 * IDENTIK byte demi byte.
 *
 * Fixture `tests/Fixtures/sertifikat-beku/*.snapshot.json` = snapshot
 * K-50-15 yang dibangun `CertificateSnapshotBuilder` dari c617ad44 (SEBELUM
 * perubahan G0-a; data seeder sintetis). Sidik jari HTML-nya (sha256) direkam
 * dari renderer SEBELUM perubahan dan dikunci di bawah. Kalau tes ini merah,
 * bentuk cetak sertifikat terbit ikut berubah — itu bukan "memperbarui hash",
 * itu cacat: gerbang perubahan bentuk harus kunci di snapshot baru.
 */
class SertifikatBekuTidakBerubahTest extends TestCase
{
    /** sha256 HTML render snapshot beku, direkam sebelum perubahan G0-a. */
    private const SIDIK_JARI = [
        'micrometer' => 'd991b4619f11d4270bb441d0e2f8e6a46d6e94684da94416765fe698ae53c6c1',
        'micrometer@tanpa_tata_letak' => 'd2d119255c4f1f83625798ef6bffe01780fb2dbdb7990222886f0dfdbb011cbb',
        'flowmeter_totalizer' => '0a01df2a178795a1afc630140bb3761d0b3b61282e28796c592ee5e5715e4a29',
        'flowmeter_flowrate' => '9bcef4265fedd223fccf06eb98ef3295925d80bb7029fd2fbb0e8acc763a2fc6',
        'timbangan' => '9094666f208786fb4a4406a8265c645ad2af22e73e1f4d50af6ee4539a8cbba0',
        'labu_ukur' => 'c9b9a9c4057a755f8fda26ec3f5046ec4bb158fc47e826fed45e85fb6f00aa37',
        'thermocouple' => '38e8e26c95d4e0eb669fa1bbcf777f3069273c5a006f7f96138f64f5d1228015',
        'gas_detector' => '1d75fad995392dd5b2cc69a47529d1afd5f3fcdcc43dfd329d160d6daae35130',
    ];

    /** @return array<string, mixed> */
    private function fixture(string $nama): array
    {
        [$kode, $varian] = array_pad(explode('@', $nama, 2), 2, null);
        $snapshot = json_decode((string) file_get_contents(base_path("tests/Fixtures/sertifikat-beku/{$kode}.snapshot.json")), true, 512, JSON_THROW_ON_ERROR);

        if ($varian === 'tanpa_tata_letak') {
            unset($snapshot['tata_letak'], $snapshot['catatan_blok'], $snapshot['header_tambahan']);
        }

        return $snapshot;
    }

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
            // Render dua kali di proses yang sama: renderer deterministik.
            $this->assertSame($sekarang[$nama], hash('sha256', $this->render($this->fixture($nama))), "{$nama}: render tidak deterministik");
        }

        if (getenv('REKAM_SIDIK_JARI') === '1') {
            fwrite(STDERR, "\n".var_export($sekarang, true)."\n");
            $this->markTestSkipped('Mode rekam.');
        }

        $this->assertSame(self::SIDIK_JARI, $sekarang);
    }

    public function test_fixture_beku_tidak_memuat_kunci_baru(): void
    {
        foreach (array_keys(self::SIDIK_JARI) as $nama) {
            $tl = $this->fixture($nama)['tata_letak'] ?? [];
            $this->assertArrayNotHasKey('akhir_sertifikat_garis', $tl, $nama);
            $this->assertArrayNotHasKey('kepala_titik_dua', $tl, $nama);
            $this->assertArrayNotHasKey('u95_relatif_menggantikan_baris', $tl, $nama);
        }
    }
}

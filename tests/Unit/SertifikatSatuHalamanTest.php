<?php

namespace Tests\Unit;

use App\Exceptions\SertifikatLebihSatuHalamanException;
use App\Services\SertifikatSatuHalaman;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Sertifikat wajib satu halaman — dan sekarang itu DIPASTIKAN, bukan diharapkan.
 *
 * ## Yang dijaga
 *
 * Header sertifikat mencetak `Page : 1 of 1`, dan angka itu ditulis mati di
 * `CertificateSnapshotBuilder` lalu dibekukan ke snapshot waktu terbit — tidak
 * pernah dihitung dari halaman yang benar-benar dirender. Jadi lembar yang
 * meluap ke halaman dua tetap mencetak "1 of 1" di kedua halamannya: dokumen
 * terkendali yang menyatakan hal yang tidak benar tentang dirinya sendiri.
 *
 * Aturan pemilihannya diuji terpisah dari dompdf. Yang dipalsukan di sini cuma
 * "berapa halaman hasilnya" — bagian yang mahal dan lambat — sementara
 * keputusannya (kapan memadatkan ulang, kapan menyerah, kapan mencatat error)
 * yang justru gampang salah, diuji utuh.
 */
class SertifikatSatuHalamanTest extends TestCase
{
    private function layanan(): SertifikatSatuHalaman
    {
        return app(SertifikatSatuHalaman::class);
    }

    /**
     * @param  array<int, int>  $halaman  jumlah halaman per percobaan
     * @return callable(bool): array{isi: string, halaman: int}
     */
    private function cetakPalsu(array $halaman, array &$jejak): callable
    {
        return function (bool $paksaPadat) use ($halaman, &$jejak): array {
            $jejak[] = $paksaPadat;

            return [
                'isi' => $paksaPadat ? 'PDF-PADAT' : 'PDF-NORMAL',
                'halaman' => $halaman[count($jejak) - 1],
            ];
        };
    }

    /** Sudah muat sejak awal: JANGAN render dua kali — itu ongkos percuma. */
    public function test_yang_sudah_muat_cuma_dirender_sekali(): void
    {
        $jejak = [];
        $isi = $this->layanan()->paling($this->cetakPalsu([1], $jejak), 7);

        $this->assertSame('PDF-NORMAL', $isi);
        $this->assertSame([false], $jejak, 'Render kedua nggak boleh jalan kalau yang pertama udah muat.');
    }

    /** Meluap → dipadatkan ulang, dan versi padat itu yang dipakai. */
    public function test_yang_meluap_dipadatkan_ulang(): void
    {
        $jejak = [];
        $isi = $this->layanan()->paling($this->cetakPalsu([2, 1], $jejak), 7);

        $this->assertSame('PDF-PADAT', $isi);
        $this->assertSame([false, true], $jejak, 'Percobaan kedua wajib memaksa mode padat.');
    }

    /**
     * Masih meluap walau sudah dipaksa padat → DITOLAK, bukan diterbitkan.
     *
     * Dulu versi terpadatnya tetap dikembalikan dengan satu baris log, dan PDF
     * dua halaman itu terbit bertulis "Page : 1 of 1". Sekarang pengecualian
     * dilempar (pemanggil penerbit menandai sertifikat `gagal`), dan kegagalannya
     * tetap meninggalkan log error.
     */
    public function test_yang_tetap_meluap_ditolak_dan_dicatat_sebagai_error(): void
    {
        Log::spy();

        $jejak = [];

        try {
            $this->layanan()->paling($this->cetakPalsu([3, 2], $jejak), 42);
            $this->fail('Sertifikat >1 halaman seharusnya ditolak, bukan dikembalikan.');
        } catch (SertifikatLebihSatuHalamanException $e) {
            $this->assertSame(42, $e->sertifikatId);
            $this->assertSame(3, $e->halamanNormal);
            $this->assertSame(2, $e->halamanPadat);
            $this->assertStringContainsString('TIDAK diterbitkan', $e->getMessage());
            // Notifikasi admin memotong pesan di 160 karakter: alasan harus muat.
            $this->assertLessThanOrEqual(160, mb_strlen($e->getMessage()));
        }

        $this->assertSame([false, true], $jejak, 'Mode padat tetap WAJIB dicoba sebelum menolak.');

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $pesan, array $konteks): bool {
                return str_contains($pesan, 'nggak muat satu halaman')
                    && $konteks['certificate_id'] === 42
                    && $konteks['halaman_normal'] === 3
                    && $konteks['halaman_padat'] === 2;
            });
    }

    /** Gerbang TIDAK dilonggarkan: dua halaman di mode padat ditolak, satu halaman di mode padat tetap lolos. */
    public function test_ambang_satu_halaman_tidak_berubah(): void
    {
        $jejak = [];
        $this->assertSame('PDF-PADAT', $this->layanan()->paling($this->cetakPalsu([2, 1], $jejak), 7));

        $this->expectException(SertifikatLebihSatuHalamanException::class);
        $jejak = [];
        $this->layanan()->paling($this->cetakPalsu([2, 2], $jejak), 7);
    }

    /** Yang berhasil TIDAK boleh mencatat error — log palsu melatih orang mengabaikannya. */
    public function test_yang_berhasil_nggak_nyampah_di_log(): void
    {
        Log::spy();

        $jejak = [];
        $this->layanan()->paling($this->cetakPalsu([2, 1], $jejak), 7);

        Log::shouldNotHaveReceived('error');
    }

    /**
     * Blade wajib benar-benar membaca `paksaPadat`.
     *
     * Seluruh layanan ini sia-sia kalau template mengabaikan saklarnya — dan
     * itu kegagalan yang tidak menghasilkan error apa pun, cuma sertifikat yang
     * tetap dua halaman.
     */
    public function test_blade_membaca_paksa_padat(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/sertifikat/pdf.blade.php',
        );

        $this->assertStringContainsString(
            '($paksaPadat ?? false)',
            $blade,
            'Blade berhenti membaca `paksaPadat` — pemadatan ulangnya jadi nggak ada efeknya.',
        );
    }
}

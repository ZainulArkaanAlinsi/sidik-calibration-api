<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Yang dijalankan `docker/entrypoint.sh` di boot harus benar-benar ada.
 *
 * ## Kenapa dijaga dari sini
 *
 * Semua di entrypoint jalan SEBELUM server menerima request, di dalam jendela
 * health check Render yang cuma 15 menit. Nama perintah yang salah ketik di
 * situ tidak menghasilkan apa pun sampai deploy — lalu gagal di tempat yang
 * paling mahal, pada container yang belum melayani siapa-siapa.
 *
 * Dan sebagian di antaranya TIDAK punya jalur lain: paket gratis Render tidak
 * menyediakan shell sama sekali ("Shell is not supported for free compute
 * plans"), jadi impor direktori dan bangun ulang sertifikat cuma bisa lewat
 * boot. Kalau baris itu diam-diam mati, tidak ada tempat lain buat
 * menjalankannya.
 */
class EntrypointBootTest extends TestCase
{
    private const ENTRYPOINT = 'docker/entrypoint.sh';

    public function test_semua_perintah_artisan_di_entrypoint_beneran_terdaftar(): void
    {
        $terdaftar = array_keys(Artisan::all());
        $dipakai = $this->perintahArtisanDiEntrypoint();

        $this->assertNotEmpty($dipakai, 'Nggak nemu satu pun `php artisan` di entrypoint — regex-nya yang rusak.');

        foreach ($dipakai as $perintah) {
            $this->assertContains(
                $perintah,
                $terdaftar,
                "docker/entrypoint.sh manggil `php artisan {$perintah}`, tapi perintah itu "
                .'nggak terdaftar. Salah ketik di sini baru ketahuan waktu deploy, di dalam '
                .'jendela health check Render yang cuma 15 menit.',
            );
        }
    }

    /**
     * Saklar boot wajib ada di `.env.example` DAN `render.yaml`.
     *
     * Aturan proyek (CLAUDE.md §9), dan lahir dari kejadian nyata: key yang
     * cuma ada di salah satunya bikin pemasangan baru jatuh ke bawaan diam-diam
     * — tanpa error, dan tanpa cara memeriksanya dari luar.
     */
    public function test_saklar_boot_terdaftar_di_env_example_dan_blueprint(): void
    {
        foreach (['SEED_ON_BOOT', 'BANGUN_ULANG_ON_BOOT'] as $key) {
            $this->assertStringContainsString(
                $key,
                (string) file_get_contents(base_path('.env.example')),
                "{$key} nggak ada di .env.example.",
            );

            $this->assertStringContainsString(
                "key: {$key}",
                (string) file_get_contents(base_path('render.yaml')),
                "{$key} nggak ada di render.yaml — pemasangan lewat blueprint bakal "
                .'kehilangan saklarnya tanpa satu pun error.',
            );
        }
    }

    /**
     * Bangun ulang sertifikat HARUS digerbangi saklar, bukan jalan tiap boot.
     *
     * Perintahnya menulis ulang setiap berkas PDF tiap kali jalan. Dibiarkan
     * tanpa gerbang, tiap kali Render membangunkan service yang ketiduran
     * ongkosnya dibayar lagi — diambil dari jendela health check yang sama.
     */
    public function test_bangun_ulang_digerbangi_saklarnya(): void
    {
        $isi = (string) file_get_contents(base_path(self::ENTRYPOINT));

        $posisiGerbang = strpos($isi, 'if [ "${BANGUN_ULANG_ON_BOOT}" = "true" ]');
        $posisiPerintah = strpos($isi, 'php artisan sertifikat:bangun-ulang');

        $this->assertNotFalse($posisiGerbang, 'Gerbang BANGUN_ULANG_ON_BOOT hilang dari entrypoint.');
        $this->assertNotFalse($posisiPerintah, 'Panggilan sertifikat:bangun-ulang hilang dari entrypoint.');
        $this->assertLessThan(
            $posisiPerintah,
            $posisiGerbang,
            'Bangun ulang jalan DI LUAR gerbangnya — tiap boot bakal nulis ulang semua PDF.',
        );
    }

    /**
     * Nama perintah `php artisan <x>` yang muncul di entrypoint.
     *
     * @return list<string>
     */
    private function perintahArtisanDiEntrypoint(): array
    {
        preg_match_all(
            '/php artisan ([a-z][a-z0-9:_-]*)/',
            (string) file_get_contents(base_path(self::ENTRYPOINT)),
            $cocok,
        );

        return array_values(array_unique($cocok[1]));
    }
}

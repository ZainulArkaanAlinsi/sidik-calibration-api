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

    /**
     * Tiap `php artisan <x>` di entrypoint harus perintah yang benar-benar ada.
     *
     * Menjaga LEBIH dari satu saklar: yang diperiksa seluruh isi berkasnya,
     * jadi perintah apa pun yang ditambahkan ke jalur boot nanti ikut terjaga
     * tanpa test baru.
     */
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
        $envExample = (string) file_get_contents(base_path('.env.example'));
        $blueprint = (string) file_get_contents(base_path('render.yaml'));

        foreach (['SEED_ON_BOOT', 'BANGUN_ULANG_ON_BOOT'] as $key) {
            // Diadu ke BARIS PENUGASANNYA, bukan ke kemunculan namanya.
            //
            // Temuan review: versi pertama memakai `assertStringContainsString`,
            // dan komentar penjelas di atas baris itu — yang ditulis di commit
            // yang sama — sudah cukup memuaskannya. Penugasannya bisa dihapus
            // dan test tetap hijau.
            $this->assertMatchesRegularExpression(
                '/^'.preg_quote($key, '/').'=/m',
                $envExample,
                "{$key} nggak punya baris penugasan di .env.example — kesebut di "
                .'komentar doang nggak bikin dia kebaca siapa pun.',
            );

            $this->assertStringContainsString(
                "key: {$key}",
                $blueprint,
                "{$key} nggak ada di render.yaml — pemasangan lewat blueprint bakal "
                .'kehilangan saklarnya tanpa satu pun error.',
            );
        }

        // `sync: false` DIWAJIBKAN, dan cuma buat saklar bangun ulang.
        //
        // Bedanya bukan gaya penulisan. `value:` artinya BLUEPRINT yang
        // menentukan, jadi deploy berikutnya menyinkronkan nilainya dan
        // mematikan saklar yang baru saja dinyalakan lewat dashboard — persis
        // kejadian 1 Sep 2026 waktu ARSIP_DRIVER ketimpa balik ke `local`, dan
        // produksi diam-diam kembali menulis ke disk yang kehapus tiap deploy.
        //
        // Di sini akibatnya lebih halus tapi arahnya sama: saklar yang mati
        // duluan berarti sertifikat yang sudah terbit TIDAK ikut dibangun ulang,
        // dan deploy-nya kelihatan berhasil.
        //
        // SEED_ON_BOOT sengaja TIDAK ikut dijaga di sini. Dia memang
        // `value: "false"` di blueprint, dan itu bukan kelalaian yang mau
        // dibetulkan sambil lalu: arah timpanya aman (mati = seeder nggak
        // jalan), sementara di sini arah timpanya merugikan. Kalau suatu saat
        // mau diseragamkan, itu perubahan tersendiri dengan alasannya sendiri.
        $this->assertMatchesRegularExpression(
            '/-\s*key:\s*BANGUN_ULANG_ON_BOOT\s*\n\s*sync:\s*false/',
            $blueprint,
            'BANGUN_ULANG_ON_BOOT di render.yaml nggak `sync: false`. Dengan `value:`, '
            .'deploy berikutnya nimpa balik saklar yang baru disetel lewat dashboard, '
            .'dan sertifikat lama diam-diam nggak ikut dibangun ulang.',
        );
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
        $this->assertNotFalse($posisiGerbang, 'Gerbang BANGUN_ULANG_ON_BOOT hilang dari entrypoint.');

        // Isi blok `if … fi`-nya, bukan "apa pun yang muncul sesudah gerbang".
        //
        // Temuan review: versi pertama cuma membandingkan posisi, jadi blok
        // `if` KOSONG yang diikuti perintah tak berpagar tetap lolos — bentuk
        // yang justru paling gampang lahir dari penyuntingan yang salah.
        $posisiTutup = strpos($isi, "\nfi\n", $posisiGerbang);
        $this->assertNotFalse($posisiTutup, 'Blok gerbangnya nggak pernah ditutup `fi`.');

        $this->assertStringContainsString(
            'php artisan sertifikat:bangun-ulang',
            substr($isi, $posisiGerbang, $posisiTutup - $posisiGerbang),
            'Bangun ulang jalan DI LUAR gerbangnya — tiap boot, termasuk tiap Render '
            .'membangunkan service yang ketiduran, bakal nulis ulang semua PDF.',
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

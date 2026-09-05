<?php

namespace Tests\Feature;

use App\Services\Direktori\DirektoriPerusahaan;
use App\Services\Direktori\NominatimDirektori;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `GET /api/health` melaporkan apakah key direktori perusahaan sudah kebaca
 * server.
 *
 * ## Kenapa ini ada di endpoint PUBLIK
 *
 * Pertanyaannya sepele dan sering: "key-nya udah nyampe server belum?" Tanpa
 * ini, jawabannya cuma bisa didapat dengan login sebagai teknisi lalu menekan
 * tombol cari, atau membuka dashboard penyedia hosting — dua-duanya butuh orang
 * yang megang akunnya. Setelan yang diubah jam sebelas malam jadi nggak bisa
 * diperiksa siapa pun sampai besok pagi.
 *
 * ## Batas yang bikin dia aman dibiarkan tanpa auth
 *
 * Dijaga berkas ini, dan dua-duanya harus tetap benar:
 *
 *  1. **Nilai key-nya nggak pernah ikut**, panjangnya juga nggak.
 *  2. **NOL request ke penyedia.** `tersedia()` cuma membaca config. Kalau
 *     suatu saat ada yang mengubahnya jadi memanggil Google buat "memastikan
 *     key-nya beneran sah", endpoint publik ini langsung jadi keran kuota
 *     berbayar yang bisa dikuras siapa pun yang tahu URL-nya.
 */
class HealthDirektoriTest extends TestCase
{
    public function test_key_disetel_dilaporkan_tanpa_membocorkan_nilainya(): void
    {
        // Driver berbayar dipatok: cuma di situ "disetel" punya dua nilai.
        // Driver OpenStreetMap (bawaan) nggak punya key, jadi selalu siap —
        // diuji terpisah di `DirektoriOsmTest`.
        config()->set('services.direktori_perusahaan.driver', 'google');
        config()->set('services.direktori_perusahaan.key', 'kunci-rahasia-banget');
        Http::fake();

        $respons = $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('direktori_perusahaan.disetel', true);

        $this->assertStringNotContainsString(
            'kunci-rahasia-banget',
            (string) $respons->getContent(),
        );

        // Daftar TERTUTUP, bukan sekadar "yang ini ada". Panjang key, empat
        // huruf pertamanya, atau apa pun turunan NILAI KEY-nya nggak boleh
        // nyelip — endpoint ini kebuka tanpa login, dan tiap potongan
        // mempersempit tebakan orang yang mencoba menyusunnya ulang.
        //
        // `driver` & `bisa_ditagih` boleh ikut karena keduanya turunan SETELAN
        // DRIVER, bukan turunan key: nilainya sama saja apakah key-nya
        // `kunci-rahasia-banget`, kosong, atau salah. Itu garis yang
        // membedakan "status" dari "rahasia", dan daftar ini yang menjaganya —
        // menambah field baru ke blok ini WAJIB lewat sini dulu.
        //
        // `lokal` lolos garis yang sama, dan sengaja diperiksa isinya juga:
        // dia `{aktif, baris}` yang diturunkan dari JUMLAH BARIS tabel
        // direktori rujukan — nol hubungannya dengan key, dan nilainya tidak
        // berubah sedikit pun kalau key-nya diganti. Yang TIDAK boleh nyelip ke
        // sini nama perusahaannya: sepuluh ribu nama PT di endpoint tanpa login
        // itu jalur ekspor diam-diam, bukan laporan status.
        $this->assertSame(
            ['disetel', 'driver', 'bisa_ditagih', 'lokal'],
            array_keys($respons->json('direktori_perusahaan')),
        );

        $this->assertSame(
            ['aktif', 'baris'],
            array_keys($respons->json('direktori_perusahaan.lokal')),
        );
    }

    public function test_key_kosong_dilaporkan_belum_disetel(): void
    {
        config()->set('services.direktori_perusahaan.driver', 'google');
        config()->set('services.direktori_perusahaan.key', null);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('direktori_perusahaan.disetel', false);
    }

    /**
     * Ini yang bikin endpoint publiknya aman: dia nggak pernah menyentuh
     * penyedia berbayar, jadi nggak bisa dipakai orang luar buat menghabiskan
     * kuota lab.
     */
    public function test_tidak_pernah_menembak_penyedia_berbayar(): void
    {
        config()->set('services.direktori_perusahaan.driver', 'google');
        config()->set('services.direktori_perusahaan.key', 'kunci-uji');
        Http::fake();

        $this->getJson('/api/health')->assertOk();

        Http::assertNothingSent();
    }

    /** Endpointnya tetap kebuka tanpa login — itu seluruh gunanya. */
    public function test_kebuka_tanpa_login(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    /**
     * **Kenapa `driver` ditambah, dan kenapa `disetel` sendirian nggak cukup.**
     *
     * `disetel` itu `true` buat `osm` MAUPUN `auto`. Yang pertama gratis; yang
     * kedua menembak Google duluan dan ditagih begitu kuota bulanannya lewat.
     * Dua keadaan yang bedanya uang, dilaporkan dengan angka yang sama persis.
     *
     * Itu bukan kasus tepi: waktu bawaan direktori dipindah ke OSM, nilainya
     * tertinggal di `auto` dan nggak ada satu pun cara memeriksanya dari luar.
     * Yang akhirnya menemukan tagihan Google Cloud, bukan endpoint ini.
     */
    public function test_osm_dan_auto_bisa_dibedakan_padahal_disetelnya_sama(): void
    {
        config()->set('services.direktori_perusahaan.driver', 'osm');
        $this->getJson('/api/health')
            ->assertJsonPath('direktori_perusahaan.disetel', true)
            ->assertJsonPath('direktori_perusahaan.driver', 'osm')
            ->assertJsonPath('direktori_perusahaan.bisa_ditagih', false);

        config()->set('services.direktori_perusahaan.driver', 'auto');
        $this->getJson('/api/health')
            ->assertJsonPath(
                'direktori_perusahaan.disetel', true,
                // Sengaja ditegaskan: `disetel` memang sama. Itu justru
                // alasannya `driver` ada.
            )
            ->assertJsonPath('direktori_perusahaan.driver', 'auto')
            ->assertJsonPath('direktori_perusahaan.bisa_ditagih', true);
    }

    /**
     * Yang dilaporkan driver EFEKTIF, bukan isi `.env` apa adanya.
     *
     * `osmm` yang salah ketik jatuh ke `osm` — dan yang membaca health perlu
     * tahu yang JALAN yang mana, bukan yang diketik. Memantulkan `osmm` apa
     * adanya bikin dia nggak tahu lab-nya sedang ditagih atau nggak.
     */
    public function test_salah_ketik_dilaporkan_sebagai_yang_beneran_jalan(): void
    {
        config()->set('services.direktori_perusahaan.driver', 'osmm');

        $this->getJson('/api/health')
            ->assertJsonPath('direktori_perusahaan.driver', 'osm')
            ->assertJsonPath('direktori_perusahaan.bisa_ditagih', false);
    }

    /**
     * **Ini yang bikin angkanya boleh dipercaya.**
     *
     * Health yang MELAPORKAN `osm` sementara container membangun jalur
     * berbayar lebih buruk daripada health yang nggak melaporkan apa-apa: yang
     * pertama bikin orang berhenti memeriksa. Jadi yang diadu di sini bukan
     * laporannya saja, tapi laporannya LAWAN penyedia yang beneran lahir dari
     * container.
     *
     * Diadu ke ATRIBUSI, bukan nama kelas: tiap lapis dibungkus
     * `DirektoriBercache`, dan pembungkus datang dan pergi sementara janji
     * "yang menjawab OSM" nggak.
     */
    public function test_yang_dilaporkan_sama_dengan_yang_beneran_dibangun(): void
    {
        config()->set('services.direktori_perusahaan.key', 'kunci-uji');

        foreach (['osm', 'google', 'auto', 'ngawur'] as $disetel) {
            config()->set('services.direktori_perusahaan.driver', $disetel);
            $this->app->forgetInstance(DirektoriPerusahaan::class);

            $dilaporkan = $this->getJson('/api/health')
                ->json('direktori_perusahaan.driver');

            $nyata = $this->app->make(DirektoriPerusahaan::class);

            $harusnya = match ($dilaporkan) {
                'osm' => NominatimDirektori::ATRIBUSI,
                'google' => 'Powered by Google',
                // Berlapis belum menjawab `cari()`, jadi atribusinya masih
                // kosong — dan itu justru sidik jarinya.
                'auto' => null,
                default => $this->fail("Driver tak terduga: {$dilaporkan}"),
            };

            $this->assertSame(
                $harusnya, $nyata->atribusi(),
                "Health bilang `{$dilaporkan}` untuk setelan `{$disetel}`, "
                .'tapi yang dibangun container penyedia lain.',
            );
        }
    }

    /**
     * Nama driver itu status, bukan rahasia — tapi batas lamanya tetap:
     * key-nya nggak pernah ikut, dan nol request ke penyedia.
     */
    public function test_field_baru_tidak_menembak_penyedia_dan_tidak_bocor(): void
    {
        config()->set('services.direktori_perusahaan.driver', 'auto');
        config()->set('services.direktori_perusahaan.key', 'kunci-rahasia-banget');
        Http::fake();

        $isi = $this->getJson('/api/health')->assertOk()->content();

        Http::assertNothingSent();
        $this->assertStringNotContainsString('kunci-rahasia-banget', $isi);
    }
}

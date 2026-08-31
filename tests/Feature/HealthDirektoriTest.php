<?php

namespace Tests\Feature;

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

        // Cuma `disetel` yang boleh ada di situ. Panjang key, empat huruf
        // pertamanya, atau apa pun turunan nilainya nggak boleh nyelip —
        // endpoint ini kebuka tanpa login, dan tiap potongan mempersempit
        // tebakan orang yang mencoba menyusunnya ulang.
        $this->assertSame(
            ['disetel'],
            array_keys($respons->json('direktori_perusahaan')),
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
}

<?php

namespace Tests\Feature;

use App\Services\Direktori\DirektoriBercache;
use App\Services\Direktori\DirektoriBerlapis;
use App\Services\Direktori\DirektoriGagal;
use App\Services\Direktori\DirektoriPerusahaan;
use App\Services\Direktori\PerusahaanDitemukan;
use Tests\TestCase;

/**
 * Cache direktori perusahaan — yang dijaga di sini TAGIHAN, dan satu kewajiban
 * lisensi yang gampang ikut hilang bersamanya.
 *
 * Places API ditagih per request. Sebelum [DirektoriBercache] ada, tidak ada
 * satu pun cache di jalur itu: pencarian nama pabrik yang sama, oleh teknisi
 * yang sama, di hari yang sama, ditagih berulang kali.
 *
 * Yang paling mudah salah bukan cache-nya, tapi LETAKNYA. Dipasang membungkus
 * `DirektoriBerlapis`, cache-hit membuat `cari()` tidak pernah jalan — dan
 * atribusi lapis berlapis dibaca dari lapis yang menjawab `cari()` TERAKHIR.
 * Hasilnya layar berhenti memajang "Powered by Google" tanpa satu pun error.
 * Itu yang dijaga [test_atribusi_selamat_waktu_jawabannya_dari_cache].
 */
class DirektoriBercacheTest extends TestCase
{
    public function test_pencarian_yang_sama_cuma_menagih_sekali(): void
    {
        $asli = $this->penyedia([$this->tempat('PT Sidik Kalibrasi')]);
        $cache = new DirektoriBercache($asli);

        $pertama = $cache->cari('PT Sidik Kalibrasi');
        $kedua = $cache->cari('PT Sidik Kalibrasi');

        $this->assertSame(
            1, $asli->dipanggil,
            'Pencarian kedua ikut menembak penyedia — tiap pengulangan ditagih lagi.',
        );

        // Dan jawabannya tetap utuh, bukan sekadar hemat.
        $this->assertCount(1, $kedua);
        $this->assertSame('PT Sidik Kalibrasi', $kedua[0]->nama);
        $this->assertEquals($pertama, $kedua);
    }

    /**
     * Ejaan yang beda-beda tipis itu satu pertanyaan yang sama buat teknisi.
     *
     * Di sinilah sebagian besar penghematannya: orang yang mengetik ulang di
     * gerbang pabrik hampir tidak pernah mengetik persis sama.
     */
    public function test_ejaan_yang_beda_tipis_dianggap_satu_pertanyaan(): void
    {
        $asli = $this->penyedia([$this->tempat('PT Sidik')]);
        $cache = new DirektoriBercache($asli);

        foreach (['PT Sidik', 'pt sidik', '  PT   Sidik  ', 'PT SIDIK'] as $ejaan) {
            $cache->cari($ejaan);
        }

        $this->assertSame(
            1, $asli->dipanggil,
            'Empat ejaan yang sama artinya jadi empat tagihan.',
        );
    }

    /**
     * Kegagalan TIDAK boleh diingat.
     *
     * Menyimpannya berarti satu gangguan sesaat di sisi penyedia mengunci
     * pencarian selama masa berlaku cache — dan yang terkunci pendaftaran
     * pelanggan di lapangan, bukan sekadar tampilan.
     */
    public function test_kegagalan_tidak_diingat_dan_percobaan_berikutnya_tetap_jalan(): void
    {
        $asli = $this->penyedia([$this->tempat('PT Sidik')], gagalSampai: 1);
        $cache = new DirektoriBercache($asli);

        try {
            $cache->cari('PT Sidik');
            $this->fail('Percobaan pertama seharusnya melempar DirektoriGagal.');
        } catch (DirektoriGagal) {
            // Diharapkan.
        }

        $hasil = $cache->cari('PT Sidik');

        $this->assertSame(2, $asli->dipanggil, 'Percobaan kedua tidak menembak penyedia — kegagalannya ikut diingat.');
        $this->assertCount(1, $hasil);
    }

    /**
     * **Ini yang paling mahal kalau salah pasang.**
     *
     * Atribusi itu kewajiban lisensi, dan `DirektoriBerlapis::atribusi()`
     * membaca lapis yang menjawab `cari()` terakhir. Cache yang dipasang di
     * LUAR lapisan bikin cache-hit melewati `cari()` seluruhnya, jadi
     * atribusinya pulang `null` — nol error, dan layar diam-diam berhenti
     * menyebut sumbernya.
     */
    public function test_atribusi_selamat_waktu_jawabannya_dari_cache(): void
    {
        $berlapis = new DirektoriBerlapis([
            new DirektoriBercache($this->penyedia([$this->tempat('PT Sidik')], atribusi: 'Powered by Google')),
        ]);

        $berlapis->cari('PT Sidik');
        $this->assertSame('Powered by Google', $berlapis->atribusi());

        // Panggilan kedua dijawab cache. Atribusinya WAJIB tetap ada.
        $berlapis->cari('PT Sidik');
        $this->assertSame(
            'Powered by Google', $berlapis->atribusi(),
            'Jawaban dari cache kehilangan atribusinya — layar berhenti memajang sumber, tanpa error.',
        );
    }

    /**
     * Dua penyedia tidak boleh berebut satu entri.
     *
     * Kalau berebut, yang terbit bukan sekadar hasil yang salah — tapi hasil
     * satu penyedia yang dipajang dengan atribusi penyedia lain.
     */
    public function test_dua_penyedia_punya_entri_sendiri_sendiri(): void
    {
        $google = $this->penyedia([$this->tempat('PT Dari Google')]);
        $osm = $this->penyedia([$this->tempat('PT Dari OSM')]);

        // Kedua palsu ini BERKELAS SAMA (kelas anonim dari satu helper), jadi
        // ruangnya harus disebut — persis keadaan yang docblock konstruktornya
        // peringatkan, dan test ini yang menemukannya.
        $dariGoogle = (new DirektoriBercache($google, 'google'))->cari('PT Sidik');
        $dariOsm = (new DirektoriBercache($osm, 'osm'))->cari('PT Sidik');

        $this->assertSame('PT Dari Google', $dariGoogle[0]->nama);
        $this->assertSame(
            'PT Dari OSM', $dariOsm[0]->nama,
            'Penyedia kedua dijawab entri milik penyedia pertama.',
        );
        $this->assertSame(1, $osm->dipanggil);
    }

    /**
     * Hasil KOSONG tetap diingat — itu justru kata kunci yang paling sering
     * diulang teknisi, dan tiap pengulangan ditagih.
     */
    public function test_hasil_kosong_tetap_diingat(): void
    {
        $asli = $this->penyedia([]);
        $cache = new DirektoriBercache($asli);

        $this->assertSame([], $cache->cari('PT Tidak Ada'));
        $this->assertSame([], $cache->cari('PT Tidak Ada'));

        $this->assertSame(1, $asli->dipanggil, 'Pencarian nihil yang diulang ikut ditagih lagi.');
    }

    /** Dan `tersedia()` tetap menembus ke penyedia aslinya. */
    public function test_tersedia_diteruskan_apa_adanya(): void
    {
        $this->assertFalse((new DirektoriBercache($this->penyedia([], siap: false)))->tersedia());
        $this->assertTrue((new DirektoriBercache($this->penyedia([])))->tersedia());
    }

    /**
     * Penyedia palsu yang MENGHITUNG berapa kali dia ditembak — angka itu yang
     * mewakili tagihan.
     *
     * @param  array<int, PerusahaanDitemukan>  $hasil
     * @param  int  $gagalSampai  jumlah panggilan pertama yang melempar
     */
    private function penyedia(
        array $hasil,
        bool $siap = true,
        int $gagalSampai = 0,
        string $atribusi = 'Sumber',
    ): DirektoriPerusahaan {
        return new class($hasil, $siap, $gagalSampai, $atribusi) implements DirektoriPerusahaan
        {
            public int $dipanggil = 0;

            /** @param  array<int, PerusahaanDitemukan>  $hasil */
            public function __construct(
                private readonly array $hasil,
                private readonly bool $siap,
                private readonly int $gagalSampai,
                private readonly string $atribusi,
            ) {}

            public function tersedia(): bool
            {
                return $this->siap;
            }

            public function atribusi(): ?string
            {
                return $this->atribusi;
            }

            public function cari(string $kata): array
            {
                $this->dipanggil++;

                if ($this->dipanggil <= $this->gagalSampai) {
                    throw new DirektoriGagal('Penyedia lagi ngambek.');
                }

                return $this->hasil;
            }
        };
    }

    private function tempat(string $nama): PerusahaanDitemukan
    {
        return new PerusahaanDitemukan(ref: 'ref-'.md5($nama), nama: $nama, alamat: 'Jl. Contoh No. 1');
    }
}

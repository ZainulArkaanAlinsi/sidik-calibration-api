<?php

namespace Tests\Feature;

use App\Services\Direktori\DirektoriBerlapis;
use App\Services\Direktori\DirektoriGagal;
use App\Services\Direktori\DirektoriPerusahaan;
use App\Services\Direktori\PerusahaanDitemukan;
use Tests\TestCase;

/**
 * Direktori berlapis: sumber tebal duluan, sumber gratis sebagai jaring.
 *
 * Yang dijaga berkas ini bukan "hasilnya kebaca" — itu urusan tiap driver
 * sendiri. Yang dijaga di sini **kapan lapis berikutnya dipakai**, karena di
 * situ letak seluruh gunanya, dan tiga keadaannya gampang tertukar:
 *
 *  - lapis pertama menjawab nol hasil  → lapis kedua HARUS dicoba
 *  - lapis pertama gagal               → lapis kedua HARUS dicoba
 *  - semua lapis gagal                 → melempar, BUKAN daftar kosong
 *
 * Yang ketiga paling mahal kalau salah: daftar kosong terbaca "PT-nya tidak
 * ada", dan teknisi yang percaya itu mendaftarkan ulang perusahaan yang
 * sebenarnya sudah ada di direktori.
 */
class DirektoriBerlapisTest extends TestCase
{
    public function test_lapis_pertama_yang_menjawab_dipakai_dan_sisanya_tidak_disentuh(): void
    {
        $kedua = $this->lapis(hasil: [$this->tempat('PT Kedua')]);

        $berlapis = new DirektoriBerlapis([
            $this->lapis(hasil: [$this->tempat('PT Pertama')], atribusi: 'Sumber A'),
            $kedua,
        ]);

        $hasil = $berlapis->cari('PT');

        $this->assertCount(1, $hasil);
        $this->assertSame('PT Pertama', $hasil[0]->nama);
        $this->assertSame('Sumber A', $berlapis->atribusi());

        // Lapis kedua sama sekali nggak ditembak — hemat kuota, dan buat
        // penyedia yang membatasi permintaan, itu bukan hal sepele.
        $this->assertSame(0, $kedua->dipanggil);
    }

    /**
     * Nol hasil BUKAN jawaban akhir.
     *
     * Ini seluruh alasan lapisannya ada: PT yang tidak ada di satu sumber
     * sering ada di sumber lain. Kalau nol hasil dianggap final, lapis kedua
     * jadi hiasan yang tidak pernah dipakai.
     */
    public function test_lapis_yang_nol_hasil_dilanjut_ke_lapis_berikutnya(): void
    {
        $berlapis = new DirektoriBerlapis([
            $this->lapis(hasil: [], atribusi: 'Sumber A'),
            $this->lapis(hasil: [$this->tempat('PT Kedua')], atribusi: 'Sumber B'),
        ]);

        $hasil = $berlapis->cari('PT');

        $this->assertCount(1, $hasil);
        $this->assertSame('PT Kedua', $hasil[0]->nama);
        $this->assertSame('Sumber B', $berlapis->atribusi());
    }

    public function test_lapis_yang_gagal_dilewat_bukan_menjatuhkan_pencarian(): void
    {
        $berlapis = new DirektoriBerlapis([
            $this->lapis(gagal: true),
            $this->lapis(hasil: [$this->tempat('PT Kedua')], atribusi: 'Sumber B'),
        ]);

        $hasil = $berlapis->cari('PT');

        $this->assertCount(1, $hasil);
        $this->assertSame('Sumber B', $berlapis->atribusi());
    }

    /** Lapis yang belum disetel dilewat tanpa dihitung sebagai kegagalan. */
    public function test_lapis_yang_belum_siap_dilewat(): void
    {
        $belumSiap = $this->lapis(siap: false);

        $berlapis = new DirektoriBerlapis([
            $belumSiap,
            $this->lapis(hasil: [$this->tempat('PT Kedua')]),
        ]);

        $this->assertCount(1, $berlapis->cari('PT'));
        $this->assertSame(0, $belumSiap->dipanggil);
    }

    public function test_semua_lapis_gagal_melempar_bukan_daftar_kosong(): void
    {
        $berlapis = new DirektoriBerlapis([
            $this->lapis(gagal: true),
            $this->lapis(gagal: true),
        ]);

        $this->expectException(DirektoriGagal::class);
        $berlapis->cari('PT');
    }

    public function test_tidak_ada_lapis_yang_siap_melempar(): void
    {
        $berlapis = new DirektoriBerlapis([
            $this->lapis(siap: false),
            $this->lapis(siap: false),
        ]);

        $this->expectException(DirektoriGagal::class);
        $berlapis->cari('PT');
    }

    /**
     * Semua lapis menjawab "tidak ketemu" itu jawaban SAH, bukan kegagalan.
     *
     * Bedanya dari test di atas cuma satu: di sini direktorinya bisa
     * dihubungi. Diratakan jadi melempar, teknisi disuruh "coba lagi nanti"
     * untuk PT yang memang belum pernah dipetakan siapa pun — dan mencoba lagi
     * tidak akan pernah mengubah jawabannya.
     */
    public function test_semua_lapis_nol_hasil_pulang_kosong_bukan_melempar(): void
    {
        $berlapis = new DirektoriBerlapis([
            $this->lapis(hasil: []),
            $this->lapis(hasil: []),
        ]);

        $this->assertSame([], $berlapis->cari('PT'));

        // Nggak ada yang menjawab → nggak ada atribusi yang boleh dipajang.
        // Memajang atribusi sumber yang tidak menyumbang satu baris pun itu
        // menyebut sumber yang salah.
        $this->assertNull($berlapis->atribusi());
    }

    /** Siap kalau ADA SATU saja yang siap — bukan harus semuanya. */
    public function test_tersedia_kalau_ada_satu_lapis_yang_siap(): void
    {
        $this->assertTrue(
            (new DirektoriBerlapis([
                $this->lapis(siap: false),
                $this->lapis(siap: true),
            ]))->tersedia(),
        );

        $this->assertFalse(
            (new DirektoriBerlapis([
                $this->lapis(siap: false),
                $this->lapis(siap: false),
            ]))->tersedia(),
        );
    }

    /**
     * Atribusi dari pencarian SEBELUMNYA nggak boleh nempel ke yang berikutnya.
     *
     * Objeknya di-`bind` per permintaan, jadi ini bukan kebocoran antar
     * teknisi — tapi satu permintaan boleh memanggil `cari` lebih dari sekali,
     * dan atribusi yang basi di situ memajang sumber yang salah di atas hasil
     * yang benar.
     */
    public function test_atribusi_direset_tiap_pencarian(): void
    {
        $berlapis = new DirektoriBerlapis([
            $this->lapis(hasil: [$this->tempat('PT Ada')], atribusi: 'Sumber A'),
        ]);

        $berlapis->cari('ada');
        $this->assertSame('Sumber A', $berlapis->atribusi());

        // Pencarian kedua nol hasil → atribusinya ikut hilang.
        $kosong = new DirektoriBerlapis([$this->lapis(hasil: [])]);
        $kosong->cari('nihil');
        $this->assertNull($kosong->atribusi());
    }

    private function tempat(string $nama): PerusahaanDitemukan
    {
        return new PerusahaanDitemukan(ref: 'ref:'.$nama, nama: $nama, alamat: 'Bekasi');
    }

    /**
     * @param  array<int, PerusahaanDitemukan>  $hasil
     */
    private function lapis(
        array $hasil = [],
        bool $siap = true,
        bool $gagal = false,
        string $atribusi = 'Sumber',
    ): DirektoriPerusahaan {
        return new class($hasil, $siap, $gagal, $atribusi) implements DirektoriPerusahaan
        {
            public int $dipanggil = 0;

            /** @param  array<int, PerusahaanDitemukan>  $hasil */
            public function __construct(
                private readonly array $hasil,
                private readonly bool $siap,
                private readonly bool $gagal,
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

                if ($this->gagal) {
                    throw new DirektoriGagal('lapis ini lagi mati');
                }

                return $this->hasil;
            }
        };
    }
}

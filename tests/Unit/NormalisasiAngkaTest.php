<?php

namespace Tests\Unit;

use App\Services\Ocr\NormalisasiAngka;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Teks mentah OCR → angka: yang boleh DITERIMA apa adanya, dan yang HARUS ditolak.
 *
 * ## Kenapa tes ini ada
 *
 * Keputusan pemilik (23 Nov 2026): hasil OCR TIDAK boleh diubah agar cocok dengan
 * templat, rentang, atau pola angka. Sampai tanggal itu kelas ini menebak: `O`
 * diganti `0`, `S` diganti `5`, `|` (garis tabel) diganti `1`, spasi di tengah
 * angka dibuang, pemisah ribuan dibuang, dan titik/koma dipilih memakai rentang
 * templat. Semuanya menghasilkan angka yang TERLIHAT benar padahal mungkin salah.
 * Tes ini sekarang menjaga arah sebaliknya: yang bukan angka murni ditolak
 * (ragu), teks mentah tidak pernah ditimpa, dan tidak ada digit yang muncul dari
 * karakter non-digit.
 *
 * Yang dijaga paling ketat: **nggak ada koma yang disisipin atau digeser**, dan
 * **tidak ada angka yang lahir dari huruf atau simbol**. Kalau penjagaan itu
 * jebol, gejalanya bukan error — gejalanya angka yang kelihatan wajar di kolom
 * yang benar.
 *
 * Semua nilai harapan di sini dihitung dari teks masukannya, bukan disalin dari
 * keluaran kodenya.
 */
class NormalisasiAngkaTest extends TestCase
{
    private NormalisasiAngka $normalisasi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalisasi = new NormalisasiAngka;
    }

    /**
     * Aturan kolom pembacaan pH di titik 4,00 — sama persis bentuknya kayak yang
     * dikeluarin `TemplateLembarKerja::aturanPembacaan()`.
     *
     * @return array<string, mixed>
     */
    private function aturanPh(float $nominal = 4.00): array
    {
        return [
            'desimal' => 2,
            'resolusi' => 0.01,
            'nominal' => $nominal,
            'min' => $nominal - 0.4,
            'maks' => $nominal + 0.4,
            'maks_digit' => 9,
            'izinkan_minus' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $aturan
     */
    #[DataProvider('bacaanWajar')]
    public function test_bacaan_wajar_jadi_angka(string $teks, float $harapan, array $aturan, int $substitusi): void
    {
        $hasil = $this->normalisasi->proses($teks, $aturan);

        $this->assertTrue($hasil['ok'], "Teks `{$teks}` mestinya kebaca. Alasan: ".implode(', ', $hasil['alasan']));
        $this->assertFalse($hasil['kosong']);
        $this->assertEqualsWithDelta($harapan, $hasil['nilai'], 1e-9);
        $this->assertSame($substitusi, $hasil['substitusi']);
    }

    /**
     * @return array<string, array{0: string, 1: float, 2: array<string, mixed>, 3: int}>
     */
    public static function bacaanWajar(): array
    {
        $ph = [
            'desimal' => 2, 'resolusi' => 0.01, 'nominal' => 4.00,
            'min' => 3.6, 'maks' => 4.4, 'maks_digit' => 9, 'izinkan_minus' => false,
        ];
        $suhu = [
            'desimal' => 1, 'resolusi' => 0.1, 'nominal' => null,
            'min' => 5.0, 'maks' => 45.0, 'maks_digit' => 4, 'izinkan_minus' => false,
        ];
        $konduktivitas = [
            'desimal' => 0, 'resolusi' => 1.0, 'nominal' => 1413.0,
            'min' => 1272.0, 'maks' => 1554.0, 'maks_digit' => 9, 'izinkan_minus' => false,
        ];

        return [
            'koma biasa' => ['4,01', 4.01, $ph, 0],
            'titik dipakai sebagai desimal' => ['4.01', 4.01, $ph, 0],
            // Spasi di AWAL/AKHIR dipangkas (isinya tidak berubah); yang di
            // TENGAH ditolak (lihat bacaanDitolak).
            'spasi di awal dan akhir dipangkas' => ['  4,01 ', 4.01, $ph, 0],
            'bilangan bulat' => ['25', 25.0, $suhu, 0],
            // Bagian utuh diawali 0 tidak mungkin kelompok ribuan: jelas desimal,
            // TANPA bukti dari templat (aturan kosong).
            'nol di depan tiga desimal tanpa bukti templat' => ['0,112', 0.112, [], 0],
            'nol di depan titik tiga desimal' => ['0.112', 0.112, [], 0],
            'dua digit di belakang pemisah tanpa bukti templat' => ['1,41', 1.41, [], 0],
            // Kolom pembacaan, BUKAN suhu: 5 digit lolos di sini, dan sengaja
            // nggak lolos di kolom suhu (`maks_digit` 4 — suhu larutan nggak
            // pernah butuh 5 digit, jadi 5 digit di sana tandanya salah baca).
            'lima digit di kolom pembacaan' => [
                '110,69', 110.69,
                ['desimal' => 2, 'resolusi' => 0.01, 'nominal' => 110.0, 'min' => 99.0, 'maks' => 121.0,
                    'maks_digit' => 9, 'izinkan_minus' => false],
                0,
            ],
            'nol di depan' => ['0,05', 0.05, [...$ph, 'nominal' => 0.05, 'min' => 0.0, 'maks' => 1.0], 0],
            // Bukti templat TIDAK dipakai memilih tafsiran: aturan konduktivitas
            // dengan rentang 1272..1554 tidak membuat "1413" dari bacaan lain berubah.
            'bilangan bulat di kolom konduktivitas' => ['1413', 1413.0, $konduktivitas, 0],
        ];
    }

    /**
     * @param  array<string, mixed>  $aturan
     */
    #[DataProvider('bacaanDitolak')]
    public function test_bacaan_meragukan_ditolak(string $teks, string $alasan, array $aturan): void
    {
        $hasil = $this->normalisasi->proses($teks, $aturan);

        $this->assertFalse($hasil['ok'], "Teks `{$teks}` mestinya DITOLAK, tapi kebaca ".var_export($hasil['nilai'], true));
        $this->assertNull($hasil['nilai']);
        $this->assertContains($alasan, $hasil['alasan']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function bacaanDitolak(): array
    {
        $ph = [
            'desimal' => 2, 'resolusi' => 0.01, 'nominal' => 4.00,
            'min' => 3.6, 'maks' => 4.4, 'maks_digit' => 9, 'izinkan_minus' => false,
        ];
        $suhuTolak = [
            'desimal' => 1, 'resolusi' => 0.1, 'nominal' => null,
            'min' => 5.0, 'maks' => 45.0, 'maks_digit' => 4, 'izinkan_minus' => false,
        ];

        return [
            // Dua sel kebaca nyambung — gejala paling sering dari garis tabel
            // yang nggak kedeteksi.
            'dua sel nyambung' => ['4,01-4,02', 'minus_di_tengah', $ph],
            'minus di kolom yang nggak boleh negatif' => ['-4,01', 'minus_tidak_diizinkan', $ph],
            'teks mentah acak tanpa angka' => ['HBs', 'karakter_di_luar_whitelist', $ph],
            'huruf yang nggak mirip digit' => ['4,X1', 'karakter_di_luar_whitelist', $ph],
            // Dulu diganti jadi digit (O→0, S→5, l→1, ...) dan jadi angka berstatus
            // kuning. Sekarang ditolak: huruf BUKAN angka.
            'huruf O di tengah angka' => ['4,O1', 'karakter_di_luar_whitelist', $ph],
            'huruf S di tengah angka' => ['4,S0', 'karakter_di_luar_whitelist', $ph],
            'dua huruf' => ['2S,O', 'karakter_di_luar_whitelist', $suhuTolak],
            'banyak huruf' => ['OS,Ol', 'karakter_di_luar_whitelist', $ph],
            'huruf l kecil' => ['1l', 'karakter_di_luar_whitelist', $ph],
            // Goresan garis tabel pernah dibaca sebagai angka 1.
            'garis tabel tegak sendirian' => ['|', 'karakter_di_luar_whitelist', $ph],
            'garis tabel tegak di dalam angka' => ['4,|1', 'karakter_di_luar_whitelist', $ph],
            'garis miring di dalam angka' => ['4/01', 'karakter_di_luar_whitelist', $ph],
            'garis bawah' => ['___', 'karakter_di_luar_whitelist', $ph],
            // Dulu spasi di tengah dibuang diam-diam ("4, 01" menjadi 4,01).
            'spasi di tengah angka' => ['4, 01', 'spasi_di_dalam_angka', $ph],
            'spasi sebelum pemisah' => ['12 .5', 'spasi_di_dalam_angka', $ph],
            'non-breaking space di tengah' => ["4\u{00A0}01", 'spasi_di_dalam_angka', $ph],
            // Dulu pemisah ribuan dibuang agar teks menjadi angka.
            'ribuan titik dan koma desimal' => ['1.234,56', 'pemisah_ribuan_tidak_didukung', $ph],
            'ribuan dua titik' => ['1.234.567', 'pemisah_ribuan_tidak_didukung', $ph],
            'ribuan dua koma' => ['1,234,567', 'pemisah_ribuan_tidak_didukung', $ph],
            // Dulu dipilih memakai rentang atau jumlah desimal dari templat.
            'ambigu walau rentang templat memihak ribuan' => [
                '1.413', 'desimal_ambigu',
                ['desimal' => 0, 'resolusi' => 1.0, 'nominal' => 1413.0, 'min' => 1272.0, 'maks' => 1554.0,
                    'maks_digit' => 9, 'izinkan_minus' => false],
            ],
            'ambigu walau jumlah desimal templat memihak desimal' => [
                '1.413', 'desimal_ambigu',
                ['desimal' => 3, 'resolusi' => 0.001, 'nominal' => 1.4, 'min' => 1.0, 'maks' => 2.0,
                    'maks_digit' => 9, 'izinkan_minus' => false],
            ],
            'dua titik berurutan' => ['4..01', 'pemisah_desimal_tidak_wajar', $ph],
            'pemisah tidak beraturan' => ['1.2.3', 'pemisah_desimal_tidak_wajar', $ph],
            'digit kepanjangan' => ['4012345678', 'digit_kebanyakan', $ph],
            // Batas digit kolom suhu lebih ketat dari kolom pembacaan — angka
            // sepanjang ini di kolom suhu hampir selalu dua sel yang nyambung.
            'lima digit di kolom suhu' => [
                '110,69', 'digit_kebanyakan',
                ['desimal' => 1, 'resolusi' => 0.1, 'nominal' => null, 'min' => 5.0, 'maks' => 45.0,
                    'maks_digit' => 4, 'izinkan_minus' => false],
            ],
            // Dua tafsiran sama-sama masuk rentang → mesin nggak berhak milih.
            'ambigu antara ribuan & desimal' => [
                '1,413', 'desimal_ambigu',
                ['desimal' => null, 'resolusi' => null, 'nominal' => null, 'min' => 0.0, 'maks' => 2000.0,
                    'maks_digit' => 9, 'izinkan_minus' => false],
            ],
        ];
    }

    /**
     * Sel kosong itu SAH, dan bedanya sama "gagal baca" harus kelihatan di
     * keluarannya — dua-duanya nggak punya nilai, tapi cuma satu yang salah.
     */
    #[DataProvider('selKosong')]
    public function test_sel_kosong_bukan_kegagalan(?string $teks): void
    {
        $hasil = $this->normalisasi->proses($teks, $this->aturanPh());

        $this->assertTrue($hasil['ok']);
        $this->assertTrue($hasil['kosong']);
        $this->assertNull($hasil['nilai']);
        $this->assertSame([], $hasil['alasan']);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function selKosong(): array
    {
        return [
            'null' => [null],
            'string kosong' => [''],
            'spasi doang' => ['   '],
            'non-breaking space' => ["\u{00A0}"],
        ];
    }

    /**
     * Penjagaan pokok: koma NGGAK PERNAH disisipin biar angkanya "masuk akal".
     *
     * "133659" di kolom yang nominalnya 1,33659 itu jelas salah baca komanya
     * kelewat. Godaannya besar buat naruh koma di posisi yang bikin nilainya pas
     * — dan itu justru yang paling bahaya, karena hasilnya angka yang kelihatan
     * benar sempurna sampai ada yang ngecek kertasnya setahun kemudian.
     */
    public function test_koma_tidak_pernah_disisipkan(): void
    {
        $hasil = $this->normalisasi->proses('133659', [
            'desimal' => 5, 'resolusi' => 0.00001, 'nominal' => 1.33659,
            'min' => 1.2, 'maks' => 1.5, 'maks_digit' => 9, 'izinkan_minus' => false,
        ]);

        // Dibaca apa adanya: seratus tiga puluh tiga ribu enam ratus lima puluh
        // sembilan. Yang nolaknya `ValidasiSel` lewat rentang — bukan dibetulin
        // diam-diam di sini.
        $this->assertTrue($hasil['ok']);
        $this->assertEqualsWithDelta(133659.0, $hasil['nilai'], 1e-9);
    }

    /**
     * Huruf TIDAK PERNAH diganti jadi digit. Dulu "4,O1" menjadi 4,01 dengan
     * catatan `O→0` dan status kuning; sekarang ditolak, tanpa nilai, tanpa
     * penggantian apa pun yang tercatat.
     */
    public function test_huruf_tidak_pernah_diganti_jadi_digit(): void
    {
        $hasil = $this->normalisasi->proses('4,O1', $this->aturanPh());

        $this->assertFalse($hasil['ok']);
        $this->assertFalse($hasil['kosong']);
        $this->assertNull($hasil['nilai']);
        $this->assertNull($hasil['teks_normal']);
        $this->assertSame(0, $hasil['substitusi']);
        $this->assertSame([], $hasil['catatan']);
        $this->assertSame(['karakter_di_luar_whitelist'], $hasil['alasan']);
    }

    /**
     * Masukan acak (seed tetap): derau OCR, goresan garis tabel, huruf mirip
     * digit, spasi, dan pemisah. Yang DITERIMA harus persis berupa angka murni
     * dari teks masukannya: tidak ada digit yang lahir dari karakter lain, tidak
     * ada digit yang hilang, tidak ada pemisah yang disisipkan atau digeser. Yang
     * mengandung karakter non-angka atau spasi di tengah WAJIB ditolak.
     */
    public function test_masukan_acak_tidak_pernah_diubah_agar_menjadi_angka(): void
    {
        mt_srand(20261123);
        $alfabet = array_merge(
            str_split('0123456789'),
            str_split('0123456789'),
            str_split(',.,.--'),
            str_split('OolIiSsBGgqQDZzAT|!/\\_()%$'),
            [' ', ' ', "\u{00A0}"],
        );

        $diterima = 0;
        $ditolak = 0;

        for ($i = 0; $i < 4000; $i++) {
            $teks = '';
            for ($j = mt_rand(0, 9); $j > 0; $j--) {
                $teks .= $alfabet[mt_rand(0, count($alfabet) - 1)];
            }

            $hasil = $this->normalisasi->proses($teks, ['izinkan_minus' => true, 'maks_digit' => 12]);

            $this->assertSame(0, $hasil['substitusi'], "substitusi bukan nol untuk `{$teks}`");
            $this->assertSame([], $hasil['catatan'], "catatan penggantian muncul untuk `{$teks}`");

            if ($hasil['kosong']) {
                $this->assertSame('', trim(preg_replace('/[\x{00A0}\s]+/u', ' ', $teks) ?? ''), "`{$teks}` bukan kosong");

                continue;
            }

            if (! $hasil['ok']) {
                $ditolak++;
                $this->assertNull($hasil['nilai'], "`{$teks}` ditolak tapi punya nilai");
                $this->assertNull($hasil['teks_normal'], "`{$teks}` ditolak tapi punya teks_normal");
                $this->assertNotSame([], $hasil['alasan'], "`{$teks}` ditolak tanpa alasan");

                continue;
            }

            $diterima++;
            $pangkas = preg_replace('/^[\x{00A0}\s]+|[\x{00A0}\s]+$/u', '', $teks) ?? '';
            // Hanya angka murni yang boleh diterima.
            $this->assertMatchesRegularExpression('/^-?[0-9]+(?:[.,][0-9]+)?$/', $pangkas, "`{$teks}` diterima padahal bukan angka murni");
            // Isinya sama persis dengan masukan (titik dan koma setara); tidak ada karakter yang lahir atau hilang.
            $this->assertSame(
                str_replace(',', '.', $pangkas),
                str_replace(',', '.', (string) $hasil['teks_normal']),
                "`{$teks}` berubah isinya menjadi `{$hasil['teks_normal']}`",
            );
        }

        // Pastikan kedua cabang benar-benar teruji, bukan lolos karena semuanya satu sisi.
        $this->assertGreaterThan(50, $diterima);
        $this->assertGreaterThan(1000, $ditolak);
    }

    /**
     * Angka ditulis balik pakai koma — itu yang dilihat teknisi di kertas, jadi
     * itu yang harus dia lihat waktu ngebandingin sama potongan citranya.
     */
    public function test_teks_normal_pakai_koma(): void
    {
        $hasil = $this->normalisasi->proses('7.02', $this->aturanPh(7.00));

        $this->assertSame('7,02', $hasil['teks_normal']);
        $this->assertEqualsWithDelta(7.02, $hasil['nilai'], 1e-9);
    }
}

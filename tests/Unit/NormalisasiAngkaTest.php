<?php

namespace Tests\Unit;

use App\Services\Ocr\NormalisasiAngka;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Teks mentah OCR → angka: yang boleh ditebak, dan yang HARUS nyerah.
 *
 * ## Kenapa tes ini ada
 *
 * Ini satu-satunya tempat di pipeline OCR yang boleh ngubah apa yang kebaca.
 * Tiap aturan di sini punya dua sisi yang sama pentingnya: dia harus nebak yang
 * gampang (spasi nyelip, `O` kebaca gantinya `0`) SUPAYA teknisi nggak ngetik
 * ulang sel yang jelas benar, dan dia harus nyerah di yang ambigu SUPAYA nggak
 * ada angka karangan yang mendarat di sertifikat terakreditasi.
 *
 * Yang dijaga paling ketat: **nggak ada koma yang disisipin atau digeser**.
 * "133659" di titik 1,33659 itu salah baca, bukan izin naruh koma di tempat yang
 * kelihatannya pas. Kalau penjagaan itu jebol, gejalanya bukan error — gejalanya
 * angka yang kelihatan wajar di kolom yang benar.
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
            'spasi nyelip dibuang' => ['4, 01', 4.01, $ph, 0],
            'huruf O kebaca ganti nol' => ['4,O1', 4.01, $ph, 1],
            'huruf S kebaca ganti lima' => ['4,S0', 4.50, $ph, 1],
            'dua tebakan masih boleh' => ['2S,O', 25.0, $suhu, 2],
            // Kolom pembacaan, BUKAN suhu: 5 digit lolos di sini, dan sengaja
            // nggak lolos di kolom suhu (`maks_digit` 4 — suhu larutan nggak
            // pernah butuh 5 digit, jadi 5 digit di sana tandanya salah baca).
            'lima digit di kolom pembacaan' => [
                '110,69', 110.69,
                ['desimal' => 2, 'resolusi' => 0.01, 'nominal' => 110.0, 'min' => 99.0, 'maks' => 121.0,
                    'maks_digit' => 9, 'izinkan_minus' => false],
                0,
            ],
            // Bukti rentang yang mutusin: 1,413 di luar rentang titik 1413 µS/cm.
            'titik jadi pemisah ribuan kalau rentangnya bilang begitu' => ['1.413', 1413.0, $konduktivitas, 0],
            // Lembar yang sama, titik ukur beda — teks identik, tafsiran beda.
            'teks sama jadi desimal kalau titiknya kecil' => [
                '1.413', 1.413,
                ['desimal' => 3, 'resolusi' => 0.001, 'nominal' => 1.4, 'min' => 1.0, 'maks' => 2.0,
                    'maks_digit' => 9, 'izinkan_minus' => false],
                0,
            ],
            'nol di depan' => ['0,05', 0.05, [...$ph, 'nominal' => 0.05, 'min' => 0.0, 'maks' => 1.0], 0],
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

        return [
            // Dua sel kebaca nyambung — gejala paling sering dari garis tabel
            // yang nggak kedeteksi.
            'dua sel nyambung' => ['4,01-4,02', 'minus_di_tengah', $ph],
            'minus di kolom yang nggak boleh negatif' => ['-4,01', 'minus_tidak_diizinkan', $ph],
            'huruf yang nggak mirip digit' => ['4,X1', 'karakter_di_luar_whitelist', $ph],
            'tebakan kebanyakan' => ['OS,Ol', 'terlalu_banyak_substitusi', $ph],
            'dua titik berurutan' => ['4..01', 'pemisah_desimal_tidak_wajar', $ph],
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
     * Tiap tebakan karakter ninggalin jejak. Tanpa ini, sel yang nilainya lahir
     * dari tebakan nggak bisa dibedain dari sel yang kebaca bersih — dan skor
     * keyakinannya nggak punya dasar buat diturunin.
     */
    public function test_setiap_tebakan_dicatat(): void
    {
        $hasil = $this->normalisasi->proses('4,O1', $this->aturanPh());

        $this->assertSame(1, $hasil['substitusi']);
        $this->assertContains('O→0', $hasil['catatan']);
        $this->assertSame('4,01', $hasil['teks_normal']);
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

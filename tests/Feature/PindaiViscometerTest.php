<?php

namespace Tests\Feature;

use App\Services\Ocr\TemplateLembarKerja;
use App\Services\Ocr\ValidasiSel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Penjaga angka lembar Viscometer.
 *
 * Lembar ini yang paling rawan dari ketujuh alat, dan alasannya satu:
 * **satu lembar memuat angka yang bedanya tiga orde magnitudo** — 96 cP,
 * 918 cP, 63181 cP, ditulis tangan, difoto pakai HP. Dua kelas kesalahan yang
 * harus ketangkep, dan dua-duanya PERNAH ADA di master lab sungguhan:
 *
 *  1. Titik desimal ganda (`631.74.2`) — Excel diam-diam melewatkannya, dan
 *     itulah sebabnya rata-rata master dihitung dari empat angka, bukan lima.
 *  2. Angka mendarat di baris yang salah — 63181 di baris 100 cP, atau 96,7 di
 *     baris 60000 cP.
 *
 * Aturannya dibaca dari template yang BENERAN dipakai server
 * (`TemplateLembarKerja::untukKode('viscometer')`), bukan dari aturan karangan
 * di test ini. Jadi begitu ada yang melonggarkan pita atau nominal per baris,
 * yang merah test ini.
 */
class PindaiViscometerTest extends TestCase
{
    // Bentuk lembar kerjanya menautkan daftar standar dari DB.
    use RefreshDatabase;

    private ValidasiSel $validasi;

    /** @var array<string, mixed> */
    private array $template;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validasi = app(ValidasiSel::class);
        $this->template = app(TemplateLembarKerja::class)->untukKode('viscometer') ?? [];
    }

    /**
     * Sel `631.74.2` ada di master: `INPUT DATA` maupun `PERHITUNGAN`, tahap
     * before maupun after.
     *
     * Yang HARUS terjadi: ditolak. Bukan ditebak jadi `631,74`, bukan jadi
     * `63174,2`. Dua-duanya kelihatan masuk akal — `63174,2` malah persis
     * seperti tetangganya — dan justru itu bahayanya: menebak salah satu
     * berarti memasukkan angka karangan ke dokumen terakreditasi, dan nggak
     * ada satu pun jejak yang bilang angka itu bukan hasil ukur.
     */
    public function test_titik_desimal_ganda_ditolak_bukan_ditebak(): void
    {
        $hasil = $this->periksa(4, '631.74.2');

        $this->assertSame(ValidasiSel::MERAH, $hasil['status']);
        $this->assertNull($hasil['nilai']);
        $this->assertContains('pemisah_desimal_tidak_wajar', $hasil['alasan']);
    }

    /**
     * `63.181` ambigu SENDIRIAN: bisa 63181 (titik = pemisah ribuan), bisa
     * 63,181. Yang menyingkirkan salah satunya BUKTI, bukan tebakan — pita
     * baris 60000 cP (baris KEEMPAT sejak larutan 3000 cP masuk) itu
     * 16049-114230 cP, dan 63,181 nggak masuk.
     *
     * Ini beda dari kasus di atas dan bedanya penting: yang punya bukti
     * dibaca, yang nggak punya ditolak.
     */
    public function test_pemisah_ribuan_dibaca_lewat_bukti_pita(): void
    {
        $hasil = $this->periksa(4, '63.181');

        $this->assertSame(63181.0, $hasil['nilai']);
        $this->assertSame([], $hasil['alasan']);
    }

    /**
     * Angka sah tetap KUNING, nggak pernah hijau — tulisan tangan wajib
     * dilihat mata teknisi walau skor OCR-nya tinggi.
     */
    public function test_pembacaan_sah_tetap_kuning_karena_tulisan_tangan(): void
    {
        $hasil = $this->periksa(4, '63181.3');

        $this->assertSame(ValidasiSel::KUNING, $hasil['status']);
        $this->assertSame(63181.3, $hasil['nilai']);
    }

    /**
     * Nominal dibaca PER BARIS (99,65 / 1018 / 3987 / 59003 / 99613), bukan
     * satu nominal buat selembar. Kalau dipukul rata, baris 60000 cP bakal
     * dinilai terhadap 99,65 dan seluruh barisnya ditolak — atau sebaliknya,
     * 96,7 di baris 60000 cP lolos.
     */
    #[DataProvider('angkaNyasar')]
    public function test_angka_yang_nyasar_baris_ditolak(int $baris, string $teks): void
    {
        $hasil = $this->periksa($baris, $teks);

        $this->assertSame(ValidasiSel::MERAH, $hasil['status']);
        $this->assertContains('di_luar_rentang', $hasil['alasan']);
        $this->assertContains('magnitudo_meleset', $hasil['alasan']);
    }

    /** @return list<array{int, string}> */
    public static function angkaNyasar(): array
    {
        return [
            'pembacaan 60000 cP nyasar ke baris 100 cP' => [1, '63181.3'],
            'pembacaan 100 cP nyasar ke baris 60000 cP' => [4, '96.7'],
            'koma kegeser sepuluh kali di baris 100 cP' => [1, '9.67'],
            'koma kegeser sepuluh kali di baris 60000 cP' => [4, '631813'],
            'pembacaan 3000 cP nyasar ke baris 100000 cP' => [5, '2710'],
            'koma kegeser sepuluh kali di baris 3000 cP' => [3, '27098'],
        ];
    }

    /**
     * Pita tiap baris harus menampung SELURUH jangkauan larutannya pada suhu
     * kerja 20-37,78 °C, bukan cuma sekitar nominal 25 °C.
     *
     * Ini penjaga yang paling gampang jebol tanpa ketahuan. Pita bawaan
     * (`nominal ±10 %`) bikin baris 1000 cP jadi 916,2-1119,8 — dan pembacaan
     * master yang paling kecil 916,3, cuma 0,1 cP di atas batasnya. Sesi yang
     * sama diukur di 30 °C bakal ditolak SELURUH barisnya, dengan pesan yang
     * kebaca seperti kamera gagal padahal angkanya benar.
     */
    #[DataProvider('ujungJangkauanLarutan')]
    public function test_pita_menampung_seluruh_jangkauan_suhu_kerja(int $baris, string $teks, float $nilai): void
    {
        $hasil = $this->periksa($baris, $teks);

        $this->assertSame($nilai, $hasil['nilai']);
        $this->assertNotSame(ValidasiSel::MERAH, $hasil['status']);
        $this->assertSame([], $hasil['alasan']);
    }

    /** @return list<array{int, string, float}> */
    public static function ujungJangkauanLarutan(): array
    {
        return [
            '100 cP @37,78 °C' => [1, '51.1', 51.1],
            '100 cP @20 °C' => [1, '134.0', 134.0],
            '1000 cP @37,78 °C' => [2, '419.5', 419.5],
            '1000 cP master, dulu 0,1 cP dari batas' => [2, '916.3', 916.3],
            '1000 cP @20 °C' => [2, '1504.0', 1504.0],
            '3000 cP @37,78 °C' => [3, '1613', 1613.0],
            '3000 cP master' => [3, '2709', 2709.0],
            '3000 cP @20 °C' => [3, '5082', 5082.0],
            '60000 cP @37,78 °C' => [4, '19259', 19259.0],
            '60000 cP @20 °C' => [4, '95192', 95192.0],
            '100000 cP @37,78 °C' => [5, '71819', 71819.0],
            '100000 cP @20 °C' => [5, '110487', 110487.0],
        ];
    }

    /** Enam alat lain nggak ikut dilonggarkan: pita pH tetap nominal ±10 %. */
    public function test_pita_alat_lain_nggak_kesenggol(): void
    {
        $template = app(TemplateLembarKerja::class)->untukKode('ph_meter') ?? [];
        $aturan = $template['sel'][array_key_first($template['sel'])]['aturan'];

        $this->assertEqualsWithDelta(4.0, $aturan['nominal'], 1e-9);
        $this->assertEqualsWithDelta(3.6, $aturan['min'], 1e-9);
        $this->assertEqualsWithDelta(4.4, $aturan['maks'], 1e-9);
        $this->assertSame(0.5, $aturan['rasio_min']);
        $this->assertSame(2.0, $aturan['rasio_maks']);
    }

    /**
     * @return array<string, mixed>
     */
    private function periksa(int $baris, string $teks): array
    {
        return $this->validasi->periksa(
            $this->template['sel']["sebelum_adjustment|{$baris}|1|pembacaan"],
            [
                'teks_mentah' => $teks,
                'confidence_ocr' => 0.95,
                'kotak_teks_di_dalam_sel' => true,
            ],
        );
    }
}

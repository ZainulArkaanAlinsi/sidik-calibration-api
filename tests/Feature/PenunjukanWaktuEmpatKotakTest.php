<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Rules\PenunjukanWaktu;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lembar Timer/Stopwatch bentuk **empat kotak** (`{jam, menit, detik,
 * milidetik}`) harus benar-benar bisa dikirim — dan angkanya harus sama dengan
 * bentuk milidetik polos.
 *
 * ## Cacat yang ditutup berkas ini
 *
 * `measurements.*.standar.*` dan `.uut.*` dulu beraturan `numeric` saja. Objek
 * empat kotak bukan angka, jadi SETIAP kiriman bentuk itu ditolak **422** —
 * kontrak yang ditulis sendiri di `docs/perintah-frontend-waktu-frekuensi.md` §5
 * mustahil dijalankan, dan cabang array di
 * `CalibrationController::waktuKeMilidetik()` jadi kode mati.
 *
 * Yang bikin mahal: tidak ada satu pun error di server. Teknisi mengisi lembar
 * penuh di lokasi, menekan kirim, dan mendapat 422 tanpa kotak mana pun yang
 * salah — karena memang tidak ada yang salah di kotaknya.
 *
 * Yang ditegakkan di sini tiga hal:
 *
 *  1. Bentuk empat kotak **diterima** (200), bukan 422.
 *  2. Hasilnya **identik** dengan kiriman milidetik polos yang setara. Diterima
 *     tapi salah hitung sama merugikannya — dan `keMilidetik()` mengalikan tiga
 *     kotak, jadi satu faktor yang meleset lolos begitu saja kalau cuma status
 *     yang dicek.
 *  3. Bentuk rusak tetap **ditolak 422**: kunci asing, objek kosong seluruhnya,
 *     nilai negatif, dan `INF`. Aturan yang meloloskan segalanya sama saja
 *     dengan tidak ada aturan.
 *
 * Bentuk angka polos ketiga alat suhu berpasangan ikut diuji supaya jelas
 * pelonggaran ini tidak menumpangi mereka.
 */
class PenunjukanWaktuEmpatKotakTest extends TestCase
{
    use RefreshDatabase;

    /** Sesi Timer/Stopwatch ter-seed dari master `Master_Olda_Timer_dan_Stopwatch.xlsm`. */
    private const SESI_TIMER = '015-CAL-424';

    /**
     * Set point 60 detik, tiga ulangan per sisi — persis contoh di
     * `docs/perintah-frontend-waktu-frekuensi.md` §5.
     *
     * @return array{standar: list<array<string, int>>, uut: list<array<string, int>>}
     */
    private static function empatKotak(): array
    {
        return [
            'standar' => [
                ['jam' => 0, 'menit' => 1, 'detik' => 0, 'milidetik' => 123],
                ['jam' => 0, 'menit' => 1, 'detik' => 0, 'milidetik' => 211],
                ['jam' => 0, 'menit' => 1, 'detik' => 0, 'milidetik' => 45],
            ],
            'uut' => [
                ['jam' => 0, 'menit' => 1, 'detik' => 0, 'milidetik' => 131],
                ['jam' => 0, 'menit' => 1, 'detik' => 0, 'milidetik' => 219],
                ['jam' => 0, 'menit' => 1, 'detik' => 0, 'milidetik' => 61],
            ],
        ];
    }

    /**
     * Kiriman yang SETARA dalam milidetik polos: 0 j + 1 m + 0 s + 123 ms
     * = 60 123 ms. Dipakai sebagai pembanding, bukan sebagai harapan yang
     * ditulis tangan — kalau `keMilidetik()` bergeser, yang gagal perbandingannya.
     *
     * @return array{standar: list<int>, uut: list<int>}
     */
    private static function milidetikPolos(): array
    {
        return [
            'standar' => [60123, 60211, 60045],
            'uut' => [60131, 60219, 60061],
        ];
    }

    public function test_bentuk_empat_kotak_diterima_dan_hasilnya_sama_dengan_milidetik_polos(): void
    {
        $this->seed(DatabaseSeeder::class);

        $kotak = $this->pratinjau(self::empatKotak());
        $polos = $this->pratinjau(self::milidetikPolos());

        $kotak->assertOk();
        $polos->assertOk();

        $titikKotak = $kotak->json('data.titik');
        $titikPolos = $polos->json('data.titik');

        $this->assertNotEmpty($titikKotak, 'Bentuk empat kotak nggak menghasilkan titik sama sekali.');

        $this->assertSame(
            $titikPolos, $titikKotak,
            'Bentuk empat kotak diterima tapi angkanya beda dari kiriman milidetik yang setara — '
            .'konversi J/M/S/ms meleset.',
        );
    }

    /**
     * Kiriman rusak yang HARUS tetap ditolak 422.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function kiriminanRusak(): array
    {
        return [
            'kunci asing (salah ketik `milidetk`)' => [[
                'standar' => [['jam' => 0, 'menit' => 1, 'detik' => 0, 'milidetk' => 123]],
                'uut' => [['jam' => 0, 'menit' => 1, 'detik' => 0, 'milidetik' => 131]],
            ]],
            'keempat kotak kosong' => [[
                'standar' => [['jam' => null, 'menit' => '', 'detik' => null, 'milidetik' => '']],
                'uut' => [['jam' => 0, 'menit' => 1, 'detik' => 0, 'milidetik' => 131]],
            ]],
            'kotak negatif' => [[
                'standar' => [['jam' => 0, 'menit' => -1, 'detik' => 0, 'milidetik' => 123]],
                'uut' => [['jam' => 0, 'menit' => 1, 'detik' => 0, 'milidetik' => 131]],
            ]],
            'kotak bukan angka' => [[
                'standar' => [['jam' => 0, 'menit' => 'satu', 'detik' => 0, 'milidetik' => 123]],
                'uut' => [['jam' => 0, 'menit' => 1, 'detik' => 0, 'milidetik' => 131]],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $deret
     */
    #[DataProvider('kiriminanRusak')]
    public function test_bentuk_rusak_tetap_ditolak_422(array $deret): void
    {
        $this->seed(DatabaseSeeder::class);

        $respons = $this->pratinjau($deret);

        $respons->assertStatus(422);

        // Dan alasannya BUKAN jejak tumpukan: yang dilihat teknisi harus
        // menunjuk kotaknya, bukan isi `vendor/`.
        $isi = (string) $respons->getContent();
        $this->assertStringNotContainsString('/home/', $isi, 'Balasan bocorin path internal.');
        $this->assertStringNotContainsString('vendor/laravel', $isi, 'Balasan bocorin isi vendor.');
    }

    /** `INF` lewat kotak `milidetik` — lubang yang sama seperti di jalur angka polos. */
    public function test_kotak_tak_terhingga_ditolak_422(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('nomor_sesi', self::SESI_TIMER)->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        // `1e400` sebagai JSON MENTAH — begitulah dia sampai dari jaringan.
        $json = sprintf(
            '{"equipment_id":%d,"standard_id":%s,"tanggal_kalibrasi":"2026-09-01","measurements":['
            .'{"titik_ukur":60,"standar":[{"jam":0,"menit":1,"detik":0,"milidetik":1e400}],'
            .'"uut":[{"jam":0,"menit":1,"detik":0,"milidetik":131}]}]}',
            $sesi->equipment_id,
            $sesi->standard_id === null ? 'null' : $sesi->standard_id,
        );

        $respons = $this->actingAs($teknisi)->call(
            'POST', '/api/calibrations/preview', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $json,
        );

        $this->assertSame(
            422, $respons->getStatusCode(),
            'Kotak `milidetik` tak terhingga harus ditolak 422, bukan '.$respons->getStatusCode()
            .'. Balasan: '.mb_substr((string) $respons->getContent(), 0, 300),
        );
    }

    /**
     * Aturannya sendiri, tanpa lewat HTTP — supaya kalau jalur request berubah,
     * yang dijaga tetap perilaku aturannya.
     *
     * @return array<string, array{mixed, bool}>
     */
    public static function nilai(): array
    {
        return [
            // Bentuk angka: dipakai ketiga alat suhu berpasangan, WAJIB tetap lolos.
            'angka biasa lolos' => [100.4, true],
            'angka negatif lolos (suhu di bawah nol)' => [-18.2, true],
            'string angka lolos' => ['60123', true],
            'nol lolos' => [0, true],
            'null dilewatkan' => [null, true],
            'string kosong dilewatkan' => ['', true],
            'INF ditolak' => [INF, false],
            'NAN ditolak' => [NAN, false],
            'string 1e400 ditolak' => ['1e400', false],
            'bukan angka ditolak' => ['abc', false],

            // Bentuk objek: cuma Timer/Stopwatch.
            'objek lengkap lolos' => [['jam' => 0, 'menit' => 1, 'detik' => 0, 'milidetik' => 123], true],
            'objek sebagian lolos' => [['milidetik' => 60123], true],
            'objek detik pecahan lolos' => [['detik' => 59.5], true],
            'objek kunci asing ditolak' => [['jam' => 0, 'menitt' => 1], false],
            'objek kosong seluruhnya ditolak' => [['jam' => null, 'menit' => '', 'detik' => null, 'milidetik' => ''], false],
            'objek tanpa kunci sama sekali ditolak' => [[], false],
            'objek kotak negatif ditolak' => [['menit' => -1], false],
            'objek kotak INF ditolak' => [['milidetik' => INF], false],
            'objek kotak bukan angka ditolak' => [['menit' => 'satu'], false],
            // Daftar polos `[0, 1, 0, 123]` bukan objek empat kotak: kuncinya
            // 0..3, bukan nama kotak. Ditolak, BUKAN dibaca sebagai J/M/S/ms —
            // urutannya cuma kebetulan cocok, dan menebaknya berarti menerima
            // kiriman yang artinya nggak pernah disepakati.
            'daftar polos ditolak' => [[0, 1, 0, 123], false],
        ];
    }

    #[DataProvider('nilai')]
    public function test_aturan_penunjukan_waktu(mixed $nilai, bool $lolos): void
    {
        $validator = Validator::make(['x' => $nilai], ['x' => [new PenunjukanWaktu]]);

        $this->assertSame(
            $lolos, $validator->passes(),
            'Aturan PenunjukanWaktu memutuskan yang sebaliknya buat '.var_export($nilai, true)
            .' — pesan: '.json_encode($validator->errors()->all()),
        );
    }

    /**
     * Satu set point Timer dikirim ke `POST /api/calibrations/preview`.
     *
     * `preview` dipilih (bukan `store`) karena dia melewati validator yang SAMA
     * PERSIS — `CalibrationRequest` — tanpa menulis apa pun ke basis data, jadi
     * yang diuji betul-betul keputusan validasinya.
     *
     * @param  array<string, mixed>  $deret
     */
    private function pratinjau(array $deret): TestResponse
    {
        $sesi = CalibrationSession::where('nomor_sesi', self::SESI_TIMER)->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        return $this->actingAs($teknisi)->postJson('/api/calibrations/preview', [
            'equipment_id' => $sesi->equipment_id,
            'standard_id' => $sesi->standard_id,
            'tanggal_kalibrasi' => '2026-09-01',
            'measurements' => [['titik_ukur' => 60, ...$deret]],
        ]);
    }
}

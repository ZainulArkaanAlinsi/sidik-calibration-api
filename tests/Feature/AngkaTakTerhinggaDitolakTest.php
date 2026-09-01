<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Rules\AngkaTerhingga;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pembacaan `INF`/`NAN` ditolak 422, bukan meledak jadi 500.
 *
 * ## Kegagalan yang ditutup berkas ini
 *
 * `is_numeric(INF)` bernilai **true**, jadi aturan `numeric` bawaan Laravel
 * meloloskannya. Dan JSON bisa mengangkutnya tanpa sintaks khusus: `1e400`
 * melampaui jangkauan float, jadi `json_decode()` memulangkan `INF`.
 *
 * Sebelum `AngkaTerhingga` ada, satu pembacaan `1e400` menjalar lewat seluruh
 * budget ketidakpastian dan menerbitkan **HTTP 500** berikut jejak tumpukan —
 * teknisi di lokasi cuma melihat "server error" tanpa tahu kotak mana yang
 * salah, dan balasannya membocorkan path internal ke pemanggil.
 *
 * Diadu ke tiga jalur simpan yang berbeda supaya jelas ini bukan cacat satu
 * alat: deret datar (pH & Tachometer), dan dua deret waktu (Timer/Stopwatch).
 */
class AngkaTakTerhinggaDitolakTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Satu nilai tak terhingga per bentuk kiriman.
     *
     * `1e400` dipakai apa adanya sebagai JSON mentah — bukan `INF` lewat PHP —
     * karena begitulah dia sampai dari jaringan: klien mana pun bisa
     * mengirimnya, termasuk yang tidak sengaja.
     *
     * @return array<string, array{string, string}>
     */
    public static function kirimanTakTerhingga(): array
    {
        return [
            'deret datar (pH)' => ['2405.13.A', '{"titik_ukur":4.01,"pembacaan":[1e400,4.02,4.03]}'],
            'deret datar (Tachometer)' => ['0140-CAL-424', '{"titik_ukur":60,"pembacaan":[1e400,59.9,60.1,60.0,60.2]}'],
            'dua deret waktu (Timer)' => ['015-CAL-424', '{"titik_ukur":60,"standar":[1e400,60211,60045],"uut":[60131,60219,60061]}'],
            'nilai negatif tak terhingga' => ['2405.13.A', '{"titik_ukur":4.01,"pembacaan":[-1e400,4.02,4.03]}'],
        ];
    }

    #[DataProvider('kirimanTakTerhingga')]
    public function test_pembacaan_tak_terhingga_ditolak_422(string $nomorSesi, string $measurement): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('nomor_sesi', $nomorSesi)->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        $json = sprintf(
            '{"equipment_id":%d,"standard_id":%s,"tanggal_kalibrasi":"2026-09-01","measurements":[%s]}',
            $sesi->equipment_id,
            $sesi->standard_id === null ? 'null' : $sesi->standard_id,
            $measurement,
        );

        $respons = $this->actingAs($teknisi)->call(
            'POST', '/api/calibrations/preview', [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
            $json,
        );

        $this->assertSame(
            422, $respons->getStatusCode(),
            "Pembacaan tak terhingga harus ditolak 422, bukan {$respons->getStatusCode()}. "
            .'Balasan: '.mb_substr((string) $respons->getContent(), 0, 300),
        );

        // Dan pesannya BUKAN jejak tumpukan: yang bocor di 500 lama itu path
        // internal berikut nama kelas framework.
        $isi = (string) $respons->getContent();
        $this->assertStringNotContainsString('/home/', $isi, 'Balasan bocorin path internal.');
        $this->assertStringNotContainsString('vendor/laravel', $isi, 'Balasan bocorin isi vendor.');
    }

    /**
     * Aturannya sendiri, tanpa lewat HTTP — supaya kalau suatu saat jalur
     * request berubah, yang dijaga tetap perilaku aturannya.
     *
     * @return array<string, array{mixed, bool}>
     */
    public static function nilai(): array
    {
        return [
            'INF ditolak' => [INF, false],
            '-INF ditolak' => [-INF, false],
            'NAN ditolak' => [NAN, false],
            'string 1e400 ditolak' => ['1e400', false],
            'angka biasa lolos' => [4.01, true],
            'nol lolos' => [0, true],
            'negatif lolos' => [-0.22, true],
            'string angka lolos' => ['59.98', true],
            'null dilewatkan' => [null, true],
            'bukan angka dilewatkan ke aturan numeric' => ['abc', true],
        ];
    }

    #[DataProvider('nilai')]
    public function test_aturan_angka_terhingga(mixed $nilai, bool $lolos): void
    {
        $validator = Validator::make(['x' => $nilai], ['x' => [new AngkaTerhingga]]);

        $this->assertSame(
            $lolos, $validator->passes(),
            'Aturan AngkaTerhingga memutuskan yang sebaliknya buat '.var_export($nilai, true),
        );
    }
}

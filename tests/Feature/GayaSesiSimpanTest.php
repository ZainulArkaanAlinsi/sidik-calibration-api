<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Support\GayaMentah as M;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jalur simpan lembar GAYA: dari kiriman HP sampai angka tersimpan.
 *
 * ## Kenapa berkas ini ada
 *
 * Sampai 24 Sep 2026 `CalibrationController::susunPengukuran()` tidak punya
 * cabang untuk gaya sama sekali. Sesi contoh kedua alat lahir dari seeder yang
 * menulis `raw_measurements` LANGSUNG, jadi seluruh rantai hitung terbukti
 * benar sementara **pintu masuknya belum ada** — HP tidak bisa menyimpan satu
 * lembar gaya pun, dan nol test yang memperlihatkannya.
 *
 * Itu bentuk kegagalan yang paling mahal di repo ini: bukan angka yang salah,
 * tapi fitur yang kelihatan selesai dari semua sudut kecuali yang dipakai
 * orang.
 *
 * ## Yang dijaga, dan kenapa justru `peran_sensor`
 *
 * Untuk BUDGET, keduabelas bacaan satu titik digabung — rata-rata, simpangan
 * baku, RSD, dan RRPE lahir dari dua belas angka itu bersama-sama, jadi urutan
 * posisinya tidak mengubah satu digit pun.
 *
 * Yang berubah kalau posisinya hilang: lembar yang dibuka ulang tidak tahu
 * angka mana milik kotak mana. Teknisi yang mengoreksi satu bacaan mengoreksi
 * kotak yang salah, dan tidak ada error di jalan mana pun. Karena itu yang
 * diuji di sini bukan cuma "dua belas baris tersimpan" melainkan "dua belas
 * baris tersimpan DI POSISI YANG BENAR".
 */
class GayaSesiSimpanTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Equipment, 1: User} */
    private function siapkan(): array
    {
        $this->seed(DatabaseSeeder::class);

        return [
            Equipment::where('serial_number', 'DEMO-LC-001')->firstOrFail(),
            User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail(),
        ];
    }

    /**
     * Kiriman HP, dengan KOMA desimal di mana-mana.
     *
     * Bukan kasus pinggiran: keyboard angka HP Indonesia menampilkan koma, dan
     * pembacaan gaya ditulis sampai empat desimal. `(float) "2,14"` di PHP
     * bukan galat melainkan `2.0` — jadi koma yang lolos tidak ditolak, dia
     * menggeser angkanya.
     *
     * @param  array<string, mixed>  $gantiBlok
     * @return array<string, mixed>
     */
    private function payload(Equipment $alat, array $gantiBlok = []): array
    {
        $deret = static fn (string $a, string $b): array => [$a, $a, $b];

        return [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-09-24',
            'suhu_awal' => '27,7', 'suhu_akhir' => 27.7,
            'kelembaban_awal' => 70, 'kelembaban_akhir' => 70,
            'measurements' => [
                [
                    'titik_ukur' => '2',
                    M::PERAN_POSISI[0] => $deret('2,16', '2,16'),
                    M::PERAN_POSISI[1] => $deret('2,16', '2,16'),
                    M::PERAN_POSISI[2] => $deret('2,16', '2,16'),
                    M::PERAN_POSISI[3] => $deret('2,14', '2,14'),
                ],
                [
                    'titik_ukur' => '3',
                    M::PERAN_POSISI[0] => $deret('3,48', '3,48'),
                    M::PERAN_POSISI[1] => $deret('3,48', '3,48'),
                    M::PERAN_POSISI[2] => $deret('3,48', '3,48'),
                    M::PERAN_POSISI[3] => $deret('3,45', '3,45'),
                ],
            ],
            'spesifikasi_alat' => [
                M::KUNCI_SESI => [
                    'satuan' => 'kN',
                    'tipe_beban' => M::ARAH_PULL,
                    'standar' => '100kN',
                    'suhu_sertifikat_standar' => '23,45',
                    'kapasitas' => '100',
                    'resolusi_uut' => '0,01',
                    'resolusi_standar' => '0,001',
                    'kapasitas_standar' => '100',
                    'preload_zero' => ['0', '0', '0'],
                    'preload_max' => ['68,86', '70,89', '71,86'],
                    'misalignment' => ['8,237', '8,234', '8,237', '8,238'],
                    ...$gantiBlok,
                ],
            ],
        ];
    }

    public function test_lembar_gaya_tersimpan_lengkap_dengan_posisinya(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertCreated()
            ->json('data.id');

        $baris = RawMeasurement::where('calibration_session_id', $id)->get();

        $this->assertCount(24, $baris, 'Dua titik x dua belas bacaan harus tersimpan utuh.');

        foreach ([1, 2] as $titikKe) {
            foreach (M::PERAN_POSISI as $peran) {
                $this->assertCount(
                    M::REPLIKAT,
                    $baris->where('titik_ke', $titikKe)->where('peran_sensor', $peran),
                    "Titik {$titikKe} posisi {$peran} tidak menyimpan ".M::REPLIKAT.' replikat.',
                );
            }
        }
    }

    /** Koma desimal tidak boleh menggeser angkanya — panduan §8.4. */
    public function test_koma_desimal_tersimpan_sebagai_angka_yang_benar(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertCreated()
            ->json('data.id');

        $posisi270 = RawMeasurement::where('calibration_session_id', $id)
            ->where('titik_ke', 1)
            ->where('peran_sensor', M::PERAN_POSISI[3])
            ->pluck('pembacaan')
            ->map(static fn ($v): float => (float) $v);

        foreach ($posisi270 as $nilai) {
            $this->assertEqualsWithDelta(2.14, $nilai, 1e-12,
                '"2,14" tersimpan bukan sebagai 2,14 — koma dibaca sebagai pemisah, bukan desimal.');
        }

        $sesi = CalibrationSession::findOrFail($id);
        $this->assertEqualsWithDelta(27.7, (float) $sesi->suhu_awal, 1e-9);

        // Blok tingkat-sesi ikut: misalignment `8,237` yang mendarat sebagai 8
        // meruntuhkan simpangan bakunya dan MENGECILKAN U95 yang tercetak.
        $blok = M::blokSesi($sesi->spesifikasi_alat);
        $this->assertEqualsWithDelta(
            [8.237, 8.234, 8.237, 8.238],
            $blok['misalignment'],
            1e-12,
            'Misalignment terpotong jadi bilangan bulat — komponen budget-nya runtuh diam-diam.',
        );
    }

    /** Hitungannya ikut tersimpan lewat jalur ini, bukan cuma baris mentahnya. */
    public function test_hitungannya_ikut_tersimpan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertCreated()
            ->json('data.id');

        $hitungan = UncertaintyCalculation::where('calibration_session_id', $id)
            ->orderBy('titik_ke')
            ->get();

        $this->assertCount(2, $hitungan);

        // `rata_rata` menyimpan kolom Unit Under Test = nominal dalam kN.
        $this->assertEqualsWithDelta(2.0, (float) $hitungan[0]->rata_rata, 1e-9);
        $this->assertSame(12, (int) $hitungan[0]->jumlah_pengulangan);
    }

    /**
     * Deret yang jumlahnya salah ditolak di REQUEST, bukan diam-diam tersimpan.
     *
     * Ditolak di sini pesannya bisa menyebut kotak mana; ditolak belakangan
     * waktu dihitung, yang muncul cuma "titik belum lengkap".
     */
    public function test_replikat_kurang_ditolak_request(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['measurements'][0][M::PERAN_POSISI[0]] = ['2,16', '2,16'];

        $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['measurements.0.'.M::PERAN_POSISI[0]]);
    }

    /** Misalignment kurang dari empat memblokir hitungannya, bukan menerbitkan U95 separuh. */
    public function test_misalignment_kurang_tidak_menghasilkan_hitungan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat, [
                'misalignment' => ['8,237', '8,234'],
            ]))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            0,
            UncertaintyCalculation::where('calibration_session_id', $id)->count(),
            'Sesi dengan misalignment kurang tetap menerbitkan hitungan.',
        );

        $this->assertGreaterThan(
            0,
            RawMeasurement::where('calibration_session_id', $id)->count(),
            'Baris mentahnya harus tetap tersimpan — data teknisi tidak pernah dibuang.',
        );
    }
}

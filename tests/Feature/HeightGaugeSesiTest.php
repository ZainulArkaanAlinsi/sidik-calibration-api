<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\User;
use App\Support\HeightGaugeMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jalur penuh lembar **Height Gauge**: payload HP → `raw_measurements` → hitung
 * ulang, dan angkanya sama di ketiga titik itu.
 *
 * ## Kenapa test ini ada
 *
 * Pola "alat yang satu titiknya bukan satu deret datar" sudah menggigit
 * **sembilan kali** — Viscometer, Gas Detector, TITS, Enclosure, tiga alat
 * suhu, Timbangan, Timer/Stopwatch, Micrometer. Bentuknya selalu sama dan
 * selalu **tanpa error**: jalur simpan menaruh bentuknya benar, jalur hitung
 * ulang tidak tahu cara menyusunnya balik, dan tiap titik pulang
 * `hitung_ulang_gagal` sampai admin belajar menekan "setujui tetap" tanpa
 * membaca.
 *
 * Height Gauge bentuk kesepuluh. Test ini ditulis bareng profilnya.
 *
 * ## Yang paling dijaga di sini: GERBANG `boleh_terbit`
 *
 * Alat ini **tidak punya lantai CMC** (di luar lampiran LK-285-IDN), dan itu
 * mengubah taruhannya. Di Micrometer, budget yang kehilangan komponen mendarat
 * di lantai CMC dan yang terbit masih di atas kemampuan terakreditasi — salah,
 * tapi tertampung. Di sini tidak ada yang menampung MAUPUN menahan: U95
 * langsung terbit terlalu kecil dan tidak ada satu pun angka di jalurnya yang
 * terlihat ganjil.
 *
 * Jadi ketiga syaratnya diuji satu per satu, dan yang membuktikannya bukan
 * peringatan sesi melainkan **ketiadaan baris hitungan** — peringatan boleh
 * dilewati admin lewat `abaikan_peringatan`, baris yang tidak ada tidak bisa.
 */
class HeightGaugeSesiTest extends TestCase
{
    use RefreshDatabase;

    private const TOLERANSI = 5e-6;

    /** Sepuluh pembacaan blok Evaluation sesi contoh master. */
    private const PRA_EVALUASI = [
        599.97, 599.92, 599.98, 599.95, 599.95, 599.97, 599.94, 599.98, 599.97, 599.95,
    ];

    /**
     * Payload sesi ringkas — tiga titik pertama, cukup buat membuktikan
     * jalurnya.
     *
     * @param  array<string, mixed>  $ganti
     * @param  array<string, mixed>  $gantiBlok
     * @return array<string, mixed>
     */
    private function payload(Equipment $alat, array $ganti = [], array $gantiBlok = []): array
    {
        return [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2026-05-05',
            'suhu_awal' => 20.2, 'suhu_akhir' => 20.3,
            'kelembaban_awal' => 44, 'kelembaban_akhir' => 55,
            // Tiga baris PERTAMA lembar (25 / 50 / 100 mm). Nominalnya tidak
            // dikirim — dia dipatok Instruksi Kerja, dan urutan baris yang
            // menentukan titik mana. `titik_ukur` ikut dikirim karena HP memang
            // menggambarnya dari bentuk lembar, tapi yang DIPAKAI server
            // nominal tabel standar.
            'measurements' => [
                ['titik_ukur' => 25.0, 'pembacaan' => [24.99, 24.99, 24.98]],
                ['titik_ukur' => 50.0, 'pembacaan' => [49.99, 49.99, 49.98]],
                ['titik_ukur' => 100.0, 'pembacaan' => [99.98, 99.98, 99.98]],
            ],
            'spesifikasi_alat' => [
                'rentang_ukur' => '0-600', 'kapasitas' => '600', 'resolusi' => '0.01',
                HeightGaugeMentah::KUNCI_SESI => [
                    'satuan' => 'mm',
                    'kapasitas_mm' => 600.0,
                    'resolusi_mm' => 0.01,
                    'paralelisme' => [0.0, 0.001, 0.002],
                    'pra_evaluasi' => self::PRA_EVALUASI,
                    'kerataan_muka_ukur' => 'baik',
                    ...$gantiBlok,
                ],
            ],
            ...$ganti,
        ];
    }

    /** @return array{Equipment, User} */
    private function siapkan(): array
    {
        $this->seed(DatabaseSeeder::class);

        return [
            Equipment::where('serial_number', '1610232804')->firstOrFail(),
            User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail(),
        ];
    }

    /**
     * Nominal DITURUNKAN server dari tabel standar, lalu disimpan bersama
     * pembacaannya sebagai baris ber-`peran_sensor` yang TERPISAH.
     *
     * Kalau keduanya mendarat di satu deret datar, koreksi yang lahir dari situ
     * — selisih nominal dan penunjukan — tidak berarti apa-apa, dan tidak ada
     * satu pun error yang membedakannya.
     */
    public function test_nominal_dan_pembacaan_tersimpan_sebagai_peran_terpisah(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertCreated()
            ->json('data.id');

        $titik1 = RawMeasurement::where('calibration_session_id', $id)->where('titik_ke', 1)->get();

        $nominal = $titik1->where('peran_sensor', HeightGaugeMentah::PERAN_NOMINAL);
        $pembacaan = $titik1->where('peran_sensor', HeightGaugeMentah::PERAN_PEMBACAAN);

        $this->assertCount(1, $nominal, 'Satu slot nominal per titik — bukan tumpukan.');
        $this->assertCount(3, $pembacaan, 'Tiga pembacaan per titik.');
        $this->assertEqualsWithDelta(25.0, (float) $nominal->first()->pembacaan, 1e-9);
        $this->assertSame('mm', $nominal->first()->satuan, 'Slot nominal SELALU mm.');
    }

    /**
     * Koreksi yang tersimpan cocok master, dan hitung ulang memulangkan angka
     * yang SAMA — bukan `hitung_ulang_gagal`.
     */
    public function test_koreksi_cocok_master_dan_hitung_ulang_konsisten(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertCreated()
            ->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);
        $hitungan = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get();

        $this->assertCount(3, $hitungan, 'Ketiga titik harus terhitung.');

        // `SERTIFIKAT!L24:L26` master — tiga koreksi pertama.
        $master = [0.014133333333337106, 0.01373333333332738, 0.02049999999999841];

        foreach ($hitungan as $i => $h) {
            $this->assertEqualsWithDelta(
                $master[$i],
                (float) $h->koreksi,
                self::TOLERANSI,
                "Koreksi titik {$h->titik_ke} meleset dari master.",
            );
        }

        // Jalur hitung ulang: angkanya harus SAMA sesudah disusun balik dari
        // baris mentah. Kalau `HeightGaugeMentah` tidak ter-wire, yang muncul
        // bukan error melainkan koreksi yang bergeser.
        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => [$sesi->nomor_sesi]])
            ->assertSuccessful();

        $sesudah = $sesi->fresh()->uncertaintyCalculations()->orderBy('titik_ke')->get();

        foreach ($sesudah as $i => $h) {
            $this->assertEqualsWithDelta(
                $master[$i],
                (float) $h->koreksi,
                self::TOLERANSI,
                'Hitung ulang menggeser koreksi — bentuk mentahnya tidak tersusun balik.',
            );
        }
    }

    /**
     * Gerbang 1 — blok Evaluation kurang dari dua pembacaan: TIDAK melahirkan
     * satu pun baris hitungan.
     */
    public function test_pra_evaluasi_kurang_dari_dua_tidak_menerbitkan_hitungan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat, gantiBlok: ['pra_evaluasi' => [599.97]]))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            0,
            CalibrationSession::findOrFail($id)->uncertaintyCalculations()->count(),
            'Tanpa keterulangan, U95 terbit terlalu kecil dan tidak ada lantai CMC yang menahannya. '
            .'Yang harus menahan ketiadaan barisnya, bukan peringatan sesi.',
        );
    }

    /**
     * Gerbang 1b — sepuluh pembacaan yang SEMUANYA identik ditolak.
     *
     * Ini yang lolos penjaga `n >= 2` dan karena itu perlu penjaganya sendiri:
     * simpangan bakunya nol eksak, komponen keterulangan lenyap dari budget,
     * dan U95 tetap terbit dengan tampang wajar.
     */
    public function test_pra_evaluasi_sepuluh_nilai_identik_ditolak(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload(
                $alat,
                gantiBlok: ['pra_evaluasi' => array_fill(0, 10, 599.95)],
            ))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            0,
            CalibrationSession::findOrFail($id)->uncertaintyCalculations()->count(),
            'Sepuluh nilai identik = simpangan baku nol = keterulangan hilang dari budget.',
        );
    }

    /** Gerbang 2 — resolusi kosong: TIDAK menerbitkan hitungan. */
    public function test_resolusi_kosong_tidak_menerbitkan_hitungan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat, gantiBlok: ['resolusi_mm' => null]))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            0,
            CalibrationSession::findOrFail($id)->uncertaintyCalculations()->count(),
            'Resolusi kosong terbaca nol dan komponen budget ke-2 lenyap — tanpa lantai CMC, '
            .'U95 langsung terbit lebih kecil.',
        );
    }

    /**
     * Paralelisme "Not Good" **TIDAK** menahan penerbitan.
     *
     * Ini hasil UKUR — alat pelanggan yang ujung scriber-nya memang tidak
     * paralel — bukan cacat data. Menahannya berarti menolak menerbitkan
     * sertifikat untuk alat yang justru paling perlu diketahui pemiliknya.
     */
    public function test_paralelisme_tidak_lulus_tetap_menerbitkan_hitungan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        // 0,02 mm — sesudah dibagi √2 hasilnya 0,0141 mm, masih di atas batas
        // 0,01 mm.
        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload(
                $alat,
                gantiBlok: ['paralelisme' => [0.0, 0.01, 0.02]],
            ))
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            3,
            CalibrationSession::findOrFail($id)->uncertaintyCalculations()->count(),
            'Paralelisme "Not Good" itu hasil ukur, bukan cacat data — sesinya tetap terbit.',
        );
    }

    /**
     * Sesi tanpa blok `spesifikasi_alat.height_gauge` sama sekali TIDAK
     * menerbitkan hitungan.
     */
    public function test_tanpa_blok_sesi_tidak_menerbitkan_hitungan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        unset($payload['spesifikasi_alat'][HeightGaugeMentah::KUNCI_SESI]);

        $balik = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertCreated();

        $this->assertSame(
            0,
            CalibrationSession::findOrFail($balik->json('data.id'))->uncertaintyCalculations()->count(),
        );
    }

    /**
     * Menyimpan payload yang SAMA dua kali menghasilkan baris mentah yang
     * identik — konversi satuannya tidak berlipat.
     *
     * Ini bug nyata yang sudah terjadi di Micrometer: versi pertama mengonversi
     * di ujung masuk, dan jalur draft mengalikan 25,4 lagi tiap simpan (1 inch
     * jadi 645,16 mm pada simpanan kedua). Nol error di seluruh jalurnya.
     */
    public function test_simpan_dua_kali_menghasilkan_baris_identik(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat, gantiBlok: ['satuan' => 'inch']);

        $ambil = fn (int $id): array => RawMeasurement::where('calibration_session_id', $id)
            ->orderBy('titik_ke')->orderBy('peran_sensor')->orderBy('sensor_ke')
            ->get()
            ->map(fn ($b): array => [
                (int) $b->titik_ke, (string) $b->peran_sensor,
                (int) $b->sensor_ke, (float) $b->pembacaan, (string) $b->satuan,
            ])
            ->all();

        $satu = $this->actingAs($teknisi)->postJson('/api/calibrations', $payload)
            ->assertCreated()->json('data.id');
        $dua = $this->actingAs($teknisi)->postJson('/api/calibrations', $payload)
            ->assertCreated()->json('data.id');

        $this->assertSame(
            $ambil($satu),
            $ambil($dua),
            'Baris mentah simpanan kedua berbeda dari yang pertama — konversi satuan terjadi di '
            .'ujung masuk dan berlipat tiap simpan.',
        );
    }
}

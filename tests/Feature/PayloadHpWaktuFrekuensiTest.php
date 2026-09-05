<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Payload yang BENAR-BENAR disusun aplikasi HP, diadu ke server ini.
 *
 * ## Kenapa test ini ada di sisi server
 *
 * Dua repo, satu kontrak. `docs/perintah-frontend-waktu-frekuensi.md` menulis
 * bentuk payloadnya, tapi dokumen tidak bisa gagal — dan sudah terbukti: bentuk
 * objek empat kotak yang ditulis di §5 ditolak 422 oleh server ini selama
 * berhari-hari, karena tidak ada satu pun test yang pernah mengirimnya.
 *
 * JSON di bawah bukan karangan. Dia dicetak dari `LembarKerjaState.toSubmission()`
 * aplikasi Flutter — lembar dibuka dari bentuk yang dikirim endpoint ini, kotak
 * diisi seperti teknisi mengisinya, lalu payloadnya disalin apa adanya ke sini.
 * Jadi yang diuji SAMBUNGAN kedua repo, bukan tafsiran masing-masing.
 *
 * Angka harapannya dari sel workbook master (§9 dokumen yang sama).
 */
class PayloadHpWaktuFrekuensiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Timer/Stopwatch — bentuk objek empat kotak, persis yang dicetak HP.
     *
     * Titik 1 sesi master `015-CAL-424`: set point 60 detik, milidetik standar
     * `123 211 45`, milidetik UUT `131 219 61`.
     */
    public function test_payload_timer_dari_hp_menghasilkan_angka_master(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('nomor_sesi', '015-CAL-424')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        $titik = $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', [
                'equipment_id' => $sesi->equipment_id,
                'standard_id' => $sesi->standard_id,
                'tanggal_kalibrasi' => '2026-09-01',
                'measurements' => [[
                    'titik_ukur' => 60.0,
                    'satuan' => 's',
                    'standard_id' => $sesi->standard_id,
                    'standar' => [
                        ['jam' => 0.0, 'menit' => 1.0, 'detik' => 0.0, 'milidetik' => 123.0],
                        ['jam' => 0.0, 'menit' => 1.0, 'detik' => 0.0, 'milidetik' => 211.0],
                        ['jam' => 0.0, 'menit' => 1.0, 'detik' => 0.0, 'milidetik' => 45.0],
                    ],
                    'uut' => [
                        ['jam' => 0.0, 'menit' => 1.0, 'detik' => 0.0, 'milidetik' => 131.0],
                        ['jam' => 0.0, 'menit' => 1.0, 'detik' => 0.0, 'milidetik' => 219.0],
                        ['jam' => 0.0, 'menit' => 1.0, 'detik' => 0.0, 'milidetik' => 61.0],
                    ],
                ]],
            ])
            ->assertOk()
            ->json('data.titik');

        $this->assertCount(1, $titik, 'Payload HP nggak menghasilkan satu titik pun.');

        // §9: rata-rata UUT 60,137 s · koreksi −40,667 ms = −0,041 s ·
        // U95 0,81 s (lantai CMC menang atas U hitung 0,38 s).
        $this->assertEqualsWithDelta(60.137, (float) $titik[0]['rata_rata'], 5e-6);
        $this->assertEqualsWithDelta(-0.040666666, (float) $titik[0]['koreksi'], 5e-6);
        $this->assertEqualsWithDelta(0.81, (float) $titik[0]['ketidakpastian_diperluas'], 5e-6);
    }

    /**
     * Ulangan yang keempat kotaknya kosong dikirim `null` DI POSISINYA — itu
     * yang disusun HP, dan server wajib menerimanya.
     *
     * Objek kosong `{}` ditolak `PenunjukanWaktu` dengan alasan yang benar;
     * `null` harus lolos, kalau nggak lembar setengah jadi mustahil disimpan.
     */
    public function test_ulangan_kosong_dikirim_null_diterima(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('nomor_sesi', '015-CAL-424')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', [
                'equipment_id' => $sesi->equipment_id,
                'standard_id' => $sesi->standard_id,
                'tanggal_kalibrasi' => '2026-09-01',
                'measurements' => [[
                    'titik_ukur' => 60.0,
                    'standar' => [
                        ['jam' => 0.0, 'menit' => 1.0, 'detik' => 0.0, 'milidetik' => 123.0],
                        null,
                        ['jam' => 0.0, 'menit' => 1.0, 'detik' => 0.0, 'milidetik' => 45.0],
                    ],
                    'uut' => [
                        ['jam' => 0.0, 'menit' => 1.0, 'detik' => 0.0, 'milidetik' => 131.0],
                        null,
                        ['jam' => 0.0, 'menit' => 1.0, 'detik' => 0.0, 'milidetik' => 61.0],
                    ],
                ]],
            ])
            ->assertOk();
    }

    /**
     * Dua alat rpm: HP mengirim SELURUH set point saran, termasuk yang belum
     * disentuh teknisi.
     *
     * Yang diuji di sini bukan cuma tiga titik yang terisi jadi angka master —
     * tapi juga bahwa lima belas baris kosong itu **tidak** membanjiri
     * `belum_dihitung`. Kalau tiap sesi terbit dengan lima belas alasan, admin
     * berhenti membacanya, dan alasan yang benar-benar penting ikut tenggelam.
     */
    public function test_payload_tachometer_dari_hp_tidak_membanjiri_belum_dihitung(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('nomor_sesi', '0140-CAL-424')->firstOrFail();
        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        // Persis yang dicetak HP: 18 set point saran, tiga terisi.
        // Kuncinya `int`, bukan `float`: PHP memang melemparkan kunci float
        // integral ke int, jadi perilakunya sama — tapi ditulis float, analisis
        // statis melaporkan `array.invalidKey` di berkas yang baru ditambah ini.
        $terisi = [
            60 => [59.9, 60.0, 60.0, 60.0, 60.0],
            80 => [80.2, 80.2, 80.2, 80.1, 80.1],
            100 => [99.8, 99.8, 99.8, 100.0, 99.9],
        ];
        $saran = [60.0, 80.0, 100.0, 120.0, 150.0, 200.0, 500.0, 1000.0, 2000.0,
            3000.0, 5000.0, 7000.0, 10000.0, 12000.0, 14000.0, 15000.0, 20000.0, 25000.0];

        $measurements = array_map(static fn (float $sp): array => [
            'titik_ukur' => $sp,
            'satuan' => 'rpm',
            'pembacaan' => $terisi[(int) $sp] ?? [null, null, null, null, null],
        ], $saran);

        $data = $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', [
                'equipment_id' => $sesi->equipment_id,
                'standard_id' => $sesi->standard_id,
                'tanggal_kalibrasi' => '2026-09-01',
                'measurements' => $measurements,
            ])
            ->assertOk()
            ->json('data');

        $titik = $data['titik'] ?? [];

        $this->assertCount(
            3, $titik,
            'Cuma tiga set point yang diisi teknisi; yang lain nggak boleh terbit sebagai titik.',
        );

        // Dan yang lima belas itu nggak boleh muncul sebagai alasan, satu pun.
        // Baris kosong bukan titik yang GAGAL dihitung — dia titik yang memang
        // nggak diisi, dan menyebutnya di daftar alasan bikin admin berhenti
        // membaca daftar itu sama sekali.
        $this->assertSame(
            [], $data['belum_dihitung'] ?? [],
            'Lima belas baris kosong terbit sebagai alasan. Admin yang tiap sesi '
            .'melihat lima belas alasan berhenti membaca daftar itu, dan alasan '
            .'yang benar-benar penting ikut tenggelam: '
            .json_encode($data['belum_dihitung'] ?? []),
        );

        // §9: koreksi −0,22 rpm.
        //
        // `rata_rata` memang SET POINT (60,0 rpm) — penunjukan alat pelanggan,
        // sesuai kontrak kolom `uncertainty_calculations`. Rata-rata pembacaan
        // tachometer standarnya 59,98 rpm dan hidup di jejak audit, BUKAN di
        // kolom ini. Lihat `ProfilPutaran::hitungPerGrup()`.
        //
        // Ditulis begini supaya pembaca berikutnya tidak "membetulkan" angka di
        // bawah jadi 59,98 — yang justru merusak kontrak kolom sertifikat yang
        // dijaga test ini.
        $this->assertEqualsWithDelta(60.0, (float) $titik[0]['rata_rata'], 5e-6);
        $this->assertEqualsWithDelta(-0.22, (float) $titik[0]['koreksi'], 5e-6);
    }
}

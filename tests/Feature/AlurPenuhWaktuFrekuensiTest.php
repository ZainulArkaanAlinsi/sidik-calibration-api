<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\User;
use App\Support\WaktuMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Alur penuh ketiga alat baru: **teknisi kirim → admin setujui → sertifikat**.
 *
 * ## Kenapa berkas ini ada
 *
 * Enam berkas test lain menjaga potongan-potongannya — angka lawan master,
 * kolom sertifikat, lantai CMC, peringatan palsu, budget, gerbang U95. Tidak
 * satu pun menjalankan ketiganya BERURUTAN lewat endpoint yang benar-benar
 * dipakai aplikasi.
 *
 * Itu celah yang berarti: tiap potongan bisa hijau sendiri-sendiri sementara
 * sambungannya putus — sesi yang tersimpan tapi tidak bisa disetujui, atau
 * disetujui tapi sertifikatnya tidak pernah terbit. Yang diuji di sini
 * SAMBUNGANNYA, dan datanya diambil dari sesi master ter-seed supaya angka yang
 * mendarat di sertifikat tetap bisa diadu ke workbook lab.
 *
 * Urutannya persis yang dipakai di lapangan:
 *
 *  1. `POST /api/calibrations` — teknisi mengirim lembar dari lokasi.
 *  2. `POST /api/calibrations/{id}/ajukan` — diajukan ke admin.
 *  3. `POST /api/calibrations/{id}/approve` — admin menyetujui.
 *  4. `certificates` — snapshot terbit dengan tabel hasilnya.
 */
class AlurPenuhWaktuFrekuensiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Ketiga sesi master, berikut baris pertama tabel hasilnya seperti
     * TERCETAK di master — disalin dari `WaktuFrekuensiSertifikatTest` supaya
     * kalau salah satunya bergeser, dua berkas yang jatuh, bukan satu.
     *
     * @return array<string, array{string, float, float, float}>
     */
    public static function sesiMaster(): array
    {
        return [
            'Centrifuge' => ['0133-CAL-324', 59.779999999999994, 60.0, -0.22000000000000597],
            'Tachometer' => ['0140-CAL-424', 59.779999999999994, 60.0, -0.22000000000000597],
            'Timer/Stopwatch' => ['015-CAL-424', 60.09633333333333, 60.137, -0.04066666666666],
        ];
    }

    #[DataProvider('sesiMaster')]
    public function test_teknisi_kirim_admin_setujui_sertifikat_terbit(
        string $nomorSesi,
        float $standard,
        float $uut,
        float $correction,
    ): void {
        // Sertifikatnya dibangun lewat job; di test dijalankan sinkron supaya
        // snapshot-nya sudah ada waktu diperiksa.
        Bus::fake([]);

        $this->seed(DatabaseSeeder::class);

        $contoh = CalibrationSession::where('nomor_sesi', $nomorSesi)
            ->with(['equipment', 'rawMeasurements'])
            ->firstOrFail();

        $teknisi = User::where('role', User::ROLE_TEKNISI)->firstOrFail();
        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        // === 1. Teknisi mengirim lembarnya =================================
        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $contoh->equipment_id,
                'standard_id' => $contoh->standard_id,
                'thermohygro_standard_id' => $contoh->thermohygro_standard_id,
                'tanggal_kalibrasi' => '2026-09-01',
                'suhu_awal' => $contoh->suhu_awal,
                'suhu_akhir' => $contoh->suhu_akhir,
                'kelembaban_awal' => $contoh->kelembaban_awal,
                'kelembaban_akhir' => $contoh->kelembaban_akhir,
                'nomor_order' => $contoh->nomor_order,
                'tanggal_terima' => $contoh->tanggal_terima?->toDateString(),
                'measurements' => $this->measurementsDari($contoh),
            ])
            ->assertCreated()
            ->json('data.id');

        $sesi = CalibrationSession::findOrFail($id);

        $this->assertNotEmpty(
            $sesi->uncertaintyCalculations,
            "Sesi {$nomorSesi} tersimpan tapi nggak menghasilkan satu titik pun — "
            .'jalur hitungnya putus di antara controller dan profilnya.',
        );

        // Statusnya langsung `menunggu_approval` — `POST /calibrations`
        // memakai itu sebagai bawaan, jadi nggak ada langkah "ajukan"
        // tersendiri: mengirim lembar SAMA DENGAN mengajukannya.
        $this->assertSame(CalibrationSession::STATUS_MENUNGGU_APPROVAL, $sesi->status);

        // === 2. Admin menyetujui ===========================================
        $this->actingAs($admin)
            ->postJson('/api/calibrations/'.$id.'/approve', ['abaikan_peringatan' => true])
            ->assertOk();

        // === 3. Sertifikatnya terbit =======================================
        $sertifikat = Certificate::where('calibration_session_id', $id)->first();

        $this->assertNotNull(
            $sertifikat,
            "Sesi {$nomorSesi} disetujui tapi sertifikatnya nggak pernah terbit.",
        );

        $baris = $sertifikat->snapshot['hasil'][0] ?? null;

        $this->assertNotNull($baris, 'Snapshot sertifikatnya nggak punya tabel hasil.');

        $this->assertEqualsWithDelta($standard, (float) $baris['standard_value'], 5e-6,
            "Sesi {$nomorSesi}: Standard Value di sertifikat beda dari master.");
        $this->assertEqualsWithDelta($uut, (float) $baris['unit_under_test'], 5e-6,
            "Sesi {$nomorSesi}: Unit Under Test di sertifikat beda dari master.");
        $this->assertEqualsWithDelta($correction, (float) $baris['correction'], 5e-6,
            "Sesi {$nomorSesi}: Correction di sertifikat beda dari master.");

        // Dan kondisi lingkungannya ikut — blok yang kosong di sertifikat
        // terakreditasi itu temuan audit tersendiri.
        $this->assertNotNull($sesi->fresh()->suhu_ruang, 'Suhu ruang nggak keisi di jalur simpan.');
    }

    /**
     * Susun ulang payload `measurements` dari baris mentah sesi contoh.
     *
     * Diturunkan dari data ter-seed, bukan ditulis tangan: angka yang dikirim
     * ulang harus PERSIS yang sudah diadu ke workbook master, kalau tidak yang
     * diuji di langkah 4 cuma konsistensi internal.
     *
     * @return list<array<string, mixed>>
     */
    private function measurementsDari(CalibrationSession $contoh): array
    {
        $perTitik = $contoh->rawMeasurements
            ->sortBy([['titik_ke', 'asc'], ['pembacaan_ke', 'asc']])
            ->groupBy('titik_ke');

        $measurements = [];

        foreach ($perTitik as $baris) {
            $titik = ['titik_ukur' => (float) $baris->first()->titik_ukur];

            // Lembar Timer punya DUA deret ber-`peran_sensor`; dua alat rpm
            // satu deret datar.
            if ($baris->first()->peran_sensor !== null) {
                $titik['standar'] = $baris
                    ->where('peran_sensor', WaktuMentah::PERAN_STANDAR)
                    ->sortBy('sensor_ke')
                    ->map(static fn ($b): float => (float) $b->pembacaan)
                    ->values()->all();
                $titik['uut'] = $baris
                    ->where('peran_sensor', WaktuMentah::PERAN_UUT)
                    ->sortBy('sensor_ke')
                    ->map(static fn ($b): float => (float) $b->pembacaan)
                    ->values()->all();
            } else {
                $titik['pembacaan'] = $baris
                    ->map(static fn ($b): float => (float) $b->pembacaan)
                    ->values()->all();
            }

            $measurements[] = $titik;
        }

        return $measurements;
    }
}

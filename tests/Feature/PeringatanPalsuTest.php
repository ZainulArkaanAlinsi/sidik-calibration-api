<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Layar approval berhenti memuntahkan peringatan yang salah alamat.
 *
 * ## Kenapa ini bukan soal rapi-rapi
 *
 * Peringatan palsu yang SELALU muncul melatih admin menekan "SETUJUI TETAP"
 * tanpa membaca — dan begitu itu jadi kebiasaan, peringatan yang benar-benar
 * penting ikut tenggelam. Sesi Inkubator 26 Agt 2026 menampilkan 25 baris
 * "Butuh konfirmasi"; 24 di antaranya menunjuk arah yang salah, dan yang satu
 * benar-benar menahan sertifikat nyaris nggak kelihatan.
 *
 * Empat sumbernya ditutup di sini.
 */
class PeringatanPalsuTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array<string, mixed>> */
    private function temuan(CalibrationSession $sesi): array
    {
        $admin = User::where('organization_id', $sesi->organization_id)
            ->where('role', User::ROLE_ADMIN)
            ->firstOrFail();

        return $this->actingAs($admin)
            ->getJson("/api/calibrations/{$sesi->id}/validasi")
            ->assertOk()
            ->json('data.temuan');
    }

    /** @return list<string> */
    private function kode(CalibrationSession $sesi): array
    {
        return array_column($this->temuan($sesi), 'kode');
    }

    private function sesiEnclosure(): CalibrationSession
    {
        $this->seed(DatabaseSeeder::class);

        return CalibrationSession::where('nomor_sesi', '2405.03.AV')->firstOrFail();
    }

    /**
     * D · Baris Suhu Ruang nggak diadu ke rentang ukur chamber.
     *
     * Disapu ke rentang chamber panas MAUPUN dingin. Furnace (300–1000) dan
     * Refrigerator (−20–10) itu yang paling telanjang: suhu ruangan lab nggak
     * akan PERNAH masuk rentang itu, jadi tiap sesi memuntahkan 20 peringatan
     * yang mustahil benar.
     */
    public function test_suhu_ruang_bebas_dari_rentang_chamber(): void
    {
        $sesi = $this->sesiEnclosure();

        foreach ([[30, 300], [300, 1000], [-20, 10], [15, 100]] as [$min, $maks]) {
            $sesi->equipment->update(['range_min' => $min, 'range_max' => $maks]);

            // Empat set point x 5 pengulangan suhu ruang wajar — persis bentuk
            // sesi yang dilaporkan.
            $sesi->rawMeasurements()->where('peran_sensor', 'suhu_ruang')->delete();

            for ($titik = 1; $titik <= 4; $titik++) {
                for ($ulang = 1; $ulang <= 5; $ulang++) {
                    RawMeasurement::create([
                        'calibration_session_id' => $sesi->id,
                        'titik_ke' => $titik,
                        'pembacaan_ke' => $ulang,
                        'sensor_ke' => null,
                        'peran_sensor' => 'suhu_ruang',
                        'tahap' => 'sesudah_adjustment',
                        'titik_ukur' => 15 * $titik,
                        'pembacaan' => 24.6,
                        'satuan' => '°C',
                        'input_source' => 'manual',
                        'is_verified' => true,
                    ]);
                }
            }

            $kode = $this->kode($sesi->fresh());

            $this->assertNotContains(
                'pembacaan_di_luar_rentang',
                $kode,
                "Suhu ruang 24,6 °C ke-flag di alat rentang {$min}–{$maks}. "
                .'Itu suhu ruangan lab, bukan pengukuran alat — 20 peringatan palsu per sesi.',
            );
        }
    }

    /**
     * D · …tapi salah salin yang NYATA sekarang ketangkep.
     *
     * Ini yang bikin perbaikannya bukan sekadar mendiamkan: dengan penggaris
     * lama, 24,6 (benar) diteriakin sementara 246 (koma kegeser) dan 121
     * (salah salin satu baris) LOLOS — dua-duanya kebetulan masuk 30–300.
     */
    public function test_suhu_ruang_yang_beneran_salah_ketangkep(): void
    {
        $sesi = $this->sesiEnclosure();
        $sesi->equipment->update(['range_min' => 30, 'range_max' => 300]);

        foreach ([246.0, 121.0] as $salah) {
            $sesi->rawMeasurements()->where('peran_sensor', 'suhu_ruang')->delete();

            RawMeasurement::create([
                'calibration_session_id' => $sesi->id,
                'titik_ke' => 1, 'pembacaan_ke' => 1,
                'sensor_ke' => null, 'peran_sensor' => 'suhu_ruang',
                'tahap' => 'sesudah_adjustment', 'titik_ukur' => 15,
                'pembacaan' => $salah, 'satuan' => '°C',
                'input_source' => 'manual', 'is_verified' => true,
            ]);

            $this->assertContains(
                'suhu_ruang_di_luar_pita',
                $this->kode($sesi->fresh()),
                "Suhu ruang {$salah} °C lolos. Dengan penggaris lama dia juga lolos — "
                .'yang diteriakin justru angka yang benar.',
            );
        }
    }

    /**
     * G · Peringatan grid menyebut BARIS yang mana.
     *
     * Satu set point Enclosure isinya 9 termokopel + Indikator + Suhu Ruang,
     * dan tanpa label perannya kesebelasnya berbunyi "Titik ke-1 Repeat 1"
     * — sepuluh sampai sebelas pesan identik byte per byte untuk satu sel.
     */
    public function test_peringatan_grid_menyebut_barisnya(): void
    {
        $sesi = $this->sesiEnclosure();
        $sesi->equipment->update(['range_min' => 0, 'range_max' => 50, 'resolusi' => 0.1]);

        $baris = $sesi->rawMeasurements()->where('peran_sensor', 'termokopel')->firstOrFail();
        $baris->update(['pembacaan' => 999999]);

        $pesan = array_column(
            array_values(array_filter(
                $this->temuan($sesi->fresh()),
                static fn (array $t): bool => ($t['konteks']['nilai'] ?? null) == 999999,
            )),
            'pesan',
        );

        $this->assertNotEmpty($pesan);
        $this->assertStringContainsString(
            'Termokopel no. '.$baris->sensor_ke,
            $pesan[0],
            'Peringatan grid nggak nyebut baris mana. Admin nggak punya cara mengembalikannya dengan jelas.',
        );
    }

    /**
     * E · Pesan "titik tidak terhitung" berhenti menyuruh merusak data master.
     *
     * Kelima lembar Enclosure SENGAJA tanpa toleransi — masternya nggak punya
     * batas keberterimaan sama sekali, dan sertifikatnya berhenti di baris
     * `Uncertainty 95%`. Menyodorkan "toleransi alat kosong" sebagai sebab
     * mengarahkan orang mengisi kolom yang sengaja dikosongkan; mengisi kolom
     * itu pernah mematikan seluruh sesi Conductivity.
     */
    public function test_sebab_yang_disebut_beneran_berlaku(): void
    {
        $sesi = $this->sesiEnclosure();

        // Bikin satu titik nggak kehitung supaya pesannya lahir.
        $sesi->uncertaintyCalculations()->delete();

        $pesan = array_column(
            array_values(array_filter(
                $this->temuan($sesi->fresh()),
                static fn (array $t): bool => $t['kode'] === 'titik_tidak_terhitung',
            )),
            'pesan',
        );

        $this->assertNotEmpty($pesan, 'Pesannya nggak lahir — testnya nggak menguji apa pun.');

        foreach ($pesan as $p) {
            $this->assertStringNotContainsString(
                'toleransi alat kosong',
                $p,
                'Lembar Enclosure sengaja tanpa toleransi. Menyebutnya bikin orang mengisi '
                .'kolom yang sengaja dikosongkan.',
            );

            // Ambang yang disebut harus yang beneran berlaku: grid minta 4.
            $this->assertStringContainsString('kurang dari 4', $p);
        }
    }

    /**
     * F · Sesi TITS Measure berhenti dituduh salah ketik.
     *
     * 25 dari 54 pembacaan ke-flag "bukan kelipatan resolusi" — atas angka
     * yang disalin apa adanya dari lembar master lab. Daya baca alatnya pindah
     * menurut besaran (0,01 di bawah ~500 °C, 0,1 di atasnya), dan itu nggak
     * bisa diwakili satu skalar di `equipments.resolusi`.
     */
    public function test_tits_measure_bersih_dari_tuduhan_kelebihan_digit(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('nomor_sesi', '22506.01.A')->firstOrFail();

        $this->assertNotContains(
            'pembacaan_bukan_kelipatan_resolusi',
            $this->kode($sesi),
            'Sesi TITS Measure masih memuntahkan tuduhan salah ketik atas angka master lab.',
        );
    }

    /** F · …dan sepuluh lembar lain TETAP dijaga. */
    public function test_lembar_lain_tetap_diadu_ke_resolusi(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::whereHas('equipment', fn ($q) => $q->whereNotNull('resolusi'))
            ->whereHas('rawMeasurements', fn ($q) => $q->whereNull('peran_sensor'))
            ->whereHas('equipment', fn ($q) => $q->where('nama_alat_kemampuan', 'not like', '%tanpa Sensor%'))
            ->firstOrFail();

        $alat = $sesi->equipment;
        $alat->update(['resolusi' => 0.1]);

        $baris = $sesi->rawMeasurements()->whereNull('peran_sensor')->firstOrFail();
        // Di dalam rentang alat, tapi digitnya kelebihan — persis salah ketik
        // yang pemeriksa ini memang dicari.
        $baris->update(['pembacaan' => (float) $alat->range_min + 0.123456]);

        $this->assertContains(
            'pembacaan_bukan_kelipatan_resolusi',
            $this->kode($sesi->fresh()),
            'Pemeriksanya kematian buat semua lembar, bukan cuma TITS.',
        );
    }
}

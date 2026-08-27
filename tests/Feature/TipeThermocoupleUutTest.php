<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use App\Services\Calibration\Profiles\ThermocoupleProfile;
use App\Services\Calibration\TabelKalibratorSuhu3Alat;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tipe termokopel ALAT PELANGGAN kecatat — beda dari sensor ACUAN lab.
 *
 * ## Dua hal yang namanya mirip dan gampang ketuker
 *
 * Kertas kerja `SIDIK-FM-CAL-0535_Rev.2` punya `Tipe Thermocouple` di blok
 * *Identitas Alat dan Data Customer* dengan sepuluh pilihan. Selama ini lembar
 * kita cuma punya `tipe_sensor` berlabel "Standar Sensor" — tiga pilihan, dan
 * itu sensor ACUAN milik lab yang memilih tabel koreksi plus dua komponen
 * budget.
 *
 * Dua-duanya ada di master, dan dua-duanya terisi di sesi yang sama:
 *
 *     INPUT DATA!E4   Sensor Type (UUT)  : 2     <- alat pelanggan
 *     INPUT DATA!O23  Standar Sensor     : 2     <- acuan lab
 *
 * Kebetulan sama-sama `2` di sesi contoh (dua-duanya Type K), dan justru itu
 * yang bikin gampang disangka satu kolom.
 *
 * ## Kenapa ini nggak boleh menyentuh satu angka pun
 *
 * Diadu ke `SERTIFIKAT`: identitas UUT tercetak tanpa tipe sensornya, dan
 * tabel standar di bawahnya mencetak sensor ACUAN. Budget-nya nol menyentuh.
 * Jadi kolom ini catatan kerja — dan test ini menegakkan sifat itu, bukan cuma
 * kehadirannya: kalau suatu hari dia mulai menggeser U95, yang merah di sini.
 */
class TipeThermocoupleUutTest extends TestCase
{
    use RefreshDatabase;

    private function bentuk(): array
    {
        $u = User::where('role', User::ROLE_TEKNISI)->firstOrFail();

        return $this->actingAs($u)
            ->getJson('/api/calibrations/lembar-kerja?profil=thermocouple')
            ->assertOk()
            ->json('data');
    }

    /** @return array<string, array<string, mixed>> */
    private function fieldIdentitas(array $bentuk): array
    {
        foreach ($bentuk['bagian'] as $b) {
            if ($b['kode'] === 'identitas_alat') {
                return collect($b['field'])->keyBy('kode')->all();
            }
        }

        return [];
    }

    public function test_kesepuluh_tipe_di_kertas_bisa_dipilih(): void
    {
        $this->seed(DatabaseSeeder::class);

        $f = $this->fieldIdentitas($this->bentuk())['spesifikasi_alat.tipe_thermocouple'] ?? null;

        $this->assertNotNull($f, 'Kolom `Tipe Thermocouple` kertas nggak punya tempat di lembar kita.');

        // Persis seperti tercetak di `SIDIK-FM-CAL-0535_Rev.2`. Excel cuma
        // punya delapan (tanpa Type E dan tanpa Others); yang dipakai daftar
        // kertas karena dia superset — termokopel Type E yang datang ke lab
        // harus punya tempat, bukan jatuh ke kolom yang salah.
        $this->assertSame(
            ['Type K', 'Type N', 'Type S', 'Type J', 'Type B',
                'Type E', 'Type T', 'Type R', 'RTD/PT100', 'Lainnya'],
            array_column($f['pilihan'], 'label'),
        );
    }

    public function test_pilihan_lainnya_bawa_kotak_tulis_yang_muncul_seperlunya(): void
    {
        $this->seed(DatabaseSeeder::class);

        $f = $this->fieldIdentitas($this->bentuk())['spesifikasi_alat.tipe_thermocouple_lain'] ?? null;

        $this->assertNotNull($f, '"Others ….." di kertas punya garis isian; tanpa kotak ini pilihannya jadi jalan buntu.');
        $this->assertSame('teks', $f['tipe']);

        // Muncul HANYA waktu `Lainnya` kepilih — bukan selalu. Kotak tulis yang
        // nongol terus bikin teknisi ngira dia wajib diisi tiap sesi.
        $this->assertSame(
            ['kode' => 'spesifikasi_alat.tipe_thermocouple', 'nilai' => ['Lainnya']],
            $f['tampil_kalau'],
        );
    }

    /**
     * Arah sebaliknya, dan ini yang paling gampang salah waktu dibaca sekilas:
     * kolom baru ini TIDAK boleh dianggap sebagai sensor acuan.
     */
    public function test_tetap_beda_dari_sensor_acuan_lab(): void
    {
        $this->seed(DatabaseSeeder::class);

        $bentuk = $this->bentuk();

        $acuan = null;
        foreach ($bentuk['bagian'] as $b) {
            if ($b['kode'] !== 'data_kalibrasi') continue;
            $acuan = collect($b['field'])->firstWhere('kode', 'tipe_sensor');
        }

        $this->assertNotNull($acuan, 'Sensor acuan lab wajib tetap ada di PENGERJAAN.');
        $this->assertSame(
            TabelKalibratorSuhu3Alat::TIPE_SENSOR_STANDAR,
            array_column($acuan['pilihan'], 'label'),
            'Sensor ACUAN cuma tiga tipe yang lab punya sertifikatnya — jangan ikut dipanjangin '
            .'jadi sepuluh cuma karena kertas UUT-nya sepuluh.',
        );

        // Letaknya pun beda, dan itu ikut kertas: identitas alat vs pengerjaan.
        $this->assertArrayHasKey(
            'spesifikasi_alat.tipe_thermocouple',
            $this->fieldIdentitas($bentuk),
            'Tipe UUT tempatnya di blok Identitas Alat, sesuai kertas.',
        );
    }

    /**
     * Kolomnya CATATAN, bukan penggerak angka. Kalau suatu hari dia mulai
     * menggeser U95, yang merah di sini — sebelum ada sertifikat yang terbit
     * dengan angka yang berubah tanpa ada yang sadar kenapa.
     */
    public function test_nggak_menggeser_satu_angka_pun(): void
    {
        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('nomor_sesi', '0513-CAL-1124')->firstOrFail();

        $sebelum = $sesi->uncertaintyCalculations()->orderBy('titik_ke')->get()
            ->map(fn ($b): array => [
                (int) $b->titik_ke,
                round((float) $b->ketidakpastian_gabungan, 10),
                round((float) $b->ketidakpastian_diperluas, 10),
                round((float) $b->koreksi, 10),
            ])->all();

        $this->assertNotSame([], $sebelum);

        $spek = $sesi->spesifikasi_alat ?? [];
        $spek['tipe_thermocouple'] = 'Type B';
        $sesi->forceFill(['spesifikasi_alat' => $spek])->save();

        $this->artisan('kalibrasi:hitung-ulang', ['sesi' => ['0513-CAL-1124']])->assertSuccessful();

        $sesudah = $sesi->fresh()->uncertaintyCalculations()->orderBy('titik_ke')->get()
            ->map(fn ($b): array => [
                (int) $b->titik_ke,
                round((float) $b->ketidakpastian_gabungan, 10),
                round((float) $b->ketidakpastian_diperluas, 10),
                round((float) $b->koreksi, 10),
            ])->all();

        $this->assertSame(
            $sebelum,
            $sesudah,
            'Tipe termokopel UUT menggeser angka hasil. Dia identitas alat pelanggan — '
            .'yang menggerakkan budget sensor ACUAN, bukan ini.',
        );
    }

    public function test_daftar_kertas_memuat_seluruh_daftar_excel(): void
    {
        // Excel `DATABASE!Q20:Q27` — delapan tipe yang dikenal workbook.
        // Daftar kertas dipakai karena superset; kalau suatu hari ada yang
        // memangkasnya balik ke delapan, test ini yang nangkep.
        foreach (['Type N', 'Type K', 'Type B', 'Type T', 'Type R', 'Type S', 'Type J'] as $tipe) {
            $this->assertContains(
                $tipe,
                ThermocoupleProfile::TIPE_THERMOCOUPLE_UUT,
                "Tipe `{$tipe}` ada di combobox Excel tapi hilang dari daftar kita.",
            );
        }

        $this->assertContains('RTD/PT100', ThermocoupleProfile::TIPE_THERMOCOUPLE_UUT);
    }
}

<?php

namespace Tests\Concerns;

use App\Models\Equipment;
use App\Models\PaketAcuanVersi;
use App\Models\UncertaintyCalculation;
use App\Models\User;
use App\Services\Acuan\SumberAcuan;
use App\Support\DialIndicatorMentah;
use App\Support\JsonKanonik;
use App\Support\MicrometerMentah;
use Database\Seeders\DatabaseSeeder;

/**
 * Perkakas bersama test stempel versi data acuan (Studio Data Acuan, B3):
 * `StempelAcuanTest` dan `HitungUlangVersiAcuanTest`.
 *
 * Payload sesinya SALINAN APA ADANYA fixture test lama — Micrometer dari
 * `MicrometerSesiTest::payload()`, Dial Indicator dari
 * `DialIndicatorSesiTest::payload()` — bukan angka karangan. Kalau fixture
 * itu berubah, salin ulang.
 */
trait SesiDataAcuan
{
    protected Equipment $micrometer;

    protected Equipment $dialIndicator;

    protected User $teknisi;

    /** Versi 1 hasil `acuan:impor-awal` untuk organisasi teknisi. */
    protected PaketAcuanVersi $v1;

    /**
     * Seed penuh, sumber `db`, lalu impor versi 1 Micrometer untuk organisasi
     * teknisi. `berlaku_mulai` v1 = sesi Micrometer seeder tertua (07 §3).
     */
    protected function siapkanAcuan(): void
    {
        SumberAcuan::lupakanCache();
        $this->seed(DatabaseSeeder::class);

        $this->micrometer = Equipment::where('serial_number', 'ZQ-100')->firstOrFail();
        $this->dialIndicator = Equipment::where('serial_number', 'DC-36')->firstOrFail();
        $this->teknisi = User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail();

        $this->assertSame((int) $this->teknisi->organization_id, (int) $this->micrometer->organization_id);
        $this->assertSame((int) $this->teknisi->organization_id, (int) $this->dialIndicator->organization_id);

        $this->artisan('acuan:impor-awal', ['profil' => 'micrometer', '--organisasi' => $this->teknisi->organization_id])
            ->assertSuccessful();

        $this->v1 = PaketAcuanVersi::query()->where('organization_id', $this->teknisi->organization_id)->sole();

        config(['acuan.sumber' => 'db']);
    }

    /**
     * `MicrometerSesiTest::payload()` — tiga titik pertama varian B
     * (25,0 = 6+19 / 27,5 / 31,0 = 14+17 mm), tanggal 2025-05-02.
     *
     * @param  array<string, mixed>  $ganti
     * @return array<string, mixed>
     */
    protected function payloadMicrometer(array $ganti = []): array
    {
        return [
            'equipment_id' => $this->micrometer->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2025-05-02',
            'suhu_awal' => 20.5, 'suhu_akhir' => 20.6,
            'kelembaban_awal' => 41, 'kelembaban_akhir' => 40,
            'measurements' => [
                ['titik_ukur' => 25.0, 'pembacaan' => [25.001, 25.0, 25.001, 25.0, 25.001]],
                ['titik_ukur' => 27.5, 'pembacaan' => [27.502, 27.501, 27.502, 27.501, 27.502]],
                ['titik_ukur' => 31.0, 'pembacaan' => [31.003, 31.002, 31.003, 31.002, 31.003]],
            ],
            'spesifikasi_alat' => [
                'rentang_ukur' => '25-50', 'kapasitas' => '50', 'resolusi' => '0.001',
                MicrometerMentah::KUNCI_SESI => [
                    'satuan' => 'mm',
                    'kapasitas_mm' => 50.0,
                    'resolusi_mm' => 0.001,
                    'pra_evaluasi' => [50.0, 50.0, 50.0, 49.999, 50.0, 50.0, 50.0, 50.001, 50.0, 50.0],
                ],
            ],
            ...$ganti,
        ];
    }

    /**
     * `DialIndicatorSesiTest::payload()` — tiga titik pertama sesi contoh
     * master; titik 2 & 3 memakai balok ukur 2,5 mm milik tabel Micrometer.
     *
     * @return array<string, mixed>
     */
    protected function payloadDialIndicator(): array
    {
        return [
            'equipment_id' => $this->dialIndicator->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2024-05-06',
            'suhu_awal' => '20,8', 'suhu_akhir' => 20.7,
            'kelembaban_awal' => 56, 'kelembaban_akhir' => 59,
            'measurements' => [
                ['titik_ukur' => null, 'nominal' => '1,1', 'pembacaan' => ['1,10', '1,10', 1.1, 1.1, 1.1, null]],
                ['titik_ukur' => 0, 'nominal' => [2.5], 'pembacaan' => [2.5, 2.5, 2.5, 2.5, 2.5]],
                ['titik_ukur' => null, 'nominal' => '2,5+1,3+1,2', 'pembacaan' => [5, 5, 5, 5, 5]],
            ],
            'spesifikasi_alat' => [
                'rentang_ukur' => '0-25',
                DialIndicatorMentah::KUNCI_SESI => [
                    'satuan' => 'mm',
                    'kapasitas_mm' => 25,
                    'resolusi_mm' => '0,01',
                    'pra_evaluasi' => ['baris' => [['titik_ukur' => null, 'pembacaan' => array_fill(0, 10, 25.01)]]],
                    'balok_pra_evaluasi' => '14+11',
                ],
            ],
        ];
    }

    /** @param  array<string, mixed>  $payload */
    protected function simpanSesi(array $payload): int
    {
        return (int) $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertSuccessful()
            ->json('data.id');
    }

    /** @return list<int|null> stempel `paket_acuan_versi_id` tiap baris hasil */
    protected function stempelSesi(int $id): array
    {
        return UncertaintyCalculation::query()
            ->where('calibration_session_id', $id)
            ->orderBy('titik_ke')
            ->pluck('paket_acuan_versi_id')
            ->map(static fn ($v): ?int => $v === null ? null : (int) $v)
            ->all();
    }

    /**
     * Kolom angka hasil tersimpan + `type_b_components` terurai — yang diadu
     * antar jalur. `calculated_at`, id, dan stempel sengaja tidak ikut.
     *
     * @return list<array<string, mixed>>
     */
    protected function angkaSesi(int $id): array
    {
        return UncertaintyCalculation::query()
            ->where('calibration_session_id', $id)
            ->orderBy('titik_ke')
            ->get()
            ->map(static fn (UncertaintyCalculation $u): array => [
                ...$u->only([
                    'titik_ke', 'titik_ukur', 'standard_id', 'rata_rata', 'error', 'koreksi', 'standar_deviasi',
                    'jumlah_pengulangan', 'type_a', 'type_b', 'ketidakpastian_gabungan', 'faktor_cakupan_k',
                    'derajat_kebebasan_efektif', 'ketidakpastian_diperluas', 'toleransi', 'keputusan', 'metode',
                ]),
                'type_b_components' => $u->type_b_components,
            ])
            ->all();
    }

    /**
     * Versi data baru paket micrometer organisasi teknisi: isi v1 dengan nilai
     * terkoreksi balok ukur yang disebut `$balok` diganti.
     *
     * @param  array<string, float>  $balok  kunci `balok_ukur` (mis. '19.0') => nilai terkoreksi mm
     */
    protected function versiBalokBeda(int $nomor, string $status, ?string $mulai, array $balok, ?string $sampai = null): PaketAcuanVersi
    {
        $isi = $this->v1->isiTerurai();

        foreach ($balok as $kunci => $nilai) {
            $this->assertArrayHasKey($kunci, $isi['balok_ukur'], "balok {$kunci} ada di versi 1");
            $isi['balok_ukur'][$kunci] = $nilai;
        }

        $kanonik = JsonKanonik::serialisasi($isi);

        return PaketAcuanVersi::create([
            'paket_acuan_id' => $this->v1->paket_acuan_id,
            'organization_id' => $this->v1->organization_id,
            'jenis' => PaketAcuanVersi::JENIS_DATA,
            'nomor_versi' => $nomor,
            'status' => $status,
            'isi' => $kanonik,
            'isi_sha256' => JsonKanonik::sha256($kanonik),
            'skema_versi' => $this->v1->skema_versi,
            'dasar_versi_id' => $this->v1->id,
            'berlaku_mulai' => $mulai,
            'berlaku_sampai' => $sampai,
        ]);
    }
}

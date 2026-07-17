<?php

namespace Database\Factories;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Certificate>
 */
class CertificateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => fn () => Organization::query()->value('id') ?? Organization::factory(),
            'calibration_session_id' => fn () => CalibrationSession::factory(),
            // Format asli: CAL/2026/07/0001 (lihat GenerateCertificate::nomorBerikutnya).
            'nomor' => sprintf('CAL/%s/%s', now()->format('Y/m'), str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT)),
            'qr_token' => Str::lower(Str::random(10)),
            'qr_payload' => fn (array $attributes) => url('/verify/'.$attributes['qr_token']),
            'diterbitkan_pada' => now(),
            'berlaku_sampai' => now()->addYear(),
            'status' => Certificate::STATUS_TERBIT,
        ];
    }

    /** Belum sempat di-generate PDF-nya (baru disetujui). */
    public function menungguGenerate(): static
    {
        return $this->state(fn (): array => [
            'status' => Certificate::STATUS_MENUNGGU_GENERATE,
            'diterbitkan_pada' => null,
            'berlaku_sampai' => null,
            'pdf_path' => null,
        ]);
    }

    /** Generate PDF-nya gagal — butuh retry. */
    public function gagal(): static
    {
        return $this->state(fn (): array => [
            'status' => Certificate::STATUS_GAGAL,
            'pdf_path' => null,
        ]);
    }
}

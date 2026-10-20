<?php

namespace Tests\Feature\Pelanggan;

use App\Http\Resources\Pelanggan\AlatPelangganResource;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * `status_alat` (REQ-ALT-05, 20 Okt 2026) — field ADDITIVE di `/alat`,
 * `/alat/{id}`, dan `/beranda` (`perlu_perhatian`). `status_kalibrasi` lama
 * tetap dikirim, karena aplikasi yang sudah terpasang membacanya.
 *
 * Kode yang dibaca aplikasi pelanggan (`StatusKalibrasi.dariApi`,
 * `lib/models/data_pelanggan.dart`): terkalibrasi, mendekati_jadwal,
 * lewat_jadwal, belum_pernah, nonaktif. `dalam_proses` tidak dikirim
 * (sumbernya permintaan pelanggan, dicabut K-50-09).
 */
class StatusAlatPelangganTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private const V1 = '/api/pelanggan/v1';

    private User $pic;

    private Customer $perusahaan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();

        $this->pic = $this->anggota();
        $this->perusahaan = $this->pic->keanggotaan()->first()->customer;
    }

    private function alat(array $isi = [], ?string $sertifikat = Certificate::STATUS_TERBIT): Equipment
    {
        $alat = Equipment::factory()->create([
            'organization_id' => $this->perusahaan->organization_id,
            'customer_id' => $this->perusahaan->id,
            'status' => Equipment::STATUS_AKTIF,
            ...$isi,
        ]);

        if ($sertifikat !== null) {
            Certificate::factory()->create([
                'organization_id' => $alat->organization_id,
                'calibration_session_id' => CalibrationSession::factory()->create([
                    'organization_id' => $alat->organization_id,
                    'equipment_id' => $alat->id,
                ])->id,
                'status' => $sertifikat,
            ]);
        }

        return $alat;
    }

    private function ambil(string $uri)
    {
        $this->permintaanBaru();

        return $this->withHeaders($this->bearer($this->pic))->getJson(self::V1.$uri)->assertOk();
    }

    public function test_lima_status_di_daftar_dan_detail_alat(): void
    {
        $harap = [
            AlatPelangganResource::ALAT_TERKALIBRASI => $this->alat(['tanggal_jatuh_tempo' => now()->addDays(200)]),
            AlatPelangganResource::ALAT_MENDEKATI_JADWAL => $this->alat(['tanggal_jatuh_tempo' => now()->addDays(10)]),
            AlatPelangganResource::ALAT_LEWAT_JADWAL => $this->alat(['tanggal_jatuh_tempo' => now()->subDays(3)]),
            // Belum ada sertifikat terbit DAN tanggal kalibrasi terakhir kosong.
            AlatPelangganResource::ALAT_BELUM_PERNAH => $this->alat(['tanggal_jatuh_tempo' => null, 'tanggal_kalibrasi_terakhir' => null], null),
            AlatPelangganResource::ALAT_NONAKTIF => $this->alat(['status' => Equipment::STATUS_NONAKTIF]),
        ];

        $daftar = collect($this->ambil('/alat')->json('data'))->keyBy('id');

        foreach ($harap as $status => $alat) {
            $this->assertSame($status, $daftar[$alat->id]['status_alat'], "daftar: {$status}");
            $this->assertSame($status, $this->ambil("/alat/{$alat->id}")->json('data.status_alat'), "detail: {$status}");
            // Field lama tetap ada (kontrak tidak dipersempit).
            $this->assertArrayHasKey('status_kalibrasi', $daftar[$alat->id]);
        }
    }

    /**
     * Alat impor: jatuh tempo & tanggal kalibrasi terakhir terisi, sertifikatnya
     * terbit di luar sistem (tidak ada baris `terbit`) → dinilai dari tanggal,
     * bukan `belum_pernah`.
     */
    public function test_alat_impor_tanpa_sertifikat_terbit_dinilai_dari_tanggal(): void
    {
        $lewat = $this->alat([
            'tanggal_kalibrasi_terakhir' => now()->subYear()->subDays(3),
            'tanggal_jatuh_tempo' => now()->subDays(3),
        ], null);
        $aman = $this->alat([
            'tanggal_kalibrasi_terakhir' => now()->subMonth(),
            'tanggal_jatuh_tempo' => now()->addDays(200),
        ], null);

        $daftar = collect($this->ambil('/alat')->json('data'))->keyBy('id');

        $this->assertSame(AlatPelangganResource::ALAT_LEWAT_JADWAL, $daftar[$lewat->id]['status_alat']);
        $this->assertSame(AlatPelangganResource::ALAT_TERKALIBRASI, $daftar[$aman->id]['status_alat']);
        $this->assertSame(AlatPelangganResource::ALAT_LEWAT_JADWAL, $this->ambil("/alat/{$lewat->id}")->json('data.status_alat'));
    }

    public function test_sertifikat_belum_terbit_dihitung_belum_pernah(): void
    {
        $alat = $this->alat(['tanggal_jatuh_tempo' => now()->addDays(200), 'tanggal_kalibrasi_terakhir' => null], Certificate::STATUS_MENUNGGU_GENERATE);

        $this->assertSame(AlatPelangganResource::ALAT_BELUM_PERNAH, $this->ambil("/alat/{$alat->id}")->json('data.status_alat'));
    }

    public function test_beranda_perlu_perhatian_membawa_status_alat(): void
    {
        $lewat = $this->alat(['tanggal_jatuh_tempo' => now()->subDays(3)]);
        $segera = $this->alat(['tanggal_jatuh_tempo' => now()->addDays(10)]);

        $perhatian = collect($this->ambil('/beranda')->json('data.perlu_perhatian'))->keyBy('id');

        $this->assertSame(AlatPelangganResource::ALAT_LEWAT_JADWAL, $perhatian[$lewat->id]['status_alat']);
        $this->assertSame(AlatPelangganResource::ALAT_MENDEKATI_JADWAL, $perhatian[$segera->id]['status_alat']);
        $this->assertSame('lewat_jatuh_tempo', $perhatian[$lewat->id]['status_kalibrasi']);
    }
}

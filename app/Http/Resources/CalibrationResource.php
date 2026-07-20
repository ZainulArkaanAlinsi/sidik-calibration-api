<?php

namespace App\Http\Resources;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\RawMeasurement;
use App\Models\UncertaintyCalculation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bentuknya dikunci sama docs/kontrak-api.md bagian 4 (repo mobile).
 *
 * @mixin CalibrationSession
 */
class CalibrationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $penentu = $this->titikPenentu();

        return [
            'id' => $this->id,
            'nomor_sesi' => $this->nomor_sesi,
            'nomor_order' => $this->nomor_order,
            'equipment' => [
                'id' => $this->equipment?->id,
                'nama_alat' => $this->equipment?->nama_alat,
                'serial_number' => $this->equipment?->serial_number,
            ],
            'teknisi' => [
                'id' => $this->teknisi?->id,
                'nama' => $this->teknisi?->name,
            ],
            'tanggal_kalibrasi' => $this->tanggal_kalibrasi?->toIso8601ZuluString(),
            'tanggal_terima' => $this->tanggal_terima?->toIso8601ZuluString(),
            'status' => $this->status,
            'input_method' => $this->input_method,

            // Sesi bisa punya banyak titik ukur, tapi kontrak minta SATU objek
            // `hasil`. Yang dikirim adalah titik penentu — yang paling mepet ke
            // batas toleransi. Rincian tiap titik ada di `titik` di bawah.
            'hasil' => $penentu ? [
                'rata_rata' => $penentu->rata_rata,
                'error' => $penentu->error,
                'ketidakpastian_gabungan' => $penentu->ketidakpastian_gabungan,
                'faktor_cakupan_k' => $penentu->faktor_cakupan_k,
                'ketidakpastian_diperluas' => $penentu->ketidakpastian_diperluas,
                'keputusan' => $this->keputusan,
            ] : null,

            'catatan_revisi' => $this->catatan_revisi,
            'certificate_id' => $this->certificate?->id,

            // Tambahan di luar kontrak (superset, aman diabaikan mobile) —
            // dibutuhin buat nampilin worksheet & rincian ketidakpastian.

            // Sertifikat sesi ini, kalau udah terbit. `pdf_url` siap-pakai biar
            // layar detail sesi bisa langsung nawarin unduh tanpa nyusun URL
            // sendiri dari `certificate_id`. null selama belum `terbit`.
            'sertifikat' => $this->certificate ? [
                'id' => $this->certificate->id,
                'nomor' => $this->certificate->nomor,
                'status' => $this->certificate->status,
                'pdf_url' => $this->certificate->status === Certificate::STATUS_TERBIT
                    ? route('certificates.download', $this->certificate)
                    : null,
            ] : null,

            'suhu_ruang' => $this->suhu_ruang,
            'kelembaban' => $this->kelembaban,
            'lokasi' => $this->lokasi,
            'standar_acuan' => $this->standard ? [
                'id' => $this->standard->id,
                'nama' => $this->standard->nama,
                'no_sertifikat' => $this->standard->no_sertifikat,
            ] : null,
            'titik' => $this->uncertaintyCalculations
                ->sortBy('titik_ke')
                ->values()
                ->map(fn (UncertaintyCalculation $titik): array => [
                    'titik_ke' => $titik->titik_ke,
                    'titik_ukur' => $titik->titik_ukur,
                    'rata_rata' => $titik->rata_rata,
                    'error' => $titik->error,
                    'koreksi' => $titik->koreksi,
                    'standar_deviasi' => $titik->standar_deviasi,
                    'jumlah_pengulangan' => $titik->jumlah_pengulangan,
                    'type_a' => $titik->type_a,
                    'type_b' => $titik->type_b,
                    'type_b_components' => $titik->type_b_components,
                    'ketidakpastian_gabungan' => $titik->ketidakpastian_gabungan,
                    'faktor_cakupan_k' => $titik->faktor_cakupan_k,
                    'ketidakpastian_diperluas' => $titik->ketidakpastian_diperluas,
                    'toleransi' => $titik->toleransi,
                    'keputusan' => $titik->keputusan,
                    'metode' => $titik->metode,
                    // Titik yang standarnya beda dari standar default sesi (mis.
                    // buffer pH 4/7/10) nampilin punyanya sendiri di sini.
                    'standar_acuan' => $titik->standard ? [
                        'id' => $titik->standard->id,
                        'nama' => $titik->standard->nama,
                        'no_sertifikat' => $titik->standard->no_sertifikat,
                    ] : null,
                ]),

            // Status verifikasi pembacaan — cuma ikut waktu detail sesi dibuka
            // (whenLoaded), biar daftar sesi nggak kebanjiran baris pembacaan.
            // Mobile pakai ini buat tau baris OCR mana yang masih perlu
            // dikonfirmasi, walau device-nya beda dari yang nginput.
            'perlu_verifikasi' => $this->whenLoaded(
                'rawMeasurements',
                fn (): bool => $this->rawMeasurements->contains('is_verified', false),
            ),
            'pembacaan_mentah' => $this->whenLoaded(
                'rawMeasurements',
                fn () => $this->rawMeasurements
                    ->sortBy([['titik_ke', 'asc'], ['pembacaan_ke', 'asc']])
                    ->values()
                    ->map(fn (RawMeasurement $m): array => [
                        'id' => $m->id,
                        'titik_ke' => $m->titik_ke,
                        'pembacaan_ke' => $m->pembacaan_ke,
                        'tahap' => $m->tahap,
                        'pembacaan' => $m->pembacaan,
                        'input_source' => $m->input_source,
                        'is_verified' => $m->is_verified,
                        'photo_path' => $m->photo_path,
                        'ocr_confidence' => $m->ocr_confidence,
                        'ocr_raw_text' => $m->ocr_raw_text,
                    ]),
            ),
        ];
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperCertificate
 */
#[Fillable([
    'organization_id', 'calibration_session_id', 'issued_by', 'revision_of', 'nomor', 'qr_token',
    'qr_payload', 'snapshot', 'validasi', 'pdf_path', 'xlsx_path', 'diterbitkan_pada',
    'berlaku_sampai', 'status', 'alasan_revisi',
])]
class Certificate extends Model
{
    use Diaudit, HasFactory;

    public const STATUS_MENUNGGU_GENERATE = 'menunggu_generate';

    public const STATUS_TERBIT = 'terbit';

    public const STATUS_GAGAL = 'gagal';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'diterbitkan_pada' => 'date',
            'berlaku_sampai' => 'date',
            'snapshot' => 'array',
            'validasi' => 'array',
        ];
    }

    /**
     * Siapa yang tanda tangan sertifikat ini (fase-2 §3c).
     *
     * Dibaca dari `snapshot`, **bukan** live dari pengaturan organisasi. Bedanya
     * penting: snapshot itu yang beneran kecetak di PDF, dan pengaturannya bisa
     * berubah kapan aja. Kalau dibaca live, sertifikat lama bakal nampilin penanda
     * tangan yang BEDA dari yang ada di PDF-nya sendiri — dan yang lihat nggak punya
     * cara buat tahu mana yang bener. Sertifikat terbit itu dokumen terkendali.
     *
     * Ditaruh di model, bukan di resource, karena dipakai di DUA bentuk respons
     * (`CertificateResource` & objek embed di `CalibrationResource`) — disalin dua
     * kali berarti suatu saat yang satu diubah dan yang lain ketinggalan.
     *
     * `ttd_url` yang diminta di §3c sengaja NGGAK ADA: gambarnya di disk privat,
     * karena URL tanda tangan yang bisa diakses siapa pun berarti siapa pun bisa
     * nempelin ke dokumen palsu.
     *
     * @return array{nama: string|null, jabatan: string|null}|null
     */
    public function penandaTangan(): ?array
    {
        $footer = $this->snapshot['footer'] ?? null;

        if (! filled($footer)) {
            return null;
        }

        return [
            'nama' => $footer['penandatangan'] ?? null,
            'jabatan' => $footer['jabatan'] ?? null,
        ];
    }

    /**
     * Nama file yang aman dipakai di disk & header unduhan. `nomor` ada
     * slash-nya (`CAL/2026/07/0001`) — kalau dipakai apa adanya, itu bikin
     * subdirektori, bukan nama file.
     */
    public function namaFile(string $ekstensi): string
    {
        $nomor = str_replace(['/', '\\'], '-', (string) ($this->nomor ?? $this->qr_token));

        return "Sertifikat-{$nomor}.{$ekstensi}";
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<CalibrationSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CalibrationSession::class, 'calibration_session_id');
    }

    /** Sertifikat asal yang direvisi sama sertifikat ini. */
    /** @return BelongsTo<Certificate, $this> */
    public function revisionOf(): BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'revision_of');
    }
}

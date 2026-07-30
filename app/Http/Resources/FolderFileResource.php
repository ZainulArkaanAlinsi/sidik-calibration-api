<?php

namespace App\Http\Resources;

use App\Models\Certificate;
use App\Models\FolderFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FolderFile
 */
class FolderFileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folder_id' => $this->folder_id,
            'nama' => $this->nama,
            'sumber' => $this->sumber,
            'mime' => $this->mime,
            'ukuran' => $this->ukuran,
            'keterangan' => $this->keterangan,

            // File sertifikat nggak disalin — dia nunjuk ke sertifikat aslinya.
            'sertifikat' => $this->certificate ? [
                'id' => $this->certificate->id,
                'nomor' => $this->certificate->nomor,
                'status' => $this->certificate->status,
                'siap_diunduh' => $this->certificate->status === Certificate::STATUS_TERBIT,
            ] : null,

            // Lembar kerja itu tautan ke record, bukan berkas — `path`-nya
            // memang null. Nunjuk ke sesi kalibrasinya biar klien bisa
            // ngebuka isinya tanpa nebak-nebak.
            'lembar_kerja' => $this->calibration_session_id !== null ? [
                'calibration_session_id' => $this->calibration_session_id,
                'nomor_sesi' => $this->calibrationSession?->nomor_sesi,
                'status' => $this->calibrationSession?->status,
            ] : null,

            // `null` buat lembar kerja: kalau URL-nya tetap dikirim, layar
            // nampilin tombol Unduh yang pasti gagal. Yang nggak bisa diunduh
            // mestinya nggak nawarin unduhan sama sekali, bukan nawarin lalu
            // ngasih error waktu dipencet.
            'download_url' => $this->sumber === FolderFile::SUMBER_LEMBAR_KERJA
                ? null
                : route('folder-files.download', $this->resource),
            'diunggah_oleh' => $this->uploader?->name,
            'dibuat_pada' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}

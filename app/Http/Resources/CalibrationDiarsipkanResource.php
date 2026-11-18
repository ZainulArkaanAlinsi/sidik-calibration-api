<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Satu baris DAFTAR ARSIP draf (`GET /calibrations?diarsipkan=1`): bentuk
 * ringkas yang sama dengan `CalibrationRingkasResource`, ditambah `arsip`
 * (alasan, pelaku, waktu -- dari `audit_logs`, lihat `JejakAlasan`).
 *
 * Dipisah dari resource daftar biasa supaya kontrak daftar yang sudah beredar
 * di HP tidak berubah satu kunci pun; `arsip` hanya ada di daftar arsip.
 */
class CalibrationDiarsipkanResource extends CalibrationRingkasResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'arsip' => $this->resource->getAttribute('arsip'),
        ];
    }
}

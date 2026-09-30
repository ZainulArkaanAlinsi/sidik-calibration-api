<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body `POST /api/calibrations/{calibration}/kembalikan-dari-pengesahan`.
 *
 * `alasan` WAJIB, dan panjang minimalnya bukan hiasan: yang menerima pesan ini
 * adalah admin yang harus memperbaiki sesuatu tanpa bisa balik bertanya. "tidak
 * lengkap" mengirimnya menebak; "lampiran sertifikat standar RZ-04 belum
 * diunggah" langsung bisa dikerjakan.
 *
 * Sepuluh karakter itu ambang terendah yang masih menahan "ok", "salah", dan
 * satu ketukan spasi — bukan jaminan kalimatnya berguna. Yang menjaga sisanya
 * bukan validator: alasannya tercatat di `audit_logs` dengan nama pengirimnya.
 */
class KembalikanDariPengesahanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'alasan' => ['required', 'string', 'min:10', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'alasan.required' => 'Tulis alasannya — admin yang terima nggak bisa nanya balik.',
            'alasan.min' => 'Alasannya kependekan. Sebut yang harus dibetulkan, bukan cuma "salah".',
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Body `POST /api/calibrations/{calibration}/tarik-pengajuan`.
 *
 * Adminnya menarik pengajuannya SENDIRI, jadi tidak ada orang lain yang perlu
 * dijelaskan — tapi alasannya tetap wajib, karena yang membaca baris ini nanti
 * bukan dia: auditor yang menemukan satu sesi diajukan, ditarik, diajukan lagi
 * dengan angka yang berbeda akan menanyakan kenapa, dan jawabannya harus ada di
 * sistem, bukan di ingatan orang.
 *
 * Ambang minimalnya lebih rendah daripada `KembalikanDariPengesahanRequest`
 * (5 vs 10): di sana pesannya harus cukup untuk dikerjakan orang lain, di sini
 * cukup untuk dimengerti sendiri enam bulan lagi. "salah ttd" lolos di sini dan
 * memang cukup.
 */
class TarikPengajuanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'alasan' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'alasan.required' => 'Tulis alasan singkat kenapa ditarik — ini yang kebaca di riwayat nanti.',
        ];
    }
}

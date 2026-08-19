<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Payload preview olah data Autoklaf. Sengaja longgar — teknisi boleh ngirim
 * separuh data (cuma suhu, atau cuma tekanan). Yang wajib cuma set point +
 * minimal satu blok terisi; kelengkapan sisanya dijaga di controller/kalkulator.
 *
 * Tabel koreksi kalibrator & CMC TIDAK diterima dari sini — itu kebenaran
 * server (`config/autoclave.php`), bukan angka yang boleh dikirim klien.
 */
class AutoclaveCalculationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'set_point' => ['required', 'numeric', 'min:0'],

            // ---- Suhu ----
            'suhu' => ['sometimes', 'array'],
            'suhu.disk' => ['sometimes', 'array', 'max:3'],
            'suhu.disk.*' => ['array'],
            'suhu.disk.*.*' => ['nullable', 'numeric'],
            'suhu.indikator' => ['sometimes', 'array'],
            'suhu.indikator.*' => ['nullable', 'numeric'],
            'suhu.suhu_ruang' => ['sometimes', 'array'],
            'suhu.suhu_ruang.*' => ['nullable', 'numeric'],
            'suhu.resolusi_alat' => ['sometimes', 'nullable', 'numeric', 'min:0'],

            // ---- Tekanan ----
            'tekanan' => ['sometimes', 'array'],
            'tekanan.uut_setting' => ['required_with:tekanan', 'numeric'],
            'tekanan.satuan' => ['sometimes', 'string', 'in:Bar,MPa,kPa,Psi,kg/cm2,inHg,mmHg,Pa'],
            'tekanan.display' => ['sometimes', 'string', 'in:Digital,Analog 1,Analog 2,Analog 3'],
            'tekanan.resolusi_alat' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'tekanan.pembacaan_standar' => ['required_with:tekanan', 'array', 'min:1'],
            'tekanan.pembacaan_standar.*' => ['nullable', 'numeric'],
            'tekanan.tekanan_atm_awal' => ['sometimes', 'nullable', 'numeric'],
        ];
    }
}

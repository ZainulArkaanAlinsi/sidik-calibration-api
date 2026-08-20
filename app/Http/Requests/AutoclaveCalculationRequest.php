<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AturanUkurAutoclave;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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
    use AturanUkurAutoclave;

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

            // Baris "Time" di kertas — jam pengambilan tiap kolom (02:00:00,
            // 04:00:00, ...). Nggak ikut ngitung, tapi tetap disimpan: tanpa
            // jamnya, lima kolom angka nggak bisa diadu balik ke rekaman disk.
            'waktu' => ['sometimes', 'array'],
            'waktu.*' => ['nullable', 'date_format:H:i,H:i:s'],

            // ---- Tekanan ----
            'tekanan' => ['sometimes', 'array'],
            'tekanan.uut_setting' => ['sometimes', 'nullable', 'numeric'],
            // Baris "Indikator Pressure (…...)" di kertas — bacaan manometer
            // autoklaf per titik waktu.
            'tekanan.indikator_pressure' => ['sometimes', 'array'],
            'tekanan.indikator_pressure.*' => ['nullable', 'numeric'],
            'tekanan.satuan' => ['sometimes', 'string', 'in:Bar,MPa,kPa,Psi,kg/cm2,inHg,mmHg,Pa'],
            'tekanan.display' => ['sometimes', 'string', 'in:Digital,Analog 1,Analog 2,Analog 3'],
            'tekanan.resolusi_alat' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // Angka Pressure Disk Logger nggak ada di kertas — teknisi ngisinya
            // sesudah disk-nya diunduh. Jadi blok tekanan boleh kekirim tanpa
            // baris ini; yang kesimpan tetap utuh, cuma olah data tekanannya
            // nunggu angkanya lengkap.
            'tekanan.pembacaan_standar' => ['sometimes', 'array'],
            'tekanan.pembacaan_standar.*' => ['nullable', 'numeric'],
            // Kertas nyediain LIMA kolom buat baris ini; payload lama ngirim
            // satu angka. Dua-duanya diterima supaya klien lama nggak patah.
            'tekanan.tekanan_atm_awal' => ['sometimes', 'nullable', $this->angkaAtauDeretAngka()],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->pastikanAdaBacaanUut($validator)];
    }
}

<?php

namespace App\Http\Requests;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\GumCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CalibrationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;

        return [
            'equipment_id' => [
                'required',
                Rule::exists('equipments', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            // Ketidakpastian standar acuan itu komponen Type B terbesar — tanpa
            // ini U jadi kekecilan dan alat yang harusnya FAIL malah lulus. Jadi
            // wajib, walaupun kontrak mobile versi awal nggak nyantumin.
            'standard_id' => [
                'required',
                Rule::exists('standards', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],
            'tanggal_kalibrasi' => ['required', 'date', 'before_or_equal:today'],
            'input_method' => ['sometimes', Rule::in(['manual', 'ocr'])],
            'lokasi' => ['sometimes', Rule::in(['lab', 'onsite'])],
            'suhu_ruang' => ['nullable', 'numeric'],
            'kelembaban' => ['nullable', 'numeric', 'between:0,100'],

            // Teknisi boleh nyimpen dulu sebagai draft & nerusin nanti. Kalau
            // nggak dikirim, sesi langsung masuk antrean approval admin.
            'status' => ['sometimes', Rule::in([
                CalibrationSession::STATUS_DRAFT,
                CalibrationSession::STATUS_MENUNGGU_APPROVAL,
            ])],

            'measurements' => ['required', 'array', 'min:1'],
            'measurements.*.titik_ukur' => ['required', 'numeric'],
            'measurements.*.satuan' => ['required', 'string', 'max:50'],
            'measurements.*.pembacaan' => ['required', 'array', 'min:'.GumCalculator::MIN_PENGULANGAN],
            'measurements.*.pembacaan.*' => ['required', 'numeric'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'equipment_id.required' => 'Alat yang mau dikalibrasi wajib diisi.',
            'equipment_id.exists' => 'Alat itu nggak ada.',
            'standard_id.required' => 'Standar acuan wajib diisi — ketidakpastiannya dipakai buat hitung Type B.',
            'standard_id.exists' => 'Standar acuan itu nggak ada.',
            'tanggal_kalibrasi.before_or_equal' => 'Tanggal kalibrasi nggak boleh di masa depan.',
            'kelembaban.between' => 'Kelembaban itu persen, jadi cuma boleh 0–100.',
            'measurements.required' => 'Data pengukuran wajib diisi.',
            'measurements.*.pembacaan.min' => 'Tiap titik ukur minimal '.GumCalculator::MIN_PENGULANGAN
                .' pembacaan — standar deviasi (Type A) nggak bisa dihitung dari satu angka.',
        ];
    }

    /**
     * Cek yang nggak bisa diomongin pakai aturan validasi biasa, karena butuh
     * baca isi baris DB-nya dulu.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $alat = Equipment::find($this->integer('equipment_id'));

            // Tanpa toleransi, PASS/FAIL nggak bisa diputuskan sama sekali —
            // nggak ada batas buat dibandingin. Lebih baik nolak sekarang
            // daripada nerbitin sertifikat tanpa keputusan.
            if ($alat && $alat->toleransi === null) {
                $validator->errors()->add(
                    'equipment_id',
                    'Alat ini belum punya nilai toleransi. Isi dulu di data alat — tanpa itu PASS/FAIL nggak bisa diputuskan.',
                );
            }

            $standar = Standard::find($this->integer('standard_id'));

            // Standar yang sertifikatnya udah lewat masa berlaku nggak boleh
            // jadi acuan — ketertelusurannya putus, dan itu temuan asesor.
            if ($standar && ! $standar->masihBerlaku()) {
                $validator->errors()->add(
                    'standard_id',
                    'Sertifikat standar acuan ini udah kadaluarsa, jadi nggak boleh dipakai kalibrasi.',
                );
            }
        });
    }
}

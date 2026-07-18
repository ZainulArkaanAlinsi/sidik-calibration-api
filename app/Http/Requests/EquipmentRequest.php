<?php

namespace App\Http\Requests;

use App\Models\Equipment;
use App\Models\EquipmentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EquipmentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;
        $equipment = $this->route('equipment');
        $wajibKalauBikinBaru = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'nama_alat' => [$wajibKalauBikinBaru, 'string', 'max:255'],
            'serial_number' => [
                $wajibKalauBikinBaru, 'string', 'max:100',
                // Unik per organisasi, bukan global — dua PT boleh punya serial sama.
                Rule::unique('equipments', 'serial_number')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at')
                    ->ignore($equipment?->id),
            ],
            'kategori' => [
                $wajibKalauBikinBaru, 'string',
                Rule::exists('equipment_categories', 'kode')->where('organization_id', $organizationId),
            ],
            'pelanggan_id' => [
                $wajibKalauBikinBaru,
                Rule::exists('customers', 'id')->where('organization_id', $organizationId),
            ],
            // Nunjuk ke CalibrationCapability.nama_alat (mis. "Vernier Caliper")
            // biar GumCalculator tau CMC mana yang beneran punya jenis alat
            // yang sama — bukan cuma kategori yang sama (lihat komentar
            // GumCalculator::kemampuanUntukTitik()). Opsional: alat yang belum
            // dilink tetap kalibrasi lewat jalur generik.
            'nama_alat_kemampuan' => [
                'sometimes', 'nullable', 'string', 'max:255',
                Rule::exists('calibration_capabilities', 'nama_alat')
                    ->where('equipment_category_id', $this->resolveEquipmentCategoryId($organizationId, $equipment)),
            ],
            'merk' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'no_identifikasi' => ['nullable', 'string', 'max:100'],
            'range_min' => ['nullable', 'numeric'],
            'range_max' => ['nullable', 'numeric', 'gte:range_min'],
            'satuan' => ['nullable', 'string', 'max:50'],
            'resolusi' => ['nullable', 'numeric', 'min:0'],
            'toleransi' => ['nullable', 'numeric', 'min:0'],
            'lokasi' => ['nullable', 'string', 'max:255'],
            'tanggal_kalibrasi_terakhir' => ['nullable', 'date'],
            'tanggal_jatuh_tempo' => ['nullable', 'date', 'after_or_equal:tanggal_kalibrasi_terakhir'],
            // `overdue` sengaja nggak boleh dikirim — itu turunan tanggal jatuh tempo.
            'status' => ['sometimes', Rule::in([Equipment::STATUS_AKTIF, Equipment::STATUS_NONAKTIF])],
            'catatan' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama_alat.required' => 'Nama alat wajib diisi.',
            'serial_number.required' => 'Nomor seri wajib diisi.',
            'serial_number.unique' => 'Nomor seri sudah dipakai alat lain.',
            'kategori.required' => 'Kategori wajib diisi.',
            'kategori.exists' => 'Kategori itu nggak ada. Ambil daftarnya dari GET /api/categories.',
            'pelanggan_id.required' => 'Pelanggan wajib diisi.',
            'pelanggan_id.exists' => 'Pelanggan itu nggak ada.',
            'nama_alat_kemampuan.exists' => 'Jenis alat itu nggak ada di kemampuan kalibrasi kategori ini. '
                .'Ambil daftarnya dari GET /api/categories/{kode}.',
            'range_max.gte' => 'Batas atas rentang ukur nggak boleh lebih kecil dari batas bawah.',
            'tanggal_jatuh_tempo.after_or_equal' => 'Tanggal jatuh tempo nggak boleh sebelum tanggal kalibrasi terakhir.',
            'status.in' => 'Status cuma boleh `aktif` atau `nonaktif` — `overdue` dihitung otomatis dari tanggal jatuh tempo.',
        ];
    }

    /**
     * Kategori buat ngecek `nama_alat_kemampuan`: dari `kategori` (kode) kalau
     * request ini ngirimnya, kalau nggak (mis. PATCH yang cuma ubah field
     * lain) jatuh balik ke kategori alat yang udah ada.
     */
    private function resolveEquipmentCategoryId(int $organizationId, ?Equipment $equipment): ?int
    {
        $kode = $this->input('kategori');

        if ($kode !== null) {
            return EquipmentCategory::where('organization_id', $organizationId)
                ->where('kode', $kode)
                ->value('id');
        }

        return $equipment?->equipment_category_id;
    }
}

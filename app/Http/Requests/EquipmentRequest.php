<?php

namespace App\Http\Requests;

use App\Models\Equipment;
use App\Models\EquipmentCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
                    ->where('equipment_category_id', $this->resolveEquipmentCategoryId($organizationId, $equipment))
                    // `deleted_at` disaring TANGAN — `Rule::exists` nembak
                    // tabelnya langsung, bukan lewat model, jadi saringan
                    // bawaan `SoftDeletes` nggak ikut. Sejak baris kemampuan
                    // bisa dinonaktifkan admin (migrasi 2026_08_24_100000),
                    // tanpa baris ini nama alat yang UDAH dimatiin masih lolos
                    // validasi dan bisa ditautkan ke alat baru. Yang kejadian
                    // sesudahnya senyap: `GumCalculator` nggak akan pernah nemu
                    // barisnya (kefilter `SoftDeletes` di sana), sesinya jatuh
                    // ke jalur generik tanpa lantai CMC, dan sertifikatnya
                    // terbit dengan U95 yang lebih kecil dari yang
                    // diakreditasi.
                    ->whereNull('deleted_at'),
            ],
            'merk' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'no_identifikasi' => ['nullable', 'string', 'max:100'],
            'range_min' => ['nullable', 'numeric'],
            'range_max' => ['nullable', 'numeric', 'gte:range_min'],
            'satuan' => ['nullable', 'string', 'max:50'],
            'resolusi' => ['nullable', 'numeric', 'min:0'],

            // Band resolusi/satuan per titik — dua bentuk baris, dan keduanya
            // harus lolos lewat kolom yang sama (lihat `Equipment::bandResolusi()`):
            //
            //  - berkunci `titik` — alat yang SATUANNYA beda antar titik
            //    (Conductivity: 25 & 1412 µS/cm, 111 mS/cm). Ambang numerik
            //    nggak bisa dipakai di sini karena 111 mS/cm secara angka lebih
            //    kecil dari 1412 µS/cm.
            //  - berkunci `maks` — satuan seragam, resolusi berubah menurut
            //    besar pembacaan (Turbidimeter).
            //
            // `sometimes`: PATCH yang nggak nyebut field ini nggak boleh
            // ngehapus band yang sudah diisi lewat panel admin. Kiriman `[]`
            // yang eksplisit tetap ngosongin — itu memang yang diminta.
            'resolusi_rentang' => ['sometimes', 'nullable', 'array'],
            'resolusi_rentang.*' => ['array'],
            'resolusi_rentang.*.titik' => ['nullable', 'numeric'],
            'resolusi_rentang.*.maks' => ['nullable', 'numeric'],
            // `min:0` nggak cukup: resolusi 0 bikin `desimalDariResolusi()`
            // jatuh ke default 4 desimal diam-diam, dan sertifikatnya ngaku
            // presisi yang alatnya nggak punya.
            'resolusi_rentang.*.resolusi' => ['required', 'numeric', 'gt:0'],
            'resolusi_rentang.*.satuan' => ['nullable', 'string', 'max:20'],
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
            'resolusi_rentang.*.resolusi.required' => 'Tiap baris resolusi per titik wajib punya angka resolusi.',
            'resolusi_rentang.*.resolusi.gt' => 'Resolusi harus lebih besar dari nol.',
            'tanggal_jatuh_tempo.after_or_equal' => 'Tanggal jatuh tempo nggak boleh sebelum tanggal kalibrasi terakhir.',
            'status.in' => 'Status cuma boleh `aktif` atau `nonaktif` — `overdue` dihitung otomatis dari tanggal jatuh tempo.',
        ];
    }

    /**
     * Satu baris `resolusi_rentang` cuma boleh berkunci `titik` ATAU `maks`,
     * nggak dua-duanya.
     *
     * `Equipment::bandResolusi()` mriksa band ber-`titik` duluan dan langsung
     * balik begitu ketemu yang cocok, jadi baris yang punya dua kunci bikin
     * `maks`-nya nggak pernah kepakai — diam, tanpa error, dan ketahuannya baru
     * waktu sertifikat kecetak dengan desimal yang salah.
     *
     * `maks: null` sengaja tetap sah: itu golongan terakhir Turbidimeter yang
     * nampung sisa pembacaan di atas band sebelumnya.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $rentang = $this->input('resolusi_rentang');

                if (! is_array($rentang)) {
                    return;
                }

                foreach ($rentang as $i => $band) {
                    if (! is_array($band)) {
                        continue;
                    }

                    $punyaTitik = ($band['titik'] ?? null) !== null;
                    $punyaMaks = array_key_exists('maks', $band);

                    if ($punyaTitik && $punyaMaks && $band['maks'] !== null) {
                        $validator->errors()->add(
                            "resolusi_rentang.{$i}.maks",
                            'Isi `titik` ATAU `maks`, jangan dua-duanya — band ber-`titik` selalu menang '
                            .'dan `maks`-nya bakal diabaikan diam-diam.',
                        );
                    }

                    if (! $punyaTitik && ! $punyaMaks) {
                        $validator->errors()->add(
                            "resolusi_rentang.{$i}.titik",
                            'Baris resolusi wajib nyebut `titik` (satuan beda antar titik) atau `maks` '
                            .'(resolusi berubah menurut besar pembacaan).',
                        );
                    }
                }
            },
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

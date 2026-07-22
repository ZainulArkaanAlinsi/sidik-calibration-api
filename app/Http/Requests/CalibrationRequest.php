<?php

namespace App\Http\Requests;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\Standard;
use App\Services\GumCalculator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Storage;
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
            'nomor_order' => ['sometimes', 'nullable', 'string', 'max:100'],
            // Tanggal alat diterima dari customer — logisnya nggak setelah
            // tanggal kalibrasinya sendiri.
            'tanggal_terima' => ['sometimes', 'nullable', 'date', 'before_or_equal:tanggal_kalibrasi'],

            // UUID yang mobile generate sekali per submission — kalau request-nya
            // di-retry (mis. sinyal putus pas nunggu respons) dengan key yang
            // sama, backend balikin sesi yang udah ada, bukan bikin dobel.
            // Opsional & backward-compatible: mobile lama yang belum kirim ini
            // tetap jalan seperti biasa (lihat CalibrationController::store()).
            'client_request_id' => ['sometimes', 'nullable', 'uuid'],
            'input_method' => ['sometimes', Rule::in(['manual', 'ocr'])],
            'lokasi' => ['sometimes', Rule::in(['lab', 'onsite'])],
            // Ruangan lab spesifik (mis. "Lab. Uji A") — beda dari `lokasi` yang
            // cuma lab vs onsite. Opsional; disaring ke organisasi pemanggil.
            'room_id' => [
                'sometimes', 'nullable',
                Rule::exists('rooms', 'id')->where('organization_id', $organizationId),
            ],
            'suhu_ruang' => ['nullable', 'numeric'],
            'kelembaban' => ['nullable', 'numeric', 'between:0,100'],

            // Kondisi lingkungan rinci (worksheet pH). Semua opsional — alat
            // non-pH cukup `suhu_ruang`/`kelembaban` di atas. Rata-rata & U95%
            // dihitung server (lihat CalibrationController::dataLingkungan).
            'suhu_ruang_awal' => ['sometimes', 'nullable', 'numeric'],
            'suhu_ruang_akhir' => ['sometimes', 'nullable', 'numeric'],
            'kelembaban_awal' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'kelembaban_akhir' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'suhu_ruang_koreksi' => ['sometimes', 'nullable', 'numeric'],
            'kelembaban_koreksi' => ['sometimes', 'nullable', 'numeric'],
            // U95% sertifikat thermohygro — dipakai ngitung U95% lingkungan.
            'suhu_ruang_u_std' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'kelembaban_u_std' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            // Alternatif: kirim U95% lingkungan jadi (kalau nggak mau server hitung).
            'suhu_ruang_u95' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'kelembaban_u95' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'thermohygro' => ['sometimes', 'nullable', 'string', 'max:50'],

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
            // As-found (sebelum alat di-adjustment) — murni dokumentasi kondisi
            // alat, TIDAK ikut hitungan GUM. Nggak ada minimum jumlah karena
            // bukan data resmi (beda dari `pembacaan` di atas).
            'measurements.*.pembacaan_sebelum' => ['sometimes', 'array'],
            'measurements.*.pembacaan_sebelum.*' => ['required', 'numeric'],
            // Suhu larutan tiap pembacaan (pasangan pH/°C di worksheet pH),
            // sejajar per-index sama pembacaan. Opsional (alat non-pH nggak isi).
            'measurements.*.suhu' => ['sometimes', 'array'],
            'measurements.*.suhu.*' => ['required', 'numeric'],
            'measurements.*.suhu_sebelum' => ['sometimes', 'array'],
            'measurements.*.suhu_sebelum.*' => ['required', 'numeric'],
            // Nilai standar sebelum adjustment (bisa beda tipis dari sesudah
            // karena koreksi suhu larutan) — buat ngitung koreksi as-found.
            'measurements.*.titik_ukur_sebelum' => ['sometimes', 'nullable', 'numeric'],
            // Sebagian kategori alat (mis. pH) butuh standar BEDA per titik ukur
            // (buffer 4/7/10) — kosong berarti titik ini ikut `standard_id` sesi.
            'measurements.*.standard_id' => [
                'sometimes', 'nullable',
                Rule::exists('standards', 'id')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at'),
            ],

            // Metadata OCR — opsional, sejajar sama `pembacaan` (index ke-i punya
            // pembacaan[i] + ocr[i]). OCR jalan di mobile; backend cuma nyimpen
            // fotonya, skor keyakinan, & teks mentahnya buat jejak audit. Pembacaan
            // yang datang lewat OCR mulai belum terverifikasi (lihat controller).
            'measurements.*.ocr' => ['sometimes', 'array'],
            'measurements.*.ocr.*.photo_path' => [
                'nullable', 'string',
                // Wajib nunjuk ke hasil upload /calibrations/photos — cegah path
                // sembarangan (path traversal) nyelip ke DB.
                'regex:/^measurements\/[A-Za-z0-9]+\.(jpg|jpeg|png|webp)$/',
            ],
            'measurements.*.ocr.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
            'measurements.*.ocr.*.raw_text' => ['nullable', 'string', 'max:255'],
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

            // Semua standar yang kesebut (sesi + per-titik) dimuat sekaligus di
            // sini, dipakai buat dua-duanya di bawah — biar nggak query
            // Standard berkali-kali buat baris yang sama.
            $idPerTitik = array_column($this->input('measurements', []), 'standard_id');
            $standarById = Standard::query()
                ->whereIn('id', array_filter([$this->integer('standard_id'), ...$idPerTitik]))
                ->get()
                ->keyBy('id');

            $standar = $standarById->get($this->integer('standard_id'));

            // Standar yang sertifikatnya udah lewat masa berlaku nggak boleh
            // jadi acuan — ketertelusurannya putus, dan itu temuan asesor.
            if ($standar && ! $standar->masihBerlaku()) {
                $validator->errors()->add(
                    'standard_id',
                    'Sertifikat standar acuan ini udah kadaluarsa, jadi nggak boleh dipakai kalibrasi.',
                );
            }

            // Sama kayak standar_id sesi: standar per-titik yang di-override juga
            // wajib masih berlaku.
            foreach ((array) $this->input('measurements', []) as $i => $titik) {
                $standardIdTitik = $titik['standard_id'] ?? null;
                if ($standardIdTitik === null) {
                    continue;
                }

                $standarTitik = $standarById->get($standardIdTitik);

                if ($standarTitik && ! $standarTitik->masihBerlaku()) {
                    $validator->errors()->add(
                        "measurements.$i.standard_id",
                        'Sertifikat standar acuan titik ini udah kadaluarsa, jadi nggak boleh dipakai kalibrasi.',
                    );
                }
            }

            // Kalau ada metadata OCR: jumlahnya wajib sama persis dengan pembacaan
            // (dipetakan per-index), dan tiap foto yang dirujuk beneran ada di disk.
            foreach ((array) $this->input('measurements', []) as $i => $titik) {
                if (! isset($titik['ocr'])) {
                    continue;
                }

                $ocr = array_values((array) $titik['ocr']);
                $pembacaan = (array) ($titik['pembacaan'] ?? []);

                if (count($ocr) !== count($pembacaan)) {
                    $validator->errors()->add(
                        "measurements.$i.ocr",
                        'Jumlah data OCR harus sama dengan jumlah pembacaan di titik ini.',
                    );

                    continue;
                }

                foreach ($ocr as $j => $meta) {
                    $path = $meta['photo_path'] ?? null;
                    if ($path !== null && ! Storage::disk('local')->exists($path)) {
                        $validator->errors()->add(
                            "measurements.$i.ocr.$j.photo_path",
                            'Foto yang dirujuk nggak ketemu — upload dulu lewat /calibrations/photos.',
                        );
                    }
                }
            }
        });
    }
}

<?php

namespace App\Http\Requests\Studio;

use App\Http\Requests\Concerns\MencariWorkbookStudio;
use App\Services\Studio\WorkbookScalarEditPreparer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator;

/**
 * `POST /api/studio/workbook/{workbook}/draf` (buat) dan
 * `PUT /api/studio/workbook/{workbook}/draf/{versi}` (perbarui) -- simpan
 * suntingan angka ke DRAF data acuan (rencana A1, B3/B4). Satu kelas untuk
 * keduanya: bentuk bodinya sama, hanya nama kunci hash isi yang beda
 * (`base_isi_sha256` pada POST, `expected_isi_sha256` pada PUT).
 *
 * ## Urutan: organisasi dulu, baru bentuk bodi, baru validasi
 *
 * Pencarian workbook ada di [MencariWorkbookStudio] dan dijalankan di
 * [authorize], yang Laravel panggil SEBELUM aturan validasi: workbook lab
 * lain/arsip/tak ada dijawab 404 apa pun isi bodinya. Baru sesudah itu bodi
 * non-JSON ditolak (415 `harus_json`), lalu aturan di bawah (422).
 *
 * ## Bentuk yang diterima (persis)
 *
 * `workbook_sha256`, `peta_sel_sha256`, hash isi: 64 heksadesimal huruf kecil.
 * `alasan`: 10..500 karakter. `suntingan`: daftar 1..300 butir `{sel, nilai}`
 * -- kedua-duanya string, tidak ada kunci lain. Jalur, tipe, dan `bulat` TIDAK
 * pernah diterima dari klien: server menentukannya dari peta sel. Kunci
 * tambahan di mana pun = 422 (tidak diabaikan diam-diam).
 *
 * ## Hanya JSON, dan `suntingan` dari bodi MENTAH
 *
 * Bodi form-urlencoded/multipart melewati `TrimStrings`, sehingga " 0.84"
 * berubah jadi "0.84" tanpa sepengetahuan klien -- angka yang disimpan di
 * sertifikat tidak boleh lolos lewat normalisasi diam-diam. Karena itu
 * Content-Type selain JSON ditolak (415), dan `suntingan` dibaca dari teks
 * bodi mentah, bukan dari input yang sudah dipangkas. Query string diabaikan
 * (input() menggabungkannya sebagai cadangan untuk kunci yang tak ada di JSON,
 * dan query ikut dipangkas).
 */
class SimpanDrafAcuanRequest extends FormRequest
{
    use MencariWorkbookStudio;

    private const HEX64 = '/\A[0-9a-f]{64}\z/';

    public function authorize(): bool
    {
        $this->cariWorkbookStudio();

        if (! $this->isJson()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Bodi harus JSON (Content-Type: application/json). Bodi form dipangkas spasinya oleh server, dan angka sertifikat tidak boleh berubah diam-diam.',
                'kode' => 'harus_json',
            ], 415));
        }

        return true;
    }

    /** Kunci hash isi: `expected_isi_sha256` pada PUT, `base_isi_sha256` pada POST. */
    private function kunciHashIsi(): string
    {
        return $this->isMethod('PUT') ? 'expected_isi_sha256' : 'base_isi_sha256';
    }

    /** @return list<string> */
    private function kunciAtas(): array
    {
        return ['workbook_sha256', 'peta_sel_sha256', $this->kunciHashIsi(), 'alasan', 'suntingan'];
    }

    protected function prepareForValidation(): void
    {
        if (! $this->isJson()) {
            return;
        }

        // Query string tidak dipakai rute ini; kosongkan supaya tak jadi cadangan input().
        $this->query->replace([]);

        $mentah = json_decode((string) $this->getContent(), true);

        if (is_array($mentah) && array_key_exists('suntingan', $mentah)) {
            $this->merge(['suntingan' => $mentah['suntingan']]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'workbook_sha256' => ['required', 'string', 'regex:'.self::HEX64],
            'peta_sel_sha256' => ['required', 'string', 'regex:'.self::HEX64],
            $this->kunciHashIsi() => ['required', 'string', 'regex:'.self::HEX64],
            'alasan' => ['required', 'string', 'min:10', 'max:500'],
            'suntingan' => ['required', 'array', 'min:1', 'max:'.WorkbookScalarEditPreparer::MAX_EDITS],
            'suntingan.*' => ['required', 'array:sel,nilai'],
            'suntingan.*.sel' => ['required', 'string', 'max:'.WorkbookScalarEditPreparer::MAX_ADDRESS_BYTES],
            'suntingan.*.nilai' => ['required', 'string', 'max:'.WorkbookScalarEditPreparer::MAX_WIRE_BYTES],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $hex = 'harus berupa 64 karakter heksadesimal huruf kecil (salinan hash dari server).';
        $isi = $this->kunciHashIsi();

        return [
            'workbook_sha256.required' => 'workbook_sha256 wajib diisi.',
            'workbook_sha256.regex' => "workbook_sha256 {$hex}",
            'peta_sel_sha256.required' => 'peta_sel_sha256 wajib diisi.',
            'peta_sel_sha256.regex' => "peta_sel_sha256 {$hex}",
            "{$isi}.required" => "{$isi} wajib diisi.",
            "{$isi}.regex" => "{$isi} {$hex}",
            'alasan.required' => 'Alasan suntingan wajib diisi (tercatat di jejak audit).',
            'alasan.min' => 'Alasan suntingan minimal 10 karakter.',
            'alasan.max' => 'Alasan suntingan maksimal 500 karakter.',
            'suntingan.required' => 'Daftar suntingan wajib diisi.',
            'suntingan.array' => 'Suntingan harus berupa daftar.',
            'suntingan.min' => 'Minimal satu suntingan.',
            'suntingan.max' => 'Maksimal '.WorkbookScalarEditPreparer::MAX_EDITS.' suntingan sekali simpan.',
            'suntingan.*.array' => 'Tiap suntingan harus berisi tepat sel dan nilai.',
            'suntingan.*.required' => 'Tiap suntingan harus berisi tepat sel dan nilai.',
            'suntingan.*.sel.required' => 'Sel suntingan wajib diisi.',
            'suntingan.*.sel.string' => 'Sel suntingan harus berupa teks.',
            'suntingan.*.sel.max' => 'Alamat sel terlalu panjang.',
            'suntingan.*.nilai.required' => 'Nilai suntingan wajib diisi (teks angka desimal).',
            'suntingan.*.nilai.string' => 'Nilai suntingan harus berupa teks angka desimal, bukan angka JSON.',
            'suntingan.*.nilai.max' => 'Nilai suntingan terlalu panjang.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $masuk = $this->getInputSource()->all();
            $diizinkan = $this->kunciAtas();

            foreach (array_keys($masuk) as $kunci) {
                if (! in_array($kunci, $diizinkan, true)) {
                    $v->errors()->add((string) $kunci, 'Kunci "'.mb_substr((string) $kunci, 0, 40).'" tidak dikenal. Hanya '.implode(', ', $diizinkan).' yang diterima.');
                }
            }

            if (is_array($masuk['suntingan'] ?? null) && ! array_is_list($masuk['suntingan'])) {
                $v->errors()->add('suntingan', 'Suntingan harus berupa daftar (array), bukan objek.');
            }
        });
    }
}

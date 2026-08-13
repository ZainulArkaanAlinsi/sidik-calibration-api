<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Bentuk kiriman HP buat satu kali pindai lembar kerja.
 *
 * Di sini yang dijaga cuma BENTUKNYA (tipe & batas wajar). Yang nentuin
 * kirimannya masuk akal atau nggak — kunci selnya ada di template, jumlah selnya
 * pas, geometrinya cukup presisi — ada di `PemrosesScanLembarKerja`, karena
 * jawabannya butuh template yang mesti dibangun dulu dari profil alatnya.
 *
 * Batas atas dipasang di mana-mana bukan buat gaya: endpoint ini nerima array
 * bersarang dari HP di lapangan, dan satu payload rusak nggak boleh bisa bikin
 * server ngunyah puluhan ribu baris.
 */
class WorksheetScanRequest extends FormRequest
{
    /**
     * Sebanyak-banyaknya sel dalam satu pindai. Lembar terpadat sekarang:
     * 3 titik × 5 Repeat × 2 kolom × 2 tabel = 60. Dikasih kelonggaran besar
     * buat lembar alat baru, tapi tetap ada langitnya.
     */
    private const MAKS_SEL = 600;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'template_id' => ['required', 'string', 'max:60'],
            'template_versi' => ['required', 'integer', 'between:1,999'],

            // Sesi boleh belum ada — teknisi kadang mindai sebelum sesinya
            // dibikin. Kalau dikirim, aksesnya dibatasi di controller.
            'calibration_session_id' => ['sometimes', 'nullable', 'integer'],
            // Dipakai template yang bentuknya ikut alat pelanggan (Conductivity
            // milih satuan µS/cm vs mS/cm dari resolusi alatnya).
            'equipment_id' => ['sometimes', 'nullable', 'integer'],
            'jumlah_pengulangan' => ['sometimes', 'nullable', 'integer', 'between:2,10'],

            'qr' => ['required', 'array'],
            'qr.terbaca' => ['required', 'boolean'],
            'qr.isi' => ['sometimes', 'nullable', 'string', 'max:255'],

            'geometri' => ['required', 'array'],
            'geometri.marker' => ['sometimes', 'array', 'max:8'],
            'geometri.marker.*.id' => ['sometimes', 'integer', 'between:0,7'],
            'geometri.marker.*.x' => ['required_with:geometri.marker.*.y', 'numeric'],
            'geometri.marker.*.y' => ['required_with:geometri.marker.*.x', 'numeric'],
            'geometri.residual_reproyeksi_px' => ['sometimes', 'nullable', 'numeric', 'between:0,10000'],
            'geometri.grid_tersnap' => ['sometimes', 'nullable', 'boolean'],
            'geometri.ukuran_referensi.w' => ['sometimes', 'nullable', 'integer', 'between:1,20000'],
            'geometri.ukuran_referensi.h' => ['sometimes', 'nullable', 'integer', 'between:1,20000'],

            'kualitas' => ['sometimes', 'array'],
            'kualitas.blur_laplacian' => ['sometimes', 'nullable', 'numeric', 'between:0,100000'],
            'kualitas.kecerahan_rata' => ['sometimes', 'nullable', 'numeric', 'between:0,255'],
            'kualitas.rasio_glare' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'kualitas.sudut_kemiringan_deg' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'kualitas.px_per_sel_tinggi' => ['sometimes', 'nullable', 'integer', 'between:0,10000'],

            'perangkat' => ['sometimes', 'nullable', 'array'],
            'perangkat.model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'perangkat.os' => ['sometimes', 'nullable', 'string', 'max:60'],
            'perangkat.app' => ['sometimes', 'nullable', 'string', 'max:30'],
            'perangkat.ocr' => ['sometimes', 'nullable', 'string', 'max:60'],

            'diambil_pada' => ['sometimes', 'nullable', 'date'],

            'sel' => ['required', 'array', 'min:1', 'max:'.self::MAKS_SEL],
            'sel.*.tabel_id' => ['required', 'string', 'max:60'],
            'sel.*.baris_ke' => ['required', 'integer', 'between:1,200'],
            'sel.*.repeat_no' => ['required', 'integer', 'between:1,50'],
            'sel.*.field_id' => ['required', 'string', 'max:30'],
            // Teks apa adanya dari OCR — termasuk yang jelas ngawur. Yang mutusin
            // dia angka atau bukan bukan di sini.
            'sel.*.teks_mentah' => ['sometimes', 'nullable', 'string', 'max:64'],
            'sel.*.confidence_ocr' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'sel.*.kotak_teks_di_dalam_sel' => ['sometimes', 'nullable', 'boolean'],
            'sel.*.kotak' => ['sometimes', 'nullable', 'array'],
            'sel.*.kotak.x' => ['sometimes', 'nullable', 'numeric'],
            'sel.*.kotak.y' => ['sometimes', 'nullable', 'numeric'],
            'sel.*.kotak.w' => ['sometimes', 'nullable', 'numeric'],
            'sel.*.kotak.h' => ['sometimes', 'nullable', 'numeric'],
            'sel.*.titik_ukur' => ['sometimes', 'nullable', 'numeric'],
            'sel.*.standard_id' => ['sometimes', 'nullable', 'integer'],
            'sel.*.sumber' => ['sometimes', 'nullable', 'string', 'max:40'],

            'sel_jangkar' => ['sometimes', 'array', 'max:100'],
            'sel_jangkar.*.field_id' => ['sometimes', 'nullable', 'string', 'max:30'],
            'sel_jangkar.*.repeat_no' => ['sometimes', 'nullable', 'integer', 'between:1,50'],
            'sel_jangkar.*.teks_mentah' => ['sometimes', 'nullable', 'string', 'max:32'],
            'sel_jangkar.*.cocok' => ['required_with:sel_jangkar.*.field_id', 'boolean'],

            // Citra buat audit & bahan dataset. Opsional: sinyal di lapangan
            // sering nggak kuat, dan nolak hasil pindai cuma gara-gara fotonya
            // gagal naik itu ngorbanin kerjaan teknisi demi arsip.
            'citra' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'citra_warp' => ['sometimes', 'nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sel.required' => 'Nggak ada satu sel pun yang kekirim dari hasil pindai.',
            'sel.max' => 'Sel yang dikirim kebanyakan buat satu lembar kerja.',
            'qr.required' => 'Status pembacaan QR wajib dikirim.',
            'geometri.required' => 'Data penyelarasan lembar (marker & homography) wajib dikirim.',
            'citra.max' => 'Foto maksimal 8 MB.',
            'citra_warp.max' => 'Foto maksimal 8 MB.',
        ];
    }

    /**
     * Payload bersih buat `PemrosesScanLembarKerja` — tanpa file & tanpa kunci
     * asing.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'template_id' => $this->string('template_id')->toString(),
            'template_versi' => $this->integer('template_versi'),
            'qr' => (array) $this->input('qr', []),
            'geometri' => (array) $this->input('geometri', []),
            'kualitas' => (array) $this->input('kualitas', []),
            'perangkat' => (array) $this->input('perangkat', []),
            'sel' => array_values((array) $this->input('sel', [])),
            'sel_jangkar' => array_values((array) $this->input('sel_jangkar', [])),
        ];
    }
}

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

    /**
     * Langit array formulir ASLI. Pilot pH: 93 jangkar teks, 4 isian, 9 centang.
     */
    private const MAKS_JANGKAR = 200;

    private const MAKS_ISIAN = 20;

    private const MAKS_CENTANG = 30;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * `kertas: "asli"` = formulir SIDIK-FM-CAL asli lab, tanpa marker & QR
     * (`App\Services\Ocr\FormulirAsli`). Tanpa `kertas` atau `cetak` = lembar
     * cetak bermarker, kontrak lama.
     */
    public function kertasAsli(): bool
    {
        return $this->input('kertas') === 'asli';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $aturan = $this->aturanCetak();

        return $this->kertasAsli() ? [...$aturan, ...$this->aturanAsli()] : $aturan;
    }

    /**
     * Tambahan & penimpa untuk formulir asli. Aturan cetak di atas TIDAK
     * berubah: ini dipasang hanya kalau `kertas=asli`.
     *
     * @return array<string, mixed>
     */
    private function aturanAsli(): array
    {
        return [
            // Formulir asli tidak punya QR — pengenalnya kode FM tercetak.
            'qr' => ['sometimes', 'nullable', 'array'],
            'qr.terbaca' => ['sometimes', 'nullable', 'boolean'],
            'kode_dokumen_terbaca' => ['required', 'string', 'max:40'],
            'revisi_terbaca' => ['sometimes', 'nullable', 'string', 'max:20'],
            // Revisi TERCETAK formulir (Rev.0 sah di dokumen mutu).
            'template_versi' => ['required', 'integer', 'between:0,999'],

            // Jangkar = tulisan cetak formulir yang dicocokkan HP ke
            // `geometri.jangkar_teks[indeks]` template. Jumlah & sebarannya
            // dinilai pemroses, bukan di sini.
            'geometri.jangkar_cocok' => ['sometimes', 'array', 'max:'.self::MAKS_JANGKAR],
            'geometri.jangkar_cocok.*.indeks' => ['required', 'integer', 'between:0,9999'],
            'geometri.jangkar_cocok.*.teks_mentah' => ['sometimes', 'nullable', 'string', 'max:64'],
            'geometri.residual_reproyeksi_pt' => ['sometimes', 'nullable', 'numeric', 'between:0,10000'],

            'isian' => ['sometimes', 'array', 'max:'.self::MAKS_ISIAN],
            'isian.*.kode' => ['required', 'string', 'max:40'],
            'isian.*.teks_mentah' => ['sometimes', 'nullable', 'string', 'max:64'],
            'isian.*.confidence_ocr' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
            'isian.*.kotak_teks_di_dalam_sel' => ['sometimes', 'nullable', 'boolean'],
            'isian.*.sumber' => ['sometimes', 'nullable', 'string', 'max:40'],

            'centang' => ['sometimes', 'array', 'max:'.self::MAKS_CENTANG],
            'centang.*.kode' => ['required', 'string', 'max:60'],
            'centang.*.baris_ke' => ['sometimes', 'nullable', 'integer', 'between:1,200'],
            'centang.*.pilihan' => ['sometimes', 'nullable', 'string', 'max:20'],
            // Rasio piksel gelap di dalam kotak, BUKAN OCR (PANDUAN §4).
            'centang.*.rasio_gelap' => ['sometimes', 'nullable', 'numeric', 'between:0,1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aturanCetak(): array
    {
        return [
            // `cetak` (bawaan) atau `asli` — lihat [kertasAsli].
            'kertas' => ['sometimes', 'nullable', 'string', 'in:cetak,asli'],

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
            'kode_dokumen_terbaca.required' => 'Kode formulir yang kebaca (SIDIK-FM-CAL-…) wajib dikirim untuk formulir asli.',
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
        $dasar = $this->payloadCetak();

        if (! $this->kertasAsli()) {
            return $dasar;
        }

        return [
            ...$dasar,
            'kertas' => 'asli',
            'kode_dokumen_terbaca' => $this->input('kode_dokumen_terbaca'),
            'revisi_terbaca' => $this->input('revisi_terbaca'),
            'isian' => array_values(
                array_map(
                    // Kolom boolean BARU — wajib ikut dirapikan, alasannya sama
                    // dengan `sel.*.kotak_teks_di_dalam_sel` (lihat [bolean]).
                    fn ($s) => $this->bolean((array) $s, ['kotak_teks_di_dalam_sel']),
                    (array) $this->input('isian', []),
                ),
            ),
            'centang' => array_values(array_map(
                static fn ($c): array => (array) $c,
                (array) $this->input('centang', []),
            )),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadCetak(): array
    {
        return [
            'template_id' => $this->string('template_id')->toString(),
            'template_versi' => $this->integer('template_versi'),
            'qr' => $this->bagian('qr', ['terbaca']),
            'geometri' => $this->bagian('geometri', ['grid_tersnap']),
            'kualitas' => (array) $this->input('kualitas', []),
            'perangkat' => (array) $this->input('perangkat', []),
            'sel' => array_values(
                array_map(
                    fn ($s) => $this->bolean((array) $s, ['kotak_teks_di_dalam_sel']),
                    (array) $this->input('sel', []),
                ),
            ),
            'sel_jangkar' => array_values(
                array_map(
                    fn ($j) => $this->bolean((array) $j, ['cocok']),
                    (array) $this->input('sel_jangkar', []),
                ),
            ),
        ];
    }

    /**
     * @param  list<string>  $bool
     * @return array<string, mixed>
     */
    private function bagian(string $kunci, array $bool): array
    {
        return $this->bolean((array) $this->input($kunci, []), $bool);
    }

    /**
     * Rapikan kolom boolean yang lewat `multipart/form-data`.
     *
     * Endpoint ini nerima DUA bentuk kiriman — JSON, dan multipart waktu HP
     * ikut nglampirin citra audit. Bedanya kelihatan sepele tapi mematikan:
     * multipart nggak punya tipe, jadi `true` nyampe sebagai string `"1"`.
     * Tahap penjagaannya ngebandingin PAKAI TIPE (`!== true`, `=== false`),
     * jadi tanpa perapian ini:
     *
     *  - `qr.terbaca` selalu dianggap NGGAK kebaca → tiap pindai bercitra
     *    ditolak `template_tidak_dikenali`, dan pesannya nyuruh teknisi
     *    mastiin QR-nya kefoto — padahal QR-nya udah kebaca;
     *  - `kotak_teks_di_dalam_sel: false` nggak pernah kena, jadi angka yang
     *    MELUBER dari sel tetangga lolos tanpa ditandai. Yang ini arah
     *    bahayanya kebalik: bukan nolak yang sah, tapi nerima yang salah.
     *
     * Nilai yang nggak dikirim dibiarin nggak ada — `null` (HP versi lama yang
     * belum ngirim kolomnya) beda artinya dari `false`, dan `ValidasiSel`
     * emang mbedain keduanya.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $bool
     * @return array<string, mixed>
     */
    private function bolean(array $data, array $bool): array
    {
        foreach ($bool as $kunci) {
            if (array_key_exists($kunci, $data) && $data[$kunci] !== null) {
                $data[$kunci] = filter_var(
                    $data[$kunci],
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                ) ?? false;
            }
        }

        return $data;
    }
}

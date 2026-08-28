<?php

namespace App\Services\Dokumen;

/**
 * Baca lembar kerja APA PUN jadi struktur, tanpa template dan tanpa geometri.
 *
 * ## Bedanya sama `WorksheetVisionExtractor`
 *
 * Yang itu membaca lembar yang sistem ini CETAK SENDIRI: template-nya diturunkan
 * dari `CalibrationProfile`, koordinat tiap selnya sudah diukur, dan promptnya
 * dikasih tahu duluan ada berapa titik dan berapa pengulangan. Hasilnya
 * `baris` — tabel dengan bentuk yang sudah diketahui sebelum fotonya diambil.
 * Akurasinya tinggi justru KARENA dia tahu bentuk yang dicari.
 *
 * Kelas ini kebalikannya: dia nggak dikasih tahu apa-apa soal bentuk lembarnya.
 * Yang dikembalikan bagian, field, dan tabel SESUAI yang benar-benar ada di
 * kertas — jumlah kolomnya, nama bagiannya, satuannya, semuanya dari dokumen.
 *
 * Dua-duanya perlu, dan urutannya penting: yang generik ini LANTAI DASAR-nya,
 * template cuma pemercepat kalau dokumennya kebetulan dikenali. Bukan
 * sebaliknya. Lembar yang belum pernah dilihat harus tetap kebaca — kalau
 * jawabannya "template nggak dikenal", sistemnya cuma sanggup membaca lembar
 * yang dia bikin sendiri.
 *
 * ## Nama alat itu KONTEKS, bukan perintah
 *
 * Kalau teknisi milih "pH Meter" tapi kertasnya bertuliskan Turbidimeter, yang
 * menang KERTASNYA — dan bedanya dilaporkan sebagai peringatan supaya orangnya
 * yang memutuskan. Diam-diam memakai skema pH buat lembar Turbidimeter
 * menghasilkan angka yang kelihatan wajar di tempat yang salah, dan itu jenis
 * kesalahan yang paling lama nggak ketahuan.
 */
class EkstraktorDokumenGenerik
{
    public function __construct(
        private readonly KlienVisi $klien,
        private readonly PenguraiStrukturDokumen $pengurai,
    ) {}

    /**
     * @param  string|null  $namaAlat  konteks opsional dari teknisi
     * @return array{ok: bool, status: string, dokumen: array<string, mixed>|null, raw: mixed, usage: array<string, int|null>, error: string|null, model: string}
     */
    public function ekstrak(string $isiGambar, string $mimeType, ?string $namaAlat = null): array
    {
        $jawab = $this->klien->minta(
            $isiGambar,
            $mimeType,
            $this->systemPrompt(),
            $this->instruksi($namaAlat),
            $this->skema(),
        );

        if (! $jawab['ok'] || ! is_array($jawab['data'])) {
            return [
                'ok' => false,
                'status' => $jawab['status'],
                'dokumen' => null,
                'raw' => $jawab['raw'],
                'usage' => $jawab['usage'],
                'error' => $jawab['error'],
                'model' => $jawab['model'],
            ];
        }

        $dokumen = $this->pengurai->urai($jawab['data']);
        $dokumen = $this->tandaiBedaAlat($dokumen, $namaAlat);

        return [
            'ok' => true,
            'status' => 'ok',
            'dokumen' => $dokumen,
            'raw' => $jawab['raw'],
            'usage' => $jawab['usage'],
            'error' => null,
            'model' => $jawab['model'],
        ];
    }

    /**
     * Bandingkan nama alat pilihan teknisi dengan yang terbaca di kertas.
     *
     * Dicek di sini, bukan diserahkan ke model: model yang disuruh
     * membandingkan gampang "mengalah" ke konteks yang dikasih — dia melihat
     * "pH Meter" di prompt dan cenderung membenarkannya. Perbandingannya harus
     * dilakukan di luar, atas teks yang sudah dia baca.
     *
     * @param  array<string, mixed>  $dokumen
     * @return array<string, mixed>
     */
    private function tandaiBedaAlat(array $dokumen, ?string $namaAlat): array
    {
        $terbaca = $dokumen['document']['equipment_name'] ?? null;

        if ($namaAlat === null || $namaAlat === '' || ! is_string($terbaca) || $terbaca === '') {
            return $dokumen;
        }

        if (! $this->miripAlat($namaAlat, $terbaca)) {
            $dokumen['warnings'][] = 'Alat yang dipilih `'.$namaAlat.'` beda dari yang terbaca di lembar `'
                .$terbaca.'`. Isi lembarnya yang dipakai — periksa dulu sebelum disimpan.';
        }

        return $dokumen;
    }

    /**
     * Kemiripan longgar: satu memuat yang lain sesudah dirapikan.
     *
     * Sengaja longgar. "pH Meter" vs "Calibration Worksheet - pH Meter" itu
     * dokumen yang SAMA, dan peringatan palsu tiap kali lembarnya kebaca
     * lengkap bakal bikin peringatannya diabaikan waktu benar-benar penting.
     */
    private function miripAlat(string $a, string $b): bool
    {
        $rapikan = static fn (string $s): string => preg_replace('/[^a-z0-9]+/', '', strtolower($s)) ?? '';

        $x = $rapikan($a);
        $y = $rapikan($b);

        if ($x === '' || $y === '') {
            return true;
        }

        return str_contains($y, $x) || str_contains($x, $y);
    }

    private function systemPrompt(): string
    {
        return <<<'TXT'
        Kamu membaca FOTO lembar kerja kalibrasi laboratorium dan mengubahnya jadi JSON terstruktur.

        Lembar kerja bisa berbentuk apa saja. Jangan berasumsi ada bentuk baku, jumlah kolom baku,
        nama bagian baku, atau jenis alat tertentu. Laporkan apa yang BENAR-BENAR ada di kertas.

        ATURAN YANG TIDAK BOLEH DILANGGAR

        1. JANGAN PERNAH mengarang. Kalau satu sel tidak terbaca, isi `value` dengan null dan beri
           `confidence` rendah. Tebakan yang kelihatan masuk akal jauh lebih berbahaya daripada
           null, karena tidak ada yang tahu itu tebakan.
        2. Sel tabel yang kosong atau tidak terbaca TETAP dilaporkan, lengkap dengan nomor `row`
           dan `column`-nya. Jangan pernah melewatinya — sel yang hilang membuat sel sesudahnya
           bergeser, dan angka yang mendarat di baris yang salah adalah kesalahan paling mahal
           di lembar kalibrasi.
        3. `confidence` adalah keyakinanmu yang sebenarnya, 0..1. Jangan tulis 0.99 untuk semuanya.
           Tulisan tangan yang miring, kecil, atau menyentuh garis memang pantas dapat nilai rendah.
        4. Bedakan teks TERCETAK dari TULISAN TANGAN:
           - `source: "static_document"` untuk yang sudah tercetak di formulir (label, judul,
             nama standar, instruksi, nilai standar yang memang sudah dicetak)
           - `source: "handwriting"` untuk yang ditulis teknisi
           Ini penting: tidak semua teks di lembar adalah data isian.
        5. Pisahkan LABEL dari VALUE. `Nama Alat : Digital Thermometer` adalah
           `{label: "Nama Alat", value: "Digital Thermometer"}`, bukan satu teks panjang.
           Label yang tidak ada isiannya tetap dilaporkan dengan `value: null`.
        6. Ambil satuan DARI DOKUMEN (`°C`, `%RH`, `pH`, `µS`, `mS`, `NTU`, `nm`, `kg`, `mL`, `s`,
           atau apa pun yang tertulis). Jangan menyeragamkan, jangan menerjemahkan.
        7. Checkbox jadi `type: "boolean"` dengan `value` true/false; kalau tidak jelas, `value: null`.
        8. Area tanda tangan jadi `type: "signature"` dengan `value: null`. Jangan mencoba membaca
           tanda tangan sebagai teks.
        9. Nama bagian (`section.name`) diambil dari judul yang tertulis di kertas. Kalau satu
           bagian tidak berjudul, pakai null.
        10. Bounding box (`bbox`) dalam PIKSEL gambar yang kamu terima: x, y dari pojok kiri atas,
            width, height. Kalau tidak yakin posisinya, hilangkan `bbox` sama sekali — jangan
            mengarang koordinat.

        TIPE DATA
        Tentukan `type` dari konteks: "text", "number", "date", "boolean", "signature", "multiline".
        Angka tetap ditulis apa adanya seperti di kertas (termasuk koma desimal) di dalam `value`.

        TABEL
        Tabel bisa bergaris penuh, bergaris sebagian, atau tanpa garis sama sekali. Kenali dari
        perataan kolom, jarak, pengulangan bentuk, dan judul kolomnya — bukan cuma dari garis.
        `headers` diisi judul kolom yang benar-benar tertulis. Baris dan kolom dinomori dari 0.
        TXT;
    }

    private function instruksi(?string $namaAlat): string
    {
        $konteks = $namaAlat !== null && $namaAlat !== ''
            ? "\n\nKonteks dari teknisi: alat yang sedang dikalibrasi kemungkinan `{$namaAlat}`. "
              .'Ini PETUNJUK saja, bukan kebenaran. Kalau isi lembarnya menunjukkan alat lain, '
              .'ikuti LEMBARNYA dan tulis nama alat yang benar-benar terbaca di `equipment_name`.'
            : '';

        return 'Baca lembar kerja di foto ini dan keluarkan JSON sesuai skema. '
            .'Sertakan kode dokumen dan revisinya kalau tertulis (biasanya di kepala atau kaki halaman). '
            .'Kalau ada bagian yang buram, terpotong, atau tidak terbaca, sebutkan di `warnings` '
            .'daripada menebak isinya.'
            .$konteks;
    }

    /**
     * @return array<string, mixed>
     */
    private function skema(): array
    {
        $bbox = [
            'type' => ['object', 'null'],
            'properties' => [
                'x' => ['type' => 'number'],
                'y' => ['type' => 'number'],
                'width' => ['type' => 'number'],
                'height' => ['type' => 'number'],
            ],
            'required' => ['x', 'y', 'width', 'height'],
            'additionalProperties' => false,
        ];

        $sumber = ['type' => 'string', 'enum' => ['static_document', 'handwriting', 'unknown']];

        return [
            'type' => 'object',
            'properties' => [
                'document' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => ['string', 'null']],
                        'equipment_name' => ['type' => ['string', 'null']],
                        'worksheet_code' => ['type' => ['string', 'null']],
                        'revision' => ['type' => ['string', 'null']],
                        'confidence' => ['type' => ['number', 'null']],
                    ],
                    'additionalProperties' => false,
                ],
                'sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => ['string', 'null']],
                            'fields' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'label' => ['type' => ['string', 'null']],
                                        'value' => ['type' => ['string', 'number', 'boolean', 'null']],
                                        'type' => ['type' => ['string', 'null']],
                                        'unit' => ['type' => ['string', 'null']],
                                        'source' => $sumber,
                                        'confidence' => ['type' => ['number', 'null']],
                                        'page' => ['type' => ['integer', 'null']],
                                        'bbox' => $bbox,
                                    ],
                                    'required' => ['label', 'value', 'confidence', 'source'],
                                    'additionalProperties' => false,
                                ],
                            ],
                            'tables' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name' => ['type' => ['string', 'null']],
                                        'headers' => ['type' => 'array', 'items' => ['type' => ['string', 'null']]],
                                        'confidence' => ['type' => ['number', 'null']],
                                        'cells' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'properties' => [
                                                    'row' => ['type' => 'integer'],
                                                    'column' => ['type' => 'integer'],
                                                    'value' => ['type' => ['string', 'number', 'boolean', 'null']],
                                                    'confidence' => ['type' => ['number', 'null']],
                                                    'source' => $sumber,
                                                    'page' => ['type' => ['integer', 'null']],
                                                    'bbox' => $bbox,
                                                ],
                                                'required' => ['row', 'column', 'value', 'confidence', 'source'],
                                                'additionalProperties' => false,
                                            ],
                                        ],
                                    ],
                                    'required' => ['headers', 'cells'],
                                    'additionalProperties' => false,
                                ],
                            ],
                        ],
                        'required' => ['name', 'fields', 'tables'],
                        'additionalProperties' => false,
                    ],
                ],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['document', 'sections', 'warnings'],
            'additionalProperties' => false,
        ];
    }
}

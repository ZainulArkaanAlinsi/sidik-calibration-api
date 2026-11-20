<?php

namespace App\Http\Requests;

use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Rules\AngkaTerhingga;
use App\Services\KonflikSerialAlat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EquipmentRequest extends FormRequest
{
    /** Nilai mutlak terbesar yang muat di kolom `decimal(20,8)`: 12 digit sebelum koma. */
    private const BATAS_DECIMAL = 999999999999;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $organizationId = $this->user()->organization_id;
        $equipment = $this->route('equipment');
        $wajibKalauBikinBaru = $this->isMethod('POST') ? 'required' : 'sometimes';

        // `gte:range_min` membandingkan ke FIELD LAIN lewat `Brick\Math`, yang melempar
        // `NumberFormatException` (HTTP 500) kalau `range_min` tak terhingga (`1e400`).
        // `bail` pada range_max tidak menolong karena nilai yang bermasalah ada di
        // atribut lain, jadi bandingannya dilewati HANYA bila `range_min` tak terhingga
        // (sudah ditolak di atribut `range_min` sendiri); selain itu perilaku lama.
        $minTakTerhingga = is_numeric($this->input('range_min')) && ! is_finite((float) $this->input('range_min'));

        // `gte:range_min` juga gagal kalau `range_min` KOSONG (null/tidak dikirim): Laravel
        // membandingkan ke field yang tidak ada dan menjawab "batas atas lebih kecil dari batas
        // bawah" padahal tidak ada batas bawah. Lembar pusat bisa menulis rentang satu angka
        // saja ("1000 oC"), jadi bandingannya hanya dipasang bila `range_min` terisi.
        $minKosong = ! $this->filled('range_min');

        $aturan = [
            'nama_alat' => [$wajibKalauBikinBaru, 'string', 'max:255'],
            'serial_number' => [
                $wajibKalauBikinBaru, 'string', 'max:100',
                // Unik per LABORATORIUM (`organization_id` = lab, BUKAN PT
                // pelanggan): dua lab boleh punya serial sama, dua PT pelanggan
                // di lab yang sama TIDAK. Ini yang menyaring alat yang masih
                // hidup; alat yang sudah dihapus ikut memegang nomor serinya
                // (indeks DB tidak mengabaikan soft delete) dan diperiksa di
                // `after()` supaya pesannya sendiri.
                Rule::unique('equipments', 'serial_number')
                    ->where('organization_id', $organizationId)
                    ->whereNull('deleted_at')
                    ->ignore($equipment?->id),
            ],
            'kategori' => [
                $wajibKalauBikinBaru, 'string',
                // Kategori NONAKTIF atau yang sudah dihapus tidak ditawarkan
                // untuk alat baru, dan alat lama tidak boleh PINDAH ke sana --
                // tetapi alat yang SUDAH berkategori itu tetap boleh disunting
                // selama kategorinya tidak diganti (form edit mengirim ulang
                // `kategori` apa adanya). Paket CRUD 18 Nov 2026.
                Rule::exists('equipment_categories', 'kode')->where(function ($kueri) use ($organizationId, $equipment): void {
                    $kodeSaatIni = $equipment?->category?->kode;

                    $kueri->where('organization_id', $organizationId)
                        ->whereNull('deleted_at')
                        ->where(function ($aktif) use ($kodeSaatIni): void {
                            $aktif->whereNull('nonaktif_pada');

                            if ($kodeSaatIni !== null) {
                                $aktif->orWhere('kode', $kodeSaatIni);
                            }
                        });
                }),
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
                    // Organisasi disaring TERPISAH dari kategorinya, walaupun
                    // kategorinya sendiri udah disaring per organisasi di
                    // `resolveEquipmentCategoryId()`.
                    //
                    // Kelihatan dobel, tapi dua kolom itu dua sumber kepemilikan
                    // yang beda: baris kemampuan bisa punya `organization_id`
                    // lab A sambil nunjuk kategori milik lab B (bentuk yang bisa
                    // lahir dari panel admin sebelum dropdown kategorinya
                    // disaring). Tanpa baris ini, nama alat milik lab A lolos
                    // validasi di lab B, alatnya ketautan, dan sesudah itu tiap
                    // sesi alat itu nyari CMC ke baris lab A — angka lab lain
                    // jadi lantai U95 di sertifikat lab ini.
                    ->where('organization_id', $organizationId)
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
            // Batas kolom `decimal(20,8)` (12 digit sebelum koma). Tanpa ini angka
            // seperti 1e15 lolos `numeric`, lalu INSERT gagal "Out of range
            // value" → 500, bukan 422 (BUG-10 uji E2E-QA, 18 Nov 2026).
            'range_min' => ['nullable', 'numeric', 'between:'.-self::BATAS_DECIMAL.','.self::BATAS_DECIMAL],
            'range_max' => ['nullable', 'numeric', ...($minTakTerhingga || $minKosong ? [] : ['gte:range_min']), 'between:'.-self::BATAS_DECIMAL.','.self::BATAS_DECIMAL],
            'satuan' => ['nullable', 'string', 'max:50'],
            // `gt:0`, bukan `min:0` — aturan yang SAMA dengan
            // `resolusi_rentang.*.resolusi` 22 baris di bawah, dan alasannya
            // ditulis lengkap di sana.
            //
            // Dua baris ini sempat berbeda: yang bawah sudah `gt:0` sementara
            // yang ini masih `min:0`, jadi nol tetap punya pintu masuk lewat
            // kolom resolusi utama. Nol bukan "belum diisi" — yang belum diisi
            // itu `null`, dan `null` tetap diterima.
            'resolusi' => ['nullable', 'numeric', 'gt:0', 'max:'.self::BATAS_DECIMAL],

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
            'toleransi' => ['nullable', 'numeric', 'min:0', 'max:'.self::BATAS_DECIMAL],
            'lokasi' => ['nullable', 'string', 'max:255'],
            'tanggal_kalibrasi_terakhir' => ['nullable', 'date'],
            'tanggal_jatuh_tempo' => ['nullable', 'date', 'after_or_equal:tanggal_kalibrasi_terakhir'],
            // `overdue` sengaja nggak boleh dikirim — itu turunan tanggal jatuh tempo.
            'status' => ['sometimes', Rule::in([Equipment::STATUS_AKTIF, Equipment::STATUS_NONAKTIF])],
            // Kolom `text` (65.535 byte): tanpa batas, 70.000 karakter menjawab 500
            // "Data too long" (BUG-9 uji E2E-QA, 18 Nov 2026). Catatan alat itu
            // beberapa kalimat, bukan dokumen.
            'catatan' => ['nullable', 'string', 'max:2000'],
        ];

        // Nilai tak terhingga (`1e400`) ditolak 422 SEBELUM aturan batas — lihat
        // `AngkaTerhingga::pasangDiSemua()`.
        return AngkaTerhingga::pasangDiSemua($aturan);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama_alat.required' => 'Nama alat wajib diisi.',
            'serial_number.required' => 'Nomor seri wajib diisi.',
            'serial_number.unique' => KonflikSerialAlat::PESAN_DIPAKAI_ALAT_LAIN,
            'kategori.required' => 'Kategori wajib diisi.',
            'kategori.exists' => 'Kategori itu nggak ada atau sudah dinonaktifkan. Ambil daftarnya dari GET /api/categories.',
            'pelanggan_id.required' => 'Pelanggan wajib diisi.',
            'pelanggan_id.exists' => 'Pelanggan itu nggak ada.',
            'nama_alat_kemampuan.exists' => 'Jenis alat itu nggak ada di kemampuan kalibrasi kategori ini. '
                .'Ambil daftarnya dari GET /api/categories/{kode}.',
            'range_max.gte' => 'Batas atas rentang ukur nggak boleh lebih kecil dari batas bawah.',
            'resolusi.gt' => 'Resolusi harus lebih besar dari nol.',
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
            // Nomor seri yang dipegang alat yang sudah dihapus. Dilewati bila
            // `unique` di atas sudah menolaknya (alat hidup yang memegang).
            function (Validator $validator): void {
                $serial = $this->input('serial_number');

                if (! is_string($serial) || $validator->errors()->has('serial_number')) {
                    return;
                }

                $pesan = KonflikSerialAlat::pesanBilaDipegangAlatDihapus(
                    $this->user()->organization_id,
                    $serial,
                    $this->route('equipment')?->id,
                );

                if ($pesan !== null) {
                    $validator->errors()->add('serial_number', $pesan);
                }
            },
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

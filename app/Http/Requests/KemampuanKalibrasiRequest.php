<?php

namespace App\Http\Requests;

use App\Models\CalibrationCapability;
use App\Models\EquipmentCategory;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/categories/{kode}/kemampuan` — teknisi (atau admin) nambah NAMA
 * ALAT baru ke master kemampuan kalibrasi dari lapangan.
 *
 * ## Yang sengaja NGGAK ada di sini
 *
 * Nggak ada `range_min`, `range_max`, `ketidakpastian_terbaik`, `faktor_cakupan`,
 * atau `metode`. Bukan kelupaan — angka-angka itu MASUK LANGSUNG ke perhitungan
 * ketidakpastian yang dicetak di sertifikat terakreditasi, dan satu salah ketik
 * di situ ngubah U95 tiap sesi yang pakai nama alat ini tanpa satu pun error.
 * Jalur pengisiannya cuma satu: panel admin (Master Data > Kemampuan Kalibrasi),
 * di layar yang nampilin akibatnya dan yang tiap perubahannya kecatat di
 * `audit_logs`. Endpoint ini cuma bikin NAMA-nya ada supaya teknisi bisa lanjut
 * kerja.
 *
 * Nggak ada `sumber` juga. Lihat komentarnya di `KemampuanKalibrasiController`.
 */
class KemampuanKalibrasiRequest extends FormRequest
{
    /**
     * Rapikan dulu sebelum divalidasi.
     *
     * Spasi di ujung & spasi ganda di tengah itu yang paling sering bikin nama
     * "baru" yang sebenernya kembar: `"Oven "` dan `"Oven"` lolos penyaring
     * kembar apa pun kalau nggak dirapikan duluan, dan di dropdown teknisi
     * dua-duanya kelihatan sama persis. Yang kejadian berikutnya: setengah alat
     * nunjuk ke baris A, setengah lagi ke baris B, dan cuma salah satunya yang
     * dilengkapi CMC-nya sama admin.
     */
    protected function prepareForValidation(): void
    {
        if (! is_string($this->input('nama_alat'))) {
            return;
        }

        $this->merge([
            'nama_alat' => trim((string) preg_replace('/\s+/u', ' ', $this->input('nama_alat'))),
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nama_alat' => ['required', 'string', 'max:255', $this->belumAdaDiKategori()],
            // Pembeda kalau satu nama alat punya beberapa besaran (mis. Autoklaf
            // punya "Suhu" & "Tekanan"). Opsional — kebanyakan alat nggak butuh.
            'parameter' => ['nullable', 'string', 'max:255'],
            'satuan' => ['nullable', 'string', 'max:50'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Nama alat nggak boleh kembar dalam SATU kategori + SATU organisasi, dan
     * perbandingannya nggak peduli besar-kecil huruf: "Oven" sama "oven" itu
     * alat yang sama.
     *
     * ## Kenapa dibandingin di PHP, bukan lewat `Rule::unique()` atau `LOWER()`
     *
     * Dua alasan, dan dua-duanya pernah bikin masalah nyata di repo ini:
     *
     *  1. `Rule::unique()` mbandingin apa adanya menurut collation kolomnya.
     *     MySQL produksi jalan di `utf8mb4_unicode_ci` (case-insensitive), tapi
     *     SQLite yang dipakai SELURUH test itu case-SENSITIVE. Artinya aturan
     *     yang ditulis pakai `unique()` bakal hijau di test buat kasus yang
     *     ditolak di produksi — atau sebaliknya. Test yang nggak bisa dipercaya
     *     buat aturan penulisan master data itu lebih buruk daripada nggak ada
     *     test-nya.
     *  2. `whereRaw('LOWER(nama_alat) = ?')` nyamain dua mesin buat ASCII, tapi
     *     `LOWER()` bawaan SQLite cuma ngerti ASCII — huruf beraksen nggak ikut
     *     diturunin. Beda perilakunya balik lagi, cuma pindah ke pojok yang
     *     lebih jarang kelihatan.
     *
     * Satu kategori isinya belasan nama alat, bukan ribuan, jadi narik
     * `distinct` nama-namanya lalu mbandingin pakai `mb_strtolower()` itu murah
     * DAN sama persis hasilnya di MySQL maupun SQLite.
     *
     * Baris yang udah di-soft-delete sengaja nggak ikut kehitung (kelewat
     * saringan bawaan `SoftDeletes`): nama alat yang dinonaktifin admin harus
     * bisa dipakai lagi, sama kayak kode ruangan di `RoomRequest`.
     */
    private function belumAdaDiKategori(): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal): void {
            $kategori = $this->kategori();

            // Kategorinya nggak ketemu — biar controller yang jawab 404. Ngadu
            // "nama kembar" di sini bakal nyesatin: yang salah bukan namanya.
            if ($kategori === null || ! is_string($nilai)) {
                return;
            }

            $dicari = mb_strtolower($nilai);

            $kembar = CalibrationCapability::query()
                ->where('equipment_category_id', $kategori->id)
                ->milikOrganisasi($this->user()?->organization_id)
                ->distinct()
                ->pluck('nama_alat')
                ->first(fn (?string $nama): bool => $nama !== null && mb_strtolower($nama) === $dicari);

            if ($kembar !== null) {
                $gagal("Nama alat \"{$kembar}\" udah ada di kategori ini. Pakai yang itu aja.");
            }
        };
    }

    /** Kategori dari segmen `{kode}` di URL, dibatesin ke organisasi pemanggil. */
    private function kategori(): ?EquipmentCategory
    {
        $kode = $this->route('kode');

        if (! is_string($kode)) {
            return null;
        }

        return EquipmentCategory::query()
            ->where('organization_id', $this->user()?->organization_id)
            ->where('kode', $kode)
            ->first();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nama_alat.required' => 'Nama alat wajib diisi.',
        ];
    }
}

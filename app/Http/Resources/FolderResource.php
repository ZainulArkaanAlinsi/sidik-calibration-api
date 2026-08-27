<?php

namespace App\Http\Resources;

use App\Models\Folder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Folder
 */
class FolderResource extends JsonResource
{
    /**
     * Breadcrumb cuma ikut di tampilan ISI folder, bukan di daftar.
     *
     * Bedanya biaya: daftar akar Arsip bisa berisi ratusan PT, dan tiap baris
     * bakal manjat `parent` sendiri-sendiri buat jalur yang selalu sepanjang
     * satu langkah (folder akar induknya null). Yang butuh jejaknya cuma layar
     * yang lagi berdiri DI DALAM satu folder.
     */
    private bool $denganBreadcrumb = false;

    /** Dipanggil `show()` & `folderPelanggan()`. */
    public function denganBreadcrumb(): static
    {
        $this->denganBreadcrumb = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nama' => $this->nama,
            // `sistem` = kebentuk otomatis dari data. Mobile pakai ini buat
            // nyembunyiin tombol rename/hapus, biar user nggak nyoba lalu
            // ditolak server.
            'tipe' => $this->tipe,
            'parent_id' => $this->parent_id,
            'keterangan' => $this->keterangan,
            // `id` di sini **id PELANGGAN**, dan itu BUKAN `id` di tingkat atas
            // (yang id folder). Bedanya menentukan: `GET /arsip/perusahaan/
            // {customer}/folder` mengikat ke `Customer`, jadi mengirim id folder
            // ke situ membuka arsip PT LAIN yang id-nya kebetulan sama —
            // status 200, nol error. Folder akar PT dibikin belakangan &
            // urutannya nggak ikut urutan pelanggan, jadi dua id itu memang
            // sering beda.
            //
            // `alamat` ikut karena kartu PT di layar Arsip memang menampilkannya
            // (nama tebal, alamat kecil di bawahnya) — tanpa ini barisnya selalu
            // kosong, dan yang kelihatan cuma nama. Sama datanya dengan yang
            // sudah dikirim `CustomerLookupResource`, jadi bukan data baru.
            'pelanggan' => $this->customer ? [
                'id' => $this->customer->id,
                'nama' => $this->customer->nama,
                'alamat' => $this->customer->alamat,
            ] : null,
            // Dua angka yang dipajang di daftar arsip. Dikirim cuma kalau
            // dihitung di controller — folder yang bukan akar PT nggak punya
            // pelanggan, dan `null` di situ lebih jujur daripada nol.
            'jumlah_alat' => $this->when(
                $this->customer?->jumlah_alat !== null,
                fn () => (int) $this->customer->jumlah_alat,
            ),
            'jumlah_sertifikat' => $this->when(
                $this->customer?->jumlah_sertifikat !== null,
                fn () => (int) $this->customer->jumlah_sertifikat,
            ),
            // Dihitung lewat withCount di controller; kalau nggak dimuat,
            // biarin null daripada nge-query per baris.
            'jumlah_folder' => $this->whenCounted('children'),
            'jumlah_file' => $this->whenCounted('files'),
            'dibuat_pada' => $this->created_at?->toIso8601ZuluString(),

            // Jejak AKAR -> folder yang lagi dibuka, tiap langkah bisa ditap
            // buat lompat balik.
            //
            // Nggak bisa diturunkan di HP: yang dipegang layar cuma folder yang
            // lagi terbuka, dan `parent_id` doang nggak cukup — namanya ada di
            // baris induk yang nggak ikut terkirim. Tanpa ini, satu-satunya
            // jalan keluar dari folder dalam itu tombol back beruntun.
            'breadcrumb' => $this->when(
                $this->denganBreadcrumb,
                fn () => array_map(fn (Folder $f) => [
                    'id' => $f->id,
                    'nama' => $f->nama,
                    'is_root' => $f->parent_id === null,
                ], $this->jalurKeAkar()),
            ),

            'sub_folder' => FolderResource::collection($this->whenLoaded('children')),
            'file' => FolderFileResource::collection($this->whenLoaded('files')),

            /*
             * Alat milik PT ini — cuma keisi di folder AKAR PT (lihat
             * `FolderController::show`). Bikin folder PT jadi satu pintu masuk:
             * buka PT-nya, kelihatan alat apa aja yang dia punya, bukan cuma
             * berkasnya.
             *
             * `tanggal_jatuh_tempo` ikut karena itu yang bikin daftar ini
             * berguna, bukan cuma lengkap: yang dicari admin waktu buka folder
             * PT biasanya "alat mana yang udah waktunya dikalibrasi lagi".
             */
            'alat' => $this->when(
                $this->relationLoaded('customer')
                    && $this->customer?->relationLoaded('equipments'),
                fn () => $this->customer->equipments->map(fn ($alat) => [
                    'id' => $alat->id,
                    'nama_alat' => $alat->nama_alat,
                    'merk' => $alat->merk,
                    'model' => $alat->model,
                    'serial_number' => $alat->serial_number,
                    'status' => $alat->status,
                    'tanggal_kalibrasi_terakhir' => $alat->tanggal_kalibrasi_terakhir?->toDateString(),
                    'tanggal_jatuh_tempo' => $alat->tanggal_jatuh_tempo?->toDateString(),
                    'jumlah_kalibrasi' => $alat->calibration_sessions_count,
                ])->values(),
            ),
        ];
    }
}

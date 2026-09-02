<?php

namespace App\Support\ImporPelanggan;

/**
 * Satu baris berkas impor yang sudah dibersihkan, sebelum diadu ke database.
 *
 * Dipisah dari pembacanya supaya pemilah kembar (`Pemilah`) tidak pernah
 * menyentuh CSV: yang diadu bentuk yang sudah pasti, bukan sel mentah yang
 * bisa saja ber-BOM, berspasi ganda, atau kelebihan kolom.
 *
 * `peringatan` ikut dibawa per baris, bukan dikumpulkan global. Laporan yang
 * cuma bilang "ada 12 peringatan" tidak bisa ditindaklanjuti siapa pun —
 * yang bisa cuma "baris 47: telepon terbaca `8.12E+11`".
 */
final readonly class BarisMasukan
{
    /**
     * @param  int  $nomorBaris  nomor baris di BERKAS (header = 1), bukan indeks array
     * @param  list<string>  $peringatan
     */
    public function __construct(
        public int $nomorBaris,
        public string $nama,
        public ?string $alamat = null,
        public ?string $contactPerson = null,
        public ?string $telepon = null,
        public ?string $email = null,
        public array $peringatan = [],
    ) {}

    /**
     * Isi yang akan ditulis ke `customers`.
     *
     * `organization_id`, `sumber`, dan `dibuat_oleh_user_id` sengaja TIDAK di
     * sini: ketiganya milik perintahnya, bukan milik berkas. Berkas yang bisa
     * menentukan organisasi tujuannya sendiri berarti satu berkas salah taruh
     * bisa menyuntik pelanggan ke lab lain.
     *
     * @return array<string, string|null>
     */
    public function untukDisimpan(): array
    {
        return [
            'nama' => $this->nama,
            'alamat' => $this->alamat,
            'contact_person' => $this->contactPerson,
            'telepon' => $this->telepon,
            'email' => $this->email,
        ];
    }
}

<?php

namespace App\Services\Direktori;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Direktori tempat usaha Google Places (Places API New, `places:searchText`).
 *
 * Dipilih karena cakupan perusahaan Indonesia-nya paling tebal di antara yang
 * bisa dipakai dengan sekadar API key — termasuk pabrik di kawasan industri
 * yang justru jadi pelanggan lab, dan yang jarang ada di sumber gratis.
 *
 * ## Yang harus disetel sebelum ini hidup
 *
 * `DIREKTORI_PERUSAHAAN_KEY` di `.env` SERVER. **Jangan pernah ditaruh di
 * aplikasi HP:** key di dalam APK bisa dicabut siapa pun dari berkasnya, lalu
 * dipakai orang lain atas tagihan lab ini. Makanya HP nembak endpoint lab, dan
 * lab yang memegang key-nya — sekalian bikin kuotanya bisa diawasi di satu
 * tempat.
 *
 * ## Biaya, dan kenapa hasilnya dibatasi
 *
 * Endpoint ini ditagih PER REQUEST. Layar HP-nya sengaja nggak mencari tiap
 * huruf diketik (ada jeda ketik di sisi HP) dan hasilnya dipotong di
 * [MAKS_HASIL] — daftar panjang nggak menolong orang yang lagi mencocokkan satu
 * papan nama, tapi tetap ikut ditagih.
 */
class GooglePlacesDirektori implements DirektoriPerusahaan
{
    private const ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

    /**
     * Cuma tiga field yang diminta. `X-Goog-FieldMask` itu yang menentukan
     * golongan tagihan di Places API — minta field yang nggak dipakai berarti
     * bayar golongan yang lebih mahal buat data yang langsung dibuang.
     */
    private const FIELD = 'places.id,places.displayName,places.formattedAddress';

    private const MAKS_HASIL = 10;

    public function __construct(
        private readonly ?string $key,
        private readonly int $timeoutDetik = 8,
    ) {}

    public function tersedia(): bool
    {
        return $this->key !== null && $this->key !== '';
    }

    public function cari(string $kata): array
    {
        if (! $this->tersedia()) {
            throw new DirektoriGagal('Direktori perusahaan belum disetel: API key kosong.');
        }

        try {
            $respons = Http::withHeaders([
                'X-Goog-Api-Key' => $this->key,
                'X-Goog-FieldMask' => self::FIELD,
            ])
                // Teknisi lagi berdiri di lapangan dengan sinyal seadanya.
                // Tanpa batas waktu, layarnya menggantung tanpa ujung dan dia
                // nggak pernah sampai ke jalur ketik tangan yang selalu jalan.
                ->timeout($this->timeoutDetik)
                ->post(self::ENDPOINT, [
                    'textQuery' => $kata,
                    // Dikunci ke Indonesia: tanpa ini "PT Maju" memulangkan
                    // tempat di negara lain yang nggak mungkin jadi pelanggan
                    // lab, dan hasil yang benar ketimbun.
                    'regionCode' => 'ID',
                    'languageCode' => 'id',
                    'maxResultCount' => self::MAKS_HASIL,
                ]);
        } catch (ConnectionException $e) {
            throw new DirektoriGagal('Direktori perusahaan tidak bisa dihubungi.', 0, $e);
        }

        if ($respons->failed()) {
            // Isi pesan penyedianya SENGAJA nggak diteruskan ke klien: dia bisa
            // memuat potongan key atau id proyek. Yang dibutuhkan layar teknisi
            // cuma "lagi nggak bisa, pakai ketik tangan".
            throw new DirektoriGagal('Direktori perusahaan menolak permintaan ('.$respons->status().').');
        }

        try {
            /** @var array<int, array<string, mixed>> $tempat */
            $tempat = $respons->json('places') ?? [];
        } catch (Throwable $e) {
            throw new DirektoriGagal('Jawaban direktori perusahaan tidak bisa dibaca.', 0, $e);
        }

        $hasil = [];

        foreach ($tempat as $satu) {
            $ref = $satu['id'] ?? null;
            $nama = $satu['displayName']['text'] ?? null;

            // Baris tanpa id atau tanpa nama dilewat, bukan diisi tanda tanya.
            // Yang dipilih teknisi dari daftar ini mendarat di blok OWNER
            // sertifikat — baris setengah jadi di situ lebih buruk daripada
            // baris yang nggak ada.
            if (! is_string($ref) || $ref === '' || ! is_string($nama) || trim($nama) === '') {
                continue;
            }

            $alamat = $satu['formattedAddress'] ?? null;

            $hasil[] = new PerusahaanDitemukan(
                ref: $ref,
                nama: trim($nama),
                alamat: is_string($alamat) && trim($alamat) !== '' ? trim($alamat) : null,
            );
        }

        return $hasil;
    }
}

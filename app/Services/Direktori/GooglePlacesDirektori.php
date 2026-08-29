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
     * `X-Goog-FieldMask` menentukan golongan tagihan di Places API, jadi
     * daftarnya sependek mungkin — minta field yang nggak dipakai berarti bayar
     * lebih buat data yang langsung dibuang. **Sebelum menambah apa pun di
     * sini, cek dulu golongan tagihannya di konsol penyedia.**
     *
     * `addressComponents` ikut BUKAN buat dipajang — dia yang dipakai menyaring
     * ke Indonesia. Lihat [_diIndonesia].
     */
    private const FIELD = 'places.id,places.displayName,places.formattedAddress,places.addressComponents';

    private const MAKS_HASIL = 10;

    /** Kode negara CLDR yang diterima. Lihat [diIndonesia]. */
    private const KODE_NEGARA = 'ID';

    /** Batas bawah & atas waktu tunggu, dalam detik. Lihat konstruktor. */
    private const TIMEOUT_MIN = 1;

    private const TIMEOUT_MAKS = 30;

    private readonly int $timeoutDetik;

    public function __construct(private readonly ?string $key, int $timeoutDetik = 8)
    {
        // Dijepit, bukan dipakai apa adanya.
        //
        // `timeout(0)` di Guzzle artinya **TANPA batas waktu**, bukan "cepat".
        // Dan nol itu justru yang keluar dari setelan yang salah: `.env` yang
        // isinya kosong, `abc`, atau memang `0` semuanya turun jadi 0 lewat
        // cast `(int)`. Akibatnya request teknisi menggantung tanpa ujung —
        // dia nggak pernah sampai ke pesan gagal, dan nggak pernah sampai ke
        // jalur ketik tangan yang selalu jalan.
        //
        // Batas atasnya juga dijepit: teknisi berdiri di lapangan dengan HP di
        // tangan, dan menunggu semenit buat satu pencarian sama nggak
        // bergunanya dengan gagal.
        $this->timeoutDetik = max(self::TIMEOUT_MIN, min($timeoutDetik, self::TIMEOUT_MAKS));
    }

    /** {@inheritDoc} */
    public function tersedia(): bool
    {
        return $this->key !== null && $this->key !== '';
    }

    /** {@inheritDoc} */
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
                    // Dicondongkan ke Indonesia — dan `regionCode` memang cuma
                    // MENCONDONGKAN, bukan menyaring. Tempat di negara lain
                    // masih bisa nongol, dan itu diterima apa adanya: menyaring
                    // dari teks alamat gampang membuang hasil yang sah (alamat
                    // yang nggak menyebut negara, ejaan yang beda), dan yang
                    // dibuang justru pabrik yang alamatnya paling berantakan.
                    //
                    // Yang menjaganya di ujung: alamatnya IKUT dipajang di layar
                    // pemilihan, jadi tempat di Johor kelihatan salah sebelum
                    // diketuk. Dan apa pun yang dipilih masih bisa disunting
                    // sebelum tersimpan.
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

            // Perusahaan di luar Indonesia dibuang di sini, bukan diserahkan ke
            // mata teknisi.
            //
            // `regionCode` di badan request cuma MENCONDONGKAN, nggak menyaring
            // — jadi tempat di Johor atau Singapura beneran bisa nongol buat
            // kata kunci yang mirip. Alamatnya memang ikut dipajang, tapi orang
            // yang lagi buru-buru di gerbang pabrik membaca nama dulu, dan yang
            // dia pilih mendarat di blok OWNER sertifikat.
            if (! $this->diIndonesia($satu)) {
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

    /**
     * Komponen alamat bergolongan `country` menyebut `ID`.
     *
     * Diadu ke `addressComponents` yang TERSTRUKTUR, bukan ke teks
     * `formattedAddress`: alamat yang nggak menyebut negara, ejaan yang beda,
     * dan urutan yang beda semuanya bikin pencocokan teks membuang hasil yang
     * sah — dan yang paling gampang kebuang justru pabrik yang alamatnya paling
     * berantakan, yaitu yang paling sering jadi pelanggan lab.
     *
     * Tempat TANPA komponen negara ikut dibuang. Dua kerugiannya diadu: yang
     * kebuang keliru tinggal diketik tangan (jalur itu selalu jalan), sementara
     * yang lolos keliru mendarat di blok OWNER sertifikat sebagai perusahaan
     * yang salah negara.
     *
     * @param  array<string, mixed>  $tempat
     */
    private function diIndonesia(array $tempat): bool
    {
        $komponen = $tempat['addressComponents'] ?? null;

        if (! is_array($komponen)) {
            return false;
        }

        foreach ($komponen as $satu) {
            if (! is_array($satu) || ! in_array('country', (array) ($satu['types'] ?? []), true)) {
                continue;
            }

            return ($satu['shortText'] ?? null) === self::KODE_NEGARA;
        }

        return false;
    }
}

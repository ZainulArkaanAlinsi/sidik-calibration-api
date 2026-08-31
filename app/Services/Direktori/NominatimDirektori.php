<?php

namespace App\Services\Direktori;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Direktori tempat dari OpenStreetMap, lewat Nominatim.
 *
 * ## Kenapa ini yang dipakai
 *
 * Nol tagihan dan nol API key — keputusan pemilik proyek, dan itu menghapus
 * seluruh kelas masalah yang menempel di penyedia berbayar: key yang bocor dari
 * APK, kuota yang habis di tengah kerjaan, tagihan yang membengkak karena satu
 * klien salah tulis, dan setelan yang harus diperiksa tiap kali server dibangun
 * ulang.
 *
 * Karena nggak ada key, [tersedia] selalu `true`. Keadaan "belum disetel" yang
 * dulu bisa muncul di layar teknisi berhenti ada sama sekali.
 *
 * ## Harganya, dan ini nyata
 *
 * Cakupannya lebih tipis dari penyedia peta komersial. OpenStreetMap dipetakan
 * sukarelawan, jadi pabrik di kawasan industri yang belum ada yang memetakan
 * memang **nggak akan ketemu**. Itu bukan kerusakan — jalur ketik tangan ada
 * justru buat ini, dan master lab sendiri yang lama-lama jadi sumber terbaik.
 *
 * ## Aturan pakai yang WAJIB dipatuhi
 *
 * Nominatim itu layanan sukarela dengan kebijakan pemakaian yang tegas, dan
 * melanggarnya bikin seluruh server lab diblokir — bukan diperingatkan dulu:
 *
 *  1. **User-Agent yang mengidentifikasi aplikasi.** Klien anonim ditolak.
 *  2. **Maksimal satu permintaan per detik.** Dijaga limiter bernama
 *     `direktori-luar` di `AppServiceProvider`, yang dihitung GLOBAL (bukan
 *     per-user) — sepuluh teknisi yang mencari bareng tetap satu antrean.
 *  3. **Bukan buat autocomplete.** Layar HP cuma menembak waktu tombol ditekan,
 *     dan minimal tiga huruf. Jangan pernah disambungkan ke tiap ketukan.
 *  4. **Atribusi wajib.** Datanya ODbL. Lihat [ATRIBUSI].
 */
class NominatimDirektori implements DirektoriPerusahaan
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    /**
     * Wajib ditampilkan di layar yang memajang hasilnya. Ini syarat lisensi
     * ODbL, bukan hiasan — dan dia ikut di badan respons supaya klien nggak
     * perlu mengarangnya sendiri (yang berarti tiap klien baru bisa lupa).
     */
    public const ATRIBUSI = '© OpenStreetMap contributors';

    private const MAKS_HASIL = 10;

    /** Batas bawah & atas waktu tunggu, dalam detik. Lihat konstruktor. */
    private const TIMEOUT_MIN = 1;

    private const TIMEOUT_MAKS = 30;

    private readonly int $timeoutDetik;

    public function __construct(
        private readonly string $userAgent,
        int $timeoutDetik = 8,
    ) {
        // Dijepit, sama alasannya dengan driver satunya: `timeout(0)` di Guzzle
        // artinya menunggu SELAMANYA, dan nol itu justru yang keluar dari
        // setelan `.env` yang kosong atau salah ketik.
        $this->timeoutDetik = max(self::TIMEOUT_MIN, min($timeoutDetik, self::TIMEOUT_MAKS));
    }

    /**
     * Selalu siap — nggak ada key yang harus disetel.
     *
     * Ini bedanya paling terasa buat teknisi: keadaan "belum disetel" nggak
     * pernah muncul, jadi tombol carinya nggak pernah hilang gara-gara setelan
     * server.
     */
    public function tersedia(): bool
    {
        return true;
    }

    /** {@inheritDoc} */
    public function atribusi(): ?string
    {
        return self::ATRIBUSI;
    }

    public function cari(string $kata): array
    {
        try {
            $respons = Http::withHeaders([
                // WAJIB. Nominatim menolak klien yang nggak menyebut dirinya,
                // dan yang diblokir alamat IP server-nya — bukan satu request.
                'User-Agent' => $this->userAgent,
            ])
                ->timeout($this->timeoutDetik)
                ->get(self::ENDPOINT, [
                    'q' => $kata,
                    'format' => 'jsonv2',
                    'addressdetails' => 1,
                    // Penyaring SUNGGUHAN, bukan pencondongan.
                    //
                    // Ini beda penting dari penyedia peta komersial, yang
                    // `regionCode`-nya cuma memiringkan hasil: di sini tempat di
                    // luar Indonesia nggak pernah dipulangkan sejak dari
                    // sananya, jadi nggak perlu disaring lagi di sisi kita.
                    'countrycodes' => 'id',
                    'accept-language' => 'id',
                    'limit' => self::MAKS_HASIL,
                ]);
        } catch (ConnectionException $e) {
            throw new DirektoriGagal('Direktori perusahaan tidak bisa dihubungi.', 0, $e);
        }

        if ($respons->failed()) {
            throw new DirektoriGagal('Direktori perusahaan menolak permintaan ('.$respons->status().').');
        }

        try {
            $tempat = $respons->json();
        } catch (Throwable $e) {
            throw new DirektoriGagal('Jawaban direktori perusahaan tidak bisa dibaca.', 0, $e);
        }

        if (! is_array($tempat)) {
            throw new DirektoriGagal('Jawaban direktori perusahaan tidak berbentuk daftar.');
        }

        $hasil = [];

        foreach ($tempat as $satu) {
            if (! is_array($satu)) {
                continue;
            }

            $ref = $this->ref($satu);
            $nama = $this->nama($satu);

            // Baris tanpa penanda tetap atau tanpa nama dilewat, bukan diisi
            // tanda tanya. Yang dipilih teknisi dari daftar ini mendarat di blok
            // OWNER sertifikat — baris setengah jadi di situ lebih buruk
            // daripada baris yang nggak ada.
            if ($ref === null || $nama === null) {
                continue;
            }

            $hasil[] = new PerusahaanDitemukan(
                ref: $ref,
                nama: $nama,
                alamat: $this->alamat($satu),
            );
        }

        return $hasil;
    }

    /**
     * Penanda tetap satu tempat di OSM.
     *
     * `osm_type` + `osm_id` yang dipakai, bukan `place_id`: yang terakhir itu
     * nomor internal basis data Nominatim dan **berubah tiap kali mereka
     * mengimpor ulang**. Disimpan sebagai `direktori_ref`, dia bakal berhenti
     * mencocokkan perusahaan yang sama beberapa bulan kemudian — persis
     * kegagalan diam yang bikin kolom itu ada.
     *
     * @param  array<string, mixed>  $tempat
     */
    private function ref(array $tempat): ?string
    {
        $tipe = $tempat['osm_type'] ?? null;
        $id = $tempat['osm_id'] ?? null;

        if (! is_string($tipe) || $tipe === '' || (! is_int($id) && ! is_string($id))) {
            return null;
        }

        return 'osm:'.$tipe.'/'.$id;
    }

    /**
     * Nama tempatnya.
     *
     * `name` dibaca duluan kalau ada. Kalau nggak, potongan PERTAMA dari
     * `display_name` dipakai — di Nominatim itu memang nama tempatnya, sisanya
     * jalan/kelurahan/kota. Dibaca toleran begini karena bentuk jawabannya
     * beda-beda menurut jenis objek yang kena, dan yang nggak kebaca lebih baik
     * dilewat daripada bikin seluruh pencarian gagal.
     *
     * @param  array<string, mixed>  $tempat
     */
    private function nama(array $tempat): ?string
    {
        $nama = $tempat['name'] ?? null;

        if (is_string($nama) && trim($nama) !== '') {
            return trim($nama);
        }

        $lengkap = $tempat['display_name'] ?? null;

        if (! is_string($lengkap) || trim($lengkap) === '') {
            return null;
        }

        $potongan = trim(explode(',', $lengkap)[0]);

        return $potongan === '' ? null : $potongan;
    }

    /**
     * Alamat lengkap, apa adanya dari `display_name`.
     *
     * Nama tempatnya dipotong dari depan supaya nggak kebaca dua kali di layar
     * (sudah tampil sebagai judul barisnya).
     *
     * @param  array<string, mixed>  $tempat
     */
    private function alamat(array $tempat): ?string
    {
        $lengkap = $tempat['display_name'] ?? null;

        if (! is_string($lengkap) || trim($lengkap) === '') {
            return null;
        }

        $bagian = array_map('trim', explode(',', $lengkap));
        $nama = $this->nama($tempat);

        if ($nama !== null && ($bagian[0] ?? null) === $nama) {
            array_shift($bagian);
        }

        $alamat = trim(implode(', ', array_filter($bagian, fn ($b) => $b !== '')));

        return $alamat === '' ? null : $alamat;
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Versi aplikasi mobile terbaru — `GET /api/app/versi-terbaru`, tanpa auth.
 *
 * ## Kenapa tanpa auth
 *
 * Layar yang paling butuh tahu "aplikasimu ketinggalan" justru layar LOGIN.
 * Kalau endpoint ini dikunci token, teknisi yang aplikasinya terlalu lama
 * tidak akan pernah lihat pemberitahuannya — dia mentok di layar yang belum
 * bisa dapat token. Isinya sendiri bukan rahasia: nomor versi dan tautan
 * unduhan yang sama dengan yang dilihat siapa pun di halaman Releases.
 *
 * ## Kenapa menumpang GitHub Releases, bukan tabel sendiri
 *
 * Yang menerbitkan APK itu workflow di repo mobile, dan dia sudah membuat
 * GitHub Release tiap kali berhasil. Kalau versinya juga dicatat di tabel
 * sini, ada DUA sumber kebenaran yang harus dijaga tetap sama — dan yang
 * kedua pasti tertinggal suatu hari, diam-diam, dengan akibat yang persis
 * kebalikan dari gunanya endpoint ini: teknisi disuruh update ke versi yang
 * tidak ada, atau tidak disuruh update padahal ada.
 *
 * Menumpang berarti rilis baru langsung kebaca **tanpa deploy API**. Itu
 * penting: rilis mobile jauh lebih sering daripada rilis backend.
 *
 * ## Kenapa di-cache
 *
 * GitHub membatasi 60 permintaan per jam per IP buat pemanggil tanpa token,
 * dan seluruh trafik API ini keluar dari SATU IP Render. Tanpa cache, 60
 * teknisi yang membuka aplikasi berbarengan sudah menghabiskan jatah satu jam
 * — dan sisanya dapat 403 dari GitHub, bukan dari kita.
 *
 * Cache-nya 15 menit. Rilis baru telat kebaca paling lama segitu, dan itu
 * jauh lebih murah daripada kehabisan jatah.
 */
class VersiAplikasiController extends Controller
{
    /** Repo yang menerbitkan APK-nya. */
    public const REPO = 'ZainulArkaanAlinsi/sidik-calibration-mobile';

    public const KUNCI_CACHE = 'versi-aplikasi-terbaru';

    /** Detik. Lihat docblock kelas. */
    public const UMUR_CACHE = 900;

    /**
     * Detik. Sengaja pendek: endpoint ini dipanggil saat aplikasi dibuka, dan
     * GitHub yang lambat tidak boleh bikin layar login ikut menggantung.
     */
    public const BATAS_WAKTU = 5;

    public function __invoke(): JsonResponse
    {
        $rilis = Cache::remember(
            self::KUNCI_CACHE,
            self::UMUR_CACHE,
            fn (): ?array => $this->ambilDariGithub(),
        );

        if ($rilis === null) {
            // Gagal tanya GitHub bukan alasan buat menggagalkan pembukaan
            // aplikasi. 200 dengan `tersedia: false` supaya mobile bisa
            // membedakan "tidak ada rilis" dari "servernya error" tanpa perlu
            // menangani status HTTP tambahan.
            //
            // Kegagalan TIDAK ikut ke-cache, dan itu bukan karena dibersihkan
            // di sini melainkan karena `Cache::remember` membaca lewat `get()`:
            // nilai null selalu dibaca sebagai "belum ada", jadi panggilan
            // berikutnya menanya GitHub lagi. Kalau tidak begitu, satu kali
            // GitHub ngadat bikin 15 menit berikutnya ikut buta walau GitHub
            // sudah pulih — dan itu dijaga `test_kegagalan_tidak_ikut_di_cache`.
            return response()->json([
                'tersedia' => false,
                'alasan' => 'Belum bisa mengecek versi terbaru sekarang.',
            ]);
        }

        return response()->json(['tersedia' => true] + $rilis);
    }

    /**
     * @return array<string, mixed>|null null = gagal / belum ada rilis.
     */
    private function ambilDariGithub(): ?array
    {
        try {
            $permintaan = Http::timeout(self::BATAS_WAKTU)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ]);

            // Token opsional. Tanpa token, API GitHub dibatasi 60 panggilan per
            // jam per ALAMAT IP — dan IP keluar Render paket gratis dipakai
            // bersama banyak layanan lain.
            $token = config('services.github.token');
            if (filled($token)) {
                $permintaan = $permintaan->withToken((string) $token);
            }

            $respons = $permintaan->get(sprintf('https://api.github.com/repos/%s/releases/latest', self::REPO));
        } catch (\Throwable $e) {
            Log::warning('Gagal menghubungi GitHub buat versi aplikasi.', [
                'pesan' => $e->getMessage(),
            ]);

            return $this->ambilLewatRedirect();
        }

        // 403/429 = jatah API habis, bukan "tidak ada rilis". 15 Sep 2026
        // endpoint ini menjawab `tersedia: false` dari Render sementara API yang
        // sama dari mesin lain menjawab v1.0.566 — dan selama itu tidak satu HP
        // pun ditawari pemutakhiran. Jalur redirect tidak memakai jatah API.
        if (in_array($respons->status(), [403, 429], true)) {
            Log::warning('Jatah API GitHub habis, pakai redirect releases/latest.', [
                'status' => $respons->status(),
            ]);

            return $this->ambilLewatRedirect();
        }

        if (! $respons->successful()) {
            // 404 di sini normal: repo yang belum punya rilis sama sekali
            // menjawab 404, bukan daftar kosong.
            Log::warning('GitHub menolak permintaan versi aplikasi.', [
                'status' => $respons->status(),
            ]);

            return null;
        }

        $data = $respons->json();
        if (! is_array($data)) {
            return null;
        }

        $apk = $this->cariApk($data['assets'] ?? []);
        if ($apk === null) {
            // Rilis tanpa APK terlampir tidak berguna buat pemutakhiran — dan
            // mengirimkannya tetap bikin mobile menampilkan tombol unduh yang
            // menuju ke mana-mana.
            Log::warning('Rilis terbaru tidak memuat berkas APK.', [
                'tag' => $data['tag_name'] ?? null,
            ]);

            return null;
        }

        $tag = (string) ($data['tag_name'] ?? '');

        return [
            // Tag-nya berbentuk `v1.4.0+57`; yang dipakai mobile buat
            // membandingkan cuma bagian versinya.
            'versi' => $this->versiDariTag($tag),
            'build' => $this->buildDariTag($tag),
            'tag' => $tag,
            'url_unduh' => (string) $apk['browser_download_url'],
            'ukuran' => (int) ($apk['size'] ?? 0),
            'catatan' => (string) ($data['body'] ?? ''),
            'terbit_pada' => $data['published_at'] ?? null,

            // Belum dipakai — disediakan supaya mobile sudah membacanya sejak
            // sekarang. Begitu ada rilis yang memang WAJIB (mis. bentuk
            // payload berubah dan versi lama mengirim data yang salah),
            // nilainya tinggal dinaikkan dari sisi rilis tanpa menunggu
            // aplikasi lama diperbarui lebih dulu.
            'wajib' => false,
        ];
    }

    /**
     * Versi terbaru dari redirect `github.com/<repo>/releases/latest`, tanpa API.
     *
     * Halaman web GitHub tidak dihitung ke jatah API, dan redirect-nya menyebut
     * tag rilis terbaru (`…/releases/tag/v1.0.566+566`). URL APK disusun dari
     * nama berkas yang dipatok `apk-rilis-cloud.yml` (`sidik-kalibrasi-<versi>.apk`).
     * Ukuran & catatan rilis memang tidak ada di jalur ini — mobile hanya
     * memakainya untuk tampilan.
     *
     * @return array<string, mixed>|null
     */
    private function ambilLewatRedirect(): ?array
    {
        try {
            $respons = Http::timeout(self::BATAS_WAKTU)
                ->withoutRedirecting()
                ->get(sprintf('https://github.com/%s/releases/latest', self::REPO));
        } catch (\Throwable $e) {
            Log::warning('Redirect releases/latest juga gagal.', ['pesan' => $e->getMessage()]);

            return null;
        }

        $lokasi = (string) $respons->header('Location');

        if (! $respons->redirect()
            || preg_match('#/releases/tag/([^/?\#]+)$#', $lokasi, $cocok) !== 1) {
            // Repo tanpa rilis diarahkan ke `/releases`, bukan ke sebuah tag.
            return null;
        }

        $tag = rawurldecode($cocok[1]);
        $versi = $this->versiDariTag($tag);

        return [
            'versi' => $versi,
            'build' => $this->buildDariTag($tag),
            'tag' => $tag,
            'url_unduh' => sprintf(
                'https://github.com/%s/releases/download/%s/sidik-kalibrasi-%s.apk',
                self::REPO,
                $tag,
                $versi,
            ),
            'ukuran' => 0,
            'catatan' => '',
            'terbit_pada' => null,
            'wajib' => false,
        ];
    }

    /**
     * @param  array<int, mixed>  $assets
     * @return array<string, mixed>|null
     */
    private function cariApk(array $assets): ?array
    {
        foreach ($assets as $aset) {
            if (! is_array($aset)) {
                continue;
            }

            $nama = (string) ($aset['name'] ?? '');
            if (str_ends_with(strtolower($nama), '.apk')
                && isset($aset['browser_download_url'])) {
                return $aset;
            }
        }

        return null;
    }

    /** `v1.4.0+57` → `1.4.0`. Tag yang bentuknya lain dipulangkan apa adanya. */
    private function versiDariTag(string $tag): string
    {
        $tanpaV = ltrim($tag, 'vV');
        $plus = strpos($tanpaV, '+');

        return $plus === false ? $tanpaV : substr($tanpaV, 0, $plus);
    }

    /** `v1.4.0+57` → `57`. Null kalau tag-nya tidak memuat nomor build. */
    private function buildDariTag(string $tag): ?int
    {
        $plus = strpos($tag, '+');
        if ($plus === false) {
            return null;
        }

        $build = substr($tag, $plus + 1);

        return is_numeric($build) ? (int) $build : null;
    }
}

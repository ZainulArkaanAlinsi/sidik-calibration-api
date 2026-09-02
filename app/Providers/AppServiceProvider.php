<?php

namespace App\Providers;

use App\Services\Direktori\DirektoriBercache;
use App\Services\Direktori\DirektoriBerlapis;
use App\Services\Direktori\DirektoriLokalDb;
use App\Services\Direktori\DirektoriPerusahaan;
use App\Services\Direktori\GooglePlacesDirektori;
use App\Services\Direktori\NominatimDirektori;
use App\Services\Direktori\PilihanDriver;
use App\Services\Push\FcmPengirimPush;
use App\Services\Push\PengirimPush;
use App\Services\Push\PengirimPushMati;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Pengirim push. BAWAANNYA yang diam — kredensial FCM cuma ada di
        // server yang beneran, sementara mesin developer, CI, dan test nggak
        // punya. Kalau bawaannya yang asli, tiap notifikasi di lingkungan itu
        // melempar, dan yang gagal bukan push-nya doang tapi seluruh aksi yang
        // memicunya: teknisi nyubmit sesi, admin menyetujui.
        //
        // Firebase di proyek ini statusnya sementara. Menggantinya nanti cukup
        // menukar satu baris ini — `via()`, tabel `device_tokens`, dan endpoint
        // pendaftarannya nggak ikut berubah.
        $this->app->bind(PengirimPush::class, function ($app): PengirimPush {
            $projectId = config('services.fcm.project_id');
            $kredensial = config('services.fcm.credentials');

            // KEDUANYA harus ada DAN berkasnya harus beneran ada. Setengah
            // disetel itu keadaan yang paling berbahaya: pengirim asli
            // terbentuk, tiap kirim gagal, dan yang kelihatan cuma "notifikasi
            // HP nggak masuk" tanpa satu error pun yang nunjuk ke sebabnya.
            if (! $projectId || ! $kredensial || ! is_file($kredensial)) {
                return new PengirimPushMati;
            }

            return new FcmPengirimPush(
                $app->make(HttpFactory::class),
                (string) $projectId,
                (string) $kredensial,
                (int) config('services.fcm.timeout', 10),
            );
        });

        // Direktori perusahaan luar. Beda dari push di atas, key yang kosong
        // TIDAK ditukar jadi implementasi diam: dia tetap kelas yang sama,
        // cuma `tersedia()`-nya false, dan controller yang mengubah itu jadi
        // kalimat "belum disetel" buat teknisi.
        //
        // Kenapa nggak dibikin `DirektoriMati` yang mulangin daftar kosong:
        // daftar kosong di layar kebaca "PT-nya nggak ada di direktori", dan
        // teknisi yang percaya itu mendaftarkan ulang perusahaan yang
        // sebenarnya ada — nambah kembar justru lewat fitur yang dipasang buat
        // menguranginya.
        $this->app->bind(DirektoriPerusahaan::class, function (): DirektoriPerusahaan {
            $timeout = (int) config('services.direktori_perusahaan.timeout', 8);

            // Tiap lapis dibungkus cache SENDIRI-SENDIRI, bukan hasil
            // berlapisnya. `DirektoriBerlapis::atribusi()` membaca lapis mana
            // yang menjawab `cari()` terakhir, jadi cache di luar bikin
            // cache-hit memulangkan atribusi `null` — layar berhenti memajang
            // "Powered by Google" tanpa satu pun error. Lihat DirektoriBercache.
            $google = fn (): DirektoriPerusahaan => new DirektoriBercache(
                new GooglePlacesDirektori(
                    config('services.direktori_perusahaan.key'),
                    $timeout,
                ),
            );

            $osm = fn (): DirektoriPerusahaan => new DirektoriBercache(
                new NominatimDirektori(
                    (string) config('services.direktori_perusahaan.user_agent'),
                    $timeout,
                ),
            );

            // Bawaannya `osm`, dan yang NGGAK DIKENALI jatuh ke situ juga —
            // bukan melempar. Salah ketik di `.env` mematikan pendaftaran
            // pelanggan di lapangan, dan itu hukuman yang jauh lebih besar
            // daripada kesalahannya.
            //
            // Kenapa jatuhnya ke OSM dan bukan ke berlapis seperti dulu: lapis
            // berlapis ikut membangun `GooglePlacesDirektori`, jadi satu huruf
            // yang meleset (`osmm`) diam-diam menyalakan lagi jalur yang
            // DITAGIH — persis yang disuruh berhenti. OSM memenuhi dua
            // syaratnya sekaligus: hidup tanpa key, dan nol tagihan.
            //
            // `auto` tetap ada, tapi sekarang harus DISEBUT. Yang didapat:
            // Google duluan — cakupan pabrik Indonesia jauh lebih tebal, dan
            // Text Search punya kuota bebas 5.000 panggilan/bulan — dengan OSM
            // di belakangnya supaya jalurnya nggak pernah mati total. Bayaran
            // yang ditukar: kalau kuota bulanannya lewat, tagihannya jalan.
            // Keputusan sebesar itu layak diketik sendiri, bukan diwarisi dari
            // pemasangan yang lupa menyetel apa-apa.
            // Terjemahan setelan -> driver dikerjakan [PilihanDriver], bukan
            // `match` di sini, karena `GET /api/health` MELAPORKAN hasil yang
            // sama. Dua salinan aturan yang sama cepat atau lambat berbeda,
            // dan yang terbit bukan sekadar laporan yang salah tapi laporan
            // yang dipercaya — health bilang "osm" sementara yang dibangun
            // jalur berbayar.
            $luar = match (PilihanDriver::sekarang()) {
                'google' => $google(),
                'auto' => new DirektoriBerlapis([$google(), $osm()]),
                default => $osm(),
            };

            // Direktori lokal SELALU jadi lapis pertama, apa pun setelan
            // drivernya — dan itu keputusan, bukan kelalaian.
            //
            // Kalau dia cuma nyala lewat nilai driver sendiri (`lokal`),
            // pemasangan yang tidak mengubah apa-apa TIDAK akan memakai data
            // yang sudah susah payah diimpor — padahal itu satu-satunya alasan
            // datanya ada. Bawaan yang mengabaikan isi database sendiri sama
            // buruknya dengan bawaan yang menagih diam-diam.
            //
            // Aman ditaruh di depan karena tiga hal:
            //  1. Nol jaringan, nol kuota, nol tagihan — tidak ada yang bisa
            //     dibuat lebih mahal olehnya.
            //  2. Nol hasil BUKAN jawaban akhir buat `DirektoriBerlapis` (butir
            //     2 aturannya), jadi PT yang tidak ada di sini tetap dicari ke
            //     luar. Cakupan tidak berkurang sedikit pun.
            //  3. Tabel kosong bikin `tersedia()` false, jadi pemasangan yang
            //     belum mengimpor apa-apa berperilaku SAMA PERSIS seperti
            //     sebelum fitur ini ada.
            //
            // Yang ikut didapat: pencarian yang ketemu di sini tidak pernah
            // sampai ke Google, jadi lapis ini justru MENGURANGI request
            // berbayar buat lab yang memilih `auto`.
            //
            // `PilihanDriver::sekarang()` sengaja tetap melaporkan driver LUAR
            // saja, karena yang dijawabnya pertanyaan "lab ini sedang ditagih
            // atau nggak". Keberadaan lapis lokal dilaporkan terpisah di
            // `GET /api/health` — lihat routes/api.php.
            $lokal = new DirektoriLokalDb;

            return $lokal->tersedia()
                ? new DirektoriBerlapis([$lokal, $luar])
                : $luar;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->rateLimiters();

        // Bawaan Laravel nunjuk ke route web `password.reset` yang nggak ada di
        // project API-only ini. Diganti deep link ke app Flutter — mobile daftarin
        // scheme `sidik://` biar link di email langsung buka layar reset.
        // Waktu dev MAIL_MAILER=log, jadi linknya nongol di storage/logs/laravel.log.
        ResetPassword::createUrlUsing(fn (object $notifiable, string $token) => sprintf(
            '%s?token=%s&email=%s',
            config('app.reset_password_url'),
            $token,
            urlencode($notifiable->getEmailForPasswordReset()),
        ));
    }

    /**
     * Jatah rate limit DIPISAH per endpoint.
     *
     * Kalau cuma pakai `throttle:5,1` bawaan, semua endpoint publik bakal berbagi
     * satu jatah: buat request tanpa login, Laravel bikin kuncinya dari
     * sha1(domain|ip) — nama route-nya nggak ikut. Akibatnya orang yang salah
     * password beberapa kali langsung kena 429 waktu mau minta reset password,
     * padahal dia belum pernah manggil endpoint itu sama sekali.
     *
     * Dengan limiter bernama, tiap endpoint punya ember sendiri.
     */
    private function rateLimiters(): void
    {
        $perMenit = fn (string $nama, int $jumlah) => RateLimiter::for(
            $nama,
            fn (Request $request) => Limit::perMinute($jumlah)
                ->by($nama.'|'.$request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Kebanyakan percobaan. Tunggu sebentar, terus coba lagi.',
                ], 429)),
        );

        $perMenit('login', 10);
        $perMenit('register', 5);
        $perMenit('password-reset', 5);

        // Direktori perusahaan luar — dihitung GLOBAL, bukan per-IP.
        //
        // Ini satu-satunya limiter di berkas ini yang begitu, dan sengaja.
        // Yang dijaga bukan pemakai kita, tapi KEWAJIBAN KITA ke penyedianya:
        // Nominatim itu layanan sukarela yang menuntut maksimal satu permintaan
        // per detik dari satu pemakai, dan yang dia hitung server ini —
        // bukan teknisi yang menekan tombolnya.
        //
        // Dibatasi per-IP seperti yang lain, sepuluh teknisi yang mencari
        // bareng jadi sepuluh permintaan sedetik, dan yang diblokir alamat IP
        // server lab — semua orang sekaligus, tanpa peringatan.
        //
        // 30/menit = satu per dua detik, separuh dari batasnya. Sisanya buat
        // gelombang pendek yang nggak terhindarkan.
        RateLimiter::for('direktori-luar', fn () => Limit::perMinute(30)
            ->by('direktori-luar')
            ->response(fn () => response()->json([
                'message' => 'Pencarian direktori lagi ramai. Tunggu sebentar, '
                    .'atau ketik nama & alamatnya manual.',
            ], 429)));
    }
}

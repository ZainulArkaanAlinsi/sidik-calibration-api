<?php

namespace App\Http\Controllers\Pelanggan;

use App\Exceptions\Pelanggan\AksiPelangganDitolak;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pelanggan\AturUlangSandiRequest;
use App\Http\Requests\Pelanggan\EmailSajaRequest;
use App\Http\Requests\Pelanggan\MasukRequest;
use App\Http\Requests\Pelanggan\TerimaUndanganRequest;
use App\Http\Resources\Pelanggan\AkunResource;
use App\Mail\Pelanggan\KodeOtpEmail;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\OtpPelanggan;
use App\Models\PersetujuanDokumen;
use App\Models\UndanganPelanggan;
use App\Models\User;
use App\Notifications\Pelanggan\UndanganDitukarDiAkunAnda;
use App\Services\Pelanggan\Keanggotaan;
use App\Services\Pelanggan\KodeOtp;
use App\Services\Pelanggan\KodeUndangan;
use App\Services\Pelanggan\TokenPelanggan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Pintu masuk aplikasi pelanggan — daftar, OTP, masuk, keluar, lupa sandi.
 *
 * ## Dua hal yang menentukan bentuk seluruh kelas ini
 *
 * **1. Balasan tidak boleh menjawab "email ini terdaftar atau tidak".** Siapa
 * pun bisa menembak endpoint ini dengan curl. Kalau `daftar` bilang "sudah
 * dipakai", `masuk` bilang "email tidak ditemukan", dan `lupa-sandi` bilang
 * "email tidak terdaftar", maka daftar pelanggan PT Sidik bisa disusun orang
 * luar dari luar — dan siapa saja pelanggan lab itu sendiri informasi bisnis.
 * Karena itu `lupa-sandi` SELALU 200, dan `masuk` memakai satu pesan buat
 * "tidak ada akun" dan "sandi salah".
 *
 * **2. Tiap error non-422 punya `kode` yang stabil** (03-SDD §7). Aplikasi yang
 * sudah terpasang di HP orang tidak bisa disuruh ikut berubah hari itu juga,
 * jadi dia bercabang pada `kode`, bukan pada isi `message`. Memperbaiki kalimat
 * Indonesia di sini tidak boleh memecahkan aplikasi yang sudah beredar.
 */
class AuthPelangganController extends Controller
{
    /** REQ-AUTH-10: gagal 5 kali dalam 15 menit → email itu dikunci 15 menit. */
    private const MAKS_GAGAL_MASUK = 5;

    private const KUNCI_MASUK_DETIK = 15 * 60;

    public function __construct(
        private readonly KodeOtp $otp,
        private readonly TokenPelanggan $token,
    ) {}

    /*
     * TIDAK ADA `daftar()`, `verifikasiEmail()`, `kirimUlangOtp()`.
     *
     * Ketiganya melayani pendaftaran mandiri, dan pendaftaran mandiri dicabut:
     * akun pelanggan sekarang HANYA lahir dari undangan, lewat
     * [terimaUndangan()] di bawah. Alasannya di AGENTS.md §Akun Lahir dari
     * Undangan; ringkasnya, yang menjamin seseorang berhak atas data PT X
     * bukan klaim yang dia ketik sendiri di layar daftar, melainkan bahwa
     * orang yang SUDAH berwenang mengirim kode ke alamat emailnya.
     *
     * Yang ikut hilang bersamanya: antrean pengajuan yang bisa dibanjiri siapa
     * pun dari internet, dan kemungkinan admin yang sedang buru-buru
     * menyetujui pemohon yang mengaku dari PT X.
     *
     * Yang SENGAJA ditinggal berdiri: tabel `pengajuan_akun_pelanggan` beserta
     * layar peninjauannya. Barisnya adalah bukti persetujuan syarat & kebijakan
     * privasi — yang justru baru sesi lalu dikunci `restrictOnDelete` supaya
     * tidak bisa terhapus (UU PDP). Mencabut tabelnya demi kerapian berarti
     * membuang bukti yang wajib disimpan.
     */

    /**
     * REQ-AUTH-06 — tukar kode undangan jadi akun yang LANGSUNG aktif.
     *
     * Tidak lewat antrean verifikasi sama sekali, dan itu aman justru karena
     * undangannya: yang menjamin orangnya berhak bukan klaim yang dia ketik,
     * melainkan bahwa seseorang yang sudah berwenang (PIC utama perusahaan itu,
     * atau admin lab) mengirim kode ke alamat emailnya. Itu bukti yang lebih
     * kuat daripada apa pun yang bisa diketik sendiri di layar daftar.
     *
     * Email TIDAK diverifikasi OTP di jalur ini, dan itu disengaja: kodenya
     * sendiri sudah membuktikan orangnya membaca email di alamat itu.
     *
     * ## Email yang SUDAH punya akun: kode saja TIDAK cukup (12 Nov 2026)
     *
     * Kode membuktikan orangnya membaca email undangan — bukan bahwa dia
     * pemilik AKUN yang sudah ada. Admin lab melihat kode polos undangannya
     * (`POST /api/customers/{c}/undangan` membalas `data.kode`), jadi dulu
     * admin lab bisa mengundang email pelanggan mana pun lalu menukar kodenya
     * dan mendapat token akun itu tanpa sandi. Sekarang akun lama wajib
     * membuktikan sandinya (`periksaAkunLama()`), dengan penguncian yang
     * SATU ember dengan `masuk()`, penolakan organisasi lain, dan penolakan
     * status yang sama dengan `masuk()`. Semua itu baru terlihat SESUDAH kode
     * terbukti sah: pihak tanpa kode tetap hanya menerima
     * `undangan_tidak_berlaku`, apa pun status akunnya.
     *
     * Akun BARU tidak tersentuh sama sekali oleh aturan itu.
     */
    public function terimaUndangan(TerimaUndanganRequest $request, KodeUndangan $kodeUndangan, Keanggotaan $keanggotaan): JsonResponse
    {
        $data = $request->validated();
        $undangan = $kodeUndangan->tukar($data['email'], $data['kode']);

        // Satu balasan buat "kode salah", "sudah dipakai", dan "kedaluwarsa".
        // Membedakannya bikin endpoint ini bisa dipakai memeriksa undangan mana
        // yang pernah ada buat sebuah email.
        if ($undangan === null || $undangan->customer === null) {
            return $this->tolakUndanganTidakBerlaku();
        }

        $hasil = DB::transaction(function () use ($data, $undangan, $keanggotaan, $request) {
            $perusahaan = $undangan->customer;

            // Orang yang emailnya SUDAH punya akun tidak dibuatkan akun kedua —
            // dia ditambahkan sebagai anggota perusahaan ini. Konsultan yang
            // memegang tiga pabrik itu satu orang, bukan tiga akun.
            $user = User::query()->where('email', $data['email'])->first();

            // Dibaca SEBELUM akun dibuat: sesudah `create()` pertanyaannya tidak
            // bisa dijawab lagi. Dikirim hanya di balasan 201 (pemegang kode yang
            // sah) — jalur gagal tidak pernah menyentuhnya.
            $akunSudahAda = $user !== null;

            if ($user === null) {
                // Klaim atomik SEBELUM akun dibuat: yang kalah balapan tidak boleh
                // meninggalkan akun yatim (lihat `klaimUndangan()`).
                if (! $this->klaimUndangan($undangan)) {
                    return ['ditolak' => $this->tolakUndanganTidakBerlaku()];
                }

                $user = User::create([
                    'organization_id' => $perusahaan->organization_id,
                    'name' => $data['nama'],
                    'email' => $data['email'],
                    'password' => $data['sandi'],
                    'telepon' => $data['telepon'],
                    'jabatan' => $data['jabatan'] ?? null,
                    'role' => User::ROLE_PELANGGAN,
                    'status' => User::STATUS_AKTIF,
                ]);

                // `forceFill`, BUKAN ikut di `create()` di atas: `email_verified_at`
                // tidak ada di `#[Fillable]` `User`, jadi mass assignment
                // MEMBUANGNYA tanpa satu pun error — akunnya jadi aktif dengan
                // email yang tercatat belum terverifikasi. Ketahuan dari test.
                //
                // Terverifikasi sejak detik ini memang benar: kode undangannya
                // hanya sampai lewat email itu, jadi menukarnya sudah
                // membuktikan orangnya membacanya.
                $user->forceFill(['email_verified_at' => now()])->save();

                $this->catatPersetujuan($user, $request);
            } elseif ($user->role !== User::ROLE_PELANGGAN) {
                // Akun LAB tidak boleh berubah jadi akun pelanggan lewat
                // undangan. Kalau boleh, siapa pun yang bisa mengundang bisa
                // menarik akun admin ke dalam perusahaannya.
                // `undangan_akun_internal`, BUKAN `bukan_akun_pelanggan`.
                // Kode yang kedua sudah dipakai `masuk()` dengan status 403 dan
                // arti yang berbeda ("masuklah lewat aplikasi teknisi"). Satu
                // kode buat dua keadaan bikin aplikasi tidak bisa bercabang —
                // dan bercabang pada `kode` justru satu-satunya yang boleh dia
                // andalkan (NFR-12). Ketahuan waktu membaca ulang tabel kode di
                // kontrak, bukan dari test.
                throw new AksiPelangganDitolak(
                    'undangan_akun_internal',
                    'Email ini terdaftar sebagai akun internal PT Sidik dan tidak bisa menerima undangan.',
                    422,
                );
            } else {
                // Akun pelanggan yang SUDAH ada: kode saja tidak cukup, pemegangnya
                // harus membuktikan sandi akun itu (lihat docblock method ini).
                //
                // Penolakan DIKEMBALIKAN, bukan dilempar: lemparan membatalkan
                // transaksi ini dan ikut membuang baris audit "ditolak" yang
                // baru ditulis `periksaAkunLama()`. Penghitung percobaannya
                // hidup di cache dan selamat dari rollback, jadi auditnya pun
                // harus selamat — kalau tidak, kunci menyala tanpa jejak.
                $penolakan = $this->periksaAkunLama($user, $undangan, $perusahaan, $data['sandi'], $request);

                if ($penolakan !== null) {
                    return ['ditolak' => $penolakan];
                }

                // SESUDAH sandi terbukti: penolakan sandi tidak boleh menghanguskan
                // kode (pemiliknya masih boleh mencoba lagi).
                if (! $this->klaimUndangan($undangan)) {
                    return ['ditolak' => $this->tolakUndanganTidakBerlaku()];
                }
            }

            $perusahaanAktifSebelum = $akunSudahAda ? $this->jumlahPerusahaanAktif($user) : 0;

            $anggota = $keanggotaan->daftarkan($perusahaan, $user, $undangan->peran, $undangan->pembuat);

            $undangan->forceFill([
                'dipakai_pada' => now(),
                'dipakai_oleh' => $user->getKey(),
            ])->save();

            if ($akunSudahAda) {
                $this->catatPenukaranAkunLama($user, $undangan, $anggota, $perusahaanAktifSebelum, $request);
            }

            return ['user' => $user->refresh(), 'anggota' => $anggota, 'akun_sudah_ada' => $akunSudahAda];
        });

        if (isset($hasil['ditolak'])) {
            return $hasil['ditolak'];
        }

        // Sesudah commit, di luar transaksi: kabar ini pelengkap dan tidak boleh
        // membatalkan penukaran yang sudah sah.
        if ($hasil['akun_sudah_ada']) {
            $this->kabariPemilikAkun($hasil['user']);
        }

        return response()->json([
            'data' => [
                ...$this->bungkusToken($hasil['user'], $data['nama_perangkat'] ?? ''),
                // Additive (12 Nov 2026), HANYA di balasan ini — `masuk` memakai
                // `bungkusToken()` yang sama tapi tidak membawanya. `true` =
                // email ini sudah punya akun pelanggan sebelum undangan
                // ditukar (nama/sandi di formulir diabaikan); `false` = akun
                // baru. Klien lama mengabaikan kunci yang tidak dikenalnya.
                'akun_sudah_ada' => $hasil['akun_sudah_ada'],
            ],
        ], 201);
    }

    /** REQ-AUTH-03/07/08/10 — masuk dari aplikasi pelanggan. */
    public function masuk(MasukRequest $request): JsonResponse
    {
        $data = $request->validated();
        $kunci = $this->kunciGagalMasuk($data['email']);

        // Penguncian per EMAIL, bukan per IP, dan bukan lewat middleware
        // `throttle:`. Dua sebab: middleware menghitung SEMUA permintaan
        // (termasuk yang berhasil), sementara REQ-AUTH-10 menghitung yang
        // gagal; dan kunci per IP dilewati cukup dengan ganti jaringan,
        // sementara yang ditebak orang itu satu akun tertentu.
        if (RateLimiter::tooManyAttempts($kunci, self::MAKS_GAGAL_MASUK)) {
            return $this->tolak(
                'terlalu_sering',
                'Terlalu banyak percobaan masuk. Coba lagi dalam '.ceil(RateLimiter::availableIn($kunci) / 60).' menit.',
                429,
            );
        }

        $user = User::query()->where('email', $data['email'])->first();

        if ($user === null || ! Hash::check($data['sandi'], (string) $user->password)) {
            RateLimiter::hit($kunci, self::KUNCI_MASUK_DETIK);

            return $this->tolak('kredensial_salah', 'Email atau sandi salah.', 401);
        }

        // REQ-AUTH-07 arah sebaliknya: akun lab ditolak di pintu pelanggan.
        // Diperiksa SESUDAH sandinya cocok, supaya balasannya tidak bisa
        // dipakai membedakan "akun lab" dari "email tidak terdaftar".
        if ($user->role !== User::ROLE_PELANGGAN) {
            RateLimiter::clear($kunci);

            return $this->tolak(
                'bukan_akun_pelanggan',
                'Akun ini akun internal PT Sidik. Silakan masuk lewat aplikasi teknisi.',
                403,
            );
        }

        RateLimiter::clear($kunci);

        $alasanTertutup = $this->alasanAkunTertutup($user);

        if ($alasanTertutup !== null) {
            return $this->balasanAkunTertutup($alasanTertutup);
        }

        return response()->json(['data' => $this->bungkusToken($user, $data['nama_perangkat'] ?? '')]);
    }

    /** `POST /auth/keluar` — cabut token yang sedang dipakai saja. */
    public function keluar(Request $request): JsonResponse
    {
        $token = $request->user()?->currentAccessToken();

        // `TransientToken` (sesi web) tidak punya `delete()`. Di jalur
        // pelanggan itu mustahil, dan pemeriksaannya tetap ada supaya endpoint
        // ini tidak pernah jadi 500 kalau suatu hari dipanggil dari tempat lain.
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => 'Anda sudah keluar dari perangkat ini.']);
    }

    /** `POST /auth/keluar-semua` — REQ-AUTH-09. */
    public function keluarSemua(Request $request): JsonResponse
    {
        $user = $request->user();
        $jumlah = $user->tokens()->count();
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Anda sudah keluar dari semua perangkat.',
            'data' => ['sesi_dicabut' => $jumlah],
        ]);
    }

    /** `POST /auth/lupa-sandi` — selalu 200, lihat docblock kelas. */
    public function lupaSandi(EmailSajaRequest $request): JsonResponse
    {
        $user = $this->cariPelanggan($request->validated()['email']);

        if ($user !== null && $user->status !== User::STATUS_PENDING_EMAIL && $user->dianonimkan_pada === null) {
            $kode = $this->otp->terbitkan($user, OtpPelanggan::TUJUAN_ATUR_ULANG_SANDI);

            if ($kode !== null) {
                $this->kirimOtp($user, $kode, OtpPelanggan::TUJUAN_ATUR_ULANG_SANDI);
            }
        }

        return response()->json([
            'message' => 'Kalau email itu terdaftar, kode untuk mengatur ulang sandi sudah dikirim.',
            'data' => ['otp_berlaku_menit' => OtpPelanggan::BERLAKU_MENIT],
        ]);
    }

    /**
     * `POST /auth/atur-ulang-sandi` — sandi baru + cabut SEMUA sesi.
     *
     * Pencabutan totalnya wajib (REQ-AUTH-09): orang yang mengatur ulang sandi
     * hampir selalu melakukannya karena curiga akunnya dipegang orang lain, dan
     * sandi baru yang tidak mengusir sesi lama tidak menyelesaikan apa pun.
     */
    public function aturUlangSandi(AturUlangSandiRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $this->cariPelanggan($data['email']);

        if ($user === null) {
            return $this->tolakOtp(KodeOtp::TIDAK_ADA);
        }

        $hasil = $this->otp->periksa($user, OtpPelanggan::TUJUAN_ATUR_ULANG_SANDI, $data['otp']);

        if ($hasil !== KodeOtp::COCOK) {
            return $this->tolakOtp($hasil);
        }

        DB::transaction(function () use ($user, $data): void {
            $user->forceFill(['password' => Hash::make($data['sandi'])])->save();
            $user->tokens()->delete();
        });

        RateLimiter::clear($this->kunciGagalMasuk((string) $user->email));

        return response()->json(['message' => 'Sandi berhasil diganti. Silakan masuk dengan sandi baru.']);
    }

    // ---------------------------------------------------------------- privat

    /**
     * Akun PELANGGAN dengan email ini — akun lab tidak pernah ikut terjaring.
     *
     * Kalau tidak disaring role, `lupa-sandi` di pintu pelanggan bisa dipakai
     * mengirim OTP atur-ulang-sandi ke akun admin lab.
     */
    private function cariPelanggan(string $email): ?User
    {
        return User::query()
            ->where('email', mb_strtolower($email))
            ->where('role', User::ROLE_PELANGGAN)
            ->first();
    }

    /**
     * Ember penghitung kegagalan sandi per EMAIL — SATU ember untuk `masuk()`
     * dan penukaran undangan di akun lama.
     *
     * Dua ember terpisah memberi penebak sandi dua kali jatah (lima di
     * `masuk`, lima lagi di `terima-undangan`) untuk akun yang sama. Satu
     * ember memastikan jatah menebak satu akun tetap lima per 15 menit
     * apa pun pintunya.
     */
    private function kunciGagalMasuk(string $email): string
    {
        return 'pelanggan-masuk-gagal|'.sha1(mb_strtolower($email));
    }

    /**
     * Status akun yang menutup pintu token pelanggan — SATU tempat, dipakai
     * `masuk()` dan penukaran undangan di akun lama, supaya dua pintu itu tidak
     * bisa berbeda diam-diam soal akun mana yang boleh dapat token. Hasilnya
     * `kode` galat (juga dipakai sebagai alasan di audit), atau `null` bila
     * akunnya boleh.
     */
    private function alasanAkunTertutup(User $user): ?string
    {
        if ($user->status === User::STATUS_PENDING_EMAIL) {
            return 'email_belum_diverifikasi';
        }

        if ($user->status === User::STATUS_NONAKTIF || $user->dianonimkan_pada !== null) {
            return 'akun_nonaktif';
        }

        return null;
    }

    /**
     * Balasan untuk [alasanAkunTertutup()]. Kode dan status HTTP-nya sama di
     * dua pintu; hanya kalimat `email_belum_diverifikasi` yang beda, karena di
     * jalur undangan tidak ada kode 6 digit untuk diminta (`kirim-ulang-otp`
     * sudah dicabut) — satu-satunya jalan keluarnya menghubungi PT Sidik.
     */
    private function balasanAkunTertutup(string $alasan, bool $lewatUndangan = false): JsonResponse
    {
        return match ($alasan) {
            'email_belum_diverifikasi' => $this->tolak(
                'email_belum_diverifikasi',
                $lewatUndangan
                    ? 'Email akun ini belum diverifikasi, jadi undangan belum bisa ditukar. Hubungi PT Sidik.'
                    : 'Email Anda belum diverifikasi. Minta kode baru lalu masukkan 6 digitnya.',
                403,
            ),
            default => $this->tolak('akun_nonaktif', 'Akun ini sudah tidak aktif. Hubungi PT Sidik.', 403),
        };
    }

    /**
     * Satu jawaban buat semua kegagalan KODE undangan — termasuk kalah balapan
     * di [klaimUndangan()]. Dikunci di satu method supaya kalimatnya tidak bisa
     * berbeda antara jalur "kode salah" dan jalur "kode sudah dipakai orang lain".
     */
    private function tolakUndanganTidakBerlaku(): JsonResponse
    {
        return $this->tolak(
            'undangan_tidak_berlaku',
            'Kode undangan tidak cocok, sudah dipakai, atau sudah kedaluwarsa.',
            422,
        );
    }

    /**
     * Klaim undangan secara ATOMIK: `UPDATE … WHERE dipakai_pada IS NULL AND
     * dibatalkan_pada IS NULL`, sukses hanya bila tepat satu baris berubah.
     *
     * `KodeUndangan::tukar()` cuma MEMBACA. Tanpa klaim ini dua request yang
     * bersamaan sama-sama lolos `tukar()` dan sama-sama menukar kode yang sama
     * (dua token, dua keanggotaan, dua audit). Pola yang sama dengan
     * `PersetujuanAkun::kunciTransisi()`: pemenangnya ditentukan basis data, bukan
     * urutan baca. Di MySQL, UPDATE kedua menunggu kunci baris yang pertama lalu
     * mengevaluasi ulang syaratnya dan mendapati 0 baris.
     *
     * Dipanggil DI DALAM transaksi, setelah semua penolakan yang tidak boleh
     * menghanguskan kode (sandi salah, organisasi, status) dan sebelum
     * `daftarkan()`: kalau `daftarkan()` melempar (mis. `batas_anggota`),
     * rollback ikut membatalkan klaim ini dan kodenya hidup lagi.
     */
    private function klaimUndangan(UndanganPelanggan $undangan): bool
    {
        return UndanganPelanggan::query()
            ->whereKey($undangan->getKey())
            ->whereNull('dipakai_pada')
            ->whereNull('dibatalkan_pada')
            ->update(['dipakai_pada' => now()]) === 1;
    }

    /**
     * Pemegang kode yang SAH menukar undangan di akun pelanggan yang sudah ada.
     * `null` = lolos; selain itu balasan penolakannya.
     *
     * ## Urutan pemeriksaan, dan kenapa urutannya itu
     *
     * 1. **Kunci** (429) — ember yang sama dengan `masuk()`.
     * 2. **Sandi** (422 `sandi_akun_lama_salah`) — gagal menghitung ke ember.
     * 3. **Organisasi** (422), 4. **Status** (403) — SESUDAH sandi cocok,
     *    persis seperti `masuk()` memeriksa status sesudah sandi: kalau
     *    didahulukan, penebak sandi bisa membaca status dan laboratorium akun
     *    itu tanpa pernah menyebut sandinya.
     *
     * Semuanya hanya terlihat oleh pemegang kode yang sah. Kegagalan KODE
     * sudah dijawab `undangan_tidak_berlaku` di pemanggil, sebelum akun mana
     * pun diperiksa, dan tidak menyentuh ember maupun audit: orang tanpa kode
     * tidak boleh bisa mengunci login pemilik akun, apalagi menebak sandinya.
     *
     * Dipanggil DI DALAM transaksi pemanggil, dan penolakan dikembalikan
     * (bukan dilempar) supaya baris audit-nya ikut commit.
     */
    private function periksaAkunLama(
        User $user,
        UndanganPelanggan $undangan,
        Customer $perusahaan,
        string $sandi,
        Request $request,
    ): ?JsonResponse {
        $kunci = $this->kunciGagalMasuk((string) $user->email);

        if (RateLimiter::tooManyAttempts($kunci, self::MAKS_GAGAL_MASUK)) {
            return $this->tolak(
                'terlalu_sering',
                'Terlalu banyak percobaan sandi untuk email ini. Coba lagi dalam '.ceil(RateLimiter::availableIn($kunci) / 60).' menit.',
                429,
            );
        }

        if (! Hash::check($sandi, (string) $user->password)) {
            RateLimiter::hit($kunci, self::KUNCI_MASUK_DETIK);

            $this->catatPenolakanUndangan($user, $undangan, $request, 'sandi_salah', [
                'percobaan_ke' => RateLimiter::attempts($kunci),
            ]);

            return $this->tolak(
                'sandi_akun_lama_salah',
                'Email ini sudah punya akun SIDIK Pelanggan. Isi kolom sandi dengan sandi akun Anda yang sudah ada, bukan sandi baru.',
                422,
            );
        }

        RateLimiter::clear($kunci);

        if ((int) $user->organization_id !== (int) $perusahaan->organization_id
            || (int) $user->organization_id !== (int) $undangan->organization_id) {
            $this->catatPenolakanUndangan($user, $undangan, $request, 'organisasi_beda');

            return $this->tolak(
                'undangan_organisasi_berbeda',
                'Akun Anda terdaftar di laboratorium lain, jadi undangan ini tidak bisa ditukar di akun itu. Hubungi PT Sidik.',
                422,
            );
        }

        $alasanTertutup = $this->alasanAkunTertutup($user);

        if ($alasanTertutup !== null) {
            $this->catatPenolakanUndangan($user, $undangan, $request, $alasanTertutup);

            return $this->balasanAkunTertutup($alasanTertutup, lewatUndangan: true);
        }

        return null;
    }

    private function jumlahPerusahaanAktif(User $user): int
    {
        return CustomerMember::query()
            ->where('user_id', $user->getKey())
            ->where('status', CustomerMember::STATUS_AKTIF)
            ->count();
    }

    /**
     * Konteks undangan yang sama di semua baris audit penukaran. TANPA sandi,
     * TANPA kode, TANPA email — baris audit bisa dibaca banyak orang dan
     * diekspor ke CSV.
     *
     * @return array<string, mixed>
     */
    private function jejakUndangan(UndanganPelanggan $undangan, Request $request): array
    {
        return [
            'undangan_id' => $undangan->getKey(),
            'customer_id' => $undangan->customer_id,
            'diundang_oleh' => $undangan->dibuat_oleh,
            'ip' => $request->ip(),
        ];
    }

    /**
     * Jejak penukaran yang SAH di akun lama. `changed_by` = akun itu sendiri:
     * sandinya baru saja terbukti, jadi dialah yang bertindak.
     */
    private function catatPenukaranAkunLama(
        User $user,
        UndanganPelanggan $undangan,
        CustomerMember $anggota,
        int $perusahaanAktifSebelum,
        Request $request,
    ): void {
        AuditLog::create([
            'organization_id' => $undangan->organization_id,
            'entity_type' => $user->getTable(),
            'entity_id' => $user->getKey(),
            'action' => AuditLog::ACTION_UNDANGAN_DITUKAR,
            'old_data' => ['jumlah_perusahaan_aktif' => $perusahaanAktifSebelum],
            'new_data' => [
                ...$this->jejakUndangan($undangan, $request),
                'peran' => $anggota->peran,
                'jumlah_perusahaan_aktif' => $this->jumlahPerusahaanAktif($user),
            ],
            'changed_by' => $user->getKey(),
            'note' => 'Undangan ditukar di akun pelanggan yang sudah ada (sandi akun terbukti).',
        ]);
    }

    /**
     * Jejak penukaran yang DITOLAK sesudah kodenya sah. `changed_by` kosong:
     * pelakunya tidak terbukti siapa pun, dan menulis nama pemilik akun di
     * situ menuduh orang yang justru jadi sasaran.
     *
     * @param  array<string, mixed>  $tambahan
     */
    private function catatPenolakanUndangan(User $user, UndanganPelanggan $undangan, Request $request, string $alasan, array $tambahan = []): void
    {
        AuditLog::create([
            'organization_id' => $undangan->organization_id,
            'entity_type' => $user->getTable(),
            'entity_id' => $user->getKey(),
            'action' => AuditLog::ACTION_UNDANGAN_DITOLAK,
            'old_data' => null,
            'new_data' => [
                ...$this->jejakUndangan($undangan, $request),
                'alasan' => $alasan,
                ...$tambahan,
            ],
            'changed_by' => null,
            'note' => 'Penukaran undangan ditolak di akun pelanggan yang sudah ada: '.$alasan,
        ]);
    }

    /**
     * Kabar netral ke pemilik akun bahwa undangan ditukar di akunnya.
     *
     * Gagal kirim TIDAK menggagalkan penukaran yang sudah sah (sama seperti
     * `PersetujuanAkun::kabari()`), dan log-nya memuat id + kelas galat saja:
     * pesan galat antrean/email bisa memuat alamat email pemiliknya.
     */
    private function kabariPemilikAkun(User $user): void
    {
        try {
            $user->notify(UndanganDitukarDiAkunAnda::baru());
        } catch (\Throwable $e) {
            Log::warning('Gagal mengabari pemilik akun soal undangan yang ditukar.', [
                'user_id' => $user->getKey(),
                'galat' => $e::class,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function bungkusToken(User $user, string $namaPerangkat): array
    {
        $token = $this->token->terbitkan($user, $namaPerangkat);
        $user->load(['keanggotaan.customer', 'pengajuanAkun']);

        return [
            'token' => $token->plainTextToken,
            'kedaluwarsa_pada' => $token->accessToken->expires_at?->toIso8601String(),
            'kemampuan' => $this->token->ability($user),
            // `resolve()`, bukan `toArray()`: `toArray()` memulangkan
            // placeholder `MissingValue` apa adanya buat relasi yang tidak
            // dimuat, dan placeholder itu ikut terserialisasi jadi `{}` di JSON.
            // `resolve()` yang membuangnya — sama seperti waktu resource-nya
            // dipulangkan langsung dari controller.
            'user' => (new AkunResource($user))->resolve(),
        ];
    }

    /**
     * Gagal kirim email TIDAK menggagalkan requestnya.
     *
     * Akunnya sudah tersimpan dan OTP-nya sudah terbit; membalas 500 cuma bikin
     * orangnya mendaftar ulang dan kena "email sudah dipakai" — jalan buntu
     * yang lebih buruk daripada satu email yang tidak sampai, karena "kirim
     * ulang kode" memperbaikinya sendiri.
     *
     * Yang dicatat cuma user-nya. Kodenya TIDAK pernah masuk log (REQ-PRV-02).
     */
    private function kirimOtp(User $user, string $kode, string $tujuan): void
    {
        try {
            Mail::to((string) $user->email)->send(
                new KodeOtpEmail((string) $user->name, $kode, $tujuan),
            );
        } catch (\Throwable $e) {
            Log::warning('Gagal mengirim OTP pelanggan.', [
                'user_id' => $user->getKey(),
                'tujuan' => $tujuan,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Persetujuan privasi & syarat (S05 langkah 1).
     *
     * Versinya diambil dari config, bukan dari yang dikirim aplikasi: kalau
     * klien yang menentukan, aplikasi lama bisa terus mencatat "setuju v1"
     * selamanya walau dokumennya sudah v2, dan catatannya jadi tidak berguna
     * persis di saat dibutuhkan.
     */
    private function catatPersetujuan(User $user, Request $request): void
    {
        foreach ([PersetujuanDokumen::JENIS_KEBIJAKAN_PRIVASI, PersetujuanDokumen::JENIS_SYARAT_KETENTUAN] as $jenis) {
            PersetujuanDokumen::create([
                'user_id' => $user->getKey(),
                'jenis' => $jenis,
                'versi' => (string) config('pelanggan.versi_dokumen.'.$jenis, '1.0'),
                'disetujui_pada' => now(),
                'ip' => $request->ip(),
            ]);
        }
    }

    /**
     * SATU-SATUNYA tempat balasan gagal OTP dirakit — termasuk buat akun yang
     * tidak ada sama sekali.
     *
     * Sebelumnya jalur "akun tidak ketemu" punya kalimatnya sendiri
     * ("Kode verifikasi salah…" vs "Kode salah…"), dan selisih satu kata itu
     * cukup buat memberi tahu orang luar email mana yang punya akun di sini.
     * Ketahuan dari test, bukan dari membaca ulang — makanya perakitannya
     * dikunci di satu method, bukan diserahkan ke kedisiplinan pemanggil.
     */
    private function tolakOtp(string $hasil): JsonResponse
    {
        return match ($hasil) {
            KodeOtp::TERKUNCI => $this->tolak(
                'otp_terkunci',
                'Kode salah 5 kali. Verifikasi dikunci '.OtpPelanggan::KUNCI_MENIT.' menit.',
                429,
            ),
            default => $this->tolak('otp_salah', 'Kode salah atau sudah kedaluwarsa.', 422),
        };
    }

    private function tolak(string $kode, string $pesan, int $status): JsonResponse
    {
        return response()->json(['kode' => $kode, 'message' => $pesan], $status);
    }
}

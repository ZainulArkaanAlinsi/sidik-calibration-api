<?php

namespace App\Http\Controllers\Pelanggan;

use App\Exceptions\Pelanggan\AksiPelangganDitolak;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pelanggan\AturUlangSandiRequest;
use App\Http\Requests\Pelanggan\DaftarRequest;
use App\Http\Requests\Pelanggan\EmailSajaRequest;
use App\Http\Requests\Pelanggan\MasukRequest;
use App\Http\Requests\Pelanggan\TerimaUndanganRequest;
use App\Http\Requests\Pelanggan\VerifikasiEmailRequest;
use App\Http\Resources\Pelanggan\AkunResource;
use App\Mail\Pelanggan\KodeOtpEmail;
use App\Models\Organization;
use App\Models\OtpPelanggan;
use App\Models\PengajuanAkunPelanggan;
use App\Models\PersetujuanDokumen;
use App\Models\User;
use App\Notifications\Pelanggan\PengajuanAkunMenunggu;
use App\Services\Pelanggan\Keanggotaan;
use App\Services\Pelanggan\KodeOtp;
use App\Services\Pelanggan\KodeUndangan;
use App\Services\Pelanggan\TokenPelanggan;
use App\Services\PenerimaNotifikasi;
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

    /**
     * REQ-AUTH-01 — daftar perusahaan baru.
     *
     * ## Kenapa baris `pengajuan_akun_pelanggan` dibuat DI SINI, bukan waktu
     * OTP-nya cocok
     *
     * 02-SRS REQ-AUTH-02 menulis barisnya lahir saat verifikasi. Nama & alamat
     * perusahaan diketik di langkah ini, dan satu-satunya tempat yang bisa
     * menampungnya adalah tabel itu — menundanya berarti menambah kolom baru di
     * `users` cuma buat memarkir dua string, dan kolom baru itu pilihan
     * terakhir (CLAUDE.md §Alur Kerja poin 4).
     *
     * Yang sebenarnya dijaga REQ-AUTH-02 adalah admin tidak diganggu pengajuan
     * dari email yang belum tentu milik si pendaftar. Itu tetap ditegakkan, dua
     * lapis, dan dua-duanya tidak bergantung pada ingatan orang:
     *
     * - notifikasi ke admin baru dikirim di [verifikasiEmail()];
     * - antrean admin membacanya lewat `PengajuanAkunPelanggan::siapDitinjau()`,
     *   yang menuntut akun pemohonnya sudah `pending_verifikasi`.
     */
    public function daftar(DaftarRequest $request): JsonResponse
    {
        $data = $request->validated();

        $hasil = DB::transaction(function () use ($data, $request): array {
            $user = User::create([
                // Satu instalasi = satu PT Sidik, sama seperti `register()`
                // internal. Pelanggan tetap menempel ke organisasi lab, bukan
                // punya organisasi sendiri — `customers` yang memisahkan
                // perusahaan, dan itu baru ditentukan admin.
                'organization_id' => Organization::query()->min('id'),
                'name' => $data['nama'],
                'email' => $data['email'],
                'password' => $data['sandi'],
                'telepon' => $data['telepon'],
                'jabatan' => $data['jabatan'],
                'role' => User::ROLE_PELANGGAN,
                'status' => User::STATUS_PENDING_EMAIL,
            ]);

            PengajuanAkunPelanggan::create([
                'organization_id' => $user->organization_id,
                'user_id' => $user->getKey(),
                'nama_perusahaan' => $data['nama_perusahaan'],
                'alamat_perusahaan' => $data['alamat_perusahaan'] ?? null,
                'jabatan' => $data['jabatan'],
                'status' => PengajuanAkunPelanggan::STATUS_MENUNGGU,
            ]);

            $this->catatPersetujuan($user, $request);

            return ['user' => $user, 'kode' => $this->otp->terbitkan($user, OtpPelanggan::TUJUAN_VERIFIKASI_EMAIL)];
        });

        /** @var User $user */
        $user = $hasil['user'];

        // `terbitkan()` memulangkan null kalau akunnya terkunci. Buat akun yang
        // baru saja lahir itu mustahil — dan dijaga di sini supaya kalau
        // suatu hari jadi mungkin, yang terkirim bukan email berisi kode kosong.
        if (is_string($hasil['kode'])) {
            $this->kirimOtp($user, $hasil['kode'], OtpPelanggan::TUJUAN_VERIFIKASI_EMAIL);
        }

        return response()->json([
            'message' => 'Kode verifikasi 6 digit dikirim ke '.$user->email.'.',
            'data' => [
                'email' => $user->email,
                'status' => $user->status,
                'otp_berlaku_menit' => OtpPelanggan::BERLAKU_MENIT,
            ],
        ], 201);
    }

    /** REQ-AUTH-02 — tukar OTP dengan status `pending_verifikasi` + token. */
    public function verifikasiEmail(VerifikasiEmailRequest $request, PenerimaNotifikasi $penerima): JsonResponse
    {
        $data = $request->validated();
        $user = $this->cariPelanggan($data['email']);

        // Akun yang tidak ada dijawab sama persis dengan OTP yang salah —
        // kalau dibedakan, endpoint ini jadi alat menyisir email terdaftar.
        if ($user === null || $user->status !== User::STATUS_PENDING_EMAIL) {
            return $this->tolakOtp(KodeOtp::TIDAK_ADA);
        }

        $hasil = $this->otp->periksa($user, OtpPelanggan::TUJUAN_VERIFIKASI_EMAIL, $data['otp']);

        if ($hasil !== KodeOtp::COCOK) {
            return $this->tolakOtp($hasil);
        }

        $user->forceFill(['status' => User::STATUS_PENDING_VERIFIKASI, 'email_verified_at' => now()])->save();

        $this->kabariAdmin($user, $penerima);

        return response()->json(['data' => $this->bungkusToken($user, $request->string('nama_perangkat')->toString())]);
    }

    /**
     * Kirim ulang OTP (§7.1).
     *
     * Selalu 200, apa pun yang ditemukan — alasan yang sama dengan
     * [lupaSandi()]. Yang membedakan cuma apakah ada email yang benar-benar
     * terkirim.
     */
    public function kirimUlangOtp(EmailSajaRequest $request): JsonResponse
    {
        $user = $this->cariPelanggan($request->validated()['email']);

        if ($user !== null && $user->status === User::STATUS_PENDING_EMAIL) {
            $kode = $this->otp->terbitkan($user, OtpPelanggan::TUJUAN_VERIFIKASI_EMAIL);

            if ($kode === null) {
                return $this->tolak(
                    'otp_terkunci',
                    'Terlalu banyak percobaan. Coba lagi dalam beberapa menit.',
                    429,
                );
            }

            $this->kirimOtp($user, $kode, OtpPelanggan::TUJUAN_VERIFIKASI_EMAIL);
        }

        return response()->json([
            'message' => 'Kalau email itu memang menunggu verifikasi, kode barunya sudah dikirim.',
            'data' => ['otp_berlaku_menit' => OtpPelanggan::BERLAKU_MENIT],
        ]);
    }

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
     */
    public function terimaUndangan(TerimaUndanganRequest $request, KodeUndangan $kodeUndangan, Keanggotaan $keanggotaan): JsonResponse
    {
        $data = $request->validated();
        $undangan = $kodeUndangan->tukar($data['email'], $data['kode']);

        // Satu balasan buat "kode salah", "sudah dipakai", dan "kedaluwarsa".
        // Membedakannya bikin endpoint ini bisa dipakai memeriksa undangan mana
        // yang pernah ada buat sebuah email.
        if ($undangan === null || $undangan->customer === null) {
            return $this->tolak(
                'undangan_tidak_berlaku',
                'Kode undangan tidak cocok, sudah dipakai, atau sudah kedaluwarsa.',
                422,
            );
        }

        $hasil = DB::transaction(function () use ($data, $undangan, $keanggotaan, $request) {
            $perusahaan = $undangan->customer;

            // Orang yang emailnya SUDAH punya akun tidak dibuatkan akun kedua —
            // dia ditambahkan sebagai anggota perusahaan ini. Konsultan yang
            // memegang tiga pabrik itu satu orang, bukan tiga akun.
            $user = User::query()->where('email', $data['email'])->first();

            if ($user === null) {
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
            }

            $anggota = $keanggotaan->daftarkan($perusahaan, $user, $undangan->peran, $undangan->pembuat);

            $undangan->forceFill([
                'dipakai_pada' => now(),
                'dipakai_oleh' => $user->getKey(),
            ])->save();

            return ['user' => $user->refresh(), 'anggota' => $anggota];
        });

        return response()->json([
            'data' => $this->bungkusToken($hasil['user'], $data['nama_perangkat'] ?? ''),
        ], 201);
    }

    /** REQ-AUTH-03/07/08/10 — masuk dari aplikasi pelanggan. */
    public function masuk(MasukRequest $request): JsonResponse
    {
        $data = $request->validated();
        $kunci = 'pelanggan-masuk-gagal|'.sha1(mb_strtolower($data['email']));

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

        if ($user->status === User::STATUS_PENDING_EMAIL) {
            return $this->tolak(
                'email_belum_diverifikasi',
                'Email Anda belum diverifikasi. Minta kode baru lalu masukkan 6 digitnya.',
                403,
            );
        }

        if ($user->status === User::STATUS_NONAKTIF || $user->dianonimkan_pada !== null) {
            return $this->tolak('akun_nonaktif', 'Akun ini sudah tidak aktif. Hubungi PT Sidik.', 403);
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

        RateLimiter::clear('pelanggan-masuk-gagal|'.sha1((string) $user->email));

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

    private function kabariAdmin(User $user, PenerimaNotifikasi $penerima): void
    {
        try {
            $notifikasi = PengajuanAkunMenunggu::dariUser($user);

            foreach ($penerima->adminAktif((int) $user->organization_id) as $admin) {
                $admin->notify($notifikasi);
            }
        } catch (\Throwable $e) {
            Log::warning('Gagal mengabari admin soal pengajuan akun pelanggan.', [
                'user_id' => $user->getKey(),
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

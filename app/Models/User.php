<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\Diaudit;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @mixin IdeHelperUser
 */
#[Fillable([
    'organization_id', 'employee_id', 'kode_teknisi', 'name', 'department',
    'email', 'role', 'status', 'password',
    // Identitas akun pelanggan (03-SDD §4.1). `dianonimkan_pada` dipakai
    // REQ-AUTH-11 — hapus akun menganonimkan data pribadi, bukan menghapus
    // rekaman lab yang menggantung padanya (BR-07).
    'telepon', 'jabatan', 'dianonimkan_pada',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use Diaudit, HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_TEKNISI = 'teknisi';

    public const ROLE_VIEWER = 'viewer';

    /**
     * Role aplikasi pelanggan. SENGAJA di luar [roles()] — baca docblock di sana.
     */
    public const ROLE_PELANGGAN = 'pelanggan';

    /**
     * Role pengawas lintas-proses. Nilainya sudah ada di ENUM sejak 16 Sep 2026,
     * tapi PERILAKUNYA belum dibangun: dia menunggu keputusan K4 (siapa yang
     * boleh mengesahkan sertifikat) dari manajer teknis. Nilai ENUM yang
     * menganggur nol risikonya; yang berbahaya justru memberi izin sebelum
     * pemisahan wewenangnya diputuskan.
     */
    public const ROLE_SUPER_ADMIN = 'super_admin';

    /** Akun baru hasil daftar mandiri. Belum boleh login sampai admin approve. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_NONAKTIF = 'nonaktif';

    /** Daftar, email belum diverifikasi OTP (REQ-AUTH-01). */
    public const STATUS_PENDING_EMAIL = 'pending_email';

    /** Email terverifikasi, menunggu admin menautkan ke pelanggan (REQ-AUTH-03). */
    public const STATUS_PENDING_VERIFIKASI = 'pending_verifikasi';

    /**
     * Role INTERNAL lab — admin, teknisi, viewer. Tiga, dan tetap tiga.
     *
     * `pelanggan` dan `super_admin` ADA di ENUM `users.role` tapi SENGAJA tidak
     * di sini, dan itu bukan kelupaan. Tiga tempat memakai daftar ini sebagai
     * gerbang, dan menambahkan role pelanggan ke dalamnya merusak ketiganya
     * tanpa satu pun memunculkan error:
     *
     * 1. `routes/channels.php` — gerbang channel `organisasi.{id}` (M0-06)
     *    langsung terbuka, dan tiap sesi & sertifikat yang lewat bocor realtime
     *    ke pelanggan.
     * 2. `UserController` — `Rule::in(self::roles())` bikin admin bisa mencetak
     *    akun pelanggan dari panel internal, melewati seluruh alur verifikasi
     *    yang menahan R-D02 (orang mengaku sebagai PT X lalu melihat
     *    sertifikat PT X).
     * 3. `role:admin,teknisi,viewer` di grup luar `routes/api.php` jadi tidak
     *    sejalan lagi dengan daftar yang dipakai di tempat lain.
     *
     * `super_admin` tetap di luar daftar ini SESUDAH Fase 2 membukanya, dan
     * alasannya beda dari alasan pelanggan: butir 2 di atas. Daftar ini dipakai
     * `Rule::in(self::roles())` sebagai pilihan role waktu admin MEMBUAT atau
     * mengubah akun — begitu `super_admin` masuk, admin biasa bisa
     * mempromosikan dirinya sendiri jadi super admin lewat panel, dan peran yang
     * seharusnya mengawasi admin jadi bisa dicetak admin. Yang dibuka Fase 2
     * pintu MASUKNYA (lihat [rolesInternal()]), bukan hak mencetaknya.
     *
     * Dijaga `RolePelangganTidakMasukRoleInternalTest`.
     *
     * @return array<int, string>
     */
    public static function roles(): array
    {
        return [self::ROLE_ADMIN, self::ROLE_TEKNISI, self::ROLE_VIEWER];
    }

    /**
     * Role yang boleh MASUK ke sisi internal — [roles()] + `super_admin`.
     *
     * Dua daftar, dua pertanyaan yang beda. Menggabungkannya yang bikin
     * `super_admin` macet sejak 16 Sep:
     *
     * - [roles()] menjawab "role apa saja yang boleh DIBERIKAN admin ke orang
     *   lain" — dipakai `Rule::in`, saringan daftar pengguna, dan form panel.
     * - [rolesInternal()] menjawab "siapa saja yang bukan orang luar" — dipakai
     *   gerbang channel dan grup luar `routes/api.php`.
     *
     * Super admin ada di yang kedua saja: dia boleh masuk dan membaca, tapi
     * tidak boleh dicetak dari dalam.
     *
     * @return array<int, string>
     */
    public static function rolesInternal(): array
    {
        return [...self::roles(), self::ROLE_SUPER_ADMIN];
    }

    /**
     * Pengguna yang lagi login, sudah pasti bertipe `User`.
     *
     * Dipakai menggantikan pemanggilan `user()` langsung dari helper `auth()`
     * di seluruh panel admin. Alasannya bukan gaya-gayaan: helper itu
     * dideklarasiin balikin `AuthFactory|Guard`, dan `AuthFactory` NGGAK punya
     * method `user()` — jadi analisa statis (intelephense/PHPStan) nandainnya
     * sebagai method nggak dikenal. Perkaranya bukan cuma garis merah di
     * editor: karena tipenya ngambang, salah ketik nama kolom
     * (`->organisation_id`) juga nggak ketahuan sampai jalan di browser.
     *
     * Lewat `auth()->guard()` tipenya jelas `Guard|StatefulGuard` yang
     * dua-duanya punya `user()`, terus dipersempit ke `User` di sini.
     */
    public static function yangLogin(): ?self
    {
        $user = auth()->guard()->user();

        return $user instanceof self ? $user : null;
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Sengaja TIDAK memulangkan `true` buat admin, dan sebaliknya juga nggak.
     *
     * Dua peran yang berbeda, bukan bertingkat: admin menulis, super admin
     * mengawasi. `isAdmin()` menjaga jalur tulis (mis. `ImportExcel`), jadi
     * membuatnya ikut `true` buat super admin bakal memberi wewenang tulis
     * lewat pintu belakang — persis yang ditahan sampai K4 turun.
     */
    public function isSuperAdmin(): bool
    {
        return $this->role === self::ROLE_SUPER_ADMIN;
    }

    /**
     * "Technician ID" yang dicetak di sertifikat (mis. `DR`). Kalau admin belum
     * ngisi kodenya, jatuh ke inisial nama — lebih baik inisial daripada kolom
     * kosong di dokumen resmi.
     */
    public function kodeTeknisi(): string
    {
        if (filled($this->kode_teknisi)) {
            return strtoupper($this->kode_teknisi);
        }

        $inisial = collect(preg_split('/\s+/', trim((string) $this->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $kata): string => strtoupper(mb_substr($kata, 0, 1)))
            ->implode('');

        return $inisial !== '' ? $inisial : '—';
    }

    /**
     * Benteng panel admin web (Filament). Ini SATU-SATUNYA yang misahin panel
     * dari sesi login biasa — tanpa ini, teknisi & viewer yang punya akun tetap
     * bisa buka /admin. Wajib admin ATAU super admin, DAN aktif: akun yang
     * dinonaktifin harus langsung kehilangan akses panel, bukan cuma ditolak API.
     *
     * Super admin ikut sejak Fase 2, dan itu membereskan kontradiksi yang
     * ditulis di AGENTS.md: `AuthController` menolak loginnya dari aplikasi
     * teknisi sambil menyuruhnya "pakai panel admin di peramban", padahal
     * panelnya sendiri cuma menerima `ROLE_ADMIN`. Petunjuknya menunjuk pintu
     * terkunci selama tiga hari — nol akun kena cuma karena nol super admin
     * pernah dibuat.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return in_array($this->role, [self::ROLE_ADMIN, self::ROLE_SUPER_ADMIN], true)
            && $this->status === self::STATUS_AKTIF;
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return HasMany<CalibrationSession, $this> */
    public function calibrationSessions(): HasMany
    {
        return $this->hasMany(CalibrationSession::class, 'teknisi_id');
    }

    /**
     * Keanggotaan orang ini di perusahaan pelanggan.
     *
     * HasMany, bukan BelongsTo satu perusahaan: konsultan bisa jadi anggota
     * lebih dari satu perusahaan sekaligus (REQ-ANG-04), dan perusahaan aktif
     * per request ditentukan `KonteksPerusahaan` dari header — bukan dari kolom
     * di baris user.
     *
     * @return HasMany<CustomerMember, $this>
     */
    public function keanggotaan(): HasMany
    {
        return $this->hasMany(CustomerMember::class);
    }

    /**
     * Pengajuan akun pelanggan TERBARU milik orang ini.
     *
     * `latestOfMany()`, bukan `hasOne()` polos: seorang pendaftar bisa punya
     * lebih dari satu baris kalau pengajuan pertamanya ditolak dan admin
     * memintanya mengajukan ulang. `hasOne()` biasa memulangkan yang mana saja
     * yang kebetulan pertama ditemukan driver — jadi layar S06 bisa menampilkan
     * penolakan lama sesudah pengajuan barunya disetujui.
     *
     * @return HasOne<PengajuanAkunPelanggan, $this>
     */
    public function pengajuanAkun(): HasOne
    {
        return $this->hasOne(PengajuanAkunPelanggan::class)->latestOfMany();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            // Tanpa cast ini dia balik STRING, dan yang pertama pecah bukan
            // error melainkan perbandingan: `$user->dianonimkan_pada?->isPast()`
            // fatal, sementara `if ($user->dianonimkan_pada)` malah TRUE buat
            // string kosong mana pun. Kolomnya lahir di fase 6 (hapus akun) dan
            // yang membacanya gerbang "akun ini masih boleh masuk atau nggak".
            'dianonimkan_pada' => 'datetime',
        ];
    }
}

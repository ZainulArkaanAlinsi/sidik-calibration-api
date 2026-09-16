<?php

namespace App\Services\Pelanggan;

use App\Models\OtpPelanggan;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;

/**
 * Terbit & periksa OTP 6 digit pelanggan (REQ-AUTH-01, REQ-AUTH-02).
 *
 * ## Kenapa bcrypt buat angka 6 digit
 *
 * Refleksnya "sha256 saja, ini cuma OTP". Tidak: ruang tebakannya cuma sejuta.
 * Dump tabel dengan sha256 di dalamnya dibalik seluruhnya dalam hitungan detik
 * di laptop biasa — dan yang bocor bukan "kode lama", melainkan kode yang masih
 * berlaku 10 menit buat akun yang emailnya ada di baris yang sama.
 * `Hash::make()` (bcrypt) bikin sejuta tebakan jadi belasan jam, dan gabungan
 * dengan masa berlaku 10 menit + kunci 5 percobaan membuatnya tidak menarik.
 *
 * Biaya CPU-nya nyata (~50 ms sekali verifikasi) dan memang mau dibayar: dia
 * cuma jalan di jalur verifikasi email dan atur ulang sandi.
 *
 * ## Penguncian menempel ke AKUN, bukan ke baris OTP yang kebetulan terakhir
 *
 * Kalau tidak, kuncinya gampang dilewati: salah 5 kali → minta kirim ulang →
 * baris baru yang bersih → tebak 5 kali lagi. Itu sebabnya [terbitkan()]
 * MENOLAK selama akunnya masih terkunci, dan kunci itu diwariskan ke baris
 * berikutnya lewat [kunciSampai()].
 */
class KodeOtp
{
    public const COCOK = 'cocok';

    public const SALAH = 'salah';

    public const TERKUNCI = 'terkunci';

    /** Belum pernah minta kode, atau kodenya sudah kedaluwarsa/terpakai. */
    public const TIDAK_ADA = 'tidak_ada';

    /**
     * Terbitkan kode baru, kembalikan kode POLOSNYA (cuma di sini dia ada).
     *
     * Balikan `null` = akunnya sedang terkunci; pemanggil membalas 429 dan
     * TIDAK membuat baris baru.
     */
    public function terbitkan(User $user, string $tujuan): ?string
    {
        if ($this->kunciSampai($user, $tujuan) !== null) {
            return null;
        }

        // Kode lama yang belum dipakai dimatikan, bukan dibiarkan hidup
        // berdampingan. Dua kode berlaku sekaligus berarti kode yang sudah
        // terlanjur terkirim ke email lama (mis. sebelum ganti email) masih
        // membuka akun.
        OtpPelanggan::query()
            ->where('user_id', $user->getKey())
            ->where('tujuan', $tujuan)
            ->whereNull('dipakai_pada')
            ->update(['dipakai_pada' => now()]);

        $kode = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);

        $otp = new OtpPelanggan;
        $otp->user_id = $user->getKey();
        $otp->tujuan = $tujuan;
        $otp->kode_hash = Hash::make($kode);
        $otp->kedaluwarsa_pada = now()->addMinutes(OtpPelanggan::BERLAKU_MENIT);
        $otp->percobaan = 0;
        $otp->save();

        return $kode;
    }

    /**
     * Adu kode yang diketik orang ke baris terakhir yang masih hidup.
     *
     * @return string salah satu konstanta kelas ini
     */
    public function periksa(User $user, string $tujuan, string $kode): string
    {
        $otp = $this->terakhir($user, $tujuan);

        if ($otp === null) {
            return self::TIDAK_ADA;
        }

        if ($otp->sedangDikunci()) {
            return self::TERKUNCI;
        }

        if (! $otp->masihBerlaku()) {
            return self::TIDAK_ADA;
        }

        if (Hash::check($kode, (string) $otp->kode_hash)) {
            $otp->forceFill(['dipakai_pada' => now()])->save();

            return self::COCOK;
        }

        $otp->percobaan = (int) $otp->percobaan + 1;

        if ($otp->percobaan >= OtpPelanggan::MAKS_PERCOBAAN) {
            $otp->dikunci_sampai = now()->addMinutes(OtpPelanggan::KUNCI_MENIT);
        }

        $otp->save();

        return $otp->sedangDikunci() ? self::TERKUNCI : self::SALAH;
    }

    /** Kapan kunci akun ini berakhir, atau `null` kalau tidak terkunci. */
    public function kunciSampai(User $user, string $tujuan): ?CarbonInterface
    {
        $otp = OtpPelanggan::query()
            ->where('user_id', $user->getKey())
            ->where('tujuan', $tujuan)
            ->whereNotNull('dikunci_sampai')
            ->orderByDesc('dikunci_sampai')
            ->first();

        return $otp !== null && $otp->sedangDikunci() ? $otp->dikunci_sampai : null;
    }

    /**
     * Baris OTP terbaru buat akun + tujuan ini.
     *
     * `orderByDesc('id')` ikut, bukan cuma `created_at`: dua baris bisa lahir
     * di detik yang sama (kirim ulang yang di-tap dua kali), dan kolom
     * `created_at` presisinya detik — tanpa `id` urutannya jadi tergantung
     * driver.
     */
    private function terakhir(User $user, string $tujuan): ?OtpPelanggan
    {
        return OtpPelanggan::query()
            ->where('user_id', $user->getKey())
            ->where('tujuan', $tujuan)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }
}

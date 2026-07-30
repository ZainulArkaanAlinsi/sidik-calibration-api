<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Organization;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Bahan render lembar sertifikat (`resources/views/sertifikat/pdf.blade.php`).
 *
 * Dipakai DUA jalur yang harus keluar sama persis:
 *   1. `GenerateCertificate` — mencetak PDF yang dipegang pelanggan.
 *   2. `VerificationController` — halaman hasil scan QR.
 *
 * Dipisah ke sini justru supaya dua jalur itu nggak bisa pelan-pelan beda.
 * Sebelumnya bahannya dirakit di dalam job, jadi halaman verifikasi kepaksa
 * bikin tampilan ringkas sendiri — dan lembar yang dipegang pelanggan beda
 * dari lembar yang muncul waktu QR-nya discan. Buat dokumen kalibrasi itu
 * bukan beda gaya, itu dua dokumen berbeda buat sertifikat yang sama.
 *
 * Isinya diambil dari `certificates.snapshot` yang dibekukan waktu terbit —
 * kelas ini cuma nyiapin gambar (logo, tanda tangan, QR) yang nggak bisa
 * disimpen di dalam snapshot.
 */
class DataTampilanSertifikat
{
    public function __construct(private QrCodeGenerator $qr) {}

    /**
     * @param  bool  $web  true = dirender browser (halaman verifikasi),
     *                     false = dicetak dompdf jadi PDF.
     * @return array<string, mixed>
     */
    public function untuk(Certificate $sertifikat, bool $web = false): array
    {
        $sertifikat->loadMissing('organization', 'session');
        $organisasi = $sertifikat->organization;

        return [
            'sertifikat' => $sertifikat,
            'snapshot' => $sertifikat->snapshot,
            'logo' => $this->logoDataUri($organisasi),
            // Kop surat selebar halaman. Kalau ada, dia GANTIIN kop teks — bukan
            // ditambahin di atasnya (lihat `kopDataUri()`).
            'kop' => $this->kopDataUri($organisasi),
            // `null` kalau belum diunggah — dan itu state yang SAH: sertifikat
            // nyetak garis + nama + jabatan dengan ruang kosong buat tanda
            // tangan basah.
            'tandaTangan' => $this->tandaTanganDataUri($organisasi),
            'posisiTtd' => $organisasi?->pengaturanTandaTangan()
                ?? ['geser_x_mm' => 0, 'geser_y_mm' => 0, 'lebar_mm' => Organization::DEFAULT_TTD_LEBAR_MM],
            'qr' => $this->qrDataUri($organisasi, $sertifikat, $web),
            'keputusan' => $this->tampilkanKeputusan($organisasi)
                ? $sertifikat->session?->keputusan
                : null,
            'web' => $web,
        ];
    }

    /**
     * QR verifikasi. **Harus di-opt-in** lewat pengaturan organisasi
     * (`tampilkan_qr_di_pdf` = true); default-nya nggak dicetak.
     *
     * Arahnya sengaja dibalik. Dulu default-nya nyetak QR dan PT Sidik
     * matiin lewat panel admin — sampai `db:seed` nimpa JSON `settings`
     * dan kuncinya ilang. Diam-diam QR-nya balik kecetak di sertifikat yang
     * layout-nya dikunci dokumen mutu, dan nggak ada yang error.
     *
     * Dengan default "nggak dicetak", kunci yang ilang bikin QR-nya absen —
     * kelihatan, dan gampang dibalikin. Kalau default-nya nyetak, kunci yang
     * ilang bikin dokumen resmi salah bentuk tanpa tanda apa pun.
     *
     * Di tampilan web QR-nya SELALU nggak ada, apa pun pengaturannya: halaman
     * itu sendiri yang dituju QR. Nyetak QR di halaman hasil scan QR cuma
     * bikin orang muter di tempat.
     */
    private function qrDataUri(?Organization $organisasi, Certificate $sertifikat, bool $web): ?string
    {
        if ($web || ($organisasi?->settings['tampilkan_qr_di_pdf'] ?? false) !== true) {
            return null;
        }

        try {
            return $this->qr->dataUri((string) $sertifikat->qr_payload, skala: 4);
        } catch (\Throwable $e) {
            // QR gagal dibikin (mis. ekstensi GD nggak aktif di server) nggak
            // boleh nahan penerbitan — sertifikatnya masih sah tanpa QR.
            Log::warning('QR sertifikat gagal dibuat.', [
                'certificate_id' => $sertifikat->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Struktur baku sertifikat pH nggak punya baris keputusan PASS/FAIL, jadi
     * defaultnya NGGAK dicetak. Buat alat lain (yang formatnya nggak dikunci
     * pelanggan) keputusan itu informasi paling penting di dokumennya —
     * makanya disediain sakelarnya, bukan dihapus permanen.
     */
    private function tampilkanKeputusan(?Organization $organisasi): bool
    {
        return (bool) ($organisasi?->settings['tampilkan_keputusan_di_pdf'] ?? false);
    }

    /**
     * Kop surat sebagai data URI — banner selebar halaman di atas sertifikat.
     *
     * Ini BUKAN logo. Logo itu lambang kecil; kop surat ini satu gambar yang
     * udah memuat lambang lab, nama & alamat PT, plus lambang KAN + nomor
     * akreditasi. Karena semua itu ada di dalam gambarnya, blade nggak nulis
     * ulang teksnya di sebelahnya — kalau ditulis dua kali, alamat & nomor
     * akreditasi kecetak dobel dan yang satu bisa basi duluan.
     *
     * Prioritasnya sama kayak logo: punya organisasi dulu (`kop_path`, diunggah
     * admin), baru bawaan di `public/images`. null kalau dua-duanya nggak ada —
     * blade balik ke kop teks yang lama, jadi sertifikatnya tetap terbit.
     */
    private function kopDataUri(?Organization $organisasi): ?string
    {
        $path = null;
        $kop = $organisasi?->settings[Organization::KEY_KOP_PATH] ?? null;

        if ($kop && Storage::disk('public')->exists($kop)) {
            $path = Storage::disk('public')->path($kop);
        } elseif (is_file(public_path('images/kop-surat.png'))) {
            $path = public_path('images/kop-surat.png');
        }

        if ($path === null) {
            return null;
        }

        $mime = str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }

    /**
     * Logo lab sebagai data URI buat kop sertifikat. Prioritas logo milik
     * organisasi (`logo_path` di disk publik, diupload admin lewat panel);
     * kalau kosong/nggak ada, pakai logo bawaan di public/images. null kalau
     * dua-duanya nggak ada — kop-nya balik ke teks doang, lembarnya tetap jadi.
     */
    private function logoDataUri(?Organization $organisasi): ?string
    {
        $path = null;

        if ($organisasi?->logo_path && Storage::disk('public')->exists($organisasi->logo_path)) {
            $path = Storage::disk('public')->path($organisasi->logo_path);
        } elseif (is_file(public_path('images/logo-sidik.png'))) {
            $path = public_path('images/logo-sidik.png');
        }

        if ($path === null) {
            return null;
        }

        $mime = str_ends_with(strtolower($path), '.png') ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }

    /**
     * Gambar tanda tangan sebagai data URI (fase-2 §3c).
     *
     * Dibaca dari disk **local (privat)**, beda dari logo yang di disk publik —
     * gambar tanda tangan yang URL-nya bisa diakses siapa pun berarti siapa pun
     * bisa nempelin ke dokumen palsu. Di-embed sebagai data URI, jadi berkasnya
     * sendiri nggak pernah punya URL: dompdf maupun browser cuma nerima
     * gambarnya yang udah nempel di lembar sertifikat ini.
     *
     * Mime-nya dipatok `image/png`, nggak ditebak dari ekstensi kayak
     * `logoDataUri()`. Unggahannya udah dibatasi PNG doang (JPG nggak punya
     * alpha → kecetak jadi kotak putih yang nutupin garis tanda tangan), jadi
     * nebak cuma nambah cara buat salah.
     */
    private function tandaTanganDataUri(?Organization $organisasi): ?string
    {
        $path = $organisasi?->tanda_tangan_path;

        if (! filled($path) || ! Storage::disk('local')->exists($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) Storage::disk('local')->get($path));
    }
}

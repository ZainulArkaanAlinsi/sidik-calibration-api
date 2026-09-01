<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Organization;
use App\Support\UkuranTandaTangan;
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

        // Isi berkasnya dibaca SEKALI: dipakai buat data URI-nya sekaligus buat
        // mengukur rasinya. Membacanya dua kali berarti dua round-trip ke
        // object storage buat satu gambar yang sama.
        $ttdIsi = $this->tandaTanganIsi($organisasi);

        $posisiTtd = $organisasi?->pengaturanTandaTangan()
            ?? ['geser_x_mm' => 0, 'geser_y_mm' => 0, 'lebar_mm' => Organization::DEFAULT_TTD_LEBAR_MM];

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
            'tandaTangan' => $ttdIsi === null ? null : 'data:image/png;base64,'.base64_encode($ttdIsi),
            'posisiTtd' => $posisiTtd,
            // Lebar pilihan admin cuma menyetel LEBAR; tingginya dulu dibiarkan
            // ikut gambar, dan gambar yang tidak lebar-mendatar meluber ke atas
            // menimpa tabel di atasnya. Lihat App\Support\UkuranTandaTangan.
            'ukuranTtd' => UkuranTandaTangan::keduaMode(
                $ttdIsi,
                (float) ($posisiTtd['lebar_mm'] ?? Organization::DEFAULT_TTD_LEBAR_MM),
            ),
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
        $kop = $organisasi?->settings[Organization::KEY_KOP_PATH] ?? null;

        if (filled($kop)) {
            $isi = Storage::disk('public')->get($kop);

            if (filled($isi)) {
                return self::dataUri($kop, $isi);
            }
        }

        $bawaan = public_path('images/kop-surat.png');

        return is_file($bawaan)
            ? self::dataUri($bawaan, (string) file_get_contents($bawaan))
            : null;
    }

    /**
     * Logo lab sebagai data URI buat kop sertifikat. Prioritas logo milik
     * organisasi (`logo_path` di disk publik, diupload admin lewat panel);
     * kalau kosong/nggak ada, pakai logo bawaan di public/images. null kalau
     * dua-duanya nggak ada — kop-nya balik ke teks doang, lembarnya tetap jadi.
     */
    private function logoDataUri(?Organization $organisasi): ?string
    {
        $logo = $organisasi?->logo_path;

        if (filled($logo)) {
            $isi = Storage::disk('public')->get($logo);

            if (filled($isi)) {
                return self::dataUri($logo, $isi);
            }
        }

        $bawaan = public_path('images/logo-sidik.png');

        return is_file($bawaan)
            ? self::dataUri($bawaan, (string) file_get_contents($bawaan))
            : null;
    }

    /**
     * Data URI dari ISI berkas, bukan dari path-nya.
     *
     * ## Kenapa isi, bukan path
     *
     * `kopDataUri()` & `logoDataUri()` dulu ngambil
     * `Storage::disk('public')->path()` lalu `file_get_contents()`. Itu jalan
     * SELAMA disknya masih driver `local`.
     *
     * Begitu disknya pindah ke S3/R2, `path()` nggak melempar error — dia
     * balikin kunci bucket sebagai string biasa. `file_get_contents()` atas
     * string itu memicu warning, dan Laravel ngubah warning jadi
     * `ErrorException`.
     *
     * Yang bikin mahal: exception-nya kelempar di tengah `GenerateCertificate`,
     * ketangkep `catch (\Throwable)` di situ, dan sertifikatnya distempel
     * `gagal`. Jadi pindah disk nggak bikin "kopnya nggak kecetak" — dia bikin
     * SERTIFIKATNYA BERHENTI TERBIT, buat tiap organisasi yang pernah ngunggah
     * kop atau logo.
     *
     * ## Kenapa fallback-nya `if` terpisah, bukan `elseif`
     *
     * Dulu bawaan `public/images` ditaruh di `elseif` sesudah
     * `Storage::exists()`. Di S3 `exists()` balikin **true** buat berkas yang
     * nggak beneran kebaca, jadi cabang bawaannya nggak pernah kesentuh —
     * jaring pengaman yang ada tapi nggak pernah nangkap apa-apa.
     *
     * Sekarang: `get()` balikin null kalau nggak kebaca (disknya `throw => false`),
     * dan bawaannya diperiksa terpisah. Jalan di semua driver.
     *
     * Polanya nyontek `tandaTanganDataUri()` di bawah, yang dari awal udah benar.
     */
    private static function dataUri(string $nama, string $isi): string
    {
        $mime = str_ends_with(strtolower($nama), '.png') ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($isi);
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
    private function tandaTanganIsi(?Organization $organisasi): ?string
    {
        $path = $organisasi?->tanda_tangan_path;

        if (! filled($path) || ! Storage::disk('arsip')->exists($path)) {
            return null;
        }

        $isi = Storage::disk('arsip')->get($path);

        // Disk `arsip` disetel `throw => false`, jadi baca yang gagal balik
        // null tanpa suara. Dibiarkan lewat, yang tercetak `data:image/png;
        // base64,` kosong — gambar rusak di dokumen terkendali, bukan ruang
        // kosong buat tanda tangan basah yang memang state sah.
        return filled($isi) ? (string) $isi : null;
    }
}

<?php

namespace App\Support;

use App\Models\WorksheetExtractionLog;
use App\Services\Vision\GoogleVisionOcr;
use App\Services\Vision\GoogleVisionToken;
use Throwable;

/**
 * Status OCR awan untuk admin (`GET /api/admin/ocr-cloud`): supaya konfigurasi yang salah cepat
 * terlihat tanpa menunggu teknisi mengeluh atau membuka dashboard hosting.
 *
 * ## Siapa yang boleh melihat
 *
 * Hanya admin terautentikasi, lewat gerbang endpoint admin biasa. SENGAJA tidak di `GET /api/health`
 * yang publik: status konfigurasi layanan berbayar pihak ketiga tidak untuk dibaca siapa saja.
 *
 * ## Yang dilaporkan, dan batasnya
 *
 *  - hanya ADA/TIDAK dan hitungan; isi kredensial, panjangnya, dan pesan galat Google tidak pernah
 *    ikut;
 *  - NOL permintaan ke Google: `kredensial_terbaca` memeriksa bentuk JSON di config dan apakah kunci
 *    privatnya terbaca OpenSSL (lokal), jadi tidak membuktikan Google menerimanya;
 *  - hitungan hanya milik organisasi pemanggil, dan hanya jalur OCR awan, bukan jalur LLM;
 *  - hitungan 24 jam hanya dihitung bila env `GOOGLE_VISION_AKTIF` nyala (satu kueri); saat env mati
 *    endpoint ini tidak menyentuh database (status saklar `ocr_cloud` tidak ditampilkan). Gagal membaca
 *    database menghasilkan `hitungan: null`, bukan 500.
 *
 * ## UTANG TEKNIS: penyaringan organisasi lewat pengguna
 *
 * `worksheet_extraction_logs` belum punya kolom `organization_id`, jadi hitungan disaring lewat
 * `whereHas('user', organization_id = pemanggil)`. Akibatnya: (1) baris yang `user_id`-nya NULL
 * (pengguna terhapus, `nullOnDelete`) tidak terhitung, jadi hitungan bisa KURANG, tidak pernah
 * bocor ke organisasi lain; (2) kueri bergabung ke `users` tanpa indeks `created_at` di tabel log.
 * Risikonya rendah (hanya admin, jendela 24 jam, tabel kecil). Pelunasan: tambah `organization_id`
 * (nullable, diisi controller saat mencatat, isi ulang baris lama dari `users`) beserta indeks
 * `(organization_id, model, created_at)`, lalu ganti `whereHas`. Itu migrasi tersendiri, sengaja belum
 * dikerjakan di sini.
 */
final class StatusOcrCloud
{
    /** Jendela hitungan, dalam jam. */
    public const JENDELA_JAM = 24;

    /** @return array<string, mixed> */
    public static function blok(int $organisasiId): array
    {
        $aktif = filter_var(config('services.google_vision.aktif'), FILTER_VALIDATE_BOOLEAN);
        $disetel = trim((string) config('services.google_vision.kredensial', '')) !== '';
        $terbaca = $disetel && app(GoogleVisionToken::class)->kredensialTerbaca();

        $blok = [
            'aktif' => $aktif,
            'kredensial_disetel' => $disetel,
            'kredensial_terbaca' => $terbaca,
            'salah_setel' => $aktif && ! $terbaca,
        ];

        if (! $aktif) {
            return $blok;
        }

        $blok['jendela_jam'] = self::JENDELA_JAM;
        $blok['hitungan'] = self::hitungan($organisasiId);

        return $blok;
    }

    /** @return array<string, int>|null */
    private static function hitungan(int $organisasiId): ?array
    {
        try {
            $baris = WorksheetExtractionLog::query()
                ->where('model', GoogleVisionOcr::MODEL)
                ->where('created_at', '>=', now()->subHours(self::JENDELA_JAM))
                ->whereHas('user', fn ($q) => $q->where('organization_id', $organisasiId))
                ->selectRaw('status, count(*) as jumlah')
                ->groupBy('status')
                ->pluck('jumlah', 'status');

            $hasil = [];
            foreach (['sukses', 'gagal', 'ditolak', 'dibatasi', 'belum_disetel'] as $status) {
                $hasil[$status] = (int) ($baris[$status] ?? 0);
            }

            return $hasil;
        } catch (Throwable) {
            return null;
        }
    }
}

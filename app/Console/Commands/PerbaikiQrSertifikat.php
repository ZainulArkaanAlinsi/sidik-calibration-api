<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use Illuminate\Console\Command;

/**
 * Nulis ulang `qr_payload` semua sertifikat supaya ngikut `APP_URL`.
 *
 * ## Kenapa perlu
 *
 * Sertifikat yang terbit sebelum 6 Agt 2026 bikin QR-nya pakai `url()`, yang
 * ngambil host dari request yang lagi jalan. Admin approve dari LAN, jadi
 * alamat sementara mesin dev ikut kecetak permanen:
 *
 *     http://192.168.1.8:8000/verify/30d45ksvp2
 *
 * IP-nya pindah, QR-nya mati. Dari luar kantor emang nggak pernah bisa dibuka.
 *
 * `qr_token`-nya SENGAJA nggak diganti: token itu yang dicocokin halaman
 * verifikasi, dan ngegantinya bakal matiin QR yang mungkin udah dicetak. Yang
 * dibetulin cuma alamat di depannya.
 *
 * Aman diulang: hasilnya cuma bergantung `APP_URL` + token, jadi jalanin dua
 * kali hasilnya sama.
 */
class PerbaikiQrSertifikat extends Command
{
    protected $signature = 'sertifikat:perbaiki-qr {--dry-run : Cuma nampilin yang bakal berubah, nggak nulis apa-apa}';

    protected $description = 'Nulis ulang qr_payload semua sertifikat biar ngikut APP_URL';

    public function handle(): int
    {
        $dasar = rtrim((string) config('app.url'), '/');

        if ($dasar === '' || str_contains($dasar, 'localhost') || str_contains($dasar, '127.0.0.1')) {
            $this->error("APP_URL sekarang: {$dasar}");
            $this->error('Ini alamat lokal — QR-nya tetap nggak bakal bisa dibuka pelanggan.');
            $this->warn('Isi APP_URL dengan alamat yang beneran kejangkau publik dulu, baru jalanin ini lagi.');

            return self::FAILURE;
        }

        $kering = (bool) $this->option('dry-run');
        $berubah = 0;

        foreach (Certificate::whereNotNull('qr_token')->cursor() as $sertifikat) {
            $baru = "{$dasar}/verify/{$sertifikat->qr_token}";

            if ($sertifikat->qr_payload === $baru) {
                continue;
            }

            $this->line("  {$sertifikat->nomor}");
            $this->line("    dari : {$sertifikat->qr_payload}");
            $this->line("    jadi : {$baru}");

            if (! $kering) {
                $sertifikat->update(['qr_payload' => $baru]);
            }

            $berubah++;
        }

        if ($berubah === 0) {
            $this->info('Semua QR udah ngikut APP_URL — nggak ada yang perlu diubah.');

            return self::SUCCESS;
        }

        $this->info($kering
            ? "{$berubah} sertifikat AKAN diubah (dry-run, belum ada yang ditulis)."
            : "{$berubah} sertifikat diperbarui.");

        // Snapshot nyimpen `meta.qr_payload` juga. Sengaja NGGAK ikut diubah:
        // snapshot itu bekuan dokumen waktu diterbitin, dan ngubahnya berarti
        // nulis ulang sejarah. Halaman verifikasi & PDF baca `qr_payload` dari
        // kolomnya, jadi yang dicetak ulang tetap benar.
        $this->warn('Catatan: `snapshot.meta.qr_payload` sengaja dibiarin — itu bekuan dokumen saat terbit.');

        return self::SUCCESS;
    }
}

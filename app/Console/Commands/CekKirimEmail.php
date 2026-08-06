<?php

namespace App\Console\Commands;

use App\Support\Mailer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Nyoba kirim satu email beneran, buat mastiin setelan SMTP-nya udah bener.
 *
 * ## Kenapa perlu perintah sendiri
 *
 * Cara lain buat ngetes cuma "terbitin sertifikat, terus kirim ke pelanggan" —
 * mahal, dan kalau setelannya salah yang jadi kelinci percobaan alamat pelanggan
 * beneran. Ini ngetes jalur yang sama (mailer + host + kredensial) tanpa perlu
 * ada sertifikat, dan tujuannya alamat sendiri.
 *
 * Jalanin sesudah ngisi setelan MAIL_* di `.env`:
 *
 *     php artisan mail:cek saya@ptsidik.co.id
 *
 * Kalau `.env` baru diubah tapi hasilnya masih `log`, config-nya kecache:
 * `php artisan config:clear`.
 */
class CekKirimEmail extends Command
{
    protected $signature = 'mail:cek {ke : Alamat email tujuan buat percobaan}';

    protected $description = 'Kirim email percobaan buat mastiin setelan SMTP udah bener';

    public function handle(): int
    {
        $ke = (string) $this->argument('ke');

        if (! filter_var($ke, FILTER_VALIDATE_EMAIL)) {
            $this->error("`{$ke}` bukan alamat email yang valid.");

            return self::FAILURE;
        }

        $this->line('Setelan yang kepakai sekarang:');
        $this->line('  MAIL_MAILER : '.config('mail.default'));
        $this->line('  MAIL_HOST   : '.config('mail.mailers.smtp.host'));
        $this->line('  MAIL_PORT   : '.config('mail.mailers.smtp.port'));
        $this->line('  MAIL_USER   : '.(filled(config('mail.mailers.smtp.username')) ? '(terisi)' : '(KOSONG)'));
        $this->line('  DARI        : '.config('mail.from.address'));
        $this->newLine();

        // Dicek DULUAN, sebelum ngirim. Kalau nggak, `Mail::raw()` bakal "sukses"
        // buat mailer `log` dan perintah ini malah ikut ngasih kabar bohong —
        // persis penyakit yang mau dia deteksi.
        if (($mati = Mailer::takMengirim()) !== null) {
            $this->error("MAIL_MAILER masih `{$mati}` — emailnya nggak bakal keluar ke mana-mana.");
            $this->warn($mati === 'log'
                ? 'Isinya cuma ditulis ke storage/logs/laravel.log.'
                : 'Isinya cuma numpuk di memori, ilang begitu prosesnya selesai.');
            $this->newLine();
            $this->line('Ganti di `.env`: MAIL_MAILER=smtp, terus isi MAIL_HOST/PORT/USERNAME/PASSWORD.');
            $this->line('Sesudah itu: php artisan config:clear');

            return self::FAILURE;
        }

        if (config('mail.from.address') === 'hello@example.com') {
            $this->warn('MAIL_FROM_ADDRESS masih `hello@example.com` — banyak server nolak atau naruh ke spam.');
            $this->warn('Ganti ke alamat domain lab. Percobaannya tetap dilanjut.');
            $this->newLine();
        }

        $this->line("Ngirim percobaan ke {$ke} ...");

        try {
            Mail::raw(
                "Ini email percobaan dari SIDIK Calibration.\n\n"
                    ."Kalau kamu nerima ini, setelan SMTP-nya udah bener dan sertifikat\n"
                    ."udah bisa dikirim ke pelanggan.\n\n"
                    .'Dikirim: '.now()->format('d/m/Y H:i:s'),
                fn ($pesan) => $pesan->to($ke)->subject('Percobaan kirim — SIDIK Calibration'),
            );
        } catch (\Throwable $e) {
            $this->error('GAGAL: '.$e->getMessage());
            $this->newLine();
            $this->line('Yang paling sering jadi sebabnya:');
            $this->line('  - Gmail/Workspace butuh App Password, bukan password akun biasa');
            $this->line('  - Port 587 pakai TLS, port 465 pakai SSL — jangan ketuker');
            $this->line('  - Jaringan kantor sering nutup port SMTP keluar');

            return self::FAILURE;
        }

        $this->info("Kekirim. Cek inbox {$ke} (termasuk folder spam).");
        $this->line('Kalau nggak nyampe padahal di sini sukses, masalahnya di sisi penerima');
        $this->line('atau di reputasi domain pengirim, bukan di setelan aplikasi.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Mail\Pelanggan;

use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\UndanganPelanggan;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email berisi kode undangan anggota (REQ-AUTH-06).
 *
 * `Mailable`, bukan `Notification`, dengan alasan yang sama seperti
 * `KodeOtpEmail`: penerimanya BELUM punya akun sama sekali, jadi tidak ada
 * `Notifiable` yang bisa dituju — dan lonceng aplikasi yang menuliskan baris
 * `notifications` buat orang yang belum ada itu mustahil.
 */
class UndanganEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $kode,
        public string $namaPerusahaan,
        public string $peran,
        public int $berlakuHari = UndanganPelanggan::BERLAKU_HARI,
    ) {}

    /**
     * Kirim, dan JANGAN menggagalkan pemanggil kalau email-nya tidak sampai.
     *
     * Undangannya sudah tersimpan dan kodenya sudah terbit. Membalas 500 bikin
     * admin menekan "undang" lagi — dan undangan kedua membatalkan yang pertama
     * (lihat `KodeUndangan::terbitkan`), jadi kode yang mungkin sudah sampai ke
     * orangnya justru mati. Lebih baik satu email gagal dan bisa dikirim ulang.
     */
    public static function kirim(UndanganPelanggan $undangan, string $kode, Customer $perusahaan): void
    {
        try {
            Mail::to((string) $undangan->email)->send(new self(
                $kode,
                (string) $perusahaan->nama,
                (string) $undangan->peran,
            ));
        } catch (\Throwable $e) {
            Log::warning('Gagal mengirim email undangan anggota pelanggan.', [
                'undangan_id' => $undangan->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Undangan bergabung di SIDIK Pelanggan — '.$this->namaPerusahaan);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pelanggan.undangan',
            text: 'emails.pelanggan.undangan-teks',
            with: ['sebutanPeran' => $this->sebutanPeran()],
        );
    }

    public function sebutanPeran(): string
    {
        return $this->peran === CustomerMember::PERAN_PIC_UTAMA ? 'PIC utama' : 'staf';
    }
}

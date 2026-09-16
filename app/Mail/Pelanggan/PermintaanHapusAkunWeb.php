<?php

namespace App\Mail\Pelanggan;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Permintaan hapus akun yang masuk lewat halaman web (REQ-PRV-03).
 *
 * Memuat `user_id` supaya admin tidak perlu mencari orangnya dari email —
 * pencarian manual itu yang bikin permintaan mengendap melewati SLA 7 hari.
 */
class PermintaanHapusAkunWeb extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $email,
        public ?string $alasan,
        public int $userId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Permintaan hapus akun pelanggan — '.$this->email);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pelanggan.hapus-akun-web',
            text: 'emails.pelanggan.hapus-akun-web-teks',
        );
    }
}

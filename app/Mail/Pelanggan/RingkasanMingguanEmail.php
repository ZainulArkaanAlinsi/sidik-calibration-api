<?php

namespace App\Mail\Pelanggan;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Ringkasan mingguan (§42 B7) — dikirim `RingkasanMingguan` tiap Senin pagi.
 *
 * Mailable, bukan Notification: ringkasan tidak boleh menumpuk di lonceng
 * aplikasi (isinya sudah ada di beranda); yang dibutuhkan cuma satu email.
 * Tidak memuat data internal lab apa pun — isinya sama dengan kartu beranda
 * aplikasi pelanggan.
 */
class RingkasanMingguanEmail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{jatuh_tempo: list<array<string, mixed>>, sertifikat_baru: list<array<string, mixed>>, paket_berjalan: int, permintaan_aktif: int}  $isi
     */
    public function __construct(
        public string $nama,
        public string $perusahaan,
        public array $isi,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Ringkasan mingguan kalibrasi — {$this->perusahaan}");
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pelanggan.ringkasan-mingguan',
            text: 'emails.pelanggan.ringkasan-mingguan-teks',
        );
    }
}

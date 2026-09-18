<?php

namespace App\Mail\Pelanggan;

use App\Models\OtpPelanggan;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email berisi OTP 6 digit (REQ-AUTH-01/02, dan jalur lupa sandi).
 *
 * ## Kenapa Mailable, bukan Notification
 *
 * Penerimanya orang yang akunnya BELUM tentu aktif — dan `Notifiable::notify()`
 * di repo ini juga menulis baris `notifications` yang dibaca lonceng aplikasi.
 * Akun `pending_email` belum boleh punya isi lonceng sama sekali; yang dia
 * butuh cuma satu email. `Mail::to()` melakukan persis itu, tanpa efek samping.
 *
 * ## OTP tidak ikut ke log
 *
 * REQ-PRV-02 melarang OTP tersimpan di log server. Kodenya cuma lewat sebagai
 * properti Mailable; yang dicatat pemanggil hanya "dikirim ke user #N",
 * tanpa kodenya. Waktu `MAIL_MAILER=log` (dev) isi emailnya memang mendarat di
 * `storage/logs` — itu lingkungan lokal, dan disebut di sini supaya tidak
 * dikira aman buat produksi.
 */
class KodeOtpEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $nama,
        public string $kode,
        public string $tujuan,
        public int $berlakuMenit = OtpPelanggan::BERLAKU_MENIT,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->judul());
    }

    public function content(): Content
    {
        // `maksud` dititipkan lewat `with`, bukan dibaca dari method di view:
        // Mailable cuma menyalin PROPERTI publik ke view, jadi `$maksud` di
        // blade bakal undefined kalau dia tetap berupa method.
        return new Content(
            view: 'emails.pelanggan.otp',
            text: 'emails.pelanggan.otp-teks',
            with: ['maksud' => $this->maksud()],
        );
    }

    public function judul(): string
    {
        return $this->tujuan === OtpPelanggan::TUJUAN_ATUR_ULANG_SANDI
            ? 'Kode atur ulang sandi SIDIK Pelanggan'
            : 'Kode verifikasi email SIDIK Pelanggan';
    }

    /** Kalimat pembuka yang berbeda per tujuan — dipakai dua view sekaligus. */
    public function maksud(): string
    {
        return $this->tujuan === OtpPelanggan::TUJUAN_ATUR_ULANG_SANDI
            ? 'mengatur ulang sandi'
            : 'memverifikasi alamat email';
    }
}

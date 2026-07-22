<?php

namespace App\Mail;

use App\Models\Certificate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Kirim PDF sertifikat ke pelanggan.
 *
 * Dikirim dari backend, bukan dari HP teknisi, karena dua hal: alamat
 * pengirimnya harus domain lab (bukan Gmail pribadi), dan pengirimannya wajib
 * tercatat buat audit — lihat `CertificateEmail`.
 */
class SertifikatKalibrasi extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Certificate $sertifikat) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Sertifikat Kalibrasi '.$this->sertifikat->nomor,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.sertifikat',
            with: [
                'sertifikat' => $this->sertifikat,
                'sesi' => $this->sertifikat->session,
                'organisasi' => $this->sertifikat->organization,
            ],
        );
    }

    /**
     * PDF-nya dilampirkan langsung, bukan dikirim sebagai tautan unduh.
     * Tautan unduh butuh login, dan pelanggan nggak punya akun di sistem ini.
     *
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $path = $this->sertifikat->pdf_path;

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('local', $path)
                // Nomor sertifikat ada slash-nya (CAL/2026/07/0001) — nggak
                // boleh jadi nama file.
                ->as('Sertifikat-'.str_replace('/', '-', $this->sertifikat->nomor).'.pdf')
                ->withMime('application/pdf'),
        ];
    }
}

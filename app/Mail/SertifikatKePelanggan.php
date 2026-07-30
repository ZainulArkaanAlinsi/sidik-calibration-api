<?php

namespace App\Mail;

use App\Models\Certificate;
use App\Models\CertificateEmailLog;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email sertifikat ke pelanggan (fase-2 §3d).
 *
 * Pakai `Mailable` Laravel langsung, BUKAN lapisan `EmailService` sendiri: facade
 * `Mail` itu udah abstraksinya (transport-nya ditentukan `MAIL_MAILER`, bisa
 * di-`fake()` buat test), jadi lapisan tambahan di atasnya cuma nambah kode tanpa
 * nambah kemampuan.
 *
 * ## Soal alamat pengirim
 *
 * Permintaannya: *"alamat pengirim harus domain lab (bukan Gmail teknisi)."* Itu
 * dipenuhi lewat `MAIL_FROM_ADDRESS` di `.env` — **bukan** dengan naruh
 * `organization.email` di `From`.
 *
 * Alasannya teknis dan penting: `From` yang domainnya beda dari domain yang
 * beneran ngirim bikin SPF/DKIM gagal, dan email-nya masuk spam atau ditolak
 * server penerima. Jadi `From` = domain pengirim yang beneran, dan
 * `organization.email` ditaruh di **`Reply-To`** — jadi balasan pelanggan tetap
 * nyampe ke lab, tanpa ngerusak pengirimannya.
 */
class SertifikatKePelanggan extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * `$salinanKe`, bukan `$cc`: `Mailable` induknya UDAH punya properti `$cc`
     * (tanpa tipe), dan PHP nolak properti anak yang nambahin tipe di atas properti
     * induk yang nggak bertipe. Ketemu waktu test pertama fatal error.
     *
     * @param  list<string>  $salinanKe
     * @param  string  $format  `pdf` | `xlsx` | `tautan` — lihat konstanta di
     *                          `CertificateEmailLog`.
     * @param  string|null  $berkasXlsx  Path absolut berkas Excel yang udah dibikin
     *                                   pemanggil. Excel dirakit on-the-fly (nggak
     *                                   disimpen kayak PDF), jadi yang bikin dia
     *                                   juga yang tanggung jawab ngehapus.
     */
    public function __construct(
        public Certificate $sertifikat,
        public array $salinanKe = [],
        public string $format = CertificateEmailLog::FORMAT_PDF,
        public ?string $berkasXlsx = null,
    ) {}

    public function envelope(): Envelope
    {
        $organisasi = $this->sertifikat->organization;
        $nomor = $this->sertifikat->nomor ?? '-';

        return new Envelope(
            subject: "Sertifikat Kalibrasi {$nomor}"
                .($organisasi?->nama ? " — {$organisasi->nama}" : ''),
            cc: $this->salinanKe,
            // Lihat penjelasan di docblock kelas: `From` tetap domain pengirim,
            // email lab ditaruh di sini biar balasan nyampe ke lab.
            replyTo: filled($organisasi?->email)
                ? [new Address($organisasi->email, (string) $organisasi->nama)]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            // `markdown:`, BUKAN `view:`. Templatenya pakai komponen
            // `<x-mail::message>`, dan komponen itu cuma kedaftar di jalur markdown
            // mailable — lewat `view:` dia gagal "No hint path defined for [mail]".
            markdown: 'emails.sertifikat',
            with: [
                'sertifikat' => $this->sertifikat,
                'organisasi' => $this->sertifikat->organization,
                'sesi' => $this->sertifikat->session,
                'alat' => $this->sertifikat->session?->equipment,
                'pelanggan' => $this->sertifikat->session?->equipment?->customer,
                'format' => $this->format,
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        // Berkasnya diambil dari disk PRIVAT dan DILAMPIRKAN — bukan dikirim
        // sebagai tautan unduh. Tautan ke disk privat butuh login, dan pelanggan
        // nggak punya akun; tautan publik berarti sertifikat bisa diakses siapa
        // pun yang dapat URL-nya. Lampiran itu jalan yang bener buat dokumen resmi.
        //
        // Kalau berkasnya nggak ada, dikirim TANPA lampiran nggak masuk akal —
        // jadi pemanggilnya (`CertificateController`) yang nolak duluan.
        return match ($this->format) {
            // Format `tautan` emang sengaja tanpa lampiran: yang dikirim cuma
            // alamat verifikasi, dan halaman itu sendiri yang nyediain unduhan.
            CertificateEmailLog::FORMAT_TAUTAN => [],

            CertificateEmailLog::FORMAT_XLSX => filled($this->berkasXlsx) ? [
                Attachment::fromPath($this->berkasXlsx)
                    ->as($this->sertifikat->namaFile('xlsx'))
                    ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            ] : [],

            default => filled($this->sertifikat->pdf_path) ? [
                Attachment::fromStorageDisk('local', $this->sertifikat->pdf_path)
                    ->as($this->sertifikat->namaFile('pdf'))
                    ->withMime('application/pdf'),
            ] : [],
        };
    }
}

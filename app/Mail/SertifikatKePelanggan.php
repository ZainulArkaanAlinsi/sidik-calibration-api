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
use Illuminate\Support\Facades\Storage;

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
            // `view:` + `text:`, bukan `markdown:` lagi. Templatenya sekarang HTML
            // email beneran (tabel + gaya inline) supaya tata letaknya kepegang di
            // Gmail/Outlook — komponen `<x-mail::message>` nggak dipakai sama
            // sekali, jadi jalur markdown-nya nggak diperlukan.
            //
            // `text:` WAJIB diisi sendiri: dulu versi teksnya digenerate otomatis
            // oleh mailable markdown. Tanpa ini yang terkirim cuma HTML, dan
            // sebagian filter spam menaikkan skor email yang nggak punya
            // alternatif teks.
            view: 'emails.sertifikat',
            text: 'emails.sertifikat-teks',
            with: [
                'sertifikat' => $this->sertifikat,
                'organisasi' => $this->sertifikat->organization,
                'sesi' => $this->sertifikat->session,
                'alat' => $this->sertifikat->session?->equipment,
                'pelanggan' => $this->sertifikat->session?->equipment?->customer,
                'format' => $this->format,
                'logo' => $this->berkasLogo(),
            ],
        );
    }

    /**
     * Path absolut logo lab, atau `null` kalau belum diunggah / berkasnya hilang.
     *
     * Dilampirkan inline (CID) lewat `$message->embed()` di view, BUKAN ditaruh
     * sebagai `<img src="https://...">`. Dua alasannya: logo ada di disk `public`
     * yang selama `APP_URL` masih lokal nggak kejangkau penerima sama sekali, dan
     * Gmail memblokir gambar dari sumber luar sampai penerima menekan "tampilkan
     * gambar" — kop surat yang baru muncul setelah diklik sama saja tidak ada.
     */
    private function berkasLogo(): ?string
    {
        $path = $this->sertifikat->organization?->logo_path;

        if (blank($path)) {
            return null;
        }

        $absolut = Storage::disk('public')->path($path);

        return is_file($absolut) ? $absolut : null;
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

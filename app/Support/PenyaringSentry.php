<?php

namespace App\Support;

use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Frame;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Penyaring privasi KERAS untuk Sentry (paket OPS-KODE butir 2).
 *
 * Dipasang di `config/sentry.php` sebagai `before_send` dan `before_breadcrumb`.
 * Jalan SESUDAH semua integrasi menambahkan datanya (permintaan, konteks
 * Laravel, `exception->context()`), jadi yang dilihatnya adalah muatan yang
 * hampir berangkat — dan tugasnya memastikan yang berangkat hanya yang
 * diizinkan. Pengaturan di config tidak cukup sendirian: integrasi baru atau
 * versi paket baru bisa menambahkan data tanpa kita sadari.
 *
 * ## Pendekatan: daftar IZIN, bukan daftar larangan
 *
 * Yang tidak disebut di sini dibuang. Konteks baru dari paket yang diperbarui
 * besok otomatis dibuang sampai ada yang sengaja mengizinkannya.
 *
 * ## Yang dibuang
 *
 *  - pengguna (id, surel, nama, IP);
 *  - seluruh `request` kecuali metodenya (alamat bisa memuat token QR atau
 *    id; rutenya sudah ada sebagai `transaction`, dalam bentuk templat);
 *  - seluruh `extra` dan `contexts` kecuali `trace`/`runtime`/`os` — di sinilah
 *    `Context` Laravel dan `exception->context()` mendarat, dan keduanya bisa
 *    berisi data lab;
 *  - tag di luar daftar izin, lampiran, serta variabel dan baris kode sumber
 *    di tiap frame (berkas, baris, dan fungsi tetap ada);
 *  - remah selain SQL, dan SQL tanpa nilai binding & tanpa literal.
 *
 * ## Yang disamarkan (pola, bukan daftar nama)
 *
 * Pesan galat dan nilai pengecualian: surel, token Bearer, token Sanctum
 * (`12|abc...`), nomor sertifikat, string buram panjang (token QR, hash, kunci),
 * alamat IP, dan seluruh bagian `(Connection: ..., SQL: ...)` yang ditambahkan
 * Laravel ke `QueryException` (memuat nama host, basis data, dan SQL dengan nilai
 * terisi).
 *
 * ## Yang TIDAK bisa dijamin pola
 *
 * Teks bebas yang ditulis kode kita sendiri ke pesan pengecualian (mis. nama
 * pelanggan) tidak punya bentuk yang bisa dikenali. Aturan untuk penulis kode:
 * jangan menyisipkan data pelanggan/lembar ke pesan `throw`. Itu dicatat di
 * docs/operasional/kesehatan-sentry-saklar.md.
 *
 * ## Galat yang bukan gangguan
 *
 * `ignore_exceptions` di config menutup kelas-kelasnya; di sini ditambah SEMUA
 * HttpException ber-status 4xx (403, 405, 409, 419, 429, ...) karena daftar kelas
 * tidak bisa mencakupnya satu per satu.
 */
final class PenyaringSentry
{
    /** Konteks yang boleh tinggal: teknis, tanpa data lab. */
    private const KONTEKS_BOLEH = ['trace', 'runtime', 'os'];

    /** Tag yang boleh tinggal. */
    private const TAG_BOLEH = ['runtime', 'runtime.name', 'sapi', 'transaction'];

    /** Satu-satunya kategori remah yang diizinkan. */
    private const REMAH_BOLEH = 'sql.query';

    /** Metadata remah SQL yang boleh tinggal (tanpa `bindings`). */
    private const METADATA_REMAH_BOLEH = ['connectionName', 'executionTimeMs'];

    private const PENGGANTI_KONTEKS = ['disamarkan' => 'ya'];

    /**
     * `before_send`. Mengembalikan `null` = peristiwa dibuang.
     */
    public static function sebelumKirim(Event $event, ?EventHint $hint = null): ?Event
    {
        if ($hint?->exception !== null && self::bukanGangguan($hint->exception)) {
            return null;
        }

        $event->setUser(null);

        // Hanya metode HTTP-nya; alamat, header, cookie, query, dan badan dibuang.
        $metode = $event->getRequest()['method'] ?? null;
        $event->setRequest(is_string($metode) ? ['method' => $metode] : []);

        $event->setExtra([]);

        // `Event` tidak punya "hapus konteks"; menimpa dengan penanda lewat API
        // publik lebih tahan versi daripada menyentuh properti privatnya.
        foreach (array_keys($event->getContexts()) as $nama) {
            if (! in_array($nama, self::KONTEKS_BOLEH, true)) {
                $event->setContext((string) $nama, self::PENGGANTI_KONTEKS);
            }
        }

        $event->setTags(array_intersect_key($event->getTags(), array_flip(self::TAG_BOLEH)));
        $event->setAttachments([]);

        if ($event->getMessage() !== null) {
            $event->setMessage(
                self::samarkan($event->getMessage()),
                [],
                $event->getMessageFormatted() === null ? null : self::samarkan($event->getMessageFormatted()),
            );
        }

        foreach ($event->getExceptions() as $galat) {
            $galat->setValue(self::samarkan($galat->getValue()));

            foreach ($galat->getStacktrace()?->getFrames() ?? [] as $frame) {
                self::bersihkanFrame($frame);
            }
        }

        foreach ($event->getStacktrace()?->getFrames() ?? [] as $frame) {
            self::bersihkanFrame($frame);
        }

        $event->setBreadcrumb(array_values(array_filter(
            array_map(self::sebelumBreadcrumb(...), $event->getBreadcrumbs()),
        )));

        return $event;
    }

    /**
     * `before_breadcrumb`. Hanya SQL; nilainya (literal dan binding) dibuang.
     */
    public static function sebelumBreadcrumb(Breadcrumb $remah): ?Breadcrumb
    {
        if ($remah->getCategory() !== self::REMAH_BOLEH) {
            return null;
        }

        foreach (array_keys($remah->getMetadata()) as $kunci) {
            if (! in_array($kunci, self::METADATA_REMAH_BOLEH, true)) {
                $remah = $remah->withoutMetadata((string) $kunci);
            }
        }

        if ($remah->getMessage() !== null) {
            $remah = $remah->withMessage(self::samarkan(self::buangLiteralSql($remah->getMessage())));
        }

        return $remah;
    }

    /**
     * Galat yang bukan gangguan sistem: HTTP 4xx. Kelas-kelas khusus
     * (ValidationException, dst.) ditutup `ignore_exceptions` di config; ini
     * jaring untuk sisanya, dan bisa dites tanpa klien.
     */
    public static function bukanGangguan(Throwable $galat): bool
    {
        return $galat instanceof HttpExceptionInterface
            && $galat->getStatusCode() >= 400
            && $galat->getStatusCode() < 500;
    }

    /**
     * Samarkan pola data sensitif di teks bebas. Urutannya penting: pesan driver
     * DB dipotong lebih dulu, lalu bagian `(Connection: ...)` dan Bearer dihapus
     * karena memuat pola lain (surel, token) di dalamnya.
     */
    public static function samarkan(string $teks): string
    {
        $teks = self::potongPesanDriver($teks);

        $pola = [
            // QueryException: nama host, basis data, dan SQL dengan nilai terisi.
            '/\(Connection:.*$/s' => '(Connection: [disamarkan])',
            '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i' => 'Bearer [token]',
            // Token Sanctum polos: `<id>|<40 karakter>`.
            '/\b\d{1,10}\|[A-Za-z0-9]{20,}\b/' => '[token]',
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => '[email]',
            // CAL/2026/09/0011, CAL/2026/09/0011-R2 (awalan dapat diatur lab).
            '/\b[A-Za-z][A-Za-z0-9._\-]{1,19}\/\d{4}\/\d{1,2}\/\d{2,6}(?:-R\d+)?\b/' => '[nomor-sertifikat]',
            // String buram panjang: token QR, hash, kunci.
            '/\b[A-Za-z0-9_\-]{32,}\b/' => '[token]',
            '/\b\d{1,3}(?:\.\d{1,3}){3}\b/' => '[ip]',
        ];

        return (string) preg_replace(array_keys($pola), array_values($pola), $teks);
    }

    /**
     * Pesan galat driver DB memantulkan NILAI: `Duplicate entry '1-PT Maju Jaya'
     * for key ...`, `Incorrect decimal value: '12,345' for column ...`,
     * `Key (nama)=(PT X) already exists`, nama host pada galat koneksi. Kolom
     * yang unik di sini adalah nama pelanggan dan nomor seri alat, dan bacaan
     * lembar masuk lewat kolom desimal — persis yang dilarang pemilik. Pola per
     * frasa tidak akan pernah lengkap (tiap driver dan versi punya kalimatnya
     * sendiri), jadi yang dipertahankan cuma bagian yang tidak berisi data:
     * `SQLSTATE[xxxxx]` beserta kategori dan kode galat driver
     * (`SQLSTATE[23000]: Integrity constraint violation: 1062`, atau
     * `SQLSTATE[HY000] [2002]` untuk galat koneksi). Sisanya diganti penanda.
     * Itu cukup untuk mendiagnosis (jenis galat, kode, berkas, baris); nilainya
     * ada di database, bukan di Sentry.
     *
     * Berlaku untuk teks apa pun yang memuat `SQLSTATE[`: pengecualian utama,
     * `previous` bersarang (tiap nilai di rantai lewat `samarkan`), pesan
     * peristiwa, dan `logentry`.
     */
    private static function potongPesanDriver(string $teks): string
    {
        if (! preg_match(
            '/SQLSTATE\[[A-Za-z0-9]+\](?:\s*\[\d+\]|:\s*[A-Za-z][^:\n]{0,80}?:\s*\d+)?/',
            $teks,
            $cocok,
            PREG_OFFSET_CAPTURE,
        )) {
            return $teks;
        }

        $akhir = $cocok[0][1] + strlen($cocok[0][0]);

        return substr($teks, 0, $akhir).(trim(substr($teks, $akhir)) === '' ? '' : ' [rincian disamarkan]');
    }

    /**
     * Frame hanya menyisakan berkas, baris, dan fungsi: variabel dan baris
     * kode sumber (yang memuat literal) dibuang. `context_lines => null` di
     * config sudah mencegahnya; ini jaring kalau ada integrasi yang mengisinya.
     */
    private static function bersihkanFrame(Frame $frame): void
    {
        $frame->setVars([]);
        $frame->setPreContext([]);
        $frame->setContextLine(null);
        $frame->setPostContext([]);
    }

    /** Literal string di dalam SQL (`'abc'`) diganti `'?'`: sisanya struktur. */
    private static function buangLiteralSql(string $sql): string
    {
        return (string) preg_replace("/'(?:[^'\\\\]|\\\\.)*'/", "'?'", $sql);
    }
}

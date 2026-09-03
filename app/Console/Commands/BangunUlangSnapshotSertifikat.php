<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use App\Services\CalibrationValidator;
use App\Services\CertificateSnapshotBuilder;
use App\Services\DataTampilanSertifikat;
use App\Services\SertifikatSatuHalaman;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Bangun ulang `snapshot` + `validasi` + PDF sertifikat yang UDAH terbit,
 * tanpa nyentuh nomor & QR-nya.
 *
 * ## Kenapa perlu
 *
 * `snapshot` itu beku: `GenerateCertificate` nyusunnya SEKALI waktu approve,
 * dan layar mobile & halaman verifikasi baca dari situ. Jadi begitu ada bug
 * olah data atau format yang dibetulin, sertifikat yang terlanjur terbit tetap
 * nampilin angka lama SELAMANYA — nggak ada jalan buat merunya.
 *
 * Kelihatan jelas di database 9 Agt 2026: tiga generasi format kelembaban masih
 * hidup berdampingan di sesi-sesi yang beda, semuanya salah dengan cara yang
 * beda-beda.
 *
 *     sesi 24   %RH: 60% ± 5,2%
 *     sesi 27   %RH: 60% ± 5%
 *     sesi 28   %RH: 54% ± 6%
 *
 * `GenerateCertificate` sendiri nggak bisa dipakai buat ini — dia sengaja
 * idempoten (`status === terbit` → langsung `return`), dan kalau penjaganya
 * dilewatin, `updateOrCreate`-nya bakal ngasih NOMOR BARU dan QR TOKEN BARU.
 * Itu bukan terbit ulang, itu nerbitin sertifikat lain.
 *
 * ## Yang SENGAJA nggak disentuh
 *
 * `nomor`, `qr_token`, `qr_payload`, `diterbitkan_pada`, `berlaku_sampai`,
 * `issued_by`. Alasannya sama kayak [PerbaikiQrSertifikat]: nomor & QR itu
 * identitas dokumen terkendali yang mungkin udah dicetak dan dikirim ke
 * pelanggan. Yang dibangun ulang cuma ISI-nya, dari data sesi yang sama.
 *
 * ## Ini BUKAN alat buat ngubah hasil
 *
 * Perintah ini nyusun ulang dari data sesi APA ADANYA. Kalau pembacaannya
 * sendiri yang salah ketik, betulin dulu lewat alur revisi di aplikasi —
 * jangan diakalin dari sini.
 *
 * Aman diulang: hasilnya cuma bergantung data sesi + kode yang lagi jalan.
 */
class BangunUlangSnapshotSertifikat extends Command
{
    protected $signature = 'sertifikat:bangun-ulang
        {sesi?* : Nomor sesi tertentu (kosong = semua yang udah terbit)}
        {--dry-run : Cuma nampilin yang bakal berubah, nggak nulis apa-apa}
        {--render-ulang-pdf : Tulis ulang PDF-nya walau snapshot-nya nggak berubah}';

    protected $description = 'Bangun ulang snapshot & PDF sertifikat terbit, nomor & QR-nya tetap';

    public function handle(
        CertificateSnapshotBuilder $snapshotBuilder,
        CalibrationValidator $validator,
        DataTampilanSertifikat $tampilan,
        SertifikatSatuHalaman $satuHalaman,
    ): int {
        $nomorSesi = (array) $this->argument('sesi');
        $keringMode = (bool) $this->option('dry-run');
        $renderUlangPdf = (bool) $this->option('render-ulang-pdf');

        $daftar = Certificate::query()
            ->where('status', Certificate::STATUS_TERBIT)
            ->when($nomorSesi !== [], fn ($q) => $q->whereHas(
                'session',
                fn ($s) => $s->whereIn('nomor_sesi', $nomorSesi),
            ))
            ->with('session.equipment.customer', 'session.organization', 'session.teknisi',
                'session.reviewer', 'session.standard', 'session.thermohygro',
                'session.uncertaintyCalculations.standard')
            ->get();

        if ($daftar->isEmpty()) {
            $this->warn('Nggak ada sertifikat terbit yang cocok.');

            return self::SUCCESS;
        }

        $berubah = 0;
        $gagal = 0;

        // Dihitung TERPISAH dari `$gagal`, dan sengaja tidak ikut menentukan
        // kode keluar: dilewati-dengan-sengaja bukan kegagalan. Menyamakannya
        // bikin sapuan massal yang berjalan persis seperti seharusnya keluar
        // dengan status error — dan status error yang rutin muncul berhenti
        // dibaca orang.
        $dilewati = 0;

        foreach ($daftar as $sertifikat) {
            $sesi = $sertifikat->session;

            if ($sesi === null) {
                $this->warn("{$sertifikat->nomor}: sesinya nggak ada, dilewati.");

                continue;
            }

            $lama = $sertifikat->snapshot['header']['env_condition'] ?? '—';

            try {
                $baru = $snapshotBuilder->bangun($sesi, $sertifikat);
            } catch (Throwable $e) {
                $this->error("{$sertifikat->nomor}: gagal nyusun snapshot — {$e->getMessage()}");
                $gagal++;

                continue;
            }

            // PENJAGA PENANDATANGAN — dipasang sesudah temuan review 3 Sep 2026.
            //
            // `footer()` di [CertificateSnapshotBuilder] mengambil nama
            // penandatangan dari setelan organisasi YANG BERLAKU SEKARANG. Jadi
            // sertifikat yang dulu ditandatangani orang lain, kalau dibangun
            // ulang, diam-diam berganti nama jadi penandatangan sekarang —
            // dokumen menyatakan seseorang menandatangani sesuatu yang tidak
            // pernah dia tandatangani, dan nol error muncul.
            //
            // Tombol "Cetak ulang PDF" di panel sudah menolak kasus ini
            // ([CetakUlangSertifikat]); perintah ini dulu tidak, padahal dia
            // yang dipakai buat sapuan massal — persis di mana kerusakannya
            // paling luas.
            //
            // DITOLAK, bukan dipertahankan diam-diam: gambar tanda tangannya
            // dibaca live tiap render, jadi mempertahankan nama lama justru
            // menempelkan tanda tangan orang baru di atas nama lama. Melewati
            // yang bentrok tidak mengerjakan apa-apa; itu yang aman.
            $alasanTtd = self::penandatanganBeda($sertifikat->snapshot, $baru);

            if ($alasanTtd !== null) {
                $this->warn("{$sertifikat->nomor}: {$alasanTtd}");
                $dilewati++;

                continue;
            }

            $envBaru = $baru['header']['env_condition'] ?? '—';
            $sama = $baru == $sertifikat->snapshot;

            $this->line(sprintf(
                '%-18s %-14s %s',
                $sertifikat->nomor,
                $sesi->nomor_sesi ?? '-',
                $sama ? 'nggak berubah' : "{$lama}  →  {$envBaru}",
            ));

            // Snapshot-nya sama, tapi CARA merendernya berubah.
            //
            // Perbaikan tata letak (mis. penjamin satu halaman, atau penjepit
            // ukuran tanda tangan) nggak menyentuh snapshot sama sekali — jadi
            // tanpa jalur ini, sertifikat lama yang PDF-nya masih beredar dan
            // masih dua halaman nggak akan pernah kesentuh perintah ini.
            //
            // Yang ditulis ulang CUMA berkasnya. Snapshot-nya sengaja nggak
            // di-`update`: dia identik, dan menulis ulang baris yang nggak
            // berubah cuma bikin jejak audit yang membingungkan.
            if ($sama && $renderUlangPdf && ! $keringMode && (string) $sertifikat->pdf_path !== '') {
                try {
                    $isiPdf = $satuHalaman->isi($sertifikat);

                    if (Storage::disk('arsip')->put($sertifikat->pdf_path, $isiPdf) === false) {
                        throw new RuntimeException("gagal nulis PDF ke {$sertifikat->pdf_path}");
                    }

                    $this->line('  └─ PDF ditulis ulang (snapshot nggak berubah)');
                    $berubah++;
                } catch (Throwable $e) {
                    $this->error("{$sertifikat->nomor}: gagal nulis ulang PDF — {$e->getMessage()}");
                    $gagal++;
                }

                continue;
            }

            if ($keringMode || $sama) {
                continue;
            }

            try {
                $sertifikat->update([
                    'snapshot' => $baru,
                    'validasi' => $validator->periksa($sesi),
                ]);

                // PDF ikut ditulis ulang — kalau nggak, lembar yang dipegang
                // pelanggan beda dari yang muncul waktu QR-nya discan, dan itu
                // justru yang paling dijaga di `GenerateCertificate`.
                //
                // Yang belum punya `pdf_path` dilewati BERKASNYA doang, bukan
                // dibatalin seluruhnya: snapshot-nya tetap yang dibaca layar &
                // halaman verifikasi, jadi mbetulin itu udah ada gunanya. Baris
                // tanpa PDF itu wajar ada — sertifikat demo, atau yang
                // generate-nya dulu gagal di tengah.
                if ((string) $sertifikat->pdf_path !== '') {
                    $isiPdf = $satuHalaman->isi($sertifikat->fresh());

                    // Tulis yang gagal balik `false` tanpa exception (disknya
                    // `throw => false`). Tanpa penjagaan ini perintahnya
                    // ngelaporin baris itu sebagai berhasil dibangun ulang,
                    // padahal PDF lamanya masih yang beredar — dan justru
                    // ketidakcocokan itu yang perintah ini ada buat mbetulin.
                    if (Storage::disk('arsip')->put($sertifikat->pdf_path, $isiPdf) === false) {
                        throw new RuntimeException("gagal nulis PDF ke {$sertifikat->pdf_path}");
                    }
                } else {
                    $this->warn("  {$sertifikat->nomor}: snapshot dibangun ulang, PDF dilewati (pdf_path kosong).");
                }

                $berubah++;
            } catch (Throwable $e) {
                $this->error("{$sertifikat->nomor}: gagal nulis — {$e->getMessage()}");
                $gagal++;
            }
        }

        $this->newLine();
        $this->info($keringMode
            ? 'Dry run — nggak ada yang ditulis.'
            : "{$berubah} sertifikat dibangun ulang, {$dilewati} dilewati, {$gagal} gagal.");

        return $gagal > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Kenapa sertifikat ini tidak boleh dibangun ulang, atau `null` kalau boleh.
     *
     * Yang dibandingkan NAMA-nya saja, bukan jabatannya: jabatan berganti tanpa
     * ganti orang itu wajar (promosi, penataan ulang struktur) dan tidak bikin
     * tanda tangannya jadi milik orang lain. Alasan yang sama dipakai
     * [\App\Services\CetakUlangSertifikat].
     *
     * Snapshot lama yang footernya belum punya nama dibiarkan lewat: menolak
     * berdasarkan data yang memang tidak pernah ada cuma memblokir sertifikat
     * yang sebenarnya baik-baik saja.
     *
     * @param  array<string, mixed>|null  $lama
     * @param  array<string, mixed>  $baru
     */
    private static function penandatanganBeda(?array $lama, array $baru): ?string
    {
        $beku = trim((string) ($lama['footer']['penandatangan'] ?? ''));

        if ($beku === '') {
            return null;
        }

        $sekarang = trim((string) ($baru['footer']['penandatangan'] ?? ''));

        if ($sekarang === '' || mb_strtolower($beku) === mb_strtolower($sekarang)) {
            return null;
        }

        return sprintf(
            'DILEWATI — penandatangannya sudah ganti. Beku atas nama "%s", '
            .'yang berlaku sekarang "%s". Membangun ulang bakal nimpa nama di '
            .'dokumen yang sudah terbit.',
            $beku,
            $sekarang,
        );
    }
}

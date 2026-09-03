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

    /** Kunci yang dapat bentuk panah `lama → baru`, bukan cuma disebut namanya. */
    private const JALUR_ENV = 'header.env_condition';

    /**
     * Berapa nama kunci yang muat sebelum sisanya diringkas jadi "+N lagi".
     *
     * Snapshot punya 15 kunci tingkat-atas dan `header` sendiri belasan. Sapuan
     * yang menyusul perubahan format bisa menyentuh hampir semuanya sekaligus,
     * dan satu baris sepanjang itu berhenti dibaca orang — persis lawan dari
     * yang perbaikan ini kejar.
     */
    private const KUNCI_DITAMPILKAN = 6;

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
                $sama
                    ? 'nggak berubah'
                    : self::ringkasanPerubahan($sertifikat->snapshot, $baru, $lama, $envBaru),
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
     * Apa yang berubah di snapshot, dalam satu baris.
     *
     * ## Kenapa bukan cuma Env. Condition
     *
     * Versi pertama SELALU mencetak `{env lama}  →  {env baru}`, padahal yang
     * menentukan berubah-atau-nggak itu perbandingan SELURUH snapshot. Jadi
     * tiap perubahan di luar kondisi lingkungan tercetak sebagai dua sisi yang
     * IDENTIK — dan "berubah, tapi kiri-kanan sama persis" itu wajar dibaca
     * sebagai perintahnya yang rusak.
     *
     * Kejadian 3 Sep 2026, sapuan yang menyalakan U95 per titik: lima baris
     * keluar dengan panah yang dua sisinya sama, dan setengah jam habis cuma
     * buat memastikan itu bukan bug. Nol error muncul — dan itu justru sebabnya
     * ditulis di sini. Keluaran yang menyesatkan tidak pernah merah.
     *
     * @param  array<string, mixed>|null  $snapLama
     * @param  array<string, mixed>  $snapBaru
     */
    private static function ringkasanPerubahan(
        ?array $snapLama,
        array $snapBaru,
        string $envLama,
        string $envBaru,
    ): string {
        $kunci = self::kunciBerubah((array) $snapLama, $snapBaru);

        // Kondisi lingkungan tetap dapat bentuk panah, bukan cuma disebut
        // namanya: dia yang paling sering berubah, dan nilainya yang bikin
        // barisnya berguna dibaca sekilas.
        if (in_array(self::JALUR_ENV, $kunci, true)) {
            $lainnya = array_values(array_diff($kunci, [self::JALUR_ENV]));

            return "{$envLama}  →  {$envBaru}"
                .($lainnya === [] ? '' : '  (+ '.self::daftarKunci($lainnya).')');
        }

        // Mustahil kosong selama perbandingannya sama-sama `==`, tapi kalau
        // suatu saat beda, yang keluar tetap kalimat yang jujur — bukan panah
        // yang dua sisinya sama, persis yang perbaikan ini hapus.
        return $kunci === []
            ? 'berubah (kuncinya sama, isinya beda tipe)'
            : 'berubah: '.self::daftarKunci($kunci);
    }

    /**
     * Jalur kunci yang isinya beda antara dua snapshot.
     *
     * Turun ke dalam SELAMA kedua sisi masih array — termasuk array berindeks
     * angka seperti `hasil`, yang isinya baris tabel.
     *
     * ## Kenapa baris tabel ikut ditelusuri, padahal semula sengaja tidak
     *
     * Versi pertama berhenti di array berindeks angka, dengan alasan
     * `hasil.7.u95` bikin satu baris ringkasan jadi sepanjang tabelnya. Alasan
     * itu terdengar masuk akal sampai dipakai.
     *
     * 3 Sep 2026, sapuan pertama di produksi: satu sertifikat Spectrophotometer
     * dilaporkan `berubah: hasil` dua jalan berturut-turut — tidak pernah
     * konvergen — sementara delapan lainnya diam. `hasil` menyebut BAGIAN-nya,
     * tapi tabel itu 24 baris dengan sepuluh kolom, dan tanpa menyebut sel mana
     * tidak ada yang bisa dikerjakan dari situ. Diagnosisnya berhenti di
     * keluaran yang seharusnya menolong.
     *
     * Kekhawatiran soal panjang tetap ditangani, tapi di tempat yang benar:
     * [daftarKunci] memotong di [KUNCI_DITAMPILKAN] nama pertama.
     *
     * Pembandingnya `==`, sama persis dengan yang dipakai memutuskan
     * berubah-atau-nggak di `handle()`. Kalau dua-duanya tidak sama, ringkasan
     * ini bisa bilang "berubah" tanpa bisa menyebut apa.
     *
     * @param  array<string, mixed>  $lama
     * @param  array<string, mixed>  $baru
     * @return list<string>
     */
    private static function kunciBerubah(array $lama, array $baru, string $awalan = ''): array
    {
        $keluar = [];

        foreach (array_keys($lama + $baru) as $kunci) {
            $jalur = $awalan === '' ? (string) $kunci : "{$awalan}.{$kunci}";
            $a = $lama[$kunci] ?? null;
            $b = $baru[$kunci] ?? null;

            if ($a == $b) {
                continue;
            }

            if (is_array($a) && is_array($b) && $a !== [] && $b !== []) {
                $keluar = array_merge($keluar, self::kunciBerubah($a, $b, $jalur));

                continue;
            }

            $keluar[] = $jalur;
        }

        return $keluar;
    }

    /**
     * Daftar kunci, dipotong biar barisnya tetap muat dibaca sekilas.
     *
     * @param  list<string>  $kunci
     */
    private static function daftarKunci(array $kunci): string
    {
        $tampil = array_slice($kunci, 0, self::KUNCI_DITAMPILKAN);
        $sisa = count($kunci) - count($tampil);

        return implode(', ', $tampil).($sisa > 0 ? " (+{$sisa} lagi)" : '');
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

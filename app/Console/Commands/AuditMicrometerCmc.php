<?php

namespace App\Console\Commands;

use App\Models\CalibrationSession;
use App\Services\Calibration\MicrometerCalculator;
use App\Services\Calibration\TabelStandarMicrometer;
use App\Support\MicrometerMentah;
use Illuminate\Console\Command;

/**
 * Melingkupi sertifikat Micrometer yang sudah terbit dengan U95 di bawah lantai
 * CMC pita rentangnya — plus dua cacat sunyi yang bentuk kegagalannya sama.
 *
 * ## Kenapa perintah ini ada
 *
 * `docs/analisis-pertanyaan-lab-micrometer.md` §1 langkah 2 meminta lab
 * "telusuri arsip, daftar sertifikat Micrometer yang U95-nya di bawah pita CMC
 * rentangnya". Selama itu kerjaan tangan, tinjauan ketidaksesuaiannya mandek —
 * bukan karena keputusannya sulit, tapi karena datanya belum ada di meja.
 *
 * Perintah ini yang menyediakan datanya. Yang MEMUTUSKAN tetap manajer teknis;
 * keluarannya bahan rapat, bukan tindakan.
 *
 * ## Tiga cacat yang dicari, dan kenapa bertiga
 *
 * Ketiganya punya bentuk kegagalan yang IDENTIK: satu komponen budget lenyap,
 * U95 jatuh, lalu lantai CMC menutupinya sehingga yang tercetak tampak wajar.
 * Nol error di sepanjang jalur. Mencari satu tanpa dua lainnya berarti
 * melingkupi sepertiga masalah lalu mengira sudah selesai.
 *
 * - `di_bawah_cmc`    U95 terbit lebih kecil dari pita terakreditasinya (§1)
 * - `keterulangan_nol` pra-evaluasi seragam, stdev nol (§3)
 * - `resolusi_kosong`  resolusi tidak diisi, komponennya jadi nol (audit #172)
 *
 * ## Sengaja LINTAS ORGANISASI
 *
 * Menyimpang dari aturan penyaringan `organization_id` yang berlaku di seluruh
 * repo, dan itu disengaja: ini perkakas audit yang dijalankan operator server
 * dari terminal, bukan endpoint yang dipanggil pengguna. Tinjauan
 * ketidaksesuaian akreditasi justru harus melihat SELURUH arsip — melewatkan
 * satu lab berarti melewatkan sertifikat yang sudah di tangan pelanggan.
 * Pakai `--org=` kalau memang cuma mau satu.
 */
class AuditMicrometerCmc extends Command
{
    protected $signature = 'micrometer:audit-cmc
        {--org= : Batasi ke satu organization_id; kosong = seluruh arsip}
        {--csv= : Tulis hasilnya ke berkas CSV di path ini}';

    protected $description = 'Lingkupi sertifikat Micrometer yang U95-nya di bawah lantai CMC, keterulangannya nol, atau resolusinya kosong';

    public function handle(TabelStandarMicrometer $tabel): int
    {
        $sesi = CalibrationSession::query()
            ->whereHas('equipment', fn ($q) => $q->where('nama_alat_kemampuan', 'Micrometer'))
            ->when($this->option('org'), fn ($q, $org) => $q->where('organization_id', (int) $org))
            ->with(['equipment', 'certificate', 'uncertaintyCalculations'])
            ->orderBy('organization_id')
            ->orderBy('nomor_sesi')
            ->get();

        if ($sesi->isEmpty()) {
            $this->info('Nggak ada sesi Micrometer di arsip'.($this->option('org') ? ' organisasi ini.' : '.'));

            return self::SUCCESS;
        }

        $baris = [];

        foreach ($sesi as $s) {
            $spek = $s->spesifikasi_alat[MicrometerMentah::KUNCI_SESI] ?? [];
            $kapasitas = (float) ($spek['kapasitas_mm'] ?? 0.0);
            $resolusi = (float) ($spek['resolusi_mm'] ?? 0.0);

            $pra = array_map(
                static fn ($x): float => MicrometerMentah::keMm($x, $spek['satuan'] ?? null),
                is_array($spek['pra_evaluasi'] ?? null) ? $spek['pra_evaluasi'] : [],
            );

            $pita = $tabel->pitaCmc($kapasitas);

            // U95 diambil dari baris hitungan yang TERSIMPAN, bukan dihitung
            // ulang: yang sedang dilingkupi adalah apa yang TERCETAK di
            // sertifikat pelanggan, dan hitung ulang hari ini bisa memakai tabel
            // standar yang sudah berbeda dari waktu sesi itu terbit.
            $u95mm = (float) ($s->uncertaintyCalculations->first()->ketidakpastian_diperluas ?? 0.0);
            $u95um = $u95mm * 1000;

            $temuan = [];

            if ($pita === null) {
                $temuan[] = 'kapasitas_di_luar_pita';
            } elseif ($u95um > 0.0 && $u95um < (float) $pita['u95_um'] - 5e-4) {
                $temuan[] = 'di_bawah_cmc';
            }

            if (count($pra) >= 2 && (new MicrometerCalculator)->simpanganBaku($pra) <= 0.0) {
                $temuan[] = 'keterulangan_nol';
            }

            if ($resolusi <= 0.0) {
                $temuan[] = 'resolusi_kosong';
            }

            if ($temuan === []) {
                continue;
            }

            $baris[] = [
                'org' => $s->organization_id,
                'sesi' => $s->nomor_sesi,
                'alat' => $s->equipment->nama_alat ?? '?',
                'serial' => $s->equipment->serial_number ?? '?',
                'kapasitas_mm' => $kapasitas,
                'pita' => $pita['kode'] ?? '-',
                'cmc_um' => $pita['u95_um'] ?? null,
                'u95_um' => round($u95um, 4),
                'sertifikat' => $s->certificate?->nomor ?? '(belum terbit)',
                'temuan' => implode(' + ', $temuan),
            ];
        }

        $this->line('');
        $this->info("Sesi Micrometer diperiksa: {$sesi->count()}. Perlu ditinjau: ".count($baris).'.');
        $this->line('');

        if ($baris === []) {
            $this->info('Nol temuan. Nggak ada sertifikat Micrometer yang perlu ditinjau.');

            return self::SUCCESS;
        }

        $this->table(
            ['Org', 'Sesi', 'Alat', 'Serial', 'Kap (mm)', 'Pita', 'CMC µm', 'U95 µm', 'Sertifikat', 'Temuan'],
            array_map(array_values(...), $baris),
        );

        if ($csv = $this->option('csv')) {
            $fh = fopen($csv, 'w');
            fputcsv($fh, array_keys($baris[0]));
            foreach ($baris as $b) {
                fputcsv($fh, $b);
            }
            fclose($fh);
            $this->info("CSV ditulis ke {$csv}");
        }

        $this->line('');
        $this->warn('Keluaran ini BAHAN TINJAUAN, bukan daftar penarikan.');
        $this->line('Tiap baris masih perlu dinilai satu per satu: adakah pernyataan kesesuaian di');
        $this->line('sertifikatnya, dan apakah dengan U yang benar keputusannya bisa berbalik?');
        $this->line('Prosedurnya di docs/analisis-pertanyaan-lab-micrometer.md §1.');

        // Sengaja SUCCESS walau ada temuan: perintah ini alat pelingkupan, dan
        // exit code bukan-nol bikin dia gagal waktu dijalankan dari scheduler
        // atau CI — padahal "ada yang perlu ditinjau" itu hasil yang sah.
        return self::SUCCESS;
    }
}

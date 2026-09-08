<?php

namespace App\Console\Commands;

use App\Models\CalibrationSession;
use App\Services\Calibration\TabelStandarFlowmeter;
use App\Support\FlowmeterMentah;
use Illuminate\Console\Command;

/**
 * Lingkupi sertifikat **Flowmeter Ultrasonic** yang sudah terbit dengan U95 di
 * bawah pita CMC terakreditasi — atau yang titiknya jatuh di luar kedua pita.
 *
 * ## Kenapa perintah ini ada
 *
 * `docs/pertanyaan-lab-flowmeter.md` §2 menanyakan satu hal yang tidak bisa
 * dijawab kode: sertifikat yang SUDAH terbit di bawah pita — ditarik atau
 * direvisi? Yang bisa dilakukan kode cuma menyodorkan daftarnya, lengkap dengan
 * angkanya, supaya keputusannya bisa diambil tanpa membuka satu per satu.
 *
 * Sebabnya sudah terukur: sel U95 sertifikat kedua master berbunyi
 * `=MAX(J60:K61)` dengan `K61` **kosong** — `MAX` atas satu angka, jadi tidak
 * ada lantai CMC sama sekali. Di sesi contoh lab, varian Flowrate titik 2
 * terbit **1,0466 %OR** pada pita terakreditasi **1,2 %**.
 *
 * Bentuk dan alasannya disamakan dengan [AuditMicrometerCmc], yang lahir dari
 * persoalan yang sama persis di alat ke-25.
 *
 * ## Yang dibandingkan PERSEN, bukan angka absolut
 *
 * Lampiran akreditasi menulis CMC-nya `% of reading`. Dibandingkan absolut,
 * lantai 1,2 pada bacaan 310 Lpm terbaca 1,2 Lpm — sepertiga dari yang
 * seharusnya, dan angkanya masih terlihat masuk akal.
 *
 * ## Per TITIK, bukan per sesi
 *
 * Beda dari Micrometer yang budget-nya satu per sesi: di sini tiap titik punya
 * blok budget penuh sendiri, jadi satu sesi bisa punya tiga titik yang aman dan
 * satu yang di bawah pita — persis yang terjadi di sesi contoh lab. Melingkupi
 * per sesi berarti tiga titik yang sehat ikut tertarik, atau satu titik yang
 * bermasalah ikut lolos.
 *
 * ## SELURUH organisasi, bukan yang sedang login
 *
 * Sengaja tidak disaring `organization_id` — dia perintah terminal, bukan
 * endpoint yang dipanggil pengguna. Tinjauan ketidaksesuaian akreditasi justru
 * harus melihat SELURUH arsip: melewatkan satu lab berarti melewatkan
 * sertifikat yang sudah di tangan pelanggan. Pakai `--org=` kalau memang cuma
 * mau satu.
 */
class AuditFlowmeterCmc extends Command
{
    protected $signature = 'flowmeter:audit-cmc
        {--org= : Batasi ke satu organization_id; kosong = seluruh arsip}
        {--csv= : Tulis hasilnya ke berkas CSV di path ini}';

    protected $description = 'Lingkupi titik Flowmeter yang U95-nya di bawah pita CMC, di luar kedua pita, atau tabel standarnya jauh';

    /** Nama alat kemampuan kedua varian — ejaannya ikut lampiran akreditasi no. 30 & 31. */
    private const NAMA_ALAT = [
        'Flow Meter Cairan (Totalizer)',
        'Flow Meter Cairan (Flowrate)',
    ];

    public function handle(TabelStandarFlowmeter $tabel): int
    {
        $sesi = CalibrationSession::query()
            ->whereHas('equipment', fn ($q) => $q->whereIn('nama_alat_kemampuan', self::NAMA_ALAT))
            ->when($this->option('org'), fn ($q, $org) => $q->where('organization_id', (int) $org))
            ->with(['equipment', 'certificate', 'uncertaintyCalculations'])
            ->orderBy('organization_id')
            ->orderBy('nomor_sesi')
            ->get();

        if ($sesi->isEmpty()) {
            $this->info('Nggak ada sesi Flowmeter di arsip'.($this->option('org') ? ' organisasi ini.' : '.'));

            return self::SUCCESS;
        }

        $baris = [];
        $titikDiperiksa = 0;

        foreach ($sesi as $s) {
            $blok = FlowmeterMentah::blokSesi($s->spesifikasi_alat);

            // Sesi tanpa blok tingkat-sesi tidak bisa dinilai sama sekali —
            // mode-nya yang menentukan pita CMC mana yang berlaku. Dilaporkan
            // sebagai temuannya sendiri, bukan dilewati diam-diam: sesi seperti
            // itu justru yang paling mungkin terbit sebelum jalur ini ada.
            if ($blok === null) {
                $baris[] = $this->baris($s, null, [
                    'titik' => '-', 'pita' => '-', 'cmc_persen' => null, 'u95_persen' => null,
                ], 'blok_sesi_hilang');

                continue;
            }

            foreach ($s->uncertaintyCalculations->sortBy('titik_ke') as $u) {
                $titikDiperiksa++;

                $bacaan = (float) $u->rata_rata;
                // U95 diambil dari baris hitungan yang TERSIMPAN, bukan dihitung
                // ulang: yang sedang dilingkupi adalah apa yang TERCETAK di
                // sertifikat pelanggan, dan hitung ulang hari ini bisa memakai
                // tabel standar yang sudah berbeda dari waktu sesi itu terbit.
                $u95 = (float) $u->ketidakpastian_diperluas;

                if ($bacaan <= 0.0 || $u95 <= 0.0) {
                    continue;
                }

                $persen = $u95 / $bacaan * 100;
                $pita = $tabel->pitaCmc($blok['mode'], $bacaan);
                $temuan = [];

                if ($pita === null) {
                    // Di LUAR kedua pita: sertifikatnya membawa nomor lingkup
                    // LK-285-IDN untuk pengukuran yang tidak diakreditasi.
                    $temuan[] = 'di_luar_pita';
                } elseif ($persen < (float) $pita['cmc_persen_of_reading'] - 5e-9) {
                    $temuan[] = 'di_bawah_cmc';
                }

                // Jarak ke titik tabel standar TIDAK menahan penerbitan (dia
                // peringatan sesi), tapi dia ikut dilingkupi: koreksi titik
                // tabel yang jauh dipakai UTUH, dan di sesi contoh lab itu
                // menggeser deviasi 22 %.
                $jarak = $tabel->jarakRelatif($blok['mode'], $bacaan);

                if ($jarak !== null && $jarak > TabelStandarFlowmeter::AMBANG_JARAK_TABEL) {
                    $temuan[] = 'jarak_tabel_'.round($jarak * 100).'pct';
                }

                if ($temuan === []) {
                    continue;
                }

                $baris[] = $this->baris($s, $blok, [
                    'titik' => (string) $u->titik_ke,
                    'pita' => $pita['label'] ?? '-',
                    'cmc_persen' => $pita['cmc_persen_of_reading'] ?? null,
                    'u95_persen' => round($persen, 4),
                ], implode(' + ', $temuan));
            }
        }

        $this->line('');
        $this->info(sprintf(
            'Sesi Flowmeter diperiksa: %d (%d titik). Perlu ditinjau: %d.',
            $sesi->count(),
            $titikDiperiksa,
            count($baris),
        ));
        $this->line('');

        if ($baris === []) {
            $this->info('Nol temuan. Nggak ada titik Flowmeter yang perlu ditinjau.');

            return self::SUCCESS;
        }

        $this->table(
            ['Org', 'Sesi', 'Alat', 'Serial', 'Mode', 'Titik', 'Pita', 'CMC %', 'U95 %OR', 'Sertifikat', 'Temuan'],
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
        $this->line('Tiap baris masih perlu dinilai satu per satu: sertifikatnya sudah di tangan');
        $this->line('pelanggan atau belum, dan dengan U yang berlantai CMC apakah pernyataan');
        $this->line('kesesuaiannya bisa berbalik.');
        $this->line('Pertanyaannya di docs/pertanyaan-lab-flowmeter.md §2.');

        // Sengaja SUCCESS walau ada temuan: perintah ini alat pelingkupan, dan
        // exit code bukan-nol bikin dia gagal waktu dijalankan dari scheduler
        // atau CI — padahal "ada yang perlu ditinjau" itu hasil yang sah.
        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>|null  $blok
     * @param  array<string, mixed>  $titik
     * @return array<string, mixed>
     */
    private function baris(CalibrationSession $s, ?array $blok, array $titik, string $temuan): array
    {
        return [
            'org' => $s->organization_id,
            'sesi' => $s->nomor_sesi,
            'alat' => $s->equipment->nama_alat ?? '?',
            'serial' => $s->equipment->serial_number ?? '?',
            'mode' => $blok['mode'] ?? '-',
            'titik' => $titik['titik'],
            'pita' => $titik['pita'],
            'cmc_persen' => $titik['cmc_persen'],
            'u95_persen' => $titik['u95_persen'],
            'sertifikat' => $s->certificate?->nomor ?? '(belum terbit)',
            'temuan' => $temuan,
        ];
    }
}

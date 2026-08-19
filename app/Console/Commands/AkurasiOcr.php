<?php

namespace App\Console\Commands;

use App\Models\WorksheetScanCell;
use App\Services\Ocr\ValidasiSel;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Ukur akurasi OCR dari KOREKSI TEKNISI, per kolom.
 *
 * ## Kenapa per kolom, bukan per halaman
 *
 * "Akurasi 94%" nggak berarti apa-apa kalau yang 6% itu selalu kolom suhu di
 * Repeat 5. Angka per halaman nyembunyiin persis pola yang harus dibetulin:
 * satu kolom yang selalu meleset, satu titik ukur yang angkanya mirip, satu
 * merek HP yang kameranya beda.
 *
 * ## Angka yang paling penting di sini: HIJAU PALSU
 *
 * Sel hijau artinya "mesin berani nanggung, teknisi nggak perlu lihat". Sel
 * hijau yang ternyata dikoreksi berarti janji itu bohong — dan bedanya sama
 * kesalahan lain: yang ini nggak ada yang lihat sampai sertifikatnya terbit.
 * Satu hijau palsu lebih gawat dari lima puluh merah palsu, jadi dia dihitung
 * & ditampilin terpisah, bukan dilebur ke rata-rata.
 *
 * ## Sumber datanya cuma koreksi yang lewat orang
 *
 * `nilai_final` cuma keisi lewat `POST /worksheet-scans/{scan}/koreksi`, yaitu
 * waktu teknisi ngonfirmasi atau mbetulin di layar review. Tebakan mentah nggak
 * pernah masuk. Ngukur akurasi pakai tebakan sendiri sama aja nanya ke mesin
 * apakah dia bener menurut dirinya sendiri.
 */
class AkurasiOcr extends Command
{
    protected $signature = 'ocr:akurasi
        {--template= : batasi ke satu template, misal ph_meter}
        {--hari=30 : rentang koreksi yang dihitung (hari ke belakang)}';

    protected $description = 'Akurasi OCR lembar kerja per kolom, dihitung dari koreksi teknisi';

    public function handle(): int
    {
        $hari = (int) $this->option('hari');

        $sel = WorksheetScanCell::query()
            ->whereNotNull('dikoreksi_pada')
            ->where('dikoreksi_pada', '>=', now()->subDays($hari))
            ->when($this->option('template'), fn ($q) => $q->whereHas(
                'scan',
                fn ($s) => $s->where('template_id', $this->option('template')),
            ))
            ->get();

        if ($sel->isEmpty()) {
            $this->warn("Belum ada koreksi teknisi dalam {$hari} hari terakhir — nggak ada yang bisa diukur.");

            // Bukan kegagalan: lab yang belum mulai mindai emang belum punya
            // data. Yang gagal itu kalau angkanya dikarang biar ada isinya.
            return self::SUCCESS;
        }

        $this->info("Koreksi teknisi {$hari} hari terakhir: {$sel->count()} sel");
        $this->newLine();

        $this->tabelPerKolom($sel);
        $this->newLine();
        $this->hijauPalsu($sel);

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, WorksheetScanCell>  $sel
     */
    private function tabelPerKolom(Collection $sel): void
    {
        $baris = $sel
            ->groupBy(fn (WorksheetScanCell $s): string => $s->tabel_id.' / '.$s->field_id)
            ->map(function (Collection $kelompok, string $nama): array {
                $total = $kelompok->count();
                $cocok = $kelompok->where('cocok', true)->count();

                return [
                    $nama,
                    $total,
                    $cocok,
                    // Dibulatin 1 desimal: presisi lebih dari itu ngasih kesan
                    // angkanya lebih pasti dari sampelnya.
                    number_format($total === 0 ? 0 : $cocok / $total * 100, 1).'%',
                    $kelompok->where('status', ValidasiSel::MERAH)->count(),
                    $kelompok->where('status', ValidasiSel::KOSONG)->count(),
                ];
            })
            ->sortBy(fn (array $b): string => $b[0])
            ->values()
            ->all();

        $this->table(['Tabel / Kolom', 'Dikoreksi', 'Cocok', 'Akurasi', 'Merah', 'Kosong'], $baris);
    }

    /**
     * @param  Collection<int, WorksheetScanCell>  $sel
     */
    private function hijauPalsu(Collection $sel): void
    {
        $hijau = $sel->where('status', ValidasiSel::HIJAU);
        $palsu = $hijau->where('cocok', false);

        if ($hijau->isEmpty()) {
            $this->line('Sel hijau: belum ada. Wajar selama `ocr.tulisan_tangan.aktif` nyala — '
                .'tulisan tangan mentok kuning, dan lembar kerja lab ini ditulis tangan.');

            return;
        }

        $persen = number_format($palsu->count() / $hijau->count() * 100, 2);

        $this->line("Sel hijau: {$hijau->count()}, yang ternyata dikoreksi: {$palsu->count()} ({$persen}%)");

        if ($palsu->isEmpty()) {
            return;
        }

        // Ditampilin satu-satu, bukan diringkas: tiap baris di sini itu angka
        // yang HAMPIR mendarat di sertifikat tanpa ada yang periksa.
        $this->error('HIJAU PALSU — angka yang keisi otomatis padahal salah:');
        $this->table(
            ['Scan', 'Kunci', 'Dibaca', 'Sebenarnya', 'Confidence'],
            $palsu->take(20)->map(fn (WorksheetScanCell $s): array => [
                $s->worksheet_scan_id,
                $s->kunci,
                $s->nilai,
                $s->nilai_final,
                $s->confidence_akhir,
            ])->all(),
        );

        if ($palsu->count() > 20) {
            $this->line('… dan '.($palsu->count() - 20).' lagi.');
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Folder;
use App\Models\FolderFile;
use App\Services\FolderOrganizer;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rapikan berkas Arsip lama ke struktur `PT › Tahun › Bulan › Jenis alat` (K-50-06).
 *
 * Hanya berkas yang lahir otomatis (sertifikat & lembar kerja) di folder SISTEM.
 * Pemilik PT dibaca dari `alat.customer_id` — TIDAK PERNAH dari kecocokan nama.
 * Berkas yang tidak punya pemilik terbaca dilaporkan sebagai yatim dan tidak
 * disentuh. Unggahan manual & berkas di folder buatan admin tidak dipindah.
 * Revisi `-R<n>` mengikuti tanggal & jenis alat sertifikat aslinya.
 *
 * Default dry-run; `--jalankan` menulis. Folder lama yang jadi kosong dibiarkan.
 */
class RapikanArsip extends Command
{
    protected $signature = 'arsip:rapikan
        {--dry-run : Hanya tampilkan rencana (bawaan)}
        {--jalankan : Pindahkan berkas}';

    protected $description = 'Rapikan berkas Arsip lama ke PT › Tahun › Bulan › Jenis alat (K-50-06)';

    public function handle(FolderOrganizer $organizer): int
    {
        if ($this->option('jalankan') && $this->option('dry-run')) {
            $this->error('Pilih salah satu: --dry-run atau --jalankan.');

            return self::INVALID;
        }

        $jalankan = (bool) $this->option('jalankan');
        $rencana = [];
        $yatim = [];
        $sudahRapi = 0;
        $dilewati = 0;

        FolderFile::query()
            ->whereIn('sumber', [FolderFile::SUMBER_SERTIFIKAT, FolderFile::SUMBER_LEMBAR_KERJA])
            ->with(['folder', 'certificate.session.equipment.category', 'certificate.session.equipment.customer', 'calibrationSession.equipment.category', 'calibrationSession.equipment.customer'])
            ->orderBy('id')
            ->each(function (FolderFile $berkas) use (&$rencana, &$yatim, &$sudahRapi, &$dilewati): void {
                [$pelanggan, $tanggal, $alat] = $this->asal($berkas);

                if ($pelanggan === null || $tanggal === null) {
                    $yatim[] = "#{$berkas->id} {$berkas->nama}";

                    return;
                }

                if ($berkas->folder === null || $berkas->folder->tipe !== Folder::TIPE_SISTEM) {
                    $dilewati++;

                    return;
                }

                $jalur = FolderOrganizer::jalurNama($tanggal, $alat);

                if ($this->jalurSaatIni($berkas->folder, $pelanggan) === $jalur) {
                    $sudahRapi++;

                    return;
                }

                $rencana[] = [$berkas, $pelanggan, $tanggal, $alat, $jalur];
            });

        foreach (array_slice($rencana, 0, 50) as [$berkas, $pelanggan, , , $jalur]) {
            $this->line("#{$berkas->id} {$berkas->nama}  →  {$pelanggan->nama} / ".implode(' / ', $jalur));
        }

        if (count($rencana) > 50) {
            $this->line('… dan '.(count($rencana) - 50).' berkas lain.');
        }

        $this->info(sprintf(
            '%d berkas perlu dipindah, %d sudah rapi, %d dilewati (folder buatan admin), %d yatim (tanpa pemilik).',
            count($rencana), $sudahRapi, $dilewati, count($yatim),
        ));

        foreach (array_slice($yatim, 0, 50) as $baris) {
            $this->warn("Yatim: {$baris}");
        }

        if (! $jalankan) {
            $this->warn('DRY-RUN: tidak ada yang diubah. Jalankan ulang dengan --jalankan.');

            return self::SUCCESS;
        }

        $dipindah = 0;

        foreach ($rencana as [$berkas, $pelanggan, $tanggal, $alat]) {
            DB::transaction(function () use ($organizer, $berkas, $pelanggan, $tanggal, $alat, &$dipindah): void {
                $folder = $organizer->folderTujuan($pelanggan, $tanggal, $alat);

                // Lewat model supaya `Diaudit` mencatat folder lama → baru.
                $berkas->forceFill(['folder_id' => $folder->id])->save();
                $dipindah++;
            });
        }

        $this->info("{$dipindah} berkas dipindah.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: ?Customer, 1: ?CarbonInterface, 2: mixed}
     */
    private function asal(FolderFile $berkas): array
    {
        if ($berkas->certificate !== null) {
            // Revisi ikut sertifikat ASLI (akar rantai `revision_of`).
            $akar = $berkas->certificate;
            $batas = 10;

            while ($akar->revision_of !== null && $batas-- > 0) {
                $induk = Certificate::query()
                    ->with(['session.equipment.category', 'session.equipment.customer'])
                    ->find($akar->revision_of);

                if ($induk === null) {
                    break;
                }

                $akar = $induk;
            }

            $alat = $akar->session?->equipment;

            return [$alat?->customer, $akar->diterbitkan_pada, $alat];
        }

        $sesi = $berkas->calibrationSession;
        $alat = $sesi?->equipment;

        return [$alat?->customer, $sesi?->tanggal_kalibrasi ?? $sesi?->created_at, $alat];
    }

    /**
     * Tiga nama di bawah folder PT milik pelanggan itu, atau null kalau folder
     * berkas bukan tepat tiga tingkat di bawah akar PT yang benar.
     *
     * @return list<string>|null
     */
    private function jalurSaatIni(Folder $folder, Customer $pelanggan): ?array
    {
        $nama = [];
        $saat = $folder;

        while ($saat->parent_id !== null) {
            array_unshift($nama, $saat->nama);
            $saat = Folder::find($saat->parent_id);

            if ($saat === null) {
                return null;
            }
        }

        // `$saat` = akar. Pemiliknya dibaca dari `customer_id`, bukan nama.
        if ((int) $saat->customer_id !== (int) $pelanggan->id || count($nama) !== 3) {
            return null;
        }

        return $nama;
    }
}

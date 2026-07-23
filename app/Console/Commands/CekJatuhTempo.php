<?php

namespace App\Console\Commands;

use App\Models\Equipment;
use App\Models\User;
use App\Notifications\AlatJatuhTempo;
use Illuminate\Console\Command;

/**
 * Dijalanin scheduler tiap hari (lihat routes/console.php). Nyari alat yang udah
 * lewat / mendekati jatuh tempo, terus kirim notifikasi ke semua admin lewat
 * lonceng panel. Tanpa ini, alat yang telat kalibrasi cuma ketahuan kalau ada
 * yang kebetulan buka daftar alat.
 */
class CekJatuhTempo extends Command
{
    protected $signature = 'alat:cek-jatuh-tempo {--hari=30 : Ambang "mendekati" jatuh tempo dalam hari}';

    protected $description = 'Cek alat yang lewat/mendekati jatuh tempo & kabarin admin';

    public function handle(): int
    {
        $ambang = (int) $this->option('hari');

        // Kelompokin per organisasi — tiap admin cuma dikabarin soal alat PT-nya.
        Equipment::query()
            ->where('status', Equipment::STATUS_AKTIF)
            ->whereNotNull('tanggal_jatuh_tempo')
            ->whereDate('tanggal_jatuh_tempo', '<=', now()->addDays($ambang))
            ->get()
            ->groupBy('organization_id')
            ->each(function ($alatList, $organizationId): void {
                $overdue = $alatList->filter(fn (Equipment $a) => $a->isOverdue())->count();
                $mendekati = $alatList->count() - $overdue;

                $pesan = collect([
                    $overdue > 0 ? "{$overdue} alat sudah lewat jatuh tempo" : null,
                    $mendekati > 0 ? "{$mendekati} alat mendekati jatuh tempo" : null,
                ])->filter()->implode(', ');

                $admins = User::where('organization_id', $organizationId)
                    ->where('role', User::ROLE_ADMIN)
                    ->where('status', User::STATUS_AKTIF)
                    ->get();

                // Notifikasi ditulis lewat kelas aplikasi, bukan langsung
                // Filament: baris yang sama harus kebaca di lonceng panel DAN
                // di halaman notifikasi mobile (spesifikasi poin 4 & 6).
                $notifikasi = new AlatJatuhTempo(
                    $overdue,
                    $mendekati,
                    $alatList->take(20)->map(fn (Equipment $a): array => [
                        'id' => $a->id,
                        'nama_alat' => $a->nama_alat,
                        'serial_number' => $a->serial_number,
                        'tanggal_jatuh_tempo' => $a->tanggal_jatuh_tempo?->toDateString(),
                        'overdue' => $a->isOverdue(),
                    ])->values()->all(),
                );

                foreach ($admins as $admin) {
                    $admin->notify($notifikasi);
                }

                $this->info("Org {$organizationId}: {$pesan} → {$admins->count()} admin dikabarin.");
            });

        return self::SUCCESS;
    }
}

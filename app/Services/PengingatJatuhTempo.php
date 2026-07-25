<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\AlatJatuhTempo;

/**
 * Pengingat alat mendekati / lewat jatuh tempo kalibrasi.
 *
 * Tanggal jatuh tempo di-SET admin (custom) per alat (`equipment.tanggal_jatuh_tempo`).
 * Ambang "mendekati" (H- berapa hari sebelum jatuh tempo) bisa diatur admin per
 * organisasi lewat `organization.settings['reminder_hari_sebelum']` — default 30
 * hari (± sebulan). Bisa dijalanin OTOMATIS (scheduler harian, lihat
 * routes/console.php) atau MANUAL (admin mencet, lewat POST /api/reminders/jatuh-tempo).
 */
class PengingatJatuhTempo
{
    /** Default kalau org belum ngatur sendiri (± sebulan sebelum jatuh tempo). */
    public const DEFAULT_HARI = 30;

    /** Kunci setting di organization.settings buat ambang reminder. */
    public const KEY_SETTING = 'reminder_hari_sebelum';

    /**
     * Jalanin buat SEMUA organisasi (dipakai scheduler harian).
     *
     * @param  int|null  $override  paksa ambang hari yang sama buat semua org (opsional)
     * @return array<int, array{organization_id: int, ambang_hari: int, overdue: int, mendekati: int, admin_dikabarin: int}>
     */
    public function jalankan(?int $override = null): array
    {
        return Organization::query()->get()
            ->map(fn (Organization $org): ?array => $this->untukOrganisasi($org, $override))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Jalanin buat SATU organisasi (dipakai trigger manual admin). Balikin null
     * kalau nggak ada alat yang perlu dikabarin.
     *
     * @return array{organization_id: int, ambang_hari: int, overdue: int, mendekati: int, admin_dikabarin: int}|null
     */
    public function untukOrganisasi(Organization $org, ?int $override = null): ?array
    {
        $ambang = $override ?? $this->ambangOrganisasi($org);

        $alatList = Equipment::query()
            ->where('organization_id', $org->id)
            ->where('status', Equipment::STATUS_AKTIF)
            ->whereNotNull('tanggal_jatuh_tempo')
            ->whereDate('tanggal_jatuh_tempo', '<=', now()->addDays($ambang))
            ->get();

        if ($alatList->isEmpty()) {
            return null;
        }

        $overdue = $alatList->filter(fn (Equipment $a): bool => $a->isOverdue())->count();
        $mendekati = $alatList->count() - $overdue;

        $admins = User::query()
            ->where('organization_id', $org->id)
            ->where('role', User::ROLE_ADMIN)
            ->where('status', User::STATUS_AKTIF)
            ->get();

        // Ditulis lewat kelas notifikasi aplikasi biar baris yang sama kebaca di
        // lonceng panel admin DAN di halaman notifikasi mobile (spec poin 4 & 6).
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

        return [
            'organization_id' => $org->id,
            'ambang_hari' => $ambang,
            'overdue' => $overdue,
            'mendekati' => $mendekati,
            'admin_dikabarin' => $admins->count(),
        ];
    }

    /** Ambang reminder yang berlaku buat satu org: setting admin, atau default. */
    public function ambangOrganisasi(Organization $org): int
    {
        $nilai = $org->settings[self::KEY_SETTING] ?? null;

        return is_numeric($nilai) && (int) $nilai > 0 ? (int) $nilai : self::DEFAULT_HARI;
    }
}

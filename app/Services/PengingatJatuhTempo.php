<?php

namespace App\Services;

use App\Models\Equipment;
use App\Models\Organization;
use App\Notifications\AlatJatuhTempo;

/**
 * Pengingat alat mendekati / lewat jatuh tempo kalibrasi.
 *
 * Tanggal jatuh tempo di-SET admin (custom) per alat (`equipment.tanggal_jatuh_tempo`).
 * Ambang "mendekati" (H- berapa hari sebelum jatuh tempo) bisa diatur admin per
 * organisasi lewat `organization.settings['reminder_hari_sebelum']` — default 30
 * hari (± sebulan). Bisa dijalanin OTOMATIS (scheduler harian, lihat
 * routes/console.php) atau MANUAL (admin mencet, lewat POST /api/reminders/jatuh-tempo).
 *
 * Pengulangannya ditahan `PenjagaNotifikasiUlang`, sama seperti saudara
 * kembarnya `PengingatStandar` — lihat penjelasannya di sana.
 */
class PengingatJatuhTempo
{
    /** Default kalau org belum ngatur sendiri (± sebulan sebelum jatuh tempo). */
    public const DEFAULT_HARI = Organization::DEFAULT_AMBANG_HARI;

    /** Kunci setting di organization.settings buat ambang reminder. */
    public const KEY_SETTING = Organization::KEY_AMBANG_HARI;

    /**
     * Isi yang sama nggak diulang selama seminggu.
     *
     * Angkanya disamakan dengan `PengingatStandar::MASA_TENANG_HARI`, dan itu
     * bukan kebetulan: dua pengingat ini dipicu scheduler yang SAMA, lima menit
     * berselang, dan mendarat di lonceng yang sama. Masa tenang yang beda bikin
     * salah satunya terasa lebih berisik tanpa ada alasan yang bisa dijelaskan
     * ke admin.
     *
     * Alasan angkanya tujuh, sama persis: ambangnya 30 hari, jadi tanpa masa
     * tenang admin dapat baris yang sama 30 kali. Tiap hari terlalu berisik,
     * sebulan sekali kelewat.
     */
    public const MASA_TENANG_HARI = 7;

    /**
     * `PenerimaNotifikasi` sekarang lewat konstruktor, bukan `app()` di tengah
     * badan method — sejajar dengan `PengingatStandar`, dan bikin dua-duanya
     * bisa disuntik di test tanpa menyentuh container.
     */
    public function __construct(
        private readonly PenerimaNotifikasi $penerima,
        private readonly PenjagaNotifikasiUlang $penjaga,
    ) {}

    /**
     * Jalanin buat SEMUA organisasi (dipakai scheduler harian).
     *
     * @param  int|null  $override  paksa ambang hari yang sama buat semua org (opsional)
     * @return array<int, array{organization_id: int, ambang_hari: int, overdue: int, mendekati: int, admin_dikabarin: int, admin_dilewat: int}>
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
     * @return array{organization_id: int, ambang_hari: int, overdue: int, mendekati: int, admin_dikabarin: int, admin_dilewat: int}|null
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

        // Lewat `PenerimaNotifikasi` biar aturan "siapa yang dikabarin" cuma ada
        // di satu tempat — dulu query-nya ditulis ulang di tiap pemicu, dan itu
        // cara paling gampang bikin salah satunya lupa nyaring `status = aktif`.
        $admins = $this->penerima->adminAktifOrganisasi($org);

        $rincian = $alatList->take(20)->map(fn (Equipment $a): array => [
            'id' => $a->id,
            'nama_alat' => $a->nama_alat,
            'serial_number' => $a->serial_number,
            'tanggal_jatuh_tempo' => $a->tanggal_jatuh_tempo?->toDateString(),
            'overdue' => $a->isOverdue(),
        ])->values()->all();

        // Ditulis lewat kelas notifikasi aplikasi biar baris yang sama kebaca di
        // lonceng panel admin DAN di halaman notifikasi mobile (spec poin 4 & 6).
        $notifikasi = new AlatJatuhTempo($overdue, $mendekati, $rincian);

        $tandaTangan = $this->tandaTangan($rincian);
        $dikabarin = 0;
        $dilewat = 0;

        foreach ($admins as $admin) {
            // Dijaga PER ADMIN, bukan per organisasi: admin yang baru diangkat
            // bulan ini tetap harus dapat kabar pertamanya, walaupun admin lama
            // udah dikabarin kemarin.
            if (! $this->penjaga->bolehKirim($admin, AlatJatuhTempo::class, $tandaTangan, self::MASA_TENANG_HARI)) {
                $dilewat++;

                continue;
            }

            $admin->notify($notifikasi);
            $dikabarin++;
        }

        return [
            'organization_id' => $org->id,
            'ambang_hari' => $ambang,
            'overdue' => $overdue,
            'mendekati' => $mendekati,
            'admin_dikabarin' => $dikabarin,
            'admin_dilewat' => $dilewat,
        ];
    }

    /**
     * Harus sama persis dengan `AlatJatuhTempo::tandaTangan()`.
     *
     * Dihitung dua kali (di sini buat mutusin kirim/nggak, di notifikasi buat
     * disimpen) karena penjaganya butuh tahu tanda tangannya SEBELUM
     * notifikasinya dikirim. Dijaga test yang ngebandingin dua-duanya, biar
     * nggak bisa geser sendiri-sendiri — kalau beda, penjaganya jadi nggak
     * pernah nyocok dan notifikasinya keulang tiap hari lagi.
     *
     * @param  list<array<string, mixed>>  $rincian
     */
    public function tandaTangan(array $rincian): string
    {
        $bagian = array_map(
            fn (array $a): string => ($a['id'] ?? '?').':'.(($a['overdue'] ?? false) ? '1' : '0'),
            $rincian,
        );

        sort($bagian);

        return implode('|', $bagian);
    }

    /**
     * Ambang reminder yang berlaku buat satu org: setting admin, atau default.
     *
     * Angkanya dipegang `Organization` supaya badge `warning` di standar acuan
     * bisa pakai ambang yang SAMA tanpa nyalin logikanya ke resource.
     */
    public function ambangOrganisasi(Organization $org): int
    {
        return $org->ambangPeringatanHari();
    }
}

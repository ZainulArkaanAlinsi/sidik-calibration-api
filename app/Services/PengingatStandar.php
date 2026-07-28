<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\Standard;
use App\Notifications\StandarMauKadaluarsa;

/**
 * Pengingat sertifikat standar acuan yang mendekati / udah kadaluarsa
 * (fase-2 §2, kejadian keenam).
 *
 * Ambangnya **satu angka yang sama** dengan badge `warning` di `/standards` dan
 * dengan pengingat jatuh tempo alat: `organization.settings.reminder_hari_sebelum`
 * (default 30). Sengaja nggak bikin setting baru — kalau ambang notifikasi beda
 * dari ambang badge, admin lihat badge kuning tanpa pernah dikabarin, atau
 * sebaliknya, dan dua-duanya bikin orang berhenti percaya sama badge-nya.
 *
 * Dijalanin scheduler tiap pagi bareng pengingat alat (`routes/console.php`).
 * Pengulangannya ditahan `PenjagaNotifikasiUlang` — lihat penjelasannya di sana.
 */
class PengingatStandar
{
    /**
     * Isi yang sama nggak diulang selama seminggu.
     *
     * Bukan angka asal: ambangnya 30 hari, jadi tanpa masa tenang admin dapat
     * baris yang sama 30 kali. Tiap hari terlalu berisik, sebulan sekali kelewat
     * — seminggu itu yang masih kebaca sebagai pengingat, bukan sebagai spam.
     * Standar yang statusnya BERUBAH tetap dikabarin saat itu juga, nggak nunggu.
     */
    public const MASA_TENANG_HARI = 7;

    public function __construct(
        private readonly PenerimaNotifikasi $penerima,
        private readonly PenjagaNotifikasiUlang $penjaga,
    ) {}

    /**
     * Jalanin buat SEMUA organisasi (dipakai scheduler harian).
     *
     * @return list<array{organization_id: int, ambang_hari: int, kadaluarsa: int, mendekati: int, admin_dikabarin: int, admin_dilewat: int}>
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
     * Satu organisasi. Balikin null kalau nggak ada standar yang perlu dikabarin.
     *
     * @return array{organization_id: int, ambang_hari: int, kadaluarsa: int, mendekati: int, admin_dikabarin: int, admin_dilewat: int}|null
     */
    public function untukOrganisasi(Organization $org, ?int $override = null): ?array
    {
        $ambang = $override ?? $org->ambangPeringatanHari();

        $kena = Standard::query()
            ->where('organization_id', $org->id)
            ->whereNotNull('berlaku_sampai')
            ->whereDate('berlaku_sampai', '<=', now()->addDays($ambang))
            // Urutan dipatok biar tanda tangannya stabil: kalau urutannya berubah
            // padahal isinya sama, penjaga pengulangan nganggepnya kabar baru dan
            // notifikasinya keulang tanpa alasan.
            ->orderBy('berlaku_sampai')
            ->orderBy('id')
            ->get();

        if ($kena->isEmpty()) {
            return null;
        }

        $rincian = $kena->map(fn (Standard $s): array => [
            'id' => $s->id,
            'nama' => $s->nama,
            'no_sertifikat' => $s->no_sertifikat,
            'berlaku_sampai' => $s->berlaku_sampai?->toDateString(),
            'hari_menuju_kadaluarsa' => $s->hariMenujuKadaluarsa(),
            'status_kalibrasi' => $s->statusKalibrasi($ambang),
            // Kunci `status` inilah yang masuk tanda tangan — begitu satu standar
            // pindah dari `warning` ke `expired`, admin dikabarin saat itu juga.
            'status' => $s->statusKalibrasi($ambang),
        ])->values()->all();

        $kadaluarsa = 0;

        foreach ($rincian as $r) {
            if ($r['status'] === Standard::STATUS_EXPIRED) {
                $kadaluarsa++;
            }
        }

        $notifikasi = new StandarMauKadaluarsa(
            $kadaluarsa,
            count($rincian) - $kadaluarsa,
            $rincian,
        );

        $tandaTangan = $this->tandaTangan($rincian);
        $dikabarin = 0;
        $dilewat = 0;

        foreach ($this->penerima->adminAktifOrganisasi($org) as $admin) {
            // Dijaga PER ADMIN, bukan per organisasi: admin yang baru diangkat
            // bulan ini tetap harus dapat kabar pertamanya, walaupun admin lama
            // udah dikabarin kemarin.
            if (! $this->penjaga->bolehKirim($admin, StandarMauKadaluarsa::class, $tandaTangan, self::MASA_TENANG_HARI)) {
                $dilewat++;

                continue;
            }

            $admin->notify($notifikasi);
            $dikabarin++;
        }

        return [
            'organization_id' => $org->id,
            'ambang_hari' => $ambang,
            'kadaluarsa' => $kadaluarsa,
            'mendekati' => count($rincian) - $kadaluarsa,
            'admin_dikabarin' => $dikabarin,
            'admin_dilewat' => $dilewat,
        ];
    }

    /**
     * Harus sama persis dengan `StandarMauKadaluarsa::tandaTangan()`.
     *
     * Dihitung dua kali (di sini buat mutusin kirim/nggak, di notifikasi buat
     * disimpen) karena penjaganya butuh tahu tanda tangannya SEBELUM notifikasinya
     * dikirim. Dijaga test yang ngebandingin dua-duanya, biar nggak bisa geser
     * sendiri-sendiri — kalau beda, penjaganya jadi nggak pernah nyocok dan
     * notifikasinya keulang tiap hari lagi.
     *
     * @param  list<array<string, mixed>>  $rincian
     */
    public function tandaTangan(array $rincian): string
    {
        $bagian = array_map(
            fn (array $s): string => ($s['id'] ?? '?').':'.($s['status'] ?? '?'),
            $rincian,
        );

        sort($bagian);

        return implode('|', $bagian);
    }
}

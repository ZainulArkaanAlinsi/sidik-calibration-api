<?php

namespace App\Filament\Widgets;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Empat angka pembuka dashboard.
 *
 * ## Urutannya sengaja: yang MENUNTUT TINDAKAN duluan
 *
 * Sebelum 26 Agt 2026 urutannya "Total alat" dulu, lalu overdue. Total alat itu
 * angka yang nggak pernah menyuruh siapa pun melakukan apa pun — dia cuma
 * memberi tahu ukuran lab, dan tetap sama isinya tiap hari. Yang dicari admin
 * waktu membuka panel: apa yang nunggu saya. Jadi overdue & menunggu approval
 * naik ke depan, total alat turun ke belakang sebagai konteks.
 *
 * ## Keterangannya harus berarti, bukan mengulang labelnya
 *
 * "Sudah lewat jatuh tempo" di bawah angka overdue nggak menambah apa-apa —
 * itu sudah dikatakan labelnya. Yang menambah: SEBERAPA lewat. Alat yang telat
 * 3 hari dan yang telat 43 hari sama-sama "overdue", tapi cuma yang kedua
 * artinya ada yang jebol di prosesnya.
 *
 * Rel warna di sisi kiri kartu dipasang lewat tema (`.fi-wi-stats-overview-stat`),
 * bukan dari sini — warna teks saja bikin yang kesulitan membedakan merah-hijau
 * kehilangan seluruh sinyalnya.
 */
class RingkasanStats extends StatsOverviewWidget
{
    /**
     * Batas "sudah kelamaan nunggu" buat sesi yang belum di-approve.
     *
     * Seminggu, dan bukan angka bulat asal: sesi yang lewat seminggu berarti
     * sudah melewati satu siklus kerja penuh tanpa ada yang menyentuhnya.
     */
    private const HARI_TERLALU_LAMA = 7;

    protected function getStats(): array
    {
        $organizationId = User::yangLogin()?->organization_id;

        return [
            $this->statOverdue($organizationId),
            $this->statMenungguApproval($organizationId),
            $this->statSertifikatBulanIni($organizationId),
            $this->statTotalAlat($organizationId),
        ];
    }

    private function statOverdue(?int $organizationId): Stat
    {
        $query = Equipment::where('organization_id', $organizationId)->overdue();
        $jumlah = (clone $query)->count();

        // Jatuh tempo TERJAUH, bukan rata-rata: yang perlu diketahui admin
        // seberapa parah kasus terburuknya, dan rata-rata justru menyembunyikan
        // satu alat yang telat berbulan-bulan di antara sepuluh yang telat sehari.
        $paling = (clone $query)->min('tanggal_jatuh_tempo');

        $keterangan = $jumlah === 0
            ? 'Semua masih dalam masa berlaku'
            : 'Paling lama: '.(int) now()->startOfDay()->diffInDays($paling).' hari';

        return Stat::make('Alat lewat jatuh tempo', $jumlah)
            ->description($keterangan)
            ->icon('heroicon-o-exclamation-triangle')
            ->color($jumlah > 0 ? 'danger' : 'success');
    }

    private function statMenungguApproval(?int $organizationId): Stat
    {
        $query = CalibrationSession::where('organization_id', $organizationId)
            ->where('status', CalibrationSession::STATUS_MENUNGGU_APPROVAL);

        $jumlah = (clone $query)->count();
        $lama = (clone $query)
            ->where('created_at', '<', now()->subDays(self::HARI_TERLALU_LAMA))
            ->count();

        $keterangan = match (true) {
            $jumlah === 0 => 'Nggak ada yang nunggu di-review',
            $lama > 0 => $lama.' di antaranya > '.self::HARI_TERLALU_LAMA.' hari',
            default => 'Semuanya masuk minggu ini',
        };

        return Stat::make('Menunggu approval', $jumlah)
            ->description($keterangan)
            ->icon('heroicon-o-clock')
            ->color($lama > 0 ? 'danger' : ($jumlah > 0 ? 'warning' : 'gray'));
    }

    private function statSertifikatBulanIni(?int $organizationId): Stat
    {
        $terbit = static fn (?int $org, CarbonInterface $awal, CarbonInterface $akhir): int => Certificate::where('organization_id', $org)
            ->where('status', Certificate::STATUS_TERBIT)
            ->whereBetween('diterbitkan_pada', [$awal, $akhir])
            ->count();

        $bulanIni = $terbit($organizationId, now()->startOfMonth(), now()->endOfMonth());
        $bulanLalu = $terbit(
            $organizationId,
            now()->subMonthNoOverflow()->startOfMonth(),
            now()->subMonthNoOverflow()->endOfMonth(),
        );

        // Dibandingkan ke bulan lalu supaya angkanya punya arah. Tanpa
        // pembanding, "48" itu cuma angka — nggak ada yang tau itu ramai atau
        // sepi buat lab ini.
        $keterangan = match (true) {
            $bulanLalu === 0 => 'Bulan lalu belum ada yang terbit',
            $bulanIni > $bulanLalu => 'Naik dari '.$bulanLalu.' bulan lalu',
            $bulanIni < $bulanLalu => 'Turun dari '.$bulanLalu.' bulan lalu',
            default => 'Sama dengan bulan lalu',
        };

        return Stat::make('Sertifikat bulan ini', $bulanIni)
            ->description($keterangan)
            ->icon('heroicon-o-document-check')
            ->color('success');
    }

    private function statTotalAlat(?int $organizationId): Stat
    {
        $total = Equipment::where('organization_id', $organizationId)->count();

        return Stat::make('Total alat terdaftar', $total)
            ->description('Seluruh alat pelanggan di lab ini')
            ->icon('heroicon-o-wrench-screwdriver')
            ->color('gray');
    }
}

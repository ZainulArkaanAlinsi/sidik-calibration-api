<?php

namespace App\Filament\Widgets;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Equipment;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Angka ringkasan di dashboard admin. Sumbernya sama persis kayak endpoint
 * `GET /api/dashboard` (versi admin/lintas-teknisi) — biar web & mobile nunjukin
 * angka yang sama, nggak ada versi kembar yang bisa geser.
 */
class RingkasanStats extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $organizationId = User::yangLogin()?->organization_id;

        $totalAlat = Equipment::where('organization_id', $organizationId)->count();
        $overdue = Equipment::where('organization_id', $organizationId)->overdue()->count();
        $nungguApproval = CalibrationSession::where('organization_id', $organizationId)
            ->where('status', CalibrationSession::STATUS_MENUNGGU_APPROVAL)->count();
        $sertifikatBulanIni = Certificate::where('organization_id', $organizationId)
            ->where('status', Certificate::STATUS_TERBIT)
            ->whereBetween('diterbitkan_pada', [now()->startOfMonth(), now()->endOfMonth()])
            ->count();

        return [
            Stat::make('Total alat', $totalAlat)
                ->icon('heroicon-o-wrench-screwdriver')
                ->color('primary'),
            Stat::make('Alat overdue', $overdue)
                ->description($overdue > 0 ? 'Sudah lewat jatuh tempo' : 'Semua masih dalam masa berlaku')
                ->icon('heroicon-o-exclamation-triangle')
                ->color($overdue > 0 ? 'danger' : 'success'),
            Stat::make('Menunggu approval', $nungguApproval)
                ->description('Sesi kalibrasi yang perlu di-review')
                ->icon('heroicon-o-clock')
                ->color($nungguApproval > 0 ? 'warning' : 'gray'),
            Stat::make('Sertifikat bulan ini', $sertifikatBulanIni)
                ->icon('heroicon-o-document-check')
                ->color('success'),
        ];
    }
}

<?php

namespace Tests\Concerns;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Perakit rantai dokumen untuk tes arsip & pencarian: PT -> alat -> (order ->
 * item) -> sesi -> sertifikat. Semua nilai sintetis.
 */
trait DokumenArsipUji
{
    private int $urutDokumenUji = 0;

    /**
     * @param  array{
     *     pt?: Customer|string,
     *     alat?: string,
     *     serial?: string,
     *     nomor?: string,
     *     terbit?: ?string,
     *     status?: string,
     *     nomor_order?: ?string,
     *     order?: ?string,
     *     teknisi?: ?User,
     *     tanggal_kalibrasi?: ?string,
     *     hapus_alat?: bool,
     *     sesi?: array<string, mixed>,
     *     sertifikat?: array<string, mixed>,
     * }  $o
     * @return array{pt: Customer, alat: Equipment, sesi: CalibrationSession, sertifikat: Certificate, order: ?Order}
     */
    protected function bangunDokumen(Organization $org, array $o = []): array
    {
        $this->urutDokumenUji++;
        $n = $this->urutDokumenUji;

        $pt = $o['pt'] ?? null;
        if (! $pt instanceof Customer) {
            $pt = Customer::factory()->create([
                'organization_id' => $org->id,
                'nama' => $pt ?? 'PT Uji '.Str::upper(Str::random(6)),
            ]);
        }

        $alat = Equipment::factory()->create([
            'organization_id' => $org->id,
            'customer_id' => $pt->id,
            'serial_number' => $o['serial'] ?? 'SN-UJI-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT).Str::upper(Str::random(3)),
            'nama_alat' => $o['alat'] ?? 'Feeler Gauge',
        ]);

        $order = null;
        $item = null;
        if (isset($o['order'])) {
            $order = Order::factory()->create([
                'organization_id' => $org->id,
                'customer_id' => $pt->id,
                'nomor' => $o['order'],
            ]);
            $item = OrderItem::factory()->create(['order_id' => $order->id, 'equipment_id' => $alat->id]);
        }

        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $org->id,
            'equipment_id' => $alat->id,
            'teknisi_id' => ($o['teknisi'] ?? null)?->id
                ?? User::factory()->create(['organization_id' => $org->id, 'role' => User::ROLE_TEKNISI])->id,
            'order_item_id' => $item?->id,
            'nomor_sesi' => 'KAL/2026/11/'.str_pad((string) (9000 + $n), 4, '0', STR_PAD_LEFT),
            'nomor_order' => $o['nomor_order'] ?? null,
            'tanggal_kalibrasi' => $o['tanggal_kalibrasi'] ?? now()->subDay()->toDateString(),
            ...($o['sesi'] ?? []),
        ]);

        $sertifikat = Certificate::factory()->create([
            'organization_id' => $org->id,
            'calibration_session_id' => $sesi->id,
            'nomor' => $o['nomor'] ?? 'CAL/2026/11/'.str_pad((string) (5000 + $n), 4, '0', STR_PAD_LEFT),
            'status' => $o['status'] ?? Certificate::STATUS_TERBIT,
            'diterbitkan_pada' => array_key_exists('terbit', $o) ? $o['terbit'] : '2026-11-12',
            ...($o['sertifikat'] ?? []),
        ]);

        if ($o['hapus_alat'] ?? false) {
            $alat->delete();
        }

        return compact('pt', 'alat', 'sesi', 'sertifikat', 'order');
    }
}

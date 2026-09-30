<?php

namespace App\Services\Pelanggan;

use App\Mail\Pelanggan\RingkasanMingguanEmail;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\PermintaanKalibrasi;
use App\Models\User;
use App\Support\Mailer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Ringkasan email mingguan untuk anggota pelanggan yang menyalakan saklar
 * `ringkasan_email_mingguan` (PL_Preferensi, §42 B7).
 *
 * Isinya empat hal yang sama dengan kartu beranda aplikasi pelanggan: alat
 * jatuh tempo (≤ 30 hari & yang sudah lewat), sertifikat terbit 7 hari
 * terakhir, paket berjalan, permintaan aktif. Minggu tanpa satu pun isi TIDAK
 * dikirimi — email "tidak ada apa-apa" tiap Senin itu yang membuat orang
 * mematikan semua email dari kita, termasuk yang penting.
 *
 * Dua pagar kiriman ganda:
 * - `withoutOverlapping` di jadwalnya (dua container yang memicu scheduler);
 * - kunci cache per orang per perusahaan per minggu ISO — perintah yang
 *   dijalankan ulang tangan di minggu yang sama tidak mengirim lagi.
 */
class RingkasanMingguan
{
    /** Jendela "segera jatuh tempo" = anak tangga pertama pengingat push. */
    public const JENDELA_HARI = PengingatJatuhTempoPelanggan::TANGGA_HARI[0];

    public const BATAS_BARIS = 10;

    public function __construct(private readonly PreferensiNotifikasi $preferensi) {}

    /**
     * @return array{dilewati: string|null, perusahaan: int, dikirim: int, kosong: int, sudah: int, gagal: int}
     */
    public function jalankan(bool $kosongan = false): array
    {
        $hasil = ['dilewati' => null, 'perusahaan' => 0, 'dikirim' => 0, 'kosong' => 0, 'sudah' => 0, 'gagal' => 0];

        // Modul pelanggan mati = tidak ada pelanggan yang bisa membuka tautan
        // di emailnya. Diam, bukan mengirim ke aplikasi yang tertutup.
        if (config('pelanggan.fitur') !== true) {
            $hasil['dilewati'] = 'FITUR_PELANGGAN mati';

            return $hasil;
        }

        Customer::query()
            ->whereHas('members', fn (Builder $m) => $m->where('status', CustomerMember::STATUS_AKTIF))
            ->orderBy('id')
            ->each(function (Customer $pelanggan) use (&$hasil, $kosongan): void {
                $penerima = $this->preferensi->penerima($pelanggan, PreferensiNotifikasi::RINGKASAN_EMAIL_MINGGUAN)
                    ->filter(fn (User $u) => filled($u->email));

                if ($penerima->isEmpty()) {
                    return;
                }

                $hasil['perusahaan']++;
                $isi = $this->untuk($pelanggan);

                if ($this->kosong($isi)) {
                    $hasil['kosong']++;

                    return;
                }

                foreach ($penerima as $user) {
                    if ($kosongan) {
                        $hasil['dikirim']++;

                        continue;
                    }

                    $kunci = 'ringkasan-mingguan:'.$user->id.':'.$pelanggan->id.':'.now()->format('o-W');

                    // `add` = cuma berhasil kalau kuncinya BELUM ada.
                    if (! Cache::add($kunci, true, now()->addDays(8))) {
                        $hasil['sudah']++;

                        continue;
                    }

                    try {
                        Mail::to($user->email)->send(new RingkasanMingguanEmail((string) $user->name, (string) $pelanggan->nama, $isi));
                        $hasil['dikirim']++;
                    } catch (\Throwable $e) {
                        // Kunci dilepas supaya jalan berikutnya boleh mencoba lagi.
                        Cache::forget($kunci);
                        $hasil['gagal']++;
                        Log::warning('Ringkasan mingguan gagal dikirim.', [
                            'user_id' => $user->id,
                            'customer_id' => $pelanggan->id,
                            'pesan' => $e->getMessage(),
                        ]);
                    }
                }
            });

        if (! $kosongan && $hasil['dikirim'] > 0 && ($tak = Mailer::takMengirim()) !== null) {
            Log::warning("Ringkasan mingguan \"terkirim\" lewat mailer `{$tak}` — tidak ada yang benar-benar sampai.", $hasil);
        }

        return $hasil;
    }

    /**
     * Isi ringkasan satu perusahaan. Dipisah supaya bisa dites & dipratinjau
     * tanpa mengirim apa pun.
     *
     * @return array{jatuh_tempo: list<array<string, mixed>>, sertifikat_baru: list<array<string, mixed>>, paket_berjalan: int, permintaan_aktif: int}
     */
    public function untuk(Customer $pelanggan): array
    {
        $jatuhTempo = Equipment::query()
            ->where('organization_id', $pelanggan->organization_id)
            ->where('customer_id', $pelanggan->id)
            ->where('status', Equipment::STATUS_AKTIF)
            ->whereNotNull('tanggal_jatuh_tempo')
            ->whereDate('tanggal_jatuh_tempo', '<=', now()->addDays(self::JENDELA_HARI))
            ->orderBy('tanggal_jatuh_tempo')
            ->limit(self::BATAS_BARIS)
            ->get(['id', 'nama_alat', 'serial_number', 'tanggal_jatuh_tempo'])
            ->map(fn (Equipment $a) => [
                'nama' => $a->nama_alat,
                'serial' => $a->serial_number,
                'tanggal' => $a->tanggal_jatuh_tempo->toDateString(),
                'hari' => (int) now()->startOfDay()->diffInDays($a->tanggal_jatuh_tempo->copy()->startOfDay(), false),
            ])
            ->values()
            ->all();

        $sertifikatBaru = Certificate::query()
            ->where('organization_id', $pelanggan->organization_id)
            ->where('status', Certificate::STATUS_TERBIT)
            ->belumDigantikan()
            ->whereDate('diterbitkan_pada', '>=', now()->subDays(7))
            ->whereHas('session.equipment', fn (Builder $a) => $a->withTrashed()->where('customer_id', $pelanggan->id))
            ->orderByDesc('diterbitkan_pada')
            ->limit(self::BATAS_BARIS)
            ->get(['id', 'nomor', 'snapshot', 'diterbitkan_pada'])
            ->map(fn (Certificate $c) => [
                'nomor' => $c->nomor,
                'alat' => $c->snapshot['header']['equipment_name'] ?? null,
                'tanggal' => $c->diterbitkan_pada?->toDateString(),
            ])
            ->values()
            ->all();

        return [
            'jatuh_tempo' => $jatuhTempo,
            'sertifikat_baru' => $sertifikatBaru,
            'paket_berjalan' => Order::query()
                ->where('organization_id', $pelanggan->organization_id)
                ->where('customer_id', $pelanggan->id)
                ->whereNotIn('status', [Order::STATUS_SELESAI, Order::STATUS_DIBATALKAN])
                ->count(),
            'permintaan_aktif' => PermintaanKalibrasi::query()
                ->where('organization_id', $pelanggan->organization_id)
                ->where('customer_id', $pelanggan->id)
                ->whereIn('status', PermintaanKalibrasi::statusAktif())
                ->count(),
        ];
    }

    /** @param  array{jatuh_tempo: array<mixed>, sertifikat_baru: array<mixed>, paket_berjalan: int, permintaan_aktif: int}  $isi */
    public function kosong(array $isi): bool
    {
        return $isi['jatuh_tempo'] === [] && $isi['sertifikat_baru'] === []
            && $isi['paket_berjalan'] === 0 && $isi['permintaan_aktif'] === 0;
    }
}

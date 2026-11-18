<?php

namespace App\Services;

use App\Exceptions\Pelanggan\AksiPelangganDitolak;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Memindahkan ALAT antar PT pelanggan dalam satu laboratorium (kontrak:
 * `docs/perintah-frontend-super-admin.md` §3.7).
 *
 * ## Yang berubah: SATU kolom
 *
 * `equipments.customer_id`. Sertifikat ikut berpindah **tanpa disentuh**, karena
 * kepemilikannya selalu dibaca lewat alat sesinya (`LingkupData::sertifikat()`,
 * `RapikanArsip`, daftar lab) — tidak ada kolom pelanggan di baris sertifikat.
 * Nomor, `snapshot`, PDF, dan QR sertifikat tidak ditulis sama sekali: dokumen
 * terbit itu terkendali, dan nama pemilik yang tercetak di dalamnya memang tetap
 * nama pada hari terbit.
 *
 * ## Yang SENGAJA tidak dipindah (dan dikatakan di pratinjau)
 *
 * - **Baris paket/order** (`order_items` → `orders.customer_id`). Satu order milik
 *   satu PT dan bisa memuat alat dari banyak sumber; memecah atau memindahkannya
 *   adalah keputusan orang, bukan efek samping. Jumlahnya dilaporkan.
 * - **Berkas arsip.** Folder arsip mengikuti PT; memindahkannya tugas
 *   `arsip:rapikan` (dijalankan pemilik), yang membaca pemilik dari `alat.customer_id`.
 *
 * ## Pratinjau dan eksekusi memakai SATU perhitungan
 *
 * `pratinjau()` dipanggil di kedua mode; eksekusi mengembalikan hitungan yang
 * sama setelah menulis. Pratinjau yang dihitung terpisah dari eksekusi bisa
 * berjanji satu hal dan melakukan hal lain.
 */
class PemindahanAlatPelanggan
{
    /** Daftar orang di pratinjau dibatasi supaya respons tetap kecil. */
    private const MAKS_ORANG = 50;

    /**
     * @param  Collection<int, Equipment>  $alat  alat PT asal yang sudah diperiksa pemanggil
     * @return array<string, mixed>
     */
    public function pratinjau(Customer $asal, Customer $tujuan, Collection $alat): array
    {
        $organisasi = (int) $asal->organization_id;
        $ids = $alat->pluck('id')->map(fn ($id) => (int) $id)->all();

        $sertifikat = $this->hitungSertifikat($ids, $organisasi);

        $aktifAsal = $this->anggotaAktif($asal);
        $aktifTujuan = $this->anggotaAktif($tujuan);

        return [
            'dry_run' => true,
            'dari' => $this->ringkasPt($asal, $aktifAsal->count()),
            'ke' => $this->ringkasPt($tujuan, $aktifTujuan->count()),
            'jumlah_alat' => count($ids),
            'alat' => $alat->sortBy('id')->map(fn (Equipment $a) => [
                'id' => $a->id,
                'nama_alat' => $a->nama_alat,
                'serial_number' => $a->serial_number,
                'jumlah_sertifikat' => $sertifikat['per_alat'][$a->id] ?? 0,
            ])->values()->all(),
            'sertifikat_terdampak' => $sertifikat['semua'],
            'sertifikat_terlihat_pelanggan' => $sertifikat['terlihat'],
            // Mulai melihat = anggota aktif TUJUAN yang bukan anggota aktif ASAL
            // (konsultan di dua PT itu sudah melihatnya); berhenti = kebalikannya.
            'anggota_mulai_melihat' => $this->orang($aktifTujuan, $aktifAsal),
            'anggota_berhenti_melihat' => $this->orang($aktifAsal, $aktifTujuan),
            'paket_terkait_count' => DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.organization_id', $organisasi)
                ->whereIn('order_items.equipment_id', $ids)
                ->count(),
            'peringatan' => [
                ['kode' => 'paket_tidak_dipindah', 'pesan' => 'Baris paket/order yang menunjuk alat ini TIDAK ikut pindah.'],
                ['kode' => 'arsip_tidak_dipindah', 'pesan' => 'Berkas arsip lama tetap di folder PT asal sampai pemilik menjalankan perapian arsip.'],
                ['kode' => 'isi_sertifikat_tetap', 'pesan' => 'Nomor dan isi sertifikat (termasuk nama pemilik yang tercetak) tidak diubah.'],
            ],
        ];
    }

    /**
     * Pindahkan, dalam SATU transaksi, dan tulis jejaknya.
     *
     * Barisnya dikunci (`lockForUpdate`) dan DIPERIKSA ULANG di dalam transaksi:
     * antara pratinjau dan eksekusi admin lain bisa memindahkan alat yang sama,
     * dan eksekusi buta menimpa keputusannya tanpa jejak yang masuk akal.
     *
     * @param  list<int>  $idAlat
     * @return array<string, mixed>
     */
    public function jalankan(Customer $asal, Customer $tujuan, array $idAlat, User $pelaku, string $alasan): array
    {
        $organisasi = (int) $asal->organization_id;

        return DB::transaction(function () use ($asal, $tujuan, $idAlat, $pelaku, $alasan, $organisasi): array {
            $terkunci = Equipment::query()
                ->where('organization_id', $organisasi)
                ->whereIn('id', $idAlat)
                ->lockForUpdate()
                ->get();

            if ($terkunci->count() !== count($idAlat)
                || $terkunci->contains(fn (Equipment $a) => (int) $a->customer_id !== (int) $asal->getKey())) {
                throw AksiPelangganDitolak::alatSudahPindah();
            }

            $hasil = $this->pratinjau($asal, $tujuan, $terkunci);

            foreach ($terkunci as $alat) {
                // `Diaudit` menulis baris `diubah` (customer_id lama → baru) atas nama
                // akun yang login.
                $alat->forceFill(['customer_id' => $tujuan->getKey()])->save();
            }

            AuditLog::create([
                'organization_id' => $organisasi,
                'entity_type' => $asal->getTable(),
                'entity_id' => $asal->getKey(),
                'action' => AuditLog::ACTION_ALAT_DIPINDAH_PT,
                'old_data' => ['customer_id' => $asal->getKey()],
                'new_data' => [
                    'customer_id' => $tujuan->getKey(),
                    'dari_customer_id' => $asal->getKey(),
                    'ke_customer_id' => $tujuan->getKey(),
                    'alat_ids' => $terkunci->pluck('id')->sort()->values()->all(),
                    'sertifikat_terdampak' => $hasil['sertifikat_terdampak'],
                    'peran_pelaku' => $pelaku->role,
                    'alasan' => $alasan,
                ],
                'changed_by' => $pelaku->getKey(),
                // Nama PT dipendekkan (kolom `nama` sampai 255) dan seluruh catatan dipotong ke
                // panjang kolom `note`; PT & alasan LENGKAP ada di `new_data` / baris `customers`.
                'note' => AuditLog::catatan(
                    'Alat dipindah dari "'.Str::limit((string) $asal->nama, 60).'" ke "'.Str::limit((string) $tujuan->nama, 60)
                    .'" oleh '.$pelaku->role.'. Alasan: '.$alasan,
                ),
            ]);

            // Anggota PT asal/tujuan setelah pindah tidak berubah — yang berubah
            // alatnya. Hitungan yang dikembalikan adalah hitungan yang dijanjikan
            // pratinjau.
            return [
                'dry_run' => false,
                'dari' => $hasil['dari'],
                'ke' => $hasil['ke'],
                'jumlah_alat' => $hasil['jumlah_alat'],
                'alat_ids' => $terkunci->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
                'sertifikat_terdampak' => $hasil['sertifikat_terdampak'],
                'sertifikat_terlihat_pelanggan' => $hasil['sertifikat_terlihat_pelanggan'],
            ];
        });
    }

    /**
     * @param  list<int>  $idAlat
     * @return array{semua: int, terlihat: int, per_alat: array<int, int>}
     */
    private function hitungSertifikat(array $idAlat, int $organisasi): array
    {
        $baris = DB::table('certificates')
            ->join('calibration_sessions', 'calibration_sessions.id', '=', 'certificates.calibration_session_id')
            ->where('certificates.organization_id', $organisasi)
            ->whereIn('calibration_sessions.equipment_id', $idAlat)
            ->groupBy('calibration_sessions.equipment_id')
            ->selectRaw(
                'calibration_sessions.equipment_id as alat_id, count(*) as semua, '
                .'sum(case when certificates.status in (?, ?) then 1 else 0 end) as terlihat',
                [Certificate::STATUS_TERBIT, Certificate::STATUS_DIBATALKAN],
            )
            ->get();

        return [
            'semua' => (int) $baris->sum('semua'),
            'terlihat' => (int) $baris->sum('terlihat'),
            'per_alat' => $baris->mapWithKeys(fn ($b) => [(int) $b->alat_id => (int) $b->semua])->all(),
        ];
    }

    /** @return Collection<int, CustomerMember> anggota AKTIF perusahaan, dengan akunnya */
    private function anggotaAktif(Customer $perusahaan): Collection
    {
        return CustomerMember::query()
            ->with('user:id,name,email')
            ->where('organization_id', $perusahaan->organization_id)
            ->where('customer_id', $perusahaan->getKey())
            ->where('status', CustomerMember::STATUS_AKTIF)
            ->orderBy('id')
            ->get();
    }

    /**
     * Orang pada `$daftar` yang TIDAK ada di `$pembanding` (menurut akun), maksimum 50.
     *
     * @param  Collection<int, CustomerMember>  $daftar
     * @param  Collection<int, CustomerMember>  $pembanding
     * @return list<array{id: int, nama: string|null, email: string|null}>
     */
    private function orang(Collection $daftar, Collection $pembanding): array
    {
        $di = $pembanding->pluck('user_id')->all();

        return $daftar
            ->reject(fn (CustomerMember $m) => in_array($m->user_id, $di, true))
            ->take(self::MAKS_ORANG)
            ->map(fn (CustomerMember $m) => [
                'id' => (int) $m->user_id,
                'nama' => $m->user?->name,
                'email' => $m->user?->email,
            ])
            ->values()
            ->all();
    }

    /** @return array{id: int, nama: string, anggota_aktif_count: int} */
    private function ringkasPt(Customer $pt, int $anggotaAktif): array
    {
        return ['id' => (int) $pt->getKey(), 'nama' => (string) $pt->nama, 'anggota_aktif_count' => $anggotaAktif];
    }
}

<?php

namespace App\Services\Permintaan;

use App\Events\PerubahanDataOrganisasi;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\PermintaanKalibrasi;
use App\Models\PermintaanKalibrasiItem;
use App\Models\PesanPermintaan;
use App\Models\User;
use App\Notifications\Pelanggan\PermintaanDiputuskan;
use App\Notifications\Pelanggan\PesanLabBaru;
use App\Notifications\PermintaanKalibrasiBaru;
use App\Notifications\PesanPermintaanDariPelanggan;
use App\Services\Pelanggan\PreferensiNotifikasi;
use App\Services\PenerimaNotifikasi;
use App\Services\PenomoranOrder;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Kabar;
use Illuminate\Validation\ValidationException;

/**
 * Seluruh alur permintaan kalibrasi pelanggan — SATU tempat, dipakai dua sisi.
 *
 * Controller pelanggan dan controller lab sama-sama memanggil kelas ini, jadi
 * aturan ("hanya yang masih `baru` yang bisa diputuskan", "percakapan ditutup
 * setelah ditolak") tidak punya dua salinan yang bisa berbeda. Controller cuma
 * memeriksa SIAPA yang boleh memanggil dan baris MILIK siapa; apa yang terjadi
 * sesudahnya urusan di sini.
 *
 * ## Keputusan pemilik proyek (30 Sep 2026) yang dikodekan di sini
 *
 * 1. Ajuan masuk ke SEMUA admin aktif; siapa pun boleh memprosesnya.
 * 2. Status awal `baru`. Admin meninjau lalu MENERIMA (lahir Order + OrderItem,
 *    dan Equipment untuk alat baru) atau MENOLAK dengan alasan yang dibaca
 *    pelanggan. Tidak pernah otomatis.
 * 3. Isi: alat terdaftar dan/atau alat baru, catatan, cara pengantaran.
 * 4. Satu utas pesan per permintaan.
 */
class AlurPermintaan
{
    public function __construct(
        private readonly PenerimaNotifikasi $admin,
        private readonly PreferensiNotifikasi $preferensi,
        private readonly PenomoranOrder $nomorOrder,
    ) {}

    // ------------------------------------------------------------------ pelanggan

    /**
     * @param  array{metode_pengantaran: string, tanggal_diinginkan_dari?: ?string, tanggal_diinginkan_sampai?: ?string, catatan?: ?string, alat_id?: list<int>, alat_baru?: list<array<string, mixed>>}  $data
     */
    public function ajukan(Customer $pelanggan, User $pemohon, array $data): PermintaanKalibrasi
    {
        $permintaan = DB::transaction(function () use ($pelanggan, $pemohon, $data): PermintaanKalibrasi {
            $permintaan = PermintaanKalibrasi::create([
                // Dari PERUSAHAAN yang sudah terlepas dari request, tidak pernah
                // dari body (AGENTS.md §Modul Pelanggan butir 3).
                'organization_id' => $pelanggan->organization_id,
                'customer_id' => $pelanggan->id,
                'diajukan_oleh' => $pemohon->id,
                'nomor' => $this->nomorBerikutnya((int) $pelanggan->organization_id),
                'status' => PermintaanKalibrasi::STATUS_BARU,
                'metode_pengantaran' => $data['metode_pengantaran'],
                'tanggal_diinginkan_dari' => $data['tanggal_diinginkan_dari'] ?? null,
                'tanggal_diinginkan_sampai' => $data['tanggal_diinginkan_sampai'] ?? null,
                'catatan' => $data['catatan'] ?? null,
            ]);

            foreach (array_unique($data['alat_id'] ?? []) as $equipmentId) {
                $permintaan->items()->create(['equipment_id' => $equipmentId]);
            }

            foreach ($data['alat_baru'] ?? [] as $alat) {
                $permintaan->items()->create(['alat_baru' => $alat]);
            }

            return $permintaan;
        });

        $permintaan->load(['customer:id,nama', 'items']);

        // Di LUAR transaksi, dan dibungkus: lihat `kabari()`.
        $this->kabari(
            $this->admin->adminAktif((int) $permintaan->organization_id),
            PermintaanKalibrasiBaru::dari($permintaan),
            $permintaan,
        );

        PerubahanDataOrganisasi::siarkanAman((int) $permintaan->organization_id, 'permintaan', 'dibuat', $permintaan->id);

        return $permintaan;
    }

    /** Pelanggan membatalkan — hanya selama belum diputuskan lab. */
    public function batalkan(PermintaanKalibrasi $permintaan): PermintaanKalibrasi
    {
        DB::transaction(function () use ($permintaan): void {
            $baris = $this->kunci($permintaan);

            $this->pastikanMasihBaru($baris, 'dibatalkan');

            $baris->update([
                'status' => PermintaanKalibrasi::STATUS_DIBATALKAN,
                'dibatalkan_pada' => now(),
            ]);

            $permintaan->setRawAttributes($baris->getAttributes(), true);
        });

        PerubahanDataOrganisasi::siarkanAman((int) $permintaan->organization_id, 'permintaan', 'dibatalkan', $permintaan->id);

        return $permintaan;
    }

    // ------------------------------------------------------------------ lab

    /**
     * Admin menerima: lahir Order + OrderItem, dan Equipment untuk alat baru.
     *
     * Semuanya SATU transaksi. Order tanpa alat, atau alat baru yang lahir
     * sementara ordernya gagal, adalah data setengah jadi yang tidak bisa
     * dibetulkan dari layar. Baris permintaan dikunci (`lockForUpdate`) dan
     * statusnya dibaca ULANG di dalam kunci: dua admin yang menekan Terima
     * bersamaan tidak boleh melahirkan dua order untuk satu ajuan.
     *
     * `$opsi['alat_baru']` memuat, per item alat baru, kategori di lab (wajib) dan
     * nomor seri (wajib kalau pelanggan membiarkannya kosong — `equipments.serial_number`
     * NOT NULL).
     *
     * @param  array<string, mixed>  $opsi
     */
    public function terima(PermintaanKalibrasi $permintaan, User $admin, array $opsi = []): PermintaanKalibrasi
    {
        $order = DB::transaction(function () use ($permintaan, $admin, $opsi): Order {
            $baris = $this->kunci($permintaan);

            $this->pastikanMasihBaru($baris, 'diterima');

            $items = $baris->items()->get();

            // Semua alat baru HARUS lengkap (kategori + nomor seri unik) sebelum
            // satu baris pun ditulis — kalau tidak, alat ketiga yang
            // kekurangan data membatalkan transaksi setelah dua alat pertama
            // dibuat, dan pesannya datang sebagai 500 dari batas UNIQUE.
            $rencana = $this->rencanaAlatBaru($baris, $items, $opsi['alat_baru'] ?? []);

            $order = Order::create([
                'organization_id' => $baris->organization_id,
                'customer_id' => $baris->customer_id,
                'diterima_oleh' => $admin->id,
                'nomor' => $this->nomorOrder->berikutnya((int) $baris->organization_id),
                'tanggal_masuk' => $opsi['tanggal_masuk'] ?? now()->toDateString(),
                'tanggal_janji_selesai' => $opsi['tanggal_janji_selesai'] ?? null,
                'status' => Order::STATUS_BARU,
                'catatan' => $this->catatanOrder($baris, $opsi['catatan'] ?? null),
            ]);

            foreach ($items as $item) {
                if ($item->alatBaru()) {
                    $alat = $this->buatAlat($baris, $item, $rencana[$item->id]);
                    $item->equipment_id = $alat->id;

                    // Foto pelat nama ikut ke alat yang lahir — sebagai BARIS
                    // KEDUA yang menunjuk berkas yang sama, bukan dipindah:
                    // permintaan tetap menyimpan buktinya, alat mendapat
                    // fotonya. `FotoPelangganLayanan::hapus` cuma membuang
                    // berkas kalau tidak ada baris lain yang masih memakainya.
                    foreach ($item->foto()->get() as $foto) {
                        $alat->fotoPelat()->create(
                            $foto->only(['organization_id', 'customer_id', 'path', 'mime', 'ukuran', 'diunggah_oleh']),
                        );
                    }
                }

                $barisOrder = $order->items()->create(['equipment_id' => $item->equipment_id]);

                $item->order_item_id = $barisOrder->id;
                $item->save();
            }

            $baris->update([
                'status' => PermintaanKalibrasi::STATUS_DITERIMA,
                'diputuskan_oleh' => $admin->id,
                'diputuskan_pada' => now(),
                'order_id' => $order->id,
            ]);

            $permintaan->setRawAttributes($baris->getAttributes(), true);

            return $order;
        });

        $permintaan->load('customer');

        $this->kabari(
            $this->preferensi->penerima($permintaan->customer, PreferensiNotifikasi::STATUS_PERMINTAAN),
            PermintaanDiputuskan::diterima($permintaan),
            $permintaan,
        );

        PerubahanDataOrganisasi::siarkanAman((int) $permintaan->organization_id, 'permintaan', 'diterima', $permintaan->id);
        PerubahanDataOrganisasi::siarkanAman((int) $permintaan->organization_id, 'paket', 'dibuat', $order->id);

        return $permintaan;
    }

    /** Admin menolak. Alasan WAJIB — pelanggan membacanya. */
    public function tolak(PermintaanKalibrasi $permintaan, User $admin, string $alasan): PermintaanKalibrasi
    {
        $alasan = trim($alasan);

        if ($alasan === '') {
            throw ValidationException::withMessages(['alasan' => 'Alasan penolakan wajib diisi.']);
        }

        DB::transaction(function () use ($permintaan, $admin, $alasan): void {
            $baris = $this->kunci($permintaan);

            $this->pastikanMasihBaru($baris, 'ditolak');

            $baris->update([
                'status' => PermintaanKalibrasi::STATUS_DITOLAK,
                'alasan_penolakan' => $alasan,
                'diputuskan_oleh' => $admin->id,
                'diputuskan_pada' => now(),
            ]);

            $permintaan->setRawAttributes($baris->getAttributes(), true);
        });

        $permintaan->load('customer');

        $this->kabari(
            $this->preferensi->penerima($permintaan->customer, PreferensiNotifikasi::STATUS_PERMINTAAN),
            PermintaanDiputuskan::ditolak($permintaan),
            $permintaan,
        );

        PerubahanDataOrganisasi::siarkanAman((int) $permintaan->organization_id, 'permintaan', 'ditolak', $permintaan->id);

        return $permintaan;
    }

    // ------------------------------------------------------------------ pesan

    /** @param  string  $sisi  PesanPermintaan::SISI_* */
    public function kirimPesan(PermintaanKalibrasi $permintaan, User $pengirim, string $sisi, string $isi): PesanPermintaan
    {
        if (! $permintaan->percakapanTerbuka()) {
            // Ditolak/dibatalkan = urusannya selesai. Membiarkan utas terus
            // menerima pesan membuat admin membalas ke ajuan yang sudah tidak
            // ada di antreannya, dan tidak ada yang akan membacanya.
            throw ValidationException::withMessages([
                'isi' => 'Percakapan ini sudah ditutup karena permintaannya '.$permintaan->status.'.',
            ]);
        }

        $pesan = $permintaan->pesan()->create([
            'pengirim_id' => $pengirim->id,
            'sisi' => $sisi,
            'isi' => trim($isi),
        ]);

        $permintaan->loadMissing('customer:id,nama,organization_id');

        if ($sisi === PesanPermintaan::SISI_LAB) {
            $this->kabari(
                $this->preferensi->penerima($permintaan->customer, PreferensiNotifikasi::PESAN_LAB),
                PesanLabBaru::dari($permintaan),
                $permintaan,
            );
        } else {
            $this->kabari(
                $this->admin->adminAktif((int) $permintaan->organization_id),
                PesanPermintaanDariPelanggan::dari($permintaan),
                $permintaan,
            );
        }

        PerubahanDataOrganisasi::siarkanAman((int) $permintaan->organization_id, 'permintaan', 'pesan', $permintaan->id);

        return $pesan;
    }

    // ------------------------------------------------------------------ dalam

    private function kunci(PermintaanKalibrasi $permintaan): PermintaanKalibrasi
    {
        return PermintaanKalibrasi::query()->lockForUpdate()->findOrFail($permintaan->id);
    }

    private function pastikanMasihBaru(PermintaanKalibrasi $baris, string $tujuan): void
    {
        if (! $baris->masihBaru()) {
            throw ValidationException::withMessages([
                'status' => "Permintaan ini tidak bisa {$tujuan}: statusnya sudah \"{$baris->status}\".",
            ]);
        }
    }

    /**
     * Alat baru dari ajuan → baris `equipments` milik pelanggan itu.
     *
     * Nilainya disalin apa adanya dari yang diketik pelanggan; admin merapikan
     * sesudahnya lewat layar alat biasa. `customer_id` & `organization_id`
     * diambil dari ajuannya (yang sudah dikunci ke perusahaan), bukan dari JSON.
     *
     * @param  array{kategori: int, serial: string}  $rencana  hasil `rencanaAlatBaru()`
     */
    private function buatAlat(PermintaanKalibrasi $permintaan, PermintaanKalibrasiItem $item, array $rencana): Equipment
    {
        $a = $item->alat_baru ?? [];

        return Equipment::create([
            'organization_id' => $permintaan->organization_id,
            'customer_id' => $permintaan->customer_id,
            'equipment_category_id' => $rencana['kategori'],
            'nama_alat' => $a['nama_alat'],
            'merk' => $a['merk'] ?? null,
            'model' => $a['model'] ?? null,
            'serial_number' => $rencana['serial'],
            'no_identifikasi' => $a['no_identifikasi'] ?? null,
            'range_min' => $a['rentang_min'] ?? null,
            'range_max' => $a['rentang_maks'] ?? null,
            'satuan' => $a['satuan'] ?? null,
            'resolusi' => $a['resolusi'] ?? null,
            'lokasi' => $a['lokasi'] ?? null,
            // Jejak asal-usul: alat ini lahir dari ketikan pelanggan, belum
            // diperiksa lab. Tanpa catatan ini, di daftar alat ia tak
            // terbedakan dari alat yang didaftarkan teknisi dari sertifikat.
            'catatan' => trim('Diajukan pelanggan lewat permintaan '.$permintaan->nomor.'. '.($a['catatan'] ?? '')),
            'status' => Equipment::STATUS_AKTIF,
        ]);
    }

    /**
     * Periksa semua alat baru SEKALIGUS dan kembalikan rencananya per item.
     *
     * Nomor seri wajib ada dan unik per organisasi (`equipments` punya
     * UNIQUE(organization_id, serial_number), TERMASUK baris yang sudah
     * dihapus lunak). Pelanggan boleh mengosongkannya di formulir (alat tanpa
     * pelat nama), jadi admin yang mengisinya saat menerima. Tabrakan dijawab
     * 422 yang menyebut alatnya — bukan 500 dari batas UNIQUE basis data.
     *
     * @param  Collection<int, PermintaanKalibrasiItem>  $items
     * @param  list<array{item_id: int, equipment_category_id: int, serial_number?: ?string}>  $masukan
     * @return array<int, array{kategori: int, serial: string}>
     */
    private function rencanaAlatBaru(PermintaanKalibrasi $permintaan, Collection $items, array $masukan): array
    {
        $perItem = collect($masukan)->keyBy('item_id');
        $rencana = [];
        $galat = [];
        $dipakaiDiAjuan = [];

        foreach ($items->filter->alatBaru() as $item) {
            $nama = $item->alat_baru['nama_alat'] ?? '?';
            $isian = $perItem->get($item->id, []);

            $kategori = $isian['equipment_category_id'] ?? null;

            if ($kategori === null) {
                $galat["alat_baru.{$item->id}.equipment_category_id"] = "Pilih kategori untuk alat baru \"{$nama}\".";
            }

            $serial = trim((string) (($isian['serial_number'] ?? null) ?: ($item->alat_baru['serial_number'] ?? '')));

            if ($serial === '') {
                $galat["alat_baru.{$item->id}.serial_number"] = "Isi nomor seri untuk alat baru \"{$nama}\" — kolom ini wajib di data alat.";

                continue;
            }

            $bentrok = isset($dipakaiDiAjuan[$serial])
                || Equipment::withTrashed()
                    ->where('organization_id', $permintaan->organization_id)
                    ->where('serial_number', $serial)
                    ->exists();

            if ($bentrok) {
                $galat["alat_baru.{$item->id}.serial_number"] = "Nomor seri \"{$serial}\" untuk \"{$nama}\" sudah dipakai alat lain di lab ini. Ubah nomor serinya, atau tolak permintaan ini dan minta pelanggan memilih alat yang sudah terdaftar.";

                continue;
            }

            $dipakaiDiAjuan[$serial] = true;

            if ($kategori !== null) {
                $rencana[$item->id] = ['kategori' => (int) $kategori, 'serial' => $serial];
            }
        }

        if ($galat !== []) {
            throw ValidationException::withMessages($galat);
        }

        return $rencana;
    }

    private function catatanOrder(PermintaanKalibrasi $permintaan, ?string $catatanAdmin): string
    {
        $cara = $permintaan->metode_pengantaran === PermintaanKalibrasi::METODE_DIAMBIL_LAB
            ? 'diambil lab'
            : 'diantar sendiri';

        return collect([
            "Dari permintaan pelanggan {$permintaan->nomor} ({$cara}).",
            filled($permintaan->catatan) ? 'Catatan pelanggan: '.$permintaan->catatan : null,
            filled($catatanAdmin) ? $catatanAdmin : null,
        ])->filter()->implode("\n");
    }

    /** PMT/2026/09/0001 — urut per organisasi per bulan. Wajib di dalam transaksi. */
    private function nomorBerikutnya(int $organizationId): string
    {
        $prefix = sprintf('PMT/%s/', now()->format('Y/m'));

        $terakhir = PermintaanKalibrasi::query()
            ->where('organization_id', $organizationId)
            ->where('nomor', 'like', $prefix.'%')
            ->lockForUpdate()
            ->orderByDesc('nomor')
            ->value('nomor');

        $urutan = $terakhir ? ((int) substr($terakhir, -4)) + 1 : 1;

        return $prefix.str_pad((string) $urutan, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Kirim notifikasi tanpa pernah menggagalkan permintaan yang memicunya.
     *
     * Notifikasi ini ikut saluran `broadcast`; Reverb yang mati melempar
     * exception SESUDAH datanya tersimpan. Tanpa `try`, pemanggilnya dijawab
     * 500, menekan tombol lagi, dan lahir ajuan/pesan kembar. Kotak masuk di
     * aplikasi (saluran `database`) tetap sumber kebenarannya — pola yang sama
     * dengan `PenugasanController::store`.
     *
     * @param  Collection<int, User>|iterable<User>  $penerima
     */
    private function kabari(iterable $penerima, Notification $notifikasi, PermintaanKalibrasi $permintaan): void
    {
        try {
            Kabar::send($penerima, $notifikasi);
        } catch (\Throwable $e) {
            Log::warning('Notifikasi permintaan kalibrasi gagal dikirim.', [
                'permintaan_id' => $permintaan->id,
                'notifikasi' => $notifikasi::class,
                'pesan' => $e->getMessage(),
            ]);
        }
    }
}

<?php

namespace Tests\Feature\Pelanggan;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\DeviceToken;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Pelanggan\TahapAlatBerubah;
use App\Services\Pelanggan\KabarTahapAlat;
use App\Services\Pelanggan\PreferensiNotifikasi;
use App\Services\Push\PengirimPush;
use App\Services\TahapPaket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Kabar perubahan TAHAP ALAT ke anggota perusahaan pemilik paket (13 Nov 2026).
 *
 * Yang dijaga, dan semuanya TIDAK menghasilkan error kalau salah:
 *  - penerima: hanya perusahaan pemilik paket (PT lain di lab yang sama diam);
 *  - saklar `tahap_alat` dihormati (dan bawaannya menyala);
 *  - hanya perubahan yang TERLIHAT pelanggan;
 *  - sekali per peristiwa;
 *  - isinya bebas data internal.
 *
 * Jalur fisik ditempuh lewat API sungguhan (`POST /pelacakan/item/{i}/tahap-fisik`);
 * jalur turunan lewat penyimpanan sesi (observer). Antrean `sync` di test.
 */
class KabarTahapAlatTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private Organization $org;

    private User $admin;

    private User $teknisi;

    private Customer $ptA;

    private Customer $ptB;

    private User $picA;

    private User $stafA;

    private User $picB;

    private Order $paketA;

    private Equipment $alatA;

    private OrderItem $itemA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->siapkanJalurPelanggan();
        // Kabar tahap TURUNAN bawaan MATI di produksi (`KABAR_TAHAP_TURUNAN=false`); kelas ini menguji
        // perilakunya saat DINYALAKAN. Bawaan mati dan pengaruhnya dites di bagian akhir kelas ini.
        config(['pelanggan.kabar_tahap_turunan' => true]);

        $this->admin = User::factory()->admin()->create(['organization_id' => $this->org->id]);
        $this->teknisi = User::factory()->create([
            'organization_id' => $this->org->id,
            'role' => User::ROLE_TEKNISI,
            'name' => 'Teknisi Rahasia Sekali',
        ]);
        $this->ptA = Customer::factory()->create(['organization_id' => $this->org->id, 'nama' => 'PT Alfa Perkasa']);
        $this->ptB = Customer::factory()->create(['organization_id' => $this->org->id, 'nama' => 'PT Beta Mandiri']);

        $this->picA = $this->anggotaDi($this->ptA, CustomerMember::PERAN_PIC_UTAMA);
        $this->stafA = $this->anggotaDi($this->ptA, CustomerMember::PERAN_STAF);
        $this->picB = $this->anggotaDi($this->ptB, CustomerMember::PERAN_PIC_UTAMA);

        $this->paketA = Order::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $this->ptA->id,
            'nomor' => 'ORD/2026/11/0042',
            'status' => Order::STATUS_DIPROSES,
        ]);
        $this->alatA = $this->alat($this->ptA, ['nama_alat' => 'Termometer Digital', 'merk' => 'Fluke', 'serial_number' => 'SN-A-0001']);
        $this->itemA = OrderItem::factory()->create([
            'order_id' => $this->paketA->id,
            'equipment_id' => $this->alatA->id,
            'teknisi_id' => $this->teknisi->id,
            'kondisi_terima' => 'KONDISI-TERIMA-INTERNAL',
            'catatan' => 'CATATAN-INTERNAL-ITEM',
        ]);
    }

    private function anggotaDi(Customer $perusahaan, string $peran): User
    {
        $user = $this->pelanggan();

        CustomerMember::create([
            'organization_id' => $perusahaan->organization_id,
            'customer_id' => $perusahaan->id,
            'user_id' => $user->id,
            'peran' => $peran,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        return $user->refresh();
    }

    private function alat(Customer $pemilik, array $isi = []): Equipment
    {
        return Equipment::factory()->create([
            'organization_id' => $this->org->id,
            'customer_id' => $pemilik->id,
            ...$isi,
        ]);
    }

    private function tandai(OrderItem $item, string $tahap, array $tambahan = [])
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/pelacakan/item/{$item->id}/tahap-fisik", ['tahap_fisik' => $tahap, ...$tambahan]);
    }

    private function sesi(OrderItem $item, string $status, array $isi = []): CalibrationSession
    {
        return CalibrationSession::factory()->create([
            'organization_id' => $this->org->id,
            'equipment_id' => $item->equipment_id,
            'order_item_id' => $item->id,
            'teknisi_id' => $this->teknisi->id,
            'status' => $status,
            ...$isi,
        ]);
    }

    /** @return Collection<int, DatabaseNotification> */
    private function kabar(User $user): Collection
    {
        return $user->notifications()->where('type', TahapAlatBerubah::class)
            // `notifications()` sudah `latest()`; dibalik ke terlama dulu supaya urutan peristiwa terbaca.
            ->reorder('created_at')
            ->get();
    }

    // --- fisik: penerima, isi, saklar --------------------------------------

    public function test_tahap_fisik_dikabarkan_ke_semua_anggota_perusahaan_pemilik_paket_saja(): void
    {
        $this->tandai($this->itemA, TahapPaket::SIAP_DIAMBIL)->assertOk();

        foreach ([$this->picA, $this->stafA] as $user) {
            $baris = $this->kabar($user);
            $this->assertCount(1, $baris);

            $data = $baris->first()->data;
            $this->assertSame('pelanggan.tahap_alat', $data['kategori']);
            $this->assertSame(['tipe' => 'pelanggan_paket', 'id' => $this->paketA->id], $data['tautan']);
            $this->assertSame('Status alat Anda diperbarui', $data['title']);
            // Identitas utama = nama + merk + nomor seri; Order Number = pengait kedua.
            $this->assertStringContainsString('Termometer Digital', $data['body']);
            $this->assertStringContainsString('Fluke', $data['body']);
            $this->assertStringContainsString('SN-A-0001', $data['body']);
            $this->assertStringContainsString('Siap diambil', $data['body']);
            $this->assertStringContainsString('ORD/2026/11/0042', $data['body']);
        }

        // PT lain di LAB YANG SAMA tidak menerima apa pun.
        $this->assertSame(0, $this->picB->notifications()->count(), 'PT lain ikut dikabari.');
    }

    public function test_isi_kabar_bebas_data_internal(): void
    {
        $this->sesi($this->itemA, CalibrationSession::STATUS_MENUNGGU_APPROVAL); // tahap fisik masih kosong
        $this->travel(2)->seconds();
        $this->tandai($this->itemA, TahapPaket::DISERAHKAN, ['diserahkan_kepada' => 'Pak Budi Kurir'])->assertOk();

        $semua = json_encode($this->kabar($this->picA)->map->data->all());

        foreach ([
            'Teknisi Rahasia Sekali', $this->teknisi->email, 'KONDISI-TERIMA-INTERNAL', 'CATATAN-INTERNAL-ITEM',
            TahapPaket::MENUNGGU_PENGESAHAN, 'PASS', 'FAIL', 'nomor_sesi',
            'Pak Budi Kurir', 'PT Alfa Perkasa', $this->admin->name, $this->admin->email,
        ] as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $semua, "Kabar tahap membawa `{$terlarang}`.");
        }

        // Push (layar kunci) hanya judul/isi/tautan -- bentuk yang sama dengan notifikasi pelanggan lain.
        $baris = $this->kabar($this->picA)->last();
        $push = TahapAlatBerubah::dari(
            $this->paketA->id, $this->itemA->id, 'ORD/2026/11/0042', 'Termometer Digital', 'Fluke', 'SN-A-0001',
            TahapPaket::DISERAHKAN, 'fisik:1',
        )->toPush($this->picA);
        $this->assertSame(['kategori', 'tujuan', 'notification_id', 'android_channel_id', 'tautan_tipe', 'tautan_id'], array_keys($push['data']));
        $this->assertSame('pelanggan_paket:'.$this->paketA->id, $push['data']['tujuan']);
        $this->assertStringContainsString('Sudah diserahkan', $baris->data['body']);
    }

    public function test_saklar_tahap_alat_dimatikan_berarti_tidak_dikirim_dan_bawaannya_menyala(): void
    {
        $this->assertTrue(PreferensiNotifikasi::BAWAAN[PreferensiNotifikasi::TAHAP_ALAT]);

        app(PreferensiNotifikasi::class)->simpan(
            $this->stafA->keanggotaan()->sole(),
            [PreferensiNotifikasi::TAHAP_ALAT => false],
        );

        $this->tandai($this->itemA, TahapPaket::SIAP_DIAMBIL)->assertOk();

        $this->assertCount(0, $this->kabar($this->stafA), 'Saklar dimatikan tapi tetap dikabari.');
        $this->assertCount(1, $this->kabar($this->picA), 'Anggota tanpa baris preferensi (bawaan menyala) tidak dikabari.');
    }

    public function test_saklar_lain_tidak_mematikan_kabar_tahap(): void
    {
        // Mematikan `status_permintaan`/`pesan_lab` bukan berarti menolak kabar tahap alat.
        app(PreferensiNotifikasi::class)->simpan($this->stafA->keanggotaan()->sole(), [
            PreferensiNotifikasi::STATUS_PERMINTAAN => false,
            PreferensiNotifikasi::PESAN_LAB => false,
        ]);

        $this->tandai($this->itemA, TahapPaket::SIAP_DIAMBIL)->assertOk();

        $this->assertCount(1, $this->kabar($this->stafA));
    }

    public function test_anggota_dan_akun_nonaktif_tidak_dikabari(): void
    {
        $nonaktif = $this->anggotaDi($this->ptA, CustomerMember::PERAN_STAF);
        $nonaktif->keanggotaan()->update(['status' => CustomerMember::STATUS_NONAKTIF]);
        $akunMati = $this->anggotaDi($this->ptA, CustomerMember::PERAN_STAF);
        $akunMati->forceFill(['status' => User::STATUS_NONAKTIF])->save();

        $this->tandai($this->itemA, TahapPaket::SIAP_DIAMBIL)->assertOk();

        $this->assertCount(0, $this->kabar($nonaktif));
        $this->assertCount(0, $this->kabar($akunMati));
    }

    // --- idempotensi -------------------------------------------------------

    public function test_menandai_tahap_yang_sama_dua_kali_tidak_mengabari_dua_kali(): void
    {
        $this->tandai($this->itemA, TahapPaket::SIAP_DIAMBIL)->assertOk();
        // Detik berbeda: penanda peristiwa (`fisik:<epoch>`) juga berbeda, jadi yang menahan
        // kabar kedua HARUS pembanding sebelum/sesudah -- bukan penjaga duplikat.
        $this->travel(2)->seconds();
        $this->tandai($this->itemA, TahapPaket::SIAP_DIAMBIL)->assertOk();

        $this->assertCount(1, $this->kabar($this->picA));
    }

    public function test_peristiwa_yang_sama_dipanggil_berulang_tetap_satu_baris(): void
    {
        $kabar = app(KabarTahapAlat::class);

        $this->assertSame(2, $kabar->kabari($this->itemA, TahapPaket::DITERIMA, TahapPaket::SIAP_DIAMBIL, 'fisik:1000'));
        $this->assertSame(0, $kabar->kabari($this->itemA, TahapPaket::DITERIMA, TahapPaket::SIAP_DIAMBIL, 'fisik:1000'));
        $this->assertCount(1, $this->kabar($this->picA));
        $this->assertCount(1, $this->kabar($this->stafA));

        // Peristiwa BARU dengan tahap yang sama (mis. pekerjaan ulang kedua) tetap lolos.
        $this->assertSame(2, $kabar->kabari($this->itemA, TahapPaket::DITERIMA, TahapPaket::SIAP_DIAMBIL, 'fisik:2000'));
        $this->assertCount(2, $this->kabar($this->picA));
    }

    // --- perpindahan yang TIDAK terlihat pelanggan --------------------------

    public function test_tahap_yang_tidak_berubah_di_mata_pelanggan_tidak_dikabarkan(): void
    {
        $kabar = app(KabarTahapAlat::class);

        $this->assertSame(0, $kabar->kabari($this->itemA, TahapPaket::DIKALIBRASI, TahapPaket::DIKALIBRASI, 'x'));
        // Dua lapis persetujuan lab = satu langkah di mata pelanggan.
        $this->assertSame(0, $kabar->kabari($this->itemA, TahapPaket::MENUNGGU_PEMERIKSAAN, TahapPaket::MENUNGGU_PEMERIKSAAN, 'x'));
        $this->assertSame(0, $this->picA->notifications()->count());
    }

    public function test_sertifikat_terbit_tidak_dikabarkan_dari_sini_karena_sudah_punya_kabar_sendiri(): void
    {
        $kabar = app(KabarTahapAlat::class);

        $this->assertSame(0, $kabar->kabari($this->itemA, TahapPaket::MENUNGGU_PEMERIKSAAN, TahapPaket::SERTIFIKAT_TERBIT, 'x'));
        // Sertifikat ditarik/direvisi = `SertifikatBerubah`, bukan "hasil sedang diperiksa".
        $this->assertSame(0, $kabar->kabari($this->itemA, TahapPaket::SERTIFIKAT_TERBIT, TahapPaket::MENUNGGU_PEMERIKSAAN, 'x'));
        $this->assertSame(0, $this->picA->notifications()->count());
    }

    public function test_sesi_berubah_saat_tahap_fisik_sudah_tercatat_tidak_dikabarkan(): void
    {
        $this->tandai($this->itemA, TahapPaket::SIAP_DIAMBIL)->assertOk();
        $this->assertCount(1, $this->kabar($this->picA));

        // Tahap fisik menang atas tahap turunan: perubahan sesi sesudahnya tidak terlihat.
        $sesi = $this->sesi($this->itemA, CalibrationSession::STATUS_DRAFT);
        $this->travel(2)->seconds();
        $sesi->update(['status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL]);

        $this->assertCount(1, $this->kabar($this->picA));
    }

    public function test_alat_milik_pelanggan_lain_di_order_ini_tidak_dikabarkan(): void
    {
        // Item itu disembunyikan dari `/paket` PT A (LingkupData::relasiPaket), jadi
        // perubahannya pun bukan kabar untuk PT A.
        $alatB = $this->alat($this->ptB);
        $itemCampur = OrderItem::factory()->create(['order_id' => $this->paketA->id, 'equipment_id' => $alatB->id]);

        $this->tandai($itemCampur, TahapPaket::SIAP_DIAMBIL)->assertOk();

        $this->assertSame(0, $this->picA->notifications()->count());
        $this->assertSame(0, $this->picB->notifications()->count());
    }

    public function test_order_dibatalkan_tidak_dikabarkan(): void
    {
        $this->paketA->update(['status' => Order::STATUS_DIBATALKAN]);

        $this->tandai($this->itemA, TahapPaket::SIAP_DIAMBIL)->assertOk();

        $this->assertSame(0, $this->picA->notifications()->count());
    }

    // --- tahap turunan dari sesi ---------------------------------------------

    public function test_tahap_turunan_dari_sesi_dikabarkan_hanya_saat_terlihat_berubah(): void
    {
        $sesi = $this->sesi($this->itemA, CalibrationSession::STATUS_DRAFT);
        $this->assertSame(['Sedang dikalibrasi'], $this->labelKabar($this->picA));

        // draft -> menunggu_approval: "Hasil sedang diperiksa".
        $this->travel(2)->seconds();
        $sesi->update(['status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL]);
        $this->assertSame(['Sedang dikalibrasi', 'Hasil sedang diperiksa'], $this->labelKabar($this->picA));

        // menunggu_approval -> disetujui: dua lapis persetujuan lab dilipat, TIDAK terlihat.
        $this->travel(2)->seconds();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);
        $this->assertCount(2, $this->kabar($this->picA));

        // Dikembalikan lalu diajukan lagi: peristiwa kedua dengan tahap yang SAMA
        // ("Hasil sedang diperiksa") tidak boleh tertelan penjaga duplikat.
        $this->travel(2)->seconds();
        $sesi->update(['status' => CalibrationSession::STATUS_PERLU_REVISI]);
        $this->travel(2)->seconds();
        $sesi->update(['status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL]);

        $this->assertSame(
            ['Sedang dikalibrasi', 'Hasil sedang diperiksa', 'Sedang dikerjakan ulang', 'Hasil sedang diperiksa'],
            $this->labelKabar($this->picA),
        );
        $this->assertSame(0, $this->picB->notifications()->count());
    }

    public function test_status_sesi_berubah_menjadi_perlu_revisi_dikabarkan_sebagai_dikerjakan_ulang(): void
    {
        $sesi = $this->sesi($this->itemA, CalibrationSession::STATUS_MENUNGGU_APPROVAL);
        $this->assertSame(['Hasil sedang diperiksa'], $this->labelKabar($this->picA));

        $this->travel(2)->seconds();
        $sesi->update(['status' => CalibrationSession::STATUS_PERLU_REVISI]);

        $this->assertSame(['Hasil sedang diperiksa', 'Sedang dikerjakan ulang'], $this->labelKabar($this->picA));
    }

    public function test_sesi_lama_yang_berubah_tidak_menggeser_apa_pun(): void
    {
        $lama = $this->sesi($this->itemA, CalibrationSession::STATUS_PERLU_REVISI);
        $this->sesi($this->itemA, CalibrationSession::STATUS_DRAFT); // sesi TERAKHIR: "Sedang dikalibrasi"
        $sebelum = $this->kabar($this->picA)->count();

        $this->travel(2)->seconds();
        $lama->update(['status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL]);

        $this->assertCount($sebelum, $this->kabar($this->picA));
    }

    public function test_sesi_tanpa_order_item_tidak_memicu_apa_pun(): void
    {
        CalibrationSession::factory()->create([
            'organization_id' => $this->org->id,
            'equipment_id' => $this->alatA->id,
            'order_item_id' => null,
            'status' => CalibrationSession::STATUS_DRAFT,
        ])->update(['status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL]);

        $this->assertSame(0, $this->picA->notifications()->count());
    }

    public function test_sertifikat_terbit_tidak_dikabarkan_dua_kali_lewat_jalur_sesi(): void
    {
        $sesi = $this->sesi($this->itemA, CalibrationSession::STATUS_MENUNGGU_APPROVAL);
        Certificate::factory()->create([
            'organization_id' => $this->org->id,
            'calibration_session_id' => $sesi->id,
            'status' => Certificate::STATUS_TERBIT,
        ]);
        $sebelum = $this->kabar($this->picA)->count();

        $this->travel(2)->seconds();
        $sesi->update(['status' => CalibrationSession::STATUS_DISETUJUI]);

        $this->assertCount($sebelum, $this->kabar($this->picA));
    }

    /** Label tahap dari isi kabar, berurutan: "<identitas>: <label>. Order <nomor>." */
    private function labelKabar(User $user): array
    {
        return $this->kabar($user)
            ->map(function ($n): string {
                preg_match('/\): (.+)\. Order /', (string) $n->data['body'], $cocok);

                return $cocok[1] ?? '';
            })
            ->values()
            ->all();
    }

    // --- FCM -----------------------------------------------------------------

    public function test_push_hanya_ke_perangkat_pelanggan_pemilik_paket(): void
    {
        $terkirim = [];
        $this->app->instance(PengirimPush::class, new class($terkirim) implements PengirimPush
        {
            public function __construct(private array &$terkirim) {}

            public function kirim(DeviceToken $perangkat, string $judul, string $isi, array $data = []): bool
            {
                $this->terkirim[] = ['token' => $perangkat->token, 'judul' => $judul, 'isi' => $isi, 'data' => $data];

                return true;
            }

            public function tokenMati(): bool
            {
                return false;
            }
        });

        DeviceToken::catat($this->picA, 'tok-pic-a', 'android', null, DeviceToken::APLIKASI_PELANGGAN);
        DeviceToken::catat($this->picB, 'tok-pic-b', 'android', null, DeviceToken::APLIKASI_PELANGGAN);

        $this->tandai($this->itemA, TahapPaket::SIAP_DIAMBIL)->assertOk();

        $this->assertCount(1, $terkirim);
        $this->assertSame('tok-pic-a', $terkirim[0]['token']);
        $this->assertSame('pelanggan_paket:'.$this->paketA->id, $terkirim[0]['data']['tujuan']);
        $this->assertStringNotContainsString('Teknisi Rahasia', json_encode($terkirim));
        $this->assertStringNotContainsString('KONDISI-TERIMA-INTERNAL', json_encode($terkirim));
    }

    // --- ketahanan -------------------------------------------------------------

    public function test_kabar_yang_gagal_tidak_menggagalkan_serah_terima_dan_log_tanpa_data_pelanggan(): void
    {
        $this->app->bind(PreferensiNotifikasi::class, fn () => new class extends PreferensiNotifikasi
        {
            public function penerima(Customer $pelanggan, string $kunci): Collection
            {
                throw new \RuntimeException('SQLSTATE gagal untuk rahasia@pelanggan.test');
            }
        });

        $log = [];
        Log::listen(function ($pesan) use (&$log): void {
            $log[] = ['level' => $pesan->level, 'pesan' => $pesan->message, 'konteks' => $pesan->context];
        });

        $this->tandai($this->itemA, TahapPaket::SIAP_DIAMBIL)->assertOk();

        $this->assertSame(TahapPaket::SIAP_DIAMBIL, $this->itemA->fresh()->tahap_fisik, 'Serah terima ikut gagal.');

        $ditemukan = collect($log)->first(fn (array $l): bool => str_contains($l['pesan'], 'mengabari pelanggan gagal'));
        $this->assertNotNull($ditemukan, 'Kegagalan kabar tidak tercatat.');
        $this->assertSame($this->itemA->id, $ditemukan['konteks']['order_item_id']);
        $this->assertStringNotContainsString('rahasia@pelanggan.test', json_encode($log));
    }

    // --- antrean: DIANTRE dan terkirim SESUDAH commit ---------------------------
    //
    // Antrean `sync` (bawaan test) mengirim seketika, jadi tidak bisa membedakan
    // `notify()` (antre, sesudah commit) dari `sendNow()` (langsung, sebelum commit).
    // Tes-tes ini memakai antrean `database` supaya perbedaannya terlihat di tabel `jobs`.

    public function test_kabar_turunan_diantre_dan_baru_ada_di_antrean_sesudah_commit(): void
    {
        config(['queue.default' => 'database']);
        $jumlahJob = fn (): int => DB::table('jobs')->count();

        $didalamTransaksi = null;
        DB::transaction(function () use (&$didalamTransaksi, $jumlahJob): void {
            $this->sesi($this->itemA, CalibrationSession::STATUS_DRAFT);
            $didalamTransaksi = $jumlahJob();
        });

        $this->assertSame(0, $didalamTransaksi, 'Kabar diantre SEBELUM transaksi commit.');
        $this->assertGreaterThan(0, $jumlahJob(), 'Kabar tidak diantre sesudah commit.');
        // Diantre, bukan ditulis sinkron: belum jadi baris `notifications` sebelum worker jalan.
        $this->assertSame(0, $this->picA->notifications()->count(), 'Kabar ditulis sinkron, bukan lewat antrean.');
    }

    public function test_transaksi_yang_dibatalkan_tidak_mengantre_atau_menulis_kabar(): void
    {
        config(['queue.default' => 'database']);

        try {
            DB::transaction(function (): void {
                $this->sesi($this->itemA, CalibrationSession::STATUS_DRAFT);

                throw new \RuntimeException('gagal di tengah transaksi');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, DB::table('jobs')->count(), 'Transaksi dibatalkan, tapi ada kabar yang diantre.');
        $this->assertSame(0, $this->picA->notifications()->count());
    }

    public function test_kabar_tahap_fisik_diantre_bukan_ditulis_sinkron(): void
    {
        config(['queue.default' => 'database']);

        $this->tandai($this->itemA, TahapPaket::SIAP_DIAMBIL)->assertOk();

        $this->assertGreaterThan(0, DB::table('jobs')->count(), 'Kabar tahap fisik tidak diantre.');
        $this->assertSame(0, $this->picA->notifications()->count(), 'Kabar tahap fisik ditulis sinkron.');
    }

    public function test_pengirim_push_yang_melempar_galat_tidak_menggagalkan_serah_terima(): void
    {
        $this->app->instance(PengirimPush::class, new class implements PengirimPush
        {
            public function kirim(DeviceToken $perangkat, string $judul, string $isi, array $data = []): bool
            {
                throw new \RuntimeException('FCM mati');
            }

            public function tokenMati(): bool
            {
                return false;
            }
        });
        DeviceToken::catat($this->picA, 'tok-pic-a', 'android', null, DeviceToken::APLIKASI_PELANGGAN);

        $this->tandai($this->itemA, TahapPaket::SIAP_DIAMBIL)->assertOk();

        $this->assertSame(TahapPaket::SIAP_DIAMBIL, $this->itemA->fresh()->tahap_fisik);
    }

    // --- galat di jalur kabar TIDAK menggagalkan / mendahului penyimpanan tahap fisik ---

    /**
     * Ganti `KabarTahapAlat` dengan versi yang `tahapTampil()`-nya melempar galat pada
     * panggilan ke-[$gagalPada] (1 = pembacaan SEBELUM menyimpan, 2 = SESUDAH menyimpan).
     */
    private function kabarYangMelemparPadaPanggilan(int $gagalPada): void
    {
        $this->app->bind(KabarTahapAlat::class, fn ($app) => new class($app->make(PreferensiNotifikasi::class), $app->make(TahapPaket::class), $gagalPada) extends KabarTahapAlat
        {
            private int $panggilan = 0;

            public function __construct(PreferensiNotifikasi $preferensi, TahapPaket $tahap, private readonly int $gagalPada)
            {
                parent::__construct($preferensi, $tahap);
            }

            public function tahapTampil(OrderItem $item): string
            {
                if (++$this->panggilan === $this->gagalPada) {
                    throw new \RuntimeException('galat rahasia@pelanggan.test');
                }

                return parent::tahapTampil($item);
            }
        });
    }

    /** @param  list<string>  $log */
    private function pastikanGalatKabarTercatatTanpaDataPelanggan(array $log): void
    {
        $this->assertNotEmpty(
            array_filter($log, fn (string $l): bool => str_contains($l, 'mengabari pelanggan gagal')),
            'Galat jalur kabar tidak dicatat.',
        );
        $this->assertStringNotContainsString('rahasia@pelanggan.test', implode("\n", $log));
    }

    public function test_galat_saat_membaca_tahap_sebelum_disimpan_tidak_menggagalkan_serah_terima(): void
    {
        $this->kabarYangMelemparPadaPanggilan(1);
        $log = [];
        Log::listen(function ($pesan) use (&$log): void {
            $log[] = $pesan->message.' '.json_encode($pesan->context);
        });

        $this->tandai($this->itemA, TahapPaket::SIAP_DIAMBIL)->assertOk();

        $this->assertSame(TahapPaket::SIAP_DIAMBIL, $this->itemA->fresh()->tahap_fisik, 'Tahap fisik tidak tersimpan.');
        $this->assertSame(0, $this->picA->notifications()->count(), 'Tahap sebelumnya tidak diketahui, tidak boleh ada kabar.');
        $this->pastikanGalatKabarTercatatTanpaDataPelanggan($log);
        // Jejak audit serah terima tetap ditulis.
        $this->assertDatabaseHas('audit_logs', ['entity_type' => 'order_items', 'entity_id' => $this->itemA->id]);
    }

    public function test_galat_saat_membaca_tahap_sesudah_disimpan_tidak_menggagalkan_serah_terima(): void
    {
        $this->kabarYangMelemparPadaPanggilan(2);
        $log = [];
        Log::listen(function ($pesan) use (&$log): void {
            $log[] = $pesan->message.' '.json_encode($pesan->context);
        });

        $this->tandai($this->itemA, TahapPaket::SIAP_DIAMBIL)->assertOk();

        $this->assertSame(TahapPaket::SIAP_DIAMBIL, $this->itemA->fresh()->tahap_fisik);
        $this->assertSame(0, $this->picA->notifications()->count());
        $this->pastikanGalatKabarTercatatTanpaDataPelanggan($log);
    }

    // --- sakelar KABAR_TAHAP_TURUNAN: hanya mematikan kabar TURUNAN ---------------------

    public function test_sakelar_turunan_mati_observer_tidak_mengirim_tetapi_tahap_fisik_tetap_dikabarkan(): void
    {
        config(['pelanggan.kabar_tahap_turunan' => false]);

        $sesi = $this->sesi($this->itemA, CalibrationSession::STATUS_DRAFT);
        $this->travel(2)->seconds();
        $sesi->update(['status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL]);
        $this->assertCount(0, $this->kabar($this->picA), 'Sakelar turunan mati, tetapi kabar turunan terkirim.');

        // Tahap FISIK tidak terpengaruh sakelar.
        $this->travel(2)->seconds();
        $this->tandai($this->itemA, TahapPaket::SIAP_DIAMBIL)->assertOk();
        $kabar = $this->kabar($this->picA);
        $this->assertCount(1, $kabar, 'Kabar tahap fisik ikut mati bersama sakelar turunan.');
        $this->assertStringContainsString('Siap diambil', $kabar->first()->data['body']);
    }

    public function test_sakelar_turunan_dinyalakan_observer_mengirim_lagi(): void
    {
        config(['pelanggan.kabar_tahap_turunan' => false]);
        $this->sesi($this->itemA, CalibrationSession::STATUS_DRAFT);
        $this->assertCount(0, $this->kabar($this->picA));

        config(['pelanggan.kabar_tahap_turunan' => true]);
        $alatKedua = $this->alat($this->ptA);
        $itemKedua = OrderItem::factory()->create(['order_id' => $this->paketA->id, 'equipment_id' => $alatKedua->id]);
        $this->sesi($itemKedua, CalibrationSession::STATUS_DRAFT);

        $this->assertSame(['Sedang dikalibrasi'], $this->labelKabar($this->picA));
    }

    public function test_bawaan_sakelar_turunan_mati_di_config_env_example_dan_blueprint(): void
    {
        // Bawaan di KODE (bukan nilai env mesin ini): kalau env tidak diisi, hasilnya MATI.
        $this->assertStringContainsString("env('KABAR_TAHAP_TURUNAN', false)", (string) file_get_contents(base_path('config/pelanggan.php')));
        $this->assertMatchesRegularExpression('/^KABAR_TAHAP_TURUNAN=false\s*$/m', (string) file_get_contents(base_path('.env.example')));
        $this->assertMatchesRegularExpression('/-\s*key:\s*KABAR_TAHAP_TURUNAN\s*\n\s*value:\s*"false"/', (string) file_get_contents(base_path('render.yaml')));
    }
}

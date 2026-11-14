<?php

namespace Tests\Feature\Pelanggan;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Organization;
use App\Models\User;
use App\Services\TahapPaket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * `data.ringkasan` di `GET /beranda` -- empat hitungan PASTI (additive, 14 Nov 2026):
 * `alat_berlaku`, `alat_belum_pernah`, `paket_siap_diambil`, `sertifikat_baru_7_hari`.
 *
 * Aplikasi Pelanggan dulu menebak dari daftar yang dipotong server (5 sertifikat,
 * 3 paket) dan dari `jumlah_alat` yang ikut menghitung alat yang belum pernah
 * dikalibrasi. Tes ini menjaga tiga hal: angkanya BENAR (diadu ke `status_alat` di
 * `/alat` dan `tahap` di `/paket`, dua jawaban yang sudah ada), tidak ikut
 * TERPOTONG batas daftar, dan tidak MENEMBUS perusahaan lain.
 *
 * Waktu dibekukan pukul 12:00 supaya batas hari (hari ke-30/31, 7/8 hari lalu)
 * tidak bergantung jam jalannya tes.
 */
class RingkasanBerandaPelangganTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private const V1 = '/api/pelanggan/v1';

    private const KUNCI_LAMA = ['jumlah_alat', 'lewat_jatuh_tempo', 'segera_jatuh_tempo', 'paket_berjalan', 'notifikasi_belum_dibaca'];

    private const KUNCI_BARU = ['alat_berlaku', 'alat_belum_pernah', 'paket_siap_diambil', 'sertifikat_baru_7_hari'];

    private User $pic;

    private Customer $ptA;

    private Customer $ptB;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::create(2026, 11, 14, 12, 0, 0));
        $this->siapkanJalurPelanggan();

        $this->pic = $this->anggota();
        $this->ptA = $this->pic->keanggotaan()->first()->customer;
        $this->ptB = $this->perusahaan('PT Pesaing Beta');
        $this->teknisi = User::factory()->create([
            'organization_id' => $this->ptA->organization_id,
            'role' => User::ROLE_TEKNISI,
        ]);
    }

    // --- perkakas ---------------------------------------------------------------

    /** Alat + (bawaan) satu sertifikat terbit; `$sertifikat = null` = tanpa sesi sama sekali. */
    private function alat(array $isi = [], ?string $sertifikat = Certificate::STATUS_TERBIT, ?Customer $pemilik = null): Equipment
    {
        $pemilik ??= $this->ptA;
        $alat = Equipment::factory()->create([
            'organization_id' => $pemilik->organization_id,
            'customer_id' => $pemilik->id,
            'status' => Equipment::STATUS_AKTIF,
            ...$isi,
        ]);

        if ($sertifikat !== null) {
            // Terbit 60 hari lalu: di luar jendela "baru" supaya tidak ikut `sertifikat_baru_7_hari`
            // kecuali tes yang bersangkutan memang menanamnya lewat [sertifikat()].
            $this->sertifikat($alat, ['status' => $sertifikat, 'diterbitkan_pada' => now()->subDays(60)]);
        }

        return $alat;
    }

    private function sertifikat(Equipment $alat, array $isi = []): Certificate
    {
        return Certificate::factory()->create([
            'organization_id' => $alat->organization_id,
            'calibration_session_id' => CalibrationSession::factory()->create([
                'organization_id' => $alat->organization_id,
                'equipment_id' => $alat->id,
            ])->id,
            ...$isi,
        ]);
    }

    private function order(Customer $pemilik, array $isi = []): Order
    {
        return Order::factory()->create([
            'organization_id' => $pemilik->organization_id,
            'customer_id' => $pemilik->id,
            'status' => Order::STATUS_DIPROSES,
            ...$isi,
        ]);
    }

    private function item(Order $order, Equipment $alat, array $isi = []): OrderItem
    {
        return OrderItem::factory()->create([
            'order_id' => $order->id,
            'equipment_id' => $alat->id,
            'teknisi_id' => $this->teknisi->id,
            ...$isi,
        ]);
    }

    /** Tahap fisik: `siap_diambil` atau `diserahkan`. */
    private function fisik(string $tahap): array
    {
        return [
            'tahap_fisik' => $tahap,
            'tahap_fisik_pada' => now(),
            ...($tahap === TahapPaket::DISERAHKAN ? ['diserahkan_kepada' => 'Pak Budi'] : []),
        ];
    }

    private function ambil(string $uri)
    {
        $this->permintaanBaru();

        return $this->withHeaders($this->bearer($this->pic))->getJson(self::V1.$uri)->assertOk();
    }

    /** @return array<string, int> */
    private function ringkasan(): array
    {
        return $this->ambil('/beranda')->json('data.ringkasan');
    }

    // --- bentuk -----------------------------------------------------------------

    public function test_ringkasan_menambah_empat_kunci_di_belakang_lima_kunci_lama_semuanya_bilangan_bulat(): void
    {
        $this->alat();

        $ringkasan = $this->ringkasan();

        $this->assertSame([...self::KUNCI_LAMA, ...self::KUNCI_BARU], array_keys($ringkasan), 'Kunci ringkasan berubah/bocor.');
        foreach ($ringkasan as $kunci => $nilai) {
            $this->assertIsInt($nilai, "ringkasan.{$kunci} bukan bilangan bulat.");
            $this->assertGreaterThanOrEqual(0, $nilai, "ringkasan.{$kunci} negatif.");
        }
    }

    public function test_perusahaan_tanpa_data_mendapat_nol_di_semua_hitungan(): void
    {
        $this->assertSame(
            ['jumlah_alat' => 0, 'lewat_jatuh_tempo' => 0, 'segera_jatuh_tempo' => 0, 'paket_berjalan' => 0, 'notifikasi_belum_dibaca' => 0,
                'alat_berlaku' => 0, 'alat_belum_pernah' => 0, 'paket_siap_diambil' => 0, 'sertifikat_baru_7_hari' => 0],
            $this->ringkasan(),
        );
    }

    // --- alat_berlaku / alat_belum_pernah ----------------------------------------

    public function test_alat_berlaku_dan_belum_pernah_sama_dengan_status_alat_di_daftar_alat(): void
    {
        // terkalibrasi (4)
        $this->alat(['tanggal_jatuh_tempo' => now()->addDays(200)]);
        $this->alat(['tanggal_jatuh_tempo' => null]); // tanpa jadwal, tapi sertifikat terbit
        $this->alat(['tanggal_jatuh_tempo' => now()->addDays(31)]); // tepat di luar jendela "mendekati"
        $this->alat(['tanggal_kalibrasi_terakhir' => now()->subMonth(), 'tanggal_jatuh_tempo' => now()->addDays(200)], null); // alat impor
        // mendekati_jadwal (2): sertifikatnya MASIH berlaku, jadi ikut `alat_berlaku` (keputusan pemilik 14 Nov 2026)
        $this->alat(['tanggal_jatuh_tempo' => now()->addDays(30)]);
        $this->alat(['tanggal_jatuh_tempo' => now()]);
        // lewat_jadwal (1): kemarin = sudah lewat, TIDAK berlaku
        $this->alat(['tanggal_jatuh_tempo' => now()->subDay()]);
        // belum_pernah (3): tanpa sertifikat terbit DAN tanggal kalibrasi terakhir kosong
        $this->alat(['tanggal_kalibrasi_terakhir' => null, 'tanggal_jatuh_tempo' => null], null);
        $this->alat(['tanggal_kalibrasi_terakhir' => null, 'tanggal_jatuh_tempo' => now()->subDays(5)], null); // lewat jadwal tapi belum pernah: belum_pernah menang
        $this->alat(['tanggal_kalibrasi_terakhir' => null, 'tanggal_jatuh_tempo' => now()->addDays(200)], Certificate::STATUS_MENUNGGU_GENERATE);
        // nonaktif (2): tidak ikut hitungan apa pun
        $this->alat(['status' => Equipment::STATUS_NONAKTIF]);
        $this->alat(['status' => Equipment::STATUS_NONAKTIF, 'tanggal_kalibrasi_terakhir' => null], null);

        $perStatus = collect($this->ambil('/alat?per_page=100')->json('data'))->countBy('status_alat');
        $ringkasan = $this->ringkasan();

        // Dua jawaban yang sudah ada, diadu: SQL di ringkasan == PHP `statusAlat()`.
        // Berlaku = terkalibrasi + mendekati_jadwal (sertifikat belum jatuh tempo).
        $this->assertSame($perStatus['terkalibrasi'] + $perStatus['mendekati_jadwal'], $ringkasan['alat_berlaku']);
        $this->assertSame($perStatus['belum_pernah'], $ringkasan['alat_belum_pernah']);
        // Angka mutlaknya (supaya dua sisi yang melenceng bersamaan tetap ketahuan).
        $this->assertSame(6, $ringkasan['alat_berlaku']);
        $this->assertSame(3, $ringkasan['alat_belum_pernah']);
        $this->assertSame(4, $perStatus['terkalibrasi']);
        $this->assertSame(2, $perStatus['mendekati_jadwal']);
        $this->assertSame(1, $perStatus['lewat_jadwal']);
        // Field lama TIDAK berubah arti: `jumlah_alat` tetap semua alat aktif, termasuk yang belum pernah.
        $this->assertSame(10, $ringkasan['jumlah_alat']);
        // berlaku + belum_pernah + lewat_jadwal = semua alat aktif (tidak ada alat yang hilang dari tiga ember ini).
        $this->assertSame(
            $ringkasan['jumlah_alat'],
            $ringkasan['alat_berlaku'] + $ringkasan['alat_belum_pernah'] + $perStatus['lewat_jadwal'],
        );
    }

    public function test_sertifikat_dibatalkan_atau_gagal_tidak_membuat_alat_pernah_dikalibrasi(): void
    {
        $this->alat(['tanggal_kalibrasi_terakhir' => null], Certificate::STATUS_DIBATALKAN);
        $this->alat(['tanggal_kalibrasi_terakhir' => null], Certificate::STATUS_GAGAL);

        $perStatus = collect($this->ambil('/alat')->json('data'))->countBy('status_alat');
        $ringkasan = $this->ringkasan();

        $this->assertSame($perStatus['belum_pernah'], $ringkasan['alat_belum_pernah']);
        $this->assertSame(2, $ringkasan['alat_belum_pernah']);
        $this->assertSame(0, $ringkasan['alat_berlaku']);
    }

    // --- paket_siap_diambil -----------------------------------------------------

    public function test_paket_siap_diambil_mengikuti_tahap_paket_dan_sama_dengan_daftar_paket(): void
    {
        // Dihitung (3)
        $this->item($this->order($this->ptA), $this->alat(), $this->fisik(TahapPaket::SIAP_DIAMBIL));
        $campur = $this->order($this->ptA);
        $this->item($campur, $this->alat(), $this->fisik(TahapPaket::SIAP_DIAMBIL));
        $this->item($campur, $this->alat(), $this->fisik(TahapPaket::DISERAHKAN)); // sebagian sudah diserahkan: tahap paket tetap siap_diambil
        $tercampurPt = $this->order($this->ptA);
        $this->item($tercampurPt, $this->alat(), $this->fisik(TahapPaket::SIAP_DIAMBIL));
        $this->item($tercampurPt, $this->alat([], null, $this->ptB)); // item beralat PT lain tersembunyi -> tidak menahan tahap

        // TIDAK dihitung
        $belumSemua = $this->order($this->ptA);
        $this->item($belumSemua, $this->alat(), $this->fisik(TahapPaket::SIAP_DIAMBIL));
        $this->item($belumSemua, $this->alat()); // satu alat masih "diterima": paket belum siap
        $diserahSemua = $this->order($this->ptA);
        $this->item($diserahSemua, $this->alat(), $this->fisik(TahapPaket::DISERAHKAN));
        $this->item($this->order($this->ptA, ['status' => Order::STATUS_SELESAI]), $this->alat(), $this->fisik(TahapPaket::SIAP_DIAMBIL));
        $this->item($this->order($this->ptA, ['status' => Order::STATUS_DIBATALKAN]), $this->alat(), $this->fisik(TahapPaket::SIAP_DIAMBIL));
        $this->order($this->ptA); // paket kosong
        $this->item($this->order($this->ptB), $this->alat([], null, $this->ptB), $this->fisik(TahapPaket::SIAP_DIAMBIL)); // paket PT lain

        $daftar = collect($this->ambil('/paket?per_page=50')->json('data'));

        $this->assertSame(3, $this->ringkasan()['paket_siap_diambil']);
        // Diadu ke jawaban yang sudah ada: tahap paket di `/paket`.
        $this->assertSame($daftar->where('tahap', TahapPaket::SIAP_DIAMBIL)->count(), $this->ringkasan()['paket_siap_diambil']);
    }

    public function test_paket_siap_diambil_tidak_dibatasi_tiga_paket_terbaru(): void
    {
        foreach (range(1, 5) as $i) {
            $this->item($this->order($this->ptA, ['tanggal_masuk' => now()->subDays($i)]), $this->alat(), $this->fisik(TahapPaket::SIAP_DIAMBIL));
        }
        // Tiga paket lain yang LEBIH BARU tapi belum siap -- semua yang siap tersingkir dari tiga paket terbaru.
        foreach (range(1, 3) as $ignored) {
            $this->item($this->order($this->ptA, ['tanggal_masuk' => now()]), $this->alat());
        }

        $respons = $this->ambil('/beranda');

        $this->assertCount(3, $respons->json('data.paket_berjalan'), 'Daftar paket_berjalan tetap tiga (kontrak lama).');
        $this->assertSame([], array_filter(
            $respons->json('data.paket_berjalan'),
            fn (array $p): bool => $p['tahap'] === TahapPaket::SIAP_DIAMBIL,
        ), 'Prasyarat: tidak satu pun paket siap ikut tiga paket terbaru.');
        $this->assertSame(5, $respons->json('data.ringkasan.paket_siap_diambil'));
        $this->assertSame(8, $respons->json('data.ringkasan.paket_berjalan'));
    }

    /**
     * Prasaring SQL `whereHas('items', tahap_fisik = siap_diambil)` hanya boleh MENYEMPITKAN
     * himpunan yang dimuat, tidak boleh mengubah hasil: keputusan akhir tetap `TahapPaket`
     * di atas item yang terlihat. Kasus yang lolos prasaring tapi tidak boleh dihitung:
     * item siap yang alatnya milik PT lain (tersembunyi), dan paket yang sebagian alatnya
     * lebih tertinggal (sertifikat terbit, diturunkan dari sesi).
     */
    public function test_prasaring_paket_tidak_mengubah_hasil_untuk_kasus_tepi(): void
    {
        // Lolos prasaring, TIDAK dihitung: satu-satunya item siap milik alat PT B (tersembunyi).
        $hanyaMilikB = $this->order($this->ptA);
        $this->item($hanyaMilikB, $this->alat([], null, $this->ptB), $this->fisik(TahapPaket::SIAP_DIAMBIL));

        // Lolos prasaring, TIDAK dihitung: satu alat siap, satu lagi baru "sertifikat terbit" (turunan, lebih tertinggal).
        $sebagian = $this->order($this->ptA);
        $this->item($sebagian, $this->alat(), $this->fisik(TahapPaket::SIAP_DIAMBIL));
        $alatTerbit = $this->alat();
        $itemTerbit = $this->item($sebagian, $alatTerbit);
        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $alatTerbit->organization_id,
            'equipment_id' => $alatTerbit->id,
            'order_item_id' => $itemTerbit->id,
            'teknisi_id' => $this->teknisi->id,
            'status' => CalibrationSession::STATUS_DISETUJUI,
        ]);
        Certificate::factory()->create(['organization_id' => $alatTerbit->organization_id, 'calibration_session_id' => $sesi->id]);

        // Tidak lolos prasaring dan tidak dihitung: tahap tertinggi yang bisa diturunkan hanya `sertifikat_terbit`.
        $hanyaTurunan = $this->order($this->ptA);
        $alatTurunan = $this->alat();
        $itemTurunan = $this->item($hanyaTurunan, $alatTurunan);
        $sesiTurunan = CalibrationSession::factory()->create([
            'organization_id' => $alatTurunan->organization_id,
            'equipment_id' => $alatTurunan->id,
            'order_item_id' => $itemTurunan->id,
            'teknisi_id' => $this->teknisi->id,
            'status' => CalibrationSession::STATUS_DISETUJUI,
        ]);
        Certificate::factory()->create(['organization_id' => $alatTurunan->organization_id, 'calibration_session_id' => $sesiTurunan->id]);

        // Dihitung.
        $this->item($this->order($this->ptA), $this->alat(), $this->fisik(TahapPaket::SIAP_DIAMBIL));

        $daftar = collect($this->ambil('/paket?per_page=50')->json('data'));

        $this->assertSame(1, $this->ringkasan()['paket_siap_diambil']);
        $this->assertSame(1, $daftar->where('tahap', TahapPaket::SIAP_DIAMBIL)->count(), 'Tidak sama dengan tahap paket di /paket.');
        // Paket yang seluruh itemnya tersembunyi tetap tampil, tahapnya `diterima` (tanpa item terlihat).
        $this->assertSame(TahapPaket::DITERIMA, $daftar->firstWhere('id', $hanyaMilikB->id)['tahap']);
        $this->assertSame(TahapPaket::SERTIFIKAT_TERBIT, $daftar->firstWhere('id', $sebagian->id)['tahap']);
    }

    /**
     * Prasaring benar-benar MENYEMPITKAN muatan: paket tanpa item `tahap_fisik = siap_diambil` tidak
     * dimuat itemnya oleh hitungan ini. Terlihat dari jumlah kueri pemuat item (`order_items.order_id in`):
     * satu dari daftar `paket_berjalan`, dan SATU lagi dari hitungan ini HANYA bila ada paket siap.
     */
    public function test_prasaring_tidak_memuat_item_paket_yang_pasti_bukan_siap_diambil(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->item($this->order($this->ptA), $this->alat());
        }

        $pemuatItem = function (): int {
            $this->ambil('/beranda'); // hangatkan
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->ambil('/beranda');
            $log = DB::getQueryLog();
            DB::disableQueryLog();

            return count(array_filter($log, fn (array $q): bool => preg_match('/[`"]order_items[`"]\.[`"]order_id[`"] in \(/i', $q['query']) === 1));
        };

        $this->assertSame(1, $pemuatItem(), 'Tanpa paket siap, hanya daftar paket_berjalan yang memuat item.');

        $this->item($this->order($this->ptA), $this->alat(), $this->fisik(TahapPaket::SIAP_DIAMBIL));

        $this->assertSame(2, $pemuatItem(), 'Dengan paket siap, hitungan memuat item satu kali (bukan satu per paket).');
        $this->assertSame(1, $this->ringkasan()['paket_siap_diambil']);
    }

    // --- sertifikat_baru_7_hari -------------------------------------------------

    public function test_sertifikat_baru_menghitung_jendela_tujuh_hari_yang_terbit_dan_belum_digantikan(): void
    {
        // Dihitung
        $this->sertifikat($this->alat([], null), ['diterbitkan_pada' => now()]);
        $this->sertifikat($this->alat([], null), ['diterbitkan_pada' => now()->subDays(7)]); // batas dalam
        $dihapus = $this->alat([], null);
        $this->sertifikat($dihapus, ['diterbitkan_pada' => now()->subDays(2)]);
        $dihapus->delete(); // alat dihapus lunak: sertifikatnya tetap milik pelanggan
        $alatRevisi = $this->alat([], null);
        $lama = $this->sertifikat($alatRevisi, ['diterbitkan_pada' => now()->subDays(3)]);
        $this->sertifikat($alatRevisi, ['diterbitkan_pada' => now()->subDay(), 'revision_of' => $lama->id, 'revisi_ke' => 1]);

        // TIDAK dihitung
        $this->sertifikat($this->alat([], null), ['diterbitkan_pada' => now()->subDays(8)]); // lewat jendela
        $this->sertifikat($this->alat([], null), ['diterbitkan_pada' => now()->addDay()]); // tanggal di depan
        $this->sertifikat($this->alat([], null), ['status' => Certificate::STATUS_DIBATALKAN, 'diterbitkan_pada' => now()]);
        $this->sertifikat($this->alat([], null), ['status' => Certificate::STATUS_MENUNGGU_GENERATE, 'diterbitkan_pada' => null]);
        $this->sertifikat($this->alat([], null), ['status' => Certificate::STATUS_GAGAL, 'diterbitkan_pada' => now()]);

        // 4 = hari ini, 7 hari lalu, alat terhapus, revisi (pendahulunya `digantikan` tidak ikut).
        $this->assertSame(4, $this->ringkasan()['sertifikat_baru_7_hari']);
    }

    public function test_sertifikat_baru_tidak_dibatasi_lima_seperti_sertifikat_terbaru(): void
    {
        foreach (range(1, 8) as $i) {
            $this->sertifikat($this->alat([], null), ['diterbitkan_pada' => now()->subDays($i % 5)]);
        }

        $respons = $this->ambil('/beranda');

        $this->assertCount(5, $respons->json('data.sertifikat_terbaru'), 'Daftar sertifikat_terbaru tetap lima (kontrak lama).');
        $this->assertSame(8, $respons->json('data.ringkasan.sertifikat_baru_7_hari'));
    }

    public function test_sertifikat_baru_sama_dengan_yang_terlihat_di_sertifikat_terbaru_saat_di_bawah_batas(): void
    {
        $this->sertifikat($this->alat([], null), ['diterbitkan_pada' => now()]);
        $this->sertifikat($this->alat([], null), ['diterbitkan_pada' => now()->subDays(4)]);
        $this->sertifikat($this->alat([], null), ['diterbitkan_pada' => now()->subDays(30)]);

        $respons = $this->ambil('/beranda');
        $terbaru = collect($respons->json('data.sertifikat_terbaru'));
        $dalamJendela = $terbaru->filter(fn (array $s): bool => $s['diterbitkan_pada'] >= now()->subDays(7)->toDateString())->count();

        $this->assertCount(3, $terbaru);
        $this->assertSame($dalamJendela, $respons->json('data.ringkasan.sertifikat_baru_7_hari'));
        $this->assertSame(2, $respons->json('data.ringkasan.sertifikat_baru_7_hari'));
    }

    // --- isolasi ----------------------------------------------------------------

    /** Data apa pun milik PT B -- dan milik PT A di LAB LAIN -- tidak boleh menggeser satu angka pun di PT A. */
    public function test_data_perusahaan_lain_dan_lab_lain_tidak_mempengaruhi_satu_angka_pun(): void
    {
        // Data PT A sendiri (supaya "nol" tidak lolos kebetulan).
        $this->alat(['tanggal_jatuh_tempo' => now()->addDays(200)]);
        $this->alat(['tanggal_kalibrasi_terakhir' => null], null);
        $this->item($this->order($this->ptA), $this->alat(), $this->fisik(TahapPaket::SIAP_DIAMBIL));
        $this->sertifikat($this->alat([], null), ['diterbitkan_pada' => now()]);
        $sebelum = $this->ringkasan();

        // PT B: satu set lengkap yang kalau bocor menggeser keempat angka.
        $this->alat(['tanggal_jatuh_tempo' => now()->addDays(200)], Certificate::STATUS_TERBIT, $this->ptB);
        $this->alat(['tanggal_kalibrasi_terakhir' => null], null, $this->ptB);
        $this->item($this->order($this->ptB), $this->alat([], null, $this->ptB), $this->fisik(TahapPaket::SIAP_DIAMBIL));
        $this->sertifikat($this->alat([], null, $this->ptB), ['diterbitkan_pada' => now()]);

        // Lab LAIN yang kebetulan memakai `customer_id` PT A (impor/salah ketik admin).
        $labLain = Organization::factory()->create();
        $asing = ['organization_id' => $labLain->id, 'customer_id' => $this->ptA->id, 'status' => Equipment::STATUS_AKTIF];
        $alatAsing = Equipment::factory()->create([...$asing, 'tanggal_jatuh_tempo' => now()->addDays(200)]);
        $this->sertifikat($alatAsing, ['diterbitkan_pada' => now()]);
        Equipment::factory()->create([...$asing, 'tanggal_kalibrasi_terakhir' => null]);
        $ordeAsing = Order::factory()->create(['organization_id' => $labLain->id, 'customer_id' => $this->ptA->id, 'status' => Order::STATUS_DIPROSES]);
        OrderItem::factory()->create(['order_id' => $ordeAsing->id, 'equipment_id' => $alatAsing->id, ...$this->fisik(TahapPaket::SIAP_DIAMBIL)]);

        $sesudah = $this->ringkasan();

        $this->assertSame($sebelum, $sesudah, 'Data perusahaan/lab lain menggeser ringkasan PT A.');
        // Angka mutlak PT A (supaya "sama-sama nol" tidak lolos kebetulan): tiga alat berlaku
        // (satu dari alat ber-sertifikat, satu di paket, satu pemilik sertifikat baru).
        $this->assertSame(
            ['alat_berlaku' => 3, 'alat_belum_pernah' => 1, 'paket_siap_diambil' => 1, 'sertifikat_baru_7_hari' => 1],
            array_intersect_key($sesudah, array_flip(self::KUNCI_BARU)),
        );
    }

    public function test_customer_id_dari_query_string_tidak_dipercaya(): void
    {
        $this->alat(['tanggal_jatuh_tempo' => now()->addDays(200)], Certificate::STATUS_TERBIT, $this->ptB);
        $this->sertifikat($this->alat([], null, $this->ptB), ['diterbitkan_pada' => now()]);

        $respons = $this->ambil("/beranda?customer_id={$this->ptB->id}&perusahaan_id={$this->ptB->id}");

        $this->assertSame(0, $respons->json('data.ringkasan.alat_berlaku'));
        $this->assertSame(0, $respons->json('data.ringkasan.sertifikat_baru_7_hari'));
        $this->assertSame($this->ptA->id, $respons->json('data.perusahaan.id'));
    }

    // --- biaya ------------------------------------------------------------------

    public function test_jumlah_kueri_beranda_tidak_bertambah_dengan_banyaknya_alat_paket_dan_sertifikat(): void
    {
        $buat = function (int $jumlah): void {
            for ($i = 0; $i < $jumlah; $i++) {
                $alat = $this->alat(['tanggal_jatuh_tempo' => now()->addDays(10 + $i)]);
                $item = $this->item($this->order($this->ptA), $alat, $i % 2 === 0 ? $this->fisik(TahapPaket::SIAP_DIAMBIL) : []);
                CalibrationSession::factory()->create([
                    'organization_id' => $alat->organization_id,
                    'equipment_id' => $alat->id,
                    'order_item_id' => $item->id,
                    'teknisi_id' => $this->teknisi->id,
                    'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
                ]);
                $this->sertifikat($this->alat([], null), ['diterbitkan_pada' => now()->subDays($i % 6)]);
            }
        };

        $hitung = function (): int {
            $this->ambil('/beranda'); // hangatkan
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->ambil('/beranda');
            $jumlah = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $jumlah;
        };

        $buat(2);
        $dua = $hitung();
        $buat(10);
        $duaBelas = $hitung();

        $ringkasan = $this->ringkasan();
        $this->assertSame(6, $ringkasan['paket_siap_diambil'], 'Prasyarat data: 6 paket siap dari 12.');
        $this->assertSame(12, $ringkasan['sertifikat_baru_7_hari']);
        $this->assertSame($dua, $duaBelas, "Kueri /beranda bertambah dari {$dua} ke {$duaBelas} saat data bertambah -- N+1.");
    }
}

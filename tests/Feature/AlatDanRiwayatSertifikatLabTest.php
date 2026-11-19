<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\User;
use App\Services\MatriksIzin;
use App\Support\KursorId;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * Melacak alat milik satu pelanggan dan riwayat sertifikatnya dari aplikasi lab
 * (18 Nov 2026): `GET /customers/{c}/alat` dan `GET /equipments/{e}/sertifikat`,
 * keduanya paginasi KURSOR. Kontrak: `docs/perintah-frontend-super-admin.md`
 * §3.4 dan §3.5.
 *
 * Yang dijaga dan kenapa:
 *
 * - **Isolasi** — lab lain dijawab 404 walau `limit`/`cursor`-nya rusak (422 lebih
 *   dulu dari 404 = bukti barisnya ada); sertifikat lab lain tak pernah ikut.
 * - **Peran** — teknisi hanya sesinya sendiri di riwayat; teknisi & viewer tidak
 *   boleh daftar alat pelanggan; token pelanggan & tamu ditolak.
 * - **Paginasi** — tidak ada baris hilang/ganda antar halaman, `limit` maks 50
 *   ditolak (bukan dipotong diam-diam), kursor rusak 422 (tidak pernah 500).
 * - **Konsisten dengan pelanggan** — `status_alat` dan `status_dokumen` sama
 *   dengan yang dikembalikan API pelanggan untuk baris yang sama.
 * - **Sertifikat lama tidak tertimpa** — revisi = baris baru, baris lama tetap.
 * - **Ukuran** — tanpa `snapshot`, dan jumlah query tidak tumbuh dengan jumlah baris.
 */
class AlatDanRiwayatSertifikatLabTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private Organization $lab;

    private Customer $pt;

    private User $teknisi;

    private int $nomor = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->siapkanJalurPelanggan();
        $this->lab = $this->organisasi();
        $this->pt = $this->perusahaan('PT Pelacakan Uji');
        $this->teknisi = $this->adminLab(User::ROLE_TEKNISI);
    }

    private function alat(?Customer $pt = null, array $tambahan = []): Equipment
    {
        $pt ??= $this->pt;

        return Equipment::factory()->create([
            'organization_id' => $pt->organization_id,
            'customer_id' => $pt->id,
            ...$tambahan,
        ]);
    }

    /** Satu sertifikat untuk alat ini, lewat sesi baru milik `$teknisi`. */
    private function sertifikat(Equipment $alat, array $tambahan = [], ?User $teknisi = null): Certificate
    {
        $sesi = CalibrationSession::factory()->create([
            'organization_id' => $alat->organization_id,
            'equipment_id' => $alat->id,
            'teknisi_id' => ($teknisi ?? $this->teknisi)->id,
        ]);

        return Certificate::factory()->create([
            'organization_id' => $alat->organization_id,
            'calibration_session_id' => $sesi->id,
            'nomor' => 'CAL/2026/11/'.str_pad((string) ++$this->nomor, 4, '0', STR_PAD_LEFT),
            ...$tambahan,
        ]);
    }

    /** Revisi dari `$asli` — baris BARU, `-R<n>`, sesi yang sama. */
    private function revisi(Certificate $asli, array $tambahan = []): Certificate
    {
        return Certificate::factory()->create([
            'organization_id' => $asli->organization_id,
            'calibration_session_id' => $asli->calibration_session_id,
            'revision_of' => $asli->id,
            'revisi_ke' => 1,
            'nomor' => $asli->nomor.'-R1',
            ...$tambahan,
        ]);
    }

    private function daftarAlat(User $pemanggil, Customer $pt, string $query = ''): TestResponse
    {
        return $this->actingAs($pemanggil)->getJson("/api/customers/{$pt->id}/alat".$query);
    }

    private function riwayat(User $pemanggil, Equipment $alat, string $query = ''): TestResponse
    {
        return $this->actingAs($pemanggil)->getJson("/api/equipments/{$alat->id}/sertifikat".$query);
    }

    // =================================================== GET /customers/{c}/alat

    public function test_admin_dan_super_admin_melihat_alat_pelanggan_dengan_jumlah_dan_sertifikat_terbaru(): void
    {
        $dengan = $this->alat($this->pt, [
            'nama_alat' => 'Termometer Digital', 'serial_number' => 'SN-LACAK-1',
            'tanggal_kalibrasi_terakhir' => now()->subMonths(11), 'tanggal_jatuh_tempo' => now()->addDays(14),
        ]);
        $asli = $this->sertifikat($dengan, ['diterbitkan_pada' => now()->subMonths(11)]);
        $r1 = $this->revisi($asli, ['diterbitkan_pada' => now()->subMonths(10)]);
        $gagal = $this->sertifikat($dengan, ['status' => Certificate::STATUS_GAGAL, 'diterbitkan_pada' => null]);

        $kosong = $this->alat($this->pt, ['nama_alat' => 'Alat Baru', 'serial_number' => 'SN-LACAK-2', 'tanggal_kalibrasi_terakhir' => null, 'tanggal_jatuh_tempo' => null]);
        $lewat = $this->alat($this->pt, ['nama_alat' => 'Alat Lewat', 'serial_number' => 'SN-LACAK-3', 'tanggal_jatuh_tempo' => now()->subDays(5)]);
        $this->sertifikat($lewat);

        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $peran) {
            $balasan = $this->daftarAlat($this->adminLab($peran), $this->pt)->assertOk();
            $baris = collect($balasan->json('data'))->keyBy('id');

            // Urut: terbaru didaftarkan dulu (id menurun).
            $this->assertSame([$lewat->id, $kosong->id, $dengan->id], $balasan->json('data.*.id'));

            $satu = $baris[$dengan->id];
            $this->assertSame('Termometer Digital', $satu['nama_alat']);
            $this->assertSame('SN-LACAK-1', $satu['serial_number']);
            $this->assertSame('aktif', $satu['status']);
            $this->assertSame('mendekati_jadwal', $satu['status_alat']);
            $this->assertSame(14, $satu['hari_ke_jatuh_tempo']);
            $this->assertSame(now()->addDays(14)->toDateString(), $satu['tanggal_jatuh_tempo']);
            // Semua baris sertifikat alat ini, status apa pun: asli + R1 + gagal.
            $this->assertSame(3, $satu['jumlah_sertifikat']);
            // Terbaru DIBUAT = id terbesar = yang gagal.
            $this->assertSame($gagal->id, $satu['sertifikat_terbaru']['id']);
            $this->assertSame('gagal', $satu['sertifikat_terbaru']['status']);
            $this->assertSame('belum_terbit', $satu['sertifikat_terbaru']['status_dokumen']);
            $this->assertNull($satu['sertifikat_terbaru']['diterbitkan_pada']);

            $this->assertSame(0, $baris[$kosong->id]['jumlah_sertifikat']);
            $this->assertNull($baris[$kosong->id]['sertifikat_terbaru']);
            $this->assertSame('belum_pernah', $baris[$kosong->id]['status_alat']);
            $this->assertNull($baris[$kosong->id]['hari_ke_jatuh_tempo']);

            $this->assertSame('overdue', $baris[$lewat->id]['status']);
            $this->assertSame('lewat_jadwal', $baris[$lewat->id]['status_alat']);
            $this->assertSame(-5, $baris[$lewat->id]['hari_ke_jatuh_tempo']);

            $this->assertSame(['limit' => 20, 'next_cursor' => null, 'ada_lagi' => false], $balasan->json('meta'));
        }

        // R1 tidak mengubah baris lama: asli tetap ada, nomor & tanggalnya utuh.
        $this->assertSame($asli->nomor, $asli->fresh()->nomor);
        $this->assertSame($r1->nomor, $asli->nomor.'-R1');
    }

    public function test_status_dan_riwayat_sama_dengan_yang_dilihat_pelanggan(): void
    {
        $pic = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $pt = $pic->keanggotaan()->firstOrFail()->customer;

        $alat = $this->alat($pt, [
            'serial_number' => 'SN-SAMA-1',
            'tanggal_kalibrasi_terakhir' => now()->subMonths(11), 'tanggal_jatuh_tempo' => now()->addDays(20),
        ]);
        $asli = $this->sertifikat($alat);
        $this->revisi($asli);
        $belum = $this->alat($pt, ['serial_number' => 'SN-SAMA-2', 'tanggal_kalibrasi_terakhir' => null, 'tanggal_jatuh_tempo' => null]);
        $lewat = $this->alat($pt, ['serial_number' => 'SN-SAMA-3', 'tanggal_jatuh_tempo' => now()->subDays(3)]);
        $this->sertifikat($lewat);

        $lab = $this->daftarAlat($this->adminLab(), $pt)->assertOk()->json('data');
        $labStatus = collect($lab)->pluck('status_alat', 'id')->all();

        $this->permintaanBaru();
        $punyaPelanggan = $this->withHeaders($this->bearer($pic))->getJson('/api/pelanggan/v1/alat')->assertOk()->json('data');
        $pelStatus = collect($punyaPelanggan)->pluck('status_alat', 'id')->all();

        // Kunci (id alat) DAN nilainya sama; urutan daftar tidak dibandingkan.
        ksort($pelStatus);
        ksort($labStatus);
        $this->assertSame($pelStatus, $labStatus);
        $this->assertSame(['belum_pernah', 'lewat_jadwal', 'mendekati_jadwal'], collect($labStatus)->sort()->values()->all());
        $this->assertSame($belum->id, array_search('belum_pernah', $labStatus, true));

        // Riwayat: berlaku / digantikan sama persis di dua dunia.
        $this->permintaanBaru();
        $detail = $this->withHeaders($this->bearer($pic))->getJson("/api/pelanggan/v1/alat/{$alat->id}")->assertOk()->json('data.riwayat_sertifikat');
        $statusPelanggan = collect($detail)->pluck('status', 'nomor')->all();

        $this->permintaanBaru();
        $statusLab = collect($this->riwayat($this->adminLab(), $alat)->assertOk()->json('data'))->pluck('status_dokumen', 'nomor')->all();

        ksort($statusPelanggan);
        ksort($statusLab);
        $this->assertSame($statusPelanggan, $statusLab);
        $this->assertSame('digantikan', $statusLab[$asli->nomor]);
        $this->assertSame('berlaku', $statusLab[$asli->nomor.'-R1']);
    }

    public function test_daftar_alat_berpaginasi_kursor_tanpa_baris_hilang_atau_ganda(): void
    {
        $semua = collect(range(1, 7))->map(fn () => $this->alat())->pluck('id')->sortDesc()->values()->all();
        $admin = $this->adminLab();

        $halaman1 = $this->daftarAlat($admin, $this->pt, '?limit=3')->assertOk();
        $this->assertSame(array_slice($semua, 0, 3), $halaman1->json('data.*.id'));
        $this->assertTrue($halaman1->json('meta.ada_lagi'));
        $this->assertSame(3, $halaman1->json('meta.limit'));
        $kursor = $halaman1->json('meta.next_cursor');
        $this->assertIsString($kursor);

        // Alat BARU yang masuk saat menggulir (id lebih besar) tidak menyusup ke halaman berikutnya.
        $susulan = $this->alat();

        $halaman2 = $this->daftarAlat($admin, $this->pt, '?limit=3&cursor='.$kursor)->assertOk();
        $this->assertSame(array_slice($semua, 3, 3), $halaman2->json('data.*.id'));
        $this->assertTrue($halaman2->json('meta.ada_lagi'));

        $halaman3 = $this->daftarAlat($admin, $this->pt, '?limit=3&cursor='.$halaman2->json('meta.next_cursor'))->assertOk();
        $this->assertSame(array_slice($semua, 6), $halaman3->json('data.*.id'));
        $this->assertFalse($halaman3->json('meta.ada_lagi'));
        $this->assertNull($halaman3->json('meta.next_cursor'));

        $this->assertNotContains($susulan->id, array_merge($halaman2->json('data.*.id'), $halaman3->json('data.*.id')));
    }

    public function test_limit_maksimum_50_ditolak_bukan_dipotong_dan_halaman_penuh_dibatasi_50(): void
    {
        $admin = $this->adminLab();
        collect(range(1, 52))->each(fn () => $this->alat());

        foreach (['0', '51', '-1', 'abc', '2.5'] as $limit) {
            $this->daftarAlat($admin, $this->pt, '?limit='.$limit)->assertStatus(422)->assertJsonValidationErrors('limit');
        }

        $penuh = $this->daftarAlat($admin, $this->pt, '?limit=50')->assertOk();
        $this->assertCount(50, $penuh->json('data'));
        $this->assertTrue($penuh->json('meta.ada_lagi'));

        $sisa = $this->daftarAlat($admin, $this->pt, '?limit=50&cursor='.$penuh->json('meta.next_cursor'))->assertOk();
        $this->assertCount(2, $sisa->json('data'));
        $this->assertFalse($sisa->json('meta.ada_lagi'));
    }

    public function test_kursor_rusak_dijawab_422_bukan_500_dan_bukan_halaman_pertama(): void
    {
        $this->alat();
        $admin = $this->adminLab();
        $enc = fn (string $isi) => rtrim(strtr(base64_encode($isi), '+/', '-_'), '=');

        $rusak = [
            'ngawur', '!!!', $enc('k1:abc'), $enc('k1:0'), $enc('k1:-5'), $enc('k1:1.5'), $enc('{"id":5}'),
            $enc('{"id":5,"_pointsToNextItems":true}'), $enc('k2:5'), $enc('k1:'.str_repeat('9', 30)), str_repeat('a', 65),
        ];

        foreach ($rusak as $kursor) {
            $this->daftarAlat($admin, $this->pt, '?cursor='.urlencode($kursor))
                ->assertStatus(422)
                ->assertJsonValidationErrors('cursor');
        }

        // Buatan server tetap diterima.
        $this->daftarAlat($admin, $this->pt, '?cursor='.KursorId::encode(999999))->assertOk();
    }

    public function test_daftar_alat_isolasi_lab_lain_pelanggan_lain_dan_alat_terhapus(): void
    {
        $lain = $this->perusahaan('PT Tetangga');
        $labLain = Organization::factory()->create(['nama' => 'Lab Lain']);
        $ptAsing = Customer::factory()->create(['organization_id' => $labLain->id, 'nama' => 'PT Asing']);
        $alatAsing = $this->alat($ptAsing);
        $milik = $this->alat();
        $alatTetangga = $this->alat($lain);
        $terhapus = $this->alat();
        $terhapus->delete();

        $admin = $this->adminLab(User::ROLE_SUPER_ADMIN);

        $ids = $this->daftarAlat($admin, $this->pt)->assertOk()->json('data.*.id');
        $this->assertSame([$milik->id], $ids);
        $this->assertNotContains($alatTetangga->id, $ids);
        $this->assertNotContains($terhapus->id, $ids);

        // Lab lain: 404 walau query-nya rusak (validasi tidak boleh mendahului penjaga).
        foreach (['', '?limit=999', '?cursor=ngawur', '?limit=abc&cursor=!!!'] as $query) {
            $this->daftarAlat($admin, $ptAsing, $query)->assertNotFound();
        }
        $this->actingAs($admin)->getJson('/api/customers/999999/alat?limit=999')->assertNotFound();
        $this->assertStringNotContainsString((string) $alatAsing->serial_number, (string) $this->daftarAlat($admin, $this->pt)->getContent());
    }

    public function test_daftar_alat_hanya_admin_dan_super_admin(): void
    {
        $this->alat();

        foreach ([User::ROLE_TEKNISI, User::ROLE_VIEWER] as $peran) {
            $this->daftarAlat($this->adminLab($peran), $this->pt)->assertForbidden();
        }

        $pic = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $this->permintaanBaru();
        $this->withHeaders($this->bearer($pic))->getJson("/api/customers/{$this->pt->id}/alat")->assertForbidden();

        $this->permintaanBaru();
        $this->getJson("/api/customers/{$this->pt->id}/alat")->assertUnauthorized();
    }

    public function test_daftar_alat_dibatasi_throttle_per_akun(): void
    {
        $admin = $this->adminLab();

        for ($i = 0; $i < 60; $i++) {
            $this->daftarAlat($admin, $this->pt, '?limit=1')->assertOk();
        }

        $this->daftarAlat($admin, $this->pt, '?limit=1')->assertStatus(429);
        $this->daftarAlat($this->adminLab(), $this->pt, '?limit=1')->assertOk();
    }

    public function test_jumlah_query_daftar_alat_tidak_tumbuh_dengan_jumlah_baris(): void
    {
        $admin = $this->adminLab();
        // Dasarnya SUDAH memuat satu revisi: query pencarian sertifikat asal hanya
        // jalan kalau ada revisi, jadi tanpa ini query yang muncul belakangan
        // terbaca sebagai "tumbuh" padahal ia tetap SATU query.
        $this->revisi($this->sertifikat($this->alat()));
        $this->sertifikat($this->alat());

        $ukur = function () use ($admin): int {
            $this->permintaanBaru();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->daftarAlat($admin, $this->pt, '?limit=50')->assertOk();
            $jumlah = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $jumlah;
        };

        $ukur(); // pemanasan: query sekali-seumur-proses tidak ikut dihitung
        $kecil = $ukur();

        foreach (range(1, 10) as $_) {
            $a = $this->alat();
            $this->revisi($this->sertifikat($a));
        }

        $this->assertSame($kecil, $ukur(), 'Jumlah query tumbuh bersama jumlah alat: ada N+1.');
    }

    // ============================================== GET /equipments/{e}/sertifikat

    public function test_riwayat_satu_alat_tahun_ke_tahun_tanpa_menimpa_yang_lama(): void
    {
        $alat = $this->alat($this->pt, ['serial_number' => 'SN-TAHUNAN']);
        $lain = $this->alat();
        $this->sertifikat($lain);

        $y2024 = $this->sertifikat($alat, ['diterbitkan_pada' => '2024-03-10', 'berlaku_sampai' => '2025-03-09']);
        $y2025 = $this->sertifikat($alat, ['diterbitkan_pada' => '2025-03-12', 'berlaku_sampai' => '2026-03-11']);
        $y2025r1 = $this->revisi($y2025, ['diterbitkan_pada' => '2025-04-01', 'berlaku_sampai' => '2026-03-11']);
        $tarik = $this->sertifikat($alat, ['status' => Certificate::STATUS_DITARIK, 'diterbitkan_pada' => '2026-03-20', 'ditarik_pada' => now()]);
        $gagal = $this->sertifikat($alat, ['status' => Certificate::STATUS_GAGAL, 'diterbitkan_pada' => null, 'berlaku_sampai' => null]);

        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN, User::ROLE_VIEWER] as $peran) {
            $balasan = $this->riwayat($this->adminLab($peran), $alat)->assertOk();
            $baris = collect($balasan->json('data'));

            // Terbaru DIBUAT dulu; alat lain tidak ikut.
            $this->assertSame([$gagal->id, $tarik->id, $y2025r1->id, $y2025->id, $y2024->id], $baris->pluck('id')->all(), $peran);

            $per = $baris->keyBy('id');
            $this->assertSame('gagal', $per[$gagal->id]['status']);
            $this->assertSame('belum_terbit', $per[$gagal->id]['status_dokumen']);
            $this->assertNull($per[$gagal->id]['tahun']);
            $this->assertNull($per[$gagal->id]['bulan']);

            $this->assertSame('ditarik', $per[$tarik->id]['status']);
            $this->assertSame('ditarik', $per[$tarik->id]['status_dokumen']);
            $this->assertNotNull($per[$tarik->id]['ditarik_pada']);
            $this->assertSame([2026, 3], [$per[$tarik->id]['tahun'], $per[$tarik->id]['bulan']]);

            $this->assertSame('berlaku', $per[$y2025r1->id]['status_dokumen']);
            $this->assertSame(1, $per[$y2025r1->id]['revisi_ke']);
            $this->assertSame(['id' => $y2025->id, 'nomor' => $y2025->nomor], $per[$y2025r1->id]['revisi_dari']);
            $this->assertNull($per[$y2025r1->id]['digantikan_oleh']);

            // Yang lama TETAP tampil, bukan ditimpa: digantikan + penunjuk ke penggantinya.
            $this->assertSame($y2025->nomor, $per[$y2025->id]['nomor']);
            $this->assertSame('terbit', $per[$y2025->id]['status']);
            $this->assertSame('digantikan', $per[$y2025->id]['status_dokumen']);
            $this->assertSame(
                ['id' => $y2025r1->id, 'nomor' => $y2025->nomor.'-R1', 'diterbitkan_pada' => '2025-04-01'],
                $per[$y2025->id]['digantikan_oleh'],
            );
            $this->assertSame([2025, 3], [$per[$y2025->id]['tahun'], $per[$y2025->id]['bulan']]);
            $this->assertSame('2025-03-12', $per[$y2025->id]['diterbitkan_pada']);
            $this->assertSame('2026-03-11', $per[$y2025->id]['berlaku_sampai']);

            $this->assertSame('berlaku', $per[$y2024->id]['status_dokumen']);
            $this->assertNull($per[$y2024->id]['revisi_dari']);
            $this->assertNull($per[$y2024->id]['digantikan_oleh']);

            $this->assertSame(['limit' => 20, 'next_cursor' => null, 'ada_lagi' => false], $balasan->json('meta'));
        }
    }

    public function test_revisi_dibatalkan_tetap_menjadikan_asli_digantikan_tanpa_penunjuk_pengganti(): void
    {
        $alat = $this->alat();
        $asli = $this->sertifikat($alat);
        $this->revisi($asli, ['status' => Certificate::STATUS_DIBATALKAN, 'dibatalkan_pada' => now()]);

        $gagalAsal = $this->sertifikat($alat);
        $this->revisi($gagalAsal, ['status' => Certificate::STATUS_GAGAL, 'diterbitkan_pada' => null]);

        $per = collect($this->riwayat($this->adminLab(), $alat)->assertOk()->json('data'))->keyBy('id');

        // K38-2: pembatalan revisi tidak menghidupkan pendahulunya, dan tidak ada yang berlaku untuk ditunjuk.
        $this->assertSame('digantikan', $per[$asli->id]['status_dokumen']);
        $this->assertNull($per[$asli->id]['digantikan_oleh']);

        // Revisi yang GAGAL belum menjadi dokumen: asalnya tetap berlaku.
        $this->assertSame('berlaku', $per[$gagalAsal->id]['status_dokumen']);
        $this->assertNull($per[$gagalAsal->id]['digantikan_oleh']);
    }

    public function test_teknisi_hanya_melihat_sertifikat_sesinya_sendiri(): void
    {
        $alat = $this->alat();
        $teknisiLain = $this->adminLab(User::ROLE_TEKNISI);

        $milikku = $this->sertifikat($alat, [], $this->teknisi);
        $this->sertifikat($alat, [], $teknisiLain);

        $this->assertSame([$milikku->id], $this->riwayat($this->teknisi, $alat)->assertOk()->json('data.*.id'));
        $this->assertCount(2, $this->riwayat($this->adminLab(), $alat)->assertOk()->json('data'));
        $this->assertCount(2, $this->riwayat($this->adminLab(User::ROLE_VIEWER), $alat)->assertOk()->json('data'));
        $this->assertSame([], $this->riwayat($teknisiLain, $this->alat())->assertOk()->json('data'));
    }

    public function test_riwayat_isolasi_lab_lain_alat_terhapus_dan_sertifikat_lab_lain(): void
    {
        $labLain = Organization::factory()->create(['nama' => 'Lab Lain']);
        $ptAsing = Customer::factory()->create(['organization_id' => $labLain->id, 'nama' => 'PT Asing']);
        $alatAsing = $this->alat($ptAsing);
        $this->sertifikat($alatAsing);

        $alat = $this->alat();
        $milik = $this->sertifikat($alat);
        // Sertifikat lab LAIN yang (salah data) menempel ke sesi alat ini tidak boleh ikut.
        $nyasar = $this->sertifikat($alat, ['organization_id' => $labLain->id]);

        $admin = $this->adminLab(User::ROLE_SUPER_ADMIN);

        $ids = $this->riwayat($admin, $alat)->assertOk()->json('data.*.id');
        $this->assertSame([$milik->id], $ids);
        $this->assertNotContains($nyasar->id, $ids);

        foreach (['', '?limit=999', '?cursor=ngawur'] as $query) {
            $this->riwayat($admin, $alatAsing, $query)->assertNotFound();
        }

        $terhapus = $this->alat();
        $this->sertifikat($terhapus);
        $terhapus->delete();
        $this->riwayat($admin, $terhapus)->assertNotFound();
        $this->actingAs($admin)->getJson('/api/equipments/999999/sertifikat?limit=999')->assertNotFound();
    }

    public function test_riwayat_berpaginasi_kursor_dan_limit_maks_50(): void
    {
        $alat = $this->alat();
        $semua = collect(range(1, 7))->map(fn () => $this->sertifikat($alat))->pluck('id')->sortDesc()->values()->all();
        $admin = $this->adminLab();

        $p1 = $this->riwayat($admin, $alat, '?limit=3')->assertOk();
        $this->assertSame(array_slice($semua, 0, 3), $p1->json('data.*.id'));
        $this->assertTrue($p1->json('meta.ada_lagi'));

        $p2 = $this->riwayat($admin, $alat, '?limit=3&cursor='.$p1->json('meta.next_cursor'))->assertOk();
        $this->assertSame(array_slice($semua, 3, 3), $p2->json('data.*.id'));

        $p3 = $this->riwayat($admin, $alat, '?limit=3&cursor='.$p2->json('meta.next_cursor'))->assertOk();
        $this->assertSame(array_slice($semua, 6), $p3->json('data.*.id'));
        $this->assertFalse($p3->json('meta.ada_lagi'));
        $this->assertNull($p3->json('meta.next_cursor'));

        foreach (['0', '51', 'abc'] as $limit) {
            $this->riwayat($admin, $alat, '?limit='.$limit)->assertStatus(422)->assertJsonValidationErrors('limit');
        }
        $this->riwayat($admin, $alat, '?cursor=ngawur')->assertStatus(422)->assertJsonValidationErrors('cursor');
    }

    public function test_riwayat_tidak_memuat_snapshot_dan_kolom_berat(): void
    {
        $alat = $this->alat();
        $this->sertifikat($alat, [
            'snapshot' => ['header' => ['equipment_name' => 'RAHASIA-SNAPSHOT'], 'isi' => str_repeat('x', 5000)],
            'validasi' => ['peringatan' => 'RAHASIA-VALIDASI'],
            'pdf_path' => 'arsip/rahasia-path.pdf',
        ]);

        $isi = (string) $this->riwayat($this->adminLab(), $alat)->assertOk()->getContent();
        foreach (['RAHASIA-SNAPSHOT', 'RAHASIA-VALIDASI', 'rahasia-path', 'snapshot', 'pdf_path', 'qr_payload', 'qr_token'] as $jangan) {
            $this->assertStringNotContainsString($jangan, $isi);
        }

        $isiAlat = (string) $this->daftarAlat($this->adminLab(), $this->pt)->assertOk()->getContent();
        foreach (['RAHASIA-SNAPSHOT', 'snapshot', 'pdf_path', 'qr_payload'] as $jangan) {
            $this->assertStringNotContainsString($jangan, $isiAlat);
        }
    }

    public function test_riwayat_menolak_pelanggan_dan_tamu_dan_dibatasi_throttle(): void
    {
        $alat = $this->alat();
        $this->sertifikat($alat);

        $pic = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $this->permintaanBaru();
        $this->withHeaders($this->bearer($pic))->getJson("/api/equipments/{$alat->id}/sertifikat")->assertForbidden();

        $this->permintaanBaru();
        $this->getJson("/api/equipments/{$alat->id}/sertifikat")->assertUnauthorized();

        $this->permintaanBaru();
        $admin = $this->adminLab();

        for ($i = 0; $i < 60; $i++) {
            $this->riwayat($admin, $alat, '?limit=1')->assertOk();
        }

        $this->riwayat($admin, $alat, '?limit=1')->assertStatus(429);
    }

    public function test_jumlah_query_riwayat_tidak_tumbuh_dengan_jumlah_baris(): void
    {
        $alat = $this->alat();
        $admin = $this->adminLab();
        $this->revisi($this->sertifikat($alat));

        $ukur = function () use ($admin, $alat): int {
            $this->permintaanBaru();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->riwayat($admin, $alat, '?limit=50')->assertOk();
            $jumlah = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $jumlah;
        };

        $ukur(); // pemanasan: query sekali-seumur-proses tidak ikut dihitung
        $kecil = $ukur();

        foreach (range(1, 12) as $_) {
            $this->revisi($this->sertifikat($alat));
        }

        $this->assertSame($kecil, $ukur(), 'Jumlah query tumbuh bersama jumlah sertifikat: ada N+1.');
    }

    /**
     * P-1 (uji kontrak app lawan API, 19 Nov 2026): lencana PASS/FAIL riwayat
     * sertifikat tidak pernah muncul karena API tidak mengirim `keputusan`, padahal
     * aplikasi membacanya (`SertifikatRingkas.keputusan`). Nilainya diambil dari
     * keputusan SESI yang tersimpan — sumber yang sama dengan sertifikat (detail
     * `GET /certificates/{id}`, halaman verifikasi, dan PDF) — bukan dihitung ulang.
     * Sertifikat tanpa vonis (43 dari 48 profil) = `null`, jadi tak ada lencana palsu.
     */
    public function test_riwayat_memuat_keputusan_tersimpan_sama_dengan_yang_tampil_di_sertifikat(): void
    {
        $alat = $this->alat();
        $lulus = $this->sertifikat($alat, ['qr_token' => 'qr-lulus-01']);
        $gagal = $this->sertifikat($alat, ['qr_token' => 'qr-gagal-02']);
        $tanpaVonis = $this->sertifikat($alat, ['qr_token' => 'qr-null-003']);

        $lulus->session->forceFill(['keputusan' => 'PASS'])->save();
        $gagal->session->forceFill(['keputusan' => 'FAIL'])->save();
        $tanpaVonis->session->forceFill(['keputusan' => null])->save();

        $admin = $this->adminLab();

        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN, User::ROLE_VIEWER] as $peran) {
            $per = collect($this->riwayat($this->adminLab($peran), $alat)->assertOk()->json('data'))->keyBy('id');

            $this->assertSame('PASS', $per[$lulus->id]['keputusan'], $peran);
            $this->assertSame('FAIL', $per[$gagal->id]['keputusan'], $peran);
            $this->assertArrayHasKey('keputusan', $per[$tanpaVonis->id], 'Kunci keputusan harus selalu ada (aditif), nilainya null.');
            $this->assertNull($per[$tanpaVonis->id]['keputusan'], $peran);
        }

        // Sama dengan yang tampil di sertifikat: detail internal & verifikasi publik.
        foreach ([$lulus, $gagal, $tanpaVonis] as $sertifikat) {
            $dariRiwayat = collect($this->riwayat($admin, $alat)->json('data'))->firstWhere('id', $sertifikat->id)['keputusan'];

            $this->assertSame($dariRiwayat, $this->actingAs($admin)->getJson("/api/certificates/{$sertifikat->id}")->assertOk()->json('data.keputusan'));
            $this->assertSame($dariRiwayat, $this->getJson("/api/verify/{$sertifikat->qr_token}")->assertOk()->json('data.keputusan'));
        }
    }

    public function test_keputusan_riwayat_tidak_dihitung_ulang_dari_hasil_titik(): void
    {
        $alat = $this->alat();
        $sertifikat = $this->sertifikat($alat);
        // Vonis sesi yang TERSIMPAN yang menang; riwayat tidak menengok titik hasil.
        $sertifikat->session->forceFill(['keputusan' => 'FAIL'])->save();

        $baris = collect($this->riwayat($this->adminLab(), $alat)->assertOk()->json('data'))->firstWhere('id', $sertifikat->id);

        $this->assertSame('FAIL', $baris['keputusan']);
    }

    /**
     * QA ronde 2-B (saran peninjau #283): sertifikat yang pernah terbit tetap milik
     * sesinya walau sesi itu kemudian dihapus lunak. Riwayat menggabung
     * `calibration_sessions` tanpa memfilter `deleted_at`, jadi barisnya MUNCUL --
     * dan `keputusan`-nya tidak boleh hilang menjadi null hanya karena query
     * keputusan memakai lingkup bawaan (tanpa baris terhapus lunak).
     */
    public function test_keputusan_riwayat_tetap_terbaca_walau_sesinya_terhapus_lunak(): void
    {
        $alat = $this->alat();
        $sertifikat = $this->sertifikat($alat);
        $sertifikat->session->forceFill(['keputusan' => 'FAIL'])->save();
        $sertifikat->session->delete();
        $this->assertSoftDeleted('calibration_sessions', ['id' => $sertifikat->calibration_session_id]);

        $baris = collect($this->riwayat($this->adminLab(), $alat)->assertOk()->json('data'))->firstWhere('id', $sertifikat->id);

        $this->assertNotNull($baris, 'sertifikat dari sesi yang terhapus lunak tetap tercantum');
        $this->assertSame('FAIL', $baris['keputusan']);
    }

    /**
     * QA ronde 2-B (saran peninjau #283): daftar alat satu pelanggan memakai
     * `ringkasanPerAlat()`, yang TIDAK memuat `keputusan`, jadi tidak boleh
     * menjalankan query `keputusan` ke `calibration_sessions` (dulu satu query
     * terbuang per halaman). Riwayat per alat tetap membacanya.
     */
    public function test_ringkasan_per_alat_tidak_menjalankan_query_keputusan_yang_terbuang(): void
    {
        $alat = $this->alat();
        $this->sertifikat($alat);
        $admin = $this->adminLab();

        $queryKeputusan = function (callable $panggil): int {
            $jumlah = 0;
            DB::listen(function ($q) use (&$jumlah): void {
                if (str_contains($q->sql, 'keputusan') && str_contains($q->sql, 'calibration_sessions')) {
                    $jumlah++;
                }
            });
            $panggil();

            return $jumlah;
        };

        $this->assertSame(0, $queryKeputusan(fn () => $this->daftarAlat($admin, $this->pt)->assertOk()), 'daftar alat menjalankan query keputusan yang tidak dipakai');
        $this->assertGreaterThan(0, $queryKeputusan(fn () => $this->riwayat($admin, $alat)->assertOk()), 'riwayat per alat tetap membaca keputusan');
    }

    // ============================================================== /me/permissions

    public function test_izin_matriks_alat_pelanggan_dan_riwayat_sertifikat(): void
    {
        $matriks = app(MatriksIzin::class);

        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN] as $peran) {
            $this->assertContains('pelanggan.alat-lihat', $matriks->bolehUntuk($peran), $peran);
        }
        foreach ([User::ROLE_TEKNISI, User::ROLE_VIEWER] as $peran) {
            $this->assertNotContains('pelanggan.alat-lihat', $matriks->bolehUntuk($peran), $peran);
        }
        foreach ([User::ROLE_ADMIN, User::ROLE_SUPER_ADMIN, User::ROLE_TEKNISI, User::ROLE_VIEWER] as $peran) {
            $this->assertContains('alat.riwayat-sertifikat', $matriks->bolehUntuk($peran), $peran);
        }

        $this->assertSame([], $matriks->petaYangNgawur());
    }
}

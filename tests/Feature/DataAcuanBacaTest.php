<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PaketAcuan;
use App\Models\PaketAcuanSuntingan;
use App\Models\PaketAcuanVersi;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Support\JsonKanonik;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PaketAcuanBaca;
use Tests\TestCase;

/**
 * Bentuk respons API baca Studio Data Acuan (CR-48 P1 B4) — FR-01, FR-03,
 * FR-04, FR-11, FR-28. Izin & isolasi organisasi ada di `IzinDataAcuanTest`.
 *
 * Paket micrometer versi 1 lahir dari `acuan:impor-awal` (organisasi tanpa
 * sesi → berlaku 2024-01-01, 07 §3). Angka di versi 2+ angka UJI.
 */
class DataAcuanBacaTest extends TestCase
{
    use PaketAcuanBaca;
    use RefreshDatabase;

    /** `balok_ukur['19.0']` di JSON repo. */
    private const BALOK_19 = 19.00011;

    /** Nilai uji pengganti untuk balok 19,0 mm. */
    private const BALOK_19_UJI = 19.00211;

    private Organization $organisasi;

    private User $admin;

    private PaketAcuanVersi $v1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organisasi = Organization::factory()->create();
        $this->admin = $this->pengguna($this->organisasi, User::ROLE_ADMIN);
        $this->v1 = $this->imporV1($this->organisasi);
    }

    public function test_daftar_paket_fr01(): void
    {
        $v2 = $this->versiBaru($this->v1, 2, PaketAcuanVersi::STATUS_DRAF, ubah: self::ubahBalok('19.0', self::BALOK_19_UJI));
        $v3 = $this->versiBaru($this->v1, 3, PaketAcuanVersi::STATUS_TERJADWAL, '2099-01-01');

        $data = $this->actingAs($this->admin)
            ->getJson('/api/data-acuan/paket')
            ->assertOk()
            ->json('data');

        // Satu-satunya profil ber-skema hari ini; sudah diimpor → tidak ada
        // baris "belum diimpor" tambahan.
        $this->assertCount(1, $data);
        $this->assertSame('micrometer', $data[0]['kode']);
        $this->assertSame('Micrometer', $data[0]['nama']);
        $this->assertTrue($data[0]['diimpor']);
        $this->assertSame([
            'id' => $this->v1->id,
            'nomor' => 1,
            'status' => PaketAcuanVersi::STATUS_AKTIF,
            'berlaku_mulai' => '2024-01-01',
            'berlaku_sampai' => null,
            'sha256' => $this->v1->isi_sha256,
        ], $data[0]['versi_aktif']);
        $this->assertSame([$v3->id], array_column($data[0]['versi_terjadwal'], 'id'));
        $this->assertSame('2099-01-01', $data[0]['versi_terjadwal'][0]['berlaku_mulai']);
        $this->assertSame(1, $data[0]['jumlah_draf'], "draf #{$v2->id}");
    }

    public function test_daftar_paket_memuat_profil_yang_belum_diimpor(): void
    {
        $labBaru = Organization::factory()->create();

        $data = $this->actingAs($this->pengguna($labBaru, User::ROLE_ADMIN))
            ->getJson('/api/data-acuan/paket')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('micrometer', $data[0]['kode']);
        $this->assertFalse($data[0]['diimpor']);
        $this->assertNull($data[0]['versi_aktif']);
        $this->assertSame([], $data[0]['versi_terjadwal']);
        $this->assertSame(0, $data[0]['jumlah_draf']);
    }

    public function test_detail_paket(): void
    {
        $this->versiBaru($this->v1, 2, PaketAcuanVersi::STATUS_DRAF);
        $this->versiBaru($this->v1, 3, PaketAcuanVersi::STATUS_DIAJUKAN);

        $data = $this->actingAs($this->admin)
            ->getJson('/api/data-acuan/paket/micrometer')
            ->assertOk()
            ->json('data');

        $this->assertSame('micrometer', $data['kode']);
        $this->assertSame(1, $data['versi_aktif']['nomor']);
        $this->assertSame(1, $data['jumlah_draf']);
        $this->assertSame(1, $data['jumlah_diajukan']);
        $this->assertSame([], $data['versi_terjadwal']);
        $this->assertSame(1, $data['skema']['skema_versi']);
        $this->assertSame(
            ['standar', 'balok_ukur', 'ketidakpastian_balok_tangga', 'ketidakpastian_balok_khusus',
                'ketidakpastian_balok_lainnya', 'pita_cmc', 'titik_pra_cetak', 'konstanta'],
            array_column($data['skema']['lembar'], 'kode'),
        );

        $this->actingAs($this->admin)->getJson('/api/data-acuan/paket/ph_meter')->assertNotFound();
        $this->actingAs($this->admin)->getJson('/api/data-acuan/paket/tidak-ada')->assertNotFound();
    }

    public function test_berlaku_fr04(): void
    {
        $url = '/api/data-acuan/paket/micrometer/berlaku';

        // Sebelum versi 1: null + alasan, tanpa fallback ke versi aktif hari ini.
        $sebelum = $this->actingAs($this->admin)->getJson($url.'?tanggal=2023-12-31')->assertOk();
        $this->assertNull($sebelum->json('data'));
        $this->assertStringContainsString('2024-01-01', (string) $sebelum->json('alasan'));

        $ada = $this->actingAs($this->admin)->getJson($url.'?tanggal=2025-05-02&jenis=data')->assertOk();
        $this->assertSame($this->v1->id, $ada->json('data.id'));
        $this->assertSame(1, $ada->json('data.nomor'));
        $this->assertSame('micrometer', $ada->json('data.paket'));
        $this->assertNull($ada->json('alasan'));
        $this->assertArrayNotHasKey('isi', $ada->json('data'), 'isi hanya di versi/{versi}');

        // Tepat di berlaku_mulai (inklusif).
        $this->assertSame($this->v1->id, $this->actingAs($this->admin)->getJson($url.'?tanggal=2024-01-01')->json('data.id'));

        // Belum ada versi bentuk sama sekali.
        $bentuk = $this->actingAs($this->admin)->getJson($url.'?tanggal=2025-05-02&jenis=bentuk')->assertOk();
        $this->assertNull($bentuk->json('data'));
        $this->assertNotEmpty($bentuk->json('alasan'));

        $this->actingAs($this->admin)->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('tanggal');
        $this->actingAs($this->admin)->getJson($url.'?tanggal=02-05-2025')->assertUnprocessable()->assertJsonValidationErrors('tanggal');
        $this->actingAs($this->admin)->getJson($url.'?tanggal=2025-02-30')->assertUnprocessable()->assertJsonValidationErrors('tanggal');
        $this->actingAs($this->admin)->getJson($url.'?tanggal=2025-05-02&jenis=rumus')->assertUnprocessable()->assertJsonValidationErrors('jenis');
    }

    public function test_garis_waktu_urut_nomor(): void
    {
        $this->v1->update(['status' => PaketAcuanVersi::STATUS_PENSIUN, 'berlaku_sampai' => '2025-12-31']);
        $this->versiBaru($this->v1, 2, PaketAcuanVersi::STATUS_AKTIF, '2026-01-01');
        $this->versiBaru($this->v1, 3, PaketAcuanVersi::STATUS_DRAF);

        $data = $this->actingAs($this->admin)
            ->getJson('/api/data-acuan/paket/micrometer/versi')
            ->assertOk()
            ->json('data');

        $this->assertSame([1, 2, 3], array_column($data, 'nomor'));
        $this->assertSame(['pensiun', 'aktif', 'draf'], array_column($data, 'status'));
        $this->assertSame('2025-12-31', $data[0]['berlaku_sampai']);
        $this->assertNull($data[2]['isi_sha256'], 'draf belum dikunci');
        $this->assertArrayNotHasKey('isi', $data[0]);
    }

    public function test_detail_versi_memuat_isi_yang_hashnya_cocok(): void
    {
        $respons = $this->actingAs($this->admin)
            ->getJson("/api/data-acuan/versi/{$this->v1->id}")
            ->assertOk();

        $this->assertSame('micrometer', $respons->json('data.paket'));
        $this->assertSame(1, $respons->json('data.nomor'));
        $this->assertSame($this->v1->isi_sha256, $respons->json('data.isi_sha256'));
        $this->assertSame(self::BALOK_19, $respons->json('data.isi.balok_ukur')['19.0']);
        $this->assertSame(200, $respons->json('data.isi.konstanta.vi_type_b'), 'int tetap int');

        // Klien bisa memverifikasi isi yang diterimanya: kanonik ulang isi
        // respons = sha256 tersimpan. Diurai sebagai objek supaya `{}` dan
        // kunci objek berangka tidak berubah bentuk.
        $isi = json_decode((string) $respons->getContent(), false, 512, JSON_THROW_ON_ERROR)->data->isi;
        $this->assertSame($this->v1->isi_sha256, JsonKanonik::sha256(JsonKanonik::serialisasi($isi)));
    }

    public function test_banding_satu_nilai_terkoreksi_fr11(): void
    {
        $v2 = $this->versiBaru($this->v1, 2, PaketAcuanVersi::STATUS_DRAF, ubah: self::ubahBalok('19.0', self::BALOK_19_UJI));

        $data = $this->actingAs($this->admin)
            ->getJson("/api/data-acuan/versi/{$this->v1->id}/banding/{$v2->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame('micrometer', $data['paket']);
        $this->assertSame($this->v1->id, $data['lama']['id']);
        $this->assertSame($v2->id, $data['baru']['id']);
        $this->assertSame(
            ['lembar_berubah' => 1, 'sel_berubah' => 1, 'baris_tambah' => 0, 'baris_hapus' => 0],
            $data['ringkasan'],
        );
        $this->assertCount(8, $data['lembar'], 'satu entri per lembar skema');

        $sel = collect($data['lembar'])->flatMap(fn (array $l): array => $l['sel_berubah'])->all();

        $this->assertCount(1, $sel);
        $this->assertSame('balok_ukur', $sel[0]['lembar']);
        $this->assertSame('19.0', $sel[0]['kunci']);
        $this->assertSame('nilai_terkoreksi_mm', $sel[0]['kolom']);
        $this->assertSame(self::BALOK_19, $sel[0]['lama']);
        $this->assertSame(self::BALOK_19_UJI, $sel[0]['baru']);
        // Selisih presisi penuh — sama bit dengan aritmetika float langsung.
        $this->assertSame(self::BALOK_19_UJI - self::BALOK_19, $sel[0]['selisih_absolut']);
        $this->assertSame((self::BALOK_19_UJI - self::BALOK_19) / self::BALOK_19, $sel[0]['selisih_relatif']);

        // Banding ke diri sendiri: nol perubahan.
        $sama = $this->actingAs($this->admin)
            ->getJson("/api/data-acuan/versi/{$this->v1->id}/banding/{$this->v1->id}")
            ->assertOk()
            ->json('data.ringkasan');
        $this->assertSame(['lembar_berubah' => 0, 'sel_berubah' => 0, 'baris_tambah' => 0, 'baris_hapus' => 0], $sama);
    }

    public function test_banding_baris_tambah_hapus_dan_jalur_bintang(): void
    {
        $v2 = $this->versiBaru($this->v1, 2, PaketAcuanVersi::STATUS_DRAF, ubah: static function (array $isi): array {
            unset($isi['balok_ukur']['1.1']);
            $isi['balok_ukur']['3.0'] = 3.00007;
            // Titik ke-4 varian B (indeks 1, posisi 3): 32,7 → 32,8 (angka uji).
            $isi['cmc'][1]['titik'][3]['nominal_cetak_mm'] = 32.8;

            return $isi;
        });

        $data = $this->actingAs($this->admin)
            ->getJson("/api/data-acuan/versi/{$this->v1->id}/banding/{$v2->id}")
            ->assertOk()
            ->json('data');

        $this->assertSame(
            ['lembar_berubah' => 2, 'sel_berubah' => 1, 'baris_tambah' => 1, 'baris_hapus' => 1],
            $data['ringkasan'],
        );

        $balok = collect($data['lembar'])->firstWhere('kode', 'balok_ukur');
        $this->assertSame([['kunci' => '3.0', 'sel' => ['nominal_mm' => '3.0', 'nilai_terkoreksi_mm' => 3.00007]]], $balok['baris_tambah']);
        $this->assertSame([['kunci' => '1.1', 'sel' => ['nominal_mm' => '1.1', 'nilai_terkoreksi_mm' => 1.09989]]], $balok['baris_hapus']);

        $titik = collect($data['lembar'])->firstWhere('kode', 'titik_pra_cetak')['sel_berubah'];
        $this->assertCount(1, $titik);
        $this->assertSame('1:3', $titik[0]['kunci'], 'awalan elemen induk `cmc/*`');
        $this->assertSame('nominal_cetak_mm', $titik[0]['kolom']);
        $this->assertSame(32.7, $titik[0]['lama']);
        $this->assertSame(32.8, $titik[0]['baru']);
    }

    public function test_banding_versi_beda_paket_422(): void
    {
        $paketLain = PaketAcuan::create([
            'organization_id' => $this->organisasi->id,
            'kode_profil' => 'paket-uji-lain',
            'nama' => 'Paket uji lain',
            'skema_versi' => 1,
        ]);
        $versiLain = PaketAcuanVersi::create([
            'paket_acuan_id' => $paketLain->id,
            'organization_id' => $this->organisasi->id,
            'jenis' => PaketAcuanVersi::JENIS_DATA,
            'nomor_versi' => 1,
            'status' => PaketAcuanVersi::STATUS_DRAF,
            'isi' => $this->v1->isi,
            'skema_versi' => 1,
        ]);

        $this->actingAs($this->admin)
            ->getJson("/api/data-acuan/versi/{$this->v1->id}/banding/{$versiLain->id}")
            ->assertUnprocessable()
            ->assertJsonPath('kode', 'versi_beda_paket');
    }

    public function test_banding_versi_beda_jenis_422(): void
    {
        $bentuk = $this->versiDiPaket($this->v1->paketAcuan, PaketAcuanVersi::JENIS_BENTUK, 1);

        // Dua arah: yang mana pun yang `data`, tetap ditolak.
        foreach ([[$this->v1, $bentuk], [$bentuk, $this->v1]] as [$lama, $baru]) {
            $this->actingAs($this->admin)
                ->getJson("/api/data-acuan/versi/{$lama->id}/banding/{$baru->id}")
                ->assertUnprocessable()
                ->assertExactJson([
                    'message' => 'Versi data tidak bisa dibandingkan dengan versi bentuk lembar.',
                    'kode' => 'versi_beda_jenis',
                ]);
        }
    }

    public function test_banding_dua_versi_bentuk_422_jenis_belum_didukung(): void
    {
        $bentuk1 = $this->versiDiPaket($this->v1->paketAcuan, PaketAcuanVersi::JENIS_BENTUK, 1);
        $bentuk2 = $this->versiDiPaket($this->v1->paketAcuan, PaketAcuanVersi::JENIS_BENTUK, 2);

        $this->actingAs($this->admin)
            ->getJson("/api/data-acuan/versi/{$bentuk1->id}/banding/{$bentuk2->id}")
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Banding versi bentuk lembar belum tersedia.',
                'kode' => 'jenis_belum_didukung',
            ]);
    }

    public function test_banding_profil_tanpa_skema_acuan_422_skema_tidak_ada(): void
    {
        // pH Meter: profil nyata di registry, tapi belum punya skemaAcuan().
        $profil = app(CalibrationProfileRegistry::class)->untukKode('ph_meter');
        $this->assertNotNull($profil, 'profilnya ada');
        $this->assertNull($profil->skemaAcuan());

        $paket = PaketAcuan::create([
            'organization_id' => $this->organisasi->id,
            'kode_profil' => 'ph_meter',
            'nama' => 'pH Meter (uji)',
            'skema_versi' => 1,
        ]);
        $v1 = $this->versiDiPaket($paket, PaketAcuanVersi::JENIS_DATA, 1);
        $v2 = $this->versiDiPaket($paket, PaketAcuanVersi::JENIS_DATA, 2);

        $this->actingAs($this->admin)
            ->getJson("/api/data-acuan/versi/{$v1->id}/banding/{$v2->id}")
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Profil paket ini tidak punya skema data acuan di kode.',
                'kode' => 'skema_tidak_ada',
            ]);
    }

    public function test_riwayat_sel_lintas_versi_dan_suntingan_fr03_fr28(): void
    {
        $v2 = $this->versiBaru($this->v1, 2, PaketAcuanVersi::STATUS_DRAF, ubah: self::ubahBalok('19.0', self::BALOK_19_UJI));

        $suntingan = PaketAcuanSuntingan::create([
            'paket_acuan_versi_id' => $v2->id,
            'lembar' => 'balok_ukur',
            'kunci_baris' => '19.0',
            'kolom' => 'nilai_terkoreksi_mm',
            'nilai_lama' => '19.00011',
            'nilai_baru' => '19.00211',
            'sumber' => PaketAcuanSuntingan::SUMBER_KETIK,
            'alasan' => 'Uji riwayat sel',
            'user_id' => $this->admin->id,
        ]);
        // Sel lain di versi yang sama — tidak boleh ikut.
        PaketAcuanSuntingan::create([
            'paket_acuan_versi_id' => $v2->id,
            'lembar' => 'balok_ukur',
            'kunci_baris' => '21.0',
            'kolom' => 'nilai_terkoreksi_mm',
            'nilai_lama' => '20.99987',
            'nilai_baru' => '20.99988',
            'sumber' => PaketAcuanSuntingan::SUMBER_KETIK,
            'user_id' => $this->admin->id,
        ]);

        $data = $this->actingAs($this->admin)
            ->getJson("/api/data-acuan/versi/{$v2->id}/sel/balok_ukur/19.0/nilai_terkoreksi_mm/riwayat")
            ->assertOk()
            ->json('data');

        $this->assertSame('micrometer', $data['paket']);
        $this->assertSame($v2->id, $data['versi']['id']);
        $this->assertTrue($data['ada']);
        $this->assertSame(self::BALOK_19_UJI, $data['nilai']);
        $this->assertSame('Standar_GB!R10:R132', $data['kolom']['asal']);
        $this->assertSame('mm', $data['kolom']['satuan']);

        $this->assertSame([1, 2], array_column(array_column($data['riwayat'], 'versi'), 'nomor'));
        $this->assertSame([self::BALOK_19, self::BALOK_19_UJI], array_column($data['riwayat'], 'nilai'));
        $this->assertSame([true, true], array_column($data['riwayat'], 'ada'));

        $this->assertCount(1, $data['suntingan']);
        $this->assertSame($suntingan->id, $data['suntingan'][0]['id']);
        $this->assertSame(2, $data['suntingan'][0]['nomor_versi']);
        $this->assertSame('19.00011', $data['suntingan'][0]['nilai_lama']);
        $this->assertSame('19.00211', $data['suntingan'][0]['nilai_baru']);
        $this->assertSame(['id' => $this->admin->id, 'nama' => $this->admin->name], $data['suntingan'][0]['user']);
    }

    public function test_riwayat_sel_kunci_khusus_dan_lembar_objek(): void
    {
        $url = fn (string $lembar, string $kunci, string $kolom): string => "/api/data-acuan/versi/{$this->v1->id}/sel/{$lembar}/{$kunci}/{$kolom}/riwayat";

        // Kunci objek berangka bulat (`"200"` terurai jadi kunci int di PHP).
        $this->assertSame(0.73, $this->actingAs($this->admin)->getJson($url('ketidakpastian_balok_khusus', '200', 'u_um'))->assertOk()->json('data.nilai'));
        // Lembar objek: satu baris berkunci 0.
        $this->assertSame(200, $this->actingAs($this->admin)->getJson($url('konstanta', '0', 'vi_type_b'))->assertOk()->json('data.nilai'));
        // Tangga tanpa kunci: posisi.
        $this->assertSame(0.14, $this->actingAs($this->admin)->getJson($url('ketidakpastian_balok_tangga', '1', 'u'))->assertOk()->json('data.nilai'));

        // Kunci yang tidak ada di versi mana pun: 200, `ada` false.
        $kosong = $this->actingAs($this->admin)->getJson($url('balok_ukur', '999.0', 'nilai_terkoreksi_mm'))->assertOk();
        $this->assertFalse($kosong->json('data.ada'));
        $this->assertNull($kosong->json('data.nilai'));

        // Lembar / kolom di luar skema → 404.
        $this->actingAs($this->admin)->getJson($url('tidak_ada', '1.0', 'nilai_terkoreksi_mm'))->assertNotFound();
        $this->actingAs($this->admin)->getJson($url('balok_ukur', '1.0', 'tidak_ada'))->assertNotFound();
    }

    /** Versi draf berisi salinan isi v1 di paket mana pun milik organisasi ini. */
    private function versiDiPaket(PaketAcuan $paket, string $jenis, int $nomor): PaketAcuanVersi
    {
        return PaketAcuanVersi::create([
            'paket_acuan_id' => $paket->id,
            'organization_id' => $this->organisasi->id,
            'jenis' => $jenis,
            'nomor_versi' => $nomor,
            'status' => PaketAcuanVersi::STATUS_DRAF,
            'isi' => $this->v1->isi,
            'skema_versi' => 1,
        ]);
    }
}

<?php

namespace Tests\Feature\Pelanggan;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\CustomerMember;
use App\Models\Equipment;
use App\Models\Order;
use App\Models\UndanganPelanggan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RuteTerdaftar;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Tests\Concerns\JalurPelanggan;
use Tests\TestCase;

/**
 * NFR-03 — tiap rute pelanggan ber-ID dicoba dengan ID milik perusahaan LAIN.
 *
 * ## Yang dijaga, dan seberapa besar cakupannya HARI INI
 *
 * Daftar rutenya dibaca dari router, bukan diketik — jadi rute ber-parameter
 * yang lahir nanti ikut tersapu otomatis. Per 16 Sep 2026 rute pelanggan
 * ber-parameter baru **dua** (`/anggota/undangan/{undangan}` dan
 * `/anggota/{anggota}/nonaktifkan`), dan dua-duanya sudah punya test 404
 * sendiri di `AnggotaDanPeranTest`.
 *
 * Sejak slice F (29 Sep 2026) sapuan ini ikut memeriksa `/alat/{alat}`,
 * `/sertifikat/{sertifikat}` (+ `/unduh`), `/paket/{paket}`, dan
 * `/notifikasi/{notifikasi}/dibaca` — keempatnya tidak bisa lahir tanpa
 * fixture milik B, karena parameter tanpa fixture memerahkan
 * [test_tiap_parameter_rute_punya_fixture()].
 *
 * Sejak 30 Sep 2026 ikut `/permintaan/{permintaan}` beserta `/batal` dan
 * `/pesan` (GET & POST). POST-nya dijawab 404 SEBELUM validasi body — kalau
 * validasi jalan duluan, id perusahaan lain dijawab 422 dan itu sudah mengakui
 * barisnya ada.
 *
 * ## Sumbu yang TIDAK diuji di sini
 *
 * Isolasi lewat header `X-Perusahaan-Id` ada di `KonteksPerusahaanTest`. Dua
 * sumbu yang berbeda: yang di sana "perusahaan mana yang saya sebut", yang di
 * sini "ID milik siapa yang saya kirim di URL". Rute bisa aman di satu sumbu
 * dan bocor di sumbu lain.
 *
 * ## 404, bukan 403 (BR-02)
 *
 * 403 sudah memberi tahu bahwa baris dengan ID itu ADA — cukup buat menyisir
 * pelanggan PT Sidik dengan menembak ID satu per satu.
 */
class IsolasiPerusahaanTest extends TestCase
{
    use JalurPelanggan, RefreshDatabase;

    private const PREFIX = 'api/pelanggan/v1/';

    private User $picA;

    private Customer $perusahaanA;

    private Customer $perusahaanB;

    /** @var array<string, string> nama parameter → ID milik perusahaan B */
    private array $milikB = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->siapkanJalurPelanggan();

        // A = perusahaan si pemanggil. Dia PIC utama supaya gerbang `peran:`
        // tidak menjawab lebih dulu — yang diuji isolasi ID, bukan peran.
        $this->picA = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $this->perusahaanA = $this->picA->keanggotaan()->first()->customer;

        // B = perusahaan orang lain, lengkap dengan barisnya sendiri.
        $picB = $this->anggota(CustomerMember::PERAN_PIC_UTAMA);
        $this->perusahaanB = $picB->keanggotaan()->first()->customer;

        $this->milikB = [
            'anggota' => (string) $picB->keanggotaan()->first()->getKey(),
            'undangan' => (string) UndanganPelanggan::factory()->create([
                'organization_id' => $this->perusahaanB->organization_id,
                'customer_id' => $this->perusahaanB->getKey(),
            ])->getKey(),
        ];

        // Slice F (29 Sep 2026): data perusahaan B yang BENERAN milik B —
        // alat, sertifikat terbit atas alat itu, paket, dan satu notifikasi
        // milik PIC B.
        $alatB = Equipment::factory()->create([
            'organization_id' => $this->perusahaanB->organization_id,
            'customer_id' => $this->perusahaanB->getKey(),
        ]);
        $sertifikatB = Certificate::factory()->create([
            'organization_id' => $this->perusahaanB->organization_id,
            'calibration_session_id' => CalibrationSession::factory()->create([
                'organization_id' => $this->perusahaanB->organization_id,
                'equipment_id' => $alatB->getKey(),
            ])->getKey(),
            'pdf_path' => 'sertifikat/milik-b.pdf',
        ]);
        $paketB = Order::factory()->create([
            'organization_id' => $this->perusahaanB->organization_id,
            'customer_id' => $this->perusahaanB->getKey(),
        ]);
        $notifikasiB = $picB->notifications()->create([
            'id' => (string) Str::uuid(),
            'type' => 'uji',
            'data' => ['title' => 'milik B'],
        ]);

        $this->milikB += [
            'alat' => (string) $alatB->getKey(),
            'sertifikat' => (string) $sertifikatB->getKey(),
            'paket' => (string) $paketB->getKey(),
            'notifikasi' => (string) $notifikasiB->getKey(),
        ];
    }

    /** @return list<RuteTerdaftar> */
    private function ruteBerParameter(): array
    {
        $hasil = [];

        foreach (Router::getRoutes() as $rute) {
            if (! str_starts_with($rute->uri(), self::PREFIX) || ! str_contains($rute->uri(), '{')) {
                continue;
            }

            $hasil[] = $rute;
        }

        return $hasil;
    }

    /**
     * INTI — tiap rute ber-ID dijawab 404 buat ID milik perusahaan lain.
     *
     * Pelanggarnya dikumpulkan, bukan gagal di rute pertama: kalau tiga rute
     * bocor, yang berguna melihat ketiganya sekaligus.
     */
    public function test_NFR_03_rute_ber_id_menolak_id_perusahaan_lain(): void
    {
        $pelanggar = [];
        $diuji = 0;

        foreach ($this->ruteBerParameter() as $rute) {
            $uri = $rute->uri();
            $method = collect($rute->methods())->first(fn (string $m) => $m !== 'HEAD') ?? 'GET';

            foreach ($rute->parameterNames() as $nama) {
                $uri = str_replace('{'.$nama.'}', $this->milikB[$nama] ?? '', $uri);
            }

            $this->permintaanBaru();

            $status = $this->withHeaders($this->bearer($this->picA))
                ->json($method, '/'.$uri)
                ->status();

            $diuji++;

            if ($status !== 404) {
                $pelanggar[] = "{$method} {$rute->uri()} → {$status}";
            }
        }

        $this->assertGreaterThan(
            0,
            $diuji,
            'Nol rute pelanggan ber-parameter yang keuji — daftar rutenya kemungkinan nggak kebaca, '
            .'dan test ini jadi hijau tanpa memeriksa apa pun.'
        );

        $this->assertSame([], $pelanggar, sprintf(
            "%d dari %d rute pelanggan ber-ID nggak menjawab 404 buat ID milik perusahaan lain.\n".
            "403 pun nggak cukup: dia sudah memberi tahu bahwa baris dengan ID itu ADA (BR-02).\n\n  %s",
            count($pelanggar),
            $diuji,
            implode("\n  ", $pelanggar),
        ));
    }

    /**
     * PENJAGA yang bikin test di atas tidak pernah jadi hijau palsu.
     *
     * Parameter tanpa fixture disulih jadi string kosong, dan URL yang jadi
     * cacat membalas 404 karena RUTENYA tidak ketemu — bukan karena isolasinya
     * bekerja. Test di atas tidak bisa membedakan keduanya, jadi yang
     * membedakan test ini.
     */
    public function test_tiap_parameter_rute_punya_fixture(): void
    {
        $tanpaFixture = [];

        foreach ($this->ruteBerParameter() as $rute) {
            foreach ($rute->parameterNames() as $nama) {
                if (! array_key_exists($nama, $this->milikB)) {
                    $tanpaFixture[] = "{$nama} (dipakai {$rute->uri()})";
                }
            }
        }

        $this->assertSame([], array_unique($tanpaFixture), sprintf(
            "Parameter rute pelanggan ini belum punya fixture milik perusahaan B di setUp():\n  - %s\n\n".
            "Rute baru ber-ID WAJIB dikasih fixture, kalau nggak URL-nya jadi cacat, membalas 404 karena\n".
            'RUTENYA nggak ketemu, dan sapuan isolasi di atas lolos tanpa pernah menguji apa pun.',
            implode("\n  - ", array_unique($tanpaFixture)),
        ));
    }

    /**
     * Fixture-nya beneran hidup — bukan ID yang kebetulan tidak ada.
     *
     * Tanpa ini, fixture yang salah bikin seluruh sapuan di atas membalas 404
     * karena barisnya memang tidak ada, dan hasilnya hijau yang tidak berarti
     * apa-apa.
     */
    public function test_fixture_perusahaan_b_beneran_ada(): void
    {
        $this->assertDatabaseHas('customer_members', [
            'id' => $this->milikB['anggota'],
            'customer_id' => $this->perusahaanB->getKey(),
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $this->assertDatabaseHas('undangan_pelanggan', [
            'id' => $this->milikB['undangan'],
            'customer_id' => $this->perusahaanB->getKey(),
        ]);

        $this->assertDatabaseHas('equipments', [
            'id' => $this->milikB['alat'],
            'customer_id' => $this->perusahaanB->getKey(),
        ]);
        $this->assertDatabaseHas('certificates', [
            'id' => $this->milikB['sertifikat'],
            'status' => Certificate::STATUS_TERBIT,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $this->milikB['paket'],
            'customer_id' => $this->perusahaanB->getKey(),
        ]);
        $this->assertDatabaseHas('notifications', ['id' => $this->milikB['notifikasi']]);

        $this->assertNotSame($this->perusahaanA->getKey(), $this->perusahaanB->getKey());
    }

    /**
     * Sisi positifnya: ID milik perusahaan SENDIRI memang tidak 404.
     *
     * Tanpa ini, sapuan di atas sama-sama hijau seandainya seluruh rutenya
     * rusak dan selalu membalas 404 buat siapa pun.
     */
    public function test_id_milik_sendiri_tidak_ikut_ditolak(): void
    {
        $undanganSendiri = UndanganPelanggan::factory()->create([
            'organization_id' => $this->perusahaanA->organization_id,
            'customer_id' => $this->perusahaanA->getKey(),
        ]);

        $this->withHeaders($this->bearer($this->picA))
            ->deleteJson("/api/pelanggan/v1/anggota/undangan/{$undanganSendiri->getKey()}")
            ->assertOk();
    }

    /**
     * `GET /anggota` untuk STAF perusahaan A: tidak ada apa pun milik B (anggota,
     * undangan, telepon, email), dan `undangan_menunggu` kosong walau A sendiri
     * punya undangan menunggu. Untuk PIC utama A: undangan A terlihat, undangan
     * B tidak — penyempitan sisi server (12 Nov 2026) tidak boleh menggeser
     * batas antar-perusahaan ke arah mana pun.
     */
    public function test_daftar_anggota_staf_dan_pic_tidak_memuat_apa_pun_milik_perusahaan_lain(): void
    {
        $staf = $this->pelanggan(tambahan: ['telepon' => '+6282200000002']);
        CustomerMember::create([
            'organization_id' => $this->perusahaanA->organization_id,
            'customer_id' => $this->perusahaanA->getKey(),
            'user_id' => $staf->getKey(),
            'peran' => CustomerMember::PERAN_STAF,
            'status' => CustomerMember::STATUS_AKTIF,
        ]);

        $anggotaB = CustomerMember::query()->findOrFail((int) $this->milikB['anggota'])->load('user');
        $anggotaB->user->forceFill(['telepon' => '+6289900000009'])->save();
        $emailUndanganB = (string) UndanganPelanggan::query()->findOrFail((int) $this->milikB['undangan'])->email;

        $emailUndanganA = 'undangan-milik-a@contoh.test';
        UndanganPelanggan::factory()->create([
            'organization_id' => $this->perusahaanA->organization_id,
            'customer_id' => $this->perusahaanA->getKey(),
            'email' => $emailUndanganA,
        ]);

        $this->permintaanBaru();
        $balasanStaf = $this->withHeaders($this->bearer($staf))->getJson('/api/pelanggan/v1/anggota')->assertOk();

        $this->assertSame([], $balasanStaf->json('data.undangan_menunggu'));
        $this->assertCount(2, $balasanStaf->json('data.anggota'));

        $this->permintaanBaru();
        $balasanPic = $this->withHeaders($this->bearer($this->picA))->getJson('/api/pelanggan/v1/anggota')->assertOk();

        $this->assertSame([$emailUndanganA], collect($balasanPic->json('data.undangan_menunggu'))->pluck('email')->all());

        foreach ([$balasanStaf, $balasanPic] as $balasan) {
            $isi = (string) $balasan->getContent();
            foreach ([$anggotaB->user->email, '+6289900000009', $emailUndanganB] as $milikB) {
                $this->assertStringNotContainsString((string) $milikB, $isi, 'Data perusahaan B bocor ke /anggota perusahaan A.');
            }
        }
    }

    /**
     * K-50-05: pencarian nomor sertifikat (`GET /sertifikat?q=`) hanya melihat
     * PT sendiri — nomor PT lain dijawab daftar kosong, sama dengan "tidak ada".
     */
    public function test_cari_nomor_sertifikat_tidak_menembus_perusahaan_lain(): void
    {
        $buat = function (Customer $pt, string $nomor): Certificate {
            $alat = Equipment::factory()->create([
                'organization_id' => $pt->organization_id,
                'customer_id' => $pt->getKey(),
            ]);

            return Certificate::factory()->create([
                'organization_id' => $pt->organization_id,
                'nomor' => $nomor,
                'status' => Certificate::STATUS_TERBIT,
                'calibration_session_id' => CalibrationSession::factory()->create([
                    'organization_id' => $pt->organization_id,
                    'equipment_id' => $alat->getKey(),
                ])->getKey(),
            ]);
        };

        $buat($this->perusahaanA, 'ISO/A/0001');
        $buat($this->perusahaanB, 'ISO/B/0002');

        $this->withHeaders($this->bearer($this->picA))
            ->getJson('/api/pelanggan/v1/sertifikat?q=ISO/B/0002')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->withHeaders($this->bearer($this->picA))
            ->getJson('/api/pelanggan/v1/sertifikat?q=ISO/A/0001')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.nomor', 'ISO/A/0001');
    }

    /**
     * Pencarian dari NOMOR ORDER (`GET /sertifikat?q=` dan `GET /paket?q=`)
     * hanya melihat PT sendiri -- nomor order PT lain dijawab daftar kosong.
     * Rincian di `CariDariNomorOrderTest`.
     */
    public function test_cari_nomor_order_tidak_menembus_perusahaan_lain(): void
    {
        $alatB = Equipment::factory()->create([
            'organization_id' => $this->perusahaanB->organization_id,
            'customer_id' => $this->perusahaanB->getKey(),
        ]);
        Certificate::factory()->create([
            'organization_id' => $this->perusahaanB->organization_id,
            'nomor' => 'ISO/B/0009',
            'status' => Certificate::STATUS_TERBIT,
            'calibration_session_id' => CalibrationSession::factory()->create([
                'organization_id' => $this->perusahaanB->organization_id,
                'equipment_id' => $alatB->getKey(),
                'nomor_order' => 'PO-MILIK-B-4711',
            ])->getKey(),
        ]);
        Order::factory()->create([
            'organization_id' => $this->perusahaanB->organization_id,
            'customer_id' => $this->perusahaanB->getKey(),
            'nomor' => 'ORD/B/4712',
        ]);

        foreach (['/sertifikat?q=PO-MILIK-B-4711', '/paket?q=ORD/B/4712'] as $uri) {
            $this->permintaanBaru();
            $this->withHeaders($this->bearer($this->picA))
                ->getJson('/api/pelanggan/v1'.$uri)
                ->assertOk()
                ->assertJsonPath('meta.total', 0);
        }
    }

    /**
     * `GET /sertifikat/garis-waktu` (rute tanpa ID, jadi tidak tersapu sapuan
     * rute ber-parameter di atas -- kasusnya ditulis di sini): sertifikat B
     * (dibuat di setUp) tidak pernah terhitung, terlihat, atau tertemu lewat
     * nomor/serial/order-nya di garis waktu A, dan `customer_id` dari query
     * string tidak dipercaya.
     */
    public function test_garis_waktu_sertifikat_tidak_menembus_perusahaan_lain(): void
    {
        $sertifikatB = Certificate::query()->findOrFail((int) $this->milikB['sertifikat']);
        $sertifikatB->update(['nomor' => 'ISO/B/GARIS-1', 'diterbitkan_pada' => '2026-11-12']);
        $sertifikatB->session->update(['nomor_order' => 'PO-GARIS-B-1']);
        $serialB = (string) $sertifikatB->session->equipment->serial_number;

        $garisWaktu = function (string $query = ''): array {
            $this->permintaanBaru();

            return $this->withHeaders($this->bearer($this->picA))
                ->getJson('/api/pelanggan/v1/sertifikat/garis-waktu'.$query)
                ->assertOk()
                ->json('data');
        };

        // Kosong untuk A, di semua tingkat, walau B punya satu sertifikat terbit.
        $this->assertSame(0, $garisWaktu()['total_dokumen']);
        $this->assertSame([], $garisWaktu()['kelompok']);
        $this->assertSame(0, $garisWaktu("?customer_id={$this->perusahaanB->getKey()}")['total_dokumen']);
        $hari = $garisWaktu('?tingkat=hari&tahun=2026&bulan=11&pekan=46');
        $this->assertSame([], $hari['item']);

        // Tidak tertemu lewat pencarian terpadu: nomor, serial, order, dan nama PT B.
        foreach (['ISO/B/GARIS-1', 'iso-b-garis-1', $serialB, 'PO-GARIS-B-1', $this->perusahaanB->nama] as $q) {
            $this->assertSame(0, $garisWaktu('?q='.urlencode($q))['total_dokumen'], "q={$q}");
        }

        // Sisi positif: milik A sendiri terhitung (supaya "kosong" di atas bukan karena rute rusak).
        $alatA = Equipment::factory()->create([
            'organization_id' => $this->perusahaanA->organization_id,
            'customer_id' => $this->perusahaanA->getKey(),
        ]);
        Certificate::factory()->create([
            'organization_id' => $this->perusahaanA->organization_id,
            'nomor' => 'ISO/A/GARIS-1',
            'status' => Certificate::STATUS_TERBIT,
            'diterbitkan_pada' => '2026-11-12',
            'calibration_session_id' => CalibrationSession::factory()->create([
                'organization_id' => $this->perusahaanA->organization_id,
                'equipment_id' => $alatA->getKey(),
            ])->getKey(),
        ]);

        $this->assertSame(1, $garisWaktu()['total_dokumen']);
        $this->assertSame(1, $garisWaktu('?q=iso-a-garis-1')['total_dokumen']);
    }
}

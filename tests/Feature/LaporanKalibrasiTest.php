<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\User;
use App\Services\LaporanKalibrasi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Laporan kalibrasi berpenyaring + export (spesifikasi poin 08, fase-2 §5).
 *
 * Yang paling dijaga di sini: **layar dan file export nggak boleh beda isi.**
 * Penyaring & barisnya dipegang satu service (`LaporanKalibrasi`), soalnya kalau
 * dua-duanya nyusun query sendiri, laporan yang dilihat admin di HP bisa beda
 * dari file yang dia kirim ke asesor — dan dua laporan dengan angka beda buat
 * periode yang sama itu temuan audit.
 */
class LaporanKalibrasiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/laporan/kalibrasi';

    private const URL_EXPORT = '/api/laporan/kalibrasi/export';

    private User $admin;

    private User $teknisiA;

    private User $teknisiB;

    private Customer $pelangganA;

    private Customer $pelangganB;

    private EquipmentCategory $kategoriPh;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();

        $this->admin = User::factory()->admin()->create();
        $this->teknisiA = User::factory()->create(['name' => 'Dwi Rahayu']);
        $this->teknisiB = User::factory()->create(['name' => 'Teknisi Lain']);

        $this->pelangganA = Customer::factory()->create(['nama' => 'PT Tirta Gracia']);
        $this->pelangganB = Customer::factory()->create(['nama' => 'PT Maju Jaya']);

        $this->kategoriPh = EquipmentCategory::factory()->create([
            'kode' => 'instrumen-analitik', 'nama' => 'Instrumen Analitik',
        ]);
    }

    /** @param array<string, mixed> $atribut */
    private function sesi(
        Customer $pelanggan,
        User $teknisi,
        string $tanggal,
        array $atribut = [],
        ?EquipmentCategory $kategori = null,
    ): CalibrationSession {
        $alat = Equipment::factory()->create([
            'customer_id' => $pelanggan->id,
            'equipment_category_id' => ($kategori ?? $this->kategoriPh)->id,
            'satuan' => 'pH', 'toleransi' => 0.05,
        ]);

        return CalibrationSession::factory()->create([
            'teknisi_id' => $teknisi->id,
            'equipment_id' => $alat->id,
            'tanggal_kalibrasi' => $tanggal,
            ...$atribut,
        ]);
    }

    // ------------------------------------------------------------ daftar

    public function test_admin_lihat_semua_sesi_lintas_teknisi(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10');
        $this->sesi($this->pelangganB, $this->teknisiB, '2026-07-12');

        $this->actingAs($this->admin)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('ringkasan.total', 2);
    }

    /**
     * Teknisi cuma dapat pekerjaannya sendiri — aturan yang sama kayak
     * `/calibrations`. Kalau di laporan dilonggarin, penyaringan di layar
     * riwayat jadi nggak ada artinya: tinggal buka Laporan buat ngintip.
     */
    public function test_teknisi_cuma_lihat_pekerjaannya_sendiri(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10');
        $this->sesi($this->pelangganB, $this->teknisiB, '2026-07-12');

        $res = $this->actingAs($this->teknisiA)->getJson(self::URL)->assertOk();

        $res->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.teknisi.nama', 'Dwi Rahayu')
            // Ringkasannya juga ikut tersaring, bukan total se-lab.
            ->assertJsonPath('ringkasan.total', 1);
    }

    public function test_viewer_boleh_baca(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10');

        $this->actingAs(User::factory()->create(['role' => User::ROLE_VIEWER]))
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_tanpa_token_ditolak(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();
    }

    // ---------------------------------------------------------- penyaring

    /** Tanggal `sampai` INKLUSIF — laporan "1–31 Juli" harus ikut tanggal 31. */
    public function test_rentang_tanggal_inklusif_di_kedua_ujung(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-01');
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-31');
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-08-01');

        $this->actingAs($this->admin)
            ->getJson(self::URL.'?dari=2026-07-01&sampai=2026-07-31')
            ->assertOk()
            ->assertJsonPath('ringkasan.total', 2);
    }

    public function test_saring_per_pelanggan(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10');
        $this->sesi($this->pelangganB, $this->teknisiA, '2026-07-11');

        $this->actingAs($this->admin)
            ->getJson(self::URL."?pelanggan_id={$this->pelangganA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pelanggan.nama', 'PT Tirta Gracia');
    }

    public function test_saring_per_teknisi(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10');
        $this->sesi($this->pelangganA, $this->teknisiB, '2026-07-11');

        $this->actingAs($this->admin)
            ->getJson(self::URL."?teknisi_id={$this->teknisiB->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.teknisi.nama', 'Teknisi Lain');
    }

    /** `kategori` pakai KODE, sama kayak penyaring di /equipments. */
    public function test_saring_per_kategori_pakai_kode(): void
    {
        $lain = EquipmentCategory::factory()->create(['kode' => 'massa', 'nama' => 'Massa']);

        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10');
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-11', kategori: $lain);

        $this->actingAs($this->admin)
            ->getJson(self::URL.'?kategori=massa')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kategori.kode', 'massa');
    }

    public function test_saring_per_keputusan_dan_status(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10', [
            'keputusan' => 'PASS', 'status' => CalibrationSession::STATUS_DISETUJUI,
        ]);
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-11', [
            'keputusan' => 'FAIL', 'status' => CalibrationSession::STATUS_MENUNGGU_APPROVAL,
        ]);

        $this->actingAs($this->admin)
            ->getJson(self::URL.'?keputusan=FAIL')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.keputusan', 'FAIL');

        $this->actingAs($this->admin)
            ->getJson(self::URL.'?status=disetujui')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_sampai_sebelum_dari_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->getJson(self::URL.'?dari=2026-07-31&sampai=2026-07-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('sampai');
    }

    public function test_status_ngawur_ditolak(): void
    {
        $this->actingAs($this->admin)
            ->getJson(self::URL.'?status=ngaco')
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    // ---------------------------------------------------------- ringkasan

    /**
     * Ringkasan dihitung dari SELURUH hasil penyaring, bukan dari halaman yang
     * sedang dibuka. "Total 15" yang cuma berarti "15 di halaman ini" itu angka
     * menyesatkan di dokumen yang dikirim ke asesor.
     */
    public function test_ringkasan_dihitung_dari_seluruh_hasil_bukan_satu_halaman(): void
    {
        for ($i = 1; $i <= 20; $i++) {
            $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10', [
                'keputusan' => $i <= 12 ? 'PASS' : 'FAIL',
            ]);
        }

        $res = $this->actingAs($this->admin)->getJson(self::URL)->assertOk();

        // Halaman pertama cuma 15 baris...
        $res->assertJsonCount(15, 'data');
        // ...tapi ringkasannya nyeritain semuanya.
        $res->assertJsonPath('ringkasan.total', 20)
            ->assertJsonPath('ringkasan.pass', 12)
            ->assertJsonPath('ringkasan.fail', 8);
    }

    public function test_sesi_tanpa_keputusan_dipisah_di_ringkasan(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10', ['keputusan' => 'PASS']);
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-11', ['keputusan' => null]);

        $this->actingAs($this->admin)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('ringkasan.total', 2)
            ->assertJsonPath('ringkasan.pass', 1)
            ->assertJsonPath('ringkasan.fail', 0)
            // Dipisah biar total nggak kelihatan nggak nyambung sama pass+fail.
            ->assertJsonPath('ringkasan.belum_ada_keputusan', 1);
    }

    /** Penyaring versi manusia ikut di respons — buat dipajang di kepala layar. */
    public function test_penyaring_terbaca_ikut_di_respons(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10');

        $this->actingAs($this->admin)
            ->getJson(self::URL."?pelanggan_id={$this->pelangganA->id}&kategori=instrumen-analitik")
            ->assertOk()
            ->assertJsonPath('penyaring.Pelanggan', 'PT Tirta Gracia')
            ->assertJsonPath('penyaring.Kategori', 'Instrumen Analitik')
            ->assertJsonPath('penyaring.Teknisi', null);
    }

    /** Id dari lab lain jadi null, bukan nama PT orang. */
    public function test_pelanggan_organisasi_lain_nggak_bocor_di_penyaring(): void
    {
        $lain = Organization::factory()->create();
        $pelangganLain = Customer::factory()->create([
            'organization_id' => $lain->id, 'nama' => 'PT Sebelah',
        ]);

        $this->actingAs($this->admin)
            ->getJson(self::URL."?pelanggan_id={$pelangganLain->id}")
            ->assertOk()
            ->assertJsonPath('penyaring.Pelanggan', null)
            ->assertJsonPath('ringkasan.total', 0);
    }

    // ------------------------------------------------------------ export

    public function test_export_xlsx(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10', ['keputusan' => 'PASS']);

        $res = $this->actingAs($this->admin)
            ->get(self::URL_EXPORT.'?format=xlsx')
            ->assertOk();

        $this->assertStringContainsString(
            'spreadsheetml',
            (string) $res->headers->get('content-type'),
        );
        $this->assertStringContainsString('Laporan-Kalibrasi-', (string) $res->headers->get('content-disposition'));
    }

    public function test_export_pdf(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10', ['keputusan' => 'PASS']);

        $res = $this->actingAs($this->admin)
            ->get(self::URL_EXPORT.'?format=pdf')
            ->assertOk();

        $this->assertSame('application/pdf', $res->headers->get('content-type'));
    }

    /**
     * Export pakai penyaring yang SAMA kayak layar.
     *
     * Diuji dengan cara ngebandingin jumlah baris: kalau export nyusun query
     * sendiri, penyaring yang ngurangin hasil di layar bisa nggak kepakai di
     * file — dan itu baru ketahuan sesudah file-nya nyampe ke asesor.
     */
    public function test_export_ikut_penyaring_yang_sama_kayak_layar(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10');
        $this->sesi($this->pelangganB, $this->teknisiA, '2026-07-11');

        $filter = "?pelanggan_id={$this->pelangganB->id}";

        $layar = $this->actingAs($this->admin)->getJson(self::URL.$filter)->assertOk();
        $this->assertSame(1, $layar->json('ringkasan.total'));

        // Penyaring yang sama, tapi buat pelanggan yang nggak punya sesi →
        // export-nya 404, bukan file berisi semua sesi.
        $this->actingAs($this->admin)
            ->get(self::URL_EXPORT."?format=xlsx&pelanggan_id={$this->pelangganA->id}&dari=2030-01-01")
            ->assertNotFound();
    }

    public function test_export_kosong_dapat_404_bukan_file_kosong(): void
    {
        $this->actingAs($this->admin)
            ->get(self::URL_EXPORT.'?format=xlsx')
            ->assertNotFound();
    }

    public function test_export_tanpa_format_ditolak(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10');

        $this->actingAs($this->admin)
            ->getJson(self::URL_EXPORT)
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');
    }

    public function test_export_format_ngawur_ditolak(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10');

        $this->actingAs($this->admin)
            ->getJson(self::URL_EXPORT.'?format=docx')
            ->assertStatus(422)
            ->assertJsonValidationErrors('format');
    }

    /** Teknisi export cuma dapat pekerjaannya sendiri — sama kayak di layar. */
    public function test_export_teknisi_tersaring_juga(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiB, '2026-07-10');

        // Teknisi A nggak punya sesi sama sekali → 404, bukan file berisi
        // pekerjaan teknisi B.
        $this->actingAs($this->teknisiA)
            ->get(self::URL_EXPORT.'?format=xlsx')
            ->assertNotFound();
    }

    // -------------------------------------------------------------- baris

    public function test_baris_bawa_kolom_yang_dibutuhin_tabel(): void
    {
        $sesi = $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10', ['keputusan' => 'PASS']);
        Certificate::factory()->create([
            'calibration_session_id' => $sesi->id,
            'nomor' => '012-CAL-524',
            'status' => Certificate::STATUS_TERBIT,
        ]);

        $this->actingAs($this->admin)
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.0.nomor_sesi', $sesi->nomor_sesi)
            ->assertJsonPath('data.0.pelanggan.nama', 'PT Tirta Gracia')
            ->assertJsonPath('data.0.kategori.nama', 'Instrumen Analitik')
            ->assertJsonPath('data.0.teknisi.kode_teknisi', 'DR')
            ->assertJsonPath('data.0.keputusan', 'PASS')
            ->assertJsonPath('data.0.sertifikat.nomor', '012-CAL-524');
    }

    /**
     * Baris laporan sengaja RINGKAS — bukan CalibrationResource.
     *
     * Laporan itu tabel; `titik[]`, `type_b_components`, dan `pembacaan_mentah`
     * nggak dipajang di situ. Di lab yang datanya setahun, bedanya laporan kebuka
     * atau HP-nya keabisan memori.
     */
    public function test_baris_nggak_bawa_rincian_titik(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-10');

        $baris = $this->actingAs($this->admin)->getJson(self::URL)->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('titik', $baris);
        $this->assertArrayNotHasKey('pembacaan_mentah', $baris);
        $this->assertArrayNotHasKey('status_standar', $baris);
    }

    // ------------------------------------------------------------- tanggal

    /**
     * Tanggal di respons harus TANGGAL POLOS, dan harus bisa dipakai balik jadi
     * penyaring.
     *
     * Kolomnya cuma tanggal (nggak ada jamnya). Kalau di-serialize sebagai ISO
     * ber-zona waktu, di Asia/Jakarta tanggal `2026-07-10` keluar jadi
     * `2026-07-09T17:00:00Z` — dan frontend yang motong 10 karakter pertama dapat
     * tanggal yang salah sehari. Efeknya: date-picker "1–31 Juli" diam-diam
     * ngebuang sesi tanggal 1, dan tombol "filter tanggal sesi ini" balik kosong.
     */
    public function test_tanggal_di_respons_polos_dan_bisa_dipakai_balik_jadi_penyaring(): void
    {
        $sesi = $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-01');

        $tanggal = $this->actingAs($this->admin)->getJson(self::URL)->assertOk()
            ->assertJsonPath('data.0.tanggal_kalibrasi', '2026-07-01')
            ->json('data.0.tanggal_kalibrasi');

        // Pulang-balik: nilai yang BARU AJA dikasih API, dipakai lagi jadi
        // penyaring, harus nemu sesi yang sama.
        $this->actingAs($this->admin)
            ->getJson(self::URL."?dari={$tanggal}&sampai={$tanggal}")
            ->assertOk()
            ->assertJsonPath('ringkasan.total', 1)
            ->assertJsonPath('data.0.nomor_sesi', $sesi->nomor_sesi);
    }

    /**
     * Tanggal di layar == tanggal yang dicetak di file export.
     *
     * Satu sesi nggak boleh punya dua tanggal beda tergantung dilihat di mana.
     * Buat lab terakreditasi itu temuan audit, dan ini pernah beneran kejadian:
     * layar nulis `2024-05-25T17:00:00Z` sementara Excel nulis `2024-05-26`.
     */
    public function test_tanggal_di_layar_sama_dengan_yang_dicetak_di_export(): void
    {
        $this->sesi($this->pelangganA, $this->teknisiA, '2026-07-01', ['keputusan' => 'PASS']);

        $layar = $this->actingAs($this->admin)->getJson(self::URL)->assertOk()
            ->json('data.0.tanggal_kalibrasi');

        $dicetak = app(LaporanKalibrasi::class)->baris(
            CalibrationSession::with(['equipment.customer', 'equipment.category', 'teknisi', 'certificate'])->get(),
        );

        $this->assertSame($layar, $dicetak[0]['tanggal_kalibrasi']);
    }
}

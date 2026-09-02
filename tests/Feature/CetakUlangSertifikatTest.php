<?php

namespace Tests\Feature;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Organization;
use App\Models\Standard;
use App\Models\User;
use App\Services\BerkasPdfSertifikat;
use App\Services\CetakUlangSertifikat;
use App\Services\DataTampilanSertifikat;
use App\Services\SertifikatSatuHalaman;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Cetak ulang PDF sertifikat yang sudah terbit, pakai tanda tangan terbaru.
 *
 * ## Kenapa fitur ini ada
 *
 * Gambar tanda tangan dibaca live tiap render, tapi PDF-nya DISIMPAN dan yang
 * tersimpan dilayani apa adanya selamanya. Jadi admin yang menukar hasil pindai
 * bertanda air dengan yang bersih melihat sertifikat lamanya tidak berubah sama
 * sekali — tanpa satu pun pesan yang menjelaskan kenapa.
 *
 * ## Yang paling dijaga di berkas ini
 *
 * Nama penandatangan DIBEKU ke snapshot; gambar tanda tangannya tidak. Begitu
 * penandatangannya ganti orang, mencetak ulang sertifikat lama menempelkan
 * tanda tangan orang baru di atas nama orang lama — dokumen yang menyatakan
 * seseorang menandatangani sesuatu yang tidak pernah dia tandatangani.
 *
 * Kegagalan itu tidak menghasilkan error apa pun: dari sisi kode semuanya
 * berhasil. Cuma test yang bisa menahannya.
 */
class CetakUlangSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $teknisi;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        $this->org = Organization::factory()->create();
        $this->admin = User::factory()->admin()->create();
        $this->teknisi = User::factory()->create();
    }

    private function layanan(): CetakUlangSertifikat
    {
        return app(CetakUlangSertifikat::class);
    }

    private function setelPenandatangan(string $nama): void
    {
        $org = $this->org->fresh();
        $org->update([
            'settings' => [...($org->settings ?? []), Organization::KEY_PENANDATANGAN_NAMA => $nama],
        ]);
    }

    private function unggahTandaTangan(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/organization/tanda-tangan', [
                'tanda_tangan' => UploadedFile::fake()->image('ttd.png', 300, 120),
            ])
            ->assertOk();
    }

    private function sertifikatTerbit(): Certificate
    {
        $alat = Equipment::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
            // `firstOrCreate`, bukan `create`: kodenya unik per organisasi, dan
            // test yang menerbitkan DUA sertifikat kena benturan constraint —
            // yang merah jadi fixture-nya, bukan yang lagi diuji.
            'equipment_category_id' => EquipmentCategory::query()->firstOrCreate(
                ['organization_id' => $this->org->id, 'kode' => 'panjang'],
                ['nama' => 'Panjang'],
            )->id,
            'satuan' => 'mm', 'resolusi' => 0.01, 'toleransi' => 0.05,
        ]);

        $this->actingAs($this->teknisi)->postJson('/api/calibrations', [
            'equipment_id' => $alat->id,
            'standard_id' => Standard::factory()->create()->id,
            'tanggal_kalibrasi' => '2026-07-20',
            'measurements' => [['titik_ukur' => 50.0, 'satuan' => 'mm', 'pembacaan' => [50.02, 50.01, 50.03]]],
        ])->assertCreated();

        $sesi = CalibrationSession::latest('id')->firstOrFail();

        // `reviewed_by` ikut diisi, bukan cuma statusnya. Tanpa itu
        // `CertificateSnapshotBuilder` membekukan nama penandatangan sebagai
        // NULL — dan penjaga kesinambungan penandatangan lolos begitu saja
        // lewat cabang "nggak ada nama beku", bukan lewat perbandingan
        // sungguhan. Versi pertama berkas ini kena persis itu: hijau tanpa
        // pernah menguji yang dimaksud.
        $sesi->update([
            'status' => CalibrationSession::STATUS_DISETUJUI,
            'reviewed_by' => $this->admin->id,
        ]);

        (new GenerateCertificate($sesi->id, $this->admin->id))->handle();

        return $sesi->fresh()->certificate()->firstOrFail();
    }

    // ------------------------------------------------------------ jalur utama

    /**
     * Inti fiturnya: berkas PDF-nya BENERAN ditulis ulang.
     *
     * Diuji lewat isi berkasnya, bukan lewat nilai balik. `pastikanAda()` juga
     * mengembalikan path yang sama untuk berkas yang tidak disentuh sama
     * sekali, jadi membandingkan path tidak membuktikan apa pun.
     */
    public function test_berkas_pdf_beneran_ditulis_ulang(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $path = $sertifikat->pdf_path;

        Storage::disk('arsip')->assertExists($path);

        // Ditandai supaya kelihatan kalau isinya diganti. Ukurannya dibikin
        // lewat MINIMUM_BYTE biar nggak dianggap rusak lalu dibangun ulang
        // karena alasan yang salah.
        $palsu = '%PDF-1.4'.str_repeat('x', 5000);
        Storage::disk('arsip')->put($path, $palsu);

        $hasil = $this->layanan()->jalankan([$sertifikat]);

        $this->assertSame([$sertifikat->nomor], $hasil['berhasil']);
        $this->assertSame([], $hasil['ditolak']);
        $this->assertNotSame(
            $palsu,
            Storage::disk('arsip')->get($path),
            'Berkasnya nggak disentuh — cetak ulangnya nggak ada efeknya.',
        );
    }

    /** Yang dicetak ulang tetap PDF yang utuh, bukan keluaran setengah jadi. */
    public function test_hasilnya_tetap_pdf_yang_utuh(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $this->layanan()->jalankan([$sertifikat]);

        $isi = Storage::disk('arsip')->get($sertifikat->pdf_path);

        $this->assertStringStartsWith('%PDF', $isi);
        $this->assertGreaterThan(1000, strlen($isi));
    }

    /**
     * Snapshot TIDAK boleh ikut berubah.
     *
     * Ini yang memisahkan "mencetak ulang" dari "menerbitkan ulang". Sertifikat
     * ini dokumen terkendali: nomor, tanggal, dan seluruh angkanya beku waktu
     * terbit, dan cetak ulang cuma merender lembar dari data beku yang sama.
     */
    public function test_snapshot_nggak_ikut_berubah(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $sebelum = $sertifikat->snapshot;

        $this->layanan()->jalankan([$sertifikat]);

        $this->assertSame($sebelum, $sertifikat->fresh()->snapshot);
    }

    /**
     * Tanda tangan yang baru diunggah BENERAN masuk ke lembar yang dicetak ulang.
     *
     * Ini alasan fiturnya ada. Test lain memastikan berkasnya ditulis ulang;
     * yang ini memastikan yang ditulis ulang itu memang memuat gambar barunya.
     */
    public function test_tanda_tangan_baru_masuk_ke_lembar(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $tanpaTtd = strlen(Storage::disk('arsip')->get($sertifikat->pdf_path));

        $this->unggahTandaTangan();
        $this->layanan()->jalankan([$sertifikat->fresh()]);

        $denganTtd = strlen(Storage::disk('arsip')->get($sertifikat->pdf_path));

        $this->assertGreaterThan(
            $tanpaTtd,
            $denganTtd,
            'Lembarnya nggak membesar sesudah TTD diunggah — gambarnya nggak ikut kecetak.',
        );
    }

    // ---------------------------------------------------------------- penjaga

    /**
     * PENJAGA UTAMA: penandatangan yang sudah ganti orang DITOLAK.
     *
     * Nama penandatangan beku di snapshot, gambar tanda tangannya tidak. Tanpa
     * penjaga ini, mencetak ulang sertifikat lama sesudah pergantian
     * penandatangan menempelkan tanda tangan orang baru di atas nama orang
     * lama — dan tidak ada satu pun error yang muncul.
     */
    public function test_penandatangan_yang_sudah_ganti_orang_ditolak(): void
    {
        $this->setelPenandatangan('Alex Misramto');
        $sertifikat = $this->sertifikatTerbit();

        $this->assertSame('Alex Misramto', $sertifikat->snapshot['footer']['penandatangan']);

        // Penandatangannya berganti orang sesudah sertifikatnya terbit.
        $this->setelPenandatangan('Budi Santoso');

        $hasil = $this->layanan()->jalankan([$sertifikat->fresh()]);

        $this->assertSame([], $hasil['berhasil']);
        $this->assertCount(1, $hasil['ditolak']);

        // Alasannya wajib menyebut KEDUA nama — admin harus bisa menilai
        // sendiri apakah ini memang pergantian orang atau cuma salah ketik.
        $alasan = $hasil['ditolak'][0]['alasan'];
        $this->assertStringContainsString('Alex Misramto', $alasan);
        $this->assertStringContainsString('Budi Santoso', $alasan);
    }

    /** Orang yang SAMA dengan gambar yang diganti — kasus paling sering — tetap jalan. */
    public function test_orang_yang_sama_dengan_gambar_baru_tetap_boleh(): void
    {
        $this->setelPenandatangan('Alex Misramto');
        $sertifikat = $this->sertifikatTerbit();

        $this->unggahTandaTangan();

        $hasil = $this->layanan()->jalankan([$sertifikat->fresh()]);

        $this->assertSame([$sertifikat->nomor], $hasil['berhasil']);
        $this->assertSame([], $hasil['ditolak']);
    }

    /** Beda huruf besar-kecil itu orang yang sama, bukan pergantian. */
    public function test_beda_besar_kecil_huruf_bukan_pergantian_orang(): void
    {
        $this->setelPenandatangan('Alex Misramto');
        $sertifikat = $this->sertifikatTerbit();

        $this->setelPenandatangan('ALEX MISRAMTO');

        $hasil = $this->layanan()->jalankan([$sertifikat->fresh()]);

        $this->assertSame([], $hasil['ditolak']);
    }

    /** Sertifikat yang belum terbit nggak punya lembar buat dicetak ulang. */
    public function test_yang_belum_terbit_ditolak(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $sertifikat->update(['status' => Certificate::STATUS_GAGAL]);

        $hasil = $this->layanan()->jalankan([$sertifikat->fresh()]);

        $this->assertSame([], $hasil['berhasil']);
        $this->assertStringContainsString('belum terbit', $hasil['ditolak'][0]['alasan']);
    }

    /** Tanpa snapshot nggak ada yang bisa dirender — dan itu harus kebaca. */
    public function test_yang_nggak_punya_snapshot_ditolak(): void
    {
        $sertifikat = $this->sertifikatTerbit();
        $sertifikat->update(['snapshot' => null]);

        $hasil = $this->layanan()->jalankan([$sertifikat->fresh()]);

        $this->assertSame([], $hasil['berhasil']);
        $this->assertStringContainsString('snapshot', $hasil['ditolak'][0]['alasan']);
    }

    /**
     * Satu yang ditolak TIDAK menghentikan sisanya.
     *
     * Admin yang memilih banyak baris lalu satu di antaranya bermasalah tetap
     * dapat sisanya, plus alasan buat yang satu itu. Menggagalkan semuanya cuma
     * bikin dia menebak yang mana.
     */
    public function test_yang_ditolak_nggak_ngerusak_yang_lain(): void
    {
        $baik = $this->sertifikatTerbit();
        $rusak = $this->sertifikatTerbit();
        $rusak->update(['snapshot' => null]);

        $hasil = $this->layanan()->jalankan([$baik->fresh(), $rusak->fresh()]);

        $this->assertSame([$baik->nomor], $hasil['berhasil']);
        $this->assertCount(1, $hasil['ditolak']);
        $this->assertSame($rusak->nomor, $hasil['ditolak'][0]['nomor']);
    }

    /**
     * Snapshot lama yang footernya belum punya nama dibiarkan lewat.
     *
     * Menolak berdasarkan data yang memang tidak ada cuma memblokir sertifikat
     * yang sebenarnya baik-baik saja.
     */
    public function test_snapshot_tanpa_nama_penandatangan_tetap_boleh(): void
    {
        $this->setelPenandatangan('Alex Misramto');
        $sertifikat = $this->sertifikatTerbit();

        $snapshot = $sertifikat->snapshot;
        unset($snapshot['footer']['penandatangan']);
        $sertifikat->update(['snapshot' => $snapshot]);

        $hasil = $this->layanan()->jalankan([$sertifikat->fresh()]);

        $this->assertSame([], $hasil['ditolak']);
    }

    /**
     * Satu render yang MELEDAK nggak boleh menghentikan batch — temuan review.
     *
     * Bedanya dari `test_yang_ditolak_nggak_ngerusak_yang_lain`: yang itu soal
     * penolakan yang memang direncanakan, yang ini soal exception. Tanpa
     * penanganan per iterasi, satu dompdf yang meledak bikin sisanya nggak
     * diproses sama sekali dan log rekapnya nggak pernah kejalan — admin cuma
     * lihat layar galat tanpa tahu mana yang keburu jadi.
     */
    public function test_render_yang_meledak_nggak_menghentikan_batch(): void
    {
        $meledak = $this->sertifikatTerbit();
        $baik = $this->sertifikatTerbit();

        $palsu = new class(app(DataTampilanSertifikat::class), app(SertifikatSatuHalaman::class)) extends BerkasPdfSertifikat
        {
            public array $diminta = [];

            public function cetakUlang(Certificate $sertifikat): ?string
            {
                $this->diminta[] = $sertifikat->getKey();

                // Yang pertama meledak, sisanya jalan normal.
                if (count($this->diminta) === 1) {
                    throw new \RuntimeException('dompdf meledak');
                }

                return parent::cetakUlang($sertifikat);
            }
        };

        $hasil = (new CetakUlangSertifikat($palsu))->jalankan([$meledak->fresh(), $baik->fresh()]);

        $this->assertSame(
            [$baik->nomor],
            $hasil['berhasil'],
            'Sertifikat sesudah yang meledak nggak ikut diproses — batch-nya berhenti di tengah.',
        );
        $this->assertCount(1, $hasil['ditolak']);
        $this->assertSame($meledak->nomor, $hasil['ditolak'][0]['nomor']);
    }

    /**
     * Nggak ada satu pun sumber nama penandatangan aktif → DITOLAK.
     *
     * Temuan review, dan alasannya sah: kalau setelan organisasi kosong DAN
     * reviewer sesinya nggak keteumu, tidak ada yang bisa menyebut siapa pemilik
     * gambar tanda tangan yang berlaku sekarang. Mencetak ulang berarti
     * menempelkan gambar yang tidak bisa dipertanggungjawabkan ke bawah nama
     * yang beku.
     */
    public function test_tanpa_sumber_nama_penandatangan_ditolak(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        // Setelan organisasi kosong (bawaan), dan reviewer-nya dilepas.
        $sertifikat->session->update(['reviewed_by' => null]);

        $hasil = $this->layanan()->jalankan([$sertifikat->fresh()]);

        $this->assertSame([], $hasil['berhasil']);
        $this->assertStringContainsString('nggak bisa dipastikan', $hasil['ditolak'][0]['alasan']);
    }

    /**
     * Setelan kosong TAPI reviewer-nya masih ada: dibandingkan ke sana.
     *
     * Ini konfigurasi yang sah — nama beku memang diambil dari reviewer waktu
     * setelan organisasi kosong — dan menolaknya bakal mematikan fitur ini buat
     * mayoritas sesi.
     */
    public function test_setelan_kosong_dibandingkan_ke_reviewer(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $this->assertSame(
            $this->admin->name,
            $sertifikat->snapshot['footer']['penandatangan'],
            'Prasyarat test ini: nama beku memang datang dari reviewer.',
        );

        $hasil = $this->layanan()->jalankan([$sertifikat->fresh()]);

        $this->assertSame([$sertifikat->nomor], $hasil['berhasil']);
    }

    /** Reviewer yang ganti nama juga pergantian orang — ikut ditolak. */
    public function test_reviewer_yang_ganti_nama_ditolak(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $this->admin->update(['name' => 'Orang Yang Beda Sekali']);

        $hasil = $this->layanan()->jalankan([$sertifikat->fresh()]);

        $this->assertSame([], $hasil['berhasil']);
        $this->assertStringContainsString('Orang Yang Beda Sekali', $hasil['ditolak'][0]['alasan']);
    }
}

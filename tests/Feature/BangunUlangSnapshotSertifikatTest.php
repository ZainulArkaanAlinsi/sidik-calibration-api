<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Certificate;
use App\Models\Organization;
use App\Services\CertificateSnapshotBuilder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

/**
 * `sertifikat:bangun-ulang` — nyusun ulang ISI sertifikat yang udah terbit,
 * tanpa nyentuh identitasnya.
 *
 * Perintah ini ada karena `snapshot` itu beku: disusun sekali waktu approve,
 * jadi bug format/olah data yang udah dibetulin tetap kelihatan salah di
 * sertifikat lama selamanya.
 *
 * Yang paling dijaga di sini bukan "snapshot-nya keupdate", tapi **nomor & QR
 * token-nya TETAP**. `GenerateCertificate` nggak bisa dipakai buat ini justru
 * karena `updateOrCreate`-nya bakal ngasih nomor baru + token baru — dan token
 * itu yang dicocokin halaman verifikasi, jadi ngegantinya matiin QR yang
 * mungkin udah kecetak di dokumen pelanggan.
 */
class BangunUlangSnapshotSertifikatTest extends TestCase
{
    use RefreshDatabase;

    private function sertifikatTerbit(): Certificate
    {
        Storage::fake('local');
        $this->seed(DatabaseSeeder::class);

        return Certificate::where('status', Certificate::STATUS_TERBIT)
            ->whereHas('session', fn ($q) => $q->where('nomor_sesi', '2405.13.A'))
            ->firstOrFail();
    }

    /**
     * Sertifikat yang penandatangannya sudah ganti orang DILEWATI, bukan ditimpa.
     *
     * Temuan review 3 Sep 2026. `CertificateSnapshotBuilder::footer()` mengambil
     * nama penandatangan dari setelan organisasi YANG BERLAKU SEKARANG, jadi
     * tanpa penjaga ini sapuan massal diam-diam mengganti nama di sertifikat
     * yang sudah terbit — dokumen menyatakan seseorang menandatangani sesuatu
     * yang tidak pernah dia tandatangani, dan nol error muncul.
     *
     * Tombol "Cetak ulang PDF" di panel sudah menolak kasus ini; perintah
     * inilah yang dipakai buat sapuan massal, jadi justru di sini kerusakannya
     * paling luas.
     */
    public function test_sertifikat_yang_penandatangannya_ganti_dilewati(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        // Bekukan nama lama ke snapshot-nya, lalu ganti setelan organisasinya —
        // persis keadaan waktu Technical Manager berganti orang.
        $snapshot = $sertifikat->snapshot;
        $snapshot['footer']['penandatangan'] = 'Budi Penandatangan Lama';
        $sertifikat->snapshot = $snapshot;
        $sertifikat->save();

        $organisasi = $sertifikat->session->organization;
        $organisasi->settings = [
            ...($organisasi->settings ?? []),
            Organization::KEY_PENANDATANGAN_NAMA => 'Alex Penandatangan Baru',
        ];
        $organisasi->save();

        $this->artisan('sertifikat:bangun-ulang', ['--render-ulang-pdf' => true])
            ->expectsOutputToContain('penandatangannya sudah ganti')
            ->assertSuccessful();

        // Yang beku TETAP beku — nama lamanya nggak boleh ketimpa.
        $this->assertSame(
            'Budi Penandatangan Lama',
            $sertifikat->fresh()->snapshot['footer']['penandatangan'],
            'Nama penandatangan di sertifikat terbit ketimpa setelan yang berlaku sekarang.',
        );
    }

    /** Nama yang SAMA tetap jalan — itu justru kasus yang paling sering. */
    public function test_penandatangan_yang_sama_tetap_dibangun_ulang(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $beku = $sertifikat->snapshot['footer']['penandatangan'] ?? null;
        $this->assertNotNull($beku, 'Fixture-nya nggak punya nama penandatangan, penjaganya nggak keuji.');

        $organisasi = $sertifikat->session->organization;
        $organisasi->settings = [
            ...($organisasi->settings ?? []),
            Organization::KEY_PENANDATANGAN_NAMA => $beku,
        ];
        $organisasi->save();

        $this->artisan('sertifikat:bangun-ulang', ['--render-ulang-pdf' => true])
            ->doesntExpectOutputToContain('penandatangannya sudah ganti')
            ->assertSuccessful();
    }

    public function test_nomor_dan_qr_token_nggak_ikut_berubah(): void
    {
        $sebelum = $this->sertifikatTerbit();

        $nomor = $sebelum->nomor;
        $token = $sebelum->qr_token;
        $payload = $sebelum->qr_payload;
        $terbit = $sebelum->diterbitkan_pada;

        $this->artisan('sertifikat:bangun-ulang')->assertSuccessful();

        $sesudah = $sebelum->fresh();

        $this->assertSame($nomor, $sesudah->nomor, 'nomor sertifikat ikut berubah — QR & dokumen cetaknya jadi nggak nyambung');
        $this->assertSame($token, $sesudah->qr_token, 'qr_token diputer — QR yang udah kecetak langsung mati');
        $this->assertSame($payload, $sesudah->qr_payload);
        $this->assertEquals($terbit, $sesudah->diterbitkan_pada);
        $this->assertSame(Certificate::STATUS_TERBIT, $sesudah->status);
    }

    /**
     * Snapshot basi ditulis ulang ngikut kode yang sekarang. Ini inti gunanya:
     * sertifikat yang kesimpen dengan format lama ikut betul tanpa nunggu
     * sesinya diulang — dan format Env. Condition udah beberapa kali berubah,
     * jadi jalur ini yang dipakai buat nyusulin sertifikat lama.
     */
    public function test_snapshot_lama_ditulis_ulang_ngikut_kode_sekarang(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $basi = $sertifikat->snapshot;
        $basi['header']['env_condition'] = 'T: 99,9°C ± 9,9°C — %RH: 99% ± 9%';
        $sertifikat->update(['snapshot' => $basi]);

        $this->artisan('sertifikat:bangun-ulang')->assertSuccessful();

        $this->assertSame(
            'T: 21,0°C ± 1,7°C — %RH: 51,95% ± 5,7%',
            $sertifikat->fresh()->snapshot['header']['env_condition'],
        );
    }

    /**
     * Perubahan DI LUAR kondisi lingkungan disebut namanya, bukan dicetak
     * sebagai panah yang dua sisinya sama.
     *
     * Kejadian 3 Sep 2026: sapuan yang menyalakan U95 per titik mengeluarkan
     * lima baris `T: 21,0°C … → T: 21,0°C …` — identik kiri-kanan, sambil
     * dihitung sebagai berubah. Yang dibanding memang seluruh snapshot, tapi
     * yang dicetak cuma `env_condition`, jadi tiap perubahan di luar itu
     * kelihatan seperti operasi kosong. Nol error, dan setengah jam habis buat
     * memastikan perintahnya tidak rusak.
     *
     * Yang dijaga di sini BUKAN kalimatnya, tapi dua hal yang bikin baris itu
     * bisa dipercaya: kunci yang berubah kesebut, dan panah dengan dua sisi
     * yang sama tidak bisa muncul.
     */
    public function test_perubahan_di_luar_env_condition_disebut_kuncinya(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        // Cuma `u95_per_titik` yang digeser. Kondisi lingkungannya sengaja
        // dibiarkan apa adanya — persis bentuk kasus yang bikin bingung.
        $basi = $sertifikat->snapshot;
        $envSebelum = $basi['header']['env_condition'];
        $basi['u95_per_titik'] = ! ($basi['u95_per_titik'] ?? false);
        $sertifikat->update(['snapshot' => $basi]);

        // SATU kali jalan, dua asersi diadu ke keluaran yang sama.
        //
        // Versi pertama menjalankannya DUA kali — `$this->artisan()` buat asersi
        // "harus ada", lalu sekali lagi buat mengambil teksnya. Jalan kedua
        // sudah melihat snapshot yang barusan dibetulkan jalan pertama, jadi
        // dia melaporkan "nggak berubah", dan penjaga panahnya lolos di
        // baseline: hijau tanpa menguji apa pun.
        $keluaran = $this->keluaranBangunUlang();

        $this->assertStringContainsString(
            'berubah: u95_per_titik',
            $keluaran,
            'Kunci yang berubah nggak kesebut, jadi barisnya nggak bisa dipakai '
            .'mastiin sapuannya ngerjain yang bener.',
        );

        $this->assertStringNotContainsString(
            "{$envSebelum}  →  {$envSebelum}",
            $keluaran,
            'Panah dengan dua sisi identik muncul lagi — itu bentuk yang bikin '
            .'"berubah" kebaca sebagai "nggak ngapa-ngapain".',
        );
    }

    /**
     * Kondisi lingkungan TETAP dapat bentuk panah. Ini penjaga arah sebaliknya:
     * perbaikan di atas gampang kebablasan jadi "semua cuma disebut namanya",
     * dan nilai T/%RH yang kebaca sekilas itu yang bikin sapuan format lama
     * berguna ditonton.
     */
    public function test_env_condition_tetap_ditampilkan_sebagai_panah(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $basi = $sertifikat->snapshot;
        $basi['header']['env_condition'] = 'T: 99,9°C ± 9,9°C — %RH: 99% ± 9%';
        $sertifikat->update(['snapshot' => $basi]);

        $this->artisan('sertifikat:bangun-ulang')
            ->expectsOutputToContain('T: 99,9°C ± 9,9°C — %RH: 99% ± 9%  →  ')
            ->assertSuccessful();
    }

    /**
     * Keluaran perintah apa adanya, buat asersi yang bentuknya "TIDAK boleh
     * ada". `expectsOutputToContain` cuma bisa menegaskan yang ada.
     */
    private function keluaranBangunUlang(): string
    {
        $keluar = new BufferedOutput;

        $this->app->make(Kernel::class)->call(
            'sertifikat:bangun-ulang',
            [],
            $keluar,
        );

        return $keluar->fetch();
    }

    public function test_dry_run_nggak_nulis_apa_apa(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $basi = $sertifikat->snapshot;
        $basi['header']['env_condition'] = 'T: 99,9°C ± 9,9°C — %RH: 99% ± 9%';
        $sertifikat->update(['snapshot' => $basi]);

        $this->artisan('sertifikat:bangun-ulang', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(
            'T: 99,9°C ± 9,9°C — %RH: 99% ± 9%',
            $sertifikat->fresh()->snapshot['header']['env_condition'],
        );
    }

    /** Sesi tertentu doang — yang lain jangan ikut kesenggol. */
    public function test_bisa_dibatesin_ke_sesi_tertentu(): void
    {
        $this->sertifikatTerbit();

        // Sertifikat KEDUA disusun di sini, dari sesi seeder yang lain.
        //
        // Dulu dipakai `SES/2026/07/0001` — sesi demo buatan tangan yang
        // sertifikatnya terbit TANPA `snapshot` sama sekali, dan sudah dibuang
        // dari `DemoDataSeeder` (lihat `SeederTidakMenanamSertifikatKosongTest`).
        // Seeder cuma menerbitkan satu sertifikat, jadi pasangan buat menguji
        // "yang lain jangan kesenggol" memang harus dibikin di test — dan itu
        // lebih kokoh daripada menumpang data demo yang bisa berubah.
        $sesiLain = CalibrationSession::where('nomor_sesi', '!=', '2405.13.A')
            ->whereHas('uncertaintyCalculations')
            ->firstOrFail();

        $lain = Certificate::create([
            'organization_id' => $sesiLain->organization_id,
            'calibration_session_id' => $sesiLain->id,
            'nomor' => 'CAL/UJI/0002',
            'qr_token' => 'ujilain02',
            'status' => Certificate::STATUS_TERBIT,
            'diterbitkan_pada' => now()->subDay(),
            'berlaku_sampai' => now()->addYear(),
        ]);
        $lain->update([
            'snapshot' => app(CertificateSnapshotBuilder::class)->bangun($sesiLain, $lain),
        ]);

        $basi = $lain->snapshot;
        $basi['header']['env_condition'] = 'JANGAN DISENTUH';
        $lain->update(['snapshot' => $basi]);

        $this->artisan('sertifikat:bangun-ulang', ['sesi' => ['2405.13.A']])->assertSuccessful();

        $this->assertSame('JANGAN DISENTUH', $lain->fresh()->snapshot['header']['env_condition']);
    }

    /**
     * Perintah ini nyusun ulang dari data sesi APA ADANYA — dia bukan alat buat
     * ngubah hasil. Pembacaan yang salah ketik tetap salah sesudah dijalanin;
     * yang betulin itu alur revisi di aplikasi.
     */
    public function test_nggak_ngubah_angka_hasil_kalibrasi(): void
    {
        $sertifikat = $this->sertifikatTerbit();

        $sebelum = $sertifikat->snapshot['hasil'];

        $this->artisan('sertifikat:bangun-ulang')->assertSuccessful();

        $this->assertEquals($sebelum, $sertifikat->fresh()->snapshot['hasil']);
    }

    public function test_sertifikat_yang_belum_terbit_dilewati(): void
    {
        $this->sertifikatTerbit();

        $belum = CalibrationSession::whereDoesntHave('certificate')->first();

        $this->artisan('sertifikat:bangun-ulang')->assertSuccessful();

        if ($belum !== null) {
            $this->assertNull($belum->fresh()->certificate);
        }
    }

    /**
     * Perbaikan TATA LETAK tidak menyentuh snapshot sama sekali.
     *
     * Jadi tanpa jalur ini, sertifikat lama yang PDF-nya masih beredar dengan
     * bentuk lama (mis. dua halaman, atau tanda tangan yang meluber) tidak akan
     * pernah kesentuh perintah ini — dia `continue` begitu snapshot-nya sama,
     * dan snapshot-nya memang selalu sama untuk perbaikan semacam itu.
     */
    public function test_render_ulang_pdf_nulis_ulang_walau_snapshot_sama(): void
    {
        Storage::fake('arsip');
        $sertifikat = $this->sertifikatTerbit();

        // Dijalankan sekali dulu supaya snapshot-nya pasti sudah mutakhir —
        // jalan berikutnya dijamin masuk cabang "nggak berubah".
        $this->artisan('sertifikat:bangun-ulang')->assertSuccessful();

        $sertifikat->forceFill(['pdf_path' => 'certificates/uji.pdf'])->save();
        Storage::disk('arsip')->put('certificates/uji.pdf', 'BUKAN-PDF');

        $this->artisan('sertifikat:bangun-ulang', ['--render-ulang-pdf' => true])
            ->assertSuccessful();

        $this->assertStringStartsWith(
            '%PDF',
            (string) Storage::disk('arsip')->get('certificates/uji.pdf'),
            'PDF-nya nggak ditulis ulang, padahal --render-ulang-pdf dipasang.',
        );
    }

    /** Tanpa flag-nya, berkas yang snapshot-nya sama TIDAK disentuh. */
    public function test_tanpa_flag_pdf_nggak_disentuh_kalau_snapshot_sama(): void
    {
        Storage::fake('arsip');
        $sertifikat = $this->sertifikatTerbit();

        $this->artisan('sertifikat:bangun-ulang')->assertSuccessful();

        $sertifikat->forceFill(['pdf_path' => 'certificates/uji.pdf'])->save();
        Storage::disk('arsip')->put('certificates/uji.pdf', 'BUKAN-PDF');

        $this->artisan('sertifikat:bangun-ulang')->assertSuccessful();

        $this->assertSame(
            'BUKAN-PDF',
            (string) Storage::disk('arsip')->get('certificates/uji.pdf'),
            'Berkasnya ditulis ulang tanpa diminta — perintah ini nggak boleh '
            .'menyentuh yang nggak berubah kecuali disuruh.',
        );
    }
}

<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\Organization;
use App\Models\User;
use App\Services\PengingatJatuhTempo;
use App\Services\PenjagaNotifikasiUlang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pengingat jatuh tempo alat tidak lagi mendarat tiap pagi tanpa batas.
 *
 * ## Kenapa berkas ini ada
 *
 * `PenjagaNotifikasiUlang` dibuat khusus untuk masalah ini, dan docblock-nya
 * sendiri yang menjelaskannya:
 *
 * > *"Pengingat standar acuan jalan dari scheduler TIAP PAGI … tanpa penjaga
 * > ini, admin dapat baris yang persis sama 30 kali berturut-turut … Sesudah
 * > minggu pertama nggak ada yang buka loncengnya lagi."*
 *
 * Dua pengingat ini dipicu scheduler yang SAMA (`routes/console.php`,
 * `dailyAt('07:00')`), lima menit berselang, dan mendarat di lonceng yang sama.
 * Yang satu memakai penjaga; yang satu tidak:
 *
 * ```
 * grep PenjagaNotifikasiUlang app/Services/PengingatStandar.php     → 3 hit
 * grep PenjagaNotifikasiUlang app/Services/PengingatJatuhTempo.php  → 0 hit
 * grep tandaTangan app/Notifications/StandarMauKadaluarsa.php       → ada
 * grep tandaTangan app/Notifications/AlatJatuhTempo.php             → 0
 * ```
 *
 * `NotifikasiSistem` bahkan sudah menyatakan aturannya: `tandaTangan()` *"cuma
 * perlu diisi notifikasi yang dipicu BERULANG (scheduler harian)"*. `AlatJatuhTempo`
 * dipicu scheduler harian, dan tidak meng-override-nya.
 *
 * ## Kenapa ini P1, bukan gangguan kecil
 *
 * Yang rusak bukan cuma pengingat ini. Selama sebuah alat belum dikalibrasi
 * ulang — bisa berminggu-minggu — tiap admin menerima baris identik SETIAP
 * PAGI. Persis skenario yang menurut dokumentasi proyek ini sendiri membuat
 * admin berhenti membuka loncengnya, sehingga notifikasi yang benar-benar
 * penting (sertifikat gagal terbit, sesi menunggu approval) ikut tertimbun.
 */
class PengingatJatuhTempoTidakBanjirTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // `createOne()`, bukan `create()`: yang kedua bertipe
        // `TModel|Collection<TModel>`, jadi properti bertipe `Organization` /
        // `Equipment` tidak bisa dibuktikan alat analisis mana pun. Perilakunya
        // sama persis; yang berubah cuma ketepatan tipenya.
        $this->org = Organization::factory()->createOne();
        $this->admin = User::factory()->admin()->createOne();
    }

    /**
     * Alat yang jatuh tempo dalam [$hari] hari — masuk jendela peringatan
     * default (30 hari) tanpa sudah lewat.
     */
    private function alat(int $hari, string $nama = 'pH Meter'): Equipment
    {
        return Equipment::factory()->createOne([
            'organization_id' => $this->org->id,
            'nama_alat' => $nama,
            'status' => Equipment::STATUS_AKTIF,
            'tanggal_jatuh_tempo' => now()->addDays($hari)->toDateString(),
        ]);
    }

    private function pengingat(): PengingatJatuhTempo
    {
        return app(PengingatJatuhTempo::class);
    }

    /**
     * INTI bug-nya. Sebelum diperbaiki, hari ke-2 dan ke-3 mengirim ulang baris
     * yang sama persis — dan begitu seterusnya sampai alatnya dikalibrasi.
     */
    public function test_isi_yang_sama_nggak_diulang_tiap_pagi(): void
    {
        $this->alat(10);
        $pengingat = $this->pengingat();

        $hari1 = $pengingat->untukOrganisasi($this->org);
        $this->assertSame(1, $hari1['admin_dikabarin']);

        $this->travel(1)->days();
        $hari2 = $pengingat->untukOrganisasi($this->org);
        $this->travel(1)->days();
        $hari3 = $pengingat->untukOrganisasi($this->org);

        $this->assertSame(0, $hari2['admin_dikabarin'], 'Hari ke-2 harusnya dilewat.');
        $this->assertSame(1, $hari2['admin_dilewat']);
        $this->assertSame(0, $hari3['admin_dikabarin'], 'Hari ke-3 harusnya dilewat.');

        $this->assertSame(
            1,
            $this->admin->fresh()->notifications()->count(),
            'Lonceng admin kebanjiran baris yang sama.'
        );
    }

    /** Sesudah masa tenang, diingetin lagi — biar nggak kelupaan. */
    public function test_sesudah_masa_tenang_diingetin_lagi(): void
    {
        // 25 hari: masih di dalam jendela 30 hari SEBELUM dan SESUDAH lompat 8
        // hari, dan statusnya tetap "mendekati" (belum overdue) — jadi yang
        // diuji beneran masa tenangnya, bukan status yang kebetulan berubah.
        $this->alat(25);
        $pengingat = $this->pengingat();

        $pengingat->untukOrganisasi($this->org);
        $this->travel(PengingatJatuhTempo::MASA_TENANG_HARI + 1)->days();
        $pengingat->untukOrganisasi($this->org);

        $this->assertSame(2, $this->admin->fresh()->notifications()->count());
    }

    /**
     * Isi BERUBAH → dikirim saat itu juga, nggak nunggu masa tenang.
     *
     * Alat kedua masuk jendela peringatan itu kabar baru, dan itu justru saat
     * yang paling nggak boleh telat.
     */
    public function test_alat_baru_masuk_jendela_langsung_dikabarin(): void
    {
        $this->alat(10, 'pH Meter');
        $pengingat = $this->pengingat();

        $pengingat->untukOrganisasi($this->org);
        $this->assertSame(1, $this->admin->fresh()->notifications()->count());

        $this->travel(1)->days();
        $this->alat(20, 'Timbangan Analitik');

        $hasil = $pengingat->untukOrganisasi($this->org);

        $this->assertSame(1, $hasil['admin_dikabarin'], 'Isi berubah harusnya langsung dikabarin.');
        $this->assertSame(2, $this->admin->fresh()->notifications()->count());
    }

    /**
     * Alat yang BERGESER dari "mendekati" jadi "sudah lewat" itu kabar baru —
     * dan itu justru saat yang paling penting.
     *
     * Ini yang menentukan bentuk tanda tangannya: kalau sumbunya cuma `id`,
     * pergeseran ini tidak akan pernah menembus masa tenang, dan admin baru
     * tahu alatnya overdue seminggu kemudian.
     */
    public function test_alat_yang_jadi_overdue_nembus_masa_tenang(): void
    {
        $this->alat(2);
        $pengingat = $this->pengingat();

        $hari1 = $pengingat->untukOrganisasi($this->org);
        $this->assertSame(0, $hari1['overdue']);
        $this->assertSame(1, $hari1['admin_dikabarin']);

        // Tiga hari kemudian alatnya lewat jatuh tempo — masih di dalam masa
        // tenang tujuh hari, tapi statusnya berubah.
        $this->travel(3)->days();
        $hasil = $pengingat->untukOrganisasi($this->org);

        $this->assertSame(1, $hasil['overdue']);
        $this->assertSame(
            1,
            $hasil['admin_dikabarin'],
            'Alat jadi overdue tapi kabarnya ketahan masa tenang.'
        );
        $this->assertSame(2, $this->admin->fresh()->notifications()->count());
    }

    /** Admin baru tetap dapat kabar pertamanya walaupun admin lama sudah dikabarin. */
    public function test_admin_baru_tetap_dapat_kabar_pertama(): void
    {
        $this->alat(10);
        $pengingat = $this->pengingat();

        $pengingat->untukOrganisasi($this->org);

        $this->travel(1)->days();
        $adminBaru = User::factory()->admin()->create(['organization_id' => $this->org->id]);

        $hasil = $pengingat->untukOrganisasi($this->org);

        $this->assertSame(1, $hasil['admin_dikabarin']);
        $this->assertSame(1, $hasil['admin_dilewat']);
        $this->assertSame(1, $adminBaru->fresh()->notifications()->count());
        $this->assertSame(1, $this->admin->fresh()->notifications()->count());
    }

    /**
     * Alat ke-21 dan seterusnya IKUT menentukan tanda tangannya.
     *
     * Payloadnya sengaja dipotong 20 baris — lonceng bukan tempat menaruh
     * ratusan alat. Tapi tanda tangannya sempat ikut dihitung dari daftar yang
     * SUDAH terpotong itu, dan akibatnya persis kebalikan dari maksud
     * penjaganya: alat di luar 20 pertama yang bergeser dari "mendekati" jadi
     * "sudah lewat" bikin `isi()` berubah tanpa tanda tangannya ikut berubah,
     * jadi kabar barunya ketahan tujuh hari.
     *
     * Yang kena cuma lab dengan lebih dari 20 alat — yaitu lab yang paling
     * butuh pengingatnya. Dan tidak ada yang error waktu itu terjadi.
     *
     * Alat yang digeser dipilih dari yang TIDAK muncul di payload, bukan dari
     * urutan sisipan: urutan `get()` tanpa `orderBy` bukan janji, dan test yang
     * bergantung padanya hijau/merah bergantian tiap ganti database.
     */
    public function test_alat_di_luar_20_pertama_tetap_bikin_kabar_baru(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $this->alat(10, "pH Meter {$i}");
        }

        $pertama = $this->pengingat()->untukOrganisasi($this->org);
        $this->assertSame(1, $pertama['admin_dikabarin']);
        $this->assertSame(21, $pertama['mendekati']);

        $notifikasi = $this->admin->fresh()->notifications()->first();
        $ditampilkan = array_column($notifikasi->data['tautan']['alat'], 'id');

        $this->assertCount(20, $ditampilkan, 'Payloadnya nggak lagi dipotong 20 — testnya yang basi.');

        // Alat yang TIDAK ikut ditampilkan. Dulu dia tak terlihat sama sekali
        // oleh penjaganya.
        $diLuarDaftar = Equipment::query()
            ->where('organization_id', $this->org->id)
            ->whereNotIn('id', $ditampilkan)
            ->firstOrFail();

        // Dia bergeser jadi sudah lewat jatuh tempo — kabar baru, dan justru
        // saat yang paling nggak boleh ketahan masa tenang.
        $diLuarDaftar->update(['tanggal_jatuh_tempo' => now()->subDay()->toDateString()]);

        $kedua = $this->pengingat()->untukOrganisasi($this->org);

        $this->assertSame(1, $kedua['overdue']);
        $this->assertSame(
            1,
            $kedua['admin_dikabarin'],
            'Alat ke-21 berubah jadi overdue tapi kabarnya ketahan masa tenang — '
                .'tanda tangannya dihitung dari daftar yang sudah dipotong.',
        );

        $this->assertSame(2, $this->admin->fresh()->notifications()->count());
    }

    /**
     * JANGAN kebablasan: yang di dalam 20 pertama pun tetap tidak boleh
     * mengulang kalau memang tidak ada yang berubah.
     */
    public function test_lab_besar_yang_nggak_berubah_tetap_nggak_diulang(): void
    {
        for ($i = 1; $i <= 25; $i++) {
            $this->alat(10, "pH Meter {$i}");
        }

        $this->assertSame(1, $this->pengingat()->untukOrganisasi($this->org)['admin_dikabarin']);

        $this->travel(1)->days();

        $this->assertSame(
            0,
            $this->pengingat()->untukOrganisasi($this->org)['admin_dikabarin'],
            'Lab 25 alat kebanjiran baris yang sama tiap pagi.',
        );
    }

    /**
     * Tanda tangan di dua tempat harus sama persis.
     *
     * Dia dihitung dua kali — di service buat memutuskan kirim/tidak, di
     * notifikasi buat disimpan ke payload. Kalau keduanya geser sendiri-sendiri,
     * penjaganya tidak pernah cocok dan notifikasinya kembali terulang tiap
     * pagi. Diam-diam, tanpa satu pun error.
     *
     * Padanan test yang sama sudah ada untuk `StandarMauKadaluarsa`.
     */
    public function test_tanda_tangan_service_dan_notifikasi_sama_persis(): void
    {
        $this->alat(10);
        $this->pengingat()->untukOrganisasi($this->org);

        $data = $this->admin->fresh()->notifications()->first()->data;
        $rincian = $data['tautan']['alat'];

        $this->assertSame(
            $this->pengingat()->tandaTangan($rincian),
            $data[PenjagaNotifikasiUlang::KEY_TANDA_TANGAN],
            'Tanda tangan service & notifikasi geser sendiri-sendiri.'
        );
    }

    /**
     * JANGAN kebablasan: pengingatnya tetap SAMPAI di kesempatan pertama.
     *
     * Kalau test ini merah, penjaganya menelan kabar pertama juga — dan
     * gejalanya persis sama dengan pengingat yang tidak pernah dipasang.
     */
    public function test_kabar_pertama_tetap_nyampe(): void
    {
        $this->alat(10);

        $hasil = $this->pengingat()->untukOrganisasi($this->org);

        $this->assertSame(1, $hasil['admin_dikabarin']);
        $this->assertSame(0, $hasil['admin_dilewat']);
        $this->assertSame(1, $this->admin->fresh()->notifications()->count());
    }

    /**
     * JANGAN kebablasan: alat di lab lain tetap tidak ikut terbawa, dan
     * penjaganya tidak boleh membuat lab kedua ikut sepi gara-gara lab pertama
     * sudah dikabarin.
     */
    public function test_organisasi_lain_punya_masa_tenang_sendiri(): void
    {
        $this->alat(10);
        $pengingat = $this->pengingat();
        $pengingat->untukOrganisasi($this->org);

        $orgLain = Organization::factory()->create();
        $adminLain = User::factory()->admin()->create(['organization_id' => $orgLain->id]);
        Equipment::factory()->create([
            'organization_id' => $orgLain->id,
            'status' => Equipment::STATUS_AKTIF,
            'tanggal_jatuh_tempo' => now()->addDays(10)->toDateString(),
        ]);

        $hasil = $pengingat->untukOrganisasi($orgLain);

        $this->assertSame(1, $hasil['admin_dikabarin']);
        $this->assertSame(1, $adminLain->fresh()->notifications()->count());
    }
}

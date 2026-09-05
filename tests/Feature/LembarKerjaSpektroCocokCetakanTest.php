<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Lembar kerja Spectrophotometer diadu ke CETAKAN ASLINYA,
 * `SIDIK-FM-CAL-0511_Rev.5 - LEMBAR KERJA SPECTROFOTOMETER.pdf` (LK-285-IDN),
 * yang masuk repo 13 Agt 2026.
 *
 * Kenapa ini dijaga tes, bukan cukup dibaca sekali: teknisi ngisi layar sambil
 * MEGANG kertas itu. Begitu penomoran atau judul kotaknya beda, dia mesti
 * nerjemahin sendiri kotak mana ke kotak mana — dan itu jenis kesalahan yang
 * nggak keliatan di hasil, cuma keliatan waktu audit nyocokin lembar kerja sama
 * sertifikat.
 *
 * Yang TIDAK dijaga di sini: nilai standar & jumlah titik. Kertas dan master
 * beda di empat tempat, dan sistem sengaja ngikut master sampai lab memutuskan
 * — lihat "Cetakan Rev.5 vs master" di [SpectrophotometerProfile].
 */
class LembarKerjaSpektroCocokCetakanTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private int $equipmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $sesi = CalibrationSession::where('nomor_sesi', 'DEMO-SPECTRO-LDC')->firstOrFail();
        $this->equipmentId = $sesi->equipment_id;
        $this->teknisi = User::findOrFail($sesi->teknisi_id);
    }

    /**
     * Dua nomor yang beda jenis: FORMULIR (FM) di kepala lembar, METODE (IK) di
     * baris "2. Calibration Methode". Sempat ketuker — `kode_dokumen` keisi
     * nomor IK — jadi dua-duanya dipatok.
     */
    public function test_nomor_formulir_dan_nomor_metode_dikirim_terpisah(): void
    {
        $bentuk = $this->lembar();

        $this->assertSame('SIDIK-FM-CAL-0511_Rev.5', $bentuk['kode_dokumen']);
        $this->assertSame('SIDIK-IK-CAL-0508_Rev.4', $bentuk['kode_metode']);
        $this->assertSame('Calibration Worksheet - UV/VIS Spectrophotometer', $bentuk['judul']);
    }

    /** Penomoran blok EQUIPMENT persis kayak Rev.5, urut & tanpa nomor loncat. */
    public function test_penomoran_blok_equipment_ngikut_cetakan(): void
    {
        $field = $this->bagian('identitas_alat')['field'];

        $label = collect($field)
            ->pluck('label')
            ->filter(static fn (string $l): bool => (bool) preg_match('/^\d\./', $l))
            ->unique()
            ->values()
            ->all();

        $this->assertSame(
            [
                '1. Name',
                '2. Range',
                '3. Sensitivity/Resolusi',
                '4. Type/Model',
                '5. Serial Number',
                '6. Manufacture',
            ],
            $label,
        );

        // Nomor 6 di kertas itu Manufacture, jadi "Thermohygro used" nggak boleh
        // bernomor lagi — di kertas dia ada di kepala lembar, sebaris sama
        // Received Date & Calibration Date.
        $this->assertSame(
            'Thermohygro used',
            collect($field)->firstWhere('kode', 'thermohygro_standard_id')['label'],
        );
    }

    /**
     * Kotak yang diminta master tapi NGGAK ada di kertas ditandai, bukan dibuang
     * dan bukan didiamkan. Dibuang = angka master hilang; didiamkan = teknisi
     * nyari kotak yang nggak ada di tangannya.
     */
    public function test_kotak_di_luar_cetakan_ditandai_di_kertas_false(): void
    {
        $bentuk = $this->lembar();

        $diKertasFalse = collect($bentuk['bagian'])
            ->flatMap(static fn (array $b): array => $b['field'] ?? [])
            ->filter(static fn (array $f): bool => $f['di_kertas'] === false)
            ->pluck('kode')
            ->all();

        $this->assertSame(['spesifikasi_alat.kapasitas_maks_transmitan'], array_values($diKertasFalse));

        // Blok SRE juga nggak ada di kertas — CALIBRATION RESULT Rev.5 cuma
        // punya "1. Wave Length Calibration" & "2. Absorbans or Transmitan
        // Calibration".
        $sre = $this->bagian('sre');
        $this->assertFalse($sre['di_kertas']);
        $this->assertSame('sumber_belum_ada', $sre['status']);
        $this->assertSame([], $sre['field']);
    }

    /**
     * Tabel Env. Condition di kertas punya TIGA kolom (Time, Temperature,
     * Humidity) × dua baris (First, End). Kolom Time-nya dulu nggak punya
     * tempat, jadi jam yang ditulis teknisi hilang waktu pindah ke aplikasi.
     */
    public function test_env_condition_punya_kolom_time_first_dan_end(): void
    {
        $field = collect($this->bagian('hasil')['field']);

        $this->assertSame(
            ['waktu_awal', 'suhu_awal', 'kelembaban_awal', 'waktu_akhir', 'suhu_akhir', 'kelembaban_akhir'],
            $field->pluck('kode')->all(),
        );

        $waktu = $field->whereIn('kode', ['waktu_awal', 'waktu_akhir']);
        $this->assertSame(['waktu', 'waktu'], $waktu->pluck('tipe')->all());
        $this->assertSame(
            ['Env. Condition — First', 'Env. Condition — End'],
            $waktu->pluck('label')->all(),
        );
    }

    /**
     * Jam yang dikirim HP nyampe database dan balik lagi dalam bentuk yang SAMA
     * — `H:i`, apa pun mesin databasenya. Kolom `time` MySQL selalu mulangin
     * `08:30:00`, SQLite mulangin persis yang ditulis; tanpa normalisasi, test
     * hijau di SQLite dan bentuk responsnya beda di produksi.
     */
    public function test_jam_env_condition_tersimpan_dan_dipulangkan_h_i(): void
    {
        $sesi = $this->simpanSesi(['waktu_awal' => '08:30', 'waktu_akhir' => '11:05']);

        $this->assertSame('08:30', $sesi->waktu_awal);
        $this->assertSame('11:05', $sesi->waktu_akhir);

        // Yang kesimpen di kolomnya tetap `H:i:s` — bentuk baku kolom `time`.
        $mentah = CalibrationSession::withoutGlobalScopes()
            ->where('id', $sesi->id)
            ->value('waktu_awal');
        $this->assertStringStartsWith('08:30', (string) $mentah);

        $this->assertSame(
            '08:30',
            $this->actingAs($this->teknisi)
                ->getJson("/api/calibrations/{$sesi->id}")
                ->assertOk()
                ->json('data.waktu_awal'),
        );
    }

    /**
     * Draft yang dibuka lagi lalu dikirim balik apa adanya (`08:30:00`, bentuk
     * yang dipulangkan kolom `time` MySQL) nggak boleh ditolak validasi.
     */
    public function test_jam_bentuk_h_i_s_ikut_diterima(): void
    {
        $this->assertSame('08:30', $this->simpanSesi(['waktu_awal' => '08:30:00'])->waktu_awal);
    }

    public function test_jam_ngawur_ditolak_bukan_disimpan_diam_diam(): void
    {
        $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $this->equipmentId,
                'tanggal_kalibrasi' => now()->subDay()->toDateString(),
                'waktu_awal' => '25:99',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('waktu_awal');
    }

    /**
     * Cetakan Rev.5 cuma nyetak empat kotak thermohygro (TH-2/6/7 Insitu, TH-4
     * Inlab). Yang tercetak DITANDAI, tapi ketujuh unit tetap ditawarkan:
     * mempersempit daftar persis inilah yang bikin Env. Condition tiga alat
     * meleset dari master pada 10 Agt 2026 — teknisi yang bawa unit di luar
     * daftar nggak punya pilihan yang benar sama sekali.
     */
    public function test_semua_unit_thermohygro_tetap_ditawarkan_walau_kertasnya_cuma_empat(): void
    {
        $pilihan = collect(
            collect($this->bagian('identitas_alat')['field'])
                ->firstWhere('kode', 'thermohygro_standard_id')['pilihan']
        );

        $this->assertSame(
            ['TH-1', 'TH-3', 'TH-4', 'TH-5', 'TH-7', 'TH-2', 'TH-6'],
            $pilihan->pluck('label')->all(),
        );

        $this->assertSame(
            ['TH-4', 'TH-7', 'TH-2', 'TH-6'],
            $pilihan->where('di_kertas', true)->pluck('label')->values()->all(),
        );
    }

    /**
     * Bentuk KERTASNYA buat pindai foto ikut dikirim bareng lembar kerjanya.
     *
     * Mobile nggak boleh nyimpen daftar "alat mana yang kertasnya beda" —
     * daftar begitu pasti basi begitu ada profil baru. Dia tinggal nerusin
     * `pindai_foto` apa adanya ke endpoint ekstraksi foto.
     *
     * Isinya buat lembar ini: nggak ada kolom suhu (tiap sel satu angka; suhu
     * ruang dicatat sekali di Env. Condition) dan standarnya turun ke bawah
     * sementara Repeat X1..X3 berjajar ke kanan.
     *
     * Dua gerbangnya disebut EKSPLISIT sejak 27 Agt 2026, dan itu bukan
     * kosmetik. Sebelumnya profil ini nggak menyebut `didukung` sama sekali,
     * jadi nilainya diwarisi dari bawaan `WorksheetExtractionController`. Waktu
     * bawaan itu diturunkan buat menutup lubang lain, jalur foto lembar ini
     * ikut mati tanpa ada yang berniat begitu — dan yang menangkapnya cuma test
     * lain, kebetulan.
     *
     * `assertSame` di sini sengaja seluruh isi array: kunci yang hilang WAJIB
     * bikin baris ini merah, karena hilangnya kunci itulah bentuk kegagalannya.
     */
    public function test_bentuk_pindai_foto_ikut_dikirim(): void
    {
        $this->assertSame(
            [
                'kolom_suhu' => false,
                'standar_di_baris' => true,
                'didukung' => true,
                'lokal' => true,
            ],
            $this->lembar()['pindai_foto'],
        );
    }

    /** @return array<string, mixed> */
    private function lembar(): array
    {
        return $this->actingAs($this->teknisi)
            ->getJson('/api/calibrations/lembar-kerja?equipment_id='.$this->equipmentId)
            ->assertOk()
            ->json('data');
    }

    /** @return array<string, mixed> */
    private function bagian(string $kode): array
    {
        /** @var Collection<int, array<string, mixed>> $bagian */
        $bagian = collect($this->lembar()['bagian']);

        return $bagian->firstWhere('kode', $kode);
    }

    /** @param  array<string, mixed>  $ubah */
    private function simpanSesi(array $ubah): CalibrationSession
    {
        $id = $this->actingAs($this->teknisi)
            ->postJson('/api/calibrations', [
                'equipment_id' => $this->equipmentId,
                'tanggal_kalibrasi' => now()->subDay()->toDateString(),
                ...$ubah,
            ])
            ->assertCreated()
            ->json('data.id');

        return CalibrationSession::findOrFail($id);
    }
}

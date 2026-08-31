<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\User;
use App\Support\TimbanganMentah;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jalur SIMPAN lembar Timbangan, ujung ke ujung lewat `POST /api/calibrations`.
 *
 * `TimbanganMasterTest` membuktikan mesin hitungnya; berkas ini membuktikan
 * **payload dari HP beneran sampai ke mesin itu**. Dua hal yang berbeda, dan
 * yang kedua yang paling sering bocor diam-diam: lembar TIDS pernah hidup
 * berbulan-bulan mengirim angka yang tidak pernah tersimpan, dengan respons
 * `201 Created` di tiap request.
 */
class TimbanganSesiTest extends TestCase
{
    use RefreshDatabase;

    private const TOLERANSI = 5e-6;

    /** Payload satu sesi kg ringkas — tiga titik, cukup buat membuktikan jalurnya. */
    private function payload(Equipment $alat, array $ganti = []): array
    {
        return [
            'equipment_id' => $alat->id,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => '2025-05-02',
            'suhu_awal' => 26.1, 'suhu_akhir' => 26.0,
            'kelembaban_awal' => 53, 'kelembaban_akhir' => 52,
            'measurements' => [
                ['titik_ukur' => 10, 'nominal' => [10.0], 'z1' => 0, 'm' => 10, 'm_aksen' => 10, 'z2' => 0],
                ['titik_ukur' => 20, 'nominal' => [20.0], 'z1' => 0, 'm' => 20, 'm_aksen' => 20, 'z2' => 0],
                ['titik_ukur' => 30, 'nominal' => [20.0, 10.0], 'z1' => 0, 'm' => 30, 'm_aksen' => 30, 'z2' => 0],
            ],
            'spesifikasi_alat' => [
                'rentang_ukur' => '100', 'kapasitas' => '100', 'resolusi' => '0.02',
                'varian_master' => 'kg', 'tipe_display' => 'Digital',
                'tipe_timbangan' => 'Non-Analytical', 'satuan' => 'kg',
                'keterulangan' => [
                    'mid' => ['nominal' => 50, 'zi' => array_fill(0, 10, 0), 'mi' => array_fill(0, 10, 50.02)],
                    'maks' => ['nominal' => 100, 'zi' => array_fill(0, 10, 0), 'mi' => array_fill(0, 10, 100.02)],
                ],
                'eksentrisitas' => ['beban' => 20, 'baca' => [
                    'center' => 20, 'front' => 20, 'back' => 20.02, 'left' => 20, 'right' => 20,
                ]],
            ],
            ...$ganti,
        ];
    }

    private function siapkan(): array
    {
        $this->seed(DatabaseSeeder::class);

        return [
            Equipment::where('serial_number', 'TB-100')->firstOrFail(),
            User::where('role', User::ROLE_TEKNISI)->where('status', User::STATUS_AKTIF)->firstOrFail(),
        ];
    }

    /**
     * Empat pembacaan per titik + slot nominalnya beneran mendarat di
     * `raw_measurements` — bukan cuma "nggak ditolak".
     */
    public function test_empat_pembacaan_dan_slot_nominal_tersimpan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertCreated()
            ->json('data.id');

        foreach (TimbanganMentah::PERAN_PEMBACAAN as $peran) {
            $this->assertSame(
                3,
                RawMeasurement::where('calibration_session_id', $id)->where('peran_sensor', $peran)->count(),
                "Pembacaan `{$peran}` nggak tersimpan buat ketiga titik.",
            );
        }

        $nominal = RawMeasurement::where('calibration_session_id', $id)
            ->where('peran_sensor', TimbanganMentah::PERAN_NOMINAL)
            ->where('titik_ke', 3)
            ->orderBy('sensor_ke')
            ->get();

        // Titik 3 memakai DUA keping — dan slotnya harus terurut, karena
        // urutan Mass 1..6 ikut ke baris drift di budget.
        $this->assertCount(2, $nominal);
        $this->assertSame([1, 2], $nominal->pluck('sensor_ke')->map(fn ($n) => (int) $n)->all());
        $this->assertEqualsWithDelta(20.0, (float) $nominal[0]->pembacaan, self::TOLERANSI);
        $this->assertEqualsWithDelta(10.0, (float) $nominal[1]->pembacaan, self::TOLERANSI);
    }

    /** Angkanya sampai ke mesin hitung, dan yang keluar angka master. */
    public function test_hasil_hitung_cocok_master(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $respons = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertCreated();

        $titik = $respons->json('data.titik');
        $this->assertCount(3, $titik);

        // Massa konvensional total — bukan nominal. Titik 1 = keping F1 10 kg.
        $this->assertEqualsWithDelta(10.000007, (float) $titik[0]['titik_ukur'], self::TOLERANSI);
        $this->assertEqualsWithDelta(19.9997, (float) $titik[1]['titik_ukur'], self::TOLERANSI);
        $this->assertEqualsWithDelta(29.999707, (float) $titik[2]['titik_ukur'], self::TOLERANSI);

        // Lantai CMC pita G (60-150 kg) = 33 g = 0,033 kg, dan di ketiga titik
        // ini hitungannya memang di bawah itu.
        foreach ($titik as $i => $t) {
            $this->assertEqualsWithDelta(
                0.033,
                (float) $t['ketidakpastian_diperluas'],
                self::TOLERANSI,
                'U95 titik '.($i + 1).' harusnya berlantai CMC.',
            );
        }
    }

    /**
     * Titik yang cuma punya kolom NOL terisi tidak boleh kebuang.
     *
     * `store()` menghapus `raw_measurements` lama SEBELUM menyusun yang baru,
     * jadi titik yang kesaring di gerbang "ada isinya" bukan cuma nggak
     * kesimpan — yang lama ikut hilang permanen. Pengulangan bug yang sudah
     * kena baris Indikator Enclosure dan tabel standar TIDS.
     */
    public function test_titik_yang_baru_punya_kolom_nol_tetap_kesimpan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['measurements'][] = [
            'titik_ukur' => 40, 'nominal' => [], 'z1' => 0, 'm' => null, 'm_aksen' => null, 'z2' => null,
        ];

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertCreated()
            ->json('data.id');

        $this->assertSame(
            4,
            RawMeasurement::where('calibration_session_id', $id)
                ->distinct()
                ->count('titik_ke'),
            'Titik keempat kebuang padahal kolom nol-nya sudah diisi teknisi.',
        );
    }

    /**
     * Nominal anak timbangan yang tidak ada di tabel lab DIBLOKIR dengan alasan
     * — bukan dihitung dengan massa standar nol.
     *
     * Diadu lewat `preview`, bukan `store`: `belum_dihitung` memang cuma
     * dikirim di jalur preview (berlaku buat kedua puluh satu profil), dan itu
     * layar tempat teknisi melihat alasannya sebelum mengirim.
     */
    public function test_nominal_asing_pulang_sebagai_belum_dihitung(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        $payload['measurements'][1]['nominal'] = [7.5];
        $payload['measurements'][1]['titik_ukur'] = 7.5;

        $respons = $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', $payload)
            ->assertOk();

        $this->assertCount(2, $respons->json('data.titik'), 'Dua titik lain harus tetap kehitung.');

        $belum = $respons->json('data.belum_dihitung');
        $this->assertNotEmpty($belum, 'Titik ber-nominal asing harus dilaporkan, bukan hilang diam-diam.');
        $this->assertStringContainsString('tabel standar lab', $belum[0]['alasan']);
    }

    /**
     * Preview dan sesi tersimpan WAJIB memulangkan angka yang sama persis.
     *
     * Buat lab terakreditasi, dua angka berbeda untuk satu pengukuran itu
     * temuan audit — dan lembar ini punya jalur simpan sendiri
     * (`susunBlokTimbangan`), jadi kesempatan keduanya menyimpang nyata.
     */
    public function test_angka_preview_sama_dengan_yang_tersimpan(): void
    {
        [$alat, $teknisi] = $this->siapkan();
        $payload = $this->payload($alat);

        $preview = $this->actingAs($teknisi)
            ->postJson('/api/calibrations/preview', $payload)->assertOk()->json('data.titik');
        $simpan = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)->assertCreated()->json('data.titik');

        $ambil = static fn (array $titik): array => array_map(static fn (array $t): array => [
            'titik_ukur' => (float) $t['titik_ukur'],
            'koreksi' => (float) $t['koreksi'],
            'U95' => (float) $t['ketidakpastian_diperluas'],
        ], $titik);

        $this->assertSame($ambil($preview), $ambil($simpan));
    }

    /**
     * Sesi yang DIKEMBALIKAN admin harus pulang dengan isinya utuh —
     * permintaan 8 pemilik proyek.
     *
     * Buat lembar ini "utuh" berarti dua hal sekaligus, dan dua-duanya lewat
     * jalur yang berbeda: empat pembacaan tiap titik + slot nominalnya lewat
     * `raw_measurements` (dibedakan `peran_sensor`/`sensor_ke`), sementara
     * keterulangan, eksentrisitas & histeresis lewat `spesifikasi_alat`.
     *
     * Yang bikin ini pantas dijaga: kegagalannya nggak bersuara. Sesi Inkubator
     * yang dikembalikan dulu memulangkan grid KOSONG — barisnya tersimpan
     * lengkap sejak ingest, cuma nggak pernah ikut pulang, dan teknisi mengetik
     * ulang 180 sel termasuk angka yang sudah benar.
     */
    public function test_sesi_yang_dibuka_lagi_pulang_utuh(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertCreated()
            ->json('data.id');

        $data = $this->actingAs($teknisi)
            ->getJson("/api/calibrations/{$id}")
            ->assertOk()
            ->json('data');

        // --- sisi per-titik
        $baris = collect($data['pembacaan_mentah'] ?? [])
            ->where('titik_ke', 3)
            ->keyBy('peran_sensor');

        foreach (TimbanganMentah::PERAN_PEMBACAAN as $peran) {
            $this->assertArrayHasKey(
                $peran,
                $baris->all(),
                "Pembacaan `{$peran}` nggak ikut pulang — teknisi bakal mengetiknya ulang.",
            );
        }

        $nominal = collect($data['pembacaan_mentah'] ?? [])
            ->where('titik_ke', 3)
            ->where('peran_sensor', TimbanganMentah::PERAN_NOMINAL)
            ->sortBy('sensor_ke')
            ->values();

        $this->assertCount(2, $nominal, 'Slot nominal titik 3 nggak ikut pulang.');
        $this->assertEqualsWithDelta(20.0, (float) $nominal[0]['pembacaan'], self::TOLERANSI);
        $this->assertEqualsWithDelta(10.0, (float) $nominal[1]['pembacaan'], self::TOLERANSI);

        // --- sisi tingkat-sesi
        $spek = $data['spesifikasi_alat'] ?? [];

        foreach (['keterulangan', 'eksentrisitas'] as $blok) {
            $this->assertArrayHasKey($blok, $spek, "Blok `{$blok}` nggak ikut pulang.");
            $this->assertIsArray($spek[$blok], "Blok `{$blok}` pulang bukan sebagai objek.");
        }

        $this->assertCount(10, $spek['keterulangan']['maks']['mi'], 'Sepuluh pengulangan MAX nggak utuh.');
        $this->assertEqualsWithDelta(
            20.02,
            (float) $spek['eksentrisitas']['baca']['back'],
            self::TOLERANSI,
            'Angka eksentrisitas berubah waktu pulang.',
        );
        $this->assertSame('kg', $spek['varian_master'], 'Varian master nggak ikut pulang — hitung ulang bakal menebak.');
    }

    /**
     * `tipe_timbangan` memilih tabel anak timbangan, dan itu MENGGESER angka.
     *
     * Diadu lewat endpoint, bukan cuma di kalkulator: dropdown ini gampang
     * dianggap keterangan pasif, dan kalau nilainya berhenti diteruskan jalur
     * simpan, yang terjadi bukan error — koreksinya cuma meleset di digit yang
     * justru dilaporkan.
     */
    public function test_tipe_timbangan_diteruskan_dan_menggeser_angka(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $kirim = function (string $tipe) use ($alat, $teknisi): float {
            $payload = $this->payload($alat);
            $payload['spesifikasi_alat']['tipe_timbangan'] = $tipe;
            // Keping 0,02 kg ada di KEDUA tabel dengan massa konvensional beda.
            $payload['measurements'] = [
                ['titik_ukur' => 0.02, 'nominal' => [0.02], 'z1' => 0, 'm' => 0.02, 'm_aksen' => 0.02, 'z2' => 0],
            ];

            return (float) $this->actingAs($teknisi)
                ->postJson('/api/calibrations', $payload)
                ->assertCreated()
                ->json('data.titik.0.titik_ukur');
        };

        $this->assertNotEqualsWithDelta(
            $kirim('Non-Analytical'),
            $kirim('Analytical'),
            1e-12,
            '`tipe_timbangan` nggak nyampe ke pemilihan tabel — F1 & E2 memulangkan angka yang sama.',
        );
    }
}

<?php

namespace Tests\Feature;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\User;
use App\Services\Calibration\Profiles\TimbanganProfile;
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

    /**
     * **Payload yang benar-benar dikirim HP** — bukan bentuk kontrak yang cuma
     * dipakai seeder & test.
     *
     * Bedanya dua hal, dan dua-duanya bentuk GENERIK yang sudah dipakai dua
     * puluh lembar lain:
     *
     *  1. Empat pembacaan datang sebagai satu deret `pembacaan` menurut posisi
     *     kolomnya (z, m, m', z'), bukan empat kunci bernama. Layar HP
     *     menggambarnya sebagai empat kolom pengulangan, dan jalur kirim
     *     generiknya memang memulangkan deret.
     *  2. Blok keterulangan datang sebagai CERMINAN TABEL (`baris[]` dengan
     *     kode kolom `zero`/`pembacaan`), bukan `{mid, maks}` — nama slot itu
     *     kosakata master, dan menaruhnya di HP berarti daftar tulis-tangan di
     *     layar yang menggambar dua puluh lembar.
     *
     * Yang dijaga di sini: kedua bentuk itu mendarat pada angka yang SAMA
     * PERSIS dengan bentuk kontraknya. Kalau tidak, sesi dari lapangan diam-diam
     * menghitung nol keterulangan — `Sr` jatuh ke lantai resolusi, tidak ada
     * error di mana pun, dan sertifikatnya tetap terbit.
     */
    public function test_bentuk_kiriman_hp_sama_hasilnya_dengan_bentuk_kontrak(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $kontrak = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat))
            ->assertCreated()
            ->json('data.titik');

        $dariHp = $this->payload($alat, [
            'measurements' => [
                ['titik_ukur' => 10, 'nominal' => [10.0], 'pembacaan' => [0, 10, 10, 0]],
                ['titik_ukur' => 20, 'nominal' => [20.0], 'pembacaan' => [0, 20, 20, 0]],
                ['titik_ukur' => 30, 'nominal' => [20.0, 10.0], 'pembacaan' => [0, 30, 30, 0]],
            ],
        ]);
        $dariHp['spesifikasi_alat']['keterulangan'] = [
            'baris' => [
                ['titik_ukur' => 50, 'zero' => array_fill(0, 10, 0), 'pembacaan' => array_fill(0, 10, 50.02)],
                ['titik_ukur' => 100, 'zero' => array_fill(0, 10, 0), 'pembacaan' => array_fill(0, 10, 100.02)],
            ],
        ];

        $respons = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $dariHp)
            ->assertCreated();

        $hp = $respons->json('data.titik');

        $this->assertCount(count($kontrak), $hp, 'Jumlah titik yang dihitung beda.');

        foreach ($kontrak as $i => $titik) {
            foreach (['titik_ukur', 'koreksi', 'ketidakpastian_diperluas'] as $kolom) {
                $this->assertEqualsWithDelta(
                    (float) $titik[$kolom],
                    (float) $hp[$i][$kolom],
                    self::TOLERANSI,
                    "Titik {$i}: `{$kolom}` bentuk HP beda dari bentuk kontrak.",
                );
            }
        }

        // Yang TERSIMPAN wajib bentuk baku juga — jalur hitung ulang membaca
        // kolom itu apa adanya, jadi bentuk mentah yang lolos ke DB bakal
        // menghitung nol keterulangan tiap kali sesi ini dihitung lagi.
        $spek = CalibrationSession::findOrFail($respons->json('data.id'))->spesifikasi_alat;

        $this->assertArrayHasKey('mid', $spek['keterulangan']);
        $this->assertArrayHasKey('maks', $spek['keterulangan']);
        $this->assertArrayNotHasKey('baris', $spek['keterulangan']);
        $this->assertEqualsWithDelta(50.0, (float) $spek['keterulangan']['mid']['nominal'], self::TOLERANSI);
        $this->assertCount(10, $spek['keterulangan']['maks']['mi']);
    }

    /**
     * Kunci bernama MENANG kalau dikirim bareng deret — dan bukan sebaliknya.
     *
     * Kalau deretnya yang menang, sesi lama yang dibuka lagi di HP (isinya
     * kunci bernama) lalu dikirim ulang bakal menimpa pembacaannya dengan
     * deret kosong bawaan layar. Nol error, angka hilang.
     */
    public function test_kunci_bernama_menang_atas_deret_pembacaan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat, [
                'measurements' => [[
                    'titik_ukur' => 10,
                    'nominal' => [10.0],
                    'z1' => 0, 'm' => 10, 'm_aksen' => 10, 'z2' => 0,
                    'pembacaan' => [99, 99, 99, 99],
                ]],
            ]))
            ->assertCreated()
            ->json('data.id');

        $m = RawMeasurement::where('calibration_session_id', $id)
            ->where('peran_sensor', 'm')
            ->firstOrFail();

        $this->assertEqualsWithDelta(10.0, (float) $m->pembacaan, self::TOLERANSI);
    }

    /**
     * Deret yang baru sebagian terisi tidak menghapus yang sudah ada.
     *
     * Teknisi mengisi kolom nol duluan lalu menyimpan draft — kalau titiknya
     * kebuang karena `m` belum ada, yang hilang bukan cuma kiriman itu:
     * `store()` sudah menghapus baris lamanya sebelum penyusunan ini jalan.
     */
    public function test_deret_setengah_terisi_tetap_tersimpan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $id = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $this->payload($alat, [
                'measurements' => [
                    ['titik_ukur' => 10, 'nominal' => [10.0], 'pembacaan' => [0, null, null, null]],
                ],
            ]))
            ->assertCreated()
            ->json('data.id');

        $baris = RawMeasurement::where('calibration_session_id', $id)
            ->whereIn('peran_sensor', TimbanganMentah::PERAN_PEMBACAAN)
            ->get();

        $this->assertCount(1, $baris, 'Cuma kolom nol yang terisi, dan itu yang harus kesimpan.');
        $this->assertSame('z1', $baris[0]->peran_sensor);
    }

    /**
     * Empat blok isian lembar ini punya kode yang BENAR-BENAR bisa dikirim HP.
     *
     * Kode bertitik tanpa awalan `spesifikasi_alat.` dibaca HP sebagai kolom
     * TURUNAN: read-only, dan tidak pernah ikut payload
     * (`FieldLembarKerja.turunan`). Empat blok ini pernah begitu — tiga puluh
     * sembilan kotak yang digambar rapi, diisi teknisi dari kertas, lalu hilang
     * waktu tombol kirim ditekan. Tanpa error, di kedua sisi.
     *
     * Dua di antaranya MENGGERAKKAN ANGKA (eksentrisitas → komponen
     * Eccentricity, histeresis → angka Hysterisis), jadi yang hilang bukan
     * cuma catatan.
     */
    public function test_semua_kotak_blok_punya_kode_yang_bisa_dikirim(): void
    {
        $bentuk = (new TimbanganProfile)->bentukLembarKerja();

        $blok = ['scale_observation', 'effect_of_tare', 'eksentrisitas', 'histeresis'];
        $ketemu = [];

        foreach ($bentuk['bagian'] as $bagian) {
            if (! in_array($bagian['kode'], $blok, true)) {
                continue;
            }

            $this->assertNotEmpty($bagian['field'], "Bagian `{$bagian['kode']}` nggak punya kotak sama sekali.");

            foreach ($bagian['field'] as $f) {
                $this->assertStringStartsWith(
                    'spesifikasi_alat.',
                    $f['kode'],
                    "Kotak `{$f['kode']}` bakal read-only di HP dan isinya nggak pernah terkirim.",
                );
                $this->assertStringNotContainsString(
                    '*',
                    $f['kode'],
                    "Kode ber-wildcard `{$f['kode']}` itu idiom lembar cetak, bukan kunci payload.",
                );
            }

            $ketemu[] = $bagian['kode'];
        }

        $this->assertSame($blok, $ketemu, 'Ada blok yang hilang dari bentuk lembarnya.');
    }

    /**
     * Blok eksentrisitas & histeresis yang dikirim lewat kode-kode itu beneran
     * sampai ke mesin hitung — bukan cuma diterima 201.
     */
    public function test_blok_bersarang_dari_kode_field_sampai_ke_hitungan(): void
    {
        [$alat, $teknisi] = $this->siapkan();

        $payload = $this->payload($alat);
        // Persis bentuk yang lahir dari kode `spesifikasi_alat.histeresis.baca1.0`
        // dst waktu HP menyusunnya jadi peta bersarang.
        $payload['spesifikasi_alat']['histeresis'] = [
            'm' => 20, 'm_aksen' => 40,
            'baca1' => [20, 40, 20, 0, 40, 20, 0.02, 20],
            'baca2' => [20, 40, 20, 0.02, 40, 20, 0, 20],
        ];

        $respons = $this->actingAs($teknisi)
            ->postJson('/api/calibrations', $payload)
            ->assertCreated();

        $jejak = collect($respons->json('data.titik.0.type_b_components'));

        $this->assertTrue(
            $jejak->contains(fn (array $b): bool => ($b['keterangan'] ?? '') === 'Eccentricity'),
            'Komponen Eccentricity nggak muncul — blok eksentrisitasnya nggak kebaca.',
        );

        // Histeresis master: ((p1+p2+q1'+q2') − (…)) / 4 = 0,01 buat deret ini.
        $sesi = CalibrationSession::findOrFail($respons->json('data.id'));

        $this->assertSame(
            [20, 40, 20, 0, 40, 20, 0.02, 20],
            $sesi->spesifikasi_alat['histeresis']['baca1'],
            'Deret histeresis berubah bentuk waktu disimpan.',
        );
    }

    /**
     * Tabel Repeatability dikirim dalam bentuk KERTASNYA, bukan transposed.
     *
     * Bentuk kertas master: nomor `1`..`10` TURUN di kolom `No.`, dua kapasitas
     * berjajar KE SAMPING, masing-masing dengan sub-kolom `Zero (…)` &
     * `Reading (…)`. Draf pertama mengirimnya terbalik.
     *
     * Bukan soal selera tata letak — pemeta foto di HP menjangkar tiap angka ke
     * dua sumbu, dan dua-duanya diambil dari tulisan yang TERCETAK. Dijalankan
     * pada bentuk transposed, kedua jangkarnya ada di sumbu yang salah dan
     * tiap jepretan pulang nol sel.
     */
    public function test_bentuk_keterulangan_ikut_kertas_bukan_transposed(): void
    {
        $tabel = $this->tabelKeterulangan();

        $this->assertSame('baris', $tabel['sumbu_pengulangan'], 'Pengulangan harus TURUN.');
        $this->assertCount(10, $tabel['pengulangan']);
        $this->assertCount(2, $tabel['slot_cetak'], 'Dua kapasitas berjajar ke samping.');

        $this->assertSame(
            ['Middle Capacity', 'Maximum Capacity'],
            array_column($tabel['slot_cetak'], 'label'),
            'Urutan slot mengikat: HP memasangkannya ke baris lewat urutan itu.',
        );

        // Nomor pengulangan seperti tercetak — `1`..`10` polos, bukan `X1`.
        $this->assertSame(
            array_map('strval', range(1, 10)),
            array_column($tabel['pengulangan_arah'], 'label'),
        );
    }

    /**
     * Label sub-kolom membawa SATUANNYA, dan itu yang menjaga gram tidak
     * ketukar dengan kilogram.
     *
     * Pemeta foto memakai tulisan ini sebagai jangkar sub-kolom. Kertas gram
     * menulis `Zero (g)`; sesi kilogram mencari `Zero (kg)`. Jadi lembar yang
     * salah satuan pulang NOL sel — gagal berisik, bukan memindahkan
     * `24,9999 g` ke kotak kilogram.
     *
     * `satuan` per slot sengaja TIDAK dikirim: HP memakai
     * `slot.satuan ?? kolom.label` buat jangkar sub-kolom, jadi mengisinya
     * bikin `Zero` dan `Reading` berjangkar tulisan yang sama.
     */
    public function test_label_sub_kolom_membawa_satuan_yang_benar(): void
    {
        foreach ([['kg', 'kg'], ['g', 'g']] as [$satuanAlat, $tercetak]) {
            $alat = new Equipment(['satuan' => $satuanAlat, 'range_max' => 100.0]);
            $tabel = $this->tabelKeterulangan($alat);

            $this->assertSame(
                ["Zero ({$tercetak})", "Reading ({$tercetak})"],
                array_column($tabel['kolom'], 'label'),
            );

            foreach ($tabel['slot_cetak'] as $slot) {
                $this->assertArrayNotHasKey(
                    'satuan',
                    $slot,
                    'Slot ber-`satuan` bikin dua sub-kolomnya berjangkar tulisan yang sama.',
                );
            }
        }
    }

    /**
     * Kapasitas uji keterulangan DIKETIK, bukan diturunkan dari rentang alat.
     *
     * Master GRAM yang membuktikan: alatnya berkapasitas 54 g dan
     * keterulangannya diambil di 25 g & 50 g — bukan 27 g & 54 g. Angka itu
     * masuk rumus lewat `deviasiKurangiNominal` (nyala di varian gram DAN
     * substitusi) dan lewat `srTerdekat()`, jadi yang meleset bukan cuma
     * labelnya.
     */
    public function test_kapasitas_keterulangan_punya_kotak_isian(): void
    {
        $bagian = $this->bagianKeterulangan();

        $this->assertSame(
            [
                'spesifikasi_alat.keterulangan.mid.nominal',
                'spesifikasi_alat.keterulangan.maks.nominal',
            ],
            array_column($bagian['field'], 'kode'),
        );
    }

    /**
     * Cuma tabel Repeatability yang boleh difoto — Accuracy tidak.
     *
     * Di kertas master, blok Accuracy daftar MENURUN (`z1`, `m1`, `m1'`, `z2`,
     * …), bukan grid; pembedanya tulisan per baris, bukan nomor kolom. Tombol
     * yang nyala di situ balik nol sel tiap jepretan, dan yang sampai ke
     * teknisi *"tabelnya dikenali, tapi selnya masih kosong"*.
     */
    public function test_kamera_nyala_cuma_di_tabel_yang_bentuknya_muat(): void
    {
        $bentuk = (new TimbanganProfile)->bentukLembarKerja();
        $pindai = [];

        foreach ($bentuk['bagian'] as $bagian) {
            foreach ($bagian['tabel'] ?? [] as $tabel) {
                $pindai[$bagian['kode']] = $tabel['pindai_foto'] ?? null;
            }
        }

        $this->assertSame(['akurasi' => false, 'keterulangan' => true], $pindai);

        // Jalur CLOUD tetap mati — dia mengirim foto lembar kerja pelanggan ke
        // layanan pihak ketiga, dan tidak ada yang meminta itu buat lembar ini.
        $gerbang = (new TimbanganProfile)->bentukPindaiFoto();

        $this->assertFalse($gerbang['didukung']);
        $this->assertTrue($gerbang['lokal']);
    }

    /** Tabel keterulangan dari bentuk lembar, buat alat contoh. */
    private function tabelKeterulangan(?Equipment $alat = null): array
    {
        return $this->bagianKeterulangan($alat)['tabel'][0];
    }

    /** @return array<string, mixed> */
    private function bagianKeterulangan(?Equipment $alat = null): array
    {
        $alat ??= new Equipment(['satuan' => 'kg', 'range_max' => 100.0]);
        $bentuk = (new TimbanganProfile)->bentukLembarKerja(false, $alat);

        foreach ($bentuk['bagian'] as $bagian) {
            if ($bagian['kode'] === 'keterulangan') {
                return $bagian;
            }
        }

        $this->fail('Bagian `keterulangan` nggak ada di bentuk lembarnya.');
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

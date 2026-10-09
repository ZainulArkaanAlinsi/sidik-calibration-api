<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Ocr\TemplateLembarKerja;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Lembar kerja kelompok SUHU ikut KERTAS formulir lab — dan datanya tidak ikut
 * bergeser.
 *
 * ## Dua janji, diuji terpisah
 *
 * Revisi 9 Okt 2026 (pola Anak Timbangan) mengganti label, judul bagian, kepala
 * kolom, dan urutan bagian supaya layar bisa diadu langsung dengan formulir
 * `SIDIK-FM-CAL-0504/0505/0506/0525/0535/0537/0539`. Itu TAMPILAN. Yang tidak
 * boleh ikut berubah: kode field, identitas tabel, titik ukur, dan kunci sel —
 * keempatnya kontrak payload HP, jalur OCR, dan sesi yang sudah tersimpan.
 *
 * Kedua daftar di bawah (`KODE_FIELD`, `TABEL`) direkam dari bentuk lembar
 * SEBELUM revisi tampilan (commit `19c7b1a`), bukan diketik dari ingatan.
 * Kalau merah: yang berubah kontrak data, bukan tampilan. Jangan menyalin
 * keluaran baru ke sini supaya hijau — putuskan dulu apakah perubahan datanya
 * memang disengaja, karena sesi lama dan geometri OCR ikut terkena.
 *
 * Kunci sel tidak ditulis satu per satu: kunci = `tabel|baris_ke|ulangan|kolom`,
 * jadi `tabel_id`, jumlah baris, daftar ulangan, dan kolom di `TABEL` sudah
 * menentukannya persis. Pasangannya dengan berkas geometri dijaga
 * `CetakLembarKerjaOcrTest::test_kunci_sel_di_kertas_sama_persis_dengan_yang_dikenal_server`.
 */
class LembarKerjaIkutKertasSuhuTest extends TestCase
{
    use RefreshDatabase;

    /** Kelima lembar Enclosure berbagi satu bentuk — bedanya cuma CMC. */
    private const KODE_ENCLOSURE = [
        'alat_merk', 'alat_model', 'alat_serial_number', 'calibration_method_id', 'catatan_teknisi',
        'dimensi.volume', 'dimensi_jari_jari', 'dimensi_lebar', 'dimensi_panjang', 'dimensi_tinggi',
        'dimensi_tinggi_silinder', 'equipment.nama_alat', 'equipment_id', 'kelembaban_akhir',
        'kelembaban_awal', 'lokasi', 'lokasi_nama', 'pemilik_alamat', 'pemilik_nama', 'persyaratan_alat',
        'reviewer.nama', 'room_id', 'spesifikasi_alat.kapasitas', 'spesifikasi_alat.rentang_ukur',
        'spesifikasi_alat.resolusi', 'standar_dicek.*.dipakai', 'standar_dicek.*.keterangan', 'suhu_akhir',
        'suhu_awal', 'tanggal_kalibrasi', 'tanggal_terima', 'teknisi.nama', 'thermohygro_standard_id',
        'tipe_sensor',
    ];

    /**
     * Semua node ber-`kode` + `tipe` (field bagian, kolom tabel, kolom per
     * baris, baris matriks) dari bentuk ADMIN — multiset, terurut.
     *
     * @var array<string, list<string>>
     */
    private const KODE_FIELD = [
        'thermocouple' => [
            'alat_bantu', 'alat_merk', 'alat_model', 'alat_serial_number', 'calibration_method_id',
            'catatan_teknisi', 'certificate.nomor', 'equipment.nama_alat', 'equipment_id', 'kelembaban_akhir',
            'kelembaban_awal', 'kelembaban_ketidakpastian', 'lokasi', 'lokasi_nama', 'no_probe', 'nomor_order',
            'pembacaan', 'pembacaan', 'pemilik_alamat', 'pemilik_nama', 'reviewer.nama', 'room_id',
            'spesifikasi_alat.kapasitas', 'spesifikasi_alat.rentang_ukur', 'spesifikasi_alat.resolusi',
            'spesifikasi_alat.tipe_thermocouple', 'spesifikasi_alat.tipe_thermocouple_lain',
            'standar_dicek.*.dipakai', 'standar_dicek.*.keterangan', 'suhu_akhir', 'suhu_awal',
            'suhu_ketidakpastian', 'tanggal_kalibrasi', 'tanggal_terima', 'teknisi.nama',
            'thermohygro_standard_id', 'tipe_sensor',
        ],
        'thermometer_glass' => [
            'alat_bantu', 'alat_merk', 'alat_model', 'alat_serial_number', 'calibration_method_id',
            'catatan_teknisi', 'certificate.nomor', 'equipment.nama_alat', 'equipment_id', 'kelembaban_akhir',
            'kelembaban_awal', 'kelembaban_ketidakpastian', 'lokasi', 'lokasi_nama', 'nomor_order',
            'pembacaan', 'pembacaan', 'pemilik_alamat', 'pemilik_nama', 'reviewer.nama', 'room_id',
            'spesifikasi_alat.kapasitas', 'spesifikasi_alat.rentang_ukur', 'spesifikasi_alat.resolusi',
            'standar_dicek.*.dipakai', 'standar_dicek.*.keterangan', 'suhu_akhir', 'suhu_awal',
            'suhu_ketidakpastian', 'tanggal_kalibrasi', 'tanggal_terima', 'teknisi.nama',
            'thermohygro_standard_id', 'tipe_pencelupan', 'titik_es_1', 'titik_es_2', 'titik_es_3',
        ],
        'thermohygro' => [
            'alat_merk', 'alat_model', 'alat_serial_number', 'calibration_method_id', 'catatan_teknisi',
            'certificate.nomor', 'equipment.nama_alat', 'equipment_id', 'kelembaban_akhir', 'kelembaban_awal',
            'kelembaban_ketidakpastian', 'lokasi', 'lokasi_nama', 'nomor_order', 'pembacaan', 'pembacaan',
            'pembacaan', 'pembacaan', 'pemilik_alamat', 'pemilik_nama', 'reviewer.nama', 'room_id',
            'spesifikasi_alat.kapasitas', 'spesifikasi_alat.kapasitas_kelembaban',
            'spesifikasi_alat.rentang_ukur', 'spesifikasi_alat.rentang_ukur_kelembaban',
            'spesifikasi_alat.resolusi', 'spesifikasi_alat.resolusi_kelembaban', 'standar_dicek.*.dipakai',
            'standar_dicek.*.keterangan', 'suhu_akhir', 'suhu_awal', 'suhu_ketidakpastian',
            'tanggal_kalibrasi', 'tanggal_terima', 'teknisi.nama', 'thermohygro_standard_id',
        ],
        'tits' => [
            'alat_merk', 'alat_model', 'alat_serial_number', 'calibration_method_id', 'catatan_teknisi',
            'certificate.nomor', 'equipment.nama_alat', 'equipment_id', 'kelembaban_akhir', 'kelembaban_awal',
            'kelembaban_ketidakpastian', 'lokasi', 'lokasi_nama', 'mode_kalibrasi', 'nomor_order', 'pembacaan',
            'pembacaan', 'pemilik_alamat', 'pemilik_nama', 'reviewer.nama', 'room_id',
            'spesifikasi_alat.kapasitas', 'spesifikasi_alat.rentang_ukur', 'spesifikasi_alat.resolusi',
            'standar_dicek.*.dipakai', 'standar_dicek.*.keterangan', 'suhu_akhir', 'suhu_awal',
            'suhu_ketidakpastian', 'tanggal_kalibrasi', 'tanggal_terima', 'teknisi.nama',
            'thermohygro_standard_id', 'tipe_sensor',
        ],
        'tids' => [
            'alat_merk', 'alat_model', 'alat_serial_number', 'calibration_method_id', 'catatan_teknisi',
            'certificate.nomor', 'equipment.nama_alat', 'equipment_id', 'kelembaban_akhir', 'kelembaban_awal',
            'kelembaban_ketidakpastian', 'lokasi', 'lokasi_nama', 'no_probe', 'nomor_order', 'pembacaan',
            'pembacaan', 'pemilik_alamat', 'pemilik_nama', 'reviewer.nama', 'room_id',
            'spesifikasi_alat.dryblock', 'spesifikasi_alat.kapasitas', 'spesifikasi_alat.rentang_ukur',
            'spesifikasi_alat.resolusi', 'standar_dicek.*.dipakai', 'standar_dicek.*.keterangan', 'suhu_akhir',
            'suhu_awal', 'suhu_ketidakpastian', 'tanggal_kalibrasi', 'tanggal_terima', 'teknisi.nama',
            'thermohygro_standard_id', 'tipe_sensor', 'titik_es_1', 'titik_es_2',
        ],
        'bath' => self::KODE_ENCLOSURE,
        'furnace' => self::KODE_ENCLOSURE,
        'inkubator' => self::KODE_ENCLOSURE,
        'oven' => self::KODE_ENCLOSURE,
        'refrigerator' => self::KODE_ENCLOSURE,
        'autoclave' => [
            'alat_merk', 'alat_model', 'alat_serial_number', 'calibration_method_id', 'catatan_teknisi',
            'certificate.nomor', 'disk_1', 'disk_2', 'disk_3', 'display_tekanan', 'equipment.nama_alat',
            'equipment_id', 'indikator_pressure', 'indikator_suhu', 'kelembaban_akhir', 'kelembaban_awal',
            'lokasi', 'lokasi_nama', 'nomor_order', 'pemilik_alamat', 'pemilik_nama', 'range_suhu',
            'range_tekanan', 'resolusi_suhu', 'resolusi_tekanan', 'reviewer.nama', 'reviewer.tanda_tangan',
            'room_id', 'satuan_tekanan', 'set_point', 'standar_dicek.*.dipakai', 'standar_dicek.*.keterangan',
            'suhu.resolusi_alat', 'suhu_akhir', 'suhu_awal', 'suhu_ruang', 'tanggal_kalibrasi',
            'tanggal_terima', 'tekanan.resolusi_alat', 'tekanan.uut_setting', 'tekanan_atm_awal',
            'teknisi.nama', 'teknisi.tanda_tangan', 'thermohygro_standard_id', 'waktu',
        ],
    ];

    /**
     * Tabel template OCR: `tabel_id|jumlah baris|titik tiap baris|ulangan|kolom`.
     *
     * Titik 0 di TIDS/Enclosure/Autoclave = baris tanpa nominal tercetak (set
     * point ditulis teknisi), bukan set point nol.
     *
     * @var array<string, list<string>>
     */
    private const TABEL = [
        'thermocouple' => [
            'standar|6|50,100,150,200,400,600|1,2,3,4,5|pembacaan',
            'uut|6|50,100,150,200,400,600|1,2,3,4,5|pembacaan',
        ],
        'thermometer_glass' => [
            'standar|5|30,50,60,80,100|1,2,3,4,5|pembacaan',
            'uut|5|30,50,60,80,100|1,2,3,4,5|pembacaan',
        ],
        'thermohygro' => [
            'suhu_standar|5|15,25,35,45,50|1,2,3,4,5|pembacaan',
            'suhu_uut|5|15,25,35,45,50|1,2,3,4,5|pembacaan',
            'kelembaban_standar|5|30,49,50,70,90|1,2,3,4,5|pembacaan',
            'kelembaban_uut|5|30,49,50,70,90|1,2,3,4,5|pembacaan',
        ],
        'tits' => [
            'sebelum_adjustment|9|-20,10,50,100,200,400,600,800,1000|1,2,3,4,5,6|pembacaan',
            'sesudah_adjustment|9|-20,10,50,100,200,400,600,800,1000|1,2,3,4,5,6|pembacaan',
        ],
        'tids' => [
            'pembacaan_standard|7|0,0,0,0,0,0,0|1,2,3,4,5|pembacaan',
            'pembacaan_uut|7|0,0,0,0,0,0,0|1,2,3,4,5|pembacaan',
        ],
        'bath' => ['grid|11|0,0,0,0,0,0,0,0,0,0,0|1,2,3,4,5|pembacaan'],
        'furnace' => ['grid|11|0,0,0,0,0,0,0,0,0,0,0|1,2,3,4,5|pembacaan'],
        'inkubator' => ['grid|11|0,0,0,0,0,0,0,0,0,0,0|1,2,3,4,5|pembacaan'],
        'oven' => ['grid|11|0,0,0,0,0,0,0,0,0,0,0|1,2,3,4,5|pembacaan'],
        'refrigerator' => ['grid|11|0,0,0,0,0,0,0,0,0,0,0|1,2,3,4,5|pembacaan'],
        'autoclave' => ['sesudah_adjustment|8|0,0,0,0,0,0,0,0|1,2,3,4,5|pembacaan'],
    ];

    /** @return array<string, array{string}> */
    public static function lembarSuhu(): array
    {
        $hasil = [];

        foreach (array_keys(self::KODE_FIELD) as $kode) {
            $hasil[$kode] = [$kode];
        }

        return $hasil;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
    }

    // ------------------------------------------------- data TIDAK bergeser

    #[DataProvider('lembarSuhu')]
    public function test_kode_field_tidak_bergeser(string $kode): void
    {
        $ada = $this->kodeBertipe($this->profil($kode)->bentukLembarKerja(true));
        $harap = self::KODE_FIELD[$kode];

        sort($ada, SORT_STRING);
        sort($harap, SORT_STRING);

        $this->assertSame(
            $harap,
            $ada,
            "Kode field lembar {$kode} berubah. Revisi ikut-kertas cuma boleh mengganti label, judul, "
            .'dan urutan — kode field itu kunci payload HP dan kolom sesi yang sudah tersimpan.',
        );
    }

    #[DataProvider('lembarSuhu')]
    public function test_tabel_titik_dan_kunci_sel_tidak_bergeser(string $kode): void
    {
        $template = app(TemplateLembarKerja::class)->dariProfil($this->profil($kode));

        $ringkas = array_map(
            static fn (array $t): string => implode('|', [
                $t['tabel_id'],
                count($t['baris']),
                implode(',', array_map(
                    static fn (array $b): string => (string) (0 + $b['titik_ukur']),
                    $t['baris'],
                )),
                implode(',', $t['pengulangan']),
                implode(',', array_column($t['kolom'], 'field_id')),
            ]),
            $template['tabel'],
        );

        $this->assertSame(
            self::TABEL[$kode],
            $ringkas,
            "Tabel/titik/kunci sel lembar {$kode} bergeser. Kunci sel = tabel|baris|ulangan|kolom; "
            .'menggesernya bikin kertas cetak & berkas geometri OCR menunjuk sel yang salah.',
        );

        $jumlah = 0;
        foreach ($template['tabel'] as $t) {
            $jumlah += count($t['baris']) * count($t['pengulangan']) * count($t['kolom']);
        }

        $this->assertCount($jumlah, $template['sel']);
    }

    // ------------------------------------------------------- ikut kertas

    /**
     * Kop yang sama di tiga lembar pasangan (`ProfilSuhuPasangan`).
     *
     * @return array<string, array{string, array<string, string>, string}>
     */
    public static function lembarPasangan(): array
    {
        $umum = [
            'equipment.nama_alat' => 'Nama Alat',
            'alat_merk' => 'Merk',
            'alat_model' => 'Type',
            'alat_serial_number' => 'No. Seri',
            'lokasi' => 'Lokasi Kalibrasi',
            'tanggal_terima' => 'Tgl. Diterima',
            'tanggal_kalibrasi' => 'Tgl. Kalibrasi',
            'suhu_awal' => 'Suhu Ruangan — awal',
            'suhu_akhir' => 'Suhu Ruangan — akhir',
            'kelembaban_awal' => 'Kelembapan — awal',
            'kelembaban_akhir' => 'Kelembapan — akhir',
            'thermohygro_standard_id' => 'Thermohygro used',
        ];

        return [
            // SIDIK-FM-CAL-0535_Rev.2
            'thermocouple' => ['thermocouple', [
                ...$umum,
                'spesifikasi_alat.rentang_ukur' => 'Rentang Ukur',
                'spesifikasi_alat.kapasitas' => 'Kapasitas Alat',
                'spesifikasi_alat.resolusi' => 'Resolusi Alat',
                'spesifikasi_alat.tipe_thermocouple' => 'Tipe Thermocouple',
            ], 'Nama Cust.'],
            // SIDIK-FM-CAL-0537_Rev.2
            'thermometer_glass' => ['thermometer_glass', [
                ...$umum,
                'spesifikasi_alat.rentang_ukur' => 'Rentang Ukur',
                'spesifikasi_alat.kapasitas' => 'Kapasitas Alat',
                'spesifikasi_alat.resolusi' => 'Resolusi Alat',
                'tipe_pencelupan' => 'Tipe Thermo Glass',
            ], 'Nama Customer'],
            // SIDIK-FM-CAL-0525 (kaki halaman Revise : 2)
            'thermohygro' => ['thermohygro', [
                ...$umum,
                'spesifikasi_alat.rentang_ukur' => 'Rentang Ukur Temp.',
                'spesifikasi_alat.kapasitas' => 'Kapasitas Temp.',
                'spesifikasi_alat.resolusi' => 'Resolusi Temp.',
                'spesifikasi_alat.rentang_ukur_kelembaban' => 'Rentang Ukur Humi.',
                'spesifikasi_alat.kapasitas_kelembaban' => 'Kapasitas Humi.',
                'spesifikasi_alat.resolusi_kelembaban' => 'Resolusi Humi.',
            ], 'Nama Customer'],
        ];
    }

    /**
     * Kertas ketiganya mencetak Suhu Ruangan, Kelembapan, Lokasi, dan
     * Thermohygro used DI DALAM blok "Identitas Alat dan Data Customer" — dulu
     * tersebar ke PENGERJAAN dan DATA HASIL KALIBRASI.
     *
     * @param  array<string, string>  $labelIdentitas
     */
    #[DataProvider('lembarPasangan')]
    public function test_kop_lembar_pasangan_ikut_kertas(string $kode, array $labelIdentitas, string $namaCustomer): void
    {
        $bagian = $this->bagian($kode);

        $this->assertSame('Identitas Alat dan Data Customer', $bagian['identitas_alat']['judul']);

        $label = $this->labelPerKode($bagian['identitas_alat']);

        foreach ($labelIdentitas as $kodeField => $teks) {
            $this->assertSame(
                $teks,
                $label[$kodeField] ?? null,
                "Lembar {$kode}: `{$kodeField}` wajib di blok identitas berlabel persis kertas.",
            );
        }

        $this->assertSame($namaCustomer, $this->labelPerKode($bagian['pemilik'])['pemilik_nama']);
        $this->assertSame(
            ['Catatan', 'Dikalibrasi Oleh', 'Diperiksa Oleh'],
            array_column($bagian['penutup']['field'], 'label'),
        );
    }

    /**
     * Kepala kolom kertas FM-0535/0537 menulis `X1`…`X5` di KEDUA tabel — detik
     * bacanya (0″/10″/…) urutan kerja, bukan tulisan kertas. Thermohygro
     * FM-0525 menulis `STD1`…`STD5` / `UUT1`…`UUT5`.
     */
    public function test_kepala_kolom_pasangan_ikut_kertas(): void
    {
        $x = ['X1', 'X2', 'X3', 'X4', 'X5'];

        foreach (['thermocouple' => 'Setpoint', 'thermometer_glass' => 'Set Point'] as $kode => $judulNilai) {
            $tabel = $this->tabel($kode);

            $this->assertSame(['Pembacaan Standard', 'Pembacaan Alat yang Dikalibrasi'], array_column($tabel, 'judul'));

            foreach ($tabel as $t) {
                $this->assertSame($x, array_column($t['pengulangan_arah'], 'label'), "Kepala kolom {$kode}");
                $this->assertSame($judulNilai, $t['judul_nilai']);
                $this->assertSame('Data Kalibrasi/Ulangan (°C)', $t['judul_pengulangan']);
            }
        }

        $tabel = collect($this->tabel('thermohygro'))->keyBy('grup');

        foreach (['suhu_standar', 'kelembaban_standar'] as $grup) {
            $this->assertSame(['STD1', 'STD2', 'STD3', 'STD4', 'STD5'], array_column($tabel[$grup]['pengulangan_arah'], 'label'));
        }

        foreach (['suhu_uut', 'kelembaban_uut'] as $grup) {
            $this->assertSame(['UUT1', 'UUT2', 'UUT3', 'UUT4', 'UUT5'], array_column($tabel[$grup]['pengulangan_arah'], 'label'));
        }

        $this->assertSame('Alat (°C)', $tabel['suhu_standar']['judul_nilai']);
        $this->assertSame('Alat (%RH)', $tabel['kelembaban_standar']['judul_nilai']);
    }

    /**
     * Isian penentu angka yang TIDAK tercetak di kertas tetap ada, ditandai
     * `di_luar_kertas`, dan dikumpulkan di satu blok sebelum tanda tangan.
     *
     * Kolom `No. Termokopel` tinggal di tabelnya (dia kolom per baris, bukan
     * isian lepas) — cuma ditandai.
     *
     * @return array<string, array{string, list<string>, list<string>}>
     */
    public static function isianDiLuarKertas(): array
    {
        $enclosure = ['calibration_method_id'];

        return [
            'thermocouple' => ['thermocouple', ['alat_bantu'], ['no_probe']],
            'thermometer_glass' => ['thermometer_glass', ['alat_bantu'], []],
            'tits' => ['tits', ['mode_kalibrasi', 'spesifikasi_alat.rentang_ukur', 'calibration_method_id'], []],
            'tids' => ['tids', ['calibration_method_id'], ['no_probe']],
            'bath' => ['bath', $enclosure, []],
            'furnace' => ['furnace', $enclosure, []],
            'inkubator' => ['inkubator', $enclosure, []],
            'oven' => ['oven', $enclosure, []],
            'refrigerator' => ['refrigerator', $enclosure, []],
        ];
    }

    /**
     * @param  list<string>  $field
     * @param  list<string>  $kolomBaris
     */
    #[DataProvider('isianDiLuarKertas')]
    public function test_isian_di_luar_kertas_ditandai_dan_dikumpulkan(string $kode, array $field, array $kolomBaris): void
    {
        $bentuk = $this->profil($kode)->bentukLembarKerja();
        $urutan = array_column($bentuk['bagian'], 'kode');
        $bagian = collect($bentuk['bagian'])->keyBy('kode');

        $blok = $bagian['data_kalibrasi'] ?? null;

        $this->assertNotNull($blok, "Lembar {$kode} nggak punya blok isian di luar kertas.");
        $this->assertTrue($blok['di_luar_kertas'] ?? false);
        $this->assertSame('Di luar kertas', $blok['judul']);
        $this->assertSame($field, array_column($blok['field'], 'kode'));

        foreach ($blok['field'] as $f) {
            $this->assertTrue($f['di_luar_kertas'] ?? false, "Isian `{$f['kode']}` belum ditandai di luar kertas.");
        }

        // Tepat sebelum tanda tangan — sesudah semua bagian yang tercetak.
        $this->assertSame(['data_kalibrasi', 'penutup'], array_slice($urutan, -2));

        $kolom = collect($bentuk['bagian'])
            ->flatMap(static fn (array $b): array => $b['tabel'] ?? [])
            ->flatMap(static fn (array $t): array => $t['kolom_baris'] ?? []);

        foreach ($kolomBaris as $kodeKolom) {
            $f = $kolom->firstWhere('kode', $kodeKolom);
            $this->assertNotNull($f, "Kolom `{$kodeKolom}` hilang dari tabel {$kode}.");
            $this->assertTrue($f['di_luar_kertas'] ?? false, "Kolom `{$kodeKolom}` belum ditandai di luar kertas.");
        }
    }

    /**
     * Kartu per set point cuma untuk tabel yang memang cocok bentuknya.
     *
     * Termometer Gelas: dua deret sebaris tanpa kolom per baris — sama persis
     * dengan Thermohygro yang sudah memakainya. Thermocouple & TIDS SENGAJA
     * tidak: kartu di HP melewati kolom `no_probe` (`lembar_kerja_kartu_baris.dart`,
     * `if (f.kode != 'no_probe')`), dan nomor termokopel itu yang memilih tabel
     * koreksi. TITS juga tidak: tabel Before-nya dilipat (keputusan 6 Okt 2026)
     * dan baris UP/DOWN per set point butuh widget HP baru.
     */
    public function test_tampilan_kartu_cuma_di_tabel_yang_cocok(): void
    {
        $glass = collect($this->profil('thermometer_glass')->bentukLembarKerja()['bagian'])->keyBy('kode');

        $this->assertSame('kartu_per_set_point', $glass['hasil']['tampilan'] ?? null);
        $this->assertTrue($glass['hasil']['kartu_sejajar'] ?? false);
        $this->assertFalse($glass['hasil']['nominal_berbintang'] ?? true);

        foreach (['thermocouple', 'tids', 'tits'] as $kode) {
            foreach ($this->profil($kode)->bentukLembarKerja()['bagian'] as $b) {
                $this->assertArrayNotHasKey('tampilan', $b, "Lembar {$kode} bagian {$b['kode']} nggak boleh berkartu.");
            }
        }
    }

    // ------------------------------------------------------------ bantu

    private function profil(string $kode): CalibrationProfile
    {
        $profil = app(CalibrationProfileRegistry::class)->untukKode($kode);
        $this->assertNotNull($profil, "Profil {$kode} nggak terdaftar.");

        return $profil;
    }

    /** @return array<string, array<string, mixed>> */
    private function bagian(string $kode): array
    {
        return collect($this->profil($kode)->bentukLembarKerja()['bagian'])->keyBy('kode')->all();
    }

    /** @return list<array<string, mixed>> */
    private function tabel(string $kode): array
    {
        return collect($this->profil($kode)->bentukLembarKerja()['bagian'])
            ->flatMap(static fn (array $b): array => $b['tabel'] ?? [])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $bagian
     * @return array<string, string>
     */
    private function labelPerKode(array $bagian): array
    {
        return array_column($bagian['field'] ?? [], 'label', 'kode');
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private function kodeBertipe(array $node): array
    {
        $hasil = [];

        if (isset($node['kode'], $node['tipe']) && is_string($node['kode'])) {
            $hasil[] = $node['kode'];
        }

        foreach ($node as $anak) {
            if (is_array($anak)) {
                $hasil = [...$hasil, ...$this->kodeBertipe($anak)];
            }
        }

        return $hasil;
    }
}

<?php

namespace App\Services;

use App\Models\Standard;

/**
 * Bentuk baku Lembar Kerja pH Meter (SIDIK-FM-CAL-0509_Rev.4).
 *
 * Ini definisi FORMULIRNYA, bukan datanya — dipakai layar input teknisi biar
 * susunan kolomnya persis kertas yang selama ini mereka pakai di lapangan.
 * Ditaruh di backend, bukan di-hardcode di mobile, karena kalau formulirnya
 * direvisi (Rev.5, dst) yang berubah cukup satu tempat, dan versi mobile lama
 * nggak jadi nampilin kolom yang udah nggak ada.
 *
 * ATURAN PENTING: `wajib` di sini SELALU false. Teknisi di lapangan sering
 * ketemu kondisi yang bikin satu-dua kolom nggak bisa diisi (thermohygro nggak
 * ada, larutan buffer habis, alat mati di tengah jalan). Nahan tombol kirim di
 * situ cuma bikin data hilang sama sekali — yang jauh lebih rugi daripada data
 * yang kurang lengkap. Penjagaannya ada di penerbitan sertifikat
 * (`CalibrationValidator`), bukan di formulirnya.
 */
class LembarKerjaTemplate
{
    public const KODE_DOKUMEN = 'SIDIK-FM-CAL-0509_Rev.4';

    /** Kolom "Repeat" di lembar kerja: 1 sampai 5. */
    public const JUMLAH_PENGULANGAN = 5;

    /**
     * Nilai larutan standar yang tercetak di lembar kerja pH. Dipakai sebagai
     * baris awal tabel hasil — teknisi tinggal ngisi pembacaannya.
     */
    public const LARUTAN_STANDAR_PH = [4.00, 7.00, 10.01];

    /**
     * Tabel "STANDARD" di lembar kerja pH — LIMA baris yang udah tercetak di
     * formulirnya, bukan katalog standar lab.
     *
     * `cocok` dipakai nyocokin baris ke master `standards` (nama ATAU serial),
     * supaya baris yang ketemu bawa `standard_id` — dari situ sertifikat dapat
     * lot & ketertelusurannya. Yang nggak ketemu tetap tampil, `standard_id`
     * null, dan itu jadi penanda buat admin: standar ini belum didaftarin di
     * master.
     */
    public const STANDARD_TERCETAK = [
        ['label' => 'pH Buffer Solutions 4', 'cocok' => ['pH Buffer Solution 4']],
        ['label' => 'pH Buffer Solutions 7', 'cocok' => ['pH Buffer Solution 7']],
        ['label' => 'pH Buffer Solutions 10', 'cocok' => ['pH Buffer Solution 10']],
        ['label' => 'RTD Sensor/SH1/20', 'cocok' => ['Termometer & Sensor Std.', 'SH1/20', '23P1005']],
        ['label' => 'Victor 14+/992613877', 'cocok' => ['Victor 14+', '992613877']],
    ];

    /**
     * Unit thermohygro yang TERCETAK di lembar kerja pH, dikelompokkan sesuai
     * kotak centangnya: Insitu (dibawa ke lokasi pelanggan) vs Inlab.
     */
    public const THERMOHYGRO_TERCETAK = [
        ['label' => 'TH-2', 'grup' => 'Insitu'],
        ['label' => 'TH-6', 'grup' => 'Insitu'],
        ['label' => 'TH-7', 'grup' => 'Insitu'],
        ['label' => 'TH-4', 'grup' => 'Inlab'],
    ];

    /**
     * @param  bool  $untukAdmin  true = tampilan admin (superset), false = tampilan teknisi
     * @return array<string, mixed>
     */
    public function phMeter(bool $untukAdmin = false): array
    {
        $bentuk = $this->bentukLengkap();
        $bentuk = $this->tautkanStandar($bentuk);
        $bentuk = $this->isiPilihanThermohygro($bentuk);

        if ($untukAdmin) {
            // Admin dapat semuanya: kolom lembar kerja + kolom administratif
            // yang emang cuma dia yang boleh isi.
            $bentuk['bagian'][] = $this->bagianAdmin();
            $bentuk['untuk'] = 'admin';

            return $bentuk;
        }

        // Tampilan teknisi = PERSIS kolom di lembar kerja, nggak lebih. Field
        // administratif dibuang di sini, bukan cuma disembunyiin CSS — supaya
        // layar teknisi nggak mungkin nampilin kolom yang bukan haknya.
        $bentuk['untuk'] = 'teknisi';
        $bentuk['bagian'] = array_map(
            fn (array $bagian): array => [
                ...$bagian,
                'field' => array_values(array_filter(
                    $bagian['field'] ?? [],
                    fn (array $field): bool => ! $field['hanya_admin'],
                )),
            ],
            $bentuk['bagian'],
        );

        return $bentuk;
    }

    /**
     * Kolom yang cuma muncul di sisi admin: nomor order, nomor sertifikat, dan
     * kawan-kawan (spesifikasi poin 1). Sebagian besar kebentuk otomatis, tapi
     * tetap ditulis di sini biar layar admin tau apa yang bakal dia lihat.
     *
     * @return array<string, mixed>
     */
    private function bagianAdmin(): array
    {
        return [
            'kode' => 'administratif',
            'halaman' => 1,
            'judul' => 'Data Administratif (Admin)',
            'field' => [
                $this->field('nomor_order', 'Order Number', 'teks', hanyaAdmin: true),
                $this->field('certificate.nomor', 'Certificate Number', 'teks', sumber: 'otomatis', hanyaAdmin: true),
                $this->field('suhu_ketidakpastian', 'U95% Suhu', 'angka', sumber: 'otomatis', satuan: '°C', hanyaAdmin: true),
                $this->field('kelembaban_ketidakpastian', 'U95% Kelembaban', 'angka', sumber: 'otomatis', satuan: '%RH', hanyaAdmin: true),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bentukLengkap(): array
    {
        return [
            'kode_dokumen' => self::KODE_DOKUMEN,
            'judul' => 'Calibration Worksheet - pH Meter',
            'jumlah_pengulangan' => self::JUMLAH_PENGULANGAN,
            'larutan_standar' => self::LARUTAN_STANDAR_PH,
            'satuan' => 'pH',
            'satuan_suhu' => '°C',
            // Ditegasin ke klien: NGGAK ada kolom yang nahan tombol kirim.
            'semua_kolom_opsional' => true,
            'catatan_pengisian' => 'Kolom yang belum bisa diisi di lapangan boleh dikosongin — '
                .'lembar kerja tetap bisa dikirim. Titik yang datanya belum cukup nggak ikut dihitung, '
                .'dan sertifikatnya baru bisa terbit sesudah dilengkapi admin.',

            'bagian' => [
                [
                    'kode' => 'identitas_alat',
                    'halaman' => 1,
                    'judul' => 'EQUIPMENT IDENTITY AND CUSTOMER DATA',
                    'field' => [
                        $this->field('tanggal_terima', 'Received Date', 'tanggal'),
                        $this->field('tanggal_kalibrasi', 'Calibration Date', 'tanggal'),
                        $this->field('equipment_id', 'Equipment', 'pilihan', sumber: 'master_alat'),
                        $this->field('equipment.nama_alat', '1. Name', 'teks', sumber: 'otomatis'),
                        $this->field('equipment.range_resolusi', '2. Range/Resolution', 'teks', sumber: 'otomatis', satuan: 'pH'),

                        // TIGA field di bawah DIKETIK TEKNISI, bukan ditarik dari
                        // master alat — dan itu bukan pilihan gaya.
                        //
                        // Teknisi megang alat fisiknya; yang dia baca dari badan
                        // alat itu yang sah. Data master diisi admin waktu alat
                        // didaftarkan, sering dari email pelanggan, dan sering
                        // beda sama unit yang beneran datang (tipe sama, seri
                        // beda; atau merk kekosongan). Kalau lembar kerja nyalin
                        // master, salah ketiknya kebawa sampai ke sertifikat dan
                        // nggak ada yang bisa mbetulin dari lapangan.
                        //
                        // Sengaja NGGAK di-prefill: kolom yang udah keisi bikin
                        // orang nyentang tanpa baca. Kosong maksa teknisi nengok
                        // badan alatnya.
                        $this->field('alat_model', '3. Type/Model', 'teks'),
                        $this->field('alat_serial_number', '4. Serial Number/LPI', 'teks'),
                        $this->field('alat_merk', '5. Merk/Manufacture', 'teks'),

                        // "6. Thermohygro used" — di kertas ini BUKAN bagian dari
                        // tabel STANDARD, dan pilihannya dikelompokkan Insitu vs
                        // Inlab. Sekarang diisi TEKNISI (dia yang tau unit mana
                        // yang kebawa ke lokasi), bukan admin.
                        $this->field(
                            'thermohygro_standard_id',
                            '6. Thermohygro used',
                            'pilihan',
                            sumber: 'master_thermohygro',
                        ),
                    ],
                ],
                [
                    'kode' => 'pemilik',
                    'halaman' => 1,
                    'judul' => 'OWNER',
                    // Sama alasannya kayak Type/Model & Serial di atas: yang
                    // nulis nama PT & alamat di lembar kerja itu teknisi, dari
                    // surat jalan/alat yang dia terima — bukan disalin dari
                    // master pelanggan yang bisa udah basi (PT pindah kantor,
                    // atau alatnya titipan cabang lain).
                    'field' => [
                        $this->field('pemilik_nama', '1. Name', 'teks'),
                        $this->field('pemilik_alamat', '2. Address', 'teks_panjang'),
                    ],
                ],
                [
                    'kode' => 'usage_check',
                    'halaman' => 1,
                    'judul' => 'STANDARD',
                    // BARISNYA TERCETAK, bukan tarikan seluruh master standar.
                    //
                    // Sebelumnya bagian ini `sumber: master_standar` — mobile
                    // narik `GET /standards` lalu nampilin SEMUANYA. Akibatnya di
                    // lembar kerja pH ikut nongol Gauge Block Set (standar
                    // panjang) dan tujuh unit thermohygro, jadi satu daftar
                    // panjang yang nggak mirip kertasnya sama sekali.
                    //
                    // Di kertas, tabel STANDARD lembar pH isinya LIMA baris yang
                    // udah tercetak — persis kayak nilai larutan 4.00/7.00/10.01
                    // yang juga udah dipatok di kelas ini. Teknisi cuma nyentang
                    // Usage Check, bukan milih standar dari katalog.
                    //
                    // Tiap baris dicocokin ke master lab (buat dapat lot &
                    // sertifikat ketertelusurannya); yang belum kedaftar tetap
                    // tampil sebagai baris berlabel dengan `standard_id` null —
                    // lebih baik barisnya ada tapi belum ketaut daripada hilang
                    // dari lembar kerja resmi.
                    'baris' => self::STANDARD_TERCETAK,
                    'field' => [
                        $this->field('standar_dicek.*.dipakai', 'Usage Check', 'centang'),
                        $this->field('standar_dicek.*.keterangan', 'Keterangan', 'teks'),
                    ],
                ],
                [
                    'kode' => 'data_kalibrasi',
                    'halaman' => 1,
                    'judul' => 'CALIBRATION DATA',
                    'field' => [
                        $this->field('lokasi', '1. Location', 'pilihan', pilihan: [
                            ['nilai' => 'lab', 'label' => 'In lab'],
                            ['nilai' => 'onsite', 'label' => 'Insitu'],
                        ]),
                        $this->field('room_id', 'Ruangan', 'pilihan', sumber: 'master_ruangan'),
                        // "Calibration Methode" tetap administratif — di kertas
                        // nilainya udah TERCETAK (SIDIK-IK-CAL-0506), bukan
                        // kolom kosong yang diisi teknisi.
                        $this->field(
                            'calibration_method_id',
                            '2. Calibration Methode',
                            'pilihan',
                            sumber: 'master_metode',
                            hanyaAdmin: true,
                        ),
                    ],
                ],
                [
                    'kode' => 'hasil',
                    'halaman' => 2,
                    'judul' => 'CALIBRATION RESULT',
                    // Env. Condition ADA DI SINI, bukan di CALIBRATION DATA.
                    // Di kertas dia baris pertama blok CALIBRATION RESULT —
                    // dicatat bareng waktu ngukur, bukan waktu nyiapin sesi.
                    'field' => [
                        $this->field('suhu_awal', 'Env. Condition — First', 'angka', satuan: '°C'),
                        $this->field('kelembaban_awal', 'Env. Condition — First', 'angka', satuan: '%RH'),
                        $this->field('suhu_akhir', 'Env. Condition — End', 'angka', satuan: '°C'),
                        $this->field('kelembaban_akhir', 'Env. Condition — End', 'angka', satuan: '%RH'),
                    ],
                    // Dua tabel dengan bentuk sama: sebelum & sesudah adjustment.
                    'tabel' => [
                        $this->tabelHasil('sebelum_adjustment', 'Before adjustment Reading'),
                        $this->tabelHasil('sesudah_adjustment', 'After adjustment Reading'),
                    ],
                ],
                [
                    'kode' => 'penutup',
                    'halaman' => 2,
                    'judul' => 'Catatan & Tanda Tangan',
                    'field' => [
                        $this->field('catatan_teknisi', 'Catatan', 'teks_panjang'),
                        $this->field('teknisi.nama', 'Calibrated by', 'teks', sumber: 'otomatis'),
                        // "Checked by" keisi sendiri dari admin yang nyetujuin —
                        // bukan diketik teknisi, biar nggak bisa ngaku-ngaku
                        // udah diperiksa.
                        $this->field('reviewer.nama', 'Checked by', 'teks', sumber: 'otomatis'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Cocokin lima baris tercetak tabel STANDARD ke master `standards` lab.
     *
     * Dicocokin ke `nama` ATAU `serial_number` — lembar kerjanya nulis
     * "RTD Sensor/SH1/20" sementara di master kekatalog "Termometer & Sensor
     * Std." dengan serial `23P1005`. Dua-duanya barang yang sama; yang beda
     * cuma penyebutan lapangan vs katalog.
     *
     * Yang nggak ketemu TETAP dikirim, `standard_id` null. Baris hilang dari
     * lembar kerja resmi jauh lebih berbahaya daripada baris yang belum
     * ketaut — teknisi nggak bakal sadar ada standar yang nggak kecatat.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function tautkanStandar(array $bentuk): array
    {
        $master = Standard::query()
            ->whereNull('parameter_kondisi')   // thermohygro punya bagiannya sendiri
            ->get(['id', 'nama', 'serial_number', 'no_sertifikat', 'tertelusur_ke']);

        foreach ($bentuk['bagian'] as $i => $bagian) {
            if (($bagian['kode'] ?? null) !== 'usage_check') {
                continue;
            }

            $bentuk['bagian'][$i]['baris'] = array_map(
                function (array $baris) use ($master): array {
                    $cocok = $master->first(fn (Standard $s): bool => collect($baris['cocok'])
                        ->contains(fn (string $kunci): bool => $s->nama === $kunci
                            || $s->serial_number === $kunci));

                    return [
                        'label' => $baris['label'],
                        'standard_id' => $cocok?->id,
                        'serial_number' => $cocok?->serial_number,
                        'no_sertifikat' => $cocok?->no_sertifikat,
                        'tertelusur_ke' => $cocok?->tertelusur_ke,
                        // Penanda buat admin, bukan buat teknisi: baris ini
                        // tercetak di formulir tapi belum ada di master lab.
                        'terdaftar' => $cocok !== null,
                    ];
                },
                $bentuk['bagian'][$i]['baris'],
            );
        }

        return $bentuk;
    }

    /**
     * Isi pilihan "6. Thermohygro used" — dikelompokkan Insitu vs Inlab persis
     * kayak kotak centang di kertas, bukan dropdown seluruh master standar.
     *
     * Unit yang tercetak di formulir cuma empat (TH-2/TH-6/TH-7 insitu, TH-4
     * inlab) walau lab punya TH-1..TH-7 — sisanya dipakai di lembar kerja alat
     * lain. Nampilin ketujuhnya di sini bikin teknisi bisa milih unit yang
     * secara prosedur nggak boleh dipakai buat pekerjaan ini.
     *
     * @param  array<string, mixed>  $bentuk
     * @return array<string, mixed>
     */
    private function isiPilihanThermohygro(array $bentuk): array
    {
        $master = Standard::query()
            ->whereNotNull('parameter_kondisi')
            ->pluck('id', 'nama');

        $pilihan = [];
        foreach (self::THERMOHYGRO_TERCETAK as $unit) {
            $id = $master[$unit['label']] ?? null;

            if ($id === null) {
                continue;   // unit belum diseed — jangan tawarin yang nggak ada
            }

            $pilihan[] = [
                'nilai' => (string) $id,
                'label' => $unit['label'],
                'grup' => $unit['grup'],
            ];
        }

        foreach ($bentuk['bagian'] as $i => $bagian) {
            foreach ($bagian['field'] ?? [] as $j => $field) {
                if ($field['kode'] === 'thermohygro_standard_id') {
                    $bentuk['bagian'][$i]['field'][$j]['pilihan'] = $pilihan;
                }
            }
        }

        return $bentuk;
    }

    /**
     * Satu tabel hasil: baris = larutan standar, kolom = Repeat 1..5, tiap sel
     * isinya pembacaan pH + suhu larutan.
     *
     * @return array<string, mixed>
     */
    private function tabelHasil(string $tahap, string $judul): array
    {
        return [
            'tahap' => $tahap,
            'judul' => $judul,
            'baris' => array_map(
                fn (float $nilai): array => ['titik_ukur' => $nilai, 'label' => number_format($nilai, 2, ',', '')],
                self::LARUTAN_STANDAR_PH,
            ),
            'kolom' => [
                ['kode' => 'pembacaan', 'label' => 'pH', 'tipe' => 'angka', 'satuan' => 'pH'],
                ['kode' => 'suhu', 'label' => '°C', 'tipe' => 'angka', 'satuan' => '°C'],
            ],
            'pengulangan' => range(1, self::JUMLAH_PENGULANGAN),
        ];
    }

    /**
     * @param  list<array<string, string>>  $pilihan
     * @return array<string, mixed>
     */
    private function field(
        string $kode,
        string $label,
        string $tipe,
        ?string $sumber = null,
        ?string $satuan = null,
        array $pilihan = [],
        bool $hanyaAdmin = false,
    ): array {
        return [
            'kode' => $kode,
            'label' => $label,
            'tipe' => $tipe,
            // Selalu false — lihat penjelasan di docblock kelas ini.
            'wajib' => false,
            'sumber' => $sumber,
            'satuan' => $satuan,
            'pilihan' => $pilihan,
            // Field administratif: nggak ditampilin di layar teknisi
            // (spesifikasi poin 1), dan kiriman teknisi buat field ini dibuang
            // backend.
            'hanya_admin' => $hanyaAdmin,
        ];
    }
}

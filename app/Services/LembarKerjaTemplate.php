<?php

namespace App\Services;

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
     * @param  bool  $untukAdmin  true = tampilan admin (superset), false = tampilan teknisi
     * @return array<string, mixed>
     */
    public function phMeter(bool $untukAdmin = false): array
    {
        $bentuk = $this->bentukLengkap();

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
                    'judul' => 'EQUIPMENT IDENTITY AND CUSTOMER DATA',
                    'field' => [
                        $this->field('tanggal_terima', 'Received Date', 'tanggal'),
                        $this->field('tanggal_kalibrasi', 'Calibration Date', 'tanggal'),
                        // Enam field di bawah ketarik otomatis dari data alat —
                        // teknisi cuma milih alatnya, nggak ngetik ulang.
                        $this->field('equipment_id', 'Equipment', 'pilihan', sumber: 'master_alat'),
                        $this->field('equipment.nama_alat', '1. Name', 'teks', sumber: 'otomatis'),
                        $this->field('equipment.range_resolusi', '2. Range/Resolution', 'teks', sumber: 'otomatis', satuan: 'pH'),
                        $this->field('equipment.model', '3. Type/Model', 'teks', sumber: 'otomatis'),
                        $this->field('equipment.serial_number', '4. Serial Number/LPI', 'teks', sumber: 'otomatis'),
                        $this->field('equipment.merk', '5. Merk/Manufacture', 'teks', sumber: 'otomatis'),
                        // "Thermohygro used" (TH-2/TH-6/TH-7/TH-4) itu field
                        // administratif — dihapus dari layar teknisi sesuai
                        // spesifikasi poin 1, diisi admin.
                        $this->field(
                            'thermohygro_standard_id',
                            '6. Thermohygro used',
                            'pilihan',
                            sumber: 'master_standar',
                            hanyaAdmin: true,
                        ),
                    ],
                ],
                [
                    'kode' => 'pemilik',
                    'judul' => 'OWNER',
                    'field' => [
                        $this->field('customer.nama', '1. Name', 'teks', sumber: 'otomatis'),
                        $this->field('customer.alamat', '2. Address', 'teks', sumber: 'otomatis'),
                    ],
                ],
                [
                    'kode' => 'data_kalibrasi',
                    'judul' => 'STANDARD CALIBRATION DATA',
                    'field' => [
                        $this->field('lokasi', '1. Location', 'pilihan', pilihan: [
                            ['nilai' => 'lab', 'label' => 'In lab'],
                            ['nilai' => 'onsite', 'label' => 'Insitu'],
                        ]),
                        $this->field('room_id', 'Ruangan', 'pilihan', sumber: 'master_ruangan'),
                        // "Calibration Methode" juga field administratif.
                        $this->field(
                            'calibration_method_id',
                            '2. Calibration Methode',
                            'pilihan',
                            sumber: 'master_metode',
                            hanyaAdmin: true,
                        ),
                        $this->field('suhu_awal', 'Env. Condition — First', 'angka', satuan: '°C'),
                        $this->field('kelembaban_awal', 'Env. Condition — First', 'angka', satuan: '%RH'),
                        $this->field('suhu_akhir', 'Env. Condition — End', 'angka', satuan: '°C'),
                        $this->field('kelembaban_akhir', 'Env. Condition — End', 'angka', satuan: '%RH'),
                    ],
                ],
                [
                    'kode' => 'usage_check',
                    'judul' => 'Standard Name / Usage Check',
                    // Daftar standarnya diambil dari master data lab
                    // (`GET /api/standards`), bukan dipatok di sini — tiap lab
                    // punya lot buffer & sensor sendiri.
                    'sumber' => 'master_standar',
                    'field' => [
                        $this->field('standar_dicek.*.standard_id', 'Standard Name', 'pilihan', sumber: 'master_standar'),
                        $this->field('standar_dicek.*.dipakai', 'Usage Check', 'centang'),
                        $this->field('standar_dicek.*.keterangan', 'Keterangan', 'teks'),
                    ],
                ],
                [
                    'kode' => 'hasil',
                    'judul' => 'CALIBRATION RESULT',
                    // Dua tabel dengan bentuk sama: sebelum & sesudah adjustment.
                    'tabel' => [
                        $this->tabelHasil('sebelum_adjustment', 'Before adjustment Reading'),
                        $this->tabelHasil('sesudah_adjustment', 'After adjustment Reading'),
                    ],
                ],
                [
                    'kode' => 'penutup',
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

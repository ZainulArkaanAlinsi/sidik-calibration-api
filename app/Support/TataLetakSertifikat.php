<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Tata letak lembar sertifikat — satu tempat yang dibaca PDF (`pdf.blade.php`)
 * DAN Excel (`CertificateExcelExporter`), supaya dua berkas satu sertifikat
 * tidak bisa beda bentuk.
 *
 * ## K-50-15 (pemilik, 18 Okt 2026)
 *
 * Tiap sertifikat wajib persis lembar `SERTIFIKAT` workbook master alatnya:
 * kepala, urutan & judul bagian, judul & urutan kolom, satuan, desimal per
 * format sel, `k` seperti format selnya, catatan & penafian tetap, "End of
 * Certificate". Master berbeda-beda bentuknya (matriks
 * `_agents_workspace/revisi-1510/04-struktur-sertifikat-48.md`), jadi bentuknya
 * dinyatakan PROFIL lewat `CalibrationProfile::tataLetakSertifikat()` dan
 * `catatanSertifikat()`, bukan dipatok di template.
 *
 * ## Bawaan = bentuk LAMA, dan itu sengaja
 *
 * [BAWAAN] adalah bentuk cetak sebelum K-50-15. Ia tidak boleh diubah:
 * snapshot yang terbit sebelum profilnya menyatakan tata letak tidak punya
 * kunci `tata_letak`, dan dibaca dengan bawaan ini — cetak ulangnya harus
 * keluar sama dengan yang dipegang pelanggan. Bentuk baru untuk SEMUA alat
 * dipasang di `CalibrationProfile::tataLetakSertifikat()` (bawaan kelas induk),
 * yang dibekukan ke snapshot baru — bukan di sini.
 *
 * Snapshot menyimpan TIMPAAN saja (kunci yang dinyatakan profil), bukan hasil
 * gabungan: snapshot alat yang tidak menyatakan apa pun tetap identik byte demi
 * byte, sehingga `sertifikat:bangun-ulang` (pembanding `==`) tidak menganggap
 * seluruh sertifikat terbit "berubah".
 */
final class TataLetakSertifikat
{
    /** Bagian sertifikat tempat blok catatan dicetak, dalam urutan lembar. */
    public const POSISI_SESUDAH_KEPALA = 'sesudah_kepala';

    public const POSISI_ATAS_HASIL = 'atas_hasil';

    public const POSISI_BAWAH_HASIL = 'bawah_hasil';

    public const POSISI_SESUDAH_CATATAN = 'sesudah_catatan';

    public const POSISI_SESUDAH_STANDAR = 'sesudah_standar';

    public const POSISI_SESUDAH_TTD = 'sesudah_ttd';

    public const POSISI = [
        self::POSISI_SESUDAH_KEPALA,
        self::POSISI_ATAS_HASIL,
        self::POSISI_BAWAH_HASIL,
        self::POSISI_SESUDAH_CATATAN,
        self::POSISI_SESUDAH_STANDAR,
        self::POSISI_SESUDAH_TTD,
    ];

    /** Kolom tabel hasil yang bisa disusun profil. */
    public const KOLOM_HASIL = ['standar', 'uut', 'koreksi', 'sebaran', 'u95', 'u95_relatif', 'remark', 'r2'];

    /**
     * Awalan kolom tambahan: `tambahan:<kunci>` mencetak
     * `baris.tambahan.<kunci>` (`CalibrationProfile::kolomTambahanTitik`).
     */
    public const AWALAN_TAMBAHAN = 'tambahan:';

    /** Kolom yang boleh diberi format sel (`formatKolomSertifikat`). */
    public const KOLOM_FORMAT = ['standar', 'uut', 'koreksi', 'sebaran', 'u95', 'u95_relatif', 'k'];

    /** Pilihan `k_tunggal`: satu `k` per kelompok dipilih seperti sel master. */
    public const K_TUNGGAL = ['baris_pertama', 'u95_terbesar'];

    /** Enam belas field kepala snapshot (`CertificateSnapshotBuilder::header`). */
    public const KUNCI_KEPALA = [
        'certificate_number', 'page', 'owner', 'order_number', 'address', 'received_date',
        'equipment_name', 'manufacturer', 'calibration_location', 'model_type', 'calibration_date',
        'serial_number', 'calibration_method', 'capacity_graduation', 'env_condition', 'technician_id',
    ];

    /** Field kepala yang isinya tanggal ISO — dicetak `d F Y`. */
    public const KUNCI_TANGGAL = ['received_date', 'calibration_date'];

    /** Kolom tabel Standard Used bawaan snapshot. */
    public const KUNCI_STANDAR = ['name', 'merk_type', 'serial_number', 'traceable_to'];

    /**
     * Bentuk cetak LAMA (sebelum K-50-15). Jangan diubah — lihat docblock kelas.
     *
     * `null` di `kolom_hasil`, `judul_kolom_u95`, `label_u95`, `kalimat_k` =
     * susunan lama yang dihitung template (kolom muncul/hilang menurut isi
     * snapshot, judul U95 ber-HTML), bukan teks kosong.
     */
    public const BAWAAN = [
        'kop' => true,
        'judul' => 'CALIBRATION CERTIFICATE',
        'kepala' => [
            [['kunci' => 'certificate_number', 'label' => 'Certificate Number'], ['kunci' => 'page', 'label' => 'Page']],
            [['kunci' => 'owner', 'label' => 'Owner'], ['kunci' => 'order_number', 'label' => 'Order Number']],
            [['kunci' => 'address', 'label' => 'Address'], ['kunci' => 'received_date', 'label' => 'Received Date']],
            [['kunci' => 'equipment_name', 'label' => 'Equipment Name'], ['kunci' => 'manufacturer', 'label' => 'Manufacturer']],
            [['kunci' => 'calibration_location', 'label' => 'Calibration Location'], ['kunci' => 'model_type', 'label' => 'Model/Type']],
            [['kunci' => 'calibration_date', 'label' => 'Calibration Date'], ['kunci' => 'serial_number', 'label' => 'Serial Number']],
            [['kunci' => 'calibration_method', 'label' => 'Calibration Method'], ['kunci' => 'capacity_graduation', 'label' => 'Capacity/Graduation']],
            [['kunci' => 'env_condition', 'label' => 'Env. Condition'], ['kunci' => 'technician_id', 'label' => 'Technician ID']],
        ],
        'env_bertumpuk' => false,
        'judul_laporan' => 'CALIBRATION REPORT',
        'kolom_hasil' => null,
        'pisah_per_remark' => true,
        'satuan_baris_sendiri' => false,
        'judul_kolom_u95' => null,
        'label_u95' => null,
        'kalimat_k' => null,
        'judul_standar_digunakan' => 'STANDARD USED',
        'kolom_standar_digunakan' => [
            ['kunci' => 'name', 'label' => 'Name'],
            ['kunci' => 'merk_type', 'label' => 'Merk/Type'],
            ['kunci' => 'serial_number', 'label' => 'Serial Number'],
            ['kunci' => 'traceable_to', 'label' => 'Traceable to SI through'],
        ],
        // Dicetak apa adanya lalu spasi lalu tanggalnya — tanda baca ikut label
        // (master: `Issuance Date :`, `Issuance Date:`).
        'label_tanggal_terbit' => 'Issuance Date:',
        'akhir_sertifikat' => null,
        // Kelompok tabel hasil ikut SATUAN baris juga, bukan cuma `remark`.
        // Bentuk lama `false`: snapshot terbit tanpa `tata_letak` dikelompokkan
        // seperti waktu terbit (satu tabel Conductivity µS/cm + mS/cm).
        'pisah_per_satuan' => false,
        // Format sel `k` bawaan bila baris & profil tidak menyatakannya
        // (`format.k`, `desimal_k`). `null` = perilaku lama (2 desimal).
        'format_k' => null,
        // Pemisah desimal angka yang DICETAK. `,` = bentuk lama.
        'pemisah_desimal' => ',',
        // Satu `k` per kelompok walau `k` antar baris beda (master mencetak
        // satu sel `k`, mis. `183!V29`). `null` = perilaku lama: `k` baris
        // pertama, `≈` bila angka cetaknya berbeda.
        'k_tunggal' => null,
    ];

    /**
     * Bentuk MAYORITAS lembar `SERTIFIKAT` master aktif — bawaan
     * `CalibrationProfile::tataLetakSertifikat()` sejak K-50-15, jadi ikut
     * dibekukan ke setiap snapshot baru. Profil yang masternya berbeda
     * menimpanya di atas bawaan ini (`[...parent::tataLetakSertifikat($sesi),
     * 'kunci' => …]`), bukan menulis ulang semuanya.
     *
     * Dihitung 20 Okt 2026 dari Studio (`workbook_master` aktif, 52 lembar
     * `SERTIFIKAT*` di luar `SERTIFIKAT KALIBRATOR`):
     *
     * - `kepala` (G1): kelompok kiri SELALU Certificate Number, Page, Order
     *   Number, Received Date, Equipment Name, Manufacturer, Model/Type, Serial
     *   Number, Capacity/Graduation, Technician ID; kanan SELALU Owner, Address,
     *   Calibration Location, Calibration Date, Calibration Method, kondisi
     *   lingkungan (micrometer `165!B2:N13`, autoclave `155!B5:K16`). Grid
     *   dua kolom: baris ke-n = field ke-n tiap kelompok. Label lingkungan
     *   `Env. Condition` (35 lembar) — 15 lembar `Environmental Condition`,
     *   hydrometer `Environment Condition` (H).
     * - `env_bertumpuk` (G9): T, %RH, (Tekanan Udara) di baris sendiri-sendiri
     *   (`165!N12:T13`, `143!O11:Y13`).
     * - `judul_laporan` (G3): `Calibration Report` + sel `:` (44 lembar), atau
     *   `Calibration Report :` dalam satu sel (timbangan, anak timbangan).
     * - `satuan_baris_sendiri` (G10): satuan di baris bawah judul kolom
     *   (`165!D17` `mm`, `177!C21` `NTU`, `163!E20` `kgf`). Yang menulisnya di
     *   judul — TITS/TIDS/Thermocouple/Glass `(oC)`, Spectro `(nm)` — menimpa.
     * - `judul_standar_digunakan` (G3): `Standard used :` (42 lembar).
     * - `kolom_standar_digunakan` (G3): `Merk/Type :` / `Serial Number :` /
     *   `Traceable to SI through :` (24 lembar, 26 profil) lawan tanpa titik
     *   dua (23 lembar, 18 profil — Dimensi, Suhu, Aliran, Enclosure: H).
     * - `label_tanggal_terbit` (G3): `Issuance Date :` (35 lembar ber-`:`,
     *   17 tanpa).
     * - `catatan` (G5): penafian berstrip persis 51 lembar
     *   (`165!D35`); kalimat `k` di depannya tetap kalimat fallback lama.
     *
     * Yang SENGAJA tidak ditiru (penyimpangan sadar, G4): master menulis
     * "Convidence Level" (47 dari 52 lembar). Sertifikat menulis "Confidence" — salah
     * ketik di dokumen terakreditasi tidak dipertahankan. `kalimat_k` tetap
     * `null` (kalimat lama ber-"Confidence").
     *
     * Yang TIDAK di sini karena bukan mayoritas: `akhir_sertifikat` (G6) hanya
     * 13 profil — anak timbangan (`139!G68`), labu ukur/pipet volume/picnometer
     * (`143!G45`), buret/gelas ukur/pipet ukur (`144!G49`), timbangan
     * (`145!L76`), autoclave (`155!E66`), piston pipette/dispensett
     * (`160!G45`), buret digital (`164!G47`), TITS (`173!F53`, `174!F47`) —
     * bunyi stripnya beda per master, jadi tiap profil menulisnya sendiri.
     * `kop` & `judul` (G2) tetap bentuk lama: lembar SERTIFIKAT tidak memuat
     * kop gambar maupun "CALIBRATION CERTIFICATE", tapi kop membawa nomor
     * akreditasi KAN — mencabutnya menunggu konfirmasi eksplisit pemilik.
     */
    public const MAYORITAS_MASTER = [
        'kepala' => [
            [['kunci' => 'certificate_number', 'label' => 'Certificate Number'], ['kunci' => 'owner', 'label' => 'Owner']],
            [['kunci' => 'page', 'label' => 'Page'], ['kunci' => 'address', 'label' => 'Address']],
            [['kunci' => 'order_number', 'label' => 'Order Number'], ['kunci' => 'calibration_location', 'label' => 'Calibration Location']],
            [['kunci' => 'received_date', 'label' => 'Received Date'], ['kunci' => 'calibration_date', 'label' => 'Calibration Date']],
            [['kunci' => 'equipment_name', 'label' => 'Equipment Name'], ['kunci' => 'calibration_method', 'label' => 'Calibration Method']],
            [['kunci' => 'manufacturer', 'label' => 'Manufacturer'], ['kunci' => 'env_condition', 'label' => 'Env. Condition']],
            [['kunci' => 'model_type', 'label' => 'Model/Type'], null],
            [['kunci' => 'serial_number', 'label' => 'Serial Number'], null],
            [['kunci' => 'capacity_graduation', 'label' => 'Capacity/Graduation'], null],
            [['kunci' => 'technician_id', 'label' => 'Technician ID'], null],
        ],
        'env_bertumpuk' => true,
        'judul_laporan' => 'Calibration Report :',
        'satuan_baris_sendiri' => true,
        'judul_standar_digunakan' => 'Standard used :',
        'kolom_standar_digunakan' => [
            ['kunci' => 'name', 'label' => 'Name'],
            ['kunci' => 'merk_type', 'label' => 'Merk/Type :'],
            ['kunci' => 'serial_number', 'label' => 'Serial Number :'],
            ['kunci' => 'traceable_to', 'label' => 'Traceable to SI through :'],
        ],
        'label_tanggal_terbit' => 'Issuance Date :',
        // Keputusan pemilik 20 Okt 2026 ("ikut master semua"):
        // - P1 (kop & judul besar) SENGAJA tidak ditimpa: kop memuat nomor
        //   akreditasi KAN, dan klaim itu tidak boleh hilang tanpa konfirmasi
        //   eksplisit pemilik (koordinator 20 Okt 2026). Bentuk lama berlaku.
        // - tabel sendiri per satuan (`157!C33:T36` µS/cm, `C38:T42` mS/cm).
        'pisah_per_satuan' => true,
        // - P2: sel `k` berformat `0` di 40 lembar (`165!P30`, `163!T36`).
        //   Cetak saja; U95 tetap dihitung dengan k sebenarnya.
        'format_k' => '0',
        // - P3: titik desimal seperti tampilan sel master (`20.2`, `0.610`).
        'pemisah_desimal' => '.',
        'catatan' => [
            'The Uncertainty is taken at a Confidence Level 95 % and Coverage Factor ( k ) = 2',
            '- Calibration results are not to be announced and only apply to related tools -',
        ],
    ];

    /**
     * Kunci timpaan yang TIDAK disimpan di `tata_letak` karena punya tempat
     * sendiri di snapshot: `catatan` menggantikan `snapshot.catatan` (daftar
     * kalimat baku di bawah tabel hasil — tetap list<string>, karena aplikasi HP
     * membacanya sebagai teks).
     */
    public const KUNCI_DIPINDAH = ['catatan'];

    /**
     * Tata letak siap pakai untuk satu snapshot: timpaan yang dibekukan di atas
     * [BAWAAN].
     *
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    public static function untuk(array $snapshot): array
    {
        $timpaan = is_array($snapshot['tata_letak'] ?? null) ? $snapshot['tata_letak'] : [];

        return array_replace(self::BAWAAN, array_intersect_key($timpaan, self::BAWAAN));
    }

    /**
     * Rapikan & periksa timpaan dari profil. Kunci yang tidak dikenal DILEMPAR,
     * bukan diabaikan: salah ketik `judul_lapoan` di profil baru tidak boleh
     * berakhir sebagai sertifikat berbentuk lama tanpa satu pun tanda.
     * `GenerateCertificate` menangkap lemparannya dan pesan ini sampai ke admin.
     *
     * @param  array<string, mixed>  $timpaan
     * @param  list<string>  $kunciKepalaTambahan  kunci `header_tambahan` sesi ini
     * @return array<string, mixed>
     */
    public static function normalisasi(array $timpaan, array $kunciKepalaTambahan = []): array
    {
        $dikenal = [...array_keys(self::BAWAAN), ...self::KUNCI_DIPINDAH];
        $asing = array_diff(array_keys($timpaan), $dikenal);

        if ($asing !== []) {
            throw new InvalidArgumentException('Kunci tata letak sertifikat tidak dikenal: '.implode(', ', $asing)
                .'. Yang dikenal: '.implode(', ', $dikenal).'.');
        }

        $hasil = [];

        foreach ($timpaan as $kunci => $nilai) {
            $hasil[$kunci] = match ($kunci) {
                'kop', 'env_bertumpuk', 'pisah_per_remark', 'satuan_baris_sendiri', 'pisah_per_satuan' => self::bool($kunci, $nilai),
                'format_k' => $nilai === null ? null : self::normalisasiFormat(['k' => $nilai])['k'],
                'k_tunggal' => $nilai === null || in_array($nilai, self::K_TUNGGAL, true)
                    ? $nilai
                    : throw new InvalidArgumentException('Tata letak sertifikat `k_tunggal` harus null atau salah satu dari '.implode(', ', self::K_TUNGGAL).'.'),
                'pemisah_desimal' => in_array($nilai, [',', '.'], true)
                    ? $nilai
                    : throw new InvalidArgumentException('Tata letak sertifikat `pemisah_desimal` harus `,` atau `.`.'),
                'judul', 'akhir_sertifikat', 'judul_kolom_u95', 'label_u95', 'kalimat_k' => self::teksAtauNull($kunci, $nilai),
                'judul_laporan', 'judul_standar_digunakan', 'label_tanggal_terbit' => self::teks($kunci, $nilai),
                'kepala' => self::kepala($nilai, [...self::KUNCI_KEPALA, ...$kunciKepalaTambahan]),
                'kolom_hasil' => $nilai === null ? null : self::periksaKolomHasil($nilai),
                'kolom_standar_digunakan' => self::kolomStandar($nilai),
                'catatan' => self::daftarTeks($kunci, $nilai),
            };
        }

        return $hasil;
    }

    /**
     * Rapikan & periksa blok catatan dari `CalibrationProfile::catatanSertifikat()`.
     *
     * @param  list<mixed>  $blok
     * @return list<array{posisi: string, judul: ?string, baris: list<string>, tabel: list<list<string>>}>
     */
    public static function normalisasiBlok(array $blok): array
    {
        $hasil = [];

        foreach (array_values($blok) as $i => $b) {
            if (! is_array($b)) {
                throw new InvalidArgumentException("Blok catatan sertifikat #{$i} bukan array.");
            }

            $asing = array_diff(array_keys($b), ['posisi', 'judul', 'baris', 'tabel']);

            if ($asing !== []) {
                throw new InvalidArgumentException("Blok catatan sertifikat #{$i}: kunci tidak dikenal ".implode(', ', $asing).'.');
            }

            $posisi = $b['posisi'] ?? null;

            if (! in_array($posisi, self::POSISI, true)) {
                throw new InvalidArgumentException("Blok catatan sertifikat #{$i}: posisi `".var_export($posisi, true)
                    .'` tidak dikenal. Yang dikenal: '.implode(', ', self::POSISI).'.');
            }

            $tabel = [];

            foreach (array_values((array) ($b['tabel'] ?? [])) as $baris) {
                if (! is_array($baris)) {
                    throw new InvalidArgumentException("Blok catatan sertifikat #{$i}: baris tabel bukan array.");
                }

                $tabel[] = array_map(static fn ($sel): string => (string) $sel, array_values($baris));
            }

            $bersih = [
                'posisi' => $posisi,
                'judul' => self::teksAtauNull('judul', $b['judul'] ?? null),
                'baris' => self::daftarTeks('baris', $b['baris'] ?? []),
                'tabel' => $tabel,
            ];

            if ($bersih['judul'] === null && $bersih['baris'] === [] && $bersih['tabel'] === []) {
                throw new InvalidArgumentException("Blok catatan sertifikat #{$i} kosong (tanpa judul, baris, maupun tabel).");
            }

            $hasil[] = $bersih;
        }

        return $hasil;
    }

    /**
     * Rapikan & periksa format sel per kolom dari `formatKolomSertifikat()`.
     * Kode yang diterima: `General`, `0`, `0.0`, `0.00`, … — persis kode format
     * angka sel master yang dipakai lembar SERTIFIKAT.
     *
     * @param  array<string, mixed>  $format
     * @return array<string, string>
     */
    public static function normalisasiFormat(array $format): array
    {
        $hasil = [];

        foreach ($format as $kolom => $kode) {
            if (! in_array($kolom, self::KOLOM_FORMAT, true)) {
                throw new InvalidArgumentException("Format kolom sertifikat: kolom `{$kolom}` tidak dikenal. Yang dikenal: "
                    .implode(', ', self::KOLOM_FORMAT).'.');
            }

            if ($kode === null) {
                continue;
            }

            if (! is_string($kode) || preg_match('/^(General|0(\.0+)?)$/', $kode) !== 1) {
                throw new InvalidArgumentException("Format kolom sertifikat `{$kolom}`: kode `".var_export($kode, true)
                    .'` tidak didukung. Pakai `General`, `0`, `0.0`, `0.00`, …');
            }

            $hasil[$kolom] = $kode;
        }

        return $hasil;
    }

    /** `0.000` → 3, `0` → 0, `General` → null (presisi tampilan General). */
    public static function desimalFormat(string $kode): ?int
    {
        if ($kode === 'General') {
            return null;
        }

        $titik = strpos($kode, '.');

        return $titik === false ? 0 : strlen($kode) - $titik - 1;
    }

    /**
     * Angka seperti yang DITAMPILKAN sel berformat `$kode`. Format tetap
     * (`0.0…`) memakai pembulatan tampilan Excel ([Angka::hasil]) dan TIDAK
     * membuang nol di belakang — `5.0` tetap `5,0`, seperti master. `General`
     * mengikuti konvensi yang sudah dipakai berkas ini untuk format itu
     * (enclosure): presisi penuh, dipangkas 10 desimal, nol di belakang dibuang.
     */
    public static function formatAngka(?float $nilai, string $kode, bool $tandaNol = true, string $kosong = '—'): string
    {
        if ($nilai === null) {
            return $kosong;
        }

        $desimal = self::desimalFormat($kode);

        return $desimal === null
            ? Angka::nilaiStandar($nilai, 10, $kosong)
            : Angka::hasil($nilai, $desimal, $kosong, $tandaNol);
    }

    /**
     * Kolom tabel hasil yang dicetak, dalam urutan cetak. Tanpa timpaan
     * `kolom_hasil`, susunannya persis bentuk lama: Standard · UUT · Correction
     * · (sebaran) · (U95 per titik) · (R²).
     *
     * @param  array<string, mixed>  $snapshot
     * @param  array<string, mixed>  $tataLetak  hasil [untuk]
     * @return list<array{kolom: string, judul: ?string, satuan: ?string, gabung: bool}>
     */
    public static function kolomHasil(array $snapshot, array $tataLetak, bool $adaR2): array
    {
        if (is_array($tataLetak['kolom_hasil'] ?? null)) {
            return array_values(array_filter(
                $tataLetak['kolom_hasil'],
                // R² tidak dicetak selama definisinya belum diputus lab —
                // aturan yang sama dengan susunan lama.
                static fn (array $k): bool => $k['kolom'] !== 'r2' || $adaR2,
            ));
        }

        $kolom = [
            ['kolom' => 'standar', 'judul' => null, 'satuan' => null, 'gabung' => false],
            ['kolom' => 'uut', 'judul' => null, 'satuan' => null, 'gabung' => false],
            ['kolom' => 'koreksi', 'judul' => null, 'satuan' => null, 'gabung' => false],
        ];

        if (($snapshot['judul_sebaran'] ?? null) !== null) {
            $kolom[] = ['kolom' => 'sebaran', 'judul' => null, 'satuan' => null, 'gabung' => false];
        }

        if ($snapshot['u95_per_titik'] ?? false) {
            $kolom[] = ['kolom' => 'u95', 'judul' => null, 'satuan' => null, 'gabung' => false];
        }

        if ($adaR2) {
            $kolom[] = ['kolom' => 'r2', 'judul' => null, 'satuan' => null, 'gabung' => true];
        }

        return $kolom;
    }

    /**
     * Rentang baris (rowspan) tiap sel grid kepala: `[baris][kolom] => n`,
     * `0` = sel kosong yang ditelan sel di atasnya. Sel kosong (`null`) DI
     * BAWAH sel berisi ikut sel itu — seperti master yang menulis kondisi
     * lingkungan tiga baris di sebelah Technician ID & baris kosong
     * (`165!J12:T14`). Tanpa ini sel lingkungan bertumpuk mempertinggi
     * barisnya sendiri sementara sel kanan di bawahnya kosong, dan lembar
     * padat (Anak Timbangan 40 keping) meluap ke halaman dua. Sel kosong di
     * PUNCAK kolom tetap sel kosong sendiri. Grid tanpa `null` (bentuk lama)
     * tidak berubah: semua rentangnya 1.
     *
     * @param  list<list<array{kunci: string, label: string}|null>>  $kepala
     * @return list<list<int>>
     */
    public static function rentangKepala(array $kepala): array
    {
        $rentang = [];
        $pemilik = [];

        foreach (array_values($kepala) as $b => $baris) {
            foreach (array_values($baris) as $k => $sel) {
                if ($sel === null && isset($pemilik[$k])) {
                    $rentang[$pemilik[$k]][$k]++;
                    $rentang[$b][$k] = 0;

                    continue;
                }

                $rentang[$b][$k] = 1;

                if ($sel !== null) {
                    $pemilik[$k] = $b;
                }
            }
        }

        return $rentang;
    }

    /**
     * Baris tabel hasil yang DICETAK. Baris yang disembunyikan master (titik
     * nol Gaya, `CalibrationProfile::titikDicetakSertifikat()`) tetap ada di
     * snapshot dengan presisi penuh — yang dilewati cuma cetaknya.
     *
     * @param  list<array<string, mixed>>  $hasil
     * @return list<array<string, mixed>>
     */
    public static function barisDicetak(array $hasil): array
    {
        return array_values(array_filter($hasil, static fn ($b): bool => is_array($b) && ($b['dicetak'] ?? true) !== false));
    }

    /**
     * Kunci kelompok tabel. Bentuk master (`pisah_per_satuan`): satu tabel =
     * satu `remark` DAN satu satuan — baris mS/cm Conductivity tidak boleh
     * tercetak di bawah kepala `(µS/cm)` milik baris pertama, dan U95 serta `k`
     * kelompoknya ikut terpisah, persis master yang mencetak tabel sendiri per
     * satuan. Bentuk lama (snapshot terbit tanpa `tata_letak`): `remark` saja,
     * seperti waktu terbit.
     *
     * @param  array<string, mixed>  $baris
     */
    public static function kunciKelompok(array $baris, bool $pisahPerRemark, bool $pisahPerSatuan = false): string
    {
        return ($pisahPerRemark ? (string) ($baris['remark'] ?? '') : '')
            .($pisahPerSatuan ? "\u{1F}".(string) ($baris['satuan'] ?? '') : '');
    }

    /**
     * Teks angka hasil `Angka` dalam pemisah desimal tata letak ini: bentuk
     * lama apa adanya (koma), bentuk master titik desimal tanpa pemisah ribuan
     * ([Angka::keTitik]). Hanya untuk teks yang lahir dari `Angka`.
     *
     * @param  array<string, mixed>  $tataLetak  hasil [untuk]
     */
    public static function angka(string $teks, array $tataLetak): string
    {
        return ($tataLetak['pemisah_desimal'] ?? ',') === '.' ? Angka::keTitik($teks) : $teks;
    }

    /**
     * Teks satu sel tabel hasil — SATU aturan untuk PDF; Excel memakai
     * [desimalKolom] yang diturunkan dari aturan yang sama.
     *
     * @param  array<string, mixed>  $baris
     * @param  array<string, mixed>  $snapshot
     */
    public static function teksSel(array $baris, string $kolom, array $snapshot, int $desimalSertifikat, ?array $spek = null): string
    {
        if ($kolom === 'remark') {
            return filled($baris['remark'] ?? null) ? (string) $baris['remark'] : '—';
        }

        if (str_starts_with($kolom, self::AWALAN_TAMBAHAN)) {
            $nilai = $baris['tambahan'][substr($kolom, strlen(self::AWALAN_TAMBAHAN))] ?? null;

            return match (true) {
                $nilai === null || $nilai === '' => '—',
                is_string($nilai) && ! is_numeric($nilai) => $nilai,
                default => self::angka(self::formatAngka((float) $nilai, $spek['format'] ?? 'General'), self::untuk($snapshot)),
            };
        }

        if ($kolom === 'u95_relatif') {
            // U95 relatif terhadap pembacaan UUT (`%OR`, master `149!O22`
            // `=K62/S23/D22*100`, `185!N26`) — padanan `u95_persen_of_reading`
            // kalkulator Flowmeter: U95 / rata-rata UUT × 100. Hanya cetak.
            $u95 = $baris['u95'] ?? null;
            $uut = $baris['unit_under_test'] ?? null;

            if ($u95 === null || $uut === null || (float) $uut == 0.0) {
                return '—';
            }

            $format = $baris['format']['u95_relatif'] ?? $spek['format'] ?? 'General';

            return self::angka(self::formatAngka(abs((float) $u95 / (float) $uut * 100), $format), self::untuk($snapshot));
        }

        if ($spek !== null && isset($spek['faktor'])) {
            $sumber = ['standar' => 'standard_value', 'uut' => 'unit_under_test', 'koreksi' => 'correction', 'sebaran' => 'sebaran', 'u95' => 'u95'][$kolom] ?? null;

            if ($sumber !== null && ($baris[$sumber] ?? null) !== null) {
                $baris[$sumber] = (float) $baris[$sumber] * $spek['faktor'];
            }
        }

        if ($spek !== null && isset($spek['format']) && ! isset($baris['format'][$kolom])) {
            $baris['format'] = [...(is_array($baris['format'] ?? null) ? $baris['format'] : []), $kolom => $spek['format']];
        }

        $teks = self::angka(self::teksSelKoma($baris, $kolom, $snapshot, $desimalSertifikat), self::untuk($snapshot));

        // Nominal negatif mungil yang membulat ke nol (`-0,0001` titik nol Load
        // Cell) tercetak `-0` di bentuk lama; tidak ada master yang mencetaknya.
        // Hanya bentuk master — snapshot terbit tetap tercetak seperti dulu.
        return $kolom === 'standar' && isset($snapshot['tata_letak']) && $teks === '-0' ? '0' : $teks;
    }

    /**
     * Teks sel-sel kolom yang dipecah (`pecah`): nilai dalam satuan baris
     * diurai serakah ke tiap sel (Timer: detik → H, M, S, S(0,001) — master
     * `183!E19:L19` dari `PERHITUNGAN!C30:G30`). Kolom Correction diurai PER
     * SEL sebagai Standard − UUT, persis master (`183!S19 = E19-O19`, …,
     * `W19 = L19-R19`), bukan mengurai koreksinya.
     *
     * @param  array<string, mixed>  $baris
     * @param  array{kolom: string, pecah: list<array{judul: string, faktor: float, format: string}>}  $spek
     * @param  array<string, mixed>  $snapshot
     * @return list<string>
     */
    public static function teksPecah(array $baris, array $spek, array $snapshot): array
    {
        $tl = self::untuk($snapshot);
        $nilai = static fn (string $k): ?float => ($baris[$k] ?? null) === null ? null : (float) $baris[$k];
        $sel = match ($spek['kolom']) {
            'standar' => self::urai($nilai('standard_value'), $spek['pecah']),
            'uut' => self::urai($nilai('unit_under_test'), $spek['pecah']),
            'koreksi' => ($nilai('standard_value') === null || $nilai('unit_under_test') === null)
                ? array_fill(0, count($spek['pecah']), null)
                : array_map(
                    static fn (float $a, float $b): float => $a - $b,
                    self::urai($nilai('standard_value'), $spek['pecah']),
                    self::urai($nilai('unit_under_test'), $spek['pecah']),
                ),
            'u95' => self::urai($nilai('u95'), $spek['pecah']),
            default => throw new InvalidArgumentException("Kolom `{$spek['kolom']}` tidak bisa dipecah."),
        };
        $tandaNol = (bool) ($baris['tanda_nol'] ?? true);

        return array_map(
            static fn (?float $v, array $p): string => self::angka(self::formatAngka($v, $p['format'], $tandaNol), $tl),
            $sel,
            $spek['pecah'],
        );
    }

    /**
     * @param  list<array{faktor: float}>  $pecah
     * @return list<float|null>
     */
    private static function urai(?float $nilai, array $pecah): array
    {
        if ($nilai === null) {
            return array_fill(0, count($pecah), null);
        }

        $tanda = $nilai < 0 ? -1.0 : 1.0;
        $sisa = abs($nilai);
        $hasil = [];
        $akhir = count($pecah) - 1;

        foreach ($pecah as $i => $p) {
            if ($i === $akhir) {
                $hasil[] = $tanda * $sisa / $p['faktor'];

                break;
            }

            // Toleransi derau biner: 59.9999999 detik tetap 0 menit 59 detik,
            // tapi 60.0000000001 jadi 1 menit.
            $n = floor($sisa / $p['faktor'] + 1e-9);
            $sisa = max(0.0, $sisa - $n * $p['faktor']);
            $hasil[] = $tanda * $n;
        }

        return $hasil;
    }

    /**
     * Lebar kolom dalam sel tabel (kolom berpecah = jumlah pecahannya).
     *
     * @param  array<string, mixed>  $kolom
     */
    public static function lebarKolom(array $kolom): int
    {
        return isset($kolom['pecah']) ? count($kolom['pecah']) : 1;
    }

    /**
     * Baris yang `k`-nya dicetak untuk satu kelompok. Bentuk lama & `k_tunggal`
     * null: baris pertama. `u95_terbesar`: baris ber-U95 terbesar (master yang
     * mencetak `MAX` U95 dengan `k` budgetnya).
     *
     * @param  list<array<string, mixed>>  $baris
     * @return array<string, mixed>|null
     */
    public static function barisK(array $baris, ?string $mode): ?array
    {
        $ber = array_values(array_filter($baris, static fn ($b): bool => ($b['faktor_cakupan_k'] ?? null) !== null));

        if ($ber === []) {
            return null;
        }

        if ($mode === 'u95_terbesar') {
            usort($ber, static fn ($a, $b): int => (float) ($b['u95'] ?? 0) <=> (float) ($a['u95'] ?? 0));
        }

        return $ber[0];
    }

    /**
     * [teksSel] dalam bunyi `Angka` (koma desimal) — sebelum pemisah tata
     * letak diterapkan.
     *
     * @param  array<string, mixed>  $baris
     * @param  array<string, mixed>  $snapshot
     */
    private static function teksSelKoma(array $baris, string $kolom, array $snapshot, int $desimalSertifikat): string
    {
        $format = is_array($baris['format'] ?? null) ? $baris['format'] : [];
        $db = (int) ($baris['desimal'] ?? $desimalSertifikat);
        $tandaNol = (bool) ($baris['tanda_nol'] ?? true);
        $nilai = static fn (string $k): ?float => ($baris[$k] ?? null) === null ? null : (float) $baris[$k];

        return match ($kolom) {
            'standar' => isset($format['standar'])
                ? self::formatAngka($nilai('standard_value'), $format['standar'], $tandaNol)
                : (($baris['standar_ringkas'] ?? true)
                    ? Angka::nilaiStandar($nilai('standard_value'), $db)
                    : Angka::hasil($nilai('standard_value'), $db, tandaNol: $tandaNol)),
            'uut' => isset($format['uut'])
                ? self::formatAngka($nilai('unit_under_test'), $format['uut'], $tandaNol)
                : Angka::hasil($nilai('unit_under_test'), $db, tandaNol: $tandaNol),
            'koreksi' => ($baris['koreksi_kosong'] ?? false)
                ? '-'
                : (isset($format['koreksi'])
                    ? self::formatAngka($nilai('correction'), $format['koreksi'], $tandaNol)
                    : Angka::hasil($nilai('correction'), (int) ($snapshot['desimal_koreksi'] ?? $db), tandaNol: $tandaNol)),
            'sebaran' => $nilai('sebaran') === null
                ? '-'
                : (isset($format['sebaran'])
                    ? self::formatAngka($nilai('sebaran'), $format['sebaran'])
                    : Angka::hasil($nilai('sebaran'), 2)),
            'u95' => isset($format['u95'])
                ? self::formatAngka($nilai('u95'), $format['u95'])
                : Angka::hasil($nilai('u95'), (int) ($baris['desimal_u95'] ?? $db)),
            'remark' => filled($baris['remark'] ?? null) ? (string) $baris['remark'] : '—',
            'r2' => $nilai('r2') === null ? '' : Angka::id($nilai('r2'), 0),
            default => throw new InvalidArgumentException("Kolom hasil `{$kolom}` tidak dikenal."),
        };
    }

    /**
     * Teks `k` di kalimat faktor cakupan untuk satu baris. Urutan: format sel
     * baris (`format.k`), desimal profil (`desimal_k`), format `k` bawaan tata
     * letak (`format_k`, bentuk master `0`), lalu perilaku lama (2 desimal, nol
     * di belakang dibuang). Hanya CETAK — U95 sudah dihitung dengan k
     * sebenarnya dan tidak tersentuh.
     *
     * @param  array<string, mixed>  $baris
     * @param  array<string, mixed>  $tataLetak  hasil [untuk]; kosong = bentuk lama
     */
    public static function teksK(array $baris, float $k, array $tataLetak = []): string
    {
        $format = is_array($baris['format'] ?? null) ? $baris['format'] : [];
        $dbK = $baris['desimal_k'] ?? null;
        $bawaan = $tataLetak['format_k'] ?? null;

        $teks = match (true) {
            isset($format['k']) => self::formatAngka($k, $format['k']),
            $dbK !== null => Angka::id($k, (int) $dbK),
            $bawaan !== null => self::formatAngka($k, $bawaan),
            default => Angka::idRingkas($k, 2),
        };

        return self::angka($teks, $tataLetak);
    }

    /**
     * Desimal angka Excel untuk satu sel — padanan [teksSel]. `null` = General.
     *
     * @param  array<string, mixed>  $baris
     * @param  array<string, mixed>  $snapshot
     */
    public static function desimalKolom(array $baris, string $kolom, array $snapshot, int $desimalSertifikat): ?int
    {
        $format = is_array($baris['format'] ?? null) ? $baris['format'] : [];

        if (isset($format[$kolom])) {
            return self::desimalFormat($format[$kolom]);
        }

        $db = (int) ($baris['desimal'] ?? $desimalSertifikat);

        return match ($kolom) {
            'koreksi' => isset($snapshot['desimal_koreksi']) ? (int) $snapshot['desimal_koreksi'] : $db,
            'sebaran' => 2,
            'u95' => (int) ($baris['desimal_u95'] ?? $db),
            'r2' => 0,
            default => $db,
        };
    }

    /**
     * Judul kolom tabel hasil sebagai TEKS (Excel, dan PDF untuk kolom yang
     * judulnya dinyatakan profil). Judul U95 & R² bawaan PDF ber-HTML
     * (`U<sub>95%</sub>`) dan dibangun di blade.
     *
     * @param  array{kolom: string, judul: ?string}  $kolom
     * @param  array<string, mixed>  $snapshot
     */
    public static function judulKolom(array $kolom, array $snapshot): string
    {
        if ($kolom['judul'] !== null) {
            return $kolom['judul'];
        }

        return match ($kolom['kolom']) {
            'standar' => (string) ($snapshot['judul_standar'] ?? 'Standard'),
            'uut' => (string) ($snapshot['judul_uut'] ?? 'Unit Under Test'),
            'koreksi' => (string) ($snapshot['judul_koreksi'] ?? 'Correction'),
            'sebaran' => (string) ($snapshot['judul_sebaran'] ?? ''),
            'u95' => 'U95% (±)',
            'u95_relatif' => 'U95% ±',
            'remark' => 'Remark',
            'r2' => 'R2',
            default => substr($kolom['kolom'], strlen(self::AWALAN_TAMBAHAN)),
        };
    }

    /**
     * Satuan yang ditulis untuk satu kolom di kelompok bersatuan `$satuan`.
     * `satuan` kolom yang dinyatakan profil menang (`''` = tanpa satuan);
     * bawaannya: sebaran `%`, remark & R² tanpa satuan, sisanya satuan
     * kelompok.
     *
     * @param  array{kolom: string, satuan: ?string}  $kolom
     */
    public static function satuanKolom(array $kolom, ?string $satuan): ?string
    {
        if ($kolom['satuan'] !== null) {
            return $kolom['satuan'] === '' ? null : $kolom['satuan'];
        }

        if (str_starts_with($kolom['kolom'], self::AWALAN_TAMBAHAN)) {
            return null;
        }

        return match ($kolom['kolom']) {
            'sebaran' => '%',
            'u95_relatif' => '%OR',
            'remark', 'r2' => null,
            default => filled($satuan) ? $satuan : null,
        };
    }

    /**
     * Rentang sel `gabung` per kolom: `[kolom => [indeks baris => rowspan]]`,
     * `0` = sel itu tertelan sel gabung di atasnya. Sel bersebelahan yang
     * TEKS CETAKNYA sama digabung — yang digabung angka yang terbaca, bukan
     * nilai mentah yang kebetulan beda di desimal ke-12.
     *
     * @param  list<array<string, string>>  $teksBaris  teks per baris per kolom
     * @param  list<array{kolom: string, gabung: bool}>  $kolom
     * @return array<string, array<int, int>>
     */
    public static function rentangGabung(array $teksBaris, array $kolom, array $baris = []): array
    {
        // Identitas rentang (`kunci_gabung`, `CalibrationProfile::
        // kunciGabungTitik`) menang atas kesamaan teks: dua rentang yang
        // kebetulan tercetak sama tetap dua kotak.
        $pakaiKunci = array_filter(array_column($baris, 'kunci_gabung'), static fn ($v) => $v !== null) !== [];
        $rentang = [];

        foreach ($kolom as $k) {
            if (! $k['gabung']) {
                continue;
            }

            $nama = $k['kolom'];
            $awal = null;

            foreach ($teksBaris as $i => $teks) {
                $sama = $pakaiKunci && $nama !== 'r2'
                    ? ($baris[$awal ?? 0]['kunci_gabung'] ?? null) !== null
                        && ($baris[$awal ?? 0]['kunci_gabung'] ?? null) === ($baris[$i]['kunci_gabung'] ?? null)
                    : $awal !== null && $teksBaris[$awal][$nama] === $teks[$nama];

                if ($awal !== null && $sama) {
                    $rentang[$nama][$awal]++;
                    $rentang[$nama][$i] = 0;

                    continue;
                }

                $awal = $i;
                $rentang[$nama][$i] = 1;
            }
        }

        return $rentang;
    }

    /**
     * Letak sel angka di baris ringkas `Uncertainty U95% = ±`: di bawah kolom
     * koreksi (bentuk lama: kolom ketiga), atau kolom terakhir kalau tabelnya
     * tanpa koreksi. Nilainya = jumlah kolom yang ditutupi sel label.
     *
     * @param  list<array{kolom: string}>  $kolom
     */
    public static function posisiU95(array $kolom): int
    {
        $i = array_search('koreksi', array_column($kolom, 'kolom'), true);
        $i = $i === false ? count($kolom) - 1 : $i;

        return max(1, array_sum(array_map(self::lebarKolom(...), array_slice($kolom, 0, $i))));
    }

    /**
     * Sel kosong sesudah angka U95 di baris ringkas. Bentuk lama cuma mengisi
     * kolom R² (kolom sebaran Gaya dibiarkan tanpa sel — dipertahankan supaya
     * lembar lama tidak bergeser); susunan dari profil mengisi semua kolom
     * sisanya.
     *
     * @param  list<array{kolom: string}>  $kolom
     */
    public static function kosongSesudahU95(array $kolom, bool $susunanLama): int
    {
        $i = array_search('koreksi', array_column($kolom, 'kolom'), true);
        $i = $i === false ? count($kolom) - 1 : $i;
        $sesudah = array_slice($kolom, max(1, $i) + 1);

        return $susunanLama
            ? count(array_intersect(array_column($sesudah, 'kolom'), ['r2']))
            : array_sum(array_map(self::lebarKolom(...), $sesudah))
                // Sel angka U95 cuma memakai satu sel kolom yang ditempatinya;
                // sisa pecahannya diisi kosong.
                + self::lebarKolom($kolom[max(1, $i)] ?? []) - 1;
    }

    // ---- pemeriksa bentuk ------------------------------------------------

    private static function bool(string $kunci, mixed $nilai): bool
    {
        if (! is_bool($nilai)) {
            throw new InvalidArgumentException("Tata letak sertifikat `{$kunci}` harus boolean.");
        }

        return $nilai;
    }

    private static function teks(string $kunci, mixed $nilai): string
    {
        if (! is_string($nilai) || trim($nilai) === '') {
            throw new InvalidArgumentException("Tata letak sertifikat `{$kunci}` harus teks tidak kosong.");
        }

        return $nilai;
    }

    private static function teksAtauNull(string $kunci, mixed $nilai): ?string
    {
        if ($nilai === null) {
            return null;
        }

        return self::teks($kunci, $nilai);
    }

    /** @return list<string> */
    private static function daftarTeks(string $kunci, mixed $nilai): array
    {
        if (! is_array($nilai)) {
            throw new InvalidArgumentException("Tata letak sertifikat `{$kunci}` harus daftar teks.");
        }

        return array_map(static function ($t) use ($kunci): string {
            if (! is_string($t)) {
                throw new InvalidArgumentException("Tata letak sertifikat `{$kunci}` harus daftar teks.");
            }

            return $t;
        }, array_values($nilai));
    }

    /**
     * `[[kiri, kanan], …]`; sel = `null` (kosong) atau `['kunci' => …, 'label'
     * => …]` / `[kunci, label]`.
     *
     * @param  list<string>  $kunciSah
     * @return list<list<array{kunci: string, label: string}|null>>
     */
    private static function kepala(mixed $nilai, array $kunciSah): array
    {
        if (! is_array($nilai) || $nilai === []) {
            throw new InvalidArgumentException('Tata letak sertifikat `kepala` harus daftar baris tidak kosong.');
        }

        $baris = [];

        foreach (array_values($nilai) as $i => $b) {
            if (! is_array($b) || count($b) !== 2) {
                throw new InvalidArgumentException("Tata letak sertifikat `kepala` baris #{$i} harus berisi tepat dua sel (kiri, kanan).");
            }

            $baris[] = array_map(function ($sel) use ($i, $kunciSah): ?array {
                if ($sel === null) {
                    return null;
                }

                $sel = self::pasangan($sel, "kepala baris #{$i}");

                if (! in_array($sel['kunci'], $kunciSah, true)) {
                    throw new InvalidArgumentException("Tata letak sertifikat `kepala` baris #{$i}: kunci `{$sel['kunci']}` "
                        .'bukan field kepala snapshot maupun `isiTambahanKepala()` sesi ini.');
                }

                return $sel;
            }, array_values($b));
        }

        return $baris;
    }

    /** @return list<array{kolom: string, judul: ?string, satuan: ?string, gabung: bool}> */
    private static function periksaKolomHasil(mixed $nilai): array
    {
        if (! is_array($nilai) || $nilai === []) {
            throw new InvalidArgumentException('Tata letak sertifikat `kolom_hasil` harus daftar kolom tidak kosong.');
        }

        $hasil = [];

        foreach (array_values($nilai) as $i => $k) {
            $k = is_string($k) ? ['kolom' => $k] : $k;

            $namaKolom = is_array($k) ? ($k['kolom'] ?? null) : null;
            $tambahan = is_string($namaKolom) && str_starts_with($namaKolom, self::AWALAN_TAMBAHAN)
                && strlen($namaKolom) > strlen(self::AWALAN_TAMBAHAN);

            if (! is_array($k) || (! $tambahan && ! in_array($namaKolom, self::KOLOM_HASIL, true))) {
                throw new InvalidArgumentException("Tata letak sertifikat `kolom_hasil` #{$i}: kolom harus salah satu dari "
                    .implode(', ', self::KOLOM_HASIL).' atau `'.self::AWALAN_TAMBAHAN.'<kunci>`.');
            }

            $asing = array_diff(array_keys($k), ['kolom', 'judul', 'satuan', 'gabung', 'format', 'pecah', 'faktor']);

            if ($asing !== []) {
                throw new InvalidArgumentException("Tata letak sertifikat `kolom_hasil` #{$i}: kunci tidak dikenal ".implode(', ', $asing).'.');
            }

            $hasil[] = [
                'kolom' => $k['kolom'],
                'judul' => self::teksAtauNull('kolom_hasil.judul', $k['judul'] ?? null),
                'satuan' => isset($k['satuan']) ? (string) $k['satuan'] : null,
                // `gabung` = sel bernilai sama yang berurutan digabung jadi satu
                // kotak (rowspan) — U95 per rentang Centrifuge, satu U95 per
                // kamar Thermohygro. R² selalu satu kotak per kelompok.
                'gabung' => (bool) ($k['gabung'] ?? $k['kolom'] === 'r2'),
                // Format sel kolom ini bila baris tidak menyatakannya (kolom
                // tambahan & `u95_relatif` tidak punya hook format per baris).
                ...(isset($k['format']) ? ['format' => self::normalisasiFormat(['k' => $k['format']])['k']] : []),
                // Satu kolom master yang dipecah jadi beberapa sel (Timer
                // `H | M | S | S(0,001)`, `183!E18:W18`).
                ...(isset($k['pecah']) ? ['pecah' => self::periksaPecah($k['pecah'], $i)] : []),
                // Pengali satuan cetak kolom ini (U Anak Timbangan dalam mg,
                // `139!L16`: g × 1000). Hanya cetak; nilai snapshot tetap.
                ...(isset($k['faktor']) ? ['faktor' => self::faktorKolom($k['faktor'], $i)] : []),
            ];
        }

        $nama = array_column($hasil, 'kolom');

        if (count($nama) !== count(array_unique($nama))) {
            throw new InvalidArgumentException('Tata letak sertifikat `kolom_hasil` memuat kolom kembar.');
        }

        return $hasil;
    }

    /**
     * `[['judul' => 'H', 'faktor' => 3600, 'format' => '0.00'], …]`, faktor
     * dalam satuan nilai baris, urut dari terbesar.
     *
     * @return list<array{judul: string, faktor: float, format: string}>
     */
    private static function periksaPecah(mixed $nilai, int $i): array
    {
        if (! is_array($nilai) || count($nilai) < 2) {
            throw new InvalidArgumentException("Tata letak sertifikat `kolom_hasil` #{$i}: `pecah` harus daftar minimal dua sel.");
        }

        $hasil = [];
        $sebelum = INF;

        foreach (array_values($nilai) as $p) {
            $faktor = is_array($p) ? ($p['faktor'] ?? null) : null;

            if (! is_array($p) || ! is_string($p['judul'] ?? null) || ! is_numeric($faktor) || (float) $faktor <= 0.0
                || (float) $faktor >= $sebelum || array_diff(array_keys($p), ['judul', 'faktor', 'format']) !== []) {
                throw new InvalidArgumentException("Tata letak sertifikat `kolom_hasil` #{$i}: sel `pecah` harus `['judul', 'faktor' > 0 menurun, 'format']`.");
            }

            $sebelum = (float) $faktor;
            $hasil[] = [
                'judul' => $p['judul'],
                'faktor' => (float) $faktor,
                'format' => self::normalisasiFormat(['k' => $p['format'] ?? 'General'])['k'],
            ];
        }

        return $hasil;
    }

    private static function faktorKolom(mixed $nilai, int $i): float
    {
        if (! is_numeric($nilai) || (float) $nilai <= 0.0) {
            throw new InvalidArgumentException("Tata letak sertifikat `kolom_hasil` #{$i}: `faktor` harus angka > 0.");
        }

        return (float) $nilai;
    }

    /** @return list<array{kunci: string, label: string}> */
    private static function kolomStandar(mixed $nilai): array
    {
        if (! is_array($nilai) || $nilai === []) {
            throw new InvalidArgumentException('Tata letak sertifikat `kolom_standar_digunakan` harus daftar kolom tidak kosong.');
        }

        return array_map(fn ($k): array => self::pasangan($k, 'kolom_standar_digunakan'), array_values($nilai));
    }

    /** @return array{kunci: string, label: string} */
    private static function pasangan(mixed $sel, string $tempat): array
    {
        if (is_array($sel) && array_is_list($sel) && count($sel) === 2) {
            $sel = ['kunci' => $sel[0], 'label' => $sel[1]];
        }

        if (! is_array($sel) || ! is_string($sel['kunci'] ?? null) || ! is_string($sel['label'] ?? null)
            || array_diff(array_keys($sel), ['kunci', 'label']) !== []) {
            throw new InvalidArgumentException("Tata letak sertifikat {$tempat}: sel harus `['kunci' => …, 'label' => …]`.");
        }

        return ['kunci' => $sel['kunci'], 'label' => $sel['label']];
    }
}

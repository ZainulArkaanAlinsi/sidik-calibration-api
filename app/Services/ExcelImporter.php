<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentCategory;
use App\Models\Standard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Import master data dari Excel (spesifikasi poin 12C).
 *
 * Tujuannya masa transisi: data lab selama ini hidup di file Excel, dan ngetik
 * ulang ratusan baris ke panel itu justru sumber salah ketik yang mau
 * dihilangkan. Jadi file lamanya dibaca, dipetakan ke struktur database, terus
 * diisi otomatis.
 *
 * Dua hal yang bikin ini nggak berbahaya:
 *
 * 1. Header kolomnya DICOCOKKAN, bukan diurut. Excel bikinan orang beda-beda
 *    urutan kolomnya; yang dicocokin nama headernya (sesudah dinormalkan),
 *    dengan daftar alias per field. Kolom yang nggak dikenal diabaikan, bukan
 *    ditebak-tebak masuk ke field terdekat.
 * 2. Defaultnya UJI COBA (`dry run`). Admin lihat dulu berapa yang bakal
 *    dibuat/diperbarui/dilewati sama alasannya, baru jalanin beneran.
 */
class ExcelImporter
{
    public const TIPE = ['customers', 'equipments', 'standards'];

    /** Batas baris per file. Di atas ini, pecah filenya — bukan naikin limitnya. */
    private const MAX_BARIS = 5000;

    /**
     * Alias header per field. Ditulis dalam bentuk yang udah dinormalkan
     * (huruf kecil, tanpa spasi/tanda baca) — lihat `normalkan()`.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const PETA = [
        'customers' => [
            'nama' => ['nama', 'namapt', 'namapelanggan', 'owner', 'customer', 'perusahaan', 'client'],
            'alamat' => ['alamat', 'address'],
            'contact_person' => ['contactperson', 'pic', 'narahubung', 'kontak'],
            'telepon' => ['telepon', 'telp', 'phone', 'nohp', 'notelp'],
            'email' => ['email', 'surel'],
        ],
        'equipments' => [
            'nama_alat' => ['namaalat', 'equipmentname', 'alat', 'namaequipment'],
            'merk' => ['merk', 'manufacturer', 'merek', 'brand', 'pabrikan'],
            'model' => ['model', 'modeltype', 'tipe', 'type'],
            'serial_number' => ['serialnumber', 'noseri', 'nomorseri', 'sn', 'serialno'],
            'no_identifikasi' => ['noidentifikasi', 'nomoridentifikasi', 'kodealat', 'idalat'],
            'range_min' => ['rangemin', 'kapasitasmin', 'batasbawah', 'min'],
            'range_max' => ['rangemax', 'kapasitasmax', 'batasatas', 'max', 'kapasitas'],
            'satuan' => ['satuan', 'unit'],
            'resolusi' => ['resolusi', 'graduation', 'graduasi', 'resolution'],
            'toleransi' => ['toleransi', 'tolerance', 'batastoleransi'],
            'lokasi' => ['lokasi', 'location', 'penempatan'],
            'pelanggan' => ['pemilik', 'owner', 'pelanggan', 'namapt', 'customer'],
            // Kategori/besaran kalibrasi (massa, panjang, suhu, pH, …). Wajib:
            // kolom ini yang nyambungin alat ke kemampuan kalibrasi lab.
            'kategori' => ['kategori', 'category', 'besaran', 'kategorialat', 'jeniskalibrasi'],
            'tanggal_kalibrasi_terakhir' => ['tanggalkalibrasiterakhir', 'kalibrasiterakhir', 'lastcalibration'],
            'tanggal_jatuh_tempo' => ['tanggaljatuhtempo', 'jatuhtempo', 'duedate', 'nextcalibration'],
        ],
        'standards' => [
            'nama' => ['nama', 'name', 'namastandar'],
            'merk' => ['merk', 'merek', 'brand', 'manufacturer'],
            'model' => ['model', 'tipe', 'type', 'merktype'],
            'serial_number' => ['serialnumber', 'noseri', 'nomorseri', 'sn'],
            'no_sertifikat' => ['nosertifikat', 'nomorsertifikat', 'certificatenumber'],
            'tertelusur_ke' => ['tertelusurke', 'traceableto', 'traceabletosithrough', 'ketertelusuran'],
            'berlaku_sampai' => ['berlakusampai', 'validuntil', 'masaberlaku', 'expired'],
            'ketidakpastian' => ['ketidakpastian', 'uncertainty', 'u95'],
            'satuan_ketidakpastian' => ['satuanketidakpastian', 'uncertaintyunit', 'satuanu'],
            'faktor_cakupan' => ['faktorcakupan', 'coveragefactor', 'k'],
            'drift' => ['drift', 'driftpertahun'],
        ],
    ];

    /** Kolom yang WAJIB ketemu di header, kalau nggak filenya ditolak. */
    private const WAJIB = [
        'customers' => ['nama'],
        'equipments' => ['nama_alat'],
        'standards' => ['nama'],
    ];

    /**
     * Nama kolom yang dikenali per tipe — dipajang di panel/mobile biar admin
     * bisa ngerapiin header Excel-nya duluan.
     *
     * @return array<string, array<string, list<string>>>
     */
    public static function daftarKolom(): array
    {
        return self::PETA;
    }

    /**
     * Baca file & (kalau bukan uji coba) tulis ke database.
     *
     * @return array{
     *     tipe: string,
     *     uji_coba: bool,
     *     kolom_terpetakan: array<string, string>,
     *     kolom_diabaikan: list<string>,
     *     ringkasan: array<string, int>,
     *     baris: list<array<string, mixed>>,
     * }
     */
    public function jalankan(string $path, string $tipe, int $organizationId, bool $ujiCoba = true): array
    {
        $baris = $this->bacaBaris($path);

        abort_if($baris === [], 422, 'File-nya kosong — nggak ada baris yang bisa dibaca.');

        $header = array_shift($baris);
        $peta = $this->petakanHeader($header, $tipe);

        $kurang = array_diff(self::WAJIB[$tipe], array_keys($peta['terpetakan']));

        abort_if(
            $kurang !== [],
            422,
            'Kolom wajib nggak ketemu di header file: '.implode(', ', $kurang).'.',
        );

        abort_if(
            count($baris) > self::MAX_BARIS,
            422,
            'Filenya kebanyakan baris (maksimal '.self::MAX_BARIS.'). Pecah dulu per bagian.',
        );

        $hasil = [];
        $ringkasan = ['dibaca' => 0, 'dibuat' => 0, 'diperbarui' => 0, 'dilewati' => 0];

        $proses = function () use ($baris, $peta, $tipe, $organizationId, &$hasil, &$ringkasan): void {
            foreach ($baris as $i => $isi) {
                // +1 karena header udah diambil, +1 lagi karena Excel mulai dari 1.
                $nomorBaris = $i + 2;
                $nilai = $this->ambilNilai($isi, $peta['indeks']);

                if ($this->kosongSemua($nilai)) {
                    continue;
                }

                $ringkasan['dibaca']++;
                $catatan = $this->proses($tipe, $nilai, $organizationId);

                $ringkasan[$catatan['tindakan']] = ($ringkasan[$catatan['tindakan']] ?? 0) + 1;
                $hasil[] = ['baris' => $nomorBaris, ...$catatan];
            }
        };

        if ($ujiCoba) {
            // Uji coba tetap NULIS beneran dulu — cuma dengan begitu pencocokan
            // "sudah ada / belum" & error constraint kelihatan apa adanya, bukan
            // ditebak. Habis itu semuanya dibatalin, jadi DB nggak berubah.
            DB::beginTransaction();

            try {
                $proses();
            } finally {
                DB::rollBack();
            }
        } else {
            // Sekali jalan, satu transaksi: file yang masuk setengah jauh lebih
            // repot dibenerin daripada gagal total lalu diulang.
            DB::transaction($proses);
        }

        return [
            'tipe' => $tipe,
            'uji_coba' => $ujiCoba,
            'kolom_terpetakan' => $peta['terpetakan'],
            'kolom_diabaikan' => $peta['diabaikan'],
            'ringkasan' => $ringkasan,
            'baris' => $hasil,
        ];
    }

    /**
     * @param  array<string, mixed>  $nilai
     * @return array<string, mixed>
     */
    private function proses(string $tipe, array $nilai, int $organizationId): array
    {
        return match ($tipe) {
            'customers' => $this->prosesPelanggan($nilai, $organizationId),
            'standards' => $this->prosesStandar($nilai, $organizationId),
            'equipments' => $this->prosesAlat($nilai, $organizationId),
            default => ['tindakan' => 'dilewati', 'alasan' => 'Tipe import nggak dikenal.'],
        };
    }

    /**
     * @param  array<string, mixed>  $nilai
     * @return array<string, mixed>
     */
    private function prosesPelanggan(array $nilai, int $organizationId): array
    {
        $nama = trim((string) ($nilai['nama'] ?? ''));

        if ($nama === '') {
            return ['tindakan' => 'dilewati', 'alasan' => 'Nama PT kosong.'];
        }

        // Kunci pencocokannya NAMA: file Excel lab nggak punya id database, dan
        // nama PT itu yang dipakai orang buat ngenalin barisnya.
        $pelanggan = Customer::where('organization_id', $organizationId)->where('nama', $nama)->first();
        $atribut = array_filter([
            'alamat' => $nilai['alamat'] ?? null,
            'contact_person' => $nilai['contact_person'] ?? null,
            'telepon' => $nilai['telepon'] ?? null,
            'email' => $nilai['email'] ?? null,
        ], fn ($v): bool => filled($v));

        if ($pelanggan) {
            $pelanggan->update($atribut);

            return ['tindakan' => 'diperbarui', 'nama' => $nama];
        }

        Customer::create([...$atribut, 'organization_id' => $organizationId, 'nama' => $nama]);

        return ['tindakan' => 'dibuat', 'nama' => $nama];
    }

    /**
     * @param  array<string, mixed>  $nilai
     * @return array<string, mixed>
     */
    private function prosesStandar(array $nilai, int $organizationId): array
    {
        $nama = trim((string) ($nilai['nama'] ?? ''));

        if ($nama === '') {
            return ['tindakan' => 'dilewati', 'alasan' => 'Nama standar kosong.'];
        }

        $serial = trim((string) ($nilai['serial_number'] ?? ''));

        $atribut = array_filter([
            'merk' => $nilai['merk'] ?? null,
            'model' => $nilai['model'] ?? null,
            'no_sertifikat' => $nilai['no_sertifikat'] ?? null,
            'tertelusur_ke' => $nilai['tertelusur_ke'] ?? null,
            'berlaku_sampai' => $this->tanggal($nilai['berlaku_sampai'] ?? null),
            'ketidakpastian' => $this->angka($nilai['ketidakpastian'] ?? null),
            'satuan_ketidakpastian' => $nilai['satuan_ketidakpastian'] ?? null,
            'faktor_cakupan' => $this->angka($nilai['faktor_cakupan'] ?? null),
            'drift' => $this->angka($nilai['drift'] ?? null),
        ], fn ($v): bool => filled($v));

        // Nomor seri itu penanda paling pasti buat alat standar; kalau kosong,
        // jatuh ke nama.
        $standar = Standard::query()
            ->where('organization_id', $organizationId)
            ->when(
                $serial !== '',
                fn (Builder $q) => $q->where('serial_number', $serial),
                fn (Builder $q) => $q->where('nama', $nama),
            )
            ->first();

        if ($standar) {
            $standar->update($atribut);

            return ['tindakan' => 'diperbarui', 'nama' => $nama];
        }

        Standard::create([
            ...$atribut,
            'organization_id' => $organizationId,
            'nama' => $nama,
            'serial_number' => $serial !== '' ? $serial : null,
        ]);

        return ['tindakan' => 'dibuat', 'nama' => $nama];
    }

    /**
     * @param  array<string, mixed>  $nilai
     * @return array<string, mixed>
     */
    private function prosesAlat(array $nilai, int $organizationId): array
    {
        $nama = trim((string) ($nilai['nama_alat'] ?? ''));

        if ($nama === '') {
            return ['tindakan' => 'dilewati', 'alasan' => 'Nama alat kosong.'];
        }

        $namaPelanggan = trim((string) ($nilai['pelanggan'] ?? ''));

        if ($namaPelanggan === '') {
            return ['tindakan' => 'dilewati', 'alasan' => "Alat \"{$nama}\" nggak punya kolom pemilik/PT."];
        }

        $pelanggan = Customer::where('organization_id', $organizationId)
            ->where('nama', $namaPelanggan)
            ->first();

        if ($pelanggan === null) {
            // Sengaja NGGAK bikin PT baru diam-diam: salah ketik nama PT di satu
            // baris bakal ngasilin pelanggan kembar yang susah digabung lagi.
            return [
                'tindakan' => 'dilewati',
                'alasan' => "PT \"{$namaPelanggan}\" belum ada. Import data pelanggan dulu.",
            ];
        }

        $namaKategori = trim((string) ($nilai['kategori'] ?? ''));

        $kategori = $namaKategori === ''
            ? null
            : EquipmentCategory::where('organization_id', $organizationId)
                ->where(fn ($q) => $q->where('kode', $namaKategori)->orWhere('nama', $namaKategori))
                ->first();

        // Kategori itu yang nyambungin alat ke kemampuan kalibrasi (CMC) lab.
        // Nebak kategori dari nama alat gampang meleset dan hasilnya
        // ketidakpastian yang salah di sertifikat — mending dilewati & dibenerin
        // filenya.
        if ($kategori === null) {
            return [
                'tindakan' => 'dilewati',
                'alasan' => $namaKategori === ''
                    ? "Alat \"{$nama}\" nggak punya kolom kategori/besaran kalibrasi."
                    : "Kategori \"{$namaKategori}\" belum terdaftar di master data kategori.",
            ];
        }

        $serial = trim((string) ($nilai['serial_number'] ?? ''));

        $atribut = array_filter([
            'merk' => $nilai['merk'] ?? null,
            'model' => $nilai['model'] ?? null,
            'no_identifikasi' => $nilai['no_identifikasi'] ?? null,
            'range_min' => $this->angka($nilai['range_min'] ?? null),
            'range_max' => $this->angka($nilai['range_max'] ?? null),
            'satuan' => $nilai['satuan'] ?? null,
            'resolusi' => $this->angka($nilai['resolusi'] ?? null),
            'toleransi' => $this->angka($nilai['toleransi'] ?? null),
            'lokasi' => $nilai['lokasi'] ?? null,
            'tanggal_kalibrasi_terakhir' => $this->tanggal($nilai['tanggal_kalibrasi_terakhir'] ?? null),
            'tanggal_jatuh_tempo' => $this->tanggal($nilai['tanggal_jatuh_tempo'] ?? null),
        ], fn ($v): bool => filled($v));

        $alat = Equipment::query()
            ->where('organization_id', $organizationId)
            ->when(
                $serial !== '',
                fn (Builder $q) => $q->where('serial_number', $serial),
                fn (Builder $q) => $q->where('nama_alat', $nama)->where('customer_id', $pelanggan->id),
            )
            ->first();

        if ($alat) {
            $alat->update([
                ...$atribut,
                'customer_id' => $pelanggan->id,
                'equipment_category_id' => $kategori->id,
            ]);

            return ['tindakan' => 'diperbarui', 'nama' => $nama];
        }

        Equipment::create([
            ...$atribut,
            'organization_id' => $organizationId,
            'customer_id' => $pelanggan->id,
            'equipment_category_id' => $kategori->id,
            'nama_alat' => $nama,
            'serial_number' => $serial !== '' ? $serial : null,
            'status' => Equipment::STATUS_AKTIF,
        ]);

        return ['tindakan' => 'dibuat', 'nama' => $nama];
    }

    /**
     * Cocokin header file sama field database.
     *
     * Balikannya dipisah dua: `terpetakan` (field → judul kolom apa adanya,
     * buat ditampilin ke admin sebagai "kolom ini kebaca sebagai apa") dan
     * `indeks` (field → nomor kolom, buat ngambil nilainya).
     *
     * @param  list<mixed>  $header
     * @return array{
     *     terpetakan: array<string, string>,
     *     indeks: array<string, int>,
     *     diabaikan: list<string>,
     * }
     */
    private function petakanHeader(array $header, string $tipe): array
    {
        $terpetakan = [];
        $indeks = [];
        $diabaikan = [];

        foreach ($header as $kolom => $judul) {
            $normal = $this->normalkan((string) $judul);

            if ($normal === '') {
                continue;
            }

            $field = null;

            foreach (self::PETA[$tipe] as $namaField => $alias) {
                if (in_array($normal, $alias, true)) {
                    $field = $namaField;
                    break;
                }
            }

            // Satu field cuma boleh diambil dari satu kolom — kolom kembar
            // (mis. dua kolom "Satuan") pakai yang pertama.
            if ($field !== null && ! isset($terpetakan[$field])) {
                $terpetakan[$field] = (string) $judul;
                $indeks[$field] = (int) $kolom;

                continue;
            }

            $diabaikan[] = (string) $judul;
        }

        return ['terpetakan' => $terpetakan, 'indeks' => $indeks, 'diabaikan' => $diabaikan];
    }

    /**
     * @param  list<mixed>  $isi
     * @param  array<string, int>  $indeks
     * @return array<string, mixed>
     */
    private function ambilNilai(array $isi, array $indeks): array
    {
        $nilai = [];

        foreach ($indeks as $field => $kolom) {
            $mentah = $isi[$kolom] ?? null;

            $nilai[$field] = is_string($mentah) ? trim($mentah) : $mentah;
        }

        return $nilai;
    }

    /** @param  array<string, mixed>  $nilai */
    private function kosongSemua(array $nilai): bool
    {
        foreach ($nilai as $v) {
            if (filled($v)) {
                return false;
            }
        }

        return true;
    }

    /**
     * `1.234,56` (format Indonesia) dan `1,234.56` (format Inggris) dua-duanya
     * kebaca. Excel lab suka campur dua-duanya di file yang sama.
     */
    private function angka(mixed $nilai): ?float
    {
        if ($nilai === null || $nilai === '') {
            return null;
        }

        if (is_numeric($nilai)) {
            return (float) $nilai;
        }

        $teks = preg_replace('/[^0-9,.\-]/', '', (string) $nilai) ?? '';

        if ($teks === '' || $teks === '-') {
            return null;
        }

        // Pemisah desimalnya = tanda baca yang muncul PALING BELAKANG.
        $posisiKoma = strrpos($teks, ',');
        $posisiTitik = strrpos($teks, '.');

        if ($posisiKoma !== false && ($posisiTitik === false || $posisiKoma > $posisiTitik)) {
            $teks = str_replace('.', '', $teks);
            $teks = str_replace(',', '.', $teks);
        } else {
            $teks = str_replace(',', '', $teks);
        }

        return is_numeric($teks) ? (float) $teks : null;
    }

    private function tanggal(mixed $nilai): ?Carbon
    {
        if ($nilai instanceof \DateTimeInterface) {
            return Carbon::instance($nilai);
        }

        if (! filled($nilai)) {
            return null;
        }

        try {
            // Excel Indonesia paling sering nulis d/m/Y — Carbon defaultnya
            // baca m/d/Y, jadi formatnya dicoba eksplisit dulu.
            foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd F Y'] as $format) {
                $tanggal = Carbon::createFromFormat($format, (string) $nilai);

                if ($tanggal !== false) {
                    return $tanggal->startOfDay();
                }
            }

            return Carbon::parse((string) $nilai)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** `Serial Number` → `serialnumber`; `No. Seri` → `noseri`. */
    private function normalkan(string $teks): string
    {
        return Str::of($teks)->lower()->replaceMatches('/[^a-z0-9]/', '')->toString();
    }

    /**
     * Baca semua baris jadi array biasa. File kecil (dibatesin MAX_BARIS), jadi
     * dimuat sekaligus — nggak perlu streaming.
     *
     * @return list<list<mixed>>
     */
    private function bacaBaris(string $path): array
    {
        $reader = str_ends_with(strtolower($path), '.csv') ? new CsvReader : new XlsxReader;
        $reader->open($path);

        $baris = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                /** @var Row $row */
                $baris[] = $row->toArray();
            }

            // Cuma sheet pertama. File Excel lab sering punya sheet contekan /
            // rumus di belakang yang bukan data.
            break;
        }

        $reader->close();

        return $baris;
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * @mixin IdeHelperCalibrationSession
 */
#[Fillable([
    'organization_id', 'equipment_id', 'order_item_id', 'teknisi_id', 'client_request_id', 'standard_id', 'reviewed_by',
    'calibration_method_id', 'thermohygro_standard_id',
    'nomor_sesi', 'nomor_order', 'input_method', 'status', 'keputusan', 'tanggal_kalibrasi',
    'tanggal_terima', 'lokasi', 'lokasi_nama', 'room_id', 'suhu_ruang', 'suhu_ketidakpastian', 'kelembaban',
    'kelembaban_ketidakpastian', 'suhu_awal', 'suhu_akhir', 'kelembaban_awal', 'kelembaban_akhir',
    // Parameter kondisi lingkungan KETIGA, cuma dipakai Gas Detector — lihat
    // migrasi 2026_08_20_100000.
    'tekanan_awal', 'tekanan_akhir', 'tekanan_udara', 'tekanan_ketidakpastian',
    // Kolom `Time` di tabel Env. Condition — lihat migrasi 2026_08_13_170000.
    'waktu_awal', 'waktu_akhir',
    'catatan_revisi', 'revisi_field', 'catatan_teknisi', 'submitted_at', 'reviewed_at',
    // Identitas alat & pemilik seperti yang DICATAT TEKNISI di lembar kerja —
    // bukan salinan master. Lihat migrasi 2026_07_29_120000.
    'alat_model', 'alat_serial_number', 'alat_merk', 'pemilik_nama', 'pemilik_alamat',
    // Rentang ukur / kapasitas / resolusi versi teknisi — lihat migrasi
    // 2026_08_13_100000.
    'spesifikasi_alat',
    // Snapshot hasil olah data Autoklaf (JSON) — alat ke-8 nggak lewat
    // raw_measurements/uncertainty_calculations. Lihat migrasi 2026_08_19_120000.
    'hasil_autoclave',
    // Mode kalibrasi (measure/source) & tipe sensor termokopel — dua-duanya
    // nentuin ANGKA di sesi TITS, dan dua-duanya milik sesi bukan alat. Lihat
    // migrasi 2026_08_21_100000.
    'mode_kalibrasi', 'tipe_sensor',
    // Alat bantu (dryblock A/B, oilbath satu/dua), tipe pencelupan termometer
    // gelas, dan tiga pembacaan uji titik es — tiganya nentuin ANGKA di sesi
    // alat ke-18..20. Lihat migrasi 2026_08_26_120000.
    'alat_bantu', 'tipe_pencelupan', 'titik_es',
])]
class CalibrationSession extends Model
{
    use Diaudit, HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_MENUNGGU_APPROVAL = 'menunggu_approval';

    public const STATUS_DISETUJUI = 'disetujui';

    public const STATUS_PERLU_REVISI = 'perlu_revisi';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_kalibrasi' => 'date',
            'tanggal_terima' => 'date',
            // Kode kolom yang diminta admin dibetulin waktu nolak.
            'revisi_field' => 'array',
            // Kunci → nilai apa adanya yang diketik teknisi, mis.
            // `rentang_ukur_transmitan` → `0-100`. Kuncinya ditentuin bentuk
            // lembar kerja, bukan dikarang HP.
            'spesifikasi_alat' => 'array',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'suhu_ruang' => 'float',
            'suhu_ketidakpastian' => 'float',
            'kelembaban' => 'float',
            'kelembaban_ketidakpastian' => 'float',
            'suhu_awal' => 'float',
            'suhu_akhir' => 'float',
            'kelembaban_awal' => 'float',
            'kelembaban_akhir' => 'float',
            'tekanan_awal' => 'float',
            'tekanan_akhir' => 'float',
            'tekanan_udara' => 'float',
            'tekanan_ketidakpastian' => 'float',
            // Snapshot hasil olah data Autoklaf (Section A/B/C + budget + input).
            'hasil_autoclave' => 'array',
            // Tiga pembacaan uji titik es termometer gelas — yang dipakai
            // rentangnya (Tmax − Tmin), dihitung ulang tiap kali, bukan
            // disimpan jadi.
            'titik_es' => 'array',
        ];
    }

    /** Sesi Autoklaf — hasilnya di `hasil_autoclave`, bukan di titik ukur. */
    public function adalahAutoclave(): bool
    {
        return $this->hasil_autoclave !== null;
    }

    /** Kolom `Time` baris `First` di Env. Condition. */
    protected function waktuAwal(): Attribute
    {
        return self::jam();
    }

    /** Kolom `Time` baris `End` di Env. Condition. */
    protected function waktuAkhir(): Attribute
    {
        return self::jam();
    }

    /**
     * Jam lembar kerja: DISIMPAN `H:i:s`, DIBACA `H:i`.
     *
     * Normalisasinya bukan kerapian, tapi syarat biar hasilnya sama di dua mesin
     * database yang dipakai proyek ini. Kolom `time` MySQL selalu memulangkan
     * `08:30:00`, sementara SQLite (yang dipakai test) memulangkan persis apa
     * yang ditulis — `08:30` kalau itu yang dikirim HP. Tanpa normalisasi, test
     * hijau di SQLite dan responsnya beda bentuk di produksi.
     *
     * `H:i` yang dibaca balik ngikut kertasnya: kolom `Time` di lembar kerja
     * ditulis jam:menit, detiknya nggak pernah ada.
     */
    private static function jam(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $nilai): ?string => $nilai === null || $nilai === ''
                ? null
                : substr($nilai, 0, 5),
            // Nilai ngawur sengaja dibiarkan melempar, bukan diam-diam jadi
            // null: jam yang hilang tanpa suara lebih susah ketahuan daripada
            // sesi yang gagal disimpan.
            set: static fn (?string $nilai): ?string => $nilai === null || trim($nilai) === ''
                ? null
                : Carbon::parse(trim($nilai))->format('H:i:s'),
        );
    }

    /**
     * Field administratif — dihapus dari layar teknisi (spesifikasi poin 1) dan
     * dibuang dari payload-nya sama `CalibrationRequest`.
     *
     * Isinya: Order Number, Calibration Method, plus ketidakpastian kondisi
     * ruang (angka ± di Env. Condition — datangnya dari sertifikat thermohygro,
     * bukan dari yang dilihat teknisi di lapangan).
     *
     * `tanggal_terima`, `suhu_ruang`, `kelembaban`, dan `room_id` SENGAJA nggak
     * di sini: itu fakta lapangan yang cuma teknisi yang tau, dan spesifikasi
     * nggak nyuruh dihapus dari layar teknisi.
     *
     * **`thermohygro_standard_id` DIKELUARKAN 29 Juli 2026.** Awalnya masuk
     * sini ngikut spesifikasi poin 1, tapi di praktiknya salah: unit thermohygro
     * mana yang kepakai itu fakta lapangan — teknisi yang bawa TH-2 ke lokasi
     * pelanggan atau makai TH-4 di lab, dan admin nggak punya cara tau selain
     * nanya. Selama field ini administratif, kiriman teknisi buat kolom
     * "6. Thermohygro used" dibuang diam-diam sama `prepareForValidation()` —
     * kolomnya keisi di HP, nyampe server jadi null.
     *
     * @return array<int, string>
     */
    public static function fieldAdmin(): array
    {
        // `calibration_method_id` KELUAR dari daftar ini (26 Agt 2026).
        //
        // Metode kalibrasi itu keputusan lapangan: teknisi yang tahu alatnya
        // dikalibrasi dengan metode mana, dan buat alat yang BARU didaftarkan
        // dari lembar kerja, nggak ada admin yang bisa mengisinya lebih dulu.
        // Selama dia di sini, `CalibrationRequest::prepareForValidation()`
        // membuangnya dari tiap kiriman teknisi — DIAM-DIAM, karena memang
        // sengaja dibuang bukan ditolak. Teknisi memilih metodenya, menekan
        // kirim, dan kolomnya sampai di admin dalam keadaan kosong.
        //
        // Yang TETAP admin-only: nomor order (nomor administratif lab) dan dua
        // kolom ketidakpastian kondisi lingkungan (dihitung dari sertifikat
        // thermohygro, bukan diketik siapa pun).
        return [
            'nomor_order',
            'suhu_ketidakpastian', 'kelembaban_ketidakpastian',
        ];
    }

    /**
     * Titik ukur yang NENTUIN hasil sesi: yang marginnya paling mepet ke batas
     * toleransi (|error| + U terbesar).
     *
     * Sesi punya banyak titik, tapi sertifikat cuma nampilin satu keputusan —
     * dan keputusannya digerakin sama titik terburuk. Satu titik FAIL bikin
     * seluruh sesi FAIL, walaupun titik lainnya lolos semua.
     */
    /**
     * Ringkasan status sertifikat SEMUA standar yang kepakai di sesi ini —
     * jadi banner merah di kepala lembar kerja ("ONE OR MORE STANDARD EXPIRED").
     *
     * Yang dihitung: standar default sesi, thermohygro, standar per titik ukur
     * (buffer pH 4/7/10 masing-masing punya sertifikat sendiri), dan baris Usage
     * Check. Diambil dari relasi yang UDAH dimuat — jangan nambah query di sini,
     * ini kepanggil buat tiap baris di daftar sesi.
     *
     * Yang dilaporin status TERBURUK: satu standar kadaluarsa udah cukup bikin
     * seluruh sesi nggak bisa diterbitin, jadi banner-nya nggak boleh kalem cuma
     * karena standar lain masih valid.
     *
     * @return array{ringkasan: string, pesan: string|null, standar: list<array{id: int, nama: string|null, status: string, hari_menuju_kadaluarsa: int|null}>}
     */
    public function statusStandar(int $ambangHari): array
    {
        $semua = collect([$this->standard, $this->thermohygro])
            ->concat($this->uncertaintyCalculations->pluck('standard'))
            ->concat($this->relationLoaded('standarDicek') ? $this->standarDicek : [])
            // Saringnya per tipe, bukan `filter()` polos. Yang masuk ke sini
            // harus `Standard` — `map(fn (Standard $s))` di bawah bertipe keras,
            // jadi satu isian nyeleneh bikin SELURUH daftar sesi mati 500, bukan
            // cuma barisnya. Pernah kejadian: kolom teks lama `thermohygro`
            // nutupin relasi senama, dibersihin migrasi 2026_07_29_090000.
            ->filter(fn (mixed $s): bool => $s instanceof Standard)
            // Standar yang sama bisa nongol dari dua jalur (mis. standar default
            // sesi juga kepakai di titik pertama) — cukup dihitung sekali.
            ->unique('id')
            ->values();

        $rincian = $semua
            ->map(fn (Standard $s): array => [
                'id' => $s->id,
                'nama' => $s->nama,
                'status' => $s->statusKalibrasi($ambangHari),
                'hari_menuju_kadaluarsa' => $s->hariMenujuKadaluarsa(),
            ])
            ->all();

        $status = array_column($rincian, 'status');

        $ringkasan = match (true) {
            in_array(Standard::STATUS_EXPIRED, $status, true) => Standard::STATUS_EXPIRED,
            in_array(Standard::STATUS_WARNING, $status, true) => Standard::STATUS_WARNING,
            default => Standard::STATUS_VALID,
        };

        return [
            'ringkasan' => $ringkasan,
            // Teks banner udah siap tampil — mobile jangan nyusun sendiri, biar
            // kalimatnya sama sama yang tercetak di lembar kerja. `null` = nggak
            // ada banner sama sekali (semua standar aman).
            'pesan' => match ($ringkasan) {
                Standard::STATUS_EXPIRED => 'ONE OR MORE STANDARD EXPIRED',
                Standard::STATUS_WARNING => 'ONE OR MORE STANDARD NEAR EXPIRY',
                default => null,
            },
            'standar' => $rincian,
        ];
    }

    public function titikPenentu(): ?UncertaintyCalculation
    {
        return $this->uncertaintyCalculations
            ->sortByDesc(fn (UncertaintyCalculation $titik): float => abs($titik->error) + $titik->ketidakpastian_diperluas)
            ->first();
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * Alat yang diterima lewat order, kalau sesinya emang lahir dari order.
     * Nullable: sesi lama (sebelum ada entitas order) dan sesi dadakan yang
     * dibikin teknisi langsung nggak punya ini.
     *
     * @return BelongsTo<OrderItem, $this>
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<User, $this> */
    public function teknisi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teknisi_id');
    }

    /** Admin yang approve/reject — penandatangan sertifikat. */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * `withTrashed()` itu WAJIB di sini, bukan pemanis.
     *
     * Standar yang dipensiunin di-soft-delete. Tanpa ini, sesi kalibrasi dari
     * tahun lalu bakal balikin `standar_acuan: null` begitu standarnya dihapus —
     * ketertelusurannya ilang, padahal itu justru yang dicari asesor waktu audit.
     *
     * Dipanggil sebagai statement, bukan dirantai: `withTrashed()` nyetel
     * query relasinya di tempat, tapi tipe baliknya `Builder` (lewat `@mixin`
     * di `Relation`), bukan `BelongsTo`. Dirantai bikin nilai balik method ini
     * bohong sama tipe yang dideklarasi.
     *
     * @return BelongsTo<Standard, $this>
     */
    public function standard(): BelongsTo
    {
        $relasi = $this->belongsTo(Standard::class);
        $relasi->withTrashed();

        return $relasi;
    }

    /** Ruangan lab tempat sesi dikerjain — "Calibration Location" di sertifikat. */
    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Thermohygro yang dipakai nyatet kondisi ruang. `withTrashed()` dengan
     * alasan yang sama kayak `standard()`: ketertelusuran sertifikat lama.
     *
     * @return BelongsTo<Standard, $this>
     */
    public function thermohygro(): BelongsTo
    {
        $relasi = $this->belongsTo(Standard::class, 'thermohygro_standard_id');
        $relasi->withTrashed();

        return $relasi;
    }

    /**
     * IK yang dipakai. `withTrashed()` — revisi IK yang dipensiunin tetap harus
     * kebaca di sertifikat yang diterbitin waktu revisi itu masih berlaku.
     *
     * @return BelongsTo<CalibrationMethod, $this>
     */
    public function calibrationMethod(): BelongsTo
    {
        $relasi = $this->belongsTo(CalibrationMethod::class, 'calibration_method_id');
        $relasi->withTrashed();

        return $relasi;
    }

    /**
     * Kolom "Usage Check" di lembar kerja: standar mana aja yang dicentang
     * teknisi. `withoutGlobalScope(SoftDeletingScope::class)` dengan alasan yang
     * sama kayak `standard()` — standar yang udah dipensiunin tetap harus
     * kebaca di lembar kerja & sertifikat lama.
     *
     * @return BelongsToMany<Standard, $this>
     */
    public function standarDicek(): BelongsToMany
    {
        // Urutannya DIPATOK, alasannya sama dengan [uncertaintyCalculations]:
        // standar "Usage Check" ikut masuk tabel ketertelusuran sertifikat, di
        // ekor daftar yang lahir dari titik ukur. Diurutkan `standards.id` —
        // urutan standar itu didaftarkan di master lab, yang stabil dan tidak
        // ikut berubah waktu barisnya di-update.
        return $this->belongsToMany(Standard::class, 'calibration_session_standard')
            ->withPivot(['dipakai', 'keterangan'])
            ->withTimestamps()
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->orderBy('standards.id');
    }

    /**
     * @return HasMany<RawMeasurement, $this>
     *
     * Urutannya DIPATOK, lihat alasan panjangnya di [uncertaintyCalculations].
     * Yang kena di sini `snapshot['satuan']`, yang diambil dari
     * `rawMeasurements->first()?->satuan`: tanpa urutan pasti, "baris pertama"
     * itu baris mana pun yang kebetulan dikembalikan database.
     */
    public function rawMeasurements(): HasMany
    {
        return $this->hasMany(RawMeasurement::class)
            ->orderBy('titik_ke')
            ->orderBy('pembacaan_ke')
            ->orderBy('id');
    }

    /**
     * @return HasMany<UncertaintyCalculation, $this>
     *
     * ## Kenapa urutannya dipatok
     *
     * SQL tidak menjamin urutan baris tanpa `ORDER BY` — dan MySQL memang
     * memakai kebebasan itu; urutannya bisa bergeser sesudah `UPDATE`.
     *
     * Itu bocor ke dokumen yang sudah terbit. `CertificateSnapshotBuilder::
     * standarDigunakan()` membaca relasi ini apa adanya, dan hasilnya dicetak
     * apa adanya jadi tabel "Standards Used" di sertifikat terakreditasi. 3 Sep
     * 2026, dua kali `sertifikat:bangun-ulang` berturut-turut di MySQL yang sama
     * dengan kode yang sama memberi hasil beda-beda: tiga sertifikat berpindah
     * antara "berubah" dan "nggak berubah" tiap jalan, dan tiap jalan menulis
     * ulang PDF-nya. Dibuktikan dengan membalik urutan koleksinya: snapshot-nya
     * ikut berubah, cuma di kunci `standar_digunakan`.
     *
     * Nol error muncul, dan 2.938 test tetap hijau — karena test jalan di
     * SQLite, yang urutannya kebetulan stabil. Produksi MySQL, tidak.
     *
     * `titik_ke` yang di depan itu pilihan pemilik lab: tabel ketertelusuran
     * mengikuti urutan titik ukur, sama seperti lembar kerjanya. `id` cuma
     * pemecah seri — alat yang satu titiknya punya beberapa baris (Chlorine
     * Free/Total, Spectrophotometer tiga blok) butuh itu supaya `sortBy`
     * yang stabil tidak jatuh balik ke urutan database.
     */
    public function uncertaintyCalculations(): HasMany
    {
        return $this->hasMany(UncertaintyCalculation::class)
            ->orderBy('titik_ke')
            ->orderBy('id');
    }

    /**
     * Keputusan SESI yang seharusnya, diturunkan dari keputusan titik-titiknya.
     *
     * Aturannya cuma empat baris, tapi tiga pemakainya dulu tahu sendiri-
     * sendiri dan dua di antaranya sempat beda bunyi:
     *
     *  - `CalibrationController` waktu menyimpan sesi,
     *  - `CalibrationValidator` waktu memeriksa sesi tersimpan,
     *  - seeder yang menyusun sesi contoh tanpa lewat API.
     *
     * Validator sempat cuma `contains FAIL ? FAIL : PASS`, jadi sesi yang SEMUA
     * titiknya tidak divonis dituntut ber-keputusan `PASS` — padahal tidak ada
     * satu pun kriteria kelulusan yang pernah diperiksa. Itu kelas kekeliruan
     * yang sama dengan `default => PASS` yang dulu menstempel sesi
     * Conductivity & Spectrophotometer lulus.
     *
     * Empat kemungkinannya:
     *
     *  - **`null` kalau belum ada titik yang kehitung.** Lembar setengah jadi
     *    nggak punya hasil, dan nulis "PASS" di situ jauh lebih berbahaya
     *    daripada ngosongin.
     *  - **`FAIL` kalau ADA satu titik yang FAIL.** Satu titik jatuh bikin
     *    seluruh sesi jatuh.
     *  - **`null` kalau SEMUA titiknya nggak divonis.** Alat tanpa batas
     *    keberterimaan (Autoklaf, DO Meter, Gas Detector), atau Viscometer yang
     *    spindle & RPM-nya belum diisi sehingga MPE-nya nggak bisa dihitung.
     *  - **`PASS`** buat sisanya.
     *
     * @param  Collection<int, UncertaintyCalculation>  $titik
     */
    public static function keputusanDariTitik($titik): ?string
    {
        return match (true) {
            $titik->isEmpty() => null,
            $titik->contains('keputusan', 'FAIL') => 'FAIL',
            $titik->every(fn (UncertaintyCalculation $t): bool => $t->keputusan === null) => null,
            default => 'PASS',
        };
    }

    /** @return HasOne<Certificate, $this> */
    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }
}

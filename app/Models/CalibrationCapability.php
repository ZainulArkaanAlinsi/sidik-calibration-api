<?php

namespace App\Models;

use App\Exceptions\KemampuanLintasOrganisasi;
use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperCalibrationCapability
 */
#[Fillable([
    'organization_id', 'equipment_category_id', 'nama_alat', 'parameter', 'range_min', 'range_max', 'range_note',
    'satuan', 'ketidakpastian_terbaik', 'satuan_ketidakpastian', 'faktor_cakupan', 'metode', 'keterangan',
    'u_temperature', 'ci_suhu', 'u_perbedaan_suhu', 'ci_perbedaan_suhu',
    // SENGAJA nggak ada `sumber` di sini. Nilainya keputusan server (lihat
    // [SUMBER]), dan `sumber` yang mass-assignable berarti satu payload
    // `{"sumber":"akreditasi"}` dari HP teknisi cukup buat bikin baris tanpa CMC
    // menyamar jadi baris lampiran akreditasi. Diisi eksplisit di controller &
    // panel admin.
    'dibuat_oleh_user_id',
])]
class CalibrationCapability extends Model
{
    use Diaudit, HasFactory, SoftDeletes;

    /**
     * Baris salinan lampiran akreditasi KAN LK-285-IDN. Angkanya bukan milik
     * aplikasi ini — dia datang dari dokumen akreditasi lab, dan yang boleh
     * ngubahnya cuma revisi dokumen itu (lewat seeder).
     */
    public const SUMBER_AKREDITASI = 'akreditasi';

    /** Ditambah admin dari panel. Boleh punya CMC, boleh juga belum. */
    public const SUMBER_ADMIN = 'admin';

    /**
     * Ditambah teknisi dari lapangan lewat `POST /api/categories/{kode}/kemampuan`.
     * Langsung kepakai tanpa nunggu persetujuan (keputusan pemilik proyek), dan
     * PASTI belum punya rentang CMC waktu lahir.
     */
    public const SUMBER_TEKNISI = 'teknisi';

    /** @var list<string> */
    public const SUMBER = [self::SUMBER_AKREDITASI, self::SUMBER_ADMIN, self::SUMBER_TEKNISI];

    /**
     * `organization_id` diisi sendiri dari kategorinya kalau pemanggil nggak
     * ngisi.
     *
     * ## Kenapa perlu
     *
     * Kolomnya baru ada sejak migrasi 2026_08_24_100000 dan langsung NOT NULL,
     * sementara SEMBILAN seeder CMC yang udah ada nulis `create()` /
     * `updateOrCreate()` tanpa `organization_id` — kolomnya emang belum ada
     * waktu mereka ditulis. Tanpa penambal ini semuanya mental di
     * `SQLSTATE[23000] Column 'organization_id' cannot be null`, termasuk
     * `db:seed` yang jalan waktu deploy.
     *
     * Nilainya BUKAN tebakan: `equipment_category_id` itu NOT NULL dan
     * `equipment_categories.organization_id` juga NOT NULL, jadi pemilik yang
     * benar selalu bisa dibaca dari sana. Persis sumber yang dipakai migrasi
     * tadi waktu ngisi 151 baris lama.
     *
     * ## Kenapa di `save()`, BUKAN di event `creating`
     *
     * Ini yang penting, dan yang bikin versi pertamanya salah. `DatabaseSeeder`
     * pakai trait `WithoutModelEvents` — dia nyabut event dispatcher selama
     * seeding, jadi SEMUA hook `creating`/`saving` mati diam-diam. Penambal yang
     * dipasang lewat event kelihatan jalan di test API & pabrik data, lalu
     * mental persis di jalur yang paling butuh: `php artisan db:seed`.
     *
     * `save()` bukan event — dia tetap kepanggil apa pun keadaan dispatcher-nya,
     * dan `create()` / `updateOrCreate()` / pabrik data semuanya lewat sini.
     *
     * Yang dikirim pemanggil TIDAK pernah ditimpa: panel admin & controller API
     * ngisi eksplisit dari organisasi si pemanggil, dan itu yang harus menang.
     * Justru karena boleh menang, yang dikirim pemanggil wajib DICOCOKIN dulu ke
     * organisasi kategorinya — lihat [pastikanSeorganisasiDenganKategori]. Cabang
     * penambal di atas nggak perlu ikut dicek: nilainya baru aja dibaca DARI
     * kategori itu, jadi cocoknya dijamin sama konstruksinya.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->organization_id === null && $this->equipment_category_id !== null) {
            $this->organization_id = EquipmentCategory::withTrashed()
                ->whereKey($this->equipment_category_id)
                ->value('organization_id');
        } else {
            $this->pastikanSeorganisasiDenganKategori();
        }

        return parent::save($options);
    }

    /**
     * PENJAGA DUA SUMBER KEBENARAN. Baris ini nggak boleh punya
     * `organization_id` yang beda dari organisasi kategorinya.
     *
     * ## Kegagalan konkret yang dicegah
     *
     * Kepemilikan baris kemampuan dulu ditulis di dua tempat yang nggak pernah
     * didamaikan: kolom `organization_id` di sini (dipakai panel Filament lewat
     * `ScopesToOrganization` dan [scopeMilikOrganisasi]) dan
     * `equipment_categories.organization_id` (dipakai SEMUA jalur baca API dan
     * `GumCalculator::kemampuanUntukTitik()`, yang nyari kandidat CMC cuma lewat
     * `where('equipment_category_id', ...)`).
     *
     * Selama dua-duanya sama, nggak ada yang kelihatan. Satu baris yang beda
     * cukup buat bikin ini:
     *
     *  1. Admin lab A bikin kemampuan tapi milih kategori milik lab B — dulu
     *     bisa, karena dropdown kategori di `CalibrationCapabilityForm` nggak
     *     disaring sementara `organization_id`-nya dicap dari admin yang login.
     *  2. Teknisi lab B narik `GET /categories/{kode}` dan kebagian nama alat +
     *     angka CMC lab A.
     *  3. Alat lab B ditautkan ke nama itu (validasi `Rule::exists` dulu cuma
     *     per kategori), lalu tiap titik ukurnya dicocokin ke baris lab A.
     *  4. **Angka ketidakpastian terbaik lab A terbit sebagai lantai U95 di
     *     sertifikat lab B** — sertifikat yang nyatain dirinya terakreditasi,
     *     buat kemampuan yang nggak pernah diakreditasi buat lab itu.
     *
     * Nggak satu pun langkah di atas ngelempar error. Yang nemuin bedanya
     * biasanya asesor, waktu surveilan.
     *
     * ## Kenapa di `save()`, bukan di observer/event `saving`
     *
     * Alasan yang sama persis kayak penambal `organization_id` di atas:
     * `DatabaseSeeder` pakai `WithoutModelEvents`, yang nyabut dispatcher-nya —
     * jadi penjaga yang dipasang lewat event mati diam-diam justru di jalur
     * yang paling ramai nulis. `save()` tetap kepanggil apa pun keadaannya.
     *
     * ## Kenapa nolak, bukan nimpa
     *
     * Nimpa `organization_id` pakai punya kategorinya kelihatan ramah, tapi
     * artinya satu baris CMC PINDAH pemilik tanpa ada yang minta. Berhenti dan
     * bikin pemanggilnya sadar itu yang bener; jalur normal nggak akan pernah
     * kena karena panel admin & `KemampuanKalibrasiController` sama-sama
     * nurunin organisasi dari kategori yang udah disaring duluan.
     *
     * ## Kenapa cuma waktu kolomnya berubah
     *
     * Biar `save()` yang cuma mbenerin salah ketik `nama_alat` nggak nambah
     * satu query per baris. Kalau dua kolomnya nggak gerak, keadaannya udah
     * pernah lolos penjaga ini waktu barisnya lahir.
     */
    private function pastikanSeorganisasiDenganKategori(): void
    {
        if ($this->equipment_category_id === null) {
            return;
        }

        if ($this->exists && ! $this->isDirty(['organization_id', 'equipment_category_id'])) {
            return;
        }

        // `withTrashed()` sengaja: kategori yang dinonaktifkan admin tetap punya
        // pemilik yang sah, dan baris kemampuannya masih boleh disunting (mis.
        // buat dilengkapi CMC-nya) tanpa tiba-tiba kelihatan yatim.
        $organisasiKategori = EquipmentCategory::withTrashed()
            ->whereKey($this->equipment_category_id)
            ->value('organization_id');

        if ($organisasiKategori === null || (int) $organisasiKategori === (int) $this->organization_id) {
            return;
        }

        throw KemampuanLintasOrganisasi::untuk(
            $this->organization_id,
            (int) $organisasiKategori,
            $this->equipment_category_id,
        );
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'range_min' => 'float',
            'range_max' => 'float',
            'ketidakpastian_terbaik' => 'float',
            'faktor_cakupan' => 'float',
            'u_temperature' => 'float',
            'ci_suhu' => 'float',
            'u_perbedaan_suhu' => 'float',
            'ci_perbedaan_suhu' => 'float',
        ];
    }

    /**
     * Baris ini PUNYA angka kemampuan kalibrasi yang sah buat dipakai ngitung.
     *
     * ## Kenapa method ini ada, dan kenapa jangan dihapus
     *
     * Sejak teknisi boleh nambah nama alat sendiri, tabel ini bisa berisi baris
     * yang cuma punya NAMA — tanpa rentang, tanpa CMC. Baris kayak gitu ada
     * gunanya (teknisi butuh milih nama alatnya biar bisa kerja), tapi dia
     * NGGAK BOLEH ikut jadi kandidat pencocokan titik ukur.
     *
     * Rantai kegagalannya pendek dan sepenuhnya senyap:
     *
     *  1. `GumCalculator::kemampuanUntukTitik()` nyocokin titik tunggal generik
     *     lewat `abs($titikUkur - (float) $k->range_max)`. `range_max` NULL
     *     ke-cast jadi `0.0`.
     *  2. Jadi tiap titik ukur yang nilainya di sekitar nol — dan itu WAJAR di
     *     kalibrasi (titik nol jangka sorong, 0 mg neraca, blank turbidimeter) —
     *     cocok sama baris tanpa rentang, dalam ambang 0,1.
     *  3. `hitungDariKemampuan()` terus ngambil `(float) $kemampuan->
     *     ketidakpastian_terbaik` = 0.0. `uCmc` jadi 0, dan lantai CMC
     *     `max($cmcDiperluas, $k * $gabungan)` jadi `max(0, ...)` — alias nggak
     *     ada lantai sama sekali.
     *  4. U95 yang terbit LEBIH KECIL daripada yang lab-nya terakreditasi, di
     *     sertifikat yang bilang dirinya terakreditasi.
     *
     * Nggak ada satu pun langkah di atas yang ngelempar error. Nggak ada test
     * lama yang kena. Yang ketahuan cuma angkanya, dan itu pun cuma kalau ada
     * yang ngebandingin ke lampiran akreditasi — biasanya asesor.
     *
     * ## NULL dan NOL itu DUA HAL BERBEDA — jangan disamain
     *
     * Godaannya besar buat nulis `> 0` di sini ("mana ada ketidakpastian
     * terbaik nol"), dan itu SALAH. Repo ini udah punya konvensi yang dipakai
     * beneran: `ketidakpastian_terbaik = 0` artinya **"lab nggak punya klaim CMC
     * buat rentang ini"**, bukan "CMC-nya nol". Lihat
     * `ViscometerCapabilitySeeder` — baris keempat, rentang 58021–95192 cP,
     * CMC 0. Baris itu ADA justru supaya titik di luar lingkup akreditasi tetap
     * ketemu kemampuan dan tetap dihitung budget EMPAT komponen; tanpa dia,
     * titiknya jatuh ke jalur cadangan dua komponen yang MEMBUANG pengaruh
     * suhu, dan `uc`-nya malah mengecil.
     *
     * Jadi:
     *  - `0`    → udah dipikirin, sengaja nggak ada lantai CMC. Tetap dipakai.
     *  - `NULL` → belum pernah diisi siapa pun. Nggak boleh dipakai ngitung.
     *
     * Nyamain keduanya bikin ketiga test budget Viscometer merah — dan yang
     * lebih penting, bikin sertifikat Viscometer di luar lingkup ngeklaim
     * ketidakpastian lebih baik dari yang bisa dibuktikan.
     */
    public function punyaCmc(): bool
    {
        return $this->ketidakpastian_terbaik !== null;
    }

    /**
     * Kebalikan [punyaCmc], dipakai buat NANDAIN diri sendiri di respons API &
     * panel admin. Dipisah biar sisi klien nggak perlu tahu aturannya.
     */
    public function tanpaCmc(): bool
    {
        return ! $this->punyaCmc();
    }

    /**
     * Alasan yang dikirim BARENGAN flag `tanpa_cmc`, dalam bahasa yang kebaca
     * teknisi di layar HP.
     *
     * Ditaruh di model, bukan di resource, biar panel admin & API ngomong hal
     * yang persis sama. Dua kalimat yang beda buat kondisi yang sama itu yang
     * bikin orang mikir salah satunya lebih ringan.
     */
    public function alasanTanpaCmc(): ?string
    {
        if ($this->punyaCmc()) {
            return null;
        }

        return 'Nama alat ini belum punya rentang kemampuan kalibrasi (CMC) dari lampiran akreditasi. '
            .'Kalibrasi tetap bisa dikerjakan dan disimpan, tapi ketidakpastiannya dihitung lewat jalur '
            .'generik (Type A + Type B) — BUKAN sebagai kemampuan terakreditasi. Admin perlu melengkapi '
            .'rentang & CMC-nya sebelum hasilnya boleh diklaim di ruang lingkup akreditasi.';
    }

    /** Baris salinan lampiran akreditasi — bukan tambahan admin/teknisi. */
    public function dariAkreditasi(): bool
    {
        return $this->sumber === self::SUMBER_AKREDITASI;
    }

    /**
     * Konstanta budgetnya lengkap, jadi `GumCalculator` boleh nyusun budget
     * 5 komponen buat titik yang pakai kemampuan ini.
     *
     * Wajib LENGKAP keempatnya, bukan sebagian: budget setengah jadi ngasih
     * angka yang kelihatan sah tapi ngelewatin komponen yang nggak keisi, dan
     * itu lebih berbahaya daripada balik ke jalur CMC yang jelas-jelas
     * penyederhanaan. Yang belum lengkap tetap lewat `hitungDariKemampuan()`.
     */
    public function punyaBudgetPenuh(): bool
    {
        return $this->u_temperature !== null
            && $this->ci_suhu !== null
            && $this->u_perbedaan_suhu !== null
            && $this->ci_perbedaan_suhu !== null;
    }

    /**
     * Batesin ke kemampuan milik satu organisasi.
     *
     * Kolomnya baru ada sejak migrasi 2026_08_24_100000 — sebelum itu
     * kepemilikan cuma bisa dibaca lewat `equipment_categories`, dan tiap
     * pemanggil harus inget nge-join. Yang lupa nggak keliatan sebagai error,
     * cuma sebagai daftar alat yang kepanjangan.
     *
     * @param  Builder<CalibrationCapability>  $query
     */
    public function scopeMilikOrganisasi(Builder $query, ?int $organizationId): void
    {
        $query->where('calibration_capabilities.organization_id', $organizationId);
    }

    /** @return BelongsTo<EquipmentCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'equipment_category_id');
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * Yang nambahin baris ini. NULL buat baris lampiran akreditasi (nggak ada
     * orangnya — itu seeder) dan buat baris yang pembuatnya udah dihapus.
     *
     * @return BelongsTo<User, $this>
     */
    public function pembuat(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibuat_oleh_user_id');
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @mixin IdeHelperEquipment
 */
#[Fillable([
    'organization_id', 'customer_id', 'equipment_category_id', 'nama_alat', 'nama_alat_kemampuan',
    'merk', 'model', 'serial_number', 'no_identifikasi', 'range_min', 'range_max', 'satuan', 'resolusi',
    'toleransi', 'lokasi', 'tanggal_kalibrasi_terakhir', 'tanggal_jatuh_tempo', 'status', 'catatan',
])]
class Equipment extends Model
{
    use Diaudit, HasFactory, SoftDeletes;

    /**
     * Inflector Laravel nganggep "equipment" itu uncountable, jadi dia bakal nyari
     * tabel `equipment` (tanpa s). Nama tabelnya dipaksa di sini.
     */
    protected $table = 'equipments';

    public const STATUS_AKTIF = 'aktif';

    public const STATUS_NONAKTIF = 'nonaktif';

    /** Cuma dipakai di API, nggak pernah disimpen — turunan dari tanggal_jatuh_tempo. */
    public const STATUS_OVERDUE = 'overdue';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tanggal_kalibrasi_terakhir' => 'date',
            'tanggal_jatuh_tempo' => 'date',
            'range_min' => 'float',
            'range_max' => 'float',
            'resolusi' => 'float',
            'resolusi_rentang' => 'array',
            'toleransi' => 'float',
        ];
    }

    /**
     * Resolusi alat DI TITIK [$nilai].
     *
     * Sebagian alat resolusinya berubah menurut rentang — Turbidimeter PT Sidik
     * 0,01 di bawah 10 NTU, 0,1 di 10–100, dan 1 di atas itu. Selama cuma ada
     * satu kolom `resolusi`, titik 100 NTU kecetak `101,00`: dua digit yang
     * alatnya nggak bisa tampilkan. Lihat migrasi
     * `2026_08_04_120000_tambah_resolusi_rentang_ke_equipments`.
     *
     * `maks` bersifat **INKLUSIF**, jadi 100 NTU tetap di golongan 0,1.
     *
     * Paragraf ini sempat nulis kebalikannya ("eksklusif ... itu yang bikin
     * cocok sama master, `100`/`101` bukan `100,0`/`101,0`) — dan itu salah.
     * Waktu master `0189-CAL-624` diadu langsung 10 Agt 2026, yang kecetak
     * `100,0` & U95 `3,1`. Batas eksklusif ngelempar titik 100 ke golongan
     * resolusi 1, dan satu angka penting hilang di kolom ketidakpastian.
     *
     * Balik ke [resolusi] biasa kalau `resolusi_rentang` kosong. Alat
     * ber-resolusi seragam (pH meter) karena itu nggak berubah perilakunya.
     */
    public function resolusiPada(?float $nilai): ?float
    {
        $band = $this->bandResolusi($nilai);

        if (isset($band['resolusi'])) {
            return (float) $band['resolusi'];
        }

        return $this->resolusi !== null ? (float) $this->resolusi : null;
    }

    /**
     * Sedekat apa (relatif) sebuah nilai boleh meleset dari `titik` sebuah band
     * dan masih dianggap titik yang sama. Nilai acuan digeser koreksi suhu —
     * 111 mS/cm jadi 111,193568, meleset 0,17%.
     */
    private const TOLERANSI_TITIK_BAND = 0.02;

    /**
     * Band `resolusi_rentang` yang berlaku buat [$nilai]. `[]` kalau nggak ada.
     *
     * ## Dua bentuk band, dan kenapa dua-duanya perlu
     *
     * **Berkunci `maks`** (Turbidimeter) — ambang numerik: 0–10 NTU resolusi
     * 0,01, dst. Cocok buat alat yang satuannya seragam dan resolusinya
     * berubah menurut besar pembacaan.
     *
     * **Berkunci `titik`** (Conductivity) — dikunci ke nilai nominal larutan.
     * Ambang numerik JEBOL di sini karena satuannya campur: titik `111 mS/cm`
     * secara ANGKA lebih kecil daripada `1412 µS/cm` (111 < 1412) padahal
     * secara fisik hampir 100× lebih besar, jadi dia nyangkut ke band "sampai
     * 1412" dan kebaca µS/cm. Bentuk ini juga lebih dekat ke lembar aslinya:
     * `INPUT DATA` E16/G16/I16 emang tiga kotak BERLABEL, bukan tiga rentang.
     *
     * Band ber-`titik` diperiksa DULUAN karena lebih spesifik. Alat lama yang
     * cuma punya band ber-`maks` nggak berubah perilakunya sama sekali.
     *
     * Publik supaya pemanggil bisa mbedain "band ini nggak nyebut satuan" dari
     * "nggak ada band yang cocok" — [satuanPada] sendiri nutup beda itu karena
     * dia jatuh ke [satuan] borongan di dua-duanya.
     *
     * @return array<string, mixed>
     */
    public function bandResolusi(?float $nilai): array
    {
        $rentang = $this->resolusi_rentang;

        if ($nilai === null || ! is_array($rentang) || $rentang === []) {
            return [];
        }

        $terdekat = [];
        $skorTerdekat = INF;

        foreach ($rentang as $band) {
            if (! is_array($band) || ! isset($band['titik'])) {
                continue;
            }

            $nominal = (float) $band['titik'];

            // Dibandingin RELATIF: titik 1,412 dan 1412 beda tiga orde
            // besaran, jadi selisih mutlak selalu milih yang kecil.
            $skor = abs($nominal - $nilai) / max(abs($nominal), 1e-9);

            if ($skor < $skorTerdekat) {
                $skorTerdekat = $skor;
                $terdekat = $band;
            }
        }

        if ($terdekat !== [] && $skorTerdekat <= self::TOLERANSI_TITIK_BAND) {
            return $terdekat;
        }

        foreach ($rentang as $band) {
            if (! is_array($band) || ! array_key_exists('maks', $band)) {
                continue;
            }

            $maks = $band['maks'];

            // `maks: null` = golongan terakhir, nampung sisanya.
            //
            // Batas atasnya INKLUSIF (`<=`). Waktu masih `<`, titik ukur yang
            // pas jatuh di batas ikut golongan berikutnya: 100 NTU dapat
            // resolusi 1 padahal Capacity/Graduation-nya bilang 0,1 sampai
            // 100 NTU. Akibatnya baris 100 kecetak `100` & U95 `3`, sementara
            // master `0189-CAL-624` nulis `100,0` & `3,1` — satu angka penting
            // hilang di kolom ketidakpastian.
            if ($maks === null || abs($nilai) <= (float) $maks) {
                return $band;
            }
        }

        return [];
    }

    /**
     * Satuan yang ALAT PELANGGAN tampilkan di titik [$nilai].
     *
     * ## Kenapa satuan bisa beda per titik
     *
     * Conductivity meter ganti satuan SENDIRI begitu pembacaannya lewat ambang
     * tertentu: di bawahnya µS/cm, di atasnya mS/cm. Ambangnya beda-beda per
     * merk/settingan alat — ada yang pindah di atas 1000 µS, ada yang lebih
     * tinggi. Jadi larutan standar 1412 bisa kebaca `1412 µS/cm` di satu alat
     * dan `1,412 mS/cm` di alat lain, dan dua-duanya benar.
     *
     * Aturan lab (arahan 11 Agt 2026): **sertifikat ikut satuan yang tampil di
     * alat pelanggan.** Kalau alatnya nunjukin mS/cm, sertifikatnya mS/cm.
     * Maksa satu format bikin pelanggan protes, karena angka di kertas nggak
     * cocok sama angka yang dia lihat sendiri di layar alatnya.
     *
     * Satuannya numpang di baris `resolusi_rentang` yang sudah ada — bandnya
     * memang sudah menggambarkan "di rentang segini alatnya berperilaku
     * begini", dan satuan itu bagian dari perilaku yang sama. Band lama yang
     * nggak punya kunci `satuan` balik ke [satuan] tunggal, jadi alat yang
     * satuannya seragam (pH, Turbidimeter, Chlorine, Refractometer) nggak
     * berubah sama sekali.
     */
    public function satuanPada(?float $nilai): ?string
    {
        // Lewat [bandResolusi] yang sama persis kayak [resolusiPada], biar dua
        // method ini nggak pernah milih band yang beda buat nilai yang sama.
        $satuan = $this->bandResolusi($nilai)['satuan'] ?? null;

        return is_string($satuan) && $satuan !== '' ? $satuan : $this->satuan;
    }

    /**
     * Status yang dilihat mobile: `overdue` menang di atas `aktif` kalau alatnya
     * udah lewat jatuh tempo.
     */
    public function statusUntukApi(): string
    {
        if ($this->status === self::STATUS_NONAKTIF) {
            return self::STATUS_NONAKTIF;
        }

        return $this->isOverdue() ? self::STATUS_OVERDUE : self::STATUS_AKTIF;
    }

    public function isOverdue(): bool
    {
        return $this->tanggal_jatuh_tempo !== null
            && $this->tanggal_jatuh_tempo->isPast()
            && $this->status === self::STATUS_AKTIF;
    }

    /** @param  Builder<Equipment>  $query */
    public function scopeOverdue(Builder $query): void
    {
        $query->where('status', self::STATUS_AKTIF)
            ->whereNotNull('tanggal_jatuh_tempo')
            ->whereDate('tanggal_jatuh_tempo', '<', now());
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<EquipmentCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EquipmentCategory::class, 'equipment_category_id');
    }

    /** @return HasMany<CalibrationSession, $this> */
    public function calibrationSessions(): HasMany
    {
        return $this->hasMany(CalibrationSession::class);
    }
}

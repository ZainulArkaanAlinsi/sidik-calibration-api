<?php

namespace App\Models;

use App\Models\Concerns\Diaudit;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * @mixin IdeHelperCertificate
 */
#[Fillable([
    'organization_id', 'calibration_session_id', 'issued_by', 'revision_of', 'revisi_ke', 'nomor', 'qr_token',
    'qr_payload', 'snapshot', 'validasi', 'pdf_path', 'xlsx_path', 'diterbitkan_pada',
    'berlaku_sampai', 'status', 'alasan_revisi', 'catatan_pelanggan',
    'dibatalkan_pada', 'dibatalkan_oleh', 'alasan_pembatalan',
])]
class Certificate extends Model
{
    use Diaudit, HasFactory;

    public const STATUS_MENUNGGU_GENERATE = 'menunggu_generate';

    public const STATUS_TERBIT = 'terbit';

    public const STATUS_GAGAL = 'gagal';

    /** Final (K38-3). PDF-nya tetap diarsip untuk lab, tidak untuk pelanggan & QR. */
    public const STATUS_DIBATALKAN = 'dibatalkan';

    /**
     * Status revisi yang MENGGANTIKAN pendahulunya.
     *
     * `dibatalkan` ikut karena pembatalan revisi tidak menghidupkan pendahulu
     * (K38-2): X yang sudah digantikan X′ tetap digantikan walau X′ lalu
     * dibatalkan. `menunggu_generate` & `gagal` TIDAK ikut — revisi yang belum
     * pernah jadi dokumen tidak boleh mematikan sertifikat asalnya, kalau
     * begitu alatnya kehilangan jadwal gara-gara dokumen yang tidak pernah ada.
     */
    public const STATUS_PENGGANTI = [self::STATUS_TERBIT, self::STATUS_DIBATALKAN];

    /** Label dokumen yang dibaca manusia (lab & pelanggan). */
    public const DOKUMEN_BERLAKU = 'berlaku';

    public const DOKUMEN_DIGANTIKAN = 'digantikan';

    public const DOKUMEN_DIBATALKAN = 'dibatalkan';

    public const DOKUMEN_BELUM_TERBIT = 'belum_terbit';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'diterbitkan_pada' => 'date',
            'berlaku_sampai' => 'date',
            'dibatalkan_pada' => 'datetime',
            'revisi_ke' => 'integer',
            'snapshot' => 'array',
            'validasi' => 'array',
        ];
    }

    /**
     * Baris yang BELUM digantikan revisi yang sah. Satu definisi untuk semua
     * pintu (jadwal alat, daftar pelanggan, halaman QR) — kalau tiap pintu
     * menulis versinya sendiri, suatu hari satu pintu bilang "berlaku" untuk
     * dokumen yang pintu lain bilang "digantikan".
     *
     * @param  Builder<Certificate>  $query
     */
    public function scopeBelumDigantikan(Builder $query): void
    {
        $tabel = $this->getTable();

        $query->whereNotExists(fn (QueryBuilder $pengganti) => $pengganti
            ->selectRaw('1')
            ->from($tabel.' as pengganti')
            ->whereColumn('pengganti.revision_of', $tabel.'.id')
            ->whereIn('pengganti.status', self::STATUS_PENGGANTI));
    }

    /** Revisi sah (terbit/dibatalkan) yang menggantikan baris ini, atau null. */
    public function penggantiSah(): ?self
    {
        return self::query()
            ->where('revision_of', $this->getKey())
            ->whereIn('status', self::STATUS_PENGGANTI)
            ->latest('id')
            ->first();
    }

    /**
     * `berlaku` · `digantikan` · `dibatalkan` · `belum_terbit`.
     *
     * `dibatalkan` menang atas `digantikan`: pembatalan ditolak untuk baris
     * yang punya revisi, jadi dua-duanya tidak mungkin bersamaan — tapi kalau
     * datanya pernah begitu, label yang paling keras yang ditampilkan.
     */
    public function statusDokumen(): string
    {
        return self::labelDokumen((string) $this->status, $this->penggantiSah()?->status);
    }

    /**
     * Versi tanpa query untuk layar daftar: pemanggil sudah memegang status
     * revisi terakhirnya (relasi `revisiTerakhir` yang dimuat sekali).
     * Rantai revisi linear — satu sertifikat paling banyak punya satu revisi —
     * jadi revisi terakhir adalah SATU-SATUNYA revisinya.
     */
    public static function labelDokumen(string $status, ?string $statusRevisi): string
    {
        return match (true) {
            $status === self::STATUS_DIBATALKAN => self::DOKUMEN_DIBATALKAN,
            $status !== self::STATUS_TERBIT => self::DOKUMEN_BELUM_TERBIT,
            in_array($statusRevisi, self::STATUS_PENGGANTI, true) => self::DOKUMEN_DIGANTIKAN,
            default => self::DOKUMEN_BERLAKU,
        };
    }

    /**
     * Revisi & pembatalan cuma untuk dokumen yang `terbit` dan belum punya
     * revisi APA PUN — termasuk yang masih diantre atau gagal dirender. Revisi
     * yang gagal diterbitkan ulang lewat tombol retry-nya, bukan ditumpuk revisi
     * kedua; dan membatalkan X sementara X′ masih dirender menghasilkan X yang
     * sekaligus "dibatalkan" dan "digantikan".
     */
    public function bisaDiubahStatusnya(): bool
    {
        return $this->status === self::STATUS_TERBIT
            && ! self::query()->where('revision_of', $this->getKey())->exists();
    }

    /** `CAL/2026/09/0011-R2` → `CAL/2026/09/0011`. */
    public static function nomorDasar(string $nomor): string
    {
        return (string) preg_replace('/-R\d+$/', '', $nomor);
    }

    /**
     * Siapa yang tanda tangan sertifikat ini (fase-2 §3c).
     *
     * Dibaca dari `snapshot`, **bukan** live dari pengaturan organisasi. Bedanya
     * penting: snapshot itu yang beneran kecetak di PDF, dan pengaturannya bisa
     * berubah kapan aja. Kalau dibaca live, sertifikat lama bakal nampilin penanda
     * tangan yang BEDA dari yang ada di PDF-nya sendiri — dan yang lihat nggak punya
     * cara buat tahu mana yang bener. Sertifikat terbit itu dokumen terkendali.
     *
     * Ditaruh di model, bukan di resource, karena dipakai di DUA bentuk respons
     * (`CertificateResource` & objek embed di `CalibrationResource`) — disalin dua
     * kali berarti suatu saat yang satu diubah dan yang lain ketinggalan.
     *
     * `ttd_url` yang diminta di §3c sengaja NGGAK ADA: gambarnya di disk privat,
     * karena URL tanda tangan yang bisa diakses siapa pun berarti siapa pun bisa
     * nempelin ke dokumen palsu.
     *
     * @return array{nama: string|null, jabatan: string|null}|null
     */
    public function penandaTangan(): ?array
    {
        $footer = $this->snapshot['footer'] ?? null;

        if (! filled($footer)) {
            return null;
        }

        return [
            'nama' => $footer['penandatangan'] ?? null,
            'jabatan' => $footer['jabatan'] ?? null,
        ];
    }

    /**
     * Nama file yang aman dipakai di disk & header unduhan. `nomor` ada
     * slash-nya (`CAL/2026/07/0001`) — kalau dipakai apa adanya, itu bikin
     * subdirektori, bukan nama file.
     */
    public function namaFile(string $ekstensi): string
    {
        $nomor = str_replace(['/', '\\'], '-', (string) ($this->nomor ?? $this->qr_token));

        return "Sertifikat-{$nomor}.{$ekstensi}";
    }

    /** @return BelongsTo<Organization, $this> */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return BelongsTo<CalibrationSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(CalibrationSession::class, 'calibration_session_id');
    }

    /** Sertifikat asal yang direvisi sama sertifikat ini. */
    /** @return BelongsTo<Certificate, $this> */
    public function revisionOf(): BelongsTo
    {
        return $this->belongsTo(Certificate::class, 'revision_of');
    }

    /**
     * Revisi TERBARU atas sertifikat ini, status apa pun — dipakai layar lab
     * supaya revisi yang masih dirender ikut kelihatan.
     *
     * @return HasOne<Certificate, $this>
     */
    public function revisiTerakhir(): HasOne
    {
        return $this->hasOne(Certificate::class, 'revision_of')->latestOfMany();
    }

    /** @return BelongsTo<User, $this> */
    public function pembatal(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dibatalkan_oleh');
    }

    /**
     * Koreksi dari pelanggan yang masih menunggu lab (§42) — satu per
     * sertifikat, dijaga `AlurKoreksi`.
     *
     * @return HasOne<KoreksiPelanggan, $this>
     */
    public function koreksiMenunggu(): HasOne
    {
        return $this->hasOne(KoreksiPelanggan::class, 'certificate_id')
            ->where('status', KoreksiPelanggan::STATUS_MENUNGGU);
    }
}

<?php

namespace App\Services\Acuan;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\PaketAcuan;
use App\Models\PaketAcuanVersi;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Support\JsonKanonik;
use DateTimeInterface;

/**
 * Versi data acuan mana yang MENGHITUNG sebuah sesi — dan satu-satunya pintu
 * yang membuka lingkup versi itu untuk jalur hitung (Studio Data Acuan,
 * 03-SDD §4, FR-04, FR-20, BR-10).
 *
 * ## Invarian yang dijaga kelas ini
 *
 * **Angka dari versi DB ⇔ hasil berstempel versi itu.** [jalankan] dan
 * [jalankanDengan] membuka `SumberAcuan::denganVersi()` dan memulangkan versi
 * yang SAMA yang dipakai lingkupnya, jadi stempel `paket_acuan_versi_id` tidak
 * pernah dicari dua kali dan tidak bisa menunjuk versi lain dari yang
 * menghitung angkanya.
 *
 * Lingkup cuma dibuka untuk sesi yang profilnya punya `skemaAcuan()` (hari ini
 * Micrometer). Dial Indicator memakai balok ukur Micrometer
 * (`TabelStandarDialIndicator`), tapi sesinya tidak pernah membuka lingkup
 * `micrometer`: dia tetap membaca JSON dan stempelnya null. Tanpa ini, versi 2
 * Micrometer diam-diam menggeser angka Dial Indicator tanpa satu pun stempel.
 *
 * Bila `config('acuan.sumber') !== 'db'` tidak ada lingkup dan stempelnya null
 * — bukan "stempel tetap ditulis": stempel yang menunjuk versi yang tidak
 * benar-benar dipakai lebih buruk daripada null.
 *
 * ## Beda dari `RumusKalibrasi`
 *
 * Pemetaan sesi → profil SAMA (`registry->untukAlat()`, jatuh ke profil
 * default bila alatnya tidak ada). Bedanya: TIDAK ada fallback ke "versi aktif
 * hari ini". Sesi bertanggal sebelum versi 1 berlaku memulangkan null dan
 * dihitung dari JSON — menstempelnya dengan versi yang belum berlaku pada
 * tanggal itu justru melanggar BR-10.
 */
class PenentuVersiAcuan
{
    /** Status versi yang boleh menghitung. Sisanya tidak pernah dipulangkan. */
    private const STATUS_BERLAKU = [
        PaketAcuanVersi::STATUS_AKTIF,
        PaketAcuanVersi::STATUS_PENSIUN,
    ];

    public function __construct(
        private readonly CalibrationProfileRegistry $registry,
        private readonly SumberAcuan $sumber,
    ) {}

    /**
     * Versi data acuan yang berlaku untuk sesi ini pada `tanggal_kalibrasi`-nya
     * (`now()` bila belum diisi — pola `RumusKalibrasi::versiUntukSesi`).
     *
     * `null` bila sumber bukan `db`, organisasinya kosong, profil alatnya
     * belum punya `skemaAcuan()`, atau tidak ada versi berlaku pada tanggal itu.
     */
    public function untukSesi(CalibrationSession $sesi): ?PaketAcuanVersi
    {
        if (! $this->sumberDb() || $sesi->organization_id === null) {
            return null;
        }

        $profil = $this->profilBerskema($sesi);

        if ($profil === null) {
            return null;
        }

        return $this->untukProfil(
            (int) $sesi->organization_id,
            $profil->kode(),
            $sesi->tanggal_kalibrasi ?? now(),
        );
    }

    /**
     * Versi berstatus `aktif`/`pensiun` paket `$kodeProfil` milik organisasi
     * ini yang rentang berlakunya memuat `$tanggal` — inklusif di kedua ujung,
     * `berlaku_sampai` null = terbuka. Yang `berlaku_mulai`-nya paling akhir
     * menang (pola `Formula::versiPadaTanggal`).
     *
     * `draf`, `diajukan`, `terjadwal`, `ditolak`, `ditarik`, `dibuang` tidak
     * pernah dipulangkan. Paket yang sudah dihapus (soft delete) dianggap tidak
     * punya versi berlaku untuk hitungan BARU; versi miliknya yang pernah
     * menstempel hasil tetap bisa dipakai lewat [untukSesiTersimpan].
     *
     * TANPA fallback ke versi aktif hari ini: tanggal sebelum versi 1 → null.
     */
    public function untukProfil(
        int $organisasiId,
        string $kodeProfil,
        DateTimeInterface $tanggal,
        string $jenis = PaketAcuanVersi::JENIS_DATA,
    ): ?PaketAcuanVersi {
        $paket = PaketAcuan::query()
            ->where('organization_id', $organisasiId)
            ->where('kode_profil', $kodeProfil)
            ->first();

        if ($paket === null) {
            return null;
        }

        $t = $tanggal->format('Y-m-d');

        return $paket->versi()
            // Organisasi diadu dua kali (paket DAN versi) dengan sengaja: versi
            // yang tersangkut di paket organisasi lain tidak boleh lolos hanya
            // karena satu kolomnya benar.
            ->where('organization_id', $organisasiId)
            ->where('jenis', $jenis)
            ->whereIn('status', self::STATUS_BERLAKU)
            ->whereDate('berlaku_mulai', '<=', $t)
            ->where(fn ($q) => $q->whereNull('berlaku_sampai')->orWhereDate('berlaku_sampai', '>=', $t))
            ->orderByDesc('berlaku_mulai')
            // Penentu urutan kalau dua versi mulai di hari yang sama; tanpa ini
            // urutannya terserah database.
            ->orderByDesc('nomor_versi')
            ->first()
            ?->setRelation('paketAcuan', $paket);
    }

    /**
     * Versi untuk MENGHITUNG ULANG sesi yang hasilnya sudah tersimpan
     * (`kalibrasi:hitung-ulang`, `CalibrationValidator`).
     *
     * Aturannya: stempel hasil tersimpan MENANG selama versinya masih
     * `aktif`/`pensiun` dan memang milik paket profil & organisasi sesi ini —
     * sesi yang dihitung v1 tetap v1 walau v2 sudah aktif pada tanggalnya.
     * Stempel null, tidak seragam antar baris, versinya hilang, bukan milik
     * paket sesi ini, atau berstatus `ditarik`/`dibuang`/lainnya → versi yang
     * berlaku pada tanggal sesi ([untukSesi]).
     *
     * `stempel` = versi yang tertulis di hasil tersimpan (kalau seragam dan
     * barisnya masih ada), apa pun statusnya — untuk temuan validator.
     * `alasan` = kalimat yang dicetak perintah hitung ulang.
     *
     * @return array{versi: ?PaketAcuanVersi, stempel: ?PaketAcuanVersi, alasan: string}
     */
    public function untukSesiTersimpan(CalibrationSession $sesi): array
    {
        [$stempel, $catatanStempel] = $this->stempelTersimpan($sesi);

        if (! $this->sumberDb()) {
            return [
                'versi' => null,
                'stempel' => $stempel,
                'alasan' => 'DATA_ACUAN_SUMBER bukan db — dihitung dari berkas JSON, tanpa stempel.',
            ];
        }

        $profil = $sesi->organization_id === null ? null : $this->profilBerskema($sesi);

        if ($profil === null) {
            return [
                'versi' => null,
                'stempel' => $stempel,
                'alasan' => 'profil alat sesi ini tidak punya data acuan berversi — dihitung dari berkas JSON, tanpa stempel.',
            ];
        }

        if ($stempel !== null) {
            $milikSesi = (int) $stempel->organization_id === (int) $sesi->organization_id
                && $stempel->jenis === PaketAcuanVersi::JENIS_DATA
                && $stempel->paketAcuan?->kode_profil === $profil->kode();

            if ($milikSesi && in_array($stempel->status, self::STATUS_BERLAKU, true)) {
                return [
                    'versi' => $stempel,
                    'stempel' => $stempel,
                    'alasan' => 'stempel hasil tersimpan ('.self::label($stempel).').',
                ];
            }

            $catatanStempel = $milikSesi
                ? 'stempel '.self::label($stempel).' tidak dipakai lagi'
                : 'stempel '.self::label($stempel).' bukan milik paket profil/organisasi sesi ini';
        }

        $berlaku = $this->untukSesi($sesi);

        return [
            'versi' => $berlaku,
            'stempel' => $stempel,
            'alasan' => $catatanStempel.' — '.($berlaku !== null
                ? 'memakai versi yang berlaku pada tanggal kalibrasi ('.self::label($berlaku).').'
                : 'tidak ada versi berlaku pada tanggal kalibrasi; dihitung dari berkas JSON, tanpa stempel.'),
        ];
    }

    /**
     * Jalankan `$hitung` di lingkup versi yang berlaku untuk sesi ini
     * ([untukSesi]) — pembantu satu pintu jalur simpan & preview.
     *
     * @template T
     *
     * @param  callable(): T  $hitung
     * @return array{hasil: T, versi: ?PaketAcuanVersi}
     */
    public function jalankan(CalibrationSession $sesi, callable $hitung): array
    {
        return $this->jalankanDengan($this->untukSesi($sesi), $hitung);
    }

    /**
     * Jalankan `$hitung` di lingkup `$versi` yang SUDAH diputuskan pemanggil
     * (mis. hasil [untukSesiTersimpan]). `null` atau sumber bukan `db` =
     * tanpa lingkup, dan `versi` yang dipulangkan ikut null — jadi yang
     * distempel selalu versi yang benar-benar menjadi lingkup hitungan.
     *
     * @template T
     *
     * @param  callable(): T  $hitung
     * @return array{hasil: T, versi: ?PaketAcuanVersi}
     */
    public function jalankanDengan(?PaketAcuanVersi $versi, callable $hitung): array
    {
        if ($versi === null || ! $this->sumberDb()) {
            return ['hasil' => $hitung(), 'versi' => null];
        }

        return ['hasil' => $this->sumber->denganVersi($versi, $hitung), 'versi' => $versi];
    }

    /**
     * Stempel hasil tersimpan sesi ini dalam bentuk respons —
     * `paket_acuan_versi` di lembar perhitungan, validasi, dan snapshot
     * sertifikat (Studio Data Acuan B4): `{nomor, sha256, paket}`.
     *
     * `null` bila sesi belum punya hasil, stempelnya null, TIDAK seragam antar
     * baris, versinya sudah tidak ada, atau versinya bukan milik organisasi
     * sesi. Tidak pernah ditebak dari tanggal kalibrasi atau versi yang
     * berlaku sekarang, dan tidak bergantung `config('acuan.sumber')`: yang
     * dilaporkan adalah yang tertulis di hasil.
     *
     * @return array{nomor: int, sha256: string, paket: ?string}|null
     */
    public function ringkasanStempel(CalibrationSession $sesi): ?array
    {
        [$versi] = $this->stempelTersimpan($sesi);

        if ($versi === null || (int) $versi->organization_id !== (int) $sesi->organization_id) {
            return null;
        }

        return [
            'nomor' => (int) $versi->nomor_versi,
            'sha256' => self::sha256($versi),
            'paket' => $versi->paketAcuan?->kode_profil,
        ];
    }

    /**
     * sha256 isi versi: kolom `isi_sha256`, atau dihitung dari byte isinya
     * bila kolom itu belum diisi (draf) — aturan yang sama dengan kunci cache
     * `SumberAcuan`.
     */
    public static function sha256(PaketAcuanVersi $versi): string
    {
        return $versi->isi_sha256 ?? JsonKanonik::sha256((string) $versi->isi);
    }

    /** `#12 micrometer v1, pensiun` — label pendek untuk keluaran & pesan. */
    public static function label(PaketAcuanVersi $versi): string
    {
        $kode = $versi->relationLoaded('paketAcuan') ? $versi->paketAcuan?->kode_profil : null;

        return '#'.$versi->getKey().' '.($kode !== null ? $kode.' ' : '')
            .'v'.$versi->nomor_versi.', '.$versi->status;
    }

    private function sumberDb(): bool
    {
        return config('acuan.sumber') === 'db';
    }

    /**
     * Profil sesi — dipetakan persis seperti `RumusKalibrasi::versiUntukSesi`
     * (`untukAlat()`, profil default bila alatnya tidak ada) — atau `null`
     * kalau profil itu belum punya `skemaAcuan()`.
     */
    private function profilBerskema(CalibrationSession $sesi): ?CalibrationProfile
    {
        $alat = $this->alatSesi($sesi);
        $profil = $alat !== null ? $this->registry->untukAlat($alat) : $this->registry->default();

        return $profil->skemaAcuan() !== null ? $profil : null;
    }

    /**
     * Alat sesi. Relasi yang sudah termuat dipakai selama masih menunjuk
     * `equipment_id` yang sekarang; `update()` yang mengganti alat tidak
     * melepas relasi lama, dan profil alat lama di situ berarti lingkup yang
     * salah.
     */
    private function alatSesi(CalibrationSession $sesi): ?Equipment
    {
        $alat = $sesi->loadMissing('equipment')->equipment;

        if ($alat !== null && (int) $alat->getKey() !== (int) $sesi->equipment_id) {
            return $sesi->equipment()->first();
        }

        return $alat;
    }

    /**
     * Stempel `paket_acuan_versi_id` hasil tersimpan sesi ini, dimuat beserta
     * paketnya (termasuk paket yang sudah soft delete — pola
     * `SumberAcuan::kodeProfil`).
     *
     * @return array{0: ?PaketAcuanVersi, 1: string}
     */
    private function stempelTersimpan(CalibrationSession $sesi): array
    {
        $id = ($sesi->relationLoaded('uncertaintyCalculations')
            ? $sesi->uncertaintyCalculations->pluck('paket_acuan_versi_id')
            : $sesi->uncertaintyCalculations()->pluck('paket_acuan_versi_id'))
            ->map(static fn ($v): ?int => $v === null ? null : (int) $v)
            ->unique()
            ->values();

        if ($id->isEmpty()) {
            return [null, 'belum ada hasil tersimpan'];
        }

        if ($id->count() > 1) {
            return [null, 'stempel hasil tersimpan tidak seragam ('
                .$id->map(static fn (?int $v): string => $v === null ? 'null' : '#'.$v)->implode(', ').')'];
        }

        if ($id->first() === null) {
            return [null, 'hasil tersimpan tanpa stempel'];
        }

        $versi = PaketAcuanVersi::query()
            ->with(['paketAcuan' => fn ($q) => $q->withTrashed()])
            ->find($id->first());

        return $versi === null
            ? [null, 'versi stempel #'.$id->first().' tidak ada lagi']
            : [$versi, 'stempel '.self::label($versi)];
    }
}

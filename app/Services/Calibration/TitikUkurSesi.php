<?php

namespace App\Services\Calibration;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\RawMeasurement;
use App\Models\User;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\CalibrationValidator;
use App\Support\MicrometerMentah;
use App\Support\TimbanganMentah;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Titik ukur milik SATU sesi — tambah, ubah nominal, nonaktifkan, fase, dan
 * jumlah pengulangan — disimpan server supaya HP, tablet, dan desktop melihat
 * set yang sama (permintaan pemilik 22 Okt 2026, `docs/permintaan-user-7.md`
 * §55).
 *
 * Ini lapis 3 AGENTS.md §Olah data ("bentuk lembar kerja: jumlah titik … Ya,
 * terbatas — wajib divalidasi bahwa perhitungan tidak kehilangan input"). Yang
 * dijaga di sini persis syarat itu:
 *
 *  - Rumus tidak disentuh. Titik nonaktif cuma DIKELUARKAN dari masukan
 *    hitung (sebelum `hitungPerGrup()`), sama artinya dengan baris master yang
 *    tidak diisi.
 *  - Pembacaan titik nonaktif tidak pernah dihapus: kiriman yang mengubah atau
 *    menghilangkannya ditolak 422 (AGENTS.md §Peran butir 5, ISO/IEC 17025
 *    7.5.2). Titik juga tidak bisa dihapus dari set — hanya dinonaktifkan.
 *  - Tiap perubahan menaikkan `revisi` (konkurensi optimistis, 409 kalau
 *    basi) dan meninggalkan baris audit lama → baru + alasan.
 *
 * Penyimpanannya `calibration_sessions.spesifikasi_alat.titik_sesi` — nol
 * kolom baru (AGENTS.md §Alur Kerja poin 4). Kuncinya MILIK SERVER: salinan
 * yang dikirim klien di dalam `spesifikasi_alat` dibuang di
 * `CalibrationRequest`; perubahan hanya lewat kunci `titik_sesi` di badan.
 *
 * Penghubung titik ↔ baris `raw_measurements` adalah NOMINAL (`titik_ukur`),
 * karena itu satu-satunya sumbu yang sudah tersimpan di semua jalur. Maka
 * nominal dalam satu set wajib unik, dan tiap baris mentah wajib jatuh ke satu
 * titik yang terdaftar.
 */
final class TitikUkurSesi
{
    public const KUNCI = 'titik_sesi';

    public const ASAL_PROFIL = 'profil';

    public const ASAL_TEKNISI = 'teknisi';

    public const ASAL_PELANGGAN = 'pelanggan';

    public const ASAL = [self::ASAL_PROFIL, self::ASAL_TEKNISI, self::ASAL_PELANGGAN];

    public const FASE = ['sebelum', 'sesudah'];

    /** Sama dengan batas `measurements` di `CalibrationRequest` (max:60). */
    public const MAKS_TOTAL = 60;

    public const ALASAN_MIN = 5;

    public const CATATAN_AUDIT = 'Titik ukur sesi diubah: ';

    /** Peran baris mentah yang BUKAN pembacaan berulang (nominal keping/balok). */
    private const PERAN_BUKAN_PENGULANGAN = [MicrometerMentah::PERAN_BALOK, TimbanganMentah::PERAN_NOMINAL];

    // ------------------------------------------------------------------
    // Baca
    // ------------------------------------------------------------------

    /** @return array<string, mixed>|null */
    public static function dariSpesifikasi(mixed $spesifikasi): ?array
    {
        $set = is_array($spesifikasi) ? ($spesifikasi[self::KUNCI] ?? null) : null;

        return is_array($set) && isset($set['titik']) && is_array($set['titik']) ? $set : null;
    }

    /** @return array<string, mixed>|null */
    public static function dariSesi(?CalibrationSession $sesi): ?array
    {
        return $sesi === null ? null : self::dariSpesifikasi($sesi->spesifikasi_alat);
    }

    public static function cocok(float $a, float $b): bool
    {
        return abs($a - $b) <= 1e-9 * max(1.0, abs($a), abs($b));
    }

    /**
     * Titik (aktif atau tidak) yang nominalnya cocok, atau null.
     *
     * @param  array<string, mixed>|null  $set
     * @return array<string, mixed>|null
     */
    public static function titikUntuk(?array $set, mixed $titikUkur): ?array
    {
        if ($set === null || ! is_numeric($titikUkur)) {
            return null;
        }

        foreach ($set['titik'] as $t) {
            if (self::cocok((float) $t['nominal'], (float) $titikUkur)) {
                return $t;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>|null  $set */
    public static function nonaktif(?array $set, mixed $titikUkur): bool
    {
        $t = self::titikUntuk($set, $titikUkur);

        return $t !== null && ! ($t['aktif'] ?? true);
    }

    /**
     * Keluarkan titik nonaktif dari masukan hitung. Dipanggil SEBELUM
     * `hitungPerGrup()` di jalur simpan/preview dan di `SiapHitungSesi`, jadi
     * titik lain dihitung seolah baris itu tidak diisi — bukan dihitung lalu
     * dibuang.
     *
     * @param  list<array<string, mixed>>  $siapHitung
     * @param  array<string, mixed>|null  $set
     * @return array{0: list<array<string, mixed>>, 1: list<array{titik_ke: int, alasan: string}>}
     */
    public static function saringSiapHitung(array $siapHitung, ?array $set): array
    {
        if ($set === null) {
            return [$siapHitung, []];
        }

        $aktif = [];
        $keluar = [];

        foreach ($siapHitung as $t) {
            $titik = self::titikUntuk($set, $t['titik_ukur'] ?? null);

            if ($titik !== null && ! ($titik['aktif'] ?? true)) {
                $keluar[] = [
                    'titik_ke' => (int) $t['titik_ke'],
                    'alasan' => 'Titik dinonaktifkan teknisi ('.($titik['alasan'] ?? '-').'). Pembacaannya tetap '
                        .'tersimpan, tidak ikut dihitung dan tidak tercetak di sertifikat.',
                ];

                continue;
            }

            $aktif[] = $t;
        }

        return [$aktif, $keluar];
    }

    // ------------------------------------------------------------------
    // Terapkan perubahan dari klien
    // ------------------------------------------------------------------

    /**
     * Validasi set kiriman lawan batas profil, rentang alat, dan set
     * tersimpan; pulangkan set efektif (yang akan disimpan & dipakai hitung).
     *
     * @param  array<string, mixed>|null  $kiriman  isi `titik_sesi` badan request
     * @param  array<string, mixed>|null  $tersimpan
     * @param  Collection<int, RawMeasurement>|null  $mentahLama
     * @param  array<string, mixed>  $spesifikasi
     * @return array{set: ?array<string, mixed>, berubah: bool, ringkasan: list<string>}
     *
     * @throws ValidationException
     * @throws HttpResponseException 409 bila `revisi` basi
     */
    public static function terapkan(
        ?array $kiriman,
        ?array $tersimpan,
        CalibrationProfile $profil,
        Equipment $alat,
        array $spesifikasi,
        ?User $pengubah,
        bool $periksaRevisi,
        ?Collection $mentahLama = null,
    ): array {
        if ($kiriman === null) {
            return ['set' => $tersimpan, 'berubah' => false, 'ringkasan' => []];
        }

        $batas = $profil->batasTitik($alat, $spesifikasi);

        if (! $batas['didukung']) {
            self::tolak('Lembar '.$profil->namaAlatKemampuan().' belum mendukung titik ukur sesi: '
                .($batas['alasan'] ?? 'belum diverifikasi.'));
        }

        if ($periksaRevisi) {
            $harap = (int) ($tersimpan['revisi'] ?? 0);
            $dikirim = (int) ($kiriman['revisi'] ?? 0);

            if ($harap !== $dikirim) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Titik ukur sesi ini sudah diubah dari perangkat lain (revisi '.$harap
                        .', kiriman berdasar revisi '.$dikirim.'). Muat ulang titiknya, lalu ulangi perubahanmu.',
                    'kode' => 'titik_sesi_konflik',
                    'titik_sesi' => $tersimpan,
                ], 409));
            }
        }

        $tabelBatas = collect($batas['tabel'])->keyBy('kode');
        $lama = collect($tersimpan['titik'] ?? [])->keyBy('id');
        $baru = [];

        foreach (array_values((array) ($kiriman['titik'] ?? [])) as $i => $t) {
            $baru[] = self::normalkan((array) $t, $i, $tabelBatas, $lama);
        }

        if (count($baru) > self::MAKS_TOTAL) {
            self::tolak('Paling banyak '.self::MAKS_TOTAL.' titik per sesi, termasuk yang nonaktif.');
        }

        $ids = array_column($baru, 'id');

        if (count($ids) !== count(array_unique($ids))) {
            self::tolak('`id` titik ganda — tiap titik wajib punya id unik.');
        }

        // Titik tidak boleh lenyap dari set: nonaktifkan, jangan hapus.
        $hilang = $lama->keys()->diff($ids);

        if ($hilang->isNotEmpty()) {
            self::tolak('Titik '.$hilang->map(fn ($id): string => self::label($lama[$id]))->implode(', ')
                .' hilang dari kiriman. Titik yang sudah tersimpan tidak bisa dihapus — nonaktifkan dengan alasan.');
        }

        foreach ($baru as &$t) {
            $b = $tabelBatas[$t['tabel']];
            $bawaan = array_map('floatval', $b['bawaan'] ?? []);
            $diBawaan = collect($bawaan)->contains(fn (float $n): bool => self::cocok($n, $t['nominal']));
            $sebelumnya = $lama[$t['id']] ?? null;

            if ($b['nominal'] === 'dipatok') {
                if ($t['asal'] === self::ASAL_PROFIL && ! $diBawaan) {
                    self::tolak('Nominal '.self::label($t).' dipatok kertas lembar ini dan tidak bisa diubah.');
                }

                if ($sebelumnya !== null && ! self::cocok((float) $sebelumnya['nominal'], $t['nominal'])) {
                    self::tolak('Nominal '.self::label($sebelumnya).' dipatok kertas lembar ini dan tidak bisa diubah.');
                }
            }

            // Nominal adalah penghubung titik ↔ baris mentah. Mengganti nominal
            // titik yang SUDAH punya pembacaan tersimpan memutus barisnya dari
            // titik mana pun — lalu dihapus-tulis-ulang tanpa jejak. Jalannya:
            // nonaktifkan titik lama (pembacaannya dibekukan), tambah titik baru.
            if ($sebelumnya !== null && ! self::cocok((float) $sebelumnya['nominal'], $t['nominal'])
                && self::punyaPembacaan($mentahLama, (float) $sebelumnya['nominal'])) {
                self::tolak('Nominal '.self::label($sebelumnya).' sudah punya pembacaan tersimpan dan tidak bisa '
                    .'diubah. Nonaktifkan titik itu (pembacaannya tetap tersimpan), lalu tambah titik baru.');
            }

            $t['tambahan'] = ! $diBawaan;

            if ($t['tambahan'] && ! $b['boleh_tambah'] && $t['aktif']) {
                self::tolak('Lembar ini tidak menerima titik di luar nominal kertasnya ('.self::label($t).'). '
                    .($b['alasan_tambah'] ?? ''));
            }

            if (! $t['aktif'] && ! $b['boleh_nonaktif']) {
                self::tolak('Titik pada lembar ini tidak bisa dinonaktifkan. '.($b['alasan_nonaktif'] ?? ''));
            }

            if ($t['aktif'] && self::diLuarRentangAlat($profil, $alat, $t)) {
                self::tolak(sprintf(
                    'Titik %s di luar rentang ukur alat (%s–%s %s). Titik yang tidak bisa ditampilkan alatnya tidak '
                    .'bisa dikalibrasi.',
                    self::label($t),
                    self::angka((float) $alat->range_min),
                    self::angka((float) $alat->range_max),
                    $alat->satuan ?? '',
                ));
            }
        }
        unset($t);

        foreach ($tabelBatas as $kode => $b) {
            $diTabel = array_values(array_filter($baru, static fn (array $t): bool => $t['tabel'] === $kode));
            $aktif = count(array_filter($diTabel, static fn (array $t): bool => $t['aktif']));

            if ($diTabel !== [] && ($aktif < (int) $b['min'] || $aktif > (int) $b['maks'])) {
                self::tolak(sprintf(
                    'Tabel "%s": titik aktif %d, batas lembar ini %d–%d.',
                    $b['judul'] ?? $kode,
                    $aktif,
                    $b['min'],
                    $b['maks'],
                ));
            }

            // Lembar yang membaca besaran tingkat-sesi dari titik PERTAMA
            // (Tekanan: zero error `Q39` = titik pertama) tidak boleh
            // kehilangan titik itu — menonaktifkannya menggeser besaran itu ke
            // baris lain, dan perilaku itu belum diadu ke master.
            if (($b['pertama_wajib_aktif'] ?? false) && $diTabel !== [] && ! $diTabel[0]['aktif']) {
                self::tolak('Titik pertama tabel "'.($b['judul'] ?? $kode).'" ('.self::label($diTabel[0]).') tidak bisa '
                    .'dinonaktifkan: '.($b['alasan_pertama'] ?? 'besaran sesi dibaca dari titik pertama.'));
            }

            for ($i = 0; $i < count($diTabel); $i++) {
                for ($j = $i + 1; $j < count($diTabel); $j++) {
                    if (self::cocok($diTabel[$i]['nominal'], $diTabel[$j]['nominal'])) {
                        self::tolak('Nominal '.self::label($diTabel[$i]).' dipakai dua titik. Pembacaan dikaitkan ke '
                            .'titiknya lewat nominal, jadi nominal dalam satu sesi wajib unik.');
                    }
                }
            }
        }

        // Nominal unik lintas tabel juga — baris mentah tidak menyimpan tabelnya.
        $semuaNominal = array_column($baru, 'nominal');
        foreach ($semuaNominal as $i => $n) {
            foreach (array_slice($semuaNominal, $i + 1) as $m) {
                if (self::cocok($n, $m)) {
                    self::tolak('Nominal '.self::angka($n).' dipakai dua titik.');
                }
            }
        }

        $pengulangan = self::pengulangan((array) ($kiriman['pengulangan'] ?? []), $tersimpan, $tabelBatas, $baru, $mentahLama);

        $isi = ['titik' => $baru, 'pengulangan' => $pengulangan];
        $isiLama = $tersimpan === null ? null : [
            'titik' => array_values($tersimpan['titik']),
            'pengulangan' => (array) ($tersimpan['pengulangan'] ?? []),
        ];

        if ($isiLama !== null && self::sama($isiLama, $isi)) {
            return ['set' => $tersimpan, 'berubah' => false, 'ringkasan' => []];
        }

        $set = [
            'revisi' => (int) ($tersimpan['revisi'] ?? 0) + 1,
            ...$isi,
            'diubah_oleh' => $pengubah?->id,
            'diubah_pada' => now()->toIso8601String(),
        ];

        return ['set' => $set, 'berubah' => true, 'ringkasan' => self::ringkasan($tersimpan, $set)];
    }

    /**
     * Penjaga sesudah payload disusun jadi baris mentah, SEBELUM baris lama
     * dihapus.
     *
     * @param  array<string, mixed>|null  $set
     * @param  list<array<string, mixed>>  $mentahBaru
     * @param  Collection<int, RawMeasurement>  $mentahLama
     * @param  bool  $bekukanNonaktif  false hanya untuk sesi yang baru dibuat di request ini
     * @param  array<string, mixed>|null  $batas  `batasTitik()` profil, untuk aturan titik pertama
     *
     * @throws ValidationException
     */
    public static function periksaSusunan(
        ?array $set,
        array $mentahBaru,
        Collection $mentahLama,
        bool $bekukanNonaktif = true,
        ?array $batas = null,
    ): void {
        if ($set === null) {
            return;
        }

        // Titik pertama yang wajib aktif juga wajib jadi baris PERTAMA kiriman
        // (`titik_ke` terkecil) — itu yang dibaca hitungan sebagai titik pertama.
        foreach ($batas['tabel'] ?? [] as $b) {
            if (! ($b['pertama_wajib_aktif'] ?? false)) {
                continue;
            }

            $pertama = collect($set['titik'])->firstWhere('tabel', $b['kode']);
            $barisAwal = collect($mentahBaru)->sortBy('titik_ke')->first();

            if ($pertama !== null && $barisAwal !== null
                && (! is_numeric($barisAwal['titik_ukur'] ?? null) || ! self::cocok((float) $barisAwal['titik_ukur'], (float) $pertama['nominal']))) {
                throw ValidationException::withMessages(['measurements' => 'Baris pertama tabel pembacaan wajib titik '
                    .self::label($pertama).' (titik pertama set) — besaran sesi dibaca dari titik pertama.']);
            }
        }

        foreach ($mentahBaru as $b) {
            $titik = self::titikUntuk($set, $b['titik_ukur'] ?? null);

            if ($titik === null) {
                throw ValidationException::withMessages(['measurements' => sprintf(
                    'Pembacaan di nominal %s tidak punya titik di daftar titik sesi. Tambahkan titiknya dulu, '
                    .'supaya pembacaan ini tidak tersimpan tanpa titik pemiliknya.',
                    is_numeric($b['titik_ukur'] ?? null) ? self::angka((float) $b['titik_ukur']) : '(kosong)',
                )]);
            }

            if (($b['tahap'] ?? 'sesudah_adjustment') === 'sebelum_adjustment' && ! in_array('sebelum', $titik['fase'], true)) {
                throw ValidationException::withMessages(['measurements' => 'Titik '.self::label($titik)
                    .' tidak memilih fase "sebelum adjustment", tapi pembacaan as-found-nya dikirim.']);
            }
        }

        // Pembacaan titik nonaktif dibekukan: sama persis dengan yang tersimpan.
        // Sesi yang baru lahir di request ini belum punya yang tersimpan.
        foreach ($bekukanNonaktif ? $set['titik'] : [] as $titik) {
            if ($titik['aktif']) {
                continue;
            }

            $milik = static fn (iterable $baris): array => collect($baris)
                ->filter(static fn ($b): bool => is_numeric(data_get($b, 'titik_ukur'))
                    && self::cocok((float) data_get($b, 'titik_ukur'), (float) $titik['nominal']))
                ->map(static fn ($b): string => implode('|', [
                    data_get($b, 'tahap') ?? 'sesudah_adjustment',
                    (string) data_get($b, 'peran_sensor'),
                    (int) data_get($b, 'sensor_ke'),
                    (int) data_get($b, 'pembacaan_ke'),
                    sprintf('%.9F', (float) data_get($b, 'pembacaan')),
                ]))
                ->sort()
                ->values()
                ->all();

            if ($milik($mentahLama) !== $milik($mentahBaru)) {
                throw ValidationException::withMessages(['measurements' => 'Pembacaan titik nonaktif '
                    .self::label($titik).' tidak boleh diubah atau dihapus — kirim balik apa adanya. '
                    .'Aktifkan titiknya dulu kalau memang mau mengoreksi.']);
            }
        }

        // Pengulangan: kiriman tidak boleh melebihi jumlah kolom yang dipilih.
        foreach ((array) ($set['pengulangan'] ?? []) as $kodeTabel => $n) {
            foreach ($mentahBaru as $b) {
                $titik = self::titikUntuk($set, $b['titik_ukur'] ?? null);

                if ($titik === null || $titik['tabel'] !== $kodeTabel || in_array($b['peran_sensor'] ?? null, self::PERAN_BUKAN_PENGULANGAN, true)) {
                    continue;
                }

                if ((int) ($b['pembacaan_ke'] ?? 0) > (int) $n) {
                    throw ValidationException::withMessages(['measurements' => sprintf(
                        'Titik %s membawa pengulangan ke-%d, padahal jumlah pengulangan sesi ini %d.',
                        self::label($titik),
                        (int) $b['pembacaan_ke'],
                        (int) $n,
                    )]);
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // CMC
    // ------------------------------------------------------------------

    /**
     * Titik AKTIF yang nominalnya di luar cakupan CMC lampiran akreditasi
     * (`CalibrationProfile::cakupanCmcTitik()`). Profil yang belum bisa
     * menyebut cakupannya (null) tidak menghasilkan temuan — peringatan palsu
     * lebih buruk daripada diam yang tercatat sebagai keterbatasan.
     *
     * @param  array<string, mixed>|null  $set
     * @return list<array<string, mixed>>
     */
    public static function diLuarCmc(?array $set, CalibrationProfile $profil, Equipment $alat, array $spesifikasi): array
    {
        if ($set === null) {
            return [];
        }

        $luar = [];

        foreach ($set['titik'] as $t) {
            if (! $t['aktif']) {
                continue;
            }

            $cakupan = $profil->cakupanCmcTitik($alat, $spesifikasi, $t['satuan'] ?? null);

            if ($cakupan === null || $cakupan === []) {
                continue;
            }

            $masuk = collect($cakupan)->contains(fn (array $r): bool => $t['nominal'] >= $r['min'] - 1e-9 * max(1.0, abs($r['min']))
                && $t['nominal'] <= $r['maks'] + 1e-9 * max(1.0, abs($r['maks'])));

            if (! $masuk) {
                $luar[] = [...$t, 'cakupan' => $cakupan];
            }
        }

        return $luar;
    }

    // ------------------------------------------------------------------
    // Bantu
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $t
     * @param  Collection<string, array<string, mixed>>  $tabelBatas
     * @param  Collection<string, array<string, mixed>>  $lama
     * @return array<string, mixed>
     */
    private static function normalkan(array $t, int $i, Collection $tabelBatas, Collection $lama): array
    {
        $no = $i + 1;
        $id = trim((string) ($t['id'] ?? ''));

        if ($id === '' || strlen($id) > 64 || ! preg_match('/^[A-Za-z0-9_.:\-]+$/', $id)) {
            self::tolak("Titik ke-{$no}: `id` wajib, maks. 64 karakter huruf/angka/_.:-.");
        }

        $tabel = (string) ($t['tabel'] ?? ($tabelBatas->count() === 1 ? $tabelBatas->keys()->first() : ''));

        if (! $tabelBatas->has($tabel)) {
            self::tolak("Titik ke-{$no}: tabel `{$tabel}` tidak ada di lembar ini (yang ada: "
                .$tabelBatas->keys()->implode(', ').').');
        }

        if (! is_numeric($t['nominal'] ?? null)) {
            self::tolak("Titik ke-{$no}: nominal wajib angka.");
        }

        $asal = (string) ($t['asal'] ?? self::ASAL_TEKNISI);

        if (! in_array($asal, self::ASAL, true)) {
            self::tolak("Titik ke-{$no}: asal `{$asal}` tidak dikenal (".implode('/', self::ASAL).').');
        }

        $sebelumnya = $lama[$id] ?? null;

        if ($asal === self::ASAL_PROFIL && $sebelumnya !== null && $sebelumnya['asal'] !== self::ASAL_PROFIL) {
            self::tolak("Titik ke-{$no}: asal titik tidak bisa diubah menjadi `profil`.");
        }

        $faseBoleh = (array) $tabelBatas[$tabel]['fase'];
        $fase = array_values(array_unique(array_map('strval', (array) ($t['fase'] ?? ['sesudah']))));

        if ($fase === [] || array_diff($fase, $faseBoleh) !== [] || ! in_array('sesudah', $fase, true)) {
            self::tolak("Titik ke-{$no}: fase wajib memuat `sesudah` dan hanya boleh ".implode('/', $faseBoleh).'.');
        }

        sort($fase);

        $aktif = filter_var($t['aktif'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        if ($aktif === null) {
            self::tolak("Titik ke-{$no}: `aktif` wajib true/false.");
        }

        $alasan = self::teks($t['alasan'] ?? null, 500);
        $nominal = (float) $t['nominal'];
        $label = self::angka($nominal);

        if (! $aktif && mb_strlen((string) $alasan) < self::ALASAN_MIN) {
            self::tolak("Titik {$label}: alasan wajib diisi (min. ".self::ALASAN_MIN.' karakter) saat menonaktifkan titik.');
        }

        if ($asal === self::ASAL_PELANGGAN && mb_strlen((string) $alasan) < self::ALASAN_MIN) {
            self::tolak("Titik {$label}: titik permintaan pelanggan wajib menyebut dasarnya di `alasan` "
                .'(mis. nomor PO/permintaan).');
        }

        return [
            'id' => $id,
            'tabel' => $tabel,
            'nominal' => $nominal,
            'satuan' => self::teks($t['satuan'] ?? null, 50) ?? ($tabelBatas[$tabel]['satuan'] ?? null),
            'fase' => $fase,
            'aktif' => $aktif,
            'asal' => $asal,
            'alasan' => $alasan,
            'justifikasi_cmc' => self::teks($t['justifikasi_cmc'] ?? null, 500),
            'tambahan' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $kiriman
     * @param  array<string, mixed>|null  $tersimpan
     * @param  Collection<string, array<string, mixed>>  $tabelBatas
     * @param  list<array<string, mixed>>  $titik
     * @param  Collection<int, RawMeasurement>|null  $mentahLama
     * @return array<string, int>
     */
    private static function pengulangan(array $kiriman, ?array $tersimpan, Collection $tabelBatas, array $titik, ?Collection $mentahLama): array
    {
        $hasil = (array) ($tersimpan['pengulangan'] ?? []);

        foreach ($kiriman as $kode => $n) {
            $b = $tabelBatas[$kode] ?? null;

            if ($b === null) {
                self::tolak("Pengulangan: tabel `{$kode}` tidak ada di lembar ini.");
            }

            if ($n === null) {
                unset($hasil[$kode]);

                continue;
            }

            $p = $b['pengulangan'] ?? null;

            if ($p === null) {
                self::tolak('Jumlah pengulangan tabel "'.($b['judul'] ?? $kode).'" dipatok lembarnya.');
            }

            if (filter_var($n, FILTER_VALIDATE_INT) === false || (int) $n < $p['min'] || (int) $n > $p['maks']) {
                self::tolak(sprintf('Pengulangan tabel "%s" wajib %d–%d.', $b['judul'] ?? $kode, $p['min'], $p['maks']));
            }

            $hasil[$kode] = (int) $n;
        }

        // Mengecilkan pengulangan tidak boleh membuang pembacaan tersimpan.
        foreach ($hasil as $kode => $n) {
            foreach ($titik as $t) {
                if ($t['tabel'] !== $kode) {
                    continue;
                }

                $maks = (int) collect($mentahLama ?? [])
                    ->filter(fn ($b): bool => is_numeric($b->titik_ukur) && self::cocok((float) $b->titik_ukur, $t['nominal'])
                        && ! in_array($b->peran_sensor, self::PERAN_BUKAN_PENGULANGAN, true))
                    ->max('pembacaan_ke');

                if ($maks > $n) {
                    self::tolak(sprintf(
                        'Pengulangan tidak bisa dikecilkan ke %d: titik %s sudah punya pembacaan ke-%d yang tersimpan. '
                        .'Pembacaan teknisi tidak pernah dihapus.',
                        $n,
                        self::label($t),
                        $maks,
                    ));
                }
            }
        }

        ksort($hasil);

        return $hasil;
    }

    /** @param  Collection<int, RawMeasurement>|null  $mentah */
    private static function punyaPembacaan(?Collection $mentah, float $nominal): bool
    {
        return collect($mentah ?? [])->contains(
            fn ($b): bool => is_numeric($b->titik_ukur) && self::cocok((float) $b->titik_ukur, $nominal),
        );
    }

    /** @param  array<string, mixed>  $t */
    private static function diLuarRentangAlat(CalibrationProfile $profil, Equipment $alat, array $t): bool
    {
        return CalibrationValidator::diLuarRentangAlat(
            $profil->nilaiDalamSatuanAlat((float) $t['nominal'], $t['satuan'] ?? null, $alat),
            $alat,
        );
    }

    /**
     * @param  array<string, mixed>|null  $lama
     * @param  array<string, mixed>  $baru
     * @return list<string>
     */
    private static function ringkasan(?array $lama, array $baru): array
    {
        $baris = [];
        $sebelum = collect($lama['titik'] ?? [])->keyBy('id');

        foreach ($baru['titik'] as $t) {
            $s = $sebelum[$t['id']] ?? null;

            if ($s === null) {
                $baris[] = 'tambah '.self::label($t).' ('.$t['asal'].($t['aktif'] ? '' : ', nonaktif').')'
                    .($t['alasan'] ? ': '.$t['alasan'] : '');

                continue;
            }

            if (! self::cocok((float) $s['nominal'], $t['nominal'])) {
                $baris[] = 'nominal '.self::label($s).' → '.self::label($t);
            }

            if ($s['aktif'] && ! $t['aktif']) {
                $baris[] = 'nonaktifkan '.self::label($t).': '.$t['alasan'];
            } elseif (! $s['aktif'] && $t['aktif']) {
                $baris[] = 'aktifkan kembali '.self::label($t).($t['alasan'] ? ': '.$t['alasan'] : '');
            }

            if ($s['fase'] !== $t['fase']) {
                $baris[] = 'fase '.self::label($t).' '.implode('+', $s['fase']).' → '.implode('+', $t['fase']);
            }

            if ($s['asal'] !== $t['asal']) {
                $baris[] = 'asal '.self::label($t).' '.$s['asal'].' → '.$t['asal'];
            }

            if (($s['justifikasi_cmc'] ?? null) !== $t['justifikasi_cmc']) {
                $baris[] = 'justifikasi CMC '.self::label($t).': '.($t['justifikasi_cmc'] ?? '(dihapus)');
            }
        }

        foreach ($baru['pengulangan'] as $kode => $n) {
            $n0 = $lama['pengulangan'][$kode] ?? null;

            if ($n0 !== $n) {
                $baris[] = "pengulangan {$kode} ".($n0 ?? 'bawaan')." → {$n}";
            }
        }

        return $baris === [] ? ['set titik disimpan'] : $baris;
    }

    /** @param  array<string, mixed>  $a  @param  array<string, mixed>  $b */
    private static function sama(array $a, array $b): bool
    {
        $bakukan = static fn (array $x): string => json_encode([
            array_map(static fn (array $t): array => [
                $t['id'], $t['tabel'], (float) $t['nominal'], $t['satuan'] ?? null, $t['fase'], (bool) $t['aktif'],
                $t['asal'], $t['alasan'] ?? null, $t['justifikasi_cmc'] ?? null,
            ], $x['titik']),
            (array) $x['pengulangan'],
        ]);

        return $bakukan($a) === $bakukan($b);
    }

    private static function teks(mixed $nilai, int $maks): ?string
    {
        if ($nilai === null) {
            return null;
        }

        $teks = trim((string) $nilai);

        if (mb_strlen($teks) > $maks) {
            self::tolak("Teks maksimal {$maks} karakter.");
        }

        return $teks === '' ? null : $teks;
    }

    /** @param  array<string, mixed>  $t */
    public static function label(array $t): string
    {
        return trim(self::angka((float) $t['nominal']).' '.($t['satuan'] ?? ''));
    }

    public static function angka(float $n): string
    {
        $s = rtrim(rtrim(sprintf('%.9F', $n), '0'), '.');

        return $s === '-0' ? '0' : $s;
    }

    /** @throws ValidationException */
    private static function tolak(string $pesan): never
    {
        throw ValidationException::withMessages(['titik_sesi' => $pesan]);
    }
}

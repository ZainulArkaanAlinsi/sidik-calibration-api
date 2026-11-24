<?php

namespace App\Services\Acuan;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use RuntimeException;

/**
 * Satu pintu baca PARAMETER KEPUTUSAN METODE (lapis 1, AGENTS.md §Olah data
 * butir 1): pilihan metode yang masih menunggu Manajer Teknis (TM) dijadikan
 * parameter berdefault = perilaku kode, supaya pekerjaan tidak berhenti dan
 * tidak ada angka sertifikat yang berubah diam-diam.
 *
 * Datanya `database/data/parameter-keputusan-metode.json` (registri + log
 * penetapan append-only, pola `log-metode-tekanan-piston.json`). Profil TIDAK
 * membaca berkas itu dan tidak bercabang pada tanggal sendiri: dia bertanya
 * ke kelas ini.
 *
 * ## Tanggal, bukan "sekarang"
 *
 * Penetapan baru hanya berlaku untuk sesi yang `tanggal_kalibrasi`-nya
 * `>= berlaku_mulai` (sesi lama dan sertifikat terbit tidak bergeser). Tanggal
 * yang dipakai datang dari LINGKUP ([untukTanggal]) yang dibuka pintu hitung
 * (`PenentuVersiAcuan::jalankan/jalankanDengan`: simpan, preview, hitung
 * ulang, validator, simulasi) atau dari argumen eksplisit (cetak sertifikat).
 * Tanpa lingkup dan tanpa argumen jawabannya BAWAAN KODE — bukan "penetapan
 * terbaru hari ini": pembaca yang lupa membuka lingkup salah ke arah perilaku
 * lama, tidak pernah ke arah angka yang bergeser.
 *
 * Kelas ini singleton di container (lingkupnya state), seperti `SumberAcuan`.
 */
final class ParameterKeputusanMetode
{
    public const BERKAS = 'database/data/parameter-keputusan-metode.json';

    public const STATUS_MENUNGGU = 'menunggu_tm';

    public const STATUS_DIPUTUSKAN = 'diputuskan';

    /** @var list<string> */
    private const SETARA_MASTER = ['ya', 'tidak', 'sebagian'];

    /** @var list<string> */
    private const LINGKUP = ['hitung', 'cetak'];

    /** @var array<string, mixed>|null isi registri terurai (cache per instance) */
    private ?array $registri = null;

    /** @var array<string, mixed>|null HANYA test: isi pengganti berkas */
    private static ?array $pengganti = null;

    /** @var list<?string> tumpukan tanggal lingkup (Y-m-d, null = "tanpa tanggal") */
    private array $lingkup = [];

    /**
     * Jalankan `$aksi` dengan `$tanggal` sebagai tanggal kalibrasi yang menentukan
     * penetapan mana yang berlaku. Boleh bersarang (yang terdalam menang) dan
     * selalu dilepas di `finally` — galat di tengah hitungan tidak boleh
     * meninggalkan tanggal yang tertinggal untuk pemanggil berikutnya.
     *
     * @template T
     *
     * @param  callable(): T  $aksi
     * @return T
     */
    public function untukTanggal(DateTimeInterface|string|null $tanggal, callable $aksi): mixed
    {
        $this->lingkup[] = self::tanggalKeTeks($tanggal);

        try {
            return $aksi();
        } finally {
            array_pop($this->lingkup);
        }
    }

    /** Tanggal lingkup terdalam (Y-m-d), atau `null` bila tak ada lingkup / lingkupnya tanpa tanggal. */
    public function tanggalLingkup(): ?string
    {
        return $this->lingkup === [] ? null : $this->lingkup[array_key_last($this->lingkup)];
    }

    /**
     * Pilihan yang berlaku untuk `$kunci` pada `$tanggal` (eksplisit), atau pada
     * tanggal lingkup bila `$tanggal` null. Tanpa keduanya: bawaan kode.
     *
     * Entri yang berlaku = penetapan dengan `berlaku_mulai <= tanggal` yang
     * `berlaku_mulai`-nya paling akhir (seri → `nomor` terbesar).
     */
    public function putusan(string $kunci, DateTimeInterface|string|null $tanggal = null): PutusanMetode
    {
        $parameter = $this->parameter($kunci);
        $acuan = $tanggal !== null ? self::tanggalKeTeks($tanggal) : $this->tanggalLingkup();

        $berlaku = null;

        if ($acuan !== null) {
            foreach ($parameter['penetapan'] as $p) {
                if ($p['berlaku_mulai'] > $acuan) {
                    continue;
                }

                if ($berlaku === null
                    || $p['berlaku_mulai'] > $berlaku['berlaku_mulai']
                    || ($p['berlaku_mulai'] === $berlaku['berlaku_mulai'] && $p['nomor'] > $berlaku['nomor'])) {
                    $berlaku = $p;
                }
            }
        }

        $nilai = $berlaku['nilai'] ?? $parameter['bawaan'];
        $pilihan = collect($parameter['pilihan'])->firstWhere('kode', $nilai);

        return new PutusanMetode(
            kunci: $kunci,
            nilai: (string) $nilai,
            data: (array) ($pilihan['data'] ?? []),
            bawaan: $berlaku === null,
            nomorPenetapan: (int) ($berlaku['nomor'] ?? 0),
            berlakuMulai: $berlaku['berlaku_mulai'] ?? null,
            status: $parameter['penetapan'] === [] ? self::STATUS_MENUNGGU : self::STATUS_DIPUTUSKAN,
            tanggalAcuan: $acuan,
            parameter: $parameter,
        );
    }

    /** Apakah `$kunci` terdaftar di registri (pembaca opsional, mis. `sertifikat.tambah_hari.<profil>`). */
    public function punya(string $kunci): bool
    {
        return isset($this->muat()['parameter'][$kunci]);
    }

    /** Kode pilihan yang berlaku — jalan pintas [putusan]. */
    public function nilai(string $kunci, DateTimeInterface|string|null $tanggal = null): string
    {
        return $this->putusan($kunci, $tanggal)->nilai;
    }

    /**
     * Baris jejak sesi untuk parameter `lingkup: hitung` milik profil `$kodeProfil`
     * pada tanggal lingkup. Kosong untuk profil tanpa parameter hitung — jadi
     * 40-an profil lain tidak berubah satu byte pun.
     *
     * Parameter `lingkup: cetak` TIDAK masuk: angkanya sudah beku di snapshot
     * sertifikat (`tata_letak.tanggal_master`), dan baris tanggal terbit tidak
     * pantas ada di budget ketidakpastian.
     *
     * @return list<array<string, mixed>>
     */
    public function barisJejakProfil(string $kodeProfil): array
    {
        $baris = [];

        foreach ($this->muat()['parameter'] as $kunci => $p) {
            if ($p['profil'] === $kodeProfil && $p['lingkup'] === 'hitung') {
                $baris[] = $this->putusan((string) $kunci)->barisJejak();
            }
        }

        return $baris;
    }

    /**
     * Seluruh registri untuk layar Studio Web (baca saja): tiap parameter beserta
     * pilihan yang berlaku pada `$tanggal` (default: hari ini — untuk TAMPILAN,
     * bukan untuk menghitung).
     *
     * @return list<array<string, mixed>>
     */
    public function semua(DateTimeInterface|string|null $tanggal = null): array
    {
        $tanggal ??= CarbonImmutable::today();
        $hasil = [];

        foreach ($this->muat()['parameter'] as $kunci => $p) {
            $putusan = $this->putusan((string) $kunci, $tanggal);

            $hasil[] = [
                'kunci' => $kunci,
                'profil' => $p['profil'],
                'lingkup' => $p['lingkup'],
                'judul' => $p['judul'],
                'pertanyaan' => $p['pertanyaan'],
                'butir_tm' => $p['butir_tm'],
                'pilihan' => $p['pilihan'],
                'bawaan' => $p['bawaan'],
                'bukti_bawaan' => $p['bukti_bawaan'],
                'dampak' => $p['dampak'],
                'status' => $putusan->status,
                'berlaku' => [
                    'tanggal' => $putusan->tanggalAcuan,
                    'nilai' => $putusan->nilai,
                    'bawaan_kode' => $putusan->bawaan,
                    'penetapan' => $putusan->nomorPenetapan,
                    'berlaku_mulai' => $putusan->berlakuMulai,
                ],
                'penetapan' => $p['penetapan'],
            ];
        }

        return $hasil;
    }

    /** Hash isi kanonik satu entri penetapan — dasar ledger append-only di test. */
    public static function hashPenetapan(array $entri): string
    {
        ksort($entri);

        return hash('sha256', json_encode($entri, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));
    }

    /**
     * HANYA untuk test: pakai isi registri ini alih-alih berkasnya (mensimulasikan
     * penetapan TM tanpa menyentuh berkas append-only). Divalidasi sama ketat
     * dengan berkasnya. Panggil [lupakan] sesudahnya.
     *
     * @param  array<string, mixed>  $isi
     */
    public static function pakai(array $isi): void
    {
        self::$pengganti = $isi;
        app()->forgetInstance(self::class);
    }

    /** Buang isi pengganti dan cache — dipakai test. */
    public static function lupakan(): void
    {
        self::$pengganti = null;

        if (app()->resolved(self::class)) {
            app()->forgetInstance(self::class);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parameter(string $kunci): array
    {
        $p = $this->muat()['parameter'][$kunci] ?? null;

        if ($p === null) {
            throw new RuntimeException("Parameter keputusan metode `{$kunci}` tidak ada di registri ".self::BERKAS.'.');
        }

        return $p;
    }

    /**
     * Registri terurai dan DIPERIKSA: kunci unik, bawaan ada di pilihan, tiap
     * penetapan sah dan tidak berlaku surut. Rusak = gagal keras — registri
     * rusak yang dibaca sebagai "tidak ada penetapan" menghitung dengan
     * bawaan tanpa tanda apa pun.
     *
     * @return array{skema_versi: int, parameter: array<string, array<string, mixed>>}
     */
    private function muat(): array
    {
        if ($this->registri !== null) {
            return $this->registri;
        }

        $isi = self::$pengganti;

        if ($isi === null) {
            $berkas = base_path(self::BERKAS);

            if (! is_file($berkas)) {
                throw new RuntimeException("Registri parameter keputusan metode tidak ketemu: {$berkas}");
            }

            $isi = json_decode((string) file_get_contents($berkas), true);
        }

        return $this->registri = self::periksa($isi);
    }

    /**
     * @return array{skema_versi: int, parameter: array<string, array<string, mixed>>}
     */
    public static function periksa(mixed $isi): array
    {
        if (! is_array($isi) || ! is_int($isi['skema_versi'] ?? null) || ! is_array($isi['parameter'] ?? null)) {
            throw new RuntimeException('Registri parameter keputusan metode rusak: wajib objek dengan `skema_versi` (int) dan `parameter` (list).');
        }

        $hasil = [];

        foreach ($isi['parameter'] as $i => $p) {
            $kunci = is_array($p) ? ($p['kunci'] ?? null) : null;

            if (! is_string($kunci) || $kunci === '' || preg_match('/^[a-z0-9_]+(\.[a-z0-9_]+)+$/', $kunci) !== 1) {
                throw new RuntimeException("Registri parameter keputusan metode: entri #{$i} tanpa `kunci` yang sah (huruf kecil, angka, garis bawah, dipisah titik).");
            }

            if (isset($hasil[$kunci])) {
                throw new RuntimeException("Registri parameter keputusan metode: kunci `{$kunci}` ganda.");
            }

            foreach (['profil', 'judul', 'pertanyaan', 'bukti_bawaan', 'dampak', 'bawaan'] as $teks) {
                if (! is_string($p[$teks] ?? null) || trim($p[$teks]) === '') {
                    throw new RuntimeException("Registri parameter keputusan metode: `{$kunci}` tanpa `{$teks}`.");
                }
            }

            if (! in_array($p['lingkup'] ?? null, self::LINGKUP, true)) {
                throw new RuntimeException("Registri parameter keputusan metode: `{$kunci}` `lingkup` harus ".implode(' atau ', self::LINGKUP).'.');
            }

            if (! is_array($p['butir_tm'] ?? null) || $p['butir_tm'] === []) {
                throw new RuntimeException("Registri parameter keputusan metode: `{$kunci}` tanpa `butir_tm` (rujukan lembar centang).");
            }

            if (! is_array($p['pilihan'] ?? null) || count($p['pilihan']) < 2) {
                throw new RuntimeException("Registri parameter keputusan metode: `{$kunci}` butuh minimal dua `pilihan`.");
            }

            $kode = [];

            foreach ($p['pilihan'] as $pil) {
                $k = is_array($pil) ? ($pil['kode'] ?? null) : null;

                if (! is_string($k) || $k === '' || in_array($k, $kode, true)) {
                    throw new RuntimeException("Registri parameter keputusan metode: `{$kunci}` punya pilihan tanpa `kode` atau dengan kode ganda.");
                }

                if (! in_array($pil['setara_master'] ?? null, self::SETARA_MASTER, true)) {
                    throw new RuntimeException("Registri parameter keputusan metode: `{$kunci}.{$k}` `setara_master` harus ya, tidak, atau sebagian.");
                }

                foreach (['label', 'perilaku', 'bukti', 'bukti_singkat'] as $teks) {
                    if (! is_string($pil[$teks] ?? null) || trim($pil[$teks]) === '') {
                        throw new RuntimeException("Registri parameter keputusan metode: `{$kunci}.{$k}` tanpa `{$teks}` (sumber bukti wajib).");
                    }
                }

                $kode[] = $k;
            }

            if (! in_array($p['bawaan'], $kode, true)) {
                throw new RuntimeException("Registri parameter keputusan metode: `{$kunci}` bawaan `{$p['bawaan']}` bukan salah satu pilihan.");
            }

            $p['penetapan'] = self::periksaPenetapan($kunci, $p['penetapan'] ?? null, $kode);
            $p['_skema_versi'] = $isi['skema_versi'];
            $hasil[$kunci] = $p;
        }

        return ['skema_versi' => $isi['skema_versi'], 'parameter' => $hasil];
    }

    /**
     * @param  list<string>  $kode
     * @return list<array<string, mixed>>
     */
    private static function periksaPenetapan(string $kunci, mixed $penetapan, array $kode): array
    {
        if (! is_array($penetapan) || ! array_is_list($penetapan)) {
            throw new RuntimeException("Registri parameter keputusan metode: `{$kunci}.penetapan` wajib list (boleh kosong).");
        }

        $nomorSebelumnya = 0;
        $berlakuSebelumnya = null;

        foreach ($penetapan as $p) {
            $nomor = is_array($p) ? ($p['nomor'] ?? null) : null;

            if (! is_int($nomor) || $nomor !== $nomorSebelumnya + 1) {
                throw new RuntimeException("Registri parameter keputusan metode: `{$kunci}.penetapan` wajib bernomor 1, 2, 3, ... berurutan tanpa celah.");
            }

            $nomorSebelumnya = $nomor;

            foreach (['berlaku_mulai', 'dicatat_pada', 'tanggal_keputusan'] as $tgl) {
                if (! is_string($p[$tgl] ?? null) || ! self::tanggalSah($p[$tgl])) {
                    throw new RuntimeException("Registri parameter keputusan metode: `{$kunci}` penetapan #{$nomor} `{$tgl}` bukan tanggal Y-m-d yang sah.");
                }
            }

            // Tiga tanggal, satu urutan: tanggal_keputusan <= dicatat_pada <= berlaku_mulai.
            // `dicatat_pada` = hari entri ini masuk registri (hari PR-nya dibuat); sesi
            // yang sudah dihitung sebelum hari itu tidak boleh bergeser oleh entri ini.
            if ($p['dicatat_pada'] < $p['tanggal_keputusan']) {
                throw new RuntimeException("Registri parameter keputusan metode: `{$kunci}` penetapan #{$nomor} dicatat sebelum diputuskan (dicatat_pada sebelum tanggal_keputusan).");
            }

            if ($p['berlaku_mulai'] < $p['dicatat_pada']) {
                throw new RuntimeException("Registri parameter keputusan metode: `{$kunci}` penetapan #{$nomor} berlaku surut (berlaku_mulai sebelum dicatat_pada) — sesi lama dan sertifikat terbit tidak boleh bergeser.");
            }

            if ($berlakuSebelumnya !== null && $p['berlaku_mulai'] < $berlakuSebelumnya) {
                throw new RuntimeException("Registri parameter keputusan metode: `{$kunci}` penetapan #{$nomor} berlaku_mulai turun dibanding penetapan sebelumnya ({$p['berlaku_mulai']} < {$berlakuSebelumnya}) — penetapan wajib menaik menurut nomor.");
            }

            $berlakuSebelumnya = $p['berlaku_mulai'];

            if (! is_string($p['nilai'] ?? null) || ! in_array($p['nilai'], $kode, true)) {
                throw new RuntimeException("Registri parameter keputusan metode: `{$kunci}` penetapan #{$nomor} `nilai` bukan salah satu pilihan.");
            }

            foreach (['diputuskan_oleh', 'alasan', 'rujukan'] as $teks) {
                if (! is_string($p[$teks] ?? null) || mb_strlen(trim($p[$teks])) < 10) {
                    throw new RuntimeException("Registri parameter keputusan metode: `{$kunci}` penetapan #{$nomor} tanpa `{$teks}` yang bermakna (min. 10 karakter) — siapa, kenapa, dan atas dasar apa wajib tercatat.");
                }
            }
        }

        return $penetapan;
    }

    private static function tanggalKeTeks(DateTimeInterface|string|null $tanggal): ?string
    {
        if ($tanggal === null) {
            return null;
        }

        if ($tanggal instanceof CarbonInterface) {
            return $tanggal->format('Y-m-d');
        }

        if ($tanggal instanceof DateTimeInterface) {
            return CarbonImmutable::instance($tanggal)->format('Y-m-d');
        }

        $teks = substr(trim($tanggal), 0, 10);

        if (! self::tanggalSah($teks)) {
            throw new RuntimeException("Tanggal parameter keputusan metode `{$tanggal}` bukan Y-m-d yang sah.");
        }

        return $teks;
    }

    /**
     * Tanggal kalender yang SAH (31 Feb ditolak, bukan digulung ke Maret).
     * Pakai DateTimeImmutable polos: Carbon bisa melempar atau menggulung
     * tergantung versi, dan di sini hasilnya harus selalu ya/tidak.
     */
    private static function tanggalSah(string $teks): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $teks) !== 1) {
            return false;
        }

        $dibaca = \DateTimeImmutable::createFromFormat('!Y-m-d', $teks);

        return $dibaca !== false && $dibaca->format('Y-m-d') === $teks;
    }
}

<?php

namespace App\Services\Pelanggan;

use App\Events\PerubahanDataOrganisasi;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\KoreksiPelanggan;
use App\Models\User;
use App\Notifications\KoreksiPelangganBaru;
use App\Notifications\Pelanggan\KabarKoreksiPermintaan;
use App\Services\PenerimaNotifikasi;
use App\Services\RevisiSertifikat;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Kabar;
use Illuminate\Validation\ValidationException;

/**
 * Koreksi data dari pelanggan: diajukan pelanggan, diputus lab (§42, D5 §38).
 *
 * - Koreksi ALAT yang diterima mengubah kolom identitas alatnya — tercatat di
 *   riwayat audit alat (`Diaudit`), nilai lama & baru dua-duanya.
 * - Koreksi SERTIFIKAT yang diterima menerbitkan REVISI lewat
 *   `RevisiSertifikat` — pintu yang sama dengan tombol Revisi admin, jadi
 *   aturannya tidak pernah bercabang.
 *
 * Satu koreksi `menunggu` per sasaran. Dua ajuan yang menunggu untuk alat yang
 * sama cuma membingungkan admin soal mana yang berlaku.
 */
class AlurKoreksi
{
    /**
     * Kunci identitas alat yang dikunci sesudah sertifikat terbit (PL_Ubah_Alat
     * "TUJUH field identitas") → label. Rentang = dua kolom.
     */
    public const KUNCI_ALAT = [
        'nama_alat' => 'Nama alat',
        'merk' => 'Merk',
        'model' => 'Model',
        'serial_number' => 'Nomor seri',
        'no_identifikasi' => 'No. identifikasi',
        'range_min' => 'Rentang min.',
        'range_max' => 'Rentang maks.',
        'satuan' => 'Satuan',
        'resolusi' => 'Resolusi',
    ];

    public const KUNCI_ANGKA = ['range_min', 'range_max', 'resolusi'];

    /** Kunci sertifikat yang boleh diminta pelanggan — D2 tanpa masa berlaku. */
    public const KUNCI_SERTIFIKAT = [
        'pemilik', 'alamat', 'merk', 'tipe', 'nomor_seri', 'lokasi_kalibrasi', 'tanggal_kalibrasi',
    ];

    public function __construct(
        private readonly RevisiSertifikat $revisi,
        private readonly PenerimaNotifikasi $penerimaLab,
        private readonly PreferensiNotifikasi $preferensi,
    ) {}

    /**
     * @param  array<string, mixed>  $perubahan
     *
     * @throws ValidationException
     */
    public function ajukanAlat(Customer $perusahaan, User $pemohon, Equipment $alat, array $perubahan, ?string $catatan): KoreksiPelanggan
    {
        $lama = [];

        foreach (array_keys(self::KUNCI_ALAT) as $kunci) {
            $lama[$kunci] = self::teks($alat->getAttribute($kunci), in_array($kunci, self::KUNCI_ANGKA, true));
        }

        $daftar = $this->daftarPerubahan($lama, $perubahan, self::KUNCI_ALAT, self::KUNCI_ANGKA);

        return $this->simpan($perusahaan, $pemohon, KoreksiPelanggan::JENIS_ALAT, $alat, null, $daftar, $catatan);
    }

    /**
     * @param  array<string, mixed>  $perubahan
     *
     * @throws ValidationException
     */
    public function ajukanSertifikat(Customer $perusahaan, User $pemohon, Certificate $sertifikat, array $perubahan, ?string $catatan): KoreksiPelanggan
    {
        if ($sertifikat->statusDokumen() !== Certificate::DOKUMEN_BERLAKU) {
            throw ValidationException::withMessages([
                'sertifikat' => 'Sertifikat ini sudah tidak berlaku (digantikan atau dibatalkan). Koreksi diajukan untuk sertifikat yang berlaku.',
            ]);
        }

        $cetak = RevisiSertifikat::dataCetak($sertifikat);

        if ($cetak === null) {
            throw ValidationException::withMessages([
                'sertifikat' => 'Sertifikat lama ini tidak bisa dikoreksi dari aplikasi. Hubungi lab lewat pesan permintaan.',
            ]);
        }

        $label = array_intersect_key(RevisiSertifikat::LABEL, array_flip(self::KUNCI_SERTIFIKAT));
        $daftar = $this->daftarPerubahan($cetak, $perubahan, $label, []);

        return $this->simpan(
            $perusahaan, $pemohon, KoreksiPelanggan::JENIS_SERTIFIKAT,
            $sertifikat->session?->equipment, $sertifikat, $daftar, $catatan,
        );
    }

    /**
     * Lab MENERIMA. `$timpa` (opsional) = nilai yang admin betulkan sebelum
     * diterapkan; kuncinya harus kunci yang memang diminta di koreksi ini.
     *
     * @param  array<string, mixed>  $timpa
     *
     * @throws ValidationException
     */
    public function terima(KoreksiPelanggan $koreksi, User $admin, array $timpa, ?string $tanggapan, ?string $alasan): KoreksiPelanggan
    {
        if ($koreksi->jenis === KoreksiPelanggan::JENIS_ALAT) {
            // Satu transaksi: kunci koreksi, ubah alat, tandai diterima. Dua
            // admin yang menekan Terima bersamaan diserialkan di kunci itu.
            $koreksi = DB::transaction(function () use ($koreksi, $admin, $timpa, $tanggapan): KoreksiPelanggan {
                $baris = $this->kunciMenunggu($koreksi);
                $final = $this->nilaiFinal($baris, $timpa);

                $this->terapkanKeAlat($baris, $final);
                $this->tandaiDiterima($baris, $admin, $final, $tanggapan, null);

                return $baris;
            });
        } else {
            $final = $this->nilaiFinal($this->kunciMenunggu($koreksi), $timpa);

            // Revisi diterbitkan di LUAR transaksi koreksi: `terbitkan()` punya
            // transaksinya sendiri dan mendorong job render sesudah commit.
            // Membungkusnya berarti worker antrean bisa mengambil job untuk
            // baris yang belum ter-commit. Dua admin yang menerima bersamaan
            // tetap aman — yang kedua ditolak `bisaDiubahStatusnya()` di dalamnya.
            $revisi = $this->revisi->terbitkan(
                $koreksi->certificate()->firstOrFail(),
                $final,
                filled($alasan) ? (string) $alasan : "Koreksi dari pelanggan #{$koreksi->id}",
                $tanggapan,
                $admin,
            );

            $koreksi = DB::transaction(function () use ($koreksi, $admin, $final, $tanggapan, $revisi): KoreksiPelanggan {
                $baris = $this->kunciMenunggu($koreksi);
                $this->tandaiDiterima($baris, $admin, $final, $tanggapan, $revisi);

                return $baris;
            });
        }

        $this->sesudahDiputus($koreksi->fresh(['revisi', 'customer']));

        return $koreksi;
    }

    /** @throws ValidationException */
    public function tolak(KoreksiPelanggan $koreksi, User $admin, string $tanggapan): KoreksiPelanggan
    {
        $koreksi = DB::transaction(function () use ($koreksi, $admin, $tanggapan): KoreksiPelanggan {
            $baris = $this->kunciMenunggu($koreksi);

            $baris->update([
                'status' => KoreksiPelanggan::STATUS_DITOLAK,
                'tanggapan' => $tanggapan,
                'ditinjau_oleh' => $admin->id,
                'ditinjau_pada' => now(),
            ]);

            return $baris;
        });

        $this->sesudahDiputus($koreksi->fresh(['customer']));

        return $koreksi;
    }

    /**
     * @param  array<string, mixed>  $timpa
     * @return array<string, string|null>
     *
     * @throws ValidationException
     */
    private function nilaiFinal(KoreksiPelanggan $koreksi, array $timpa): array
    {
        $diminta = collect($koreksi->perubahan)->keyBy('field');
        $asing = array_diff(array_keys($timpa), $diminta->keys()->all());

        if ($asing !== []) {
            throw ValidationException::withMessages(collect($asing)->mapWithKeys(fn (string $k) => [
                "perubahan.{$k}" => 'Bagian ini tidak diminta di koreksi ini.',
            ])->all());
        }

        return $diminta->map(fn (array $baris, string $kunci) => array_key_exists($kunci, $timpa)
            ? self::teks($timpa[$kunci], in_array($kunci, self::KUNCI_ANGKA, true))
            : $baris['baru'])->all();
    }

    /** @param  array<string, string|null>  $final */
    private function tandaiDiterima(KoreksiPelanggan $baris, User $admin, array $final, ?string $tanggapan, ?Certificate $revisi): void
    {
        $baris->update([
            'status' => KoreksiPelanggan::STATUS_DITERIMA,
            'tanggapan' => filled($tanggapan) ? $tanggapan : null,
            'ditinjau_oleh' => $admin->id,
            'ditinjau_pada' => now(),
            'certificate_revisi_id' => $revisi?->id,
            // Nilai yang BENAR-BENAR diterapkan ikut dicatat di samping yang
            // diminta — admin boleh membetulkannya, dan dua-duanya harus
            // terbaca di jejak (ISO/IEC 17025 §7.5.2).
            'perubahan' => collect($baris->perubahan)
                ->map(fn (array $b) => $b + ['diterapkan' => $final[$b['field']] ?? $b['baru']])
                ->values()
                ->all(),
        ]);
    }

    /**
     * @param  array<string, string|null>  $lama
     * @param  array<string, mixed>  $perubahan
     * @param  array<string, string>  $label
     * @param  list<string>  $kunciAngka
     * @return list<array{field: string, label: string, lama: string|null, baru: string|null}>
     *
     * @throws ValidationException
     */
    private function daftarPerubahan(array $lama, array $perubahan, array $label, array $kunciAngka): array
    {
        $asing = array_diff(array_keys($perubahan), array_keys($label));

        if ($asing !== []) {
            throw ValidationException::withMessages(collect($asing)->mapWithKeys(fn (string $k) => [
                "perubahan.{$k}" => 'Bagian ini tidak bisa diminta koreksi.',
            ])->all());
        }

        $daftar = [];

        foreach ($perubahan as $kunci => $nilai) {
            $baru = self::teks($nilai, in_array($kunci, $kunciAngka, true));

            if ($baru === ($lama[$kunci] ?? null)) {
                continue;
            }

            $daftar[] = ['field' => $kunci, 'label' => $label[$kunci], 'lama' => $lama[$kunci] ?? null, 'baru' => $baru];
        }

        if ($daftar === []) {
            throw ValidationException::withMessages([
                'perubahan' => 'Tidak ada yang berbeda dari data yang tercatat.',
            ]);
        }

        return $daftar;
    }

    /**
     * @param  list<array<string, mixed>>  $daftar
     *
     * @throws ValidationException
     */
    private function simpan(
        Customer $perusahaan,
        User $pemohon,
        string $jenis,
        ?Equipment $alat,
        ?Certificate $sertifikat,
        array $daftar,
        ?string $catatan,
    ): KoreksiPelanggan {
        $koreksi = DB::transaction(function () use ($perusahaan, $pemohon, $jenis, $alat, $sertifikat, $daftar, $catatan): KoreksiPelanggan {
            // Kunci di baris sasaran: dua anggota yang menekan "Minta koreksi"
            // bersamaan tidak boleh melahirkan dua koreksi `menunggu`.
            if ($sertifikat !== null) {
                Certificate::whereKey($sertifikat->id)->lockForUpdate()->first();
            } elseif ($alat !== null) {
                Equipment::withTrashed()->whereKey($alat->id)->lockForUpdate()->first();
            }

            $sudahAda = KoreksiPelanggan::query()
                ->where('status', KoreksiPelanggan::STATUS_MENUNGGU)
                ->where('jenis', $jenis)
                ->when(
                    $jenis === KoreksiPelanggan::JENIS_SERTIFIKAT,
                    fn ($q) => $q->where('certificate_id', $sertifikat?->id),
                    fn ($q) => $q->where('equipment_id', $alat?->id),
                )
                ->exists();

            if ($sudahAda) {
                throw ValidationException::withMessages([
                    'koreksi' => 'Masih ada koreksi yang menunggu ditinjau lab untuk data ini.',
                ]);
            }

            return KoreksiPelanggan::create([
                'organization_id' => $perusahaan->organization_id,
                'customer_id' => $perusahaan->id,
                'diajukan_oleh' => $pemohon->id,
                'jenis' => $jenis,
                'equipment_id' => $alat?->id,
                'certificate_id' => $sertifikat?->id,
                'perubahan' => $daftar,
                'catatan' => filled($catatan) ? $catatan : null,
                'status' => KoreksiPelanggan::STATUS_MENUNGGU,
            ]);
        });

        $this->kabari(
            $this->penerimaLab->adminAktif((int) $koreksi->organization_id),
            KoreksiPelangganBaru::dari($koreksi),
            $koreksi,
        );
        PerubahanDataOrganisasi::siarkanAman((int) $koreksi->organization_id, 'koreksi_pelanggan', 'dibuat', $koreksi->id);

        return $koreksi;
    }

    /**
     * @param  array<string, string|null>  $final
     *
     * @throws ValidationException
     */
    private function terapkanKeAlat(KoreksiPelanggan $koreksi, array $final): void
    {
        /** @var Equipment|null $alat */
        $alat = Equipment::withTrashed()->whereKey($koreksi->equipment_id)->lockForUpdate()->first();

        if ($alat === null) {
            throw ValidationException::withMessages(['koreksi' => 'Alat untuk koreksi ini sudah tidak ada.']);
        }

        if (array_key_exists('serial_number', $final)) {
            if ($final['serial_number'] === null) {
                throw ValidationException::withMessages(['perubahan.serial_number' => 'Nomor seri tidak boleh kosong.']);
            }

            $bentrok = Equipment::withTrashed()
                ->where('organization_id', $alat->organization_id)
                ->where('serial_number', $final['serial_number'])
                ->whereKeyNot($alat->id)
                ->exists();

            if ($bentrok) {
                throw ValidationException::withMessages([
                    'perubahan.serial_number' => 'Nomor seri ini sudah dipakai alat lain di lab ini.',
                ]);
            }
        }

        if (array_key_exists('nama_alat', $final) && $final['nama_alat'] === null) {
            throw ValidationException::withMessages(['perubahan.nama_alat' => 'Nama alat tidak boleh kosong.']);
        }

        $alat->update(collect($final)->map(fn (?string $v, string $k) => $v !== null && in_array($k, self::KUNCI_ANGKA, true)
            ? (float) $v
            : $v)->all());
    }

    /** @throws ValidationException */
    private function kunciMenunggu(KoreksiPelanggan $koreksi): KoreksiPelanggan
    {
        /** @var KoreksiPelanggan $baris */
        $baris = KoreksiPelanggan::query()->whereKey($koreksi->id)->lockForUpdate()->firstOrFail();

        if (! $baris->masihMenunggu()) {
            throw ValidationException::withMessages(['koreksi' => 'Koreksi ini sudah diputus.']);
        }

        return $baris;
    }

    private function sesudahDiputus(KoreksiPelanggan $koreksi): void
    {
        if ($koreksi->customer !== null) {
            $this->kabari(
                $this->preferensi->penerima($koreksi->customer, PreferensiNotifikasi::STATUS_PERMINTAAN),
                KabarKoreksiPermintaan::koreksiDiputus($koreksi),
                $koreksi,
            );
        }

        PerubahanDataOrganisasi::siarkanAman((int) $koreksi->organization_id, 'koreksi_pelanggan', $koreksi->status, $koreksi->id);
    }

    /**
     * Pola `AlurPermintaan::kabari` — Reverb yang mati tidak boleh menjawab 500
     * sesudah datanya tersimpan.
     *
     * @param  iterable<User>  $penerima
     */
    private function kabari(iterable $penerima, Notification $notifikasi, KoreksiPelanggan $koreksi): void
    {
        try {
            Kabar::send($penerima, $notifikasi);
        } catch (\Throwable $e) {
            Log::warning('Notifikasi koreksi pelanggan gagal dikirim.', [
                'koreksi_id' => $koreksi->id,
                'notifikasi' => $notifikasi::class,
                'pesan' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Normalisasi nilai jadi teks yang bisa dibandingkan. Angka dibandingkan
     * sebagai angka — `0.010` dan `0.01` itu nilai yang sama, dan MySQL
     * memulangkan `decimal` sebagai string berekor nol.
     */
    public static function teks(mixed $nilai, bool $angka = false): ?string
    {
        if ($nilai === null) {
            return null;
        }

        $s = trim((string) $nilai);

        if ($s === '') {
            return null;
        }

        if ($angka) {
            $titik = str_replace(',', '.', $s);

            if (is_numeric($titik)) {
                return rtrim(rtrim(number_format((float) $titik, 8, '.', ''), '0'), '.');
            }
        }

        return $s;
    }
}

<?php

namespace App\Services\Acuan;

use App\Models\ParameterMetodePenetapan;
use App\Models\ParameterMetodePenetapanJejak;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Penulis PENETAPAN keputusan metode (Studio Tahap 1, 25 Nov 2026). Satu-satunya
 * pintu tulis tabel `parameter_metode_penetapan`; pembacanya untuk perhitungan
 * tetap `ParameterKeputusanMetode`.
 *
 * Aturan (keputusan koordinator D2/D3), semuanya ditegakkan DI SINI di server:
 *
 *  - Baris baru selalu `menunggu_tm`; tidak memengaruhi angka. Cap waktu
 *    `dicatat_pada` dari jam SERVER, bukan dari klien.
 *  - `berlaku_mulai >= tanggal server` saat dicatat, DAN saat disetujui
 *    (penetapan yang dibiarkan menunggu sampai tanggalnya lewat menjadi berlaku
 *    surut: ditolak, tarik lalu catat ulang). Sesi lama dan sertifikat terbit
 *    tidak boleh bergeser.
 *  - Per parameter, `berlaku_mulai` tidak boleh turun menurut nomor. Pembanding =
 *    baris TERAKHIR yang belum ditarik (berkas atau DB); baris yang ditarik tidak
 *    menahan penetapan berikutnya (salah ketik tanggal jauh di depan tidak
 *    mengunci parameter selamanya). Invarian: baris yang belum ditarik selalu
 *    menaik menurut nomor, jadi "nomor terbesar menang" di pembaca sama dengan
 *    "paling akhir berlaku".
 *  - Isi baris tidak pernah diubah/dihapus; hanya transisi status
 *    (`menunggu_tm` -> `disetujui` | `ditarik`, `disetujui` -> `ditarik`), tiap
 *    transisi satu baris jejak (siapa, kapan, dari-ke, catatan).
 *
 * Tiap penulisan dalam satu transaksi dengan kunci baris (`lockForUpdate`;
 * di SQLite tanpa efek tetapi satu penulis per waktu), dan indeks unik
 * `(kunci, nomor)` menahan dua catatan serentak.
 */
final class PenetapanParameterMetode
{
    /**
     * Semua baris semua status beserta jejaknya, dikelompokkan per kunci dan
     * urut nomor. Dua query tetap (baris + jejak), bukan satu per baris.
     *
     * @return array<string, list<ParameterMetodePenetapan>>
     */
    public function daftar(): array
    {
        $hasil = [];

        foreach (ParameterMetodePenetapan::query()->with('jejak')->orderBy('kunci')->orderBy('nomor')->get() as $baris) {
            $hasil[$baris->kunci][] = $baris;
        }

        return $hasil;
    }

    public function catat(User $oleh, string $kunci, string $nilai, string $berlakuMulai, ?string $catatan = null): ParameterMetodePenetapan
    {
        $registri = app(ParameterKeputusanMetode::class);

        // Gagal keras bila `$kunci` tak ada di registri (pemanggil memeriksa lebih dulu).
        $kode = $registri->kodePilihan($kunci);

        if (! in_array($nilai, $kode, true)) {
            throw new PenetapanMetodeDitolak(
                'nilai_bukan_pilihan',
                'Nilai bukan salah satu pilihan parameter ini. Pilihan sah: '.implode(', ', $kode).'.',
                'nilai',
                ['pilihan' => $kode],
            );
        }

        if (! self::tanggalSah($berlakuMulai)) {
            throw new PenetapanMetodeDitolak('tanggal_tidak_sah', 'berlaku_mulai harus tanggal Y-m-d yang sah.', 'berlaku_mulai');
        }

        $hariIni = self::hariIni();

        if ($berlakuMulai < $hariIni) {
            throw new PenetapanMetodeDitolak(
                'berlaku_surut',
                "berlaku_mulai ({$berlakuMulai}) sebelum tanggal server ({$hariIni}): penetapan tidak boleh berlaku surut karena sesi lama dan sertifikat terbit tidak boleh bergeser.",
                'berlaku_mulai',
                ['tanggal_server' => $hariIni],
            );
        }

        try {
            return DB::transaction(function () use ($oleh, $registri, $kunci, $nilai, $berlakuMulai, $catatan): ParameterMetodePenetapan {
                $barisDb = ParameterMetodePenetapan::query()
                    ->where('kunci', $kunci)
                    ->orderBy('nomor')
                    ->lockForUpdate()
                    ->get();

                $berkas = $registri->penetapanBerkas($kunci);

                // Pembanding monoton: baris terakhir yang belum ditarik (berkas selalu hitung).
                $terakhir = null;

                foreach ($berkas as $b) {
                    $terakhir = ['nomor' => (int) $b['nomor'], 'berlaku_mulai' => (string) $b['berlaku_mulai']];
                }

                foreach ($barisDb as $b) {
                    if ($b->status !== ParameterMetodePenetapan::STATUS_DITARIK) {
                        $terakhir = ['nomor' => $b->nomor, 'berlaku_mulai' => $b->berlakuMulaiTeks()];
                    }
                }

                if ($terakhir !== null && $berlakuMulai < $terakhir['berlaku_mulai']) {
                    throw new PenetapanMetodeDitolak(
                        'berlaku_mulai_turun',
                        "berlaku_mulai ({$berlakuMulai}) lebih awal dari penetapan nomor {$terakhir['nomor']} ({$terakhir['berlaku_mulai']}): berlaku_mulai tidak boleh turun menurut nomor. Tarik penetapan itu bila memang salah.",
                        'berlaku_mulai',
                        ['nomor_sebelumnya' => $terakhir['nomor'], 'berlaku_mulai_sebelumnya' => $terakhir['berlaku_mulai']],
                    );
                }

                $nomor = max(count($berkas), (int) ($barisDb->max('nomor') ?? 0)) + 1;
                $sekarang = now();

                $baris = ParameterMetodePenetapan::query()->create([
                    'kunci' => $kunci,
                    'nomor' => $nomor,
                    'nilai' => $nilai,
                    'berlaku_mulai' => $berlakuMulai,
                    'dicatat_pada' => $sekarang,
                    'status' => ParameterMetodePenetapan::STATUS_MENUNGGU_TM,
                    'catatan' => $catatan,
                ]);

                $this->catatJejak($baris, null, ParameterMetodePenetapan::STATUS_MENUNGGU_TM, $oleh, $sekarang, $catatan);

                return $baris->load('jejak');
            });
        } catch (UniqueConstraintViolationException) {
            throw self::konflikNomor();
        } catch (Throwable $e) {
            // Parameter yang belum punya baris: `lockForUpdate` mengunci rentang kosong
            // (gap lock InnoDB), jadi dua pencatatan serentak bisa berujung deadlock
            // (SQLSTATE 40001 / 1213) alih-alih pelanggaran unik. Hasilnya sama dengan
            // pelanggaran unik: tidak ada yang tersimpan dari transaksi yang kalah,
            // klien memuat ulang lalu mencoba lagi.
            if (app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e)) {
                throw self::konflikNomor();
            }

            throw $e;
        }
    }

    private static function konflikNomor(): PenetapanMetodeDitolak
    {
        return new PenetapanMetodeDitolak('konflik_nomor', 'Penetapan lain untuk parameter ini baru saja dicatat. Muat ulang lalu coba lagi.', null, [], 409);
    }

    public function setujui(User $oleh, int $id, string $keputusanOleh, string $tanggalKeputusan): ParameterMetodePenetapan
    {
        if (! self::tanggalSah($tanggalKeputusan)) {
            throw new PenetapanMetodeDitolak('tanggal_tidak_sah', 'tanggal_keputusan harus tanggal Y-m-d yang sah.', 'tanggal_keputusan');
        }

        return DB::transaction(function () use ($oleh, $id, $keputusanOleh, $tanggalKeputusan): ParameterMetodePenetapan {
            $baris = ParameterMetodePenetapan::query()->lockForUpdate()->findOrFail($id);

            if ($baris->status !== ParameterMetodePenetapan::STATUS_MENUNGGU_TM) {
                throw new PenetapanMetodeDitolak(
                    'status_tidak_sesuai',
                    'Hanya penetapan berstatus menunggu_tm yang bisa disetujui. Status sekarang: '.$baris->status.'.',
                    null,
                    ['status' => $baris->status],
                );
            }

            $hariIni = self::hariIni();

            if ($baris->berlakuMulaiTeks() < $hariIni) {
                throw new PenetapanMetodeDitolak(
                    'berlaku_surut',
                    "Penetapan ini berlaku mulai {$baris->berlakuMulaiTeks()}, sebelum tanggal server ({$hariIni}): menyetujuinya sama dengan berlaku surut. Tarik, lalu catat ulang dengan berlaku_mulai yang baru.",
                    'berlaku_mulai',
                    ['berlaku_mulai' => $baris->berlakuMulaiTeks(), 'tanggal_server' => $hariIni],
                );
            }

            if ($tanggalKeputusan > $hariIni) {
                throw new PenetapanMetodeDitolak(
                    'tanggal_keputusan_di_depan',
                    "tanggal_keputusan ({$tanggalKeputusan}) tidak boleh sesudah tanggal server ({$hariIni}).",
                    'tanggal_keputusan',
                    ['tanggal_server' => $hariIni],
                );
            }

            $baris->status = ParameterMetodePenetapan::STATUS_DISETUJUI;
            $baris->keputusan_oleh = $keputusanOleh;
            $baris->tanggal_keputusan = $tanggalKeputusan;
            $baris->save();

            $this->catatJejak(
                $baris,
                ParameterMetodePenetapan::STATUS_MENUNGGU_TM,
                ParameterMetodePenetapan::STATUS_DISETUJUI,
                $oleh,
                now(),
                "Diputuskan oleh {$keputusanOleh}, tanggal keputusan {$tanggalKeputusan}.",
            );

            return $baris->load('jejak');
        });
    }

    public function tarik(User $oleh, int $id, string $alasan): ParameterMetodePenetapan
    {
        return DB::transaction(function () use ($oleh, $id, $alasan): ParameterMetodePenetapan {
            $baris = ParameterMetodePenetapan::query()->lockForUpdate()->findOrFail($id);

            if ($baris->status === ParameterMetodePenetapan::STATUS_DITARIK) {
                throw new PenetapanMetodeDitolak(
                    'status_tidak_sesuai',
                    'Penetapan ini sudah ditarik.',
                    null,
                    ['status' => $baris->status],
                );
            }

            $dari = $baris->status;
            $baris->status = ParameterMetodePenetapan::STATUS_DITARIK;
            $baris->save();

            $this->catatJejak($baris, $dari, ParameterMetodePenetapan::STATUS_DITARIK, $oleh, now(), $alasan);

            return $baris->load('jejak');
        });
    }

    private function catatJejak(ParameterMetodePenetapan $baris, ?string $dari, string $ke, User $oleh, \DateTimeInterface $waktu, ?string $catatan): void
    {
        ParameterMetodePenetapanJejak::query()->create([
            'penetapan_id' => $baris->id,
            'dari_status' => $dari,
            'ke_status' => $ke,
            'oleh_user_id' => $oleh->id,
            'oleh_email' => $oleh->email,
            'terjadi_pada' => $waktu,
            'catatan' => $catatan,
        ]);
    }

    /** Tanggal server (zona waktu aplikasi) sebagai Y-m-d. */
    public static function hariIni(): string
    {
        return now()->format('Y-m-d');
    }

    private static function tanggalSah(string $teks): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $teks) !== 1) {
            return false;
        }

        $dibaca = DateTimeImmutable::createFromFormat('!Y-m-d', $teks);

        return $dibaca !== false && $dibaca->format('Y-m-d') === $teks;
    }
}

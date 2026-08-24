<?php

namespace App\Console\Commands;

use App\Models\CalibrationSession;
use App\Models\Equipment;
use App\Models\User;
use App\Services\Calibration\CalibrationProfileRegistry;
use App\Services\Calibration\Profiles\CalibrationProfile;
use App\Services\Calibration\Profiles\Enclosure\EnclosureProfileBase;
use App\Services\Calibration\TabelKalibratorSuhu;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Kirim satu lembar kerja PALSU untuk TIAP profil alat lewat jalur `preview`
 * yang sebenarnya, lalu laporkan berapa titik yang benar-benar terhitung.
 *
 * ## Yang dibuktikan, dan kenapa test tidak cukup
 *
 * Suite test membuktikan tiap profil benar di database kosong berisi seeder.
 * Yang TIDAK dibuktikannya: apakah database KERJA hari ini masih punya semua
 * yang dibutuhkan jalur itu — baris kemampuan (CMC), standar acuan yang belum
 * kedaluwarsa, thermohygro yang cocok, kategori alat yang benar. Satu baris
 * master yang hilang bikin seluruh titik satu alat jatuh ke jalur cadangan
 * atau tidak terhitung sama sekali, dan itu tidak memunculkan error di mana
 * pun — cuma `belum_dihitung` yang sunyi.
 *
 * Dipakai sebelum sesi uji lapangan: satu perintah, dan ketahuan alat mana
 * yang belum siap dipakai teknisi hari itu.
 *
 *   php artisan kalibrasi:uji-profil
 *   php artisan kalibrasi:uji-profil --profil=gas_detector
 *
 * ## Yang TIDAK dilakukan perintah ini
 *
 * **Tidak menulis apa pun ke database.** Yang dipanggil `preview`, bukan
 * simpan — jadi aman dijalankan di database kerja kapan saja, termasuk
 * beberapa menit sebelum teknisi mulai. Kalau suatu saat ada yang menambah
 * penyimpanan di sini, sifat itu hilang dan perintah ini berhenti aman.
 *
 * Pembacaan yang dikirim disalin dari sesi terakhir alat itu kalau ada, atau
 * dipakai nilai standarnya sendiri kalau belum pernah ada sesi. Dua-duanya
 * cukup untuk membuktikan jalurnya jalan; yang diuji BUKAN kebenaran
 * angkanya (itu tugas `ViscometerBudgetTest` dan kawan-kawan), melainkan
 * bahwa titiknya keluar sama sekali.
 */
class UjiProfilKalibrasi extends Command
{
    protected $signature = 'kalibrasi:uji-profil
        {--profil= : batasi ke satu kode profil, mis. gas_detector}';

    protected $description = 'Uji jalur olah data tiap profil alat lewat endpoint preview (tanpa menulis ke database)';

    public function handle(CalibrationProfileRegistry $registry): int
    {
        $teknisi = User::where('role', User::ROLE_TEKNISI)
            ->where('status', User::STATUS_AKTIF)
            ->first();

        if ($teknisi === null) {
            $this->components->error('Tidak ada user teknisi aktif — jalankan seeder dulu.');

            return self::FAILURE;
        }

        // Login beneran, bukan cuma `setUserResolver`: rute preview dijaga
        // guard `sanctum`, dan guard membaca dari Auth — bukan dari resolver
        // yang ditempel ke Request. Tanpa ini semua profil balik HTTP 401 dan
        // laporannya terbaca seperti sembilan alat rusak.
        Auth::login($teknisi);

        $alatPerProfil = $this->alatPerProfil($registry);
        $filter = $this->option('profil');

        $baris = [];
        $gagal = 0;

        foreach ($registry->semua() as $profil) {
            if ($filter !== null && $profil->kode() !== $filter) {
                continue;
            }

            [$status, $keterangan, $ok] = $this->ujiSatu($profil, $alatPerProfil[$profil->kode()] ?? null, $teknisi);

            if (! $ok) {
                $gagal++;
            }

            $baris[] = [
                $profil->kode(),
                mb_substr($alatPerProfil[$profil->kode()]->nama_alat ?? '-', 0, 26),
                $status,
                $keterangan,
            ];
        }

        $this->newLine();
        $this->table(['Profil', 'Alat contoh', 'Titik', 'Keterangan'], $baris);

        if ($gagal > 0) {
            $this->components->error("{$gagal} profil belum siap dipakai.");

            return self::FAILURE;
        }

        $this->components->info('Semua profil jalan.');

        return self::SUCCESS;
    }

    /**
     * Alat contoh per profil.
     *
     * Cuma alat yang `nama_alat_kemampuan`-nya BENERAN cocok profilnya. Tanpa
     * saringan ini, alat yang jenisnya belum didukung (Jangka Sorong, misalnya)
     * jatuh ke profil pH sebagai cadangan dan kepilih jadi "alat contoh pH" —
     * jalurnya memang jalan, tapi yang diuji bukan pH, dan itu bikin laporan
     * ini berbohong tanpa kelihatan salah.
     *
     * @return array<string, Equipment>
     */
    private function alatPerProfil(CalibrationProfileRegistry $registry): array
    {
        $hasil = [];

        foreach (Equipment::orderBy('id')->get() as $alat) {
            $profil = $registry->untukAlat($alat);
            $cocok = mb_strtolower(trim($alat->nama_alat_kemampuan ?? ''))
                === mb_strtolower($profil->namaAlatKemampuan());

            if ($cocok) {
                $hasil[$profil->kode()] ??= $alat;
            }
        }

        return $hasil;
    }

    /**
     * @return array{0: string, 1: string, 2: bool} status, keterangan, lolos
     */
    private function ujiSatu(CalibrationProfile $profil, ?Equipment $alat, User $teknisi): array
    {
        // Enclosure (Oven/Furnace/Bath/Inkubator/Refrigerator) tidak lewat
        // `preview` per titik — tiap set point GRID (9 termokopel × 5) diolah di
        // `EnclosureCalculator` lewat `hitungPerGrup`. Kelima profil berbagi SATU
        // mesin hitung; yang diperiksa sesi tersimpannya lengkap. Jenis yang
        // belum punya sesi demo (Furnace/Bath/Refrigerator) diuji lewat Oven &
        // Inkubator — bukan kegagalan.
        if ($profil instanceof EnclosureProfileBase) {
            if ($alat === null) {
                return ['-', 'mesin hitung enclosure sama untuk 5 jenis — diuji lewat Oven & Inkubator', true];
            }

            $sesi = CalibrationSession::where('equipment_id', $alat->id)
                ->has('uncertaintyCalculations')
                ->latest('id')
                ->first();

            if ($sesi === null) {
                return ['-', 'sesi enclosure kosong atau tidak punya hasil hitung', false];
            }

            // Yang dibandingkan JUMLAH HASIL vs JUMLAH SET POINT YANG ADA
            // DATANYA — bukan sekadar "ada minimal satu hasil".
            //
            // Sesi setengah jadi (tiga set point terisi, satu yang kehitung)
            // dulu dilaporkan `1/1` dan lolos: yang ditanya cuma apakah relasinya
            // tidak kosong. Laporan yang bilang "jalan" untuk jalur yang
            // sebenarnya kehilangan dua pertiga hasilnya lebih berbahaya
            // daripada tidak ada laporan sama sekali.
            $terisi = $sesi->rawMeasurements()
                ->whereNotNull('peran_sensor')
                ->distinct()
                ->count('titik_ke');
            $terhitung = $sesi->uncertaintyCalculations()->count();

            return $terhitung > 0 && $terhitung === $terisi
                ? ["{$terhitung}/{$terisi}", "U95 per set point lengkap (sesi {$sesi->nomor_sesi})", true]
                : [
                    "{$terhitung}/{$terisi}",
                    sprintf('set point terisi %d tapi kehitung %d (sesi %s)', $terisi, $terhitung, $sesi->nomor_sesi),
                    false,
                ];
        }

        // TIDS sengaja BELUM menghasilkan angka — `TidsProfile::hitungPerGrup()`
        // memblokir seluruh titik sampai workbook olah data TIDS turun dari lab.
        // Jadi dia harus punya barisnya sendiri di sini, dan barisnya bukan
        // kegagalan.
        //
        // Tanpa cabang ini dia jatuh ke `tidak ada alat contoh di database` di
        // bawah, dan itu bohong dua kali: bunyinya seperti baris master yang
        // hilang (padahal yang belum ada rumusnya), dan perintah ini jadi MERAH
        // permanen. Perintah kesiapan yang selalu merah berhenti dibaca — lalu
        // alat yang beneran rusak lewat tanpa ada yang sadar. Itu justru
        // kegagalan yang perintah ini ada buat mencegahnya.
        if ($profil->kode() === 'tids') {
            return [
                '-',
                'budget ketidakpastian sengaja kosong sampai workbook TIDS turun dari lab; '
                .'lembar kerja & jalur simpannya jalan',
                true,
            ];
        }

        if ($alat === null) {
            return ['-', 'tidak ada alat contoh di database', false];
        }

        // Autoklaf tidak lewat `preview` per titik — olah datanya utuh di
        // `AutoclaveCalculator` dan hasilnya disimpan di `hasil_autoclave`.
        // Yang diperiksa: sesi tersimpannya masih lengkap.
        if ($profil->kode() === 'autoclave') {
            $sesi = CalibrationSession::where('equipment_id', $alat->id)->latest('id')->first();
            $hasil = $sesi?->hasil_autoclave;
            $lengkap = is_array($hasil) && isset($hasil['suhu'], $hasil['tekanan']);

            return $lengkap
                ? ['OK', "hasil_autoclave lengkap (sesi {$sesi->nomor_sesi})", true]
                : ['-', 'hasil_autoclave kosong atau tidak lengkap', false];
        }

        try {
            $bentuk = $profil->bentukLembarKerja(false, $alat);
            $tabel = collect($bentuk['bagian'])
                ->flatMap(fn (array $b): array => $b['tabel'] ?? [])
                ->firstWhere('tahap', 'sesudah_adjustment');

            if ($tabel === null) {
                return ['-', 'lembar kerjanya tidak punya tabel After Adjustment', false];
            }

            $payload = $this->payload($profil, $alat, $tabel);

            $request = Request::create('/api/calibrations/preview', 'POST', $payload);
            $request->setUserResolver(fn (): User => $teknisi);
            $response = app()->handle($request);
            $body = json_decode($response->getContent(), true);

            if ($response->getStatusCode() !== 200) {
                return ['-', 'HTTP '.$response->getStatusCode().' — '.mb_substr($body['message'] ?? '', 0, 60), false];
            }

            $terhitung = count($body['data']['titik'] ?? []);
            $diminta = count($tabel['baris']);
            $belum = collect($body['data']['belum_dihitung'] ?? []);

            return [
                "{$terhitung}/{$diminta}",
                $belum->isEmpty() ? 'OK' : 'belum dihitung: '.$belum->pluck('alasan')->unique()->implode('; '),
                $belum->isEmpty() && $terhitung === $diminta,
            ];
        } catch (Throwable $e) {
            return ['-', class_basename($e).': '.mb_substr($e->getMessage(), 0, 60), false];
        }
    }

    /**
     * Payload preview, disusun dari BENTUK LEMBAR KERJA-nya sendiri — bukan
     * dari daftar yang ditulis ulang di sini.
     *
     * Itu yang bikin perintah ini tetap benar waktu alat ke-11 ditambahkan:
     * titik, satuan, jumlah pengulangan, dan `standard_id` semuanya dibaca
     * dari yang dikirim profilnya.
     *
     * @param  array<string, mixed>  $tabel
     * @return array<string, mixed>
     */
    private function payload(CalibrationProfile $profil, Equipment $alat, array $tabel): array
    {
        $ulang = count($tabel['pengulangan'] ?? [1, 2, 3]);
        $adaKolomSuhu = collect($tabel['kolom'] ?? [])->contains('kode', 'suhu');

        $contoh = CalibrationSession::where('equipment_id', $alat->id)
            ->with('rawMeasurements')
            ->latest('id')
            ->first();

        $measurements = [];

        foreach ($tabel['baris'] as $i => $baris) {
            $titikUkur = (float) $baris['titik_ukur'];

            $mentah = $contoh?->rawMeasurements
                ->where('titik_ke', $i + 1)
                ->where('tahap', 'sesudah_adjustment');

            $pembacaan = $mentah !== null && $mentah->isNotEmpty()
                ? $mentah->pluck('pembacaan')->map(fn ($v): float => (float) $v)->take($ulang)->values()->all()
                : array_fill(0, $ulang, $titikUkur);

            while (count($pembacaan) < $ulang) {
                $pembacaan[] = end($pembacaan);
            }

            $satu = [
                'titik_ukur' => $titikUkur,
                'standard_id' => $baris['standard_id'] ?? null,
                'satuan' => $baris['satuan'] ?? $alat->satuan,
                'pembacaan' => $pembacaan,
            ];

            if ($adaKolomSuhu) {
                $satu['suhu'] = array_fill(0, $ulang, 25.0);
            }

            // Viscometer: tanpa spindle & RPM, MPE-nya tidak bisa dihitung dan
            // titiknya terbit tanpa vonis. Bukan kegagalan jalur, tapi bikin
            // laporan ini terbaca seperti ada yang salah.
            if ($profil->kode() === 'viscometer') {
                $satu['spindle'] = 'RV1';
                $satu['rpm'] = 60;
            }

            $measurements[] = $satu;
        }

        $payload = [
            'equipment_id' => $alat->id,
            'standard_id' => $measurements[0]['standard_id'] ?? null,
            'input_method' => 'manual',
            'tanggal_kalibrasi' => now()->subDay()->toIso8601ZuluString(),
            'suhu_awal' => 24.8,
            'suhu_akhir' => 25.2,
            'kelembaban_awal' => 55,
            'kelembaban_akhir' => 56,
            // Cuma Gas Detector yang membacanya; profil lain mengabaikan.
            'tekanan_awal' => 923.5,
            'tekanan_akhir' => 922.8,
            'measurements' => $measurements,
        ];

        if ($profil->kode() === 'viscometer') {
            $payload['spesifikasi_alat'] = ['model_visco' => 'DV2TRV'];
        }

        // TITS: tanpa mode & tipe sensor, arah perhitungan koreksi dan tabel
        // kalibrator mana yang dibaca sama-sama nggak ketahuan — profilnya
        // sengaja nolak nebak, jadi seluruh titik pulang tanpa angka. Dipakai
        // kombinasi sesi master fungsi Measure.
        if ($profil->kode() === 'tits') {
            $payload['mode_kalibrasi'] = TabelKalibratorSuhu::MODE_MEASURE;
            $payload['tipe_sensor'] = 'Type N';
        }

        return $payload;
    }
}

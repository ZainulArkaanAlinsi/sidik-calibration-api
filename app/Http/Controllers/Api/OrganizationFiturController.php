<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\FiturSaklar;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Saklar fitur lab oleh SUPER ADMIN (paket OPS-KODE butir 3).
 *
 * `GET /api/organization/fitur`  — daftar fitur yang boleh dimatikan + keadaannya.
 * `PUT /api/organization/fitur`  — menetapkan daftar fitur yang MATI (menggantikan
 *                                   seluruhnya) beserta alasan.
 *
 * ## Satu organisasi: milik pemanggil
 *
 * Tidak ada parameter organisasi, dan `organization_id` di badan permintaan
 * diabaikan: yang diubah SELALU organisasi pemanggil. Tidak ada jalan untuk
 * menyentuh saklar lab lain.
 *
 * ## Kenapa endpoint sendiri, bukan `PUT /organization`
 *
 * `PUT /organization` terbuka untuk Master Data (`admin`); saklar ini hak
 * pengendali. Kunci `fitur_nonaktif` sengaja TIDAK masuk `SETTINGS_API` di
 * `OrganizationController` — kunci di luar daftar itu ditolak 422 — dan
 * `settings` digabung per kunci, jadi PUT biasa tidak bisa menyentuhnya maupun
 * menghapusnya (`SaklarFiturTest`).
 *
 * ## Jejak audit
 *
 * Tiap fitur yang berubah menulis satu baris `audit_logs` (`fitur_dimatikan`
 * atau `fitur_dinyalakan`) dengan daftar lama dan baru, pelaku, dan alasan.
 * Model `Organization` juga menulis baris `diubah` otomatis (Diaudit) untuk
 * kolom `settings`; keduanya sengaja ada — yang pertama menjawab "siapa
 * mematikan apa dan kenapa", yang kedua "apa persisnya yang berubah di data".
 */
class OrganizationFiturController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->bentuk($request->user()->organization)]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            // `present`, bukan `required`: daftar KOSONG sah (= nyalakan semuanya),
            // dan `required` menolak larik kosong.
            'fitur_nonaktif' => ['present', 'array'],
            'fitur_nonaktif.*' => ['string', 'distinct', $this->aturanKode()],
            'alasan' => ['required', 'string', 'min:5', 'max:400'],
        ], [
            'fitur_nonaktif.present' => 'Daftar fitur yang dimatikan wajib dikirim (boleh kosong).',
            'fitur_nonaktif.array' => 'Daftar fitur yang dimatikan harus berupa larik.',
            'alasan.required' => 'Alasan wajib diisi.',
            'alasan.min' => 'Alasan minimal 5 karakter.',
            'alasan.max' => 'Alasan maksimal 400 karakter.',
        ]);

        $alasan = trim($data['alasan']);

        /** @var User $pelaku */
        $pelaku = $request->user();

        [$organisasi, $dimatikan, $dinyalakan] = DB::transaction(function () use ($pelaku, $data, $alasan): array {
            // Dikunci: baca-ubah-tulis kolom JSON `settings` bisa menimpa PUT
            // lain yang datang bersamaan (pola yang sama dengan `OrganizationController`).
            /** @var Organization $organisasi */
            $organisasi = Organization::whereKey($pelaku->organization_id)->lockForUpdate()->firstOrFail();

            $lama = FiturSaklar::nonaktif($organisasi);
            // Urut seperti daftar resmi dan tanpa duplikat.
            $baru = array_values(array_filter(
                FiturSaklar::kode(),
                fn (string $kode): bool => in_array($kode, $data['fitur_nonaktif'], true),
            ));

            $dimatikan = array_values(array_diff($baru, $lama));
            $dinyalakan = array_values(array_diff($lama, $baru));

            // Tidak ada perubahan: tidak menulis apa pun (termasuk audit).
            if ($dimatikan === [] && $dinyalakan === []) {
                return [$organisasi, [], []];
            }

            $settings = $organisasi->settings ?? [];

            if ($baru === []) {
                unset($settings[FiturSaklar::KUNCI_SETTINGS]);
            } else {
                $settings[FiturSaklar::KUNCI_SETTINGS] = $baru;
            }

            $organisasi->update(['settings' => $settings]);

            foreach ([
                AuditLog::ACTION_FITUR_DIMATIKAN => $dimatikan,
                AuditLog::ACTION_FITUR_DINYALAKAN => $dinyalakan,
            ] as $aksi => $kodeKode) {
                foreach ($kodeKode as $kode) {
                    AuditLog::create([
                        'organization_id' => $organisasi->getKey(),
                        'entity_type' => $organisasi->getTable(),
                        'entity_id' => $organisasi->getKey(),
                        'action' => $aksi,
                        'old_data' => [self::KUNCI_AUDIT => $lama],
                        'new_data' => [
                            self::KUNCI_AUDIT => $baru,
                            'fitur' => $kode,
                            'peran_pelaku' => $pelaku->role,
                            'alasan' => $alasan,
                        ],
                        'changed_by' => $pelaku->getKey(),
                        // Dipotong ke panjang kolom `note` (500); alasan LENGKAP ada di `new_data`.
                        'note' => AuditLog::catatan(sprintf(
                            'Fitur %s %s oleh %s. Alasan: %s',
                            $kode,
                            $aksi === AuditLog::ACTION_FITUR_DIMATIKAN ? 'dimatikan' : 'dinyalakan',
                            $pelaku->role,
                            $alasan,
                        )),
                    ]);
                }
            }

            return [$organisasi, $dimatikan, $dinyalakan];
        });

        return response()->json([
            'message' => $dimatikan === [] && $dinyalakan === []
                ? 'Tidak ada perubahan.'
                : 'Pengaturan fitur disimpan.',
            'perubahan' => ['dimatikan' => $dimatikan, 'dinyalakan' => $dinyalakan],
            'data' => $this->bentuk($organisasi->fresh()),
        ]);
    }

    /** Nama kunci di `old_data`/`new_data` baris audit. */
    private const KUNCI_AUDIT = 'fitur_nonaktif';

    /**
     * Kode harus terdaftar; fitur inti dijawab dengan alasan yang jelas.
     */
    private function aturanKode(): Closure
    {
        return function (string $atribut, mixed $nilai, Closure $gagal): void {
            if (! is_string($nilai) || isset(FiturSaklar::DAFTAR[$nilai])) {
                return;
            }

            $tampil = mb_substr($nilai, 0, 60);

            if (in_array($nilai, FiturSaklar::INTI, true)) {
                $gagal("Fitur inti \"{$tampil}\" (pengisian, perhitungan, persetujuan, penerbitan) tidak bisa dimatikan.");

                return;
            }

            $gagal("Kode fitur \"{$tampil}\" tidak dikenal. Kode yang boleh dimatikan: "
                .implode(', ', FiturSaklar::kode()).'.');
        };
    }

    /**
     * @return array{fitur: list<array{kode: string, label: string, deskripsi: string, aktif: bool}>, fitur_nonaktif: list<string>}
     */
    private function bentuk(?Organization $organisasi): array
    {
        $mati = FiturSaklar::nonaktif($organisasi);

        $fitur = [];
        foreach (FiturSaklar::DAFTAR as $kode => $keterangan) {
            $fitur[] = [
                'kode' => $kode,
                'label' => $keterangan['label'],
                'deskripsi' => $keterangan['deskripsi'],
                'aktif' => ! in_array($kode, $mati, true),
            ];
        }

        return ['fitur' => $fitur, 'fitur_nonaktif' => $mati];
    }
}

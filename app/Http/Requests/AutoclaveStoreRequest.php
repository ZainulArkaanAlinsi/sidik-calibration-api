<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AturanUkurAutoclave;
use App\Models\CalibrationSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Simpan sesi Autoklaf (`POST /calibrations/autoclave`). Gabungan identitas sesi
 * (equipment, tanggal, kondisi lingkungan, thermohygro) + data ukur (set point,
 * disk suhu, tekanan). Tabel kalibrator & CMC TIDAK diterima — server-side.
 *
 * Sengaja longgar kayak lembar kerja alat lain: kolom yang belum keisi tetap
 * lolos (draft bertahap), yang dijaga penerbitan sertifikatnya di pemeriksaan
 * admin — bukan kelengkapan formulir.
 */
class AutoclaveStoreRequest extends FormRequest
{
    use AturanUkurAutoclave;

    public function authorize(): bool
    {
        return true;
    }

    private function draft(): bool
    {
        return $this->string('status')->value() === CalibrationSession::STATUS_DRAFT;
    }

    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        $org = $this->user()->organization_id;

        $aturan = [
            // ---- Identitas sesi ----
            'equipment_id' => [
                'required',
                Rule::exists('equipments', 'id')->where('organization_id', $org)->whereNull('deleted_at'),
            ],
            'client_request_id' => ['sometimes', 'nullable', 'uuid'],
            'input_method' => ['sometimes', Rule::in(['manual', 'ocr'])],
            'status' => ['sometimes', Rule::in([
                CalibrationSession::STATUS_DRAFT,
                CalibrationSession::STATUS_MENUNGGU_APPROVAL,
            ])],
            'tanggal_kalibrasi' => [$this->draft() ? 'nullable' : 'required', 'date', 'before_or_equal:today'],
            'tanggal_terima' => ['sometimes', 'nullable', 'date', 'before_or_equal:tanggal_kalibrasi'],
            'nomor_order' => ['sometimes', 'nullable', 'string', 'max:100'],
            'lokasi' => ['sometimes', Rule::in(['lab', 'onsite'])],
            'lokasi_nama' => ['sometimes', 'nullable', 'string', 'max:255'],
            'room_id' => ['sometimes', 'nullable', Rule::exists('rooms', 'id')->where('organization_id', $org)->whereNull('deleted_at')],
            'calibration_method_id' => ['sometimes', 'nullable', Rule::exists('calibration_methods', 'id')->where('organization_id', $org)->whereNull('deleted_at')],
            'thermohygro_standard_id' => ['sometimes', 'nullable', Rule::exists('standards', 'id')->where('organization_id', $org)->whereNull('deleted_at')],
            'suhu_awal' => ['sometimes', 'nullable', 'numeric'],
            'suhu_akhir' => ['sometimes', 'nullable', 'numeric'],
            'kelembaban_awal' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'kelembaban_akhir' => ['sometimes', 'nullable', 'numeric', 'between:0,100'],
            'waktu_awal' => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            'waktu_akhir' => ['sometimes', 'nullable', 'date_format:H:i,H:i:s'],
            'catatan_teknisi' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'alat_model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alat_serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'alat_merk' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pemilik_nama' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pemilik_alamat' => ['sometimes', 'nullable', 'string', 'max:1000'],

            // ---- Data ukur (identik AutoclaveCalculationRequest) ----
            'set_point' => [$this->draft() ? 'nullable' : 'required', 'numeric', 'min:0'],
            'suhu' => ['sometimes', 'array'],
            'suhu.disk' => ['sometimes', 'array', 'max:3'],
            'suhu.disk.*' => ['array'],
            'suhu.disk.*.*' => ['nullable', 'numeric'],
            'suhu.indikator' => ['sometimes', 'array'],
            'suhu.indikator.*' => ['nullable', 'numeric'],
            'suhu.suhu_ruang' => ['sometimes', 'array'],
            'suhu.suhu_ruang.*' => ['nullable', 'numeric'],
            // `gt:0`, bukan `min:0` — nol di sini bukan "belum diisi".
            //
            // Angkanya jadi komponen budget di `EnclosureCalculator`
            // (`u = (resolusi_alat / 2) / sqrt(3)`), jadi nol MENGHAPUS komponen
            // daya-baca alat dari budget dan bikin U95 yang tercetak lebih kecil
            // dari yang seharusnya — sertifikat yang mengklaim ketidakpastian
            // lebih baik daripada yang alatnya sanggup.
            //
            // Yang belum diisi itu `null`/absen, dan itu tetap sah: cadangan dari
            // `config/autoclave.php` (master INPUT DATA E16/H16, dua-duanya
            // positif) yang dipakai lewat `??` di `AutoclaveInputBuilder`.
            // Dijaga `ResolusiAlatAutoklafNolDitolakTest`.
            'suhu.resolusi_alat' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            // Baris "Time" di kertas — jam pengambilan tiap kolom (02:00:00,
            // 04:00:00, ...). Nggak ikut ngitung, tapi tetap disimpan: tanpa
            // jamnya, lima kolom angka nggak bisa diadu balik ke rekaman disk.
            'waktu' => ['sometimes', 'array'],
            'waktu.*' => ['nullable', 'date_format:H:i,H:i:s'],

            'tekanan' => ['sometimes', 'array'],
            'tekanan.uut_setting' => ['sometimes', 'nullable', 'numeric'],
            // Baris "Indikator Pressure (…...)" di kertas — bacaan manometer
            // autoklaf per titik waktu.
            'tekanan.indikator_pressure' => ['sometimes', 'array'],
            'tekanan.indikator_pressure.*' => ['nullable', 'numeric'],
            'tekanan.satuan' => ['sometimes', 'string', 'in:Bar,MPa,kPa,Psi,kg/cm2,inHg,mmHg,Pa'],
            'tekanan.display' => ['sometimes', 'string', 'in:Digital,Analog 1,Analog 2,Analog 3'],
            'tekanan.resolusi_alat' => ['sometimes', 'nullable', 'numeric', 'gt:0'],
            // Angka Pressure Disk Logger nggak ada di kertas — teknisi ngisinya
            // sesudah disk-nya diunduh. Jadi blok tekanan boleh kekirim tanpa
            // baris ini; yang kesimpan tetap utuh, cuma olah data tekanannya
            // nunggu angkanya lengkap.
            'tekanan.pembacaan_standar' => ['sometimes', 'array'],
            'tekanan.pembacaan_standar.*' => ['nullable', 'numeric'],
            // Kertas nyediain LIMA kolom buat baris ini; payload lama ngirim
            // satu angka. Dua-duanya diterima supaya klien lama nggak patah.
            'tekanan.tekanan_atm_awal' => ['sometimes', 'nullable', $this->angkaAtauDeretAngka()],
        ];

        /*
         * ---- Tebakan mesin per sel (blok `ocr`) ----
         *
         * Bercermin PERSIS ke jalur nilainya, cuma berawalan `ocr.`:
         * `suhu.disk.0` nilainya, `ocr.suhu.disk.0` tebakannya, sejajar indeks.
         *
         * Kenapa bercermin dan bukan kunci datar: jalur nilainya sudah jadi
         * kontrak yang dipatok `BarisMatriks.kodeData` di server, dan HP menulis
         * ke jalur itu apa adanya. Bentuk kedua yang harus diurai ulang cuma
         * nambah tempat buat salah alamat — dan di data latih, salah alamat
         * nggak pernah kelihatan.
         *
         * Baris "Time" nggak punya padanan di sini: dia jam, bukan angka ukur,
         * dan jalur fotonya memang melewatinya.
         */
        $jalurOcr = [
            // Tiga disk suhu — satu tingkat lebih dalam dari yang lain.
            'ocr.suhu.disk.*',
            'ocr.suhu.indikator',
            'ocr.suhu.suhu_ruang',
            'ocr.tekanan.indikator_pressure',
            'ocr.tekanan.pembacaan_standar',
        ];

        $aturan['ocr'] = ['sometimes', 'nullable', 'array'];
        $aturan['ocr.suhu.disk'] = ['sometimes', 'nullable', 'array', 'max:3'];

        foreach ($jalurOcr as $jalur) {
            $aturan[$jalur] = ['sometimes', 'nullable', 'array', 'max:20'];
            $aturan[$jalur.'.*'] = ['nullable', 'array'];
            $aturan[$jalur.'.*.raw_text'] = ['nullable', 'string', 'max:255'];
            $aturan[$jalur.'.*.confidence'] = ['nullable', 'numeric', 'between:0,1'];
        }

        return $aturan;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'suhu.resolusi_alat.gt' => 'Resolusi alat harus lebih besar dari nol.',
            'tekanan.resolusi_alat.gt' => 'Resolusi alat harus lebih besar dari nol.',
        ];
    }

    /**
     * Tebakan mesin per sel, DIPISAH dari [dataUkur()] dengan sengaja.
     *
     * `dataUkur()` diumpankan ke `AutoclaveInputBuilder` — kalkulatornya. Blok
     * ini bukan data ukur: dia catatan asal-usul. Menitipkannya di sana berarti
     * kalkulator menerima kunci yang nggak dia kenal, dan angka sertifikat
     * bukan tempat buat mencoba-coba.
     *
     * @return array<string, mixed>
     */
    public function bacaanMesin(): array
    {
        return (array) $this->input('ocr', []);
    }

    /**
     * Data ukur murni (buat AutoclaveInputBuilder), dipisah dari identitas.
     *
     * @return array<string, mixed>
     */
    public function dataUkur(): array
    {
        return array_filter(
            $this->only(['set_point', 'waktu', 'suhu', 'tekanan']),
            static fn ($v): bool => $v !== null,
        );
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->pastikanAdaBacaanUut($validator)];
    }
}

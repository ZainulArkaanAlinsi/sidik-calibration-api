<?php

namespace App\Services\Acuan;

/**
 * Jawaban satu pertanyaan ke [ParameterKeputusanMetode]: pilihan mana yang
 * berlaku untuk sebuah tanggal kalibrasi, dan dari mana asalnya.
 *
 * Objek nilai tak berubah. Pembaca (profil) hanya butuh [$nilai] dan [$data];
 * sisanya ([barisJejak]) untuk jejak sesi.
 */
final class PutusanMetode
{
    /**
     * @param  array<string, mixed>  $data  muatan pilihan yang berlaku (`pilihan[].data`), `[]` bila tak ada
     * @param  array<string, mixed>  $parameter  entri registri utuh (untuk teks jejak)
     * @param  int  $nomorPenetapan  0 = bawaan kode (tidak ada penetapan yang berlaku pada tanggal itu)
     */
    public function __construct(
        public readonly string $kunci,
        public readonly string $nilai,
        public readonly array $data,
        public readonly bool $bawaan,
        public readonly int $nomorPenetapan,
        public readonly ?string $berlakuMulai,
        public readonly string $status,
        public readonly ?string $tanggalAcuan,
        private readonly array $parameter,
    ) {}

    /**
     * Satu baris jejak untuk `uncertainty_calculations.type_b_components`.
     *
     * Bentuknya sama dengan baris catatan yang sudah ada (`sumber`,
     * `keterangan`, `distribusi` '-', `nilai` null) supaya konsumen jejak yang
     * hanya membaca keempat kunci itu tidak berubah perilakunya. Kunci tambahan
     * (`parameter`, `pilihan`, ...) untuk hitung ulang dan peninjau; angkanya
     * tidak ikut hitungan mana pun (`u_baku`, `ci`, `kontribusi` nol).
     *
     * @return array<string, mixed>
     */
    public function barisJejak(): array
    {
        $pilihan = $this->pilihanTerpilih();
        $lain = array_values(array_filter(
            (array) ($this->parameter['pilihan'] ?? []),
            fn (array $p): bool => $p['kode'] !== $this->nilai,
        ));

        $asal = $this->nomorPenetapan === 0
            ? 'bawaan kode (belum ada penetapan yang berlaku pada tanggal kalibrasi)'
            : sprintf('penetapan #%d berlaku mulai %s', $this->nomorPenetapan, $this->berlakuMulai);

        $keterangan = sprintf(
            'Parameter keputusan metode %s = %s (%s; status %s; butir TM %s). %s %s',
            $this->kunci,
            $this->nilai,
            $asal,
            $this->status === 'diputuskan' ? 'diputuskan' : 'menunggu TM',
            implode(', ', (array) ($this->parameter['butir_tm'] ?? [])),
            self::kalimatMaster($pilihan['setara_master'] ?? null, (string) ($pilihan['bukti_singkat'] ?? '')),
            $lain === [] ? '' : 'Pilihan lain: '.implode(', ', array_column($lain, 'kode')).'.',
        );

        return [
            'sumber' => 'parameter_metode',
            'keterangan' => trim($keterangan),
            'distribusi' => '-',
            'nilai' => null,
            'u_baku' => 0.0,
            'ci' => 0.0,
            'kontribusi' => 0.0,
            'parameter' => $this->kunci,
            'pilihan' => $this->nilai,
            'bawaan_kode' => $this->bawaan,
            'penetapan' => $this->nomorPenetapan,
            'berlaku_mulai' => $this->berlakuMulai,
            'status' => $this->status,
            'registri_skema' => (int) ($this->parameter['_skema_versi'] ?? 0),
            'tanggal_acuan' => $this->tanggalAcuan,
        ];
    }

    /** @return array<string, mixed> */
    private function pilihanTerpilih(): array
    {
        foreach ((array) ($this->parameter['pilihan'] ?? []) as $p) {
            if (($p['kode'] ?? null) === $this->nilai) {
                return $p;
            }
        }

        return [];
    }

    /**
     * Kalimat "sama/tidak sama dengan workbook master" — aturan Olah data
     * butir 4: penyimpangan dari master wajib terbaca di jejak sesi tanpa
     * membuka kode.
     */
    private static function kalimatMaster(?string $setara, string $bukti): string
    {
        $dasar = $bukti === '' ? '' : ' Dasar: '.$bukti.'.';

        return match ($setara) {
            'ya' => 'Perilaku ini SAMA dengan workbook master.'.$dasar,
            'sebagian' => 'Perilaku ini SEBAGIAN sama dengan workbook master (workbook sendiri memuat lebih dari satu perilaku).'.$dasar,
            'tidak' => 'Perilaku ini BERBEDA dari workbook master.'.$dasar,
            default => '',
        };
    }
}

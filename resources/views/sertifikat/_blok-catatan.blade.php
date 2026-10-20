{{--
    Blok catatan/legenda khas alat (K-50-15) di satu posisi lembar, dalam urutan
    master. Isinya `snapshot.catatan_blok` yang posisinya cocok — lihat
    `CalibrationProfile::catatanSertifikat()` & `TataLetakSertifikat::POSISI_*`.
    Snapshot tanpa kunci itu (semua sertifikat lama) tidak mencetak apa pun.
--}}
@foreach ($blok as $b)
    <div class="blok-catatan">
        @if (filled($b['judul'] ?? null))
            <div class="judul-blok">{{ $b['judul'] }}</div>
        @endif
        @foreach ($b['baris'] ?? [] as $teks)
            <div>{{ $teks }}</div>
        @endforeach
        @if (! empty($b['tabel'] ?? []))
            <table class="catatan-tabel">
                @foreach ($b['tabel'] as $barisTabel)
                    <tr>
                        @foreach ($barisTabel as $sel)
                            <td>{{ $sel }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        @endif
    </div>
@endforeach

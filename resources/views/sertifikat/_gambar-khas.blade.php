{{-- Gambar khas alat di dalam print area master (G2): diagram posisi termokopel
     autoclave/enclosure + label `Thermocouple Position :`. `null` = tidak ada
     satu byte pun (snapshot lama identik). Lebar kolom dalam % lebar isi; tinggi
     gambar dalam pt (`GambarKhasSertifikat::LEBAR_ISI_PT` x pecahan) karena master
     merentangkan gambar enclosure. Gaya inline supaya tidak menyentuh CSS lama. --}}@if (filled($gambar))
@foreach ($gambar as $g)
@php
$lebarSisa = max(0, 100 - ($g['kiri'] + $g['lebar']) * 100);
@endphp
<table class="gambar-khas" data-gambar-khas="{{ $g['berkas'] }}" style="width: 100%; border-collapse: collapse; table-layout: fixed; margin: 2px 0 4px 0;"><tr>
<td style="width: {{ round($g['kiri_teks'] * 100, 2) }}%; padding: 0;"></td>
<td style="width: {{ round(($g['kiri'] - $g['kiri_teks']) * 100, 2) }}%; padding: {{ $g['turun_teks_pt'] }}pt 0 0 0; vertical-align: top; white-space: nowrap; font-size: 10px;">@foreach ($g['teks'] as $barisTeks){{ $barisTeks }}@if (! $loop->last)<br>@endif @endforeach</td>
<td style="width: {{ round($g['lebar'] * 100, 2) }}%; padding: {{ $g['turun_gambar_pt'] }}pt 0 0 0; vertical-align: top;"><img src="{{ \App\Support\GambarKhasSertifikat::dataUri($g['berkas']) }}" alt="" style="width: 100%; height: {{ round($g['tinggi'] * \App\Support\GambarKhasSertifikat::LEBAR_ISI_PT, 1) }}pt;"></td>
<td style="width: {{ round($lebarSisa, 2) }}%; padding: 0;"></td>
</tr></table>
@endforeach
@endif
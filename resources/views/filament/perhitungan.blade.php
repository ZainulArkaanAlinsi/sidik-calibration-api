{{--
    Lembar PERHITUNGAN versi panel web (spesifikasi poin 11 & 12A).

    Bentuknya ngikutin sheet "PERHITUNGAN" di Master Olah Data_pH.xlsm.
    SEMUA angka di sini datang jadi dari PerhitunganBuilder — nggak ada satu
    pun hitungan di blade ini. Ikut menghitung di tampilan cepat atau lambat
    bikin angkanya beda dari sertifikat, dan bedanya nggak ketahuan sampai ada
    yang ngebandingin dua dokumen.

    `angka()` cuma motong ekor desimal buat kebacaan; nilainya nggak disentuh.
--}}
@php
    $angka = fn (?float $n, int $desimal = 4) => $n === null
        ? '—'
        : rtrim(rtrim(number_format($n, $desimal, ',', '.'), '0'), ',');

    $alat = $perhitungan['identitas_alat'];
    $cust = $perhitungan['identitas_customer'];
    $kondisi = $perhitungan['kondisi_lingkungan'];
@endphp

<div class="space-y-6 text-sm">

    <div class="grid gap-6 md:grid-cols-2">
        <div>
            <h3 class="font-bold text-primary-600 mb-2">IDENTITAS ALAT</h3>
            <dl class="space-y-1">
                @foreach ([
                    'Nama Alat' => $alat['nama_alat'],
                    'Merk' => $alat['merk'],
                    'Type' => $alat['type'],
                    'No. Seri' => $alat['no_seri'],
                    'Rentang Ukur' => $alat['rentang_ukur'],
                    'Kapasitas Max.' => $alat['kapasitas_max'],
                    'Resolusi Alat' => $alat['resolusi'],
                ] as $label => $nilai)
                    <div class="flex gap-2">
                        <dt class="w-36 shrink-0 text-gray-500">{{ $label }}</dt>
                        <dd class="font-medium">: {{ $nilai === null || $nilai === '' ? '—' : $nilai }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <div>
            <h3 class="font-bold text-primary-600 mb-2">IDENTITAS CUSTOMER</h3>
            <dl class="space-y-1">
                @foreach ([
                    'Nama Customer' => $cust['nama'],
                    'Alamat' => $cust['alamat'],
                    'Tanggal Terima' => $cust['tanggal_terima'],
                    'Tanggal Kalibrasi' => $cust['tanggal_kalibrasi'],
                ] as $label => $nilai)
                    <div class="flex gap-2">
                        <dt class="w-36 shrink-0 text-gray-500">{{ $label }}</dt>
                        <dd class="font-medium">: {{ $nilai ?: '—' }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </div>

    <div>
        <h3 class="font-bold text-primary-600 mb-2">PERHITUNGAN KONDISI LINGKUNGAN</h3>

        @if ($kondisi['thermohygro'] === null)
            {{-- Kolom Correction & U95% bakal kosong sampai thermohygro dipilih.
                 Dikasihtau eksplisit, bukan dibiarin admin nebak kenapa strip. --}}
            <p class="text-warning-600 mb-2">
                Thermohygro belum dipilih — kolom Correction &amp; U95% bakal tetap kosong sampai diisi.
            </p>
        @endif

        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-left border-b">
                        <th class="py-1 pr-3">&nbsp;</th>
                        @foreach (['Awal', 'Akhir', 'Average', 'Indexed Value', 'Correction', 'Δ', 'U95% Std TH', 'U95% Sertifikat'] as $k)
                            <th class="py-1 px-2 text-center">{{ $k }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach (['suhu' => 'Suhu Ruangan', 'kelembaban' => 'Kelembaban'] as $key => $label)
                        @php $b = $kondisi[$key]; @endphp
                        @if ($b !== null)
                            <tr class="border-b last:border-0">
                                <td class="py-1 pr-3 font-medium whitespace-nowrap">
                                    {{ $label }} ({{ $b['satuan'] }})
                                </td>
                                @foreach (['awal', 'akhir', 'average', 'indexed_value', 'correction', 'delta', 'u95_std_th', 'u95_sertifikat'] as $kol)
                                    <td class="py-1 px-2 text-center">{{ $angka($b[$kol]) }}</td>
                                @endforeach
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-2 text-gray-500">
            Thermohygro Used: <span class="font-medium">{{ $kondisi['thermohygro'] ?? '—' }}</span>
        </p>
    </div>

    <div>
        <h3 class="font-bold text-primary-600 mb-2">DATA HASIL KALIBRASI</h3>

        @foreach ($perhitungan['hasil'] as $tabel)
            <div class="mb-5">
                <p class="font-semibold mb-1">{{ $tabel['judul'] }}</p>

                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead>
                            <tr class="text-left border-b">
                                <th class="py-1 pr-3">Standard</th>
                                @foreach ($tabel['titik'] as $t)
                                    {{-- Header = nilai buffer pada suhu larutan, BUKAN nominal. --}}
                                    <th class="py-1 px-2 text-center">
                                        {{ $angka($t['standard'], 7) }}
                                        <span class="block font-normal text-gray-400">{{ $t['satuan'] }}</span>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $maksRepeat = collect($tabel['titik'])->map(fn ($t) => count($t['pembacaan']))->max() ?? 0;
                            @endphp

                            @for ($r = 0; $r < $maksRepeat; $r++)
                                <tr class="border-b last:border-0">
                                    <td class="py-1 pr-3 text-gray-500">Repeat {{ $r + 1 }}</td>
                                    @foreach ($tabel['titik'] as $t)
                                        @php $p = $t['pembacaan'][$r] ?? null; @endphp
                                        <td class="py-1 px-2 text-center">
                                            @if ($p === null)
                                                —
                                            @else
                                                {{ $angka($p['nilai']) }}
                                                <span class="text-gray-400">
                                                    · {{ $angka($p['suhu'], 2) }} °C
                                                </span>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endfor

                            <tr class="border-t-2 font-semibold">
                                <td class="py-1 pr-3">Average</td>
                                @foreach ($tabel['titik'] as $t)
                                    <td class="py-1 px-2 text-center">{{ $angka($t['average']) }}</td>
                                @endforeach
                            </tr>
                            <tr class="font-semibold">
                                <td class="py-1 pr-3">Correction</td>
                                @foreach ($tabel['titik'] as $t)
                                    <td class="py-1 px-2 text-center">{{ $angka($t['correction'], 7) }}</td>
                                @endforeach
                            </tr>
                            <tr>
                                <td class="py-1 pr-3 text-gray-500">STDEV</td>
                                @foreach ($tabel['titik'] as $t)
                                    <td class="py-1 px-2 text-center">{{ $angka($t['stdev'], 7) }}</td>
                                @endforeach
                            </tr>
                        </tbody>
                    </table>
                </div>

                <p class="mt-1 text-xs text-gray-500">
                    MAX STDEV: <span class="font-medium">{{ $angka($tabel['max_stdev'], 7) }}</span>
                </p>
            </div>
        @endforeach
    </div>

    {{-- Dua catatan yang paling gampang bikin salah baca angka. Ditulis di
         layar, bukan cuma di komentar kode: yang kebalik nanti itu orangnya. --}}
    <div class="text-xs text-gray-500 space-y-1 border-t pt-3">
        <p>Standard = nilai buffer pada suhu larutan saat itu, bukan nilai nominal.</p>
        <p>Di lembar ini Correction = Average − Standard. Di sertifikat tandanya kebalikan.</p>
    </div>
</div>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Sertifikat {{ $sertifikat->nomor }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 11px; color: #1a1a1a; margin: 0; }
        .kop { border-bottom: 3px double #333; padding-bottom: 10px; margin-bottom: 16px; }
        .kop table { width: 100%; border-collapse: collapse; }
        .kop td.logo { width: 84px; vertical-align: middle; }
        .kop td.logo img { width: 74px; height: auto; }
        .kop td.teks { vertical-align: middle; }
        .kop h1 { font-size: 16px; margin: 0 0 2px; }
        .kop .akr { font-size: 10px; color: #555; }
        .judul { text-align: center; font-size: 15px; font-weight: bold; letter-spacing: 1px; margin: 4px 0 2px; }
        .nomor { text-align: center; font-size: 11px; color: #444; margin-bottom: 16px; }
        table.info { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.info td { padding: 3px 6px; vertical-align: top; }
        table.info td.lbl { width: 30%; color: #555; }
        table.data { width: 100%; border-collapse: collapse; margin-bottom: 14px; font-size: 10px; }
        table.data th, table.data td { border: 1px solid #999; padding: 4px 6px; text-align: center; }
        table.data th { background: #f0f0f0; }
        .putusan { text-align: center; font-size: 14px; font-weight: bold; padding: 8px; margin: 10px 0; border: 2px solid; }
        .pass { color: #146c2e; border-color: #146c2e; }
        .fail { color: #a01919; border-color: #a01919; }
        .footer { font-size: 9px; color: #555; border-top: 1px solid #ccc; padding-top: 8px; margin-top: 20px; }
        .verify { margin-top: 12px; font-size: 9px; }
        .verify code { font-size: 11px; font-weight: bold; }
        .judul-sub { font-size: 11px; font-weight: bold; margin: 14px 0 4px; }
        table.ttd { width: 100%; border-collapse: collapse; margin-top: 24px; }
        table.ttd td { width: 50%; vertical-align: top; font-size: 10px; }
        .ttd .garis { margin-top: 40px; border-top: 1px solid #333; padding-top: 3px; }
        /* Kalau ada gambar TTD, dia yang ngisi jarak di atas garis — jadi
           margin-top garisnya dikecilin lewat kelas ini biar nggak dobel. */
        .ttd .ttd-gambar { margin-top: 6px; height: 46px; }
        .ttd .ttd-gambar + .garis { margin-top: 0; }
        .ttd .ttd-gambar img { height: 46px; width: auto; }
        .halaman { position: fixed; top: -20px; right: 0; font-size: 9px; color: #777; }
        .disclaimer { font-size: 9px; font-style: italic; color: #555; margin-top: 6px; }
    </style>
</head>
<body>
    <div class="kop">
        <table>
            <tr>
                @if (! empty($logo))
                    <td class="logo"><img src="{{ $logo }}" alt="Logo"></td>
                @endif
                <td class="teks">
                    <h1>{{ $sesi->organization->nama ?? 'Laboratorium Kalibrasi' }}</h1>
                    <div class="akr">
                        Terakreditasi {{ $sesi->organization->standar_akreditasi ?? 'KAN' }}
                        &middot; No. {{ $sesi->organization->no_akreditasi ?? '—' }}
                        @if ($sesi->organization->alamat) <br>{{ $sesi->organization->alamat }} @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="halaman">Halaman 1 dari 1</div>
    <div class="judul">SERTIFIKAT KALIBRASI</div>
    <div class="nomor">Nomor: {{ $sertifikat->nomor }}@if ($sesi->nomor_order) &middot; No. Order: {{ $sesi->nomor_order }} @endif</div>

    <table class="info">
        <tr>
            <td class="lbl">Nama alat</td><td>{{ $sesi->equipment->nama_alat }}</td>
            <td class="lbl">Nomor seri</td><td>{{ $sesi->equipment->serial_number }}</td>
        </tr>
        <tr>
            <td class="lbl">Merk / Model</td><td>{{ $sesi->equipment->merk }} {{ $sesi->equipment->model }}</td>
            <td class="lbl">Pemilik</td><td>{{ $sesi->equipment->customer->nama ?? '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Kapasitas / Graduasi</td>
            <td>
                @if ($sesi->equipment->range_min !== null || $sesi->equipment->range_max !== null)
                    {{ $sesi->equipment->range_min ?? '—' }}–{{ $sesi->equipment->range_max ?? '—' }} {{ $sesi->equipment->satuan }}
                    @if ($sesi->equipment->resolusi) / {{ $sesi->equipment->resolusi }} {{ $sesi->equipment->satuan }} @endif
                @else
                    —
                @endif
            </td>
            <td class="lbl">Lokasi kalibrasi</td><td>{{ $sesi->lokasi === 'onsite' ? 'Onsite' : 'Laboratorium' }}</td>
        </tr>
        <tr>
            <td class="lbl">Tanggal terima</td><td>{{ $sesi->tanggal_terima?->translatedFormat('d F Y') ?? '—' }}</td>
            <td class="lbl">Tanggal kalibrasi</td><td>{{ $sesi->tanggal_kalibrasi?->translatedFormat('d F Y') }}</td>
        </tr>
        <tr>
            <td class="lbl">Diterbitkan</td><td>{{ $sertifikat->diterbitkan_pada?->translatedFormat('d F Y') }}</td>
            <td class="lbl">Berlaku sampai</td><td>{{ $sertifikat->berlaku_sampai?->translatedFormat('d F Y') }}</td>
        </tr>
        <tr>
            <td class="lbl">Suhu ruang</td><td>{{ $sesi->suhu_ruang !== null ? $sesi->suhu_ruang.' °C' : '—' }}</td>
            <td class="lbl">Kelembaban</td><td>{{ $sesi->kelembaban !== null ? $sesi->kelembaban.' %RH' : '—' }}</td>
        </tr>
        <tr>
            <td class="lbl">Teknisi</td><td>{{ $sesi->teknisi->name ?? '—' }}</td>
            <td class="lbl">Metode kalibrasi</td><td>{{ $metodeKalibrasi ?? '—' }}</td>
        </tr>
    </table>

    @if ($adaLingkungan)
        <div class="judul-sub">Kondisi Lingkungan</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Parameter</th><th>Awal</th><th>Akhir</th><th>Rata-rata</th>
                    <th>Koreksi</th><th>Nilai terkoreksi</th><th>U95% (±)</th>
                </tr>
            </thead>
            <tbody>
                @foreach ([
                    ['Suhu Ruangan (°C)', $sesi->suhu_ruang_awal, $sesi->suhu_ruang_akhir, $sesi->suhu_ruang, $sesi->suhu_ruang_koreksi, $sesi->suhu_ruang_u95],
                    ['Kelembaban (%RH)', $sesi->kelembaban_awal, $sesi->kelembaban_akhir, $sesi->kelembaban, $sesi->kelembaban_koreksi, $sesi->kelembaban_u95],
                ] as [$nama, $awal, $akhir, $rata, $koreksi, $u95])
                    <tr>
                        <td style="text-align:left">{{ $nama }}</td>
                        <td>{{ $awal !== null ? rtrim(rtrim(number_format($awal, 2, '.', ''), '0'), '.') : '—' }}</td>
                        <td>{{ $akhir !== null ? rtrim(rtrim(number_format($akhir, 2, '.', ''), '0'), '.') : '—' }}</td>
                        <td>{{ $rata !== null ? number_format($rata, 2, '.', '') : '—' }}</td>
                        <td>{{ $koreksi !== null ? number_format($koreksi, 2, '.', '') : '—' }}</td>
                        <td>{{ ($rata !== null && $koreksi !== null) ? number_format($rata + $koreksi, 2, '.', '') : '—' }}</td>
                        <td>{{ $u95 !== null ? number_format($u95, 4, '.', '') : '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($sesi->thermohygro)
            <div class="disclaimer">Alat pemantau ruangan (thermohygro): {{ $sesi->thermohygro }}.</div>
        @endif
    @endif

    @foreach ([['Pembacaan — Sebelum Adjustment', $bacaSebelum], ['Pembacaan — Sesudah Adjustment', $bacaSesudah]] as [$judulBaca, $baca])
        @if ($baca->isNotEmpty())
            @php($maxBaca = $baca->max(fn ($b) => count($b['pembacaan'])))
            <div class="judul-sub">{{ $judulBaca }}</div>
            <table class="data">
                <thead>
                    <tr>
                        <th>Standar</th>
                        @for ($i = 1; $i <= $maxBaca; $i++)<th>{{ $i }}</th>@endfor
                        <th>Suhu (°C)</th><th>Rata-rata</th><th>Koreksi</th><th>STDEV</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($baca as $b)
                        <tr>
                            <td>{{ rtrim(rtrim(number_format($b['titik_ukur'], 4, '.', ''), '0'), '.') }}</td>
                            @foreach ($b['pembacaan'] as $p)<td>{{ rtrim(rtrim(number_format($p, 2, '.', ''), '0'), '.') }}</td>@endforeach
                            @for ($i = count($b['pembacaan']); $i < $maxBaca; $i++)<td>—</td>@endfor
                            <td>{{ $b['suhu_rata'] !== null ? number_format($b['suhu_rata'], 1, '.', '') : '—' }}</td>
                            <td>{{ number_format($b['rata_rata'], 4, '.', '') }}</td>
                            <td>{{ number_format($b['koreksi'], 5, '.', '') }}</td>
                            <td>{{ number_format($b['stdev'], 5, '.', '') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    @endforeach

    <div class="judul-sub">Hasil Kalibrasi{{ $bacaSesudah->isNotEmpty() ? ' (Sesudah Adjustment)' : '' }}</div>
    <table class="data">
        <thead>
            <tr>
                <th>Titik ukur</th>
                <th>Pembacaan rata-rata</th>
                <th>Error</th>
                <th>Koreksi</th>
                <th>Ketidakpastian (U)</th>
                <th>k</th>
                <th>Hasil</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($titik as $t)
                <tr>
                    <td>{{ rtrim(rtrim(number_format($t->titik_ukur, 4, '.', ''), '0'), '.') }}</td>
                    <td>{{ rtrim(rtrim(number_format($t->rata_rata, 4, '.', ''), '0'), '.') }}</td>
                    <td>{{ number_format($t->error, 5, '.', '') }}</td>
                    <td>{{ number_format($t->koreksi, 5, '.', '') }}</td>
                    <td>&plusmn;{{ number_format($t->ketidakpastian_diperluas, 5, '.', '') }}</td>
                    <td>{{ rtrim(rtrim(number_format($t->faktor_cakupan_k, 2, '.', ''), '0'), '.') }}</td>
                    <td>{{ $t->keputusan }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="putusan {{ strtolower($sesi->keputusan ?? 'pass') }}">
        KEPUTUSAN: {{ $sesi->keputusan ?? '—' }}
    </div>

    @if ($standarDipakai->isNotEmpty())
        <div class="judul-sub">Standar Acuan yang Digunakan</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Nama</th><th>Merk / Model</th><th>No. Seri</th><th>Tertelusur ke</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($standarDipakai as $s)
                    <tr>
                        <td>{{ $s->nama }}</td>
                        <td>{{ trim(($s->merk ?? '').' '.($s->model ?? '')) ?: '—' }}</td>
                        <td>{{ $s->serial_number ?? '—' }}</td>
                        <td>{{ $s->tertelusur_ke ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="ttd">
        <tr>
            {{-- "Calculated by" pakai INISIAL (mis. NR); penanda tangan di
                 sebelah kanan pakai nama lengkap. Dua orang yang bisa beda. --}}
            <td>
                @if (! empty($dihitungOleh))
                    <div>Dihitung oleh</div>
                    <div class="garis"><strong>{{ $dihitungOleh }}</strong></div>
                @endif
            </td>
            <td>
                <div>{{ $sesi->organization->alamat ? \Illuminate\Support\Str::before($sesi->organization->alamat, ',') : '' }}, {{ $sertifikat->diterbitkan_pada?->translatedFormat('d F Y') }}</div>
                @if (! empty($ttdPenandaTangan))
                    {{-- Gambar TTD ditaruh DI ATAS garis, tingginya dikunci biar
                         file besar nggak ngedorong blok tanda tangan ke halaman
                         berikutnya. --}}
                    <div class="ttd-gambar"><img src="{{ $ttdPenandaTangan }}" alt="Tanda tangan"></div>
                @endif
                <div class="garis">
                    <strong>{{ $sesi->reviewer->name ?? '—' }}</strong><br>
                    {{ $sesi->reviewer->department ?? 'Technical Manager' }}
                </div>
            </td>
        </tr>
    </table>

    <div class="footer">
        <p>
            Ketidakpastian pengukuran dinyatakan pada tingkat kepercayaan ~95% dengan faktor cakupan k=2.
            Keputusan kesesuaian memakai aturan <em>guarded acceptance</em> (ILAC-G8): alat dinyatakan LAIK
            bila |error| + U masih dalam batas toleransi.
        </p>
        <p>Sertifikat ini tidak boleh digandakan sebagian tanpa izin tertulis dari laboratorium penerbit.</p>
        <p class="disclaimer">Calibration results are not to be announced and only apply to related tools.</p>
        <p>No. Dokumen Form: SIDIK-FM-CAL-2403_Rev.0</p>
        <div class="verify">
            Verifikasi keaslian: <code>{{ $sertifikat->qr_payload }}</code>
            (kode: <code>{{ $sertifikat->qr_token }}</code>)
        </div>
    </div>
</body>
</html>

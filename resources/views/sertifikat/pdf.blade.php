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
            <td></td>
            <td>
                <div>{{ $sesi->organization->alamat ? \Illuminate\Support\Str::before($sesi->organization->alamat, ',') : '' }}, {{ $sertifikat->diterbitkan_pada?->translatedFormat('d F Y') }}</div>
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

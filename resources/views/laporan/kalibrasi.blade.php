<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Kalibrasi</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 9px; color: #1a1a1a; margin: 0; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        .sub { font-size: 9px; color: #555; margin-bottom: 10px; }
        .filter { font-size: 8px; color: #555; margin-bottom: 8px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 3px 4px; text-align: left; }
        th { background: #f0f0f0; }
        td.c, th.c { text-align: center; }
        .ring { margin-top: 10px; font-size: 9px; }
        .ring span { display: inline-block; margin-right: 14px; }
        .pass { color: #146c2e; font-weight: bold; }
        .fail { color: #a01919; font-weight: bold; }
        .footer { margin-top: 12px; font-size: 8px; color: #666; }
    </style>
</head>
<body>
    <h1>{{ $organisasi->nama ?? 'Laboratorium Kalibrasi' }}</h1>
    <div class="sub">Laporan Kalibrasi &middot; dicetak {{ now()->translatedFormat('d F Y H:i') }}</div>

    @php
        $aktif = collect($filter)->filter(fn ($v) => $v !== null && $v !== '');
    @endphp
    @if ($aktif->isNotEmpty())
        <div class="filter">
            Filter:
            @foreach ($aktif as $kunci => $nilai)
                <strong>{{ str_replace('_', ' ', $kunci) }}</strong>: {{ $nilai }}@if (! $loop->last) &middot; @endif
            @endforeach
        </div>
    @endif

    <table>
        <thead>
            <tr>
                <th>No. Sesi</th>
                <th class="c">Tanggal</th>
                <th>Pelanggan</th>
                <th>Alat</th>
                <th>No. Seri</th>
                <th>Kategori</th>
                <th>Teknisi</th>
                <th class="c">Status</th>
                <th class="c">Hasil</th>
                <th>No. Sertifikat</th>
                <th class="c">Terbit</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($baris as $b)
                <tr>
                    <td>{{ $b['nomor_sesi'] ?? '—' }}</td>
                    <td class="c">{{ $b['tanggal_kalibrasi'] ?? '—' }}</td>
                    <td>{{ $b['pelanggan'] ?? '—' }}</td>
                    <td>{{ $b['alat'] ?? '—' }}</td>
                    <td>{{ $b['serial_number'] ?? '—' }}</td>
                    <td>{{ $b['kategori'] ?? '—' }}</td>
                    <td>{{ $b['teknisi'] ?? '—' }}</td>
                    <td class="c">{{ $b['status'] ?? '—' }}</td>
                    <td class="c {{ strtolower($b['keputusan'] ?? '') }}">{{ $b['keputusan'] ?? '—' }}</td>
                    <td>{{ $b['nomor_sertifikat'] ?? '—' }}</td>
                    <td class="c">{{ $b['diterbitkan_pada'] ?? '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="11" class="c">Nggak ada data yang cocok sama filternya.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="ring">
        <span>Total: <strong>{{ $ringkasan['total'] }}</strong></span>
        <span class="pass">PASS: {{ $ringkasan['pass'] }}</span>
        <span class="fail">FAIL: {{ $ringkasan['fail'] }}</span>
        <span>Disetujui: <strong>{{ $ringkasan['disetujui'] }}</strong></span>
        <span>Bersertifikat: <strong>{{ $ringkasan['bersertifikat'] }}</strong></span>
    </div>

    <div class="footer">
        Angka di laporan ini dirakit server dari data kalibrasi yang tersimpan — sumbernya sama
        dengan yang dipakai menerbitkan sertifikat.
    </div>
</body>
</html>

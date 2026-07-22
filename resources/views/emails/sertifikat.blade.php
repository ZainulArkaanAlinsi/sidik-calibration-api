<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Sertifikat Kalibrasi {{ $sertifikat->nomor }}</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #1a1a1a; line-height: 1.6;">
    <p>Yth. {{ $sesi?->equipment?->customer?->nama ?? 'Pelanggan' }},</p>

    <p>
        Bersama email ini kami lampirkan sertifikat kalibrasi untuk alat yang
        telah kami kalibrasi.
    </p>

    <table cellpadding="6" cellspacing="0" style="border-collapse: collapse; font-size: 13px;">
        <tr>
            <td style="color:#555;">Nomor Sertifikat</td>
            <td><strong>{{ $sertifikat->nomor }}</strong></td>
        </tr>
        <tr>
            <td style="color:#555;">Nama Alat</td>
            <td>{{ $sesi?->equipment?->nama_alat ?? '—' }}</td>
        </tr>
        <tr>
            <td style="color:#555;">Nomor Seri</td>
            <td>{{ $sesi?->equipment?->serial_number ?? '—' }}</td>
        </tr>
        <tr>
            <td style="color:#555;">Tanggal Kalibrasi</td>
            <td>{{ $sesi?->tanggal_kalibrasi?->translatedFormat('d F Y') ?? '—' }}</td>
        </tr>
        <tr>
            <td style="color:#555;">Berlaku Sampai</td>
            <td>{{ $sertifikat->berlaku_sampai?->translatedFormat('d F Y') ?? '—' }}</td>
        </tr>
    </table>

    @if ($sertifikat->qr_payload)
        <p style="font-size: 13px;">
            Keaslian sertifikat ini dapat diperiksa di:<br>
            <a href="{{ $sertifikat->qr_payload }}">{{ $sertifikat->qr_payload }}</a>
        </p>
    @endif

    <p style="font-size: 13px; color: #555;">
        Hasil kalibrasi hanya berlaku untuk alat yang tercantum di dalam sertifikat.
    </p>

    <p>
        Hormat kami,<br>
        <strong>{{ $organisasi->nama ?? 'Laboratorium Kalibrasi' }}</strong>
        @if ($organisasi?->no_akreditasi)
            <br><span style="font-size: 12px; color: #555;">No. Akreditasi {{ $organisasi->no_akreditasi }}</span>
        @endif
    </p>
</body>
</html>

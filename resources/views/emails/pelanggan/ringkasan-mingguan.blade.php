{{-- Gaya inline, alasannya sama dengan emails/pelanggan/otp.blade.php:
     klien email membuang <style> dan kelas CSS. --}}
@php($tgl = fn (?string $iso) => $iso ? \Illuminate\Support\Carbon::parse($iso)->locale('id')->translatedFormat('j M Y') : '—')
<div style="font-family: Arial, Helvetica, sans-serif; color: #1f2933; line-height: 1.6; max-width: 600px;">
    <p>Halo {{ $nama }},</p>
    <p>Ringkasan kalibrasi <strong>{{ $perusahaan }}</strong> minggu ini:</p>

    <table style="border-collapse: collapse; margin: 8px 0 16px;">
        <tr>
            <td style="padding: 4px 16px 4px 0; color: #616e7c;">Paket berjalan</td>
            <td style="padding: 4px 0;"><strong>{{ $isi['paket_berjalan'] }}</strong></td>
        </tr>
        <tr>
            <td style="padding: 4px 16px 4px 0; color: #616e7c;">Permintaan aktif</td>
            <td style="padding: 4px 0;"><strong>{{ $isi['permintaan_aktif'] }}</strong></td>
        </tr>
    </table>

    @if ($isi['jatuh_tempo'] !== [])
        <h3 style="font-size: 16px; margin: 20px 0 8px;">Alat yang perlu dikalibrasi ulang</h3>
        <table style="border-collapse: collapse; width: 100%;">
            @foreach ($isi['jatuh_tempo'] as $alat)
                <tr style="border-bottom: 1px solid #e4e7eb;">
                    <td style="padding: 6px 8px 6px 0;">{{ $alat['nama'] }}<br>
                        <span style="color: #616e7c; font-size: 13px;">{{ $alat['serial'] }}</span></td>
                    <td style="padding: 6px 0; text-align: right; white-space: nowrap;
                        color: {{ $alat['hari'] < 0 ? '#a81b33' : '#1f2933' }};">
                        {{ $tgl($alat['tanggal']) }}<br>
                        <span style="font-size: 13px;">{{ $alat['hari'] < 0 ? 'lewat '.abs($alat['hari']).' hari' : ($alat['hari'] === 0 ? 'hari ini' : $alat['hari'].' hari lagi') }}</span>
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($isi['sertifikat_baru'] !== [])
        <h3 style="font-size: 16px; margin: 20px 0 8px;">Sertifikat terbit 7 hari terakhir</h3>
        <table style="border-collapse: collapse; width: 100%;">
            @foreach ($isi['sertifikat_baru'] as $s)
                <tr style="border-bottom: 1px solid #e4e7eb;">
                    <td style="padding: 6px 8px 6px 0; font-family: 'Courier New', monospace;">{{ $s['nomor'] }}</td>
                    <td style="padding: 6px 8px 6px 0;">{{ $s['alat'] }}</td>
                    <td style="padding: 6px 0; text-align: right; white-space: nowrap;">{{ $tgl($s['tanggal']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <p style="margin-top: 24px;">Rinciannya ada di aplikasi SIDIK Pelanggan.</p>
    <p style="color: #616e7c; font-size: 13px;">
        Anda menerima email ini karena "Ringkasan email mingguan" menyala di Preferensi aplikasi.
        Matikan di sana kalau tidak ingin menerimanya lagi.
    </p>
</div>

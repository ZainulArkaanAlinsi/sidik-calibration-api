{{-- Gaya inline, alasannya sama dengan emails/pelanggan/otp.blade.php. --}}
<div style="font-family: Arial, Helvetica, sans-serif; color: #1f2933; line-height: 1.6;">
    <p>Ada permintaan hapus akun lewat halaman web SIDIK Pelanggan.</p>

    <table style="border-collapse: collapse; margin: 16px 0;">
        <tr>
            <td style="padding: 4px 16px 4px 0; color: #616e7c;">Email</td>
            <td style="padding: 4px 0;"><strong>{{ $email }}</strong></td>
        </tr>
        <tr>
            <td style="padding: 4px 16px 4px 0; color: #616e7c;">ID pengguna</td>
            <td style="padding: 4px 0;">{{ $userId }}</td>
        </tr>
        @if ($alasan)
            <tr>
                <td style="padding: 4px 16px 4px 0; color: #616e7c; vertical-align: top;">Alasan</td>
                <td style="padding: 4px 0;">{{ $alasan }}</td>
            </tr>
        @endif
    </table>

    <p>
        Diproses paling lambat <strong>7 hari</strong> sejak email ini
        (REQ-PRV-03). Data perusahaan, alat, permintaan, dan sertifikat
        <strong>tidak</strong> ikut dihapus &mdash; yang dianonimkan hanya data
        pribadi orangnya.
    </p>
</div>

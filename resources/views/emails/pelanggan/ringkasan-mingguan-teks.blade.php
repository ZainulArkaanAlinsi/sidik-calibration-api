Halo {{ $nama }},

Ringkasan kalibrasi {{ $perusahaan }} minggu ini:

Paket berjalan   : {{ $isi['paket_berjalan'] }}
Permintaan aktif : {{ $isi['permintaan_aktif'] }}
@if ($isi['jatuh_tempo'] !== [])

Alat yang perlu dikalibrasi ulang:
@foreach ($isi['jatuh_tempo'] as $alat)
- {{ $alat['nama'] }} ({{ $alat['serial'] }}) - {{ $alat['tanggal'] }}, {{ $alat['hari'] < 0 ? 'lewat '.abs($alat['hari']).' hari' : ($alat['hari'] === 0 ? 'hari ini' : $alat['hari'].' hari lagi') }}
@endforeach
@endif
@if ($isi['sertifikat_baru'] !== [])

Sertifikat terbit 7 hari terakhir:
@foreach ($isi['sertifikat_baru'] as $s)
- {{ $s['nomor'] }} - {{ $s['alat'] }} ({{ $s['tanggal'] }})
@endforeach
@endif

Rinciannya ada di aplikasi SIDIK Pelanggan.

Anda menerima email ini karena "Ringkasan email mingguan" menyala di Preferensi
aplikasi. Matikan di sana kalau tidak ingin menerimanya lagi.

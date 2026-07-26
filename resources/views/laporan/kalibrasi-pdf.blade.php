{{--
  Laporan kalibrasi berpenyaring (fase-2 §5), versi PDF.

  Nol hitungan di sini. Semua angka datang dari LaporanKalibrasi — sumber yang
  SAMA dengan versi Excel dan dengan laporan di layar. Begitu template ikut
  ngitung, tiga keluaran bisa mulai beda buat periode yang sama.
--}}
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Laporan Kalibrasi</title>
    <style>
        @page { margin: 18mm 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #111; }
        h1 { font-size: 14px; margin: 0 0 2px; }
        .sub { color: #555; margin-bottom: 10px; }

        table { width: 100%; border-collapse: collapse; }
        .meta td { padding: 1.5px 0; vertical-align: top; }
        .meta td.label { width: 110px; color: #555; }

        .ringkas { margin: 8px 0 12px; }
        .ringkas td { border: 0.5px solid #bbb; padding: 4px 6px; text-align: center; }
        .ringkas td.angka { font-weight: bold; font-size: 11px; }

        .data th, .data td { border: 0.5px solid #999; padding: 3px 4px; }
        .data th { background: #e7e7e7; font-weight: bold; text-align: left; }
        .data td.angka { text-align: right; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }

        .kaki { margin-top: 10px; color: #666; font-size: 7.5px; }
    </style>
</head>
<body>
    <h1>LAPORAN KALIBRASI</h1>
    <div class="sub">{{ $organisasi?->nama }}@if ($organisasi?->no_akreditasi) · {{ $organisasi->no_akreditasi }}@endif</div>

    {{-- Penyaring ikut dicetak: file yang udah nyebar harus bisa dijelasin
         isinya periode/pelanggan mana. Itu pertanyaan pertama asesor. --}}
    <table class="meta">
        @foreach ($penyaring as $label => $nilai)
            <tr>
                <td class="label">{{ $label }}</td>
                <td>: {{ $nilai ?: 'Semua' }}</td>
            </tr>
        @endforeach
        <tr>
            <td class="label">Dibuat pada</td>
            <td>: {{ now()->toDateTimeString() }}</td>
        </tr>
    </table>

    <table class="ringkas">
        <tr>
            <td>Total sesi</td>
            <td>PASS</td>
            <td>FAIL</td>
            <td>Belum ada keputusan</td>
            <td>Disetujui</td>
        </tr>
        <tr>
            <td class="angka">{{ $ringkasan['total'] }}</td>
            <td class="angka">{{ $ringkasan['pass'] }}</td>
            <td class="angka">{{ $ringkasan['fail'] }}</td>
            <td class="angka">{{ $ringkasan['belum_ada_keputusan'] }}</td>
            <td class="angka">{{ $ringkasan['disetujui'] }}</td>
        </tr>
    </table>

    @if ($baris === [])
        <p>Nggak ada sesi kalibrasi yang cocok sama penyaring itu.</p>
    @else
        <table class="data">
            <thead>
                <tr>
                    @foreach ($judulKolom as $judul)
                        <th>{{ $judul }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($baris as $b)
                    <tr>
                        <td>{{ $b['nomor_sesi'] }}</td>
                        <td>{{ $b['tanggal_kalibrasi'] }}</td>
                        <td>{{ $b['pelanggan'] }}</td>
                        <td>{{ $b['nama_alat'] }}</td>
                        <td>{{ $b['serial_number'] }}</td>
                        <td>{{ $b['kategori'] }}</td>
                        <td>{{ $b['teknisi'] }}</td>
                        <td>{{ $b['status'] }}</td>
                        <td>{{ $b['keputusan'] }}</td>
                        <td class="angka">{{ $b['ketidakpastian_diperluas'] }}</td>
                        <td>{{ $b['nomor_sertifikat'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="kaki">
        Angka U95% diambil dari titik penentu tiap sesi — titik yang paling mepet
        batas toleransi, sama kayak yang dipajang di detail sesi.
    </div>
</body>
</html>

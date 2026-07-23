{{--
    Sertifikat kalibrasi — LAYOUT BAKU (spesifikasi poin 9).

    Isinya diambil BULAT-BULAT dari `certificates.snapshot`, bukan dari relasi
    model. Snapshot dibekukan waktu terbit, jadi cetak ulang tahun depan tetap
    keluar angka & alamat yang sama persis kayak salinan yang dipegang pelanggan.

    Empat bagian, nggak boleh ditambah: header informasi, tabel hasil kalibrasi
    (+ dua catatan baku), tabel standar yang dipakai, footer. Kalau ada
    permintaan nambah kolom, yang diubah spesifikasinya dulu — bukan file ini.
--}}
@php
    $header = $snapshot['header'] ?? [];
    $footer = $snapshot['footer'] ?? [];
    $meta = $snapshot['meta'] ?? [];
    $organisasi = $meta['organization'] ?? [];
    $desimal = $snapshot['desimal'] ?? \App\Support\Angka::DESIMAL_DEFAULT;

    $tgl = fn (?string $iso): string => $iso
        ? \Illuminate\Support\Carbon::parse($iso)->translatedFormat('d F Y')
        : '—';
    $angka = fn ($nilai): string => \App\Support\Angka::id($nilai === null ? null : (float) $nilai, $desimal);
    $isi = fn (?string $nilai): string => filled($nilai) ? $nilai : '—';
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Sertifikat {{ $header['certificate_number'] ?? $sertifikat->nomor }}</title>
    <style>
        * { font-family: DejaVu Sans, sans-serif; }
        body { font-size: 10.5px; color: #1a1a1a; margin: 0; }

        .kop { border-bottom: 3px double #333; padding-bottom: 10px; margin-bottom: 14px; }
        .kop table { width: 100%; border-collapse: collapse; }
        .kop td.logo { width: 84px; vertical-align: middle; }
        .kop td.logo img { width: 74px; height: auto; }
        .kop td.teks { vertical-align: middle; }
        .kop h1 { font-size: 16px; margin: 0 0 2px; }
        .kop .akr { font-size: 9.5px; color: #555; }

        .judul { text-align: center; font-size: 15px; font-weight: bold; letter-spacing: 1px; margin: 2px 0 12px; }

        table.info { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        table.info td { padding: 2.5px 6px; vertical-align: top; }
        table.info td.lbl { width: 17%; color: #555; }
        table.info td.val { width: 33%; }

        .judul-sub { font-size: 11px; font-weight: bold; margin: 12px 0 4px; letter-spacing: .5px; }
        table.data { width: 100%; border-collapse: collapse; font-size: 10px; }
        table.data th, table.data td { border: 1px solid #999; padding: 4px 6px; text-align: center; }
        table.data th { background: #efefef; }
        table.data td.kiri { text-align: left; }

        .catatan { font-size: 9px; font-style: italic; color: #444; margin-top: 6px; }
        .catatan div { margin-bottom: 2px; }

        .putusan { text-align: center; font-size: 13px; font-weight: bold; padding: 6px; margin: 10px 0; border: 2px solid; }
        .pass { color: #146c2e; border-color: #146c2e; }
        .fail { color: #a01919; border-color: #a01919; }

        table.ttd { width: 100%; border-collapse: collapse; margin-top: 22px; }
        table.ttd td { vertical-align: top; font-size: 10px; }
        table.ttd td.qr { width: 110px; text-align: center; }
        table.ttd td.qr img { width: 92px; height: 92px; }
        table.ttd td.qr .ket { font-size: 7.5px; color: #666; margin-top: 2px; }
        .ttd .garis { margin-top: 44px; border-top: 1px solid #333; padding-top: 3px; }

        .kode-dokumen { font-size: 8.5px; color: #666; margin-top: 18px; border-top: 1px solid #ccc; padding-top: 5px; }
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
                    <h1>{{ $organisasi['nama'] ?? 'Laboratorium Kalibrasi' }}</h1>
                    <div class="akr">
                        Terakreditasi {{ $organisasi['standar_akreditasi'] ?? 'KAN' }}
                        &middot; No. {{ $organisasi['no_akreditasi'] ?? '—' }}
                        @if (! empty($organisasi['alamat'])) <br>{{ $organisasi['alamat'] }} @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <div class="judul">CALIBRATION CERTIFICATE</div>

    {{-- Header informasi: 16 field, urutannya dikunci spesifikasi. --}}
    <table class="info">
        <tr>
            <td class="lbl">Certificate Number</td><td class="val">{{ $isi($header['certificate_number'] ?? null) }}</td>
            <td class="lbl">Page</td><td class="val">{{ $isi($header['page'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="lbl">Owner</td><td class="val">{{ $isi($header['owner'] ?? null) }}</td>
            <td class="lbl">Order Number</td><td class="val">{{ $isi($header['order_number'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="lbl">Address</td><td class="val">{{ $isi($header['address'] ?? null) }}</td>
            <td class="lbl">Received Date</td><td class="val">{{ $tgl($header['received_date'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="lbl">Equipment Name</td><td class="val">{{ $isi($header['equipment_name'] ?? null) }}</td>
            <td class="lbl">Manufacturer</td><td class="val">{{ $isi($header['manufacturer'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="lbl">Calibration Location</td><td class="val">{{ $isi($header['calibration_location'] ?? null) }}</td>
            <td class="lbl">Model/Type</td><td class="val">{{ $isi($header['model_type'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="lbl">Calibration Date</td><td class="val">{{ $tgl($header['calibration_date'] ?? null) }}</td>
            <td class="lbl">Serial Number</td><td class="val">{{ $isi($header['serial_number'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="lbl">Calibration Method</td><td class="val">{{ $isi($header['calibration_method'] ?? null) }}</td>
            <td class="lbl">Capacity/Graduation</td><td class="val">{{ $isi($header['capacity_graduation'] ?? null) }}</td>
        </tr>
        <tr>
            <td class="lbl">Env. Condition</td><td class="val">{{ $isi($header['env_condition'] ?? null) }}</td>
            <td class="lbl">Technician ID</td><td class="val">{{ $isi($header['technician_id'] ?? null) }}</td>
        </tr>
    </table>

    <div class="judul-sub">CALIBRATION REPORT</div>
    <table class="data">
        <thead>
            <tr>
                <th>Standard Value</th>
                <th>Unit Under Test</th>
                <th>Correction</th>
                <th>U95% (&plusmn;)</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($snapshot['hasil'] ?? [] as $baris)
                <tr>
                    <td>{{ $angka($baris['standard_value'] ?? null) }}</td>
                    <td>{{ $angka($baris['unit_under_test'] ?? null) }}</td>
                    <td>{{ $angka($baris['correction'] ?? null) }}</td>
                    <td>{{ $angka($baris['u95'] ?? null) }}</td>
                </tr>
            @empty
                <tr><td colspan="4">—</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="catatan">
        @foreach ($snapshot['catatan'] ?? [] as $catatan)
            <div>{{ $catatan }}</div>
        @endforeach
    </div>

    {{-- Keputusan PASS/FAIL bukan bagian struktur baku — cuma muncul kalau lab
         sengaja nyalain lewat pengaturan organisasi. --}}
    @if (! empty($keputusan))
        <div class="putusan {{ strtolower($keputusan) }}">KEPUTUSAN: {{ $keputusan }}</div>
    @endif

    <div class="judul-sub">STANDARD USED</div>
    <table class="data">
        <thead>
            <tr>
                <th>Name</th>
                <th>Merk/Type</th>
                <th>Serial Number</th>
                <th>Traceable to SI through</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($snapshot['standar_digunakan'] ?? [] as $standar)
                <tr>
                    <td class="kiri">{{ $isi($standar['name'] ?? null) }}</td>
                    <td>{{ $isi($standar['merk_type'] ?? null) }}</td>
                    <td>{{ $isi($standar['serial_number'] ?? null) }}</td>
                    <td>{{ $isi($standar['traceable_to'] ?? null) }}</td>
                </tr>
            @empty
                <tr><td colspan="4">—</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="ttd">
        <tr>
            @if (! empty($qr))
                <td class="qr">
                    <img src="{{ $qr }}" alt="QR verifikasi">
                    <div class="ket">Scan untuk verifikasi &amp; unduh</div>
                </td>
            @endif
            <td></td>
            <td style="width: 38%;">
                <div>Issuance Date: {{ $tgl($footer['issuance_date'] ?? null) }}</div>
                <div class="garis">
                    <strong>{{ $isi($footer['penandatangan'] ?? null) }}</strong><br>
                    {{ $isi($footer['jabatan'] ?? null) }}
                </div>
            </td>
        </tr>
    </table>

    <div class="kode-dokumen">{{ $isi($footer['kode_dokumen'] ?? null) }}</div>
</body>
</html>

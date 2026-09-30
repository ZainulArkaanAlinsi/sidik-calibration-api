<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Halaman ini dibuka orang luar yang memindai QR dengan kamera HP dan
         memuat nama pelanggan. Jangan sampai terindeks mesin pencari. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('judul') — {{ $organization?->nama ?? config('app.name') }}</title>
    {{--
      Berdiri sendiri, BUKAN memperluas `layouts/publik`: beranda & halaman
      hapus-akun memakai layout itu, dan gaya verifikasi (artboard Web_Verifikasi
      & Web_Verifikasi_HP) tidak boleh ikut mengubah mereka.

      Tanpa font luar: halaman publik tidak boleh memanggil pihak ketiga waktu
      dibuka dari QR di kertas sertifikat. Angka & nomor memakai mono sistem.
    --}}
    <style>
        :root {
            --meja: #d6d2c8;
            --kertas: #f5f1e7;
            --kertas-tepi: #d9d2c0;
            --garis: #c9d5e2;
            --tinta: #1a1f26;
            --tinta-2: #4a5059;
            --biru: #1d4292;
            --lulus: #125739;
            --lulus-tipis: #dcebe0;
            --gagal: #a81b33;
            --gagal-tipis: #f6dee2;
            --sans: "Instrument Sans", "IBM Plex Sans", "Segoe UI", system-ui, -apple-system, sans-serif;
            --mono: "JetBrains Mono", "IBM Plex Mono", ui-monospace, Menlo, Consolas, monospace;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --meja: #0f1114;
                --kertas: #24272d;
                --kertas-tepi: #33373e;
                --garis: #39414b;
                --tinta: #eae6dc;
                --tinta-2: #a6a9b1;
                --biru: #8fb0ff;
                --lulus: #5dce93;
                --lulus-tipis: #16301f;
                --gagal: #ff8092;
                --gagal-tipis: #3a1820;
            }
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--meja);
            color: var(--tinta);
            font-family: var(--sans);
            font-size: 15px;
            line-height: 1.5;
            -webkit-text-size-adjust: 100%;
        }

        .bungkus {
            max-width: 560px;
            margin: 0 auto;
            padding: 0 16px 40px;
        }

        /* Kepala: ubin perisai + nama lab + sublabel. */
        .kepala {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 0;
        }

        .ubin {
            flex: 0 0 auto;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: grid;
            place-items: center;
            color: #fff;
            background: var(--biru);
        }

        @media (prefers-color-scheme: dark) {
            .ubin {
                color: #0f1114;
            }
        }

        .kepala h1 {
            margin: 0;
            font-size: 15px;
            line-height: 20px;
            font-weight: 700;
        }

        .sub {
            font-size: 13px;
            color: var(--tinta-2);
        }

        .kartu {
            background: var(--kertas);
            border: 1px solid var(--kertas-tepi);
            border-radius: 6px;
            margin-bottom: 10px;
        }

        .kartu-status {
            display: flex;
            gap: 12px;
            align-items: flex-start;
            padding: 14px 16px;
            border-color: color-mix(in srgb, var(--lulus) 45%, var(--kertas-tepi));
        }

        .kartu-status.kedaluwarsa {
            border-color: color-mix(in srgb, var(--gagal) 45%, var(--kertas-tepi));
        }

        .ubin-status {
            flex: 0 0 auto;
            width: 44px;
            height: 44px;
            border-radius: 8px;
            display: grid;
            place-items: center;
            color: var(--lulus);
            background: var(--lulus-tipis);
        }

        .kedaluwarsa .ubin-status {
            color: var(--gagal);
            background: var(--gagal-tipis);
        }

        .label {
            display: block;
            font-size: 12px;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: var(--tinta-2);
        }

        .status {
            margin: 0;
            font-size: 26px;
            line-height: 32px;
            font-weight: 700;
            color: var(--lulus);
        }

        .kedaluwarsa .status {
            color: var(--gagal);
        }

        .kecil {
            margin: 0;
            font-size: 13px;
            color: var(--tinta-2);
        }

        .nomor-blok {
            padding: 12px 14px 10px;
        }

        .nomor-baris {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            flex-wrap: wrap;
        }

        .nomor {
            font-family: var(--mono);
            font-size: 20px;
            line-height: 28px;
            font-weight: 600;
            /* Dipilih sekali ketuk: orang menyalin nomornya untuk mencocokkan. */
            user-select: all;
            -webkit-user-select: all;
            overflow-wrap: anywhere;
        }

        .lencana {
            display: inline-block;
            padding: 2px 10px;
            border-radius: 3px;
            font-size: 13px;
            font-weight: 700;
            border: 1px solid currentColor;
        }

        .lencana-pass {
            color: var(--lulus);
            background: var(--lulus-tipis);
        }

        .lencana-fail {
            color: var(--gagal);
            background: var(--gagal-tipis);
        }

        dl {
            margin: 0;
            border-top: 1px solid var(--garis);
        }

        .baris {
            display: flex;
            gap: 12px;
            padding: 8px 14px;
            border-bottom: 1px solid var(--garis);
            align-items: flex-start;
        }

        .baris:last-child {
            border-bottom: 0;
        }

        dt {
            flex: 0 0 112px;
            font-size: 13px;
            color: var(--tinta-2);
            padding-top: 1px;
        }

        dd {
            margin: 0;
            flex: 1 1 auto;
            min-width: 0;
            font-weight: 500;
            overflow-wrap: anywhere;
        }

        dd.mono {
            font-family: var(--mono);
            font-size: 14px;
        }

        .catatan {
            font-size: 12px;
            color: var(--tinta-2);
            padding: 12px 14px;
        }

        .catatan p {
            margin: 0 0 8px;
        }

        .catatan p:last-child {
            margin-bottom: 0;
        }

        .lab {
            padding: 10px 14px 12px;
            border-top: 1px solid var(--garis);
            font-size: 13px;
            color: var(--tinta);
        }

        .petunjuk {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            font-size: 13px;
            color: var(--tinta-2);
            margin: 4px 0 0;
        }

        footer {
            text-align: center;
            font-size: 12px;
            color: var(--tinta-2);
            margin-top: 24px;
        }

        @media (max-width: 380px) {
            .baris {
                flex-direction: column;
                gap: 0;
            }

            dt {
                flex-basis: auto;
            }
        }
    </style>
</head>

<body>
    <div class="bungkus">
        <header class="kepala">
            <span class="ubin" aria-hidden="true">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                    stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 3l7.5 3v5.5c0 4.5-3.2 8.3-7.5 9.5-4.3-1.2-7.5-5-7.5-9.5V6z" />
                    <path d="M9 12l2 2 4-4" />
                </svg>
            </span>
            <div>
                <h1>{{ $organization?->nama ?? config('app.name') }}</h1>
                <div class="sub">@yield('subjudul', 'Verifikasi sertifikat')</div>
            </div>
        </header>

        @yield('isi')

        <footer>{{ $organization?->nama ?? config('app.name') }}</footer>
    </div>
</body>

</html>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Halaman ini dibuka orang luar yang scan QR pakai kamera HP, jadi
         jangan sampai ke-index mesin pencari. --}}
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('judul') — {{ $organization?->nama ?? config('app.name') }}</title>
    <style>
        :root {
            --teks: #0e2a33;
            --teks-redup: #5b7480;
            --garis: #dbe4e8;
            --latar: #f4f7f8;
            --kartu: #ffffff;
            --aksen: #0e5c68;
            --pass: #1b7a3d;
            --fail: #b3261e;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --teks: #e8f0f2;
                --teks-redup: #94a9b2;
                --garis: #24383f;
                --latar: #0d1619;
                --kartu: #14232833;
                --aksen: #4fb3c4;
                --pass: #4ade80;
                --fail: #f87171;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 24px 16px 48px;
            background: var(--latar);
            color: var(--teks);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            line-height: 1.55;
        }

        .bungkus { max-width: 640px; margin: 0 auto; }

        .kartu {
            background: var(--kartu);
            border: 1px solid var(--garis);
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 16px;
        }

        .lab-head { display: flex; align-items: center; gap: 14px; margin-bottom: 24px; }
        .lab-logo {
            flex: 0 0 auto;
            height: 58px;
            width: auto;
            background: #fff;
            border-radius: 8px;
            padding: 4px;
        }
        .lab { font-size: 13px; color: var(--teks-redup); }
        .lab strong { display: block; color: var(--teks); font-size: 15px; }

        h1 { font-size: 20px; margin: 0 0 4px; }

        .lencana {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 600;
            border: 1px solid currentColor;
        }
        .lencana-pass { color: var(--pass); }
        .lencana-fail { color: var(--fail); }

        dl { margin: 0; display: grid; grid-template-columns: 1fr; gap: 14px; }
        @media (min-width: 480px) { dl { grid-template-columns: 1fr 1fr; } }
        dt { font-size: 12px; color: var(--teks-redup); text-transform: uppercase; letter-spacing: .04em; }
        dd { margin: 2px 0 0; font-weight: 600; }

        .catatan { font-size: 12px; color: var(--teks-redup); }
        .catatan p { margin: 0 0 8px; }
        footer { text-align: center; font-size: 12px; color: var(--teks-redup); margin-top: 24px; }
    </style>
</head>
<body>
    <div class="bungkus">
        <div class="lab-head">
            <img class="lab-logo" src="{{ asset('images/logo-sidik.png') }}"
                 alt="Logo {{ $organization?->nama ?? config('app.name') }}">
            @if ($organization)
                <div class="lab">
                    <strong>{{ $organization->nama }}</strong>
                    {{ $organization->alamat }}<br>
                    @if ($organization->no_akreditasi)
                        Terakreditasi KAN No. {{ $organization->no_akreditasi }}
                        @if ($organization->standar_akreditasi) — {{ $organization->standar_akreditasi }} @endif
                    @endif
                </div>
            @endif
        </div>

        @yield('isi')

        <footer>{{ $organization?->nama ?? config('app.name') }}</footer>
    </div>
</body>
</html>

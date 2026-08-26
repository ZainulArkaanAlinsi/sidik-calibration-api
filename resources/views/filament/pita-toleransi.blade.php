{{--
    Pita toleransi — di mana penyimpangan satu titik jatuh terhadap batas yang
    diizinkan.

    ## Kenapa perlu bentuk, bukan cuma angka

    Tabel per titik sudah memuat `error` dan `toleransi`, dan sampai sekarang
    admin yang approve harus membandingkan keduanya di kepala, baris demi baris.
    Untuk sesi 9 titik seperti TITS itu sembilan perbandingan manual, dan yang
    dicari sebetulnya cuma satu hal: ada yang mepet batas nggak.

    Pita ini menjawab itu sekilas. Angkanya TETAP ada di kolom sebelahnya —
    ini menambah cara baca, bukan mengganti.

    ## Yang digambar

    Trek penuh = rentang -toleransi..+toleransi. Zona hijau = daerah aman
    (seluruh trek, karena trek memang batasnya). Penanda = posisi `error`.

    Kalau |error| > toleransi penandanya dijepit ke ujung DAN diberi warna
    bahaya — jadi titik yang jauh di luar tetap kelihatan sebagai "di luar",
    bukan hilang dari kanvas.

    ## Yang sengaja nggak digambar

    Titik tanpa toleransi (`toleransi` null) nggak dapat pita sama sekali,
    cuma tanda strip. Enam dari tujuh belas lembar memang NGGAK divonis
    PASS/FAIL — Autoklaf, DO Meter, Gas Detector, TITS, TIDS, dan kelima
    Enclosure sertifikatnya berhenti di baris `Uncertainty 95%`. Menggambar
    pita kosong buat mereka bikin seolah-olah ada batas yang mereka lewati.
--}}

@php
    $toleransi = $getRecord()->toleransi === null ? null : abs((float) $getRecord()->toleransi);
    $error = $getRecord()->error === null ? null : (float) $getRecord()->error;

    $adaPita = $toleransi !== null && $toleransi > 0.0 && $error !== null;

    if ($adaPita) {
        $rasio = $error / $toleransi;              // -1..+1 kalau di dalam batas
        $diLuar = abs($rasio) > 1.0;
        $persen = (max(-1.0, min(1.0, $rasio)) + 1.0) / 2.0 * 100.0;
    }
@endphp

@if (! $adaPita)
    <span class="text-sm text-gray-400 dark:text-gray-500" title="Titik ini nggak punya batas keberterimaan">&mdash;</span>
@else
    <div
        class="relative flex h-5 min-w-32 items-center"
        title="{{ $diLuar ? 'Di luar batas' : 'Di dalam batas' }}: {{ number_format($error, 4) }} dari ± {{ number_format($toleransi, 4) }}"
    >
        {{-- Trek: seluruh rentang yang diizinkan. --}}
        <div class="absolute inset-x-0 top-2 h-1 rounded-full bg-gray-200 dark:bg-gray-700"></div>

        {{-- Zona aman. Sedikit disisipkan dari ujung supaya batasnya kelihatan
             sebagai batas, bukan sebagai tepi kanvas. --}}
        <div class="absolute top-2 left-[8%] right-[8%] h-1 rounded-full bg-success-500/35"></div>

        {{-- Garis tengah = nol penyimpangan. --}}
        <div class="absolute top-[3px] left-1/2 h-3.5 w-px -translate-x-1/2 bg-gray-300 dark:bg-gray-600"></div>

        {{-- Penanda posisi error. --}}
        <div
            class="absolute top-[2px] h-4 w-[3px] -translate-x-1/2 rounded-sm {{ $diLuar ? 'bg-danger-600' : 'bg-gray-900 dark:bg-gray-100' }}"
            style="left: {{ number_format($persen, 2, '.', '') }}%"
        ></div>
    </div>
@endif

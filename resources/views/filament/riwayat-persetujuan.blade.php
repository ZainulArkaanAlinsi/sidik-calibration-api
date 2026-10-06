{{-- Riwayat persetujuan sesi (baca saja) — App\Support\RiwayatPersetujuanSesi. --}}
@php
    $label = [
        'diajukan' => 'Diajukan',
        'diajukan_ulang' => 'Diajukan ulang sesudah revisi',
        'ditolak' => 'Ditolak — perlu revisi',
        'menunggu_pengesahan' => 'Menunggu pengesahan',
        'disetujui' => 'Disetujui',
        'kembali_ke_draft' => 'Kembali ke draft',
    ];
    $jumlahTolak = collect($riwayat)->where('jenis', 'ditolak')->count();
@endphp

<div class="space-y-3">
    <p class="text-sm text-gray-600 dark:text-gray-400">
        Ditolak {{ $jumlahTolak }} kali. Sumber: jejak audit sesi — alasan lama tidak hilang walau kolom catatan sesi ditimpa.
    </p>

    @forelse ($riwayat as $p)
        <div class="rounded-lg border p-3 {{ $p['jenis'] === 'ditolak' ? 'border-danger-300 bg-danger-50 dark:bg-danger-950/30' : 'border-gray-200 dark:border-gray-700' }}">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <span class="font-semibold">{{ $label[$p['jenis']] ?? $p['jenis'] }}</span>
                <span class="text-xs text-gray-500">
                    {{ $p['waktu'] ? \Illuminate\Support\Carbon::parse($p['waktu'])->timezone('Asia/Jakarta')->format('d M Y H:i') : '—' }}
                    · {{ $p['oleh']['nama'] ?? 'sistem' }}
                </span>
            </div>

            @if ($p['jenis'] === 'ditolak')
                <p class="mt-2 whitespace-pre-line text-sm">{{ $p['alasan'] ?? '(tanpa alasan tercatat)' }}</p>
                @if (! empty($p['kolom']))
                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                        Kolom/sel ditandai: {{ implode(', ', $p['kolom']) }}
                    </p>
                @endif
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500">Belum ada riwayat persetujuan untuk sesi ini.</p>
    @endforelse
</div>

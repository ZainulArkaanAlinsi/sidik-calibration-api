{{--
    Import Excel dua langkah (spesifikasi poin 12C).

    Tombol "Terapkan" sengaja baru muncul SESUDAH uji coba jalan, dan mati
    kalau nggak ada yang bakal berubah — nggak ada jalan pintas dari "pilih
    file" ke "tulis ke master data".
--}}
<x-filament-panels::page>
    <form wire:submit.prevent="ujiCoba">
        {{ $this->form }}

        <div class="mt-4 flex flex-wrap gap-3">
            <x-filament::button type="submit" icon="heroicon-o-clipboard-document-check" color="gray">
                Uji coba
            </x-filament::button>

            @if ($hasil !== null && ($hasil['uji_coba'] ?? false))
                @php
                    $r = $hasil['ringkasan'];
                    $adaPerubahan = ($r['dibuat'] ?? 0) > 0 || ($r['diperbarui'] ?? 0) > 0;
                @endphp

                <x-filament::button
                    wire:click="terapkan"
                    icon="heroicon-o-check"
                    :disabled="! $adaPerubahan"
                >
                    Terapkan sekarang
                </x-filament::button>

                @unless ($adaPerubahan)
                    <span class="self-center text-sm text-gray-500">
                        Nggak ada yang berubah — nggak perlu diterapkan.
                    </span>
                @endunless
            @endif
        </div>
    </form>

    @if ($hasil !== null)
        @php $r = $hasil['ringkasan']; @endphp

        <x-filament::section class="mt-6">
            <x-slot name="heading">
                Ringkasan
                @if ($hasil['uji_coba'] ?? false)
                    <span class="text-warning-600">(uji coba — belum disimpan)</span>
                @endif
            </x-slot>

            <div class="flex flex-wrap gap-4 text-sm mb-4">
                <span>{{ $r['dibaca'] ?? 0 }} dibaca</span>
                <span class="text-success-600">{{ $r['dibuat'] ?? 0 }} dibuat</span>
                <span class="text-info-600">{{ $r['diperbarui'] ?? 0 }} diperbarui</span>
                <span class="text-warning-600">{{ $r['dilewati'] ?? 0 }} dilewati</span>
            </div>

            @if (! empty($hasil['kolom_diabaikan']))
                {{-- Kolom asing nggak bikin import gagal, tapi kalau nggak
                     dikasihtau, header yang salah ketik hilang diam-diam. --}}
                <p class="text-warning-600 text-sm mb-3">
                    Kolom diabaikan: {{ implode(', ', $hasil['kolom_diabaikan']) }}
                </p>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left border-b">
                            <th class="py-1 pr-3">Baris</th>
                            <th class="py-1 pr-3">Tindakan</th>
                            <th class="py-1 pr-3">Nama</th>
                            <th class="py-1">Alasan</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($hasil['baris'] as $b)
                            <tr class="border-b last:border-0">
                                {{-- Nomor barisnya ditulis biar admin tau persis
                                     mana yang harus dibenerin di file Excel-nya. --}}
                                <td class="py-1 pr-3 text-gray-500">{{ $b['baris'] }}</td>
                                <td class="py-1 pr-3">
                                    <span @class([
                                        'text-success-600' => $b['tindakan'] === 'dibuat',
                                        'text-info-600' => $b['tindakan'] === 'diperbarui',
                                        'text-warning-600' => $b['tindakan'] === 'dilewati',
                                    ])>{{ $b['tindakan'] }}</span>
                                </td>
                                <td class="py-1 pr-3">{{ $b['nama'] ?? '—' }}</td>
                                <td class="py-1 text-gray-500">{{ $b['alasan'] ?? '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>

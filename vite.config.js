import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            // Tema panel admin dibangun TERPISAH dari app.css.
            //
            // Filament nggak memakai CSS aplikasi: dia punya entry point tema
            // sendiri yang mengimpor `vendor/filament/.../theme.css`. Sampai
            // 26 Agt 2026 panelnya jalan tanpa tema kustom sama sekali —
            // `app.css` di bawah itu berkas starter Laravel yang nggak pernah
            // nyampe ke Filament, jadi warna & hurufnya bawaan semua.
            input: [
                'resources/css/app.css',
                'resources/css/filament/admin/theme.css',
                'resources/js/app.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                // Huruf ANGKA. Di produk ini angka itu isinya — U95, koreksi,
                // nomor sertifikat — dan lebar digit yang tetap bikin kolom
                // beneran lurus di tabel yang padat.
                bunny('JetBrains Mono', {
                    weights: [400, 500, 700],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});

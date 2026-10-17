# P3 — Studio di panel desktop (Flutter Windows/macOS)

Repo: **`sidik-calibration-mobile`**, branch `feat/studio-data-acuan`. Rujukan:
`docs/studio-data-acuan/04-UI-UX-Flow.md` (layar S1–S8, semua state di §5),
`03-SDD-ADR.md` §6 + ADR-01/09, rancangan visual di `ui-rujukan/` (Main, DaftarPaket, Simulasi,
Pengesahan, Keadaan). Tema & font mengikuti aplikasi: `lib/core/theme/app_colors.dart`
(meja ivory, kertas, cobalt, mint/crimson), Inter + IBM Plex Mono untuk angka.

## Gerbang masuk

API P1 (baca) dan P2 (tulis) tersedia di server lokal. Kerjakan dengan `AppConfig.useMock`
dulu bila server belum siap — mock wajib meniru kontrak `docs/kontrak-api.md` persis.

## Tugas

1. `lib/models/data_acuan.dart`, `lib/services/data_acuan_service.dart` (abstrak + `Api…` +
   `Mock…`, pola `rumus_service.dart`), `lib/providers/data_acuan_provider.dart`; sinyal
   `data_acuan` di `realtime_provider` meng-invalidate provider ini.
2. Seksi **"Data Acuan"** di `lib/screens/shell/desktop_shell.dart` (Paket, Pengesahan Acuan),
   tiap menu digerbangi izin dari server (`NamaIzin`), bukan cuma peran.
3. **Grid acuan** `lib/screens/data_acuan/widgets/grid_acuan.dart`: virtualisasi baris; panah,
   Tab, Enter, F2, Esc, Ctrl+C/V (TSV), Ctrl+Z/Y, Ctrl+F; sorot sel berubah + nilai lama dicoret;
   "presisi penuh"; sel terkunci bergembok dengan alasan. Lembar `tampilan_usulan: panjang` di
   skema → tampil memanjang (kunci, kolom, nilai). Parser angka `lib/core/utils/angka_lokal.dart`
   (BR-04) + 20 kasus test.
4. Layar S1 Daftar Paket, S2 Studio (tab dari `skema` server, BUKAN dikodekan per alat),
   S3 Banding, S4 Simulasi, S5 dialog Ajukan, S6 Pengesahan (dengan kartu `pengesah_ikut_menyunting`),
   S7 Riwayat, S8 Peta Rumus (baca saja + "Usulkan perubahan rumus").
5. Semua state 04 §5: memuat, kosong, error, tanpa izin, offline (baca saja dari cache + label),
   konflik 409 (dialog sel yang bentrok). Teks "berlaku di perangkat lain paling lambat 3 menit"
   selama `/api/health` → `realtime.nyala = false`.
6. Dependensi baru (mis. `two_dimensional_scrollables`) **minta izin dulu**, tulis alasannya.

## Penting: tab mengikuti alat

Studio tidak boleh punya `if (kode == 'micrometer')`. Tab, kolom, satuan, kunci, dan kunci-gembok
datang dari `skemaAcuan()` server. Alat ke-2 sampai ke-32 harus muncul **tanpa** mengubah kode
Flutter — itu ukuran berhasilnya.

## Test

Widget/golden test terang & gelap untuk S1, S2, S6; test parser angka; test izin menu
(`pakaiPanelDesktopProvider.overrideWithValue(true)`); `flutter test` penuh hijau.

## Selesai bila

Alur draf → sunting → simulasi → ajukan → sahkan (akun berbeda) berjalan melawan server lokal
untuk Micrometer, dan paket ber-JSON lain tampil baca-saja dengan tab yang benar tanpa kode khusus.

# P8 — Pola umum: mengekstrak nilai acuan yang masih ditulis di kode

Untuk 11 paket jenis `konstanta-php` (pH, Conductivity, Chlorine, DO, Turbidimeter,
Refractometer, Spektrofotometer, Viscometer, Gas Detector, Autoclave, Hydrometer). Prompt yang
dijalankan tetap `prompts/alat/NN-<kode>.md`; ini aturan bersamanya.

## Aturan penggolongan (AGENTS.md §Olah data butir 1)

| Contoh di KARTU | Lapis | Ke mana |
|---|---|---|
| nilai standar/buffer/larutan acuan, koreksi, U standar, drift, pita CMC, MPE, tabel koefisien suhu (`KOEF_SUHU`, `TABEL_TK`, `TABEL_SMC`), konstanta fisika metode, toleransi titik | 1 | paket data acuan |
| `ci`/`vi` yang merupakan bagian **struktur** budget (ekspresi koefisien sensitivitas, derajat kebebasan yang diatur metode) | 2 | tetap kode — tanyakan Lab bila ragu |
| `KODE_DOKUMEN`, `KODE_METODE`, label, satuan tampil, `STANDARD_TERCETAK`, `THERMOHYGRO_TERCETAK`, jumlah pengulangan di lembar | 3 | versi bentuk (P6) / identitas lembar |

Nilai yang **sudah** ada di tabel `standards` (U, k, drift standar) atau `calibration_capabilities`
(CMC) tidak diduplikasi — dirujuk.

## Langkah

1. Tabel penggolongan untuk SETIAP baris kandidat di KARTU, dengan alasan satu kalimat.
2. JSON acuan baru `database/data/acuan-<kode>.json` berisi lapis 1 persis seperti kode (string
   kanonik), tiap nilai ber-`_asal` berkas:baris.
3. Refactor profil membaca lewat `SumberAcuan` — refactor murni, perilaku identik.
4. `skemaAcuan()`, `acuan:impor-awal`, kasus `TabelAcuanSetaraJsonTest`.
5. Test master alat hijau tanpa diubah (mis. `PhMeterMasterTest`, `ViscometerMasterBaruTest`).

## Jangan

Mengubah nilai "sekalian membetulkan". Kejanggalan → pertanyaan lab bernomor.

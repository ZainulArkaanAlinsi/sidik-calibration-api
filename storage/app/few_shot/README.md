# Foto few-shot AI Vision (worksheet pH Tirta Gracia)

Folder ini tempat foto contoh ("few-shot") yang dilampirkan ke prompt AI Vision
biar pembacaan lebih akurat & kenal variasi tulisan tangan.
Lihat `Project-PT-Sidik/SPEC-vision-prompt.md` §4.

## Yang perlu diisi (tugas transisi — Arkaan)

Foto tabel worksheet fisik Tirta Gracia (cert **012-CAL-524**,
`SIDIK-FM-CAL-0509_Rev.4`), simpan dengan nama PERSIS:

| File | Isi |
|---|---|
| `few_shot_before.jpg` | Foto tabel **Before Adjustment Reading** (buffer 3.99 / 7 / 10.01) |
| `few_shot_after.jpg`  | Foto tabel **After Adjustment** (opsional, contoh ke-2) |

Ground-truth JSON-nya sudah di-hardcode di
`app/Services/WorksheetVisionExtractor.php` (dari `SPEC-vision-prompt.md` §4) —
jadi cukup taruh foto yang cocok di sini.

## Perilaku kalau foto belum ada

`WorksheetVisionExtractor` melampirkan few-shot **hanya kalau file-nya ada**.
Kalau folder ini masih kosong, ekstraksi tetap jalan pakai system prompt +
schema saja (akurasi lebih rendah, tapi tidak error). Begitu foto ditaruh di
sini, few-shot otomatis kepakai — tidak perlu ubah kode.

> Idealnya 2–3 few-shot dari worksheet berbeda supaya AI kenal variasi tulisan
> tangan. Tambahkan pasangan `(foto, JSON)` baru di `bangunMessages()` bila mau.

File `.jpg` di folder ini di-gitignore (data pelanggan) — hanya README ini yang
di-commit.

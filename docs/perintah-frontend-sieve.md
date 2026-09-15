# Perintah frontend — lembar kerja Sieve Mesh

> Dokumen ini berdiri sendiri. Tempel apa adanya ke sesi kerja `sidik-calibration-mobile`.

## Konteks singkat

Backend menambah profil **`sieve`** (lampiran akreditasi LK-285-IDN no. 33, metode
SIDIK-IK-CAL-0526, kertas **SIDIK-FM-CAL-0536 Rev.2**). Alat dengan
`nama_alat_kemampuan = "Sieve"` (alias: *Sieve Mesh, Test Sieve, Ayakan, Ayakan Uji*)
mendapat lembar ini dari `GET /api/calibrations/lembar-kerja?equipment_id=…`.

Bentuk lembarnya BARU di dua hal, dan dua-duanya sudah didukung model HP yang ada —
tidak perlu tipe tabel baru:

1. **Tabel opening berkolom TIGA** (`warp`, `weft`, `kawat`) dengan `pengulangan: [1]`,
   dan tujuannya `simpan_ke: spesifikasi_alat.sieve.opening` — jadi dikirim lewat
   `_tanamTabelSpesifikasi()` sebagai cerminan tabel, BUKAN lewat `measurements[]`.
2. **Tabel frame berkolom DUA** (`diameter`, `tinggi`), `offset_kunci: 1000`,
   `simpan_ke: spesifikasi_alat.sieve.frame`.

**Kalau `lembar_kerja_service.dart` punya `switch (profil)`, tambahkan cabang
`'sieve'`.** Tanpa cabang, mode mock/generic jatuh ke lembar pH tiga buffer — tidak
error, lembarnya salah. Kejadian ini sudah terulang di Micrometer, Timbangan, TIDS.

## Urutan bagian

`identitas_alat` → `pemilik` → `usage_check` → `hasil` (opening) → `frame` → `penutup`.

### `identitas_alat` — field penting

| kode | tipe | catatan |
|---|---|---|
| `spesifikasi_alat.sieve.tipe` | pilihan | `compliance` / `inspection` / `calibration` — menentukan jumlah minimum opening |
| `spesifikasi_alat.sieve.satuan` | pilihan | `mm` / `inch` / `µm` — satuan nominal DAN angka opening |
| `spesifikasi_alat.sieve.nominal` | angka | wajib persis ukuran ASTM E11 (inch ditulis inch: `0,75`) |
| `spesifikasi_alat.sieve.jumlah_opening_total` | angka | cuma diminta untuk ukuran ≥ 25 mm (minimum "all") |
| `suhu_awal`, `suhu_akhir` | angka | wajib — masuk komponen muai termal |

### `usage_check`

Dua baris tercetak (Digital Microscope/Dino-Lite, Digital Caliper Tesa) seperti lembar lain,
**plus** field pilihan `spesifikasi_alat.sieve.standar_dipakai` = `mikroskop` / `caliper`.
Yang dihitung server field pilihan ini, bukan centang.

### `hasil` — tabel opening

```json
{
  "tahap": "sesudah_adjustment",
  "grup": "opening",
  "simpan_ke": "spesifikasi_alat.sieve.opening",
  "titik_bisa_diubah": true,
  "baris": [{"nomor": 1, "titik_ukur": 1.0, "label": "1"}, "… sampai 30"],
  "kolom": [
    {"kode": "warp", "label": "Wrap (x')", "tipe": "angka"},
    {"kode": "weft", "label": "Weft (y')", "tipe": "angka"},
    {"kode": "kawat", "label": "Ø Kawat", "tipe": "angka"}
  ],
  "pengulangan": [1]
}
```

- `titik_ukur` baris = **nomor opening**. Teknisi boleh menambah baris sampai 100;
  `titik_ukur` baris baru = nomor berikutnya.
- Kertas mencetak 15 baris × 2 blok. Di HP cukup satu daftar menurun 1..30.
- **Opening 1..6 wajib lengkap** (sumber komponen pengulangan) — tampilkan penanda.

### `frame`

Tiga baris, kolom `diameter` & `tinggi` (mm), `offset_kunci: 1000`. Dicatat saja.

## Payload yang dikirim

```json
{
  "equipment_id": 123,
  "input_method": "manual",
  "tanggal_kalibrasi": "2026-05-11",
  "suhu_awal": 20.1, "suhu_akhir": 20.3,
  "kelembaban_awal": 54, "kelembaban_akhir": 55,
  "spesifikasi_alat": {
    "sieve": {
      "tipe": "inspection",
      "satuan": "mm",
      "nominal": 19,
      "standar_dipakai": "caliper",
      "jumlah_opening_total": null,
      "opening": {"baris": [
        {"titik_ukur": 1, "warp": [19.06], "weft": [18.94], "kawat": [3.34]},
        {"titik_ukur": 2, "warp": [19.04], "weft": [19.28], "kawat": [3.40]}
      ]},
      "frame": {"baris": [
        {"titik_ukur": null, "diameter": [200.0], "tinggi": [68.52]}
      ]}
    }
  },
  "measurements": []
}
```

Server meratakan `opening.baris` jadi `[{no, warp, weft, kawat}]` sebelum validasi, lalu
menulis `raw_measurements` tiga grup (warp/weft/kawat). **Baris kosong ikut dikirim di
posisinya** — nomor opening diambil dari `titik_ukur`, bukan dari urutan terisi.

## Koma desimal

`parseAngka()` sudah menerima `19,06` dan `19.06` → kirim sebagai angka JSON `19.06`.
Jangan kirim string. Server menolak (422) teks non-angka, tidak menebak.

## Yang ditampilkan dari respons

Tiga baris hasil (`titik_ke` 1 = Warp, 2 = Weft, 3 = Ø Kawat), 4 desimal, U95 dengan k = 2,
`keputusan` PASS/FAIL (guarded acceptance: `|deviasi| + U ≤ Y`).

`belum_dihitung` berisi alasan yang KEBACA bila sesi ditahan, mis.:

- nominal tidak persis di Tabel MPE ASTM E11,
- opening di bawah minimum (Inspection 19 mm = 15; Calibration 19 mm = 30),
- opening 1..6 belum lengkap,
- standar kedaluwarsa pada tanggal kalibrasi,
- ukuran × standar tanpa pita CMC (mis. mikroskop untuk sieve > 2 mm).

Tampilkan alasan itu apa adanya — jangan diganti "gagal menghitung".

## Fixture HP

Generate `contoh_lembar_kerja_panjang.dart` (atau berkas sieve sendiri) dari respons API
sungguhan lewat `php docs/skrip/gen-contoh-lembar-kerja.php` — jangan diketik tangan.

## Checklist

- [ ] cabang `'sieve'` di `lembar_kerja_service.dart`
- [ ] tabel 3 kolom `pengulangan: [1]` tergambar sebagai grid opening × (warp, weft, kawat)
- [ ] `simpan_ke: spesifikasi_alat.sieve.opening` terkirim sebagai `{baris: [...]}`
- [ ] tabel frame tidak bertabrakan kunci dengan opening (`offset_kunci` 1000)
- [ ] field pilihan `standar_dipakai` di bagian standar
- [ ] tambah baris opening sampai 100
- [ ] alasan `belum_dihitung` tampil utuh

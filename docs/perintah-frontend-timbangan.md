# Perintah frontend — lembar **Timbangan** (alat ke-21, kelompok Massa)

Dokumen berdiri sendiri. Tempel ke sesi kerja `sidik-calibration-mobile`; tidak perlu membaca
percakapan backend.

**Status backend:** BERES 31 Agt 2026. Profil, lembar kerja, mesin hitung, jalur simpan, jalur
hitung ulang, tiga sesi contoh ter-seed, dan `TimbanganMasterTest` (1.099 angka) hijau.

---

## 1. Yang berubah buat HP

| | |
|---|---|
| Kategori baru | `massa` — sebelumnya tidak pernah dipakai lembar mana pun |
| Kode profil | `timbangan` |
| Nama kemampuan | `Timbangan (Elektronik, mekanik)` (ejaan lampiran akreditasi, kurungnya ikut) |
| Endpoint bentuk | `GET /api/worksheet-schema?equipment_id=…` — sama seperti 20 alat lain |
| Nomor formulir | **null** — kertas lembar kerjanya belum pernah dikirim lab |
| Jalur kamera | **BELUM** — `bentuk_pindai_foto.didukung = false`, jangan gambar tombol FOTO TABEL |

Alat contoh sudah ter-seed, jadi bisa langsung dicoba:

| Sesi | Alat | Kapasitas / resolusi | Varian |
|---|---|---|---|
| `011-CAL-525` | Timbangan Bestar TB-100 | 100 kg / 0,02 kg | `kg` |
| `019-CAL-425` | Moisture Analyzer Mettler Toledo HB53 | 54 g / 0,0001 g | `gram` |
| `0136-CAL-123` | Timbangan Elektronik Dini Argeo DFWLB-3 | 2000 kg / 0,1 kg | `substitusi` |

## 2. Bentuk lembarnya BEDA dari 20 alat sebelumnya

Dua puluh lembar sebelumnya punya SATU tabel pengukuran. Timbangan punya **tujuh blok**, dan
cuma satu yang jadi baris titik di sertifikat:

| Bagian (`kode`) | Judul | Bentuk |
|---|---|---|
| `identitas_alat` | IDENTITAS ALAT | field — termasuk 4 dropdown baru, lihat §3 |
| `pemilik` | IDENTITAS CUSTOMER | field + lokasi + kondisi lingkungan |
| `usage_check` | STANDAR ANAK TIMBANGAN | 7 baris centang |
| `scale_observation` | 1. SCALE OBSERVATION | 2 baris (before/after adjustment) × 5 kolom |
| `effect_of_tare` | 2. EFFECT OF TARE | field |
| `akurasi` | 3. ACCURACY | **`tabel`** — 10 baris × 4 kolom + daftar nominal |
| `keterulangan` | 4. REPEATABILITY | **`tabel`** — 2 baris × 10 kolom × 2 sub-kolom |
| `eksentrisitas` | 5. LOADING INFLUENCE | field (5 posisi) |
| `histeresis` | 6. HYSTERISIS | field (2 deret × 8) |
| `penutup` | Catatan & Tanda Tangan | field |

Dua bagian ber-`tabel` memakai bentuk yang **sudah dikenal app** (`baris` / `pengulangan` /
`kolom` / `pengulangan_arah`) — sama persis dengan lembar TIDS & ketiga alat suhu. Jadi widget
tabel yang sudah ada bisa dipakai ulang; yang baru cuma jumlah & label kolomnya.

### `akurasi` — empat kolom yang BUKAN pengulangan biasa

```
pengulangan_arah: [ {ke:1,label:"z"}, {ke:2,label:"m"}, {ke:3,label:"m'"}, {ke:4,label:"z'"} ]
```

Keempatnya pembacaan yang artinya berbeda: `z` nol sebelum beban, `m` & `m'` dua pembacaan
berbeban, `z'` nol sesudah beban. **Jangan** dinamai "Pengulangan 1..4" di layar — teknisi
membaca kertas yang menulis `z1 / m1 / m1' / z2`.

Baris awalnya sudah terisi **10 % s/d 100 % rentang ukur, sepuluh langkah rata** (`titik_bisa_diubah:
true`). Itu pola yang dipakai ketiga master, bukan tebakan. Lembar tidak pernah terbuka dengan nol
baris — pelajaran K18 dari TIDS.

### `keterulangan` — dua sub-kolom per pengulangan

```
kolom: [ {kode:"zero", label:"Zero (zi)"}, {kode:"pembacaan", label:"Reading (mi)"} ]
```

Sepuluh pengulangan × dua angka, dua baris (Middle & Maximum Capacity). Kalau widget tabel yang
ada baru sanggup satu sub-kolom, ini satu-satunya yang perlu ditambah.

## 3. Empat dropdown yang MENENTUKAN angka — bukan hiasan

Ini bagian yang paling gampang dianggap kosmetik dan paling mahal kalau salah:

| Field | Pilihan | Kalau salah |
|---|---|---|
| `tipe_timbangan` | `Non-Analytical` / `Analytical` | **Memilih tabel anak timbangan** (F1 vs E2). Salah pilih = massa konvensional keping yang berbeda, koreksi meleset di digit yang justru dilaporkan |
| `varian_master` | `kg` / `gram` / `substitusi` | Memilih revisi master: rumus keterulangan, pembulatan, dan snapshot sertifikat anak timbangan |
| `tipe_display` | `Digital` / `Mekanik` | `a` = resolusi/2 atau /10; ikut ke lantai `Sres` DAN komponen Resolution |
| `jenis_timbangan` | Tak Bertingkat / Bertingkat analog / digital | Menentukan nilai `e` di sertifikat |

Ketiganya dikirim server di `bentuk['bagian']` lengkap dengan `pilihan` — **jangan dipetakan ulang
di HP.** Peta yang disalin ke HP pasti ketinggalan begitu lab mengubahnya, dan gagalnya paling
sepi: dropdown memajang kode mentah, atau memilih varian yang sudah tidak ada.

Bawaan `varian_master` kalau teknisi tidak memilih: kapasitas > 200 kg → `substitusi`, kalau tidak
`gram` untuk satuan g dan `kg` untuk sisanya (aturan lampiran akreditasi no. 12: *"untuk rentang di
atas 200 kg menggunakan Metode beban substitusi"*).

## 4. Payload simpan

`POST /api/calibrations` — sama seperti alat lain, `measurements[]` yang bentuknya beda:

```jsonc
{
  "equipment_id": 42,
  "measurements": [
    {
      "titik_ukur": 10,           // WAJIB — kolom "Nominal" di kertas (jumlah nominal keping)
      "nominal": [10],            // Mass 1..6, KOLOM-MAJOR (lihat catatan)
      "z1": 0, "m": 10, "m_aksen": 10, "z2": 0
    }
    // … sampai 10 titik
  ],
  "spesifikasi_alat": {
    "rentang_ukur": "100", "kapasitas": "100", "resolusi": "0.02",
    "varian_master": "kg", "tipe_display": "Digital",
    "tipe_timbangan": "Non-Analytical", "satuan": "kg",
    "keterulangan": {
      "mid":  { "nominal": 50,  "zi": [0,0,…], "mi": [50.02,…] },
      "maks": { "nominal": 100, "zi": [0,0,…], "mi": [100.02,…] }
    },
    "eksentrisitas": { "beban": 20, "baca": { "center":20,"front":20,"back":20.02,"left":20,"right":20 } },
    "histeresis": { "baca1": [20,40,20,0,40,20,0.02,20], "baca2": [20,40,20,0.02,40,20,0,20] }
  }
}
```

> **`titik_ukur` tetap wajib**, sama seperti dua puluh lembar lain — isinya **jumlah nominal**
> keping yang dipakai titik itu, yaitu angka yang tercetak di kolom `Nominal` kertasnya. Massa
> KONVENSIONAL-nya (10,000007 kg untuk keping 10 kg) diturunkan server dari tabel anak timbangan;
> jangan dikirim dari HP — tabelnya bisa berubah begitu keping dikalibrasi ulang, dan dua angka
> yang mengaku mewakili hal yang sama itu temuan audit.
>
> **`nominal` urutannya MENGIKAT.** Urut **Mass 1..6 kolom-major** seperti master: kolom kiri baris
> 1..3 dulu, baru kolom kanan baris 1..3. Slot pertama dapat `ci` = 10 di varian substitusi dan
> jadi satu-satunya sumber `u` standar, jadi keping yang mendarat di slot yang salah menggeser
> budget **tanpa satu pun error**. Kalau layar cuma menyediakan satu kolom nominal, kirim apa
> adanya berurutan — itu sudah benar.

Lima blok tingkat-sesi (`keterulangan`, `eksentrisitas`, `histeresis`, dan dua lagi) masuk
`spesifikasi_alat`, **bukan** `measurements` — kelimanya satu per sesi, bukan per titik.

## 5. Yang dikembalikan

Tiap titik di `uncertainty_calculations`:

- `ketidakpastian_diperluas` — **U95% of Correction**, yang menempel di kolom `Correction`.
- `type_b_components` — KEDUA budget, tiap baris ber-`budget: "koreksi" | "penimbangan"`, plus
  baris `u95_penimbangan` (angka bagian 7 sertifikat), `varian_master`, dan `lantai_cmc` kalau
  CMC yang menang.

**Sertifikat Timbangan mencetak DUA ketidakpastian** (NMI Monograph 4): bagian 3 memakai U95 of
Correction, bagian 7 memakai U95 of Weighing. Layar detail harus menampilkan dua-duanya — kalau
cuma satu, separuh angka yang tercetak tidak punya asal-usul di layar.

## 6. Yang SENGAJA belum ada

- **Tombol kamera.** `bentuk_pindai_foto.didukung = false`. Tujuh blok yang tidak sebentuk tidak
  bisa diungkapkan lewat `kolom_suhu`/`standar_di_baris` yang cuma memodelkan satu tabel datar;
  dipaksakan, yang balik dari model bukan error melainkan angka karangan. Alasan yang sama dipakai
  Autoklaf & grid Enclosure.
- **Vonis PASS/FAIL.** `punyaToleransi()` = false — batas keberterikan Timbangan datang dari MPE
  kelas (SNSU PK.M-02:2021) yang butuh nilai `e`, dan `e` diisi teknisi. Jangan gambar chip
  lulus/tidak sebelum itu ada.
- **Nomor formulir di kop.** `kode_dokumen` null sampai kertasnya turun dari lab.

## 7. Desimal U95 di layar & PDF — belum diputus

Master mencetak U95 dengan **satu desimal lebih banyak** daripada kolom pembacaan (kg: 3 vs 2;
gram: 5 vs 4), sementara aturan bawaan sistem memakai desimal yang sama untuk keduanya. Jadi
`0,033 kg` bakal tampil `0,03 kg` kalau ikut bawaan.

Sampai **T11** dijawab lab, **jangan** menambal pembulatannya di HP. Angka penuh selalu ada di
respons; yang belum pasti berapa desimal yang benar untuk DICETAK, dan menambalnya di dua tempat
(HP & PDF) hampir pasti melahirkan dua angka berbeda untuk satu pengukuran — temuan audit.

## 8. Yang perlu ditanyakan balik ke backend kalau kelihatan aneh

Sepuluh pertanyaan lab yang masih terbuka ada di `docs/pertanyaan-lab-timbangan.md`. Dua yang bisa
kelihatan di layar:

- **T1** — tiga workbook memuat sertifikat anak timbangan yang BERBEDA untuk keping yang sama.
  Kalau dua sesi alat yang sama beda varian menghasilkan koreksi yang berbeda, itu bukan bug.
- **T2** — `ui` U-of-Correction diperlakukan beda di tiga master; U95 of Weighing bisa hampir 2×
  antar varian. Juga bukan bug.

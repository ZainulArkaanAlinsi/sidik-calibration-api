# Perintah frontend — lembar **Timbangan** (alat ke-21, kelompok Massa)

Dokumen berdiri sendiri. Tempel ke sesi kerja `sidik-calibration-mobile`; tidak perlu membaca
percakapan backend.

**Status backend:** BERES 31 Agt 2026. Profil, lembar kerja, mesin hitung, jalur simpan, jalur
hitung ulang, tiga sesi contoh ter-seed, dan `TimbanganMasterTest` (1.099 angka) hijau.

**Status HP:** BERES 31 Agt 2026 — lembarnya kegambar, payloadnya sampai, 13 test baru hijau
(`timbangan_lembar_test.dart` + `timbangan_layar_test.dart`). Bentuk mock-nya ada di
`lib/services/contoh_lembar_kerja_massa.dart`, disalin apa adanya dari respons server.

---

## 1. Yang berubah buat HP

| | |
|---|---|
| Kategori baru | `massa` — sebelumnya tidak pernah dipakai lembar mana pun |
| Kode profil | `timbangan` |
| Nama kemampuan | `Timbangan (Elektronik, mekanik)` (ejaan lampiran akreditasi, kurungnya ikut) |
| Endpoint bentuk | `GET /api/worksheet-schema?equipment_id=…` — sama seperti 20 alat lain |
| Nomor formulir | **null** — kertas lembar kerjanya belum pernah dikirim lab |
| Jalur kamera | **PER TABEL** — `pindai_foto.lokal = true`; Repeatability ON, Accuracy OFF. Lihat §6 |

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

Nominal keping tiap titik duduk di **`kolom_baris`** tabel itu — satu kotak per baris, bertipe
`daftar_angka`, diisi `20+20+10`. Mekanisme `kolom_baris` sudah dipakai `no_probe` Thermocouple;
yang baru cuma tipenya. Kotak ini **bukan pelengkap**: server yang menjumlahkannya jadi
`titik_ukur`, jadi titik tanpa isinya bernilai nol dan koreksinya nggak berarti apa-apa.

> **Koma itu koma DESIMAL, bukan pemisah keping.** `20,5+10` = dua keping. Dibaca sebagai pemisah
> dia jadi `[20, 5, 10]` — tiga keping, jumlahnya melar 30,5 jadi 35, dan slot Mass-nya geser
> semua. Nol error. Pemisah yang diterima cuma `+`, `;`, dan spasi.

### `keterulangan` — bentuk KERTAS, bukan transposed

```
sumbu_pengulangan: "baris"          <- nomor 1..10 TURUN di kolom `No.`
slot_cetak:  [ {label:"Middle Capacity"}, {label:"Maximum Capacity"} ]
kolom:       [ {kode:"zero", label:"Zero (kg)"}, {kode:"pembacaan", label:"Reading (kg)"} ]
pengulangan_arah: [ {ke:1,label:"1"}, … {ke:10,label:"10"} ]
pindai_foto: true
simpan_ke:   "spesifikasi_alat.keterulangan"
offset_kunci: 1000
titik_bisa_diubah: false
```

Di kertas master susunannya begini — dan bentuk yang dikirim sekarang mengikutinya persis:

```
        |  Middle Capacity   |  Maximum Capacity
        |        50          |        100
   No.  | Zero (kg)  Reading (kg) | Zero (kg)  Reading (kg)
        |   (zi)       (mi)       |   (zi)       (mi)
        |     1          2        |     1          2         <- penomoran sub-kolom
    1   |     0        50.02      |     0        100.02
   ...  |
   10   |     0        50.02      |     0        100.02
```

Draf pertama mengirimnya **transposed** (kapasitas jadi baris, pengulangan jadi kolom). Bukan soal
selera tata letak: pemeta foto menjangkar tiap angka ke dua sumbu, dan dua-duanya diambil dari
tulisan yang TERCETAK. Dijalankan pada bentuk transposed, kedua jangkarnya ada di sumbu yang salah
— **tiap jepretan pulang nol sel.**

**Satuan ikut jadi jangkar, dan itu disengaja.** Label sub-kolomnya ditulis persis seperti tercetak
— `Zero (kg)` di master kg & substitusi, `Zero (g)` di master gram. Jadi lembar gram yang difoto ke
sesi kilogram tidak menemukan jangkarnya dan pulang nol sel: gagal berisik, bukan memindahkan
`24,9999 g` ke kotak kilogram.

> `satuan` per slot SENGAJA tidak dikirim. HP memakai `slot.satuan ?? kolom.label` buat jangkar
> sub-kolom, jadi mengisinya bikin `Zero` dan `Reading` berjangkar tulisan yang sama — dan angka
> nol bisa mendarat di kolom berbeban.

**Dua kapasitas ujinya DIKETIK**, lewat `spesifikasi_alat.keterulangan.mid.nominal` dan
`.maks.nominal` di `field` bagian itu. Bukan diturunkan dari rentang alat: master gram punya alat
berkapasitas **54 g** yang keterulangannya diambil di **25 g & 50 g**. Angka itu masuk rumus lewat
`deviasiKurangiNominal` (nyala di gram DAN substitusi) dan lewat `srTerdekat()`.

Tiga kunci lain yang menentukan, dan ketiganya lahir dari kesalahan nyata:

- **`simpan_ke`** — isinya besaran satu per SESI, bukan titik yang dikoreksi. Lewat `measurements[]`,
  sertifikatnya terbit dengan dua baris titik tambahan yang nggak pernah diminta siapa pun (50 kg &
  100 kg, angkanya sah, set point-nya sah, nol error).
- **`offset_kunci`** — baris Accuracy 50 kg & 100 kg BENTROK dengan Middle/Maximum. Tanpa offset,
  empat baris berbagi dua kotak isian dan angka yang diketik di satu tabel muncul di tabel satunya.
- **`titik_bisa_diubah: false`** — di HP penanda itu menggerakkan SATU daftar titik untuk seluruh
  lembar. Nyala di dua tabel sekaligus, menyusun sepuluh titik Accuracy ikut mengubah tabel ini jadi
  sepuluh baris Middle/Maximum yang nggak ada di kertas mana pun.

### Yang TIDAK boleh dipakai: `peran`

Kedua tabel dibedakan `grup` (`akurasi` / `keterulangan`), **bukan `peran`**. Di HP `peran` yang
bukan null berarti satu hal yang sangat spesifik: *"lembar ini membaca DUA deret per titik —
standar & UUT"*, dan nilainya membelokkan SELURUH lembar ke jalur pasangan (payload berangkat
berisi `standar`/`uut` tanpa satu pun nominal) sekaligus mengunci baris ke offset parameter.
Dijaga `SemuaProfilLembarKerjaTest::test_peran_tabel_cuma_buat_lembar_pasangan`.

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

Lima blok tingkat-sesi (`keterulangan`, `eksentrisitas`, `histeresis`, `scale_observation`,
`effect_of_tare`) masuk `spesifikasi_alat`, **bukan** `measurements` — kelimanya satu per sesi,
bukan per titik.

### Bentuk yang benar-benar dikirim HP — dan kenapa server menerima dua-duanya

Bentuk di atas itu **kontraknya**, dipakai seeder & test. HP mengirim dua bagian dengan bentuk
GENERIK yang sudah dipakai dua puluh lembar lain, dan server menerjemahkannya:

| Bagian | Bentuk kontrak | Bentuk HP | Diterjemahkan di |
|---|---|---|---|
| Empat pembacaan akurasi | `z1`, `m`, `m_aksen`, `z2` | `pembacaan: [z, m, m', z']` menurut posisi kolom | `CalibrationController::bacaanTimbangan()` |
| Blok keterulangan | `{mid, maks}` dengan `zi`/`mi` | `{baris: [{titik_ukur, zero: [...], pembacaan: [...]}]}` | `CalibrationRequest::bakukanKeterulanganTimbangan()` |

Alasannya satu: nama slot (`m_aksen`, `mid`, `zi`) itu **kosakata master alat ini**, dan menaruhnya
di layar yang menggambar dua puluh lembar berarti daftar tulis-tangan yang menyusut diam-diam tiap
ada alat baru. Urutan kolomnya sendiri sudah dipatok bentuk lembar (`pengulangan_arah`: z, m, m',
z'), dan urutan barisnya sudah dipatok `bagianKeterulangan()` (Middle dulu, Maximum kedua) — jadi
posisi sudah cukup buat memetakannya di sisi server.

**Kunci bernama MENANG kalau dikirim bareng deret.** Kalau tidak, sesi lama yang dibuka lagi di HP
lalu dikirim ulang bakal menimpa pembacaannya dengan deret kosong bawaan layar.

Yang TERSIMPAN selalu bentuk baku `{mid, maks}` — jalur hitung ulang (`kalibrasi:hitung-ulang`)
membaca `calibration_sessions.spesifikasi_alat` apa adanya, jadi bentuk mentah yang lolos ke DB
bakal menghitung nol keterulangan di setiap sesi. Dijaga
`TimbanganSesiTest::test_bentuk_kiriman_hp_sama_hasilnya_dengan_bentuk_kontrak`.

### Kode kotak keempat blok field WAJIB berawalan `spesifikasi_alat.`

```
spesifikasi_alat.scale_observation.sebelum_adjustment.z1
spesifikasi_alat.effect_of_tare.bentuk_pan
spesifikasi_alat.eksentrisitas.baca.center
spesifikasi_alat.histeresis.baca1.0        … sampai .baca2.7
```

Di HP, kode bertitik **tanpa** awalan itu berarti kolom TURUNAN: read-only, diisi sistem dari alat
yang dipilih, dan tidak pernah ikut payload (`FieldLembarKerja.turunan`). Keempat blok ini sempat
begitu — tiga puluh sembilan kotak digambar rapi, diisi teknisi dari kertas, lalu hilang waktu
tombol kirim ditekan. Tanpa satu pun error, di kedua sisi. Dua di antaranya menggerakkan angka
(eksentrisitas → komponen Eccentricity, histeresis → angka Hysterisis).

Titik SESUDAH awalan itu berarti **bersarang**, jadi HP menyusunnya jadi objek. Segmen yang
seluruhnya angka tetap jadi kunci teks (`"0"`, `"7"`); PHP membaca `{"0":…,"7":…}` sebagai array
berindeks, jadi `count($b) >= 8` dan `$b[0]` di sisi sana tetap benar.

## 5. Yang dikembalikan

Tiap titik di `uncertainty_calculations`:

- `ketidakpastian_diperluas` — **U95% of Correction**, yang menempel di kolom `Correction`.
- `type_b_components` — KEDUA budget, tiap baris ber-`budget: "koreksi" | "penimbangan"`, plus
  baris `u95_penimbangan` (angka bagian 7 sertifikat), `varian_master`, dan `lantai_cmc` kalau
  CMC yang menang.

**Sertifikat Timbangan mencetak DUA ketidakpastian** (NMI Monograph 4): bagian 3 memakai U95 of
Correction, bagian 7 memakai U95 of Weighing. Layar detail harus menampilkan dua-duanya — kalau
cuma satu, separuh angka yang tercetak tidak punya asal-usul di layar.

### Peta lengkap sertifikat — dibaca dari sheet `SERTIFIKAT` workbook, bukan ditebak

Delapan bagian, dan tiap angkanya ditelusuri ke selnya:

| § Sertifikat | Isi | Dari |
|---|---|---|
| 1. REPEATABILITY | Half/Full Capacity, **Deviation Standard**, **Maximum Deviation With the Next Reading** | `keterulangan.nominal_mid/maks`, `stdev_mid/maks`, `mid/maks.maks_beda` |
| 2. EFFECT OF TARE | satu angka | **`|m1 − m2|`** — lihat catatan di bawah |
| 3. ACCURACY | Nominal Standard, Correction, **Uncertainty ±** | `titik_ukur` (massa konvensional), `koreksi`, `u95_koreksi` |
| 4. LOADING INFLUENCE | 5 posisi + **Maximum Difference** | `eksentrisitas.selisih`, `eksentrisitas.rentang` |
| 5. HYSTERISIS | Load, **Hysterisis** | `histeresis.m`, lalu **perbandingan** — lihat catatan |
| 6. LIMIT OF PERFORMANCE | satu angka | `lop` |
| 7. WEIGHING UNCERTAINTY | Nominal Standard, **Uncertainty ±**, `K =` | `titik_ukur`, `u95_penimbangan`, faktor cakupan |
| 8. STANDARD USED | Name, Nominal Mass, Merk/Class, SN, Traceability | anak timbangan yang dicentang di `standar_dicek` |

Tiga hal yang **tidak** bisa ditebak dari tampilan sertifikatnya, dan sudah salah kalau ditebak:

- **§2 bukan `C = Ms−(M−z)`** seperti tertulis di petunjuk lembar kerjanya. Sel yang dicetak
  `FC!F44 = ABS(E44−E45)`, yaitu **selisih mutlak dua pembacaan tare** (`|m1 − m2|`). Petunjuk di
  kertas kerja itu untuk besaran lain.
- **§5 mencetak PERBANDINGAN, bukan nilai.** Selnya `IF(hasil ≤ resolusi, "<", ">")` lalu memajang
  **nilai resolusi** — jadi yang terbit `< 0,0001 g`, bukan angka histeresisnya. Mencetak angka
  mentahnya berarti sertifikat menyatakan hal yang berbeda dari yang dimaksud lab.
- **§4 "Reading" itu SELISIH, bukan pembacaan.** Selnya `beban − pembacaan posisi`; yang tercetak
  penyimpangan tiap posisi. `Maximum Difference` = `MAX − MIN` dari kelima selisih itu.

> **Catatan pelaksanaan.** Mesin hitung sekarang menurunkan selisih eksentrisitas dari pembacaan
> **CENTER**, bukan dari beban — karena `eksentrisitas.beban` kosong di ketiga sesi master. Di
> ketiganya pembacaan center kebetulan sama dengan bebannya, jadi angkanya identik dan paritasnya
> hijau. Begitu ada sesi yang center-nya menyimpang dari beban, keduanya berpisah — dan yang benar
> rumus master (`beban − pembacaan`). Diangkat sebagai **T13**.

## 6. Yang SENGAJA belum ada

- **Tombol kamera — NYALA, tapi cuma di satu tabel.**

  `pindai_foto.lokal = true` (ML Kit di perangkat, citra tidak pernah keluar HP), dan gerbangnya
  diturunkan ke tabel lewat `tabel[].pindai_foto`:

  | Blok | Kamera | Sebabnya, dari kertas masternya |
  |---|---|---|
  | `keterulangan` | **ON** | Grid sempurna: nomor `1`..`10` turun di kolom `No.`, dua kapasitas berjajar ke samping, sub-kolom `Zero (kg)`/`Reading (kg)`. Ketiga jangkarnya tercetak |
  | `akurasi` | **OFF** | Bukan grid. Di kertas dia daftar MENURUN — `z1`, `m1`, `m1'`, `z2`, … (kg & gram) atau `z1`, `M1`, `M1'`, `z1'` (substitusi, huruf besar + baris penutup). Pembedanya TULISAN per baris, dan pemeta yang ada menjangkar kolom ke nomor pengulangan |

  Menyalakan Accuracy juga tidak balik error — yang muncul *"tabelnya dikenali, tapi selnya masih
  kosong"*, dan teknisi menyangka fotonya kurang terang lalu mengulang jepretan sampai menyerah.

  **`pindai_foto.didukung` tetap `false`.** Itu gerbang jalur CLOUD, yang MENGIRIM FOTO LEMBAR KERJA
  PELANGGAN ke layanan pihak ketiga; prompt-nya dibangun untuk satu tabel datar, dan tujuh blok yang
  tidak sebentuk bikin yang balik bukan error melainkan angka karangan.

  **Nomor polos aman di sini karena dijaga deretnya.** Kertas ini menomori baris `1`..`10` polos,
  dan angka `1` juga muncul di baris penomoran sub-kolom TEPAT DI ATAS isi tabel. Pencarian teks
  biasa mengambil kemunculan paling atas — jangkarnya jadi **lengkap tapi salah**, dan seluruh grid
  bergeser satu baris tanpa satu pun gejala. `_jangkarNomorPolosBaris` di HP menolak itu: nomor
  polos cuma diterima kalau lembarnya menyatakannya lewat `pengulangan_arah`, dan hanya sebagai
  DERET UTUH yang tersusun tegak di satu kolom, di kiri kolom data. Kurang satu nomor → nol sel,
  bukan sembilan baris yang bergeser. Dijaga `test/foto_tabel_timbangan_test.dart`.

- **Pindai SATU HALAMAN PENUH bermarker.** Lembar ini tidak punya berkas geometri
  (`database/ocr-templates/timbangan-v1.json` sengaja tidak ada), dan `ocr:rangka-geometri`
  menolaknya: pipeline itu menurunkan tinggi sel, kotak jangkar, dan urutan kunci sel SEKALI untuk
  satu lembar, sementara lembar ini mencampur dua orientasi tabel. Berkas yang tetap diterbitkan
  bakal menggambar Repeatability melintang — bertentangan dengan bentuknya sendiri. Jalur per-tabel
  di atas tidak membutuhkannya: dia menjangkar ke tulisan tercetak, bukan koordinat.

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

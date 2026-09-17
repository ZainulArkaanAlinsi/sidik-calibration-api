# Perintah frontend — Flowmeter Ultrasonic (alat ke-27 & ke-28)

**Tempel dokumen ini apa adanya ke sesi kerja `sidik-calibration-mobile`.** Dia berdiri
sendiri: tidak ada satu pun hal di bawah yang perlu dicari balik ke repo backend.

**Status backend: SELESAI, 8 September 2026.** Kedua profil terdaftar, jalur simpan &
hitung ulang tersambung, sertifikat mencetak blok spesifikasi pipa, dan seluruh angka
sesi contohnya sudah diadu sel demi sel ke kedua workbook master (5·10⁻⁶).

> **STATUS SISI MOBILE: SUDAH DIKERJAKAN, 9 September 2026.** `dart analyze` bersih,
> `flutter test` **1627/1627** hijau. Dokumen ini sekarang jadi CATATAN apa yang
> mendarat — bukan lagi daftar tugas. Yang mengerjakannya ulang dari nol tetap bisa
> memakainya sebagai panduan.
>
> Satu berkas test ikut diperbarui di luar kelima butir di bawah:
> `test/vonis_toleransi_mock_test.dart` — tabel vonisnya menuntut TIAP baris kemampuan
> mock punya padanan di server, jadi kedua alat baru harus didaftarkan (keduanya
> `false`, masternya berhenti di `U95%`).

Lembar kerjanya digerakkan JSON dari server, jadi sebagian besar jalan sendiri — yang
perlu disentuh cuma **lima** hal, dan **empat di antaranya menggerakkan ANGKA**.

---

## 0. Ringkasan satu paragraf

Dua alat, satu kertas (`SIDIK-FM-CAL-0538_Rev.0`), satu standar (UFM Krohne UFC300),
satu mesin hitung. **Flow Meter Cairan (Totalizer)** bersatuan `L`, **Flow Meter Cairan
(Flowrate)** bersatuan `Lpm`; keduanya terakreditasi (lampiran LK-285-IDN no. 30 & 31).
Ketidakpastiannya lahir **per TITIK** — tiap titik punya blok budget penuh sendiri, jadi
sertifikatnya mencetak kolom `U95% ±` per baris dan `k` per baris.

### 0.1 Sertifikatnya dicetak dalam satuan ALAT, bukan satuan hitung (17 Sep 2026)

Mesin hitungnya hidup dalam `L`/`Lpm` — apa pun satuan yang dipilih teknisi, pembacaannya
dikonversi ke situ dulu supaya satu mesin melayani semua satuan, dan
`uncertainty_calculations` menyimpan angka yang sudah dikonversi itu.

Yang **berubah**: `certificates.snapshot.hasil[].{standard_value, unit_under_test,
correction, u95}` sekarang dibalik ke satuan yang dipilih teknisi sebelum dibekukan, dan
`hasil[].satuan` ikut berisi satuan itu. Sebelumnya kolomnya berisi angka `Lpm` sementara
kop sertifikat (`snapshot.satuan`, dari `raw_measurements.satuan`) sudah menulis `m3/h` —
satu dokumen, dua satuan untuk besaran yang sama, dan alat yang layarnya menunjukkan
`3,0` terbit dengan `50,0`.

Yang **tidak** berubah, dan ini yang penting buat HP:

- Bentuk JSON-nya sama persis — nol kunci baru, nol kunci hilang.
- Sesi bersatuan `L`/`LPM` (faktor 1,0) **nol pergeseran**, angkanya maupun labelnya.
- Sertifikat yang SUDAH terbit nol pergeseran: snapshot dibekukan waktu terbit dan tidak
  pernah dihitung ulang waktu dibaca.
- Lembar kerja, jalur kirim, dan `spesifikasi_alat` nol perubahan.

Jadi HP tidak perlu mengerjakan apa pun — asalkan yang dipajang tetap `hasil[].satuan`
per baris, bukan satuan yang ditebak dari kode profil. Yang memajang `Lpm` mati di kode
akan salah begitu ada pelanggan bersatuan `m3/h`.

Kalibrasi bersatuan massa (`kg`, `kg/h`, `kg/min`) dibalik lewat densitas fluida yang
diketik teknisi (`flow_densitas_uut`). Kalau densitas itu hilang sesudah hitungannya
tersimpan, sertifikatnya **gagal terbit** dengan pesan yang menyebut nomor titiknya —
bukan terbit dengan angka satuan hitung berlabel `kg/h`.

---

Yang bikin alat ini beda dari 26 lainnya: **satu titik punya DUA deret berdampingan** —
pembacaan UUT dan pembacaan totalizer standar. Kalau keduanya tertukar atau saling
tertimpa, yang terbit bukan error melainkan **deviasi nol** di setiap titik: sertifikat
yang mencetak koreksi 0,000 dan terlihat seperti alat yang sangat akurat.

Dan pada varian **Flowrate**, deret UUT-nya **bersarang**: tiap ulangan berisi tiga
durasi (20″/40″/60″), dan simpangan bakunya dihitung atas ketiga durasi ulangan **itu**
— bukan antar-ulangan. Diratakan, komponen budget ke-3 keluar jauh lebih besar dan
tetap terlihat masuk akal.

---

## 1. `lib/services/contoh_lembar_kerja_aliran.dart` — BERKAS BARU, dua fungsi

Repo mobile menamai contoh per **kelompok pengukuran**. Kelompok `Aliran` belum punya
berkasnya, jadi ini berkas baru berisi **dua** fungsi:

```dart
Map<String, dynamic> contohBentukLembarKerjaFlowmeterTotalizer() { ... }
Map<String, dynamic> contohBentukLembarKerjaFlowmeterFlowrate()  { ... }
```

Isinya **salinan APA ADANYA** dari respons

```
GET /api/calibrations/lembar-kerja?equipment_id=<id alat contoh>
```

untuk alat contoh ter-seed: serial **`FM-TOT-DEMO-01`** dan **`FM-FLW-DEMO-01`**.

**Digenerate dari JSON-nya, jangan disusun ulang tangan.** Menyusun ulang berarti mode
mock memajang bentuk yang berbeda dari yang dikirim server, dan bedanya baru ketahuan di
lapangan.

---

## 2. `lembar_kerja_service.dart` — DUA cabang

Tanpa ini, mode mock memajang lembar pH tiga titik buffer untuk alat flowmeter: **tidak
ada error, cuma lembar yang salah**.

Cabangnya dipilih dari **kode profil**, bukan dari nama alat — `switch` yang sudah ada di
berkas itu memang berjalan di atas kode profil, dan itu justru yang aman: kedua nama
alatnya saling memuat sebagian ("Flow Meter Cairan"), sementara kodenya tidak.

| kode profil | fungsi contoh |
|---|---|
| `flowmeter_totalizer` | `contohBentukLembarKerjaFlowmeterTotalizer()` |
| `flowmeter_flowrate` | `contohBentukLembarKerjaFlowmeterFlowrate()` |

---

## 3. `lembar_kerja_state.dart` — TAMBAH ke `_kodePenentuAngka`

**Ini yang paling menentukan di dokumen ini.** Kelima kode di bawah **mengubah angka
yang terbit**, jadi lembar yang salah satunya kosong tidak boleh dianggap "sudah diisi":

```
spesifikasi_alat.flowmeter.mode
spesifikasi_alat.flowmeter.satuan
spesifikasi_alat.flowmeter.resolusi
spesifikasi_alat.flowmeter.diameter_pipa_mm
spesifikasi_alat.flowmeter.ketebalan_pipa_mm
```

Kenapa masing-masing:

| Kode | Kalau kosong / salah |
|---|---|
| `mode` | Menentukan budgetnya **8 komponen (Totalizer) atau 9 (Flowrate)**. Kosong → seluruh titik pulang "belum dihitung". Salah → budget generasi yang keliru, angkanya tetap keluar |
| `satuan` | **Mengalikan SELURUH pembacaan.** `m3/h` vs `LPM` berbeda 16,67×. Sejak 17 Sep 2026 dia juga menentukan satuan yang TERCETAK di sertifikat — lihat §0.1 |
| `resolusi` | Komponen resolusi jadi nol; U95 terbit lebih kecil |
| `diameter_pipa_mm` | Tanpa dia `u_A` nol dan **DUA** komponen lenyap sekaligus |
| `ketebalan_pipa_mm` | Sama — dan diameter DALAM (`D − 2t`) yang masuk hitungan, bukan yang luar |

Backend memang **memblokir** titiknya kalau resolusi atau geometri pipa kosong, jadi
teknisi tidak bisa menerbitkan angka yang salah. Yang dicegah di sisi HP hal lain:
teknisi menyelesaikan seluruh lembar, mengirim, lalu baru tahu titiknya tidak terbit.

---

## 4. `category_service.dart` — dua entri `CalibrationCapability`, empat pita CMC

Kelompok: **Aliran**. Metode: `SIDIK-IK-CAL-0528_Rev.4; ISO 4185:1980; NIST Special
Publication 250 (Static weighing method)`.

| Alat | Pita | CMC |
|---|---|---|
| Flow Meter Cairan (Totalizer) | 10 – 78 L | 0,76 % of reading |
| | 78 – 1991 L | 1,2 % of reading |
| Flow Meter Cairan (Flowrate) | 75 – 191 Lpm | 0,76 % of reading |
| | 190,6 – 519,4 Lpm | 1,2 % of reading |

> Nomor IK yang dipakai `Rev.4` (angka lampiran akreditasi), sementara kedua workbook
> master memakai `Rev.6` dan kertasnya berjudul *"Perbandingan Langsung dengan UFM"*
> sedangkan lampirannya menyebut *static weighing method*. Selisih itu **sedang
> ditanyakan ke lab** (`docs/pertanyaan-lab-flowmeter.md` §16) — jangan diubah sendiri
> di sisi mobile.

---

## 5. `instrument_picker_screen.dart` — PERIKSA saja

Cabang `n.contains('flow')` sudah ada (`Icons.waves_outlined`). Yang perlu dipastikan:
`'Flow Meter Cairan (Totalizer)'` mendarat di situ dan **bukan** di cabang
`n.contains('meter')` yang lebih pendek dan biasanya diperiksa lebih dulu.

---

## 6. Bentuk lembar kerja — kontrak yang dikirim server

Enam bagian, urutannya mengikat:

```
identitas_alat  →  pemilik  →  usage_check  →  pipa  →  hasil  →  penutup
```

### 6.1 `identitas_alat`

Field yang khas alat ini (sisanya baku):

| Kode | Tipe | Catatan |
|---|---|---|
| `spesifikasi_alat.flowmeter.mode` | pilihan | `totalizer` / `flowrate` |
| `spesifikasi_alat.flowmeter.satuan` | pilihan | Totalizer: `L`, `m3`, `usg`, `ml`, `kg` · Flowrate: `LPM`, `m3/h`, `usg/min`, `m3/min`, `kg/h`, `kg/min` |
| `spesifikasi_alat.flowmeter.kapasitas` | angka | |
| `spesifikasi_alat.flowmeter.resolusi` | angka | |
| `spesifikasi_alat.flowmeter.material_pipa` | teks | dari kertas; **tidak ada di master** |
| `spesifikasi_alat.flowmeter.jenis_fluida` | teks | idem |
| `spesifikasi_alat.flowmeter.path_configuration` | pilihan | `Z` / `V` / `W` — **satu pilihan, bukan tiga centang** |
| `spesifikasi_alat.flowmeter.liner_material` | teks | opsional |
| `spesifikasi_alat.flowmeter.liner_ketebalan_mm` | angka | opsional |

**Satuan berbasis massa (`kg`, `kg/h`, `kg/min`) WAJIB disertai densitas fluida UUT.**
Tanpa densitas, backend **memblokir** titiknya — dan itu bukan kekakuan: master pun
tidak punya faktor konversinya (selnya berisi teks `'perlu dibagi densitas'`, `#REF!`,
atau faktor yang salah dimensi). Tampilkan peringatan di HP begitu satuan massa dipilih
sementara tabel densitas masih kosong.

### 6.2 `pipa` — dua tabel, satu baris, tiga pembacaan

| grup | `offset_kunci` | baris × ulangan | satuan |
|---|---|---|---|
| `pipa_diameter` | 5000 | 1 × 3 | mm |
| `pipa_ketebalan` | 5100 | 1 × 3 | mm |

Keduanya **SELALU mm**, apa pun satuan aliran alatnya — caliper dan thickness gauge
standarnya bersertifikat mm.

### 6.3 `hasil` — LIMA tabel, `tahap` sama, offset berbeda

| grup | `offset_kunci` | baris × ulangan | kolom |
|---|---|---|---|
| `flow_uut_pembacaan` | **0** | 3 × 3 | Totalizer: `pembacaan` · Flowrate: `durasi_1`, `durasi_2`, `durasi_3` |
| `flow_std_pembacaan` | 1000 | 3 × 3 | `pembacaan` |
| `flow_suhu_awal` | 2000 | 3 × 3 | `pembacaan` (°C) |
| `flow_suhu_akhir` | 3000 | 3 × 3 | `pembacaan` (°C) |
| `flow_densitas_uut` | 4000 | 3 × 1 | `pembacaan` (kg/L) — **opsional** |

**`offset_kunci` yang berbeda itu bukan kerapian.** Kelimanya ber-`tahap`
`sesudah_adjustment`, jadi tanpa offset kunci barisnya bertabrakan **di layar**: angka
yang diketik di satu kotak muncul di kotak lain, tanpa satu pun error. Sudah nyata di
lembar Timbangan. Di sini akibatnya pembacaan standar tertimpa pembacaan UUT, dan
**deviasinya jadi nol di seluruh sesi**.

Jangan menurunkan kunci baris dari nomor titik saja — pakai `offset_kunci + nomor`.

### 6.4 Deret UUT Flowrate BERSARANG

Yang dikirim ke `POST /calibrations`:

```jsonc
"flow_uut_pembacaan": [
  [101.255, 101.276, 101.289],   // ulangan 1 -> durasi 20", 40", 60"
  [102.654, 102.625, 102.678],   // ulangan 2
  [101.986, 101.910, 101.945]    // ulangan 3
]
```

Totalizer mengirim bentuk yang sama tapi tiap ulangan berisi **satu** angka:

```jsonc
"flow_uut_pembacaan": [[1002.65], [1004.56], [1006.52]]
```

Deret datar (sembilan angka sekaligus) **tidak sah** — backend menyusunnya ulang jadi
ulangan, dan simpangan bakunya berubah dari sebaran antar-DURASI jadi sebaran
antar-ULANGAN. Angkanya keluar, dan salah.

Deret lain datar biasa:

```jsonc
"flow_std_pembacaan": [1010.885, 1011.52, 1012.215],
"flow_suhu_awal":     [25.5, 25.5, 25.5],
"flow_suhu_akhir":    [25.4, 25.4, 25.4],
"flow_densitas_uut":  []          // kosong = SAH, medianya air
```

Urutan `flow_std_pembacaan` **berpasangan** dengan urutan ulangan UUT — dari selisih
berpasangan itulah komponen pengulangan lahir. Urutan yang tertukar menggeser simpangan
bakunya tanpa satu pun error.

---

## 7. Yang akan dilihat teknisi, dan itu BENAR

Kedua sesi selalu memunculkan peringatan; keduanya bukan bug:

1. **Master belum divalidasi.** `FORM VALIDASI` kolom VALIDATION kosong di kedua
   workbook lab. Peringatan ini muncul di setiap sesi flowmeter sampai lab
   menandatanganinya (`docs/pertanyaan-lab-flowmeter.md` §1).
2. **Jarak ke titik tabel standar > 10 %.** Sertifikat UFM Flowrate cuma punya tiga
   titik (100 / 236 / 507 Lpm) untuk pita 75–519,4 Lpm, dan master memakai pencocokan
   **terdekat** — bukan interpolasi. Bacaan 309,7 Lpm memungut koreksi titik 236,1 Lpm,
   jaraknya 23,8 % (§4).

Keduanya **peringatan**, bukan pemblokir: admin melewatinya lewat `abaikan_peringatan`.

Yang benar-benar **memblokir** satu titik (enam syarat):

1. bacaan standar di luar jangkauan tabel sertifikat UFM;
2. kurang dari dua pembacaan UUT atau standar;
3. pembacaannya tanpa sebaran sama sekali (satu nilai disalin berkali-kali);
4. resolusi kosong;
5. diameter/ketebalan pipa kosong;
6. bacaan UUT di luar **kedua** pita CMC terakreditasi.

Densitas UUT kosong **tidak** memblokir (jatuh ke densitas air pada suhu tercatat) —
kecuali satuannya berbasis massa.

---

## 8. Sertifikat

Empat kolom baku (`Unit Under Test` · `Standard Indication` · `Correction` · `U95% ±`),
plus:

- **`U95` dan `k` per TITIK.** Kalau `k` berbeda antar-titik, kalimat di bawah tabel
  mencetak `Coverage Factor ( k ) ≈` alih-alih `=`. Di sesi contoh Flowrate memang beda:
  2,1009 (titik 1) vs 1,9908 (titik 2).
- **Blok `PIPE SPECIFICATION & SENSOR MOUNTING`** di atas tabel hasil: material pipa,
  jenis fluida, path configuration, diameter luar/dalam, ketebalan, dan liner (liner
  cuma dicetak kalau pipanya memang berpelapis).

`Correction` = `Standard Indication − Unit Under Test`. Tandanya sudah pernah terbalik
sekali di berkas profil dan ketahuan lewat test — jangan "membetulkan"-nya di sisi HP.

---

## 9. Angka acuan buat menguji mock

Sesi contoh ter-seed (pelanggan sintetis; angka ukurnya asli dari master lab):

**`DEMO-FM-TOT-001` — Totalizer, satuan L, resolusi 0,01**

| Titik | UUT rata | Standard | Correction | U95 |
|---|---|---|---|---|
| 1 | 1004,5767 | 998,4880 | −6,0887 | 13,5723 |
| 2 | 1902,7667 | 1883,8760 | −18,8907 | 25,6228 |

**`DEMO-FM-FLW-001` — Flowrate, satuan LPM, resolusi 0,001**

| Titik | UUT rata | Standard | Correction | U95 |
|---|---|---|---|---|
| 1 | 101,9576 | 100,6787 | −1,2789 | 1,9309 |
| 2 | 310,6426 | 306,8280 | −3,8146 | **3,7277** |

> U95 titik 2 Flowrate mendarat di **lantai CMC** (1,2 % × 310,6426). Master menerbitkan
> 3,2512 — yaitu 1,047 %, **di bawah** pita terakreditasi 1,2 %. Lantainya dipasang di
> backend; lihat `docs/pertanyaan-lab-flowmeter.md` §2.

Geometri pipa kedua sesi: diameter luar `50,81 / 50,82 / 50,81` mm, ketebalan
`2,32 / 2,31 / 2,32` mm.

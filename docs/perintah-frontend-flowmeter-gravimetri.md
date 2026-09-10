# Perintah frontend — Flowmeter Gravimetri (ISO 4185)

Dokumen serah-terima untuk `sidik-calibration-mobile`. **Berdiri sendiri**:
tidak perlu membuka kode backend sama sekali.

Yang mendarat di server 10 Sep 2026: **varian metode kedua** untuk alat ke-27
(`flowmeter_totalizer`) dan ke-28 (`flowmeter_flowrate`) — bukan alat baru, bukan
kode profil baru.

---

## 0. Yang berubah dalam satu paragraf

Lembar Flowmeter sekarang punya **sumbu varian metode**. Varian `ufm`
(perbandingan langsung dengan Krohne UFC300) adalah yang selama ini ada; varian
`gravimetri` (penimbangan statis ISO 4185) baru, dan **jadi bawaan untuk sesi
baru**. Kode profilnya tetap dua, nama alatnya tetap sama, endpoint-nya tetap
sama. Yang bercabang: **tabel mana yang tampil di layar**, dan **kunci mana yang
dikirim balik**.

Cabangnya dipilih dari `spesifikasi_alat.flowmeter.varian_metode`, **BUKAN dari
nama alat**. Nama alatnya identik untuk kedua varian — memilih dari nama berarti
selalu memajang cabang yang sama, dan yang salah tidak menghasilkan error, cuma
kolom yang salah terisi.

---

## 1. Bentuk lembar kerja yang dikirim server

`GET /api/calibrations/lembar-kerja?kode=flowmeter_totalizer` (atau
`flowmeter_flowrate`) — bentuknya tidak berubah, tapi isinya bertambah.

### 1.1 Field baru di bagian `identitas_alat`

```json
{
  "kode": "spesifikasi_alat.flowmeter.varian_metode",
  "label": "Metode Kalibrasi",
  "tipe": "pilihan",
  "pilihan": [
    { "nilai": "ufm", "label": "Perbandingan langsung UFM (Krohne UFC300) — master BELUM divalidasi" },
    { "nilai": "gravimetri", "label": "Penimbangan statis gravimetri (ISO 4185)" }
  ],
  "tampil_kalau": null
}
```

```json
{
  "kode": "spesifikasi_alat.flowmeter.kode_timbangan",
  "label": "Timbangan Standar",
  "tipe": "pilihan",
  "pilihan": [
    { "nilai": 1, "label": "1 — Dini Argeo DFWLB-3 (U95 0.52 kg)" },
    { "nilai": 2, "label": "2 — Sartorius 150GF (U95 0.033 kg)" },
    { "nilai": 3, "label": "3 — Mettler DJ Series (U95 0.00017 kg)" },
    { "nilai": 4, "label": "4 — Fujitsu FSR-A (U95 1.6e-05 kg)" }
  ],
  "tampil_kalau": {
    "kode": "spesifikasi_alat.flowmeter.varian_metode",
    "nilai": ["gravimetri"]
  }
}
```

> Daftar timbangannya **diturunkan server dari tabel standar**, dan isinya BEDA
> antar-mode: `flowmeter_totalizer` punya empat, `flowmeter_flowrate` cuma tiga
> (Fujitsu tidak ada di workbook Flowrate). **Jangan menyalin daftar ini ke sisi
> HP.** Peta kode-ke-nama yang disalin selalu ketinggalan begitu lab menambah
> timbangan, dan gagalnya diam: layar memajang kode mentah, atau lebih buruk,
> memajang nama yang salah.

### 1.2 Tabel di bagian `hasil` — sekarang ber-`tampil_kalau`

Tiap tabel di blok pengukuran boleh punya kunci `tampil_kalau` dengan bentuk
yang **sama persis** dengan yang dipakai `field`:
`{"kode": "<kode field lain>", "nilai": ["<nilai yang membuatnya tampil>", ...]}`.
`nilai` selalu DAFTAR, bukan skalar — varian ketiga tinggal menambah anggotanya.
Tabel tanpa kunci itu tampil di kedua varian.

| Tabel (`grup`) | Varian | Satuan | Bentuk | Offset kunci |
|---|---|---|---|---|
| `flow_uut_pembacaan` | keduanya | satuan alat | Totalizer 1 kolom, Flowrate 3 kolom durasi | 0 |
| `flow_std_pembacaan` | **ufm** | satuan alat | 1 kolom × 3 ulangan | 1000 |
| `flow_suhu_awal` | keduanya | °C | 1 kolom × 3 ulangan | 2000 |
| `flow_suhu_akhir` | keduanya | °C | 1 kolom × 3 ulangan | 3000 |
| `flow_densitas_uut` | **ufm** | kg/L | 1 kolom × 1 | 4000 |
| `pipa_diameter` / `pipa_ketebalan` | **ufm** | mm | 1 kolom × 3 | 5000 / 5100 |
| `flow_berat_isi` | **gravimetri** | **kg** | 1 kolom × 3 ulangan | 6000 |
| `flow_berat_kosong` | **gravimetri** | **kg** | 1 kolom × 3 ulangan | 7000 |
| `flow_waktu_menit` | **gravimetri**, hanya Flowrate | **menit** | 1 kolom × 3 ulangan | 8000 |

**Offset kunci wajib dipakai apa adanya.** Kesembilan tabel ber-`tahap` sama
(`sesudah_adjustment`), jadi kunci baris yang bertabrakan memindahkan angka
antar-tabel tanpa satu pun error — angka yang diketik di kotak berat isi muncul
di kotak suhu. Ini sudah terjadi di Timbangan dan Height Gauge.

---

## 2. Bentuk payload yang diharapkan balik

Tidak ada endpoint baru. `POST /api/calibrations` dan `PUT` draft-nya menerima
bentuk yang sama; yang berubah isi `measurements[]` dan blok sesi.

### 2.1 Blok sesi

```json
"spesifikasi_alat": {
  "rentang_ukur": "140-2500",
  "kapasitas": "9999",
  "resolusi": "0.01",
  "satuan": "L",
  "flowmeter": {
    "mode": "totalizer",
    "varian_metode": "gravimetri",
    "kode_timbangan": 1,
    "satuan": "L",
    "kapasitas": 9999.0,
    "resolusi": 0.01,
    "volume_pipa_l": 0.10129012,
    "diameter_pipa_mm": [],
    "ketebalan_pipa_mm": [],
    "material_pipa": "Carbon Steel",
    "jenis_fluida": "Air",
    "path_configuration": null,
    "liner_material": null,
    "liner_ketebalan_mm": null
  }
}
```

### 2.2 `measurements[]` — contoh satu titik gravimetri Totalizer

```json
{
  "titik_ke": 1,
  "tahap": "sesudah_adjustment",
  "flow_uut_pembacaan": [[140.11], [140.12], [140.09]],
  "flow_berat_isi":    [139.9, 139.8, 139.9],
  "flow_berat_kosong": [0.0, 0.0, 0.0],
  "flow_suhu_awal":    [25.5, 25.5, 25.5],
  "flow_suhu_akhir":   [25.4, 25.4, 25.4]
}
```

Flowrate menambah `flow_waktu_menit` dan UUT-nya **bersarang** (ulangan → durasi):

```json
{
  "titik_ke": 1,
  "flow_uut_pembacaan": [
    [0.1221, 0.1219, 0.1220],
    [0.1221, 0.1219, 0.1220],
    [0.1221, 0.1219, 0.1220]
  ],
  "flow_berat_isi":    [2.0205, 2.0207, 2.0206],
  "flow_berat_kosong": [0.0, 0.0, 0.0],
  "flow_waktu_menit":  [1.001, 1.0, 1.0],
  "flow_suhu_awal":    [26.5, 26.5, 26.5],
  "flow_suhu_akhir":   [26.5, 26.6, 26.6]
}
```

**Deret UUT Flowrate JANGAN diratakan.** Bentuk bersarang (ulangan → durasi)
yang menentukan bagaimana simpangan baku dihitung; diratakan jadi sembilan angka
datar, hasilnya keluar jauh lebih besar dan tetap terlihat masuk akal.

Kirim **nilai MENTAH** dalam satuan yang diketik teknisi. Konversi terjadi di
server, di tempat pakai. Mengalikan di HP membuat draft tidak idempoten —
faktornya berlipat tiap kali draft disimpan ulang, dan itu sudah terjadi di
Micrometer.

---

## 3. Kode isian yang MENENTUKAN ANGKA

Yang kalau kelupaan diisi membuat hasilnya salah **tanpa satu pun error**:

| Kode | Kalau kosong | Seberapa besar angkanya bergeser |
|---|---|---|
| `spesifikasi_alat.flowmeter.varian_metode` | jatuh ke `ufm` (perlakuan sesi lama) | seluruh rantai hitung berganti: 9/11 komponen jadi 8/9, penimbangan jadi pembacaan totalizer standar. Angkanya **tidak** error — dia keluar dari metode yang tidak dipakai teknisi |
| `spesifikasi_alat.flowmeter.kode_timbangan` | **titik diblokir** dengan alasan yang kebaca | — (server menahan, tidak menebak) |
| `spesifikasi_alat.flowmeter.mode` | seluruh titik "belum dihitung" | — |
| `spesifikasi_alat.flowmeter.resolusi` | **titik diblokir** | komponen resolusi bernilai nol → U95 lebih KECIL |
| `flow_berat_kosong` | dianggap 0 kg | massa bersih kelebihan berat wadah. Wadah 12,5 kg pada isi 139,87 kg = **8,9 % kelebihan** |
| `flow_waktu_menit` (Flowrate) | **titik diblokir** | — |
| `flow_suhu_awal` / `flow_suhu_akhir` | **titik diblokir** | densitas air adalah PEMBAGI hasil akhir |

Nilai `varian_metode` yang **ada tapi salah ketik** (`gravimetrik`) memblokir
seluruh sesi dengan alasan yang kebaca — dia tidak jatuh ke bawaan.

---

## 4. Kunci baris & bukti tidak ada tabrakan

Kunci baris tiap sel: `{grup}|{titik_ke}|{pembacaan_ke}|{kode_kolom}`, dengan
`offset_kunci` tabel ditambahkan ke nomor barisnya di sisi lembar cetak.

Kesembilan tabel blok `hasil` ber-`tahap` sama. Offset yang dipakai:
`0, 1000, 2000, 3000, 4000, 5000, 5100, 6000, 7000, 8000` — sepuluh nilai
berbeda, jarak minimum 100, dan tabel terbesar (`flow_uut_pembacaan` Flowrate)
memakai 3 ulangan × 3 durasi = 9 baris. Tidak ada dua tabel yang rentang
kuncinya bersinggungan.

Yang menjaga kesepakatan ini di sisi server:
`CetakLembarKerjaOcrTest::test_kunci_sel_di_kertas_sama_persis_dengan_yang_dikenal_server`
(63 sel Totalizer, 90 sel Flowrate).

---

## 5. Yang SENGAJA menyimpang dari formulir kertas

- **Kotak `Empty Container Weight` DIPUNGUT** walau di seluruh sesi master
  bernilai nol. Kertasnya punya bloknya; masternya tidak pernah memakainya.
  Tanpa kotak ini, sesi pertama yang benar-benar memakai wadah tidak punya
  tempat menuliskannya dan teknisi akan menulis berat kotor ke kolom isi.
- **Kotak densitas fluida UUT TIDAK muncul di varian gravimetri.** Fluidanya air
  dan densitasnya sudah diukur piknometer di lab. Kotak yang tetap muncul akan
  diisi orang, dan angkanya tidak akan dibaca siapa pun.
- **Kotak geometri pipa & path configuration TIDAK muncul di varian
  gravimetri.** Varian ini tidak punya komponen `u_A`.
- **Nomor formulir cetaknya BEDA, dan sudah dipisah di server.** Kertasnya tiga
  berkas, semuanya ada di `Project-PT-Sidik/worksheet_alat_calibration/`:

  | Varian | Mode | Nomor formulir |
  |---|---|---|
  | `ufm` | Totalizer & Flowrate (satu kertas, kotak modenya dicentang) | `SIDIK-FM-CAL-0538_Rev.0` |
  | `gravimetri` | Totalizer | `SIDIK-FM-CAL-0538.B_Rev.3` |
  | `gravimetri` | Flowrate | `SIDIK-FM-CAL-0538.A_Rev.3` |

  Server mengirimnya di `kode_dokumen_varian` (peta varian → nomor), dan
  `kode_dokumen` berisi nomor varian BAWAAN. **Jangan menyalin tabel ini ke sisi
  HP** — ambil dari respons. Kertas gravimetri revisinya tiga tingkat di depan
  kertas UFM, dan lab merevisi kertas lebih sering daripada aplikasi rilis.

---

## 6. Keadaan sisi HP per 10 Sep 2026 — dan apa yang tersisa

**Koreksi atas versi pertama dokumen ini.** Bagian ini semula menulis bahwa repo
mobile "belum punya penjaga sapuan registry" dan bahwa `flowmeter_lembar_test.dart`
"belum ada sama sekali". **Dua-duanya sudah tidak benar** — keduanya mendarat
8–9 Sep 2026, sebelum dokumen ini ditulis. Klaimnya diwarisi dari brief pekerjaan
ini tanpa diadu ke repo mobile. Diperiksa ulang:

| Yang sudah ADA | Berkas |
|---|---|
| Sapuan mock seluruh kode profil | `test/bentuk_mock_semua_profil_test.dart` — menjaga daftarnya tidak menyusut, tiap profil server punya bentuk mock sendiri, dan tiap entri utang menyebut alasannya |
| Test lembar Flowmeter | `test/flowmeter_lembar_test.dart` |
| Cabang lembar Flowmeter | `lembar_kerja_service.dart:305-308` — `flowmeter_totalizer` / `flowmeter_flowrate` |

**Yang SUDAH dikerjakan 10 Sep 2026** (satu berkas, di repo mobile):

`lembar_kerja_state.dart` `_kodePenentuAngka` bertambah dua kunci —
`spesifikasi_alat.flowmeter.varian_metode` dan `.kode_timbangan`. Itu yang paling
menentukan dari ketujuh kode Flowmeter, karena varian memilih **rantai
hitungnya**, bukan satu angka. Tanpa ini, teknisi menyelesaikan seluruh lembar,
mengirim, lalu baru tahu titiknya tidak terbit — dan saat itu dia sudah tidak di
depan alatnya.

**Yang TERSISA di sisi HP:**

1. `contoh_lembar_kerja_aliran.dart` — mock-nya belum memuat satu pun kunci baru
   (`varian_metode`, `kode_timbangan`, `flow_berat_isi`, `flow_berat_kosong`,
   `flow_waktu_menit`, `kode_dokumen_varian`, `tampil_kalau` pada tabel).
   **Digenerate** dari respons server sungguhan
   (`docs/skrip/gen-contoh-lembar-kerja.php`), bukan disusun tangan.
2. `lembar_kerja_service.dart` — cabang varian dipilih dari
   `spesifikasi_alat.flowmeter.varian_metode`, bukan dari nama alat. Nama alatnya
   IDENTIK untuk kedua varian; memilih dari nama berarti selalu memajang cabang
   yang sama.
3. Penyaring tabel ber-`tampil_kalau` — tabel yang syaratnya tidak terpenuhi
   tidak digambar, dan yang lebih penting: **tidak ikut terkirim** di
   `measurements[]`. Tabel gravimetri yang ikut terkirim di sesi UFM mengisi
   kolom yang tidak dibaca siapa pun.
4. Nomor formulir cetak diambil dari `kode_dokumen_varian`, bukan `kode_dokumen`
   saja — lihat §1.1 dan §5.

## 7. Yang BELUM dikerjakan, dan kenapa

Ditulis terang supaya tidak diam-diam dianggap selesai:

- **Dua kotak di kertas `Rev.3` belum punya tempat simpan.** Kertas Totalizer
  `0538.B` punya baris **`Set Point UUT ( )`** dan **`Floware ( )`** yang belum
  dipungut server. `Floware` itu blok `Flowrate on Software (kg/s)` di master —
  kosong di seluruh sesi, dan sel sertifikat yang membacanya memulangkan `0`
  (pertanyaan lab §7). Kotaknya sengaja BELUM dibuat: menaruh kotak yang isinya
  tidak dibaca siapa pun justru bikin teknisi mengira angkanya terpakai. Dibuka
  begitu lab menjawab §7.
- **Kotak `Berat Wadah Kosong` ADA di server tapi TIDAK ada di kertas `Rev.3`.**
  Sengaja: blok `Empty Container Weight` ada di master (`INPUT DATA!D41:H43`),
  bernilai nol di seluruh sesi, jadi jalur pengurangannya nol kali teruji. Tanpa
  kotaknya, sesi pertama yang benar-benar memakai wadah akan menuliskan berat
  KOTOR ke kolom isi — dan massanya kelebihan 8,9 % tanpa satu pun error.
  Pertanyaan lab §11.
- **Koordinat geometri OCR masih TEBAKAN.** `flowmeter_totalizer-v1.json` dan
  `flowmeter_flowrate-v1.json` sudah diregenerasi (63 dan 90 sel), tapi
  `terverifikasi` masih `false` — koordinatnya harus diukur dari formulir cetak
  asli dulu. Sampai itu, jalur pindai kamera untuk alat ini tetap mati.
- **Sesi Flowrate gravimetri tidak bisa terbit sama sekali** sampai lab menjawab
  `docs/pertanyaan-lab-flowmeter-gravimetri.md` §2 — rentang yang diukur
  (2–10 Lpm) ada di luar pita akreditasi yang mulai 75 Lpm. Layar HP boleh
  merekamnya; server yang menahan penerbitannya, dengan alasan yang kebaca.
- **`volume_pipa_l` disimpan tapi tidak dihitung** — pertanyaan lab §5.

---

## 8. Cara mengeceknya tanpa membaca kode server

```bash
# bentuk lembar kedua varian
php artisan test --filter=SemuaProfilLembarKerjaTest

# angka gravimetri diadu ke master, sel demi sel
php artisan test --filter=FlowmeterGravimetriMasterTest

# gerbang penerbitan + arah tiap perbaikan
php artisan test --filter=FlowmeterGravimetriGerbangTest

# sesi varian UFM lama tidak bergeser
php artisan test --filter=FlowmeterVarianTest
```

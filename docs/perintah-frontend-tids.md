# TIDS — lembar kerja pindah ke jalur PASANGAN (28 Agt 2026)

Dokumen serah-terima, berdiri sendiri. Boleh ditempel utuh ke sesi kerja frontend/mobile
terpisah — tidak perlu membaca percakapan backend.

**Yang berubah, dalam satu kalimat:** lembar **Temperatur Indikator dengan Sensor** (TIDS,
`SIDIK-FM-CAL-0506 Rev.4`) berhenti memakai deret datar `measurements[].pembacaan` dan pindah ke
bentuk **dua deret** `standar` / `uut` — bentuk yang sama dengan Thermocouple, Termometer Gelas,
dan Thermohygrometer.

**Kenapa sekarang:** dua workbook master TIDS akhirnya turun dari lab. Budget ketidakpastiannya
sudah jalan (`TidsCalculator`, cocok sampai digit terakhir dengan dua sesi contoh master), dan
blokir U95 yang berdiri sejak profil ini lahir sudah dicabut.

---

## 1 · Yang berubah di `GET /api/calibrations/lembar-kerja?profil=tids`

| Kunci | Sebelum | Sesudah |
|---|---|---|
| `bagian[hasil].tabel[0].peran` | — | `standar` |
| `bagian[hasil].tabel[1].peran` | — | `uut` |
| `tabel[0].simpan_ke` | **`null`** | `measurements[].standar` |
| `tabel[1].simpan_ke` | `measurements[].pembacaan` | `measurements[].uut` |
| `tabel[0].kolom_baris` | — | `[{kode: 'no_probe', label: 'No. Termokopel', tipe: 'pilihan', pilihan: 27 nomor digrup per tipe sensor}]` |
| `bagian[titik_es].field[].kode` | `spesifikasi_alat.titik_es_awal` / `_akhir` | `titik_es_1` / `titik_es_2` |
| `bagian[usage_check].baris` | 2 baris | **3 baris** (+ Temperature Recorder Graptech GL840) |
| `bagian[usage_check].field[]` | — | + `tipe_sensor` (pilihan `RTD`/`Type K`/`Type N`) |
| `budget_ketidakpastian.tersedia` | `false` | **`true`** |
| `budget_ketidakpastian.butuh` | daftar 4 bahan yang kurang | **kunci dihapus** |
| `sumbu_uut.keputusan_skema` | `belum_diambil` | **`lima_ulangan`** |
| `sumbu_uut.daftar[].label_master` | — | `PRT1`…`PRT5` |

`tabel[].grup` **tidak dikirim sama sekali** untuk lembar ini — kuncinya HILANG dari JSON-nya,
bukan ada berisi `null`. Bedanya penting buat yang membaca: `'grup' in tabel` pulang `false`, jadi
pakai jalur cadangan kunci-hilang, bukan pemeriksaan `=== null`. Beda dari tiga lembar pasangan
lain yang mengisinya `standar`/`uut`. Itu disengaja: identitas tabel TIDS dipegang `tahap`
(`pembacaan_standard` / `pembacaan_uut`), dan kunci sel berkas geometri OCR-nya yang **kertasnya
sudah tercetak** dibangun dari situ. `LembarKerja.kunciTabel` di HP jatuh ke `peran` waktu `grup`
null, dan itu tetap unik — tidak ada yang perlu diubah.

## 2 · Yang dikirim `POST /api/calibrations`

```json
{
  "equipment_id": 31,
  "standard_id": 46,
  "tipe_sensor": "Type K",
  "alat_bantu": "A",
  "titik_es": [0.2, 0.4],
  "measurements": [
    { "titik_ukur": 60,  "no_probe": 2, "standar": [60.1, 60.1, 60.1, 60.2, 60.2], "uut": [60.3, 60.34, 60.45, 60.47, 60.41] },
    { "titik_ukur": 100, "no_probe": 3, "standar": [92.8, 93.4, 94.5, 94.6, 94.6], "uut": [92.29, 92.85, 94.05, 94.09, 94.12] }
  ]
}
```

Tiga field sesi menentukan ANGKA, bukan catatan — kosong berarti seluruh titiknya ditahan dengan
alasan yang kebaca di `belum_dihitung`:

| Field | Isi | Kalau kosong |
|---|---|---|
| `tipe_sensor` | `RTD` · `Type K` · `Type N` | seluruh sesi ditahan |
| `alat_bantu` | `A` (Isotech) · `B` (Techne) | seluruh sesi ditahan |
| `measurements[].no_probe` | Type K → 1–16 · Type N → **3–12** · RTD → 17 | titik itu diblokir |
| `titik_es` | 2 angka (Awal & Akhir) | komponen `Drift UUT` jadi nol + peringatan sesi |
| `standard_id` | salah satu dari 3 baris `Standard used` | seluruh sesi ditahan |

**Penomoran `no_probe` beda per tipe**, dan itu dari kertasnya sendiri: *"If using Thermocouple
Type N, No. Thermocouple START FROM 3. If using PRT PT100 (RTD), No. Thermocouple ALL 17."*
**PRT PT100 + Temperature Recorder tidak punya tabel koreksi sama sekali** — kombinasi itu
diblokir server dengan alasan yang menyebut kenapa.

## 3 · Kompatibilitas payload lama — sudah ditangani server

APK yang belum diperbarui mengirim `measurements[].pembacaan` (deret UUT) tanpa `standar`/`uut`.
Server **memindahkannya ke deret `uut`**, jadi kerja lapangannya tetap tersimpan utuh. Yang
tetap hilang deret standarnya — memang tidak pernah dikirim — dan tanpa itu sesinya tidak
kehitung. Jadi rilis mobile ini bukan opsional untuk lembar TIDS.

Peta `spesifikasi_alat` lama (`dryblock`, `sensor_standar`, `titik_es_awal`/`_akhir`) juga masih
DIBACA sebagai cadangan, supaya sesi yang sudah telanjur tersimpan tetap bisa dihitung ulang.

## 4 · Lima kolom itu lima ULANGAN, bukan lima UUT

Ini koreksi tafsir, dan datangnya dari workbook master. Kepala kolom di kertas berbunyi
`0" (UUT1)`…`90" (UUT5)` dan selama ini dibaca sebagai LIMA ALAT dalam satu lembar. Dua workbook
menamai kolom yang sama `PRT1`…`PRT5` lalu memakainya `AVERAGE` + `STDEV` **per baris**:

```
satu baris  = satu set point
lima kolom  = lima ULANGAN, standar & UUT dibaca bergantian tiap 10 detik
0″ standar · 10″ UUT · 20″ standar · 30″ UUT · … · 80″ standar · 90″ UUT
```

**Label cetaknya TIDAK diubah** (`0" (UUT1)` tetap): itu yang tertulis di kertas yang dipegang
teknisi, dan itu juga satu-satunya jangkar sumbu mendatar jalur foto — TIDS tidak mencetak `Xn`
maupun nomor polos. Yang berubah artinya, dan artinya dikirim di `sumbu_uut`.

## 5 · Peringatan sesi baru yang WAJIB ditampilkan

| Kode | Kapan | Kenapa penting |
|---|---|---|
| `tids_tipe_sensor_kosong` | tipe sensor standar belum dipilih | sesinya tidak kehitung sama sekali |
| `tids_dryblock_kosong` | dryblock belum dicentang | idem |
| `tids_titik_es_kosong` | uji titik es belum lengkap | komponen `Drift UUT` jadi nol, dan nol di situ artinya "alatnya tidak drift sama sekali" |
| `tids_master_recorder_sel_tetap` | standar sesi = Recorder | tiga angka budget diambil dari sel tetap workbook, bukan dari tabelnya |
| `tids_master_tiga_komponen_tidak_dijumlah` | standar sesi = Constant/Yokogawa | workbook menjumlah 9 dari 12 komponen — U95-nya lebih KECIL |
| `tids_titik_luar_cmc` | set point di luar −20…600 °C | lab belum mengklaimnya di lampiran akreditasi |

Dua yang terakhir di baris `tids_master_*` bukan bug aplikasi: itu penyimpangan workbook master
yang **sengaja ditiru** supaya angkanya cocok dengan sertifikat yang sudah diserahkan ke
pelanggan. Rinciannya di `docs/pertanyaan-lab-tids-workbook.md`; yang memutuskan manajer teknis
lab. Peringatan ini yang menahan tombol APPROVE sampai ada manusia yang membacanya.

## 6 · Yang SUDAH dikerjakan di repo mobile

- `lib/services/contoh_lembar_kerja_suhu.dart` — bentuk mock TIDS (`contohBentukLembarKerjaTids`),
  disalin apa adanya dari respons server. Sebelum ini `MockLembarKerjaService` tidak punya bentuk
  TIDS sama sekali dan diam-diam jatuh ke bentuk pH.
- `lib/services/lembar_kerja_service.dart` — cabang `'tids'` di `MockLembarKerjaService`.
- `test/fixtures/lembar-kerja-tids.json` + `test/tids_lembar_pasangan_test.dart` — 9 test.
- **`lib/screens/calibration/lembar_kerja_state.dart` — satu bug diperbaiki.**
  `toSubmissionPasangan()` mengirim `titikUkur` mentah, bukan `titikUkurEfektif`. Untuk tiga
  lembar pasangan pertama keduanya selalu sama (set point-nya tercetak di kertas). Lembar TIDS
  tidak: kertasnya mencetak tujuh baris Setpoint KOSONG, dan `titikUkur` di situ cuma nomor
  barisnya (1..7). Dibiarkan, tiap sesi TIDS terkirim dengan set point 1, 2, 3… — angkanya
  lengkap, `Correction` terbit, dan yang salah cuma titik yang diklaim sertifikat.

`flutter test` 1240 hijau, `flutter analyze` bersih.

## 7 · Yang MASIH perlu dicek di layar (belum diuji lewat UI)

- Kotak **No. Termokopel** per baris tabel standar. Daftarnya sudah dikirim lengkap (27 pilihan,
  digrup `RTD` / `Type K` / `Type N` — dijaga dua test, satu di tiap repo), tapi `_BarisNoProbe`
  belum pernah digambar untuk lembar bertabel **tujuh baris Setpoint KOSONG**. Yang perlu dilihat
  di layar: label barisnya (`Set point 1`…`Set point 7`) muat di kotak selebar 96 px.
- Dropdown **Sensor Standard** sekarang berkode `tipe_sensor` (kolom sesi), bukan
  `spesifikasi_alat.sensor_standar`. Kunci lamanya masih dikirim dengan label
  `Sensor Standard (lama)` supaya APK terpasang tidak kehilangan kotaknya — **layar baru
  sebaiknya menyembunyikan yang berlabel `(lama)`**.
- Panel hasil: `budget_ketidakpastian.tersedia` sekarang `true`, jadi tampilan "budget belum ada"
  untuk TIDS harus berhenti muncul.

## 8 · Desimal cetak — dibaca dari respons, jangan dihitung sendiri

Diambil dari format sel `SERTIFIKAT` kedua workbook, dan tiga di antaranya **tidak** mengikuti
resolusi alat:

| Yang dicetak | Desimal | Sel master |
|---|---|---|
| Standard / UUT / Correction | ikut resolusi alat (aturan umum) | `E20:L33` — `0.00` di workbook Recorder, `0.0` di Yokogawa, bergeser bareng resolusi UUT-nya |
| `U95 ±` | **1** | `L34` — `0.0` di kedua workbook, termasuk yang kolom hasilnya dua desimal |
| `k` | **0** | `O35` — jadi `k = 1,99` tercetak `2` |
| Suhu ruang | **1** | `P14` |
| Kelembaban | **0** | `P15` — beda dari suhu di baris tepat di atasnya |

Server sudah mengirimkannya (`desimal_u95` dan kawan-kawan di respons sesi). Jangan menurunkan
sendiri dari resolusi alat: untuk sesi ber-UUT resolusi 0,01 itu akan mencetak `1,62` di baris
yang master-nya menulis `1,6`.

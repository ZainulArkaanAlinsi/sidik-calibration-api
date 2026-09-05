---
name: sidik-alat-baru-dari-master
description: Playbook menambah JENIS ALAT baru ke sidik-calibration-api dari workbook master Excel lab (biasanya ber-password) — bongkar workbook, buktikan rumusnya sebelum menulis PHP, profil + registry, sapuan test yang pasti merah, dan pertanyaan lab. Pakai saat user mengirim master olah data alat baru atau bilang "ada alat baru lagi".
---

# Menambah alat baru dari workbook master lab

Dipakai tiap kali pemilik proyek mengirim satu atau lebih **master olah data**
(`.xlsm`, hampir selalu ber-password) dan bilang *"ada alat baru"*. Sudah
dijalani untuk alat ke-18…21; urutan di bawah yang membuatnya tidak meleset.

**Aturan induknya satu: BUKTIKAN rumusnya sebelum menulis satu baris PHP.**
Menulis profil dulu lalu "menyetel sampai cocok" selalu berakhir dengan angka
yang cocok di sesi contoh dan salah di sesi berikutnya.

## 1. Bongkar workbook — jangan baca lewat ringkasan

```bash
python3 -m venv venv && ./venv/bin/pip install msoffcrypto-tool openpyxl
```

Sistem `cryptography` Debian sering bentrok (`_cffi_backend`); **venv wajib**,
jangan `pip install` ke sistem.

Dekripsi (password ada di pesan user), lalu dump TIAP sheet ke CSV dengan
**nilai DAN rumusnya berdampingan** — `openpyxl` dua kali, `data_only=True`
dan `False`. Rumus yang tidak ikut terbaca = penyimpangan yang tidak ketahuan.

Yang wajib dicari sebelum apa pun:

- **`wb.defined_names`** — nama rentang (`Tabel_F1`, `CMC_AT`) menunjukkan
  tabel mana yang dipakai VLOOKUP mana.
- **Tautan luar `[n]`** (`wb._external_links`). Rumus ber-`'[3]Sheet'!A1`
  membaca **workbook lain** lewat cache yang bisa basi. Sudah dua kali
  menggigit repo ini (Yokogawa Thermocouple, `Weight Standard` Timbangan
  titik 9). Jangan pernah ditiru — hitung dari berkasnya sendiri, lalu
  laporkan.
- **Sheet `Sekilas Info`/sejenis** — biasanya menyebut acuan metodenya
  (mis. *NMI Monograph 4 (CSIRO 2010)*). Itu yang menentukan ada berapa
  ketidakpastian yang diterbitkan.

## 2. Buktikan rumusnya di Python dulu

Tulis reimplementasi kecil, lalu adu ke workbook **sel demi sel**: tiap kolom
turunan, tiap `ui`, tiap `vi`, `uc`, `veff`, `k`, `U`. Toleransi 5·10⁻⁶.

Yang membuat langkah ini murah: `App\Services\GumCalculator::agregasiBudget()`
sudah ada dan sudah terbukti cocok dengan `TINV(0,05; veff)` Excel — termasuk
pemotongan `veff` ke bawah. **Jangan bikin mesin agregasi kedua.**

Kalau ada N workbook, adu ketiganya. Beda antar-workbook itu temuan, bukan
gangguan — lihat §5.

## 3. Baru tulis PHP

Urutan berkas yang sudah terbukti:

| Berkas | Isi |
|---|---|
| `database/data/tabel-standar-<alat>.json` | tabel master, **digenerate skrip**, jangan diketik |
| `app/Services/Calibration/TabelStandar<Alat>.php` | pembaca tabel + penjagaan "tidak ketemu = null" |
| `app/Services/Calibration/<Alat>Calculator.php` | mesin hitung |
| `app/Services/Calibration/Profiles/<Alat>Profile.php` | bentuk lembar + `hitungPerGrup()` |
| `CalibrationProfileRegistry::daftarProfil()` | satu baris |
| `app/Support/<Alat>Mentah.php` | susun ulang baris mentah buat hitung ulang |
| `database/seeders/<Alat>Seeder.php` | sesi contoh, angkanya DIHITUNG bukan ditempel |

### Jebakan yang sudah menggigit

- **Lingkaran konstruktor.** `new <Alat>Calculator` di parameter bawaan
  konstruktor profil → `GumCalculator` → `CalibrationProfileRegistry` →
  profil itu lagi. Gejalanya `Maximum call stack size … Infinite recursion?`
  jauh dari penyebabnya. **Selalu malas:** `$this->kalk ??= new …`.
- **`hitungPerGrup()` wajib memulangkan kolom NYATA**
  `uncertainty_calculations`. Tidak ada kolom `rincian`; yang JSON cuma
  `type_b_components`. `jumlah_pengulangan`, `type_b`, dan `keputusan` tidak
  boleh hilang.
- **Kolom yang tidak ada di `equipments`.** Tidak ada `kapasitas` maupun
  `rentang_ukur` — yang ada `range_min`/`range_max`. Kapasitas hidup di
  `calibration_sessions.spesifikasi_alat`.
- **Blok tingkat-SESI jangan dipaksa jadi `titik_ke`.** Jalur hitung ulang
  mengelompokkan per `titik_ke`; blok tanpa titik (keterulangan,
  eksentrisitas) yang diberi `titik_ke = 0` lahir sebagai titik hantu yang
  selalu gagal. Taruh di `spesifikasi_alat`.
- **Hitung ulang: SELALU tulis `<Alat>Mentah` bareng profilnya.** Pola ini
  sudah menggigit tujuh kali (Viscometer, Gas Detector, TITS, Enclosure, tiga
  alat suhu, Timbangan). Wire ke DUA tempat: `CalibrationValidator` dan
  `HitungUlangSesi`, dan di perintah itu **cek blok alat baru DULUAN** —
  `GridSensorMentah::dari()` balik `[]` cuma kalau tidak ada `peran_sensor`
  sama sekali, dan kosakata baru tetap punya `peran_sensor`.

## 4. Sapuan test yang PASTI merah — siapkan jawabannya

Menambah satu profil membuat delapan sapuan registry ikut menguji alat baru.
Yang pasti merah dan apa yang benar dilakukan:

| Test | Yang diminta |
|---|---|
| `CmcSemuaProfilTest` | `namaAlatKemampuan()` **persis** nama lampiran akreditasi, kurungnya ikut. Nama pendek masuk `aliasNama()` |
| `SemuaProfilLembarKerjaTest` | urutan bagian `identitas_alat` > `pemilik` > `usage_check` > pengukuran > `penutup`; kotak lokasi; nomor formulir (atau masuk `belumAdaKertasnya` **dengan bukti sapuan `SIDIK-FM-` di workbook**) |
| `ThermohygroSemuaLembarTest` | dropdown `thermohygro_standard_id` terisi, disaring ke unit yang tercetak di masternya — bukan semua `parameter_kondisi` |
| `StandarTidakBocorAntarLabTest` | baris standar tertaut lewat `masterStandarTertaut()`, **jangan query sendiri** |
| `CetakLembarKerjaOcrTest` | wajib ada `database/ocr-templates/<kode>-v1.json`. Digenerate `ocr:rangka-geometri`, dan itu **cuma menghasilkan sel kalau bentuk lembarnya memakai `tabel`**, bukan field datar |
| `PerintahUjiKesiapanTest` | butuh alat contoh ter-seed yang `nama_alat_kemampuan`-nya sama persis |
| `ProfilDariNamaAlatTest` | cabut nama alat dari daftar `namaGenerik`, dan tambahkan test arah sebaliknya |
| `HitungUlangSemuaSesiTest` | sesi ter-seed wajib `hitung_ulang_gagal/beda/keputusan_beda = 0` |

> **`EquipmentFactory` juga.** Daftar `nama_alat` acaknya WAJIB tidak memuat
> nama yang sekarang punya profil. Waktu `TimbanganProfile` lahir, `'Timbangan'`
> masih di daftar itu — satu dari empat fixture acak mendarat di lembar
> Timbangan, dan yang merah bergantian tiap jalan: Sertifikat, Masa Berlaku,
> Pembacaan Mustahil. Bukan test alat barunya.

Perintah artisan tidak bisa dijalankan langsung (tidak ada DB di sesi kerja) —
bungkus dalam test sekali pakai ber-`RefreshDatabase`.

## 5. Penyimpangan master: tiru, JANGAN betulkan diam-diam

Master lab hampir selalu memuat kejanggalan. Aturannya:

- **Kejanggalan METODE** (pembagi yang salah label, `ci` tanpa keterangan,
  keterulangan dibagi √2 alih-alih √n) → **ditiru**, ditulis terang di
  docblock, dan diangkat sebagai pertanyaan bernomor di
  `docs/pertanyaan-lab-<alat>.md`. Yang memutuskan manajer teknis lab.
- **Kerusakan SALIN-TEMPEL** (rujukan sel meleset tiga baris, tautan luar ke
  workbook lain) → **dihitung benar**, selisihnya ditulis, dan test menegakkan
  arahnya (hitungan kita harus lebih BESAR, bukan sekadar berbeda).
- **Sel kosong dibaca nol** (`IFERROR(…,"")` yang ikut dijumlah) → **tidak
  pernah ditiru.** Blokir titiknya dengan alasan yang kebaca.

Beda antar-workbook untuk hal yang sama (mis. tiga snapshot sertifikat standar
untuk keping fisik yang sama) **disimpan ketiganya**, dipilih per sesi. Memilih
satu sebagai "yang benar" berarti diam-diam menggeser angka yang sudah tercetak
di sertifikat pelanggan.

## 6. Jangan percaya bacaan tabel yang terpotong

Tabel lampiran akreditasi di `docs/Rekap-Data-Kemampuan-Kalibrasi.md` panjang
dan barisnya menyambung dengan kolom kosong. Membacanya sebagian pernah
menghasilkan kesimpulan "sembilan pita CMC di luar akreditasi" yang **salah** —
dan peringatan sesi palsu melatih admin menekan "setujui tetap" tanpa membaca.

Adu ke `database/data/kemampuan-kalibrasi.json` (terstruktur), dan tulis test
yang mengadu tabel master ke lampiran baris demi baris.

## Sebelum bilang selesai

- [ ] Tiap kolom turunan & tiap komponen KEDUA budget diadu ke master, 5·10⁻⁶
- [ ] `vendor/bin/phpunit` penuh — bandingkan ke baseline `git stash` dulu,
      jangan asumsikan kegagalan yang muncul itu milikmu
- [ ] `vendor/bin/pint` **cuma pada berkas yang kamu sentuh** (dia merapikan
      berkas lain dan mengotori diff)
- [ ] `docs/pertanyaan-lab-<alat>.md`, `docs/perintah-frontend-<alat>.md`,
      dan §baru di `docs/permintaan-user-7.md` + baris Gelombang

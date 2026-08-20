# Perintah Frontend — DO Meter (alat ke-9)

**Berkas ini lengkap dan berdiri sendiri.** Tempel seluruh isi di bawah garis ke
sesi kerja frontend — tidak perlu membuka dokumen lain.

| | |
|---|---|
| Repo API | `sidik-calibration-api`, branch `claude/do-meter-alat-ke-9` |
| Formulir | `SIDIK-FM-CAL-0532_Rev.2` (LK-285-IDN) |
| Metode | `SIDIK-IK-CAL-0530_Rev.2` |
| Master | `Master Olah Data_DO Meter.xlsm`, sesi asli `0566-CAL-624` |
| Status backend | Profil + lembar kerja + olah data **selesai & terverifikasi persis ke master di MySQL** (Uc, v_eff, k, U, koreksi, kondisi lingkungan semua cocok) |

---

Bertindak sebagai Frontend Engineer untuk aplikasi kalibrasi PT Sidik.

Kabar baiknya: **DO Meter memakai jalur yang SAMA PERSIS dengan pH / Turbidimeter
/ Chlorine / Refractometer.** Tidak ada layar atau endpoint khusus seperti
Autoclave. Kalau layar generik lembar kerja sudah jalan untuk alat-alat itu, DO
Meter tinggal muncul begitu kamu kirim `?profil=do_meter`. Dokumen ini menandai
yang perlu diperhatikan saja.

## 0. Aturan paling penting

1. **Frontend TIDAK menghitung apa pun.** Rata-rata, STDEV, koreksi,
   ketidakpastian — semua dari backend (`POST /calibrations/preview` /
   `POST /calibrations`). Frontend mengumpulkan angka mentah + menampilkan hasil.
2. **Jangan hardcode** titik, satuan, atau jumlah kolom. Bentuk lembar datang
   dari `GET /calibrations/lembar-kerja?profil=do_meter`.

## 1. Yang khas dari DO Meter

- **Satu titik: 8,77 mg/L.** Bukan dua/tiga seperti pH/Chlorine, dan **BUKAN
  0,00** yang tercetak di kertas. "Zero Oxygen Std. 0.0 mg/l" di form itu larutan
  untuk menol-kan alat sebelum diukur, bukan titik kalibrasinya. Titik yang
  dilaporkan & terakreditasi 8,77 mg/L. (Pola sama dengan Chlorine yang formnya
  cetak 0,4/4 tapi pakai 1,74/1,83.)
- **Dua tabel: Before adjustment & After adjustment.** Sertifikat memakai yang
  **After**. Tiap tabel: baris = titik (8,77), kolom = pembacaan `mg/L` + suhu
  larutan `°C`, Repeat 1–5.
- **Tidak ada vonis PASS/FAIL.** Master tidak punya batas keberterimaan (MPE),
  dan sertifikat tidak mencetak vonis. `keputusan` sesi akan `null` — jangan
  tampilkan badge PASS/FAIL untuk alat ini.
- **Thermohygro ada di blok CALIBRATION RESULT** (kotak centang TH-2/6/7/4 di
  kertas), bukan di EQUIPMENT IDENTITY seperti lembar pH/Chlorine. Di respons
  lembar kerja, field `thermohygro_standard_id` ada di bagian `kode: hasil`.
- **`%O2` TIDAK diolah.** Kertas & master punya kolom %O2, tapi seluruh
  perhitungan %O2 di master rusak (`#DIV/0!`/`#REF!`). Backend hanya mengolah
  mg/L — sama dengan yang benar-benar tercetak di sertifikat. Jangan bikin kolom
  %O2 di layar input.

## 2. Lembar kerja — `GET /calibrations/lembar-kerja?profil=do_meter`

Bentuknya identik struktur dengan Chlorine. Yang perlu dibaca:

- `data.satuan` = `mg/L`, `data.kode_dokumen` = `SIDIK-FM-CAL-0532_Rev.2`.
- `data.larutan_standar` = `[8.77]` (satu titik).
- Bagian `kode: hasil` punya `tabel[]` dua tahap (`sebelum_adjustment`,
  `sesudah_adjustment`); tiap tabel `kolom` = `[pembacaan, suhu]`, `baris` satu
  entri titik 8,77, `pengulangan` = `[1..5]`.
- `resolusi`/`desimal` per baris **tidak dikirim** (resolusi seragam 0,01 →
  di mobile "tidak ada" berarti "pakai resolusi alat"). Sama seperti Chlorine.

Jumlah kolom pengulangan bisa diatur (`&pengulangan=3`) seperti alat lain.

## 3. Kirim & olah data

Sama persis dengan alat GUM lain — `POST /calibrations/preview` (hitung tanpa
simpan) dan `POST /calibrations` (simpan). Payload titik ukur: `titik_ukur =
8.77`, `pembacaan[]` mg/L, `suhu` °C per pembacaan, `tahap` =
`sebelum_adjustment`/`sesudah_adjustment`.

### Angka acuan (sesi master `0566-CAL-624`, After adjustment)

Pembacaan `[8.82, 8.82, 8.82, 8.83, 8.83]` di titik 8,77 menghasilkan:

| Keluaran | Nilai | Tampilan |
|---|---|---|
| Standard Indication | 8,77 mg/L | `8,77` |
| Instrument Indication (rata-rata) | 8,824 mg/L | `8,824` |
| Correction (Standard − Instrument) | −0,054 mg/L | `-0,054` |
| U95 dilaporkan | 0,16 mg/L | `± 0,16` |
| k | 1,9718 | — |
| Kondisi lingkungan | 23,31 °C ± 1,71 · 52,5 %RH ± 5,66 | dari awal/akhir |

**Correction bertanda `Standard − Instrument`** (bukan sebaliknya): alat baca
8,824 di standar 8,77, jadi koreksinya −0,054 (angka yang ditambahkan ke bacaan
alat untuk mendapat nilai benar). Backend sudah menghitung tanda ini; tampilkan
apa adanya.

U95 0,16 = **CMC** (0,16) yang menang atas U hitung (0,148): backend melantaikan
ke CMC, sama seperti sel `MAX(U, CMC)` di master. Jadi selama alat sehat,
U95-nya 0,16. Simpan & tampilkan nilai penuh dari API, jangan bulatkan sendiri.

## 4. Yang BELUM ada

- Layar input DO Meter di mobile (kalau layar generik lembar kerja sudah
  menangani profil dinamis, ini otomatis jalan — cukup arahkan ke
  `?profil=do_meter` dan sembunyikan badge PASS/FAIL).
- Template OCR pindai foto untuk DO Meter (belum ada geometri).

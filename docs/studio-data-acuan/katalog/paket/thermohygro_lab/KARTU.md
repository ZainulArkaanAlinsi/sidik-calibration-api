# Thermohygrometer lab (alat kondisi lingkungan, lintas alat) — paket `thermohygro_lab`

| | |
|---|---|
| Kelompok | Lintas alat |
| Sumber data acuan hari ini | seed-standards — `database/data/thermohygro-lab.json` |
| Status di Studio | Lintas alat — tampilkan & versikan dari tabel `standards` (prompt P7, mode seed) |
| Profil | `(semua profil yang memakai Environmental Meter)` |
| Kalkulator | — |
| Kelas tabel | — |
| Formulir resmi | — (tidak ada di worksheet_alat_calibration/) |
| Folder master | — |
| Generator | — |
| Fixture master | — |
| Pertanyaan lab | `docs/pertanyaan-lab-thermohygro-satuan.md` |
| Serah-terima frontend | — |

**Catatan:** Diseed ThermohygroSeeder ke tabel `standards` (koreksi & U kondisi lingkungan). Sumber kebenaran sesudah seed adalah tabel `standards`, yang SUDAH bisa disunting di menu Standar. Studio menampilkannya sebagai paket ber-versi, tidak membuat salinan kedua.

## Berkas kode yang terlibat


## Test yang wajib tetap hijau tanpa diubah

- — (belum ada test yang menyebut kelas/profil ini; buat test setara versi 1 dulu)

## Peta Excel → Studio

Tidak ada CSV master di `alat-alat-Pt-Sidik/` untuk paket ini.


## Lembar data acuan (dari JSON — 100% sel tercakup)

- `thermohygro-lab.json`: 456/456 sel data terwakili lembar di bawah.

| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |
|---|---|---|---|---|---|
| `thermohygro` | tabel | 8 | 49 | lebar | nama·teks; serial_number·teks; lokasi·teks; tertelusur_ke·teks; tanggal_kalibrasi·tanggal; berlaku_sampai·tanggal; _catatan·teks |
| `thermohygro/*/parameter_kondisi` | tabel_anak | 17 | 51 | lebar | indexed_value·desimal; correction·desimal; u95·desimal |
| `thermohygro/*/titik_kalibrasi` | tabel_anak | 8 | 0 | lebar |  |
| `thermohygro/*/titik_kalibrasi/*/suhu` | tabel_anak | 40 | 160 | lebar | indexed_value·desimal; instrument·desimal; correction·desimal; u95·desimal |
| `thermohygro/*/titik_kalibrasi/*/kelembaban` | tabel_anak | 40 | 160 | lebar | indexed_value·desimal; instrument·desimal; correction·desimal; u95·desimal |
| `thermohygro/*/titik_kalibrasi/*/tekanan` | tabel_anak | 9 | 36 | lebar | indexed_value·desimal; instrument·desimal; correction·desimal; u95·desimal |

Rincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.


## Catatan yang sudah tertulis di JSON acuan

- **thermohygro-lab.json:_sumber**: Project-PT-Sidik/Master Olah Data_pH for trial_CSV/DATABASE.csv, blok 'Environmental Meter' (baris 28-67)
- **thermohygro-lab.json:_catatan**: Tiap thermohygro dikalibrasi di 5 titik suhu & 5 titik kelembaban. `parameter_kondisi` cuma muat SATU titik per parameter, jadi yang dipilih titik yang paling dekat sama kondisi ruang lab sehari-hari (~20 °C / ~50 %RH) — itu juga titik yang dipakai sheet PERHITUNGAN aslinya buat TH-3 (19,83 / 47,05). Titik penuhnya diarsipkan di `titik_kalibrasi` supaya nggak ilang kalau nanti pemilihan titiknya dibikin otomatis.
- **thermohygro-lab.json:_u95_suhu**: Kolom 'Uncertainty ±' suhu di CSV cuma diisi di baris pertama tiap unit (sel di-merge) — berlaku buat kelima titiknya.
- **thermohygro-lab.json:_catatan_tekanan**: Thermobarometer Lutron (MHB372, SN AM02225) punya parameter KETIGA: tekanan udara (hPa, 9 titik 930-1005). Sumbernya DATABASE!K28:N39 di 'Gas Detector Uli Skin (std Rigaz).xlsm'. Cuma unit ini yang punya kolom tekanan; TH-1..TH-7 nggak, dan `parameter_kondisi['tekanan']`-nya memang nggak ada.

## Yang wajib dikonfirmasi sebelum paket ini "selesai"

- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).
- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.
- `kontrakInput()` profil: field lembar yang dibaca rumus.
- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).

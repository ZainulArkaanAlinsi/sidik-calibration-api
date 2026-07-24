# Permintaan Backend — Deadline 2026-07-24

Ditulis untuk menutup gap yang bikin app **kelihatan "komponen hilang / isi kurang / melenceng dari spec"** di HP fisik, padahal kode mobile-nya lolos analyze + 211 test. Akar masalahnya bukan di layar — **layar teknisi & mayoritas data digambar dari respons backend.** Kalau backend beda bentuk / tidak kejangkau, layarnya jadi kosong atau gagal muat.

Urutan di bawah = urutan prioritas untuk deadline.

---

## 0. WAJIB DULU — pastikan app nyambung ke backend

Mobile default: `API_BASE_URL=http://10.0.2.2:8000/api` (itu **cuma** jalan dari emulator Android). Di **HP fisik** harus diisi IP LAN laptop:

```
flutter run --dart-define=API_BASE_URL=http://<IP-LAPTOP>:8000/api
```

Cek: HP & laptop satu Wi-Fi, `php artisan serve --host=0.0.0.0`, buka `http://<IP-LAPTOP>:8000/api` dari browser HP → harus ada respons. Kalau ini salah, **semua** layar data kosong (bukan bug mobile).

---

## 1. KRITIS — `GET /api/calibrations/lembar-kerja` HARUS sama persis bentuk ini

Ini yang paling mungkin bikin form teknisi ambruk. Mobile mem-parse respons ini jadi form. Beberapa key **wajib** ada — kalau `null`/beda nama, `fromJson` melempar dan **seluruh form hilang** (jatuh ke layar "gagal muat").

Field yang **wajib non-null** (kalau kosong → form ambruk):
- Tiap `bagian[].kode`
- Tiap `bagian[].field[].kode`
- Tiap `bagian[].tabel[].tahap`
- Tiap `tabel.baris[].titik_ukur` (harus number)
- Tiap `tabel.kolom[].kode`
- Tiap `field.pilihan[].nilai`

Respons harus disaring per role dari token: **teknisi TIDAK menerima** field `hanya_admin: true` (Order Number, Calibration Methode, Thermohygro used) — bukan disembunyikan di mobile, tapi **tidak dikirim** sama sekali.

Bentuk baku (contoh untuk role teknisi):

```json
{
  "kode_dokumen": "SIDIK-FM-CAL-0509_Rev.4",
  "judul": "Calibration Worksheet - pH Meter",
  "untuk": "teknisi",
  "jumlah_pengulangan": 5,
  "larutan_standar": [4.00, 7.00, 10.01],
  "satuan": "pH",
  "satuan_suhu": "°C",
  "semua_kolom_opsional": true,
  "catatan_pengisian": "Kolom yang belum bisa diisi di lapangan boleh dikosongin — lembar kerja tetap bisa dikirim.",
  "bagian": [
    {
      "kode": "identitas_alat",
      "judul": "EQUIPMENT IDENTITY AND CUSTOMER DATA",
      "field": [
        {"kode": "tanggal_terima",  "label": "Received Date",   "tipe": "tanggal", "wajib": false, "sumber": null,          "satuan": null, "pilihan": [], "hanya_admin": false},
        {"kode": "tanggal_kalibrasi","label": "Calibration Date","tipe": "tanggal", "wajib": false, "sumber": null,          "satuan": null, "pilihan": [], "hanya_admin": false},
        {"kode": "equipment_id",    "label": "Equipment",        "tipe": "pilihan", "wajib": false, "sumber": "master_alat", "satuan": null, "pilihan": [], "hanya_admin": false},
        {"kode": "equipment.nama_alat",     "label": "1. Name",            "tipe": "teks", "sumber": "otomatis", "hanya_admin": false},
        {"kode": "equipment.range_resolusi","label": "2. Range/Resolution","tipe": "teks", "sumber": "otomatis", "satuan": "pH", "hanya_admin": false},
        {"kode": "equipment.model",         "label": "3. Type/Model",      "tipe": "teks", "sumber": "otomatis", "hanya_admin": false},
        {"kode": "equipment.serial_number", "label": "4. Serial Number/LPI","tipe": "teks","sumber": "otomatis", "hanya_admin": false},
        {"kode": "equipment.merk",          "label": "5. Merk/Manufacture","tipe": "teks", "sumber": "otomatis", "hanya_admin": false}
        // field {"kode":"thermohygro_standard_id", ... "hanya_admin":true} HANYA dikirim untuk role admin
      ]
    },
    {
      "kode": "pemilik",
      "judul": "OWNER",
      "field": [
        {"kode": "customer.nama",   "label": "1. Name",    "tipe": "teks", "sumber": "otomatis", "hanya_admin": false},
        {"kode": "customer.alamat", "label": "2. Address", "tipe": "teks", "sumber": "otomatis", "hanya_admin": false}
      ]
    },
    {
      "kode": "data_kalibrasi",
      "judul": "STANDARD CALIBRATION DATA",
      "field": [
        {"kode": "lokasi", "label": "1. Location", "tipe": "pilihan", "pilihan": [
          {"nilai": "lab", "label": "In lab"}, {"nilai": "onsite", "label": "Insitu"}
        ], "hanya_admin": false},
        {"kode": "room_id",          "label": "Ruangan",              "tipe": "pilihan", "sumber": "master_ruangan", "hanya_admin": false},
        {"kode": "suhu_awal",        "label": "Env. Condition — First","tipe": "angka", "satuan": "°C",  "hanya_admin": false},
        {"kode": "kelembaban_awal",  "label": "Env. Condition — First","tipe": "angka", "satuan": "%RH", "hanya_admin": false},
        {"kode": "suhu_akhir",       "label": "Env. Condition — End",  "tipe": "angka", "satuan": "°C",  "hanya_admin": false},
        {"kode": "kelembaban_akhir", "label": "Env. Condition — End",  "tipe": "angka", "satuan": "%RH", "hanya_admin": false}
        // field {"kode":"calibration_method_id", ... "hanya_admin":true} HANYA untuk admin
      ]
    },
    {
      "kode": "usage_check",
      "judul": "Standard Name / Usage Check",
      "sumber": "master_standar",
      "field": []
    },
    {
      "kode": "hasil",
      "judul": "CALIBRATION RESULT",
      "field": [],
      "tabel": [
        {
          "tahap": "sebelum_adjustment", "judul": "Before adjustment Reading",
          "baris": [
            {"titik_ukur": 4.00, "label": "4,00"},
            {"titik_ukur": 7.00, "label": "7,00"},
            {"titik_ukur": 10.01,"label": "10,01"}
          ],
          "kolom": [
            {"kode": "pembacaan", "label": "pH", "tipe": "angka", "satuan": "pH"},
            {"kode": "suhu",      "label": "°C", "tipe": "angka", "satuan": "°C"}
          ],
          "pengulangan": [1, 2, 3, 4, 5]
        },
        {
          "tahap": "sesudah_adjustment", "judul": "After adjustment Reading",
          "baris": [ /* sama */ ], "kolom": [ /* sama */ ], "pengulangan": [1,2,3,4,5]
        }
      ]
    },
    {
      "kode": "penutup",
      "judul": "Catatan & Tanda Tangan",
      "field": [
        {"kode": "catatan_teknisi", "label": "Catatan",      "tipe": "teks_panjang", "hanya_admin": false},
        {"kode": "teknisi.nama",    "label": "Calibrated by", "tipe": "teks", "sumber": "otomatis", "hanya_admin": false},
        {"kode": "reviewer.nama",   "label": "Checked by",    "tipe": "teks", "sumber": "otomatis", "hanya_admin": false}
      ]
    }
  ]
}
```

Nilai `tipe` yang dikenali: `teks`, `teks_panjang`, `angka`, `tanggal`, `pilihan`, `centang`.
Nilai `sumber`: `otomatis`, `master_alat`, `master_standar`, `master_ruangan`, `master_metode`, atau `null` (manual).

> Sumber kebenaran ada di `lib/services/lembar_kerja_service.dart` → `contohBentukLembarKerja()`. Samakan `LembarKerjaTemplate` backend ke situ.

---

## 2. Endpoint lain yang app panggil — pastikan ada & bentuknya konsisten

Semua di bawah `/api`. Butuh Bearer token (Sanctum). Bungkus data di `{"data": ...}` atau langsung objek (mobile terima dua-duanya).

| Method | Path | Dipakai untuk |
|---|---|---|
| GET | `/me` | Validasi token saat splash (role & status) |
| POST | `/logout`, `/logout-all` | Keluar / keluar semua perangkat |
| POST | `/forgot-password` | Reset password |
| GET | `/dashboard` | Kartu ringkasan (`total_alat`, `alat_overdue`, `total_sertifikat`, `sertifikat_bulan_ini`) |
| GET | `/calibrations/lembar-kerja` | **Bentuk form (bagian 1)** |
| POST/PUT | `/calibrations`, `/calibrations/{id}` | Kirim / perbaiki lembar kerja |
| GET | `/calibrations?mine=true`, `/calibrations/{id}` | Tugas saya, detail sesi |
| GET | `/categories`, `/categories/{kode}` | Picker kategori & jenis alat |
| GET/POST/DELETE | `/equipments`, `/standards`, `/customers`, `/rooms` | Master data |
| GET | `/organization` | Data organisasi/lab |
| GET | `/certificates/{id}`, POST `/certificates/{id}/retry` | Sertifikat & regen |
| GET/PUT/DELETE | `/arsip/perusahaan`, `/arsip/folders/{id}`, `/folders/{id}` | Folder Manager (spec poin 3 & 7) |
| GET/POST/DELETE | `/notifications`, `/notifications/unread-count`, `/notifications/{id}/read`, `/notifications/read-all` | Notifikasi |
| POST | `/users/{id}/reject` | Approval akun |

**Semua error harus JSON** (`{"message": "..."}`) dengan status yang benar (401/403/404/422/500) — bukan HTML Laravel. Mobile nampilin `message`-nya.

---

## 3. Gap spesifikasi yang menuntut backend (bukan mobile)

### Poin 6 — Reminder jatuh tempo (belum ada di backend)
- Perlu job/scheduler yang generate notifikasi otomatis:
  - Alat mendekati jatuh tempo kalibrasi (kirim X minggu/bulan sebelum).
  - Masa pakai alat hampir habis.
  - Sesi kalibrasi disetujui → notifikasi konfirmasi.
- Mobile sudah siap menampilkan (kategori `jatuh_tempo`); tinggal backend yang mengisi.

### Poin 11 — Validasi hitung ulang sebelum sertifikat terbit
- Sebelum status `final`, backend **hitung ulang** Correction, U95%, dll dari data mentah (bukan ambil input apa adanya).
- Kalau ada anomali → balikin **warning** ke admin, sertifikat belum boleh terbit. Mobile (`perhitungan_screen`) sudah punya tempat menampilkan temuan/warning.

### Poin 12 — Admin panel Desktop/Web + real-time sync
- Versi desktop/web admin panel (kelola DB, rumus, struktur sertifikat, import Excel).
- Sinkron real-time desktop ↔ mobile (satu database). Perlu keputusan mekanisme: polling vs websocket/broadcast.

---

## 4. KAMERA AI — endpoint vision untuk baca worksheet (WAJIB, OCR sudah dibuang)

**Status mobile (2026-07-24): OCR on-device (Google ML Kit) SUDAH DIHAPUS total** —
dependency, `live_scan_screen`, `worksheet_ocr`, `ocr_service` dibuang. Tombol scan
sekarang: foto → **upload ke endpoint di bawah** → pra-isi sel + tandai sel keyakinan
rendah → teknisi konfirmasi. **Backend WAJIB bikin endpoint ini biar tombol foto jalan.**
Acuan lengkap: `Project-PT-Sidik/SPEC-vision-ai-worksheet-extraction.md`.

Endpoint yang dipanggil mobile (`ApiWorksheetVisionService` di `lib/services/worksheet_vision.dart`):

```
POST /api/raw-measurements/extract-from-photo
Content-Type: multipart/form-data
  - foto: <file gambar tabel>            (WAJIB)
  - jumlah_titik: 3                       (jumlah larutan standar)
  - jumlah_pengulangan: 5                 (Repeat 1..n)
  - calibration_session_id: <id>          (opsional; sesi baru belum punya id)

→ 200 OK  — satu entri "baris" per Repeat; tiap array sepanjang jumlah_titik,
            urut ikut larutan standar (4 / 7 / 10). null = sel tak terbaca.
{
  "baris": [
    {
      "ph":            [4.01, 7.02, 10.11],
      "suhu":          [22.2, 22.3, 22.1],
      "ph_keyakinan":  ["high", "high", "low"],     // high | medium | low
      "suhu_keyakinan":["high", "high", "high"]
    }
    // ... satu objek per Repeat
  ]
}
```

Backend memanggil Claude vision (temperature 0, structured output) dengan prompt: "petakan
tiap angka ke (larutan standar, Repeat, kolom pH/°C)" — lihat prompt draft di SPEC §3.3.
Keunggulan vs OCR lama: paham layout & tulisan tangan. Mobile:
- Isi `ph`/`suhu` ke sel yang **masih kosong** (koreksi manual teknisi tidak ketimpa).
- Sel `*_keyakinan: "low"` → **ditandai border kuning** biar teknisi cek angka itu saja.
- Kalau endpoint gagal / foto jelek → teknisi tetap bisa **ketik manual** (fallback, SPEC §4.2).

> Butuh: API key vision **di backend** (jangan di mobile), temperature 0, structured/JSON
> output, validasi range per tipe alat, log `worksheet_extraction_logs` (SPEC §3.4), rate limit,
> batas ukuran gambar. Confidence dikirim sebagai `high|medium|low` (mobile juga terima
> `tinggi|sedang|rendah`); string asing dianggap `low` supaya disuruh cek.

---

## Ringkas prioritas deadline
1. **#0 + #1** — koneksi + bentuk `lembar-kerja` benar. Ini yang bikin app "melenceng/komponen hilang". Selesaikan ini dulu.
2. **#2** — endpoint lain balikin JSON konsisten + error JSON.
3. **#3, #4** — reminder, validasi hitung, sync, kamera AI (menyusul, bukan blocker tampilan).

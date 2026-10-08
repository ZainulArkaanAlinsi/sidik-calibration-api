# 03 — SDD & ADR Studio Data Acuan

## 1. Gambaran arsitektur

```
┌────────────────────── Laptop admin (Flutter Windows/macOS) ──────────────────────┐
│ DesktopShell ─► seksi "Data Acuan"                                               │
│   Daftar paket │ Studio (grid per lembar) │ Banding │ Simulasi │ Pengesahan │ Rumus│
└──────────────────────────────┬───────────────────────────────────────────────────┘
                               │ REST /api/data-acuan/*  (Sanctum, MatriksIzin)
┌──────────────────────────────▼───────────────────────────────────────────────────┐
│ Laravel                                                                           │
│  DataAcuanController ─► LayananDataAcuan (draf, sunting, ajukan, sahkan, tarik)   │
│                         ├─ ValidatorSkemaAcuan  (skema dari profil, BR-04/07)     │
│                         ├─ Pengurai Workbook (F1, PhpSpreadsheet di worker)       │
│                         └─ JobSimulasiAcuan ─► profil+calculator yang SAMA        │
│  SumberAcuan (baru) ◄── TabelStandar*/TabelKalibrator* (ganti baca JSON)          │
│  RumusKalibrasi (stempel formula)  +  PenentuVersiAcuan (stempel acuan)            │
│  PerubahanDataOrganisasi::siarkanAman('data_acuan', …)                            │
└──────────────────────────────┬───────────────────────────────────────────────────┘
                               │ satu MySQL
            ┌──────────────────┴──────────────────┐
            ▼                                     ▼
   HP teknisi (lembar kerja, hasil)      Desktop lain (Studio, antrean)
   tarik ulang: sinyal / 3 menit / saat lembar dibuka (ETag)
```

Tidak ada database di laptop. Tidak ada perhitungan di HP: HP menampilkan hasil server
(`/calibrations/preview`, `/perhitungan`). Jadi "berubah di semua perangkat" = versi aktif di
server berubah + klien menarik ulang.

## 2. Model data (ERD)

```
organizations 1─* paket_acuan 1─* paket_acuan_versi 1─* paket_acuan_suntingan
                                         │ 1
                                         ├─* simulasi_acuan 1─* simulasi_acuan_sesi
                                         ├─0..1 unggahan_workbook
                                         └─* uncertainty_calculations (paket_acuan_versi_id)
calibration_sessions.bentuk_versi_id ─► paket_acuan_versi (jenis = bentuk)
```

### 2.1 `paket_acuan`

| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint | |
| organization_id | FK | `cascadeOnDelete` |
| kode_profil | string(64) | `CalibrationProfile::kode()` / `kodeFormula()` — kunci stabil |
| nama | string | label tampilan |
| skema_versi | unsignedInteger | versi skema lembar dari kode; naik bila struktur lembar berubah (lewat PR) |
| timestamps, softDeletes | | |
| unique | (organization_id, kode_profil) `pa_org_profil_unik` | nama index pendek (MySQL ≤ 64) |

### 2.2 `paket_acuan_versi`

| Kolom | Tipe | Catatan |
|---|---|---|
| id | bigint | |
| paket_acuan_id, organization_id | FK | |
| jenis | string(16) | `data` (lapis 1) atau `bentuk` (lapis 3) |
| nomor_versi | unsignedInteger | unik per (paket, jenis) |
| status | string(16) | `draf, diajukan, terjadwal, aktif, pensiun, ditolak, ditarik, dibuang` |
| isi | json (longText di MySQL) | dokumen kanonik, lihat §2.6 |
| isi_sha256 | char(64) null | diisi saat `diajukan`; sesudah itu tidak berubah |
| skema_versi | unsignedInteger | skema yang dipakai isi ini |
| dasar_versi_id | FK self null | versi asal draf |
| berlaku_mulai, berlaku_sampai | date null | pola `formula_versions` |
| alasan | text null | wajib saat diajukan |
| rujukan | json null | `[{jenis: sertifikat_standar|ik|lampiran|sel_master, nilai, catatan}]` |
| dibuat_oleh, diajukan_oleh, disahkan_oleh, ditolak_oleh | FK users null | |
| diajukan_pada, disahkan_pada, ditolak_pada | timestamp null | |
| catatan_keputusan | text null | alasan tolak/tarik/kembalikan |
| simulasi_terakhir_id | FK null | |
| lock_versi | unsignedInteger default 0 | optimistic lock |
| timestamps | | |
| index | (paket_acuan_id, jenis, status), (paket_acuan_id, jenis, berlaku_mulai) | |

### 2.3 `paket_acuan_suntingan`

`id, paket_acuan_versi_id, lembar, kunci_baris, kolom, nilai_lama (text null), nilai_baru
(text null), sumber (ketik|tempel|impor|ambil_alih), alasan null, user_id, created_at`.
Append-only (tidak ada update/delete lewat model). Index (versi_id, lembar).

### 2.4 `simulasi_acuan`, `simulasi_acuan_sesi`

`simulasi_acuan`: `id, paket_acuan_versi_id, isi_sha256_saat_jalan, status
(antre|jalan|selesai|gagal|kedaluwarsa), mulai, selesai, ringkasan json {sesi_total,
sesi_berubah, cetak_berubah, terbit_cetak_berubah, vonis_berubah, vektor_lulus, vektor_gagal},
dijalankan_oleh`.

`simulasi_acuan_sesi`: `id, simulasi_acuan_id, calibration_session_id null, vektor_kode null,
status_sesi, sudah_terbit bool, cetak_berubah bool, vonis_berubah bool, selisih json
[{titik, kolom, lama, baru, cetak_lama, cetak_baru}]`.

### 2.5 `unggahan_workbook`

`id, organization_id, paket_acuan_id, berkas_path (disk privat), nama_asli, ukuran, sha256,
versi_workbook (dari sheet FORM VALIDASI), peta_sel_versi, hasil (draf_dibuat|struktur_berubah|
gagal), laporan json, user_id, timestamps`. unique (organization_id, sha256).

### 2.6 Bentuk isi kanonik (contoh Micrometer, nilai versi 1 = JSON hari ini)

```json
{
  "skema": "micrometer@1",
  "lembar": {
    "standar": {
      "baris": [{"kunci": "gb-160006", "nama": "Gauge Block Standard", "merk_tipe": "Metrology/GB-9122-0",
                 "seri": "160006", "traceability": "LK-410-IDN", "tanggal_kalibrasi": "2024-01-24"}]
    },
    "balok_ukur": {
      "kolom_asal": {"nominal_mm": "Standar_GB!Q10:Q132", "nilai_terkoreksi_mm": "Standar_GB!R10:R132"},
      "baris": [{"kunci": "1.0", "nominal_mm": "1.0", "nilai_terkoreksi_mm": "1.00011"},
                {"kunci": "1.1", "nominal_mm": "1.1", "nilai_terkoreksi_mm": "1.09989"}]
    },
    "ketidakpastian_balok": {"aturan": [{"maks_mm": "10.0", "u_um": "0.12"}, {"maks_mm": "21.0", "u_um": "0.14"},
                                       {"maks_mm": "50.0", "u_um": "0.25"}, {"maks_mm": "100.0", "u_um": "0.26"}],
                             "persis": [{"nominal_mm": "101.6", "u_um": "0.26"}, {"nominal_mm": "200", "u_um": "0.73"}]},
    "pita_cmc": {"baris": [{"kunci": "A", "kapasitas_min_mm": "0.0", "kapasitas_maks_mm": "25.0", "u95_um": "0.83",
                            "kode_dokumen": "SIDIK-FM-CAL-0522.A_Rev.1"}]},
    "titik_pra_cetak": {"baris": [{"kunci": "A-3", "varian": "A", "nominal_cetak_mm": "5.1", "tumpukan_mm": ["1.1", "2.5", "1.5"]}]},
    "konstanta": {"delta_alpha_per_c": "1e-05", "wringing_um": "0.111803398875", "geometri_um": "0.5",
                  "drift_a_um": "0.02", "drift_b_um_per_mm": "0.00025", "suhu_acuan_c": "20.0", "vi_type_b": "200"}
  }
}
```

Angka berbentuk string (BR-04). `SumberAcuan` mengubahnya ke `float` di titik baca yang sama
dengan `json_decode` hari ini, jadi nilai float yang sampai ke calculator **identik bit** dengan
sekarang — itu yang diuji `TabelAcuanSetaraJsonTest`.

## 3. Kontrak API

Semua di `routes/api.php`, prefix `/api/data-acuan`, middleware `auth:sanctum` + `role:` + izin.
Respons mengikuti gaya repo (`{data: …}`, error `{message, kode, rincian?}`).

| Metode & path | Izin | Keterangan |
|---|---|---|
| `GET /data-acuan/paket` | lihat | FR-01 |
| `GET /data-acuan/paket/{kode}` | lihat | paket + skema lembar + versi aktif/terjadwal + draf |
| `GET /data-acuan/paket/{kode}/berlaku?tanggal=&jenis=` | lihat | FR-04 |
| `GET /data-acuan/paket/{kode}/versi` | lihat | garis waktu |
| `GET /data-acuan/versi/{versi}` | lihat | isi + metadata; teknisi hanya `aktif` |
| `GET /data-acuan/versi/{a}/banding/{b}` | lihat | FR-11 |
| `GET /data-acuan/versi/{versi}/sel/{lembar}/{kunci}/{kolom}/riwayat` | lihat | FR-03, FR-28 |
| `POST /data-acuan/paket/{kode}/draf` | sunting | body `{dasar_versi_id, jenis}` → 201 |
| `PATCH /data-acuan/versi/{versi}/sel` | sunting | header `If-Match: <lock_versi>`; body `{perubahan:[{lembar,kunci,kolom,nilai,alasan?}]}` → `{diterima:[…], ditolak:[{…,kode,pesan}], lock_versi}`; 409 `draf_berubah` |
| `POST /data-acuan/versi/{versi}/baris` / `DELETE …/baris/{lembar}/{kunci}` | sunting | FR-08 |
| `POST /data-acuan/versi/{versi}/impor-workbook` | impor | multipart; FR-10 |
| `POST /data-acuan/versi/{versi}/simulasi` | sunting | 202 `{simulasi_id}`; throttle |
| `GET /data-acuan/simulasi/{simulasi}` | lihat | ringkasan + halaman sesi |
| `POST /data-acuan/versi/{versi}/ajukan` | ajukan | `{alasan, rujukan[]}` |
| `POST /data-acuan/versi/{versi}/sahkan` | sahkan | `{berlaku_mulai, sandi}`; grup `role:super_admin` |
| `POST /data-acuan/versi/{versi}/tolak` · `/kembalikan` · `/tarik` | sahkan | `{alasan}` |
| `DELETE /data-acuan/versi/{versi}` | sunting | hanya draf milik sendiri → `dibuang` |
| `GET /data-acuan/paket/{kode}/rumus` | lihat | FR-26 |
| `POST /data-acuan/paket/{kode}/usulan-rumus` | sunting | FR-27 → `{markdown}` |
| `GET /data-acuan/paket/{kode}/bentuk-pratinjau?versi=` | lihat | lembar dirender dari `bentukLembarKerja()` + override bentuk |

Diubah, **kompatibel** (field tambahan saja):

- `GET /calibrations/lembar-kerja` → `data.versi_acuan = {paket, data: {nomor, sha256}, bentuk: {nomor, sha256}}` + `ETag`.
- `GET /calibrations/{c}/perhitungan`, `/validasi`, snapshot sertifikat → `paket_acuan_versi: {nomor, sha256}`.
- `GET /api/health` → `data_acuan: {paket_termigrasi, versi_aktif_tanpa_simulasi: 0}` (pengawas).

Kode error baru: `draf_berubah`, `bukan_pemilik_draf`, `nilai_tidak_valid`, `kunci_kembar`,
`baris_dipakai_titik`, `simulasi_belum_ada`, `simulasi_kedaluwarsa`, `vektor_uji_gagal`,
`pengesah_ikut_menyunting`, `rentang_bentrok`, `berlaku_mundur_menyentuh_terbit`,
`kontrak_input_dilanggar`, `klien_belum_mendukung`, plus kode F1.

## 4. Alur hitung sesudah perubahan

```
sesi.tanggal_kalibrasi ─► PenentuVersiAcuan::untukSesi(sesi)  (pola RumusKalibrasi)
      └─► versi V (aktif pada tanggal itu)
SumberAcuan::untuk(profil, V) ─► array isi ter-cache per (V.id, V.isi_sha256)
TabelStandarMicrometer(sumber) ─► MicrometerCalculator (TIDAK BERUBAH)
simpan uncertainty_calculations { formula_version_id, paket_acuan_versi_id = V.id }
CalibrationValidator / HitungUlangSesi ─► pakai V dari stempel, bukan versi aktif hari ini
```

**Jebakan yang sudah terlihat di kode:** 18 dari 20 kelas `Tabel*` menyimpan isi JSON di
`private static ?array $data` — cache per proses. Worker `queue:work` di
`docker/entrypoint.sh` hidup sampai `--max-time=3600`. Kalau cache statis itu dipertahankan,
sesudah versi baru sah worker tetap menghitung dengan isi lama sampai satu jam — tanpa error.
Cache wajib dikunci `(versi_id, sha256)`, bukan per kelas. Dijaga `CacheAcuanPerVersiTest`.

## 5. ADR

### ADR-01 — Studio di panel desktop Flutter yang sudah ada, bukan aplikasi baru atau Filament
**Konteks:** pemilik ingin "versi laptop". Ada dua kandidat: panel Filament `/admin` (web) dan
panel desktop Flutter (`DesktopShell`, aktif di Windows/macOS). **Keputusan:** seksi baru
"Data Acuan" di `DesktopShell`. **Alasan:** desktop sudah memakai izin per menu, sinyal realtime,
dan layar `rumus_list_screen.dart`; satu basis kode dengan HP; grid besar butuh kontrol
keyboard/clipboard yang lebih leluasa dibanding form Filament. Filament tetap baca-saja untuk
paket (opsional). **Konsekuensi:** butuh widget grid (03 §6); rilis desktop ikut fase 2.

### ADR-02 — Server tetap satu-satunya database
**Konteks:** frasa "ubah database kita bikin buat laptop aja". **Keputusan:** tidak ada DB lokal;
yang khusus laptop hanya layar penyuntingnya. **Alasan:** `arsitektur-desktop-database.md`
Keputusan 1 (dua sumber kebenaran = temuan audit) dan `infrastruktur-vps-produksi.md` (tetap
MySQL, desktop = klien API). **Konsekuensi:** Studio butuh online untuk menyimpan; offline
baca-saja (NFR-07).

### ADR-03 — Skema lembar dideklarasikan kode, isinya data
**Konteks:** tiap alat beda bentuk (Lampiran A). **Keputusan:** tiap profil mendeklarasikan
`skemaAcuan(): array` (lembar, kolom, tipe, satuan, batas, kunci, asal sel, bisa disunting,
boleh tambah baris). Isi = data ber-versi. **Alasan:** struktur tabel ikut menentukan cara
rumus membacanya → itu lapis 2; nilainya lapis 1. **Konsekuensi:** menambah kolom/lembar baru =
PR + `skema_versi` naik + migrasi isi (§7 07).

### ADR-04 — `SumberAcuan` menggantikan baca JSON; cache per versi
**Keputusan:** kelas `Tabel*` menerima `SumberAcuan` (injeksi) yang memuat isi versi tertentu;
fallback ke JSON di `database/data/` **hanya** di environment `testing` untuk fixture lama,
dengan peringatan bila sha256 JSON ≠ versi 1. **Alasan:** satu titik ganti, calculator tidak
disentuh; jebakan cache statis (§4). **Konsekuensi:** refactor 20 kelas, satu per PR alat.

### ADR-05 — Pengesah orang kedua, tanpa pengecualian
**Keputusan:** BR-08 ditegakkan di satu pintu (perluasan `App\Services\PemisahanWewenang` dengan
metode `bolehMengesahkanVersiAcuan`). **Alasan:** pola K-30-03; satu orang yang mengetik dan
mengesahkan angka acuan sama dengan tanpa pemeriksaan. **Konsekuensi:** organisasi wajib punya
≥ 1 super admin yang bukan penyunting; bila hanya satu super admin dan dia yang menyunting,
versi tertahan — itu disengaja.

### ADR-06 — Lapis 3 dijaga kontrak input
**Keputusan:** profil mendeklarasikan `kontrakInput()` (kunci field yang dibaca `*Mentah` /
calculator) dan `batasBentuk()` (jumlah titik min/maks, satuan yang diizinkan, field yang
nominalnya dipatok IK — mis. nominal balok Micrometer yang `catatan_pengisian`-nya menyebut
"SUDAH DIPATOK kertas"). Override bentuk hanya mengubah label, urutan, satuan tampil, jumlah
titik dalam batas, wajib/opsional non-kontrak. **Alasan:** AGENTS.md §Olah data 1 lapis 3
"wajib divalidasi bahwa perhitungan tidak kehilangan input".

### ADR-07 — Lapis 2 tetap kode (menunggu K-48-01)
**Konteks:** pemilik ingin rumus juga bisa diganti. **Keputusan saat ini:** tidak. Studio
menampilkan peta rumus baca-saja + usulan terstruktur. **Alasan:** AGENTS.md §Olah data,
ISO/IEC 17025 7.2.1.5 (metode divalidasi sebelum dipakai), evaluator sengaja ditolak sejak
27 Jul. **Jalan bila K-48-01 dijawab "ya" kelak:** mesin ekspresi deterministik (bukan `eval`),
versi rumus `sumber=database` hanya boleh aktif sesudah **uji bayangan**: dijalankan berdampingan
dengan kode pada semua fixture master + semua sesi tersimpan, selisih per komponen = 0 dalam
toleransi Lab, minimal N sesi nyata, disahkan dua orang. Itu proyek tersendiri dengan CR sendiri.

### ADR-08 — Angka sebagai string desimal kanonik
**Keputusan:** BR-04. **Alasan:** float JSON dari Excel/ketikan menghasilkan `1.9000899999999998`
(terlihat di `Standar_GB.csv` Micrometer baris 77); menyimpannya sebagai string mencegah
"perubahan" palsu di diff dan menjaga sha256 stabil. Konversi ke float terjadi di satu tempat
(`SumberAcuan`) dengan `(float)` yang sama dengan `json_decode` hari ini.

### ADR-09 — Sinyal + tarik, jujur soal realtime mati
**Keputusan:** siarkan `data_acuan`; klien juga menarik ulang saat membuka lembar (ETag) dan
lewat tarikan berkala 3 menit yang sudah ada (§40.2). UI menulis "berlaku di perangkat lain
paling lambat 3 menit" selama `GET /api/health` → `realtime.nyala = false`. **Alasan:**
`docs/realtime-sync.md` keputusan 4 Sep. **Konsekuensi:** tidak menunggu plan berbayar.

## 6. Komponen sisi Flutter

| Berkas (rencana) | Isi |
|---|---|
| `lib/models/data_acuan.dart` | `PaketAcuan`, `VersiAcuan`, `SkemaLembar`, `KolomSkema`, `Suntingan`, `HasilSimulasi` |
| `lib/services/data_acuan_service.dart` | API + mock (pola `rumus_service.dart`: abstrak + `Api…` + `Mock…`) |
| `lib/providers/data_acuan_provider.dart` | Riverpod; invalidasi saat sinyal `data_acuan` di `realtime_provider` |
| `lib/screens/data_acuan/daftar_paket_screen.dart` | FR-01 |
| `lib/screens/data_acuan/studio_screen.dart` | tab lembar, grid, panel sel, bilah draf |
| `lib/screens/data_acuan/widgets/grid_acuan.dart` | grid ber-virtualisasi: navigasi panah/Tab/Enter, salin/tempel TSV, sorot ubahan |
| `lib/screens/data_acuan/banding_screen.dart`, `simulasi_screen.dart`, `pengesahan_acuan_screen.dart`, `peta_rumus_screen.dart`, `editor_bentuk_screen.dart` | sisa layar |
| `lib/core/utils/angka_lokal.dart` | parser BR-04 (koma/titik, tolak pemisah ribuan) + test |
| `desktop_shell.dart` | seksi "Data Acuan" ber-izin |
| `lembar_kerja_service.dart` | baca `versi_acuan`, kirim `If-None-Match`, banner draf terdampak |

Pilihan grid: widget sendiri di atas `TableView` dari paket resmi tim Flutter
`two_dimensional_scrollables` — tetap **dependensi baru** (belum ada di `pubspec.yaml` per
8 Okt), jadi butuh persetujuan seperti T9.2. Alternatif tanpa dependensi: `ListView` virtual
per baris + `Row` kolom tetap; lebih banyak kode, tanpa gulir dua arah yang mulus.

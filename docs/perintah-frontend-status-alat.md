# Perintah frontend: status per alat (sesi kalibrasi terakhir)

Status: **API DRAF (27 Nov 2026, cabang `feat/status-per-alat`, PR draf)**. Kontrak di bawah ADITIF: klien lama
mengabaikan field baru dan tidak ada yang berubah untuknya. Layar belum dirancang; setiap perubahan tampilan di
aplikasi menunggu PNG dari pemilik, bukan bagian dari perintah ini.

## Yang berubah untuk pengguna

Satu alat sekarang membawa posisi pekerjaannya: sedang dikerjakan teknisi, sudah dikirim untuk diperiksa, sedang
direvisi, atau sertifikatnya sudah terbit. Aplikasi tidak perlu membuka daftar sesi dan mencocokkan alat satu per
satu; jawabannya ikut di respons alat.

## Endpoint

Field baru `sesi_terakhir` ada di bentuk alat yang sama pada:

| Metode | URI | Catatan |
|---|---|---|
| GET | `/api/equipments` | tiap baris `data[]` |
| GET | `/api/equipments/{id}` | `data` |
| POST | `/api/equipments` | selalu `null` (alat baru belum punya sesi) |
| PUT / PATCH | `/api/equipments/{id}` | sesi terakhir alat itu |
| GET, POST, PUT, PATCH | `/api/studio/equipments[/{id}]` | controller yang sama, bentuk sama (Super Admin) |

Alat yang tampil **bersarang** di respons lain (mis. `alat` di baris order) TIDAK memuat `sesi_terakhir`: kuncinya
tidak ada di sana. Jangan bergantung padanya di luar daftar/detail alat.

## Bentuk field

```json
"sesi_terakhir": {
  "id": 4821,
  "status": "menunggu_approval",
  "tahap": "menunggu_pemeriksaan",
  "tahap_label": "Menunggu pemeriksaan admin",
  "diperbarui_pada": "2026-11-27T03:14:07+00:00"
}
```

`sesi_terakhir` bernilai `null` bila alat belum punya sesi. **Kuncinya selalu ada** di endpoint di atas; nilai
`null` artinya "belum ada sesi", bukan "server belum mengirim".

| Field | Tipe | Arti |
|---|---|---|
| `id` | integer | id sesi kalibrasi (`/api/calibrations/{id}` untuk detailnya) |
| `status` | string | status mentah sesi: `draft`, `menunggu_approval`, `menunggu_pengesahan`, `disetujui`, `perlu_revisi` |
| `tahap` | string | tahap TURUNAN, salah satu dari delapan tahap pelacakan paket (tabel bawah); tidak ada tahap baru |
| `tahap_label` | string | label internal tahap itu (`TahapPaket::LABEL_INTERNAL`); aman ditampilkan apa adanya |
| `diperbarui_pada` | string ISO 8601 | waktu ubah baris sesi (`updated_at`) |

`status` di tingkat alat (`aktif` / `overdue` / `nonaktif`) tetap milik alat dan tidak berubah artinya. Yang baru
hanya `sesi_terakhir.status`.

### Pemetaan ke pertanyaan lapangan

| Pertanyaan | `sesi_terakhir` | `status` | `tahap` |
|---|---|---|---|
| Belum ada pekerjaan | `null` | | |
| Dikerjakan / datanya sedang diinput teknisi | ada | `draft` | `dikalibrasi` |
| Sudah dikirim, menunggu admin | ada | `menunggu_approval` | `menunggu_pemeriksaan` |
| Admin sudah mengajukan, menunggu pengesah | ada | `menunggu_pengesahan` | `menunggu_pengesahan` |
| Dikembalikan ke teknisi | ada | `perlu_revisi` | `perlu_diulang` |
| Disetujui, PDF masih dicetak | ada | `disetujui` | `menunggu_pengesahan` |
| Sertifikat sudah terbit | ada | `disetujui` | `sertifikat_terbit` |
| Alat sudah dicatat siap diambil / diserahkan | ada | (apa adanya) | `siap_diambil` / `diserahkan` |

**Batas yang jujur:** "sedang dikerjakan" dan "datanya sedang diinput" sama-sama `draft` / `dikalibrasi`. Server
tidak punya sumber kebenaran yang membedakannya, dan tahap baru sengaja tidak dibuat. `diperbarui_pada` adalah waktu
ubah baris sesi, bukan jam tiap titik ukur disimpan.

## Aturan data

- **Sesi terakhir** = sesi dengan `id` terbesar untuk alat itu (aturan yang sama dengan `GET /api/calibrations`).
  Sesi yang diulang setelah `perlu_revisi` adalah baris baru, jadi yang lama tidak menggantikan yang baru.
- **Satu lab**: hanya sesi dengan `organization_id` pemanggil. Sesi lab lain tidak pernah dihitung, termasuk bila
  ada baris sesi yang menunjuk ke alat ini dari lab lain.
- **Sesi yang diarsipkan** (draf yang diarsipkan, soft delete) tidak dihitung; yang tampil sesi sebelumnya, atau
  `null` bila tidak ada.
- **Peran**: admin, super admin, dan viewer melihat sesi terakhir siapa pun di lab. **Teknisi hanya melihat sesi
  miliknya sendiri**, sama seperti `GET /api/calibrations`: alat yang sesi terbarunya dipegang teknisi lain tampil
  dengan sesi terbaru MILIK si penanya, atau `null`. Itu bukan galat.
- Tahap fisik yang dicatat petugas di baris paket (`siap_diambil`, `diserahkan`) menang atas tahap turunan, sama
  seperti di pelacakan paket.

## Menyegarkan data (tanpa realtime)

Field ini dibaca saat respons dibuat. **Tidak ada push atau realtime untuk status alat**: HP melihat perubahan
setelah menyegarkan daftar (tarik untuk menyegarkan, atau membuka ulang layar). Jangan mengandalkan data lama yang
tersimpan di perangkat untuk menampilkan "sedang dikerjakan".

## Biaya kueri

Satu halaman daftar alat menambah tiga kueri tetap (sesi, sertifikat, baris paket), berapa pun jumlah alatnya.
Dijaga `StatusPerAlatTest::test_jumlah_kueri_sama_untuk_dua_alat_dan_untuk_delapan_alat`.

## Pengetesan

`tests/Feature/StatusPerAlatTest.php`: alat tanpa sesi, satu sesi, banyak sesi, tahap untuk tiap status, tahap fisik,
sesi terarsip, lintas lab (termasuk baris sesi rusak), peran teknisi, jumlah kueri, jalur tulis, resource bersarang,
dan jalur Studio.

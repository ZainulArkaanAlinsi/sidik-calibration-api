# 05 — Konflik, cara menerapkan, dan cara mengembalikan

> **DRAFT.** Persetujuan penerapan instruksi (berkas ini) **terpisah** dari persetujuan
> implementasi aplikasi (`docs/paket-30sep/VERIFIKASI-DAN-RENCANA-G0-G1.md`).

## 1. Konflik antar-instruksi

Tidak ada yang diputuskan sendiri. Tiap baris menyebut sumber, tabrakan, dan konsekuensi.

| Kode | Aturan yang bertabrakan | Sumber | Konsekuensi kalau dibiarkan | Usulan (menunggu pemilik) |
|---|---|---|---|---|
| **K-I1** Commit/push/merge | (a) "JANGAN commit/push otomatis; saat diminta `git push origin main`" · (b) izin berdiri "commit + push ke branch + PR, jangan push langsung ke `main`" · (c) "PR boleh langsung di-merge sesudah CI hijau" · (d) "jangan push/merge/deploy tanpa izin untuk aksi itu" · (e) "jangan commit/push tanpa perintah eksplisit" · (f) pesan 1 Okt: "selalu push, commit, merge, pull" · (g) tugas 1 Okt: tahap ini tanpa commit/push/deploy | (a) `AGENTS.md` §Git · (b) memory `commit-push-tiap-perubahan` · (c) memory `merge-pr-tanpa-tunggu-tapi-wajib-verifikasi` · (d) `rules/github-cli-workflow.md` · (e) berkas 30 Sep §0.2 · (f)(g) percakapan | Tiap sesi harus menebak aturan mana yang menang. (a) bahkan menyuruh push langsung ke `main` = deploy produksi, bertentangan dengan (b) | Satu kalimat di `AGENTS.md`: "Izin berdiri: commit per langkah logis, push ke branch fitur, buka PR, merge sesudah CI hijau **dan** suite MySQL hijau. Tidak pernah push langsung ke `main`. Tahap yang user nyatakan 'pemeriksaan saja' = tanpa commit." |
| **K-I2** graphify vs pencarian terarah | Hook: "MUST run `graphify query` before grepping/reading" · aturan: cari terarah, hemat token | Hook PreToolUse graphify · `CLAUDE.local.md` · `rules/focused-research.md` | Sesudah ganti branch, graph di-rebuild di latar dan bisa basi. Hook tetap memaksa. Sesi 1 Okt memakai Grep terarah dan tidak menjalankan `graphify query` | Ubah teks hook dari "MUST" jadi "pakai kalau graph mutakhir; kalau baru ganti branch, Grep terarah boleh". Ini konfigurasi lokal, bukan teks repo |
| **K-I3** Bug Hunter Protocol | Berkas 30 Sep Lampiran B: fase Hunter → Skeptic → Referee **wajib tampil** di output · aturan global: "Do not output simulated internal debates" · tugas 1 Okt: "jangan menampilkan simulasi debat internal" | berkas 30 Sep §0.2 & Lampiran B · `rules/bug-review-and-learning.md` | Laporan bug memuat "debat" buatan yang tidak menambah bukti | Pakai **standar bukti** Lampiran B §4 (lokasi, pemicu, dampak, reachability, bantahan), tanpa format debat. Instruksi user terbaru sudah memilih ini |
| **K-I4** Pemisahan wewenang | "Default aman: blokir dulu" · "Keputusan 26 Sep: peringatan mencolok, belum memblokir" + `PEMISAHAN_WEWENANG_MEMBLOKIR=false` · "K4 belum dijawab" vs dokumen 26 Sep (menurut berkas 30 Sep) "SA = pengesah = jawaban K4" | `AGENTS.md` §Peran butir 4 · docblock `app/Services/PemisahanWewenang.php` + `config/kalibrasi.php` · berkas 30 Sep §10 butir 2–3. Dokumen 26 Sep sendiri **tidak ada** di workspace | T1.1 (B01) tidak bisa ditulis tanpa memilih. Blokir: lab satu admin aktif berhenti menerbitkan. Peringatan: sertifikat bisa terbit tanpa pemeriksa kedua (B01 terbukti) | Jawab K-30-03. Draft `01` tidak mengubah teks aturan, hanya menambah status kode B01 |
| **K-I5** `composer dev` | `AGENTS.md` menulisnya sebagai perintah biasa · `CLAUDE.local.md`: "Jangan `composer dev` — ikut menyalakan `queue:listen` yang memproses antrean job produksi" | `AGENTS.md` §Perintah · `CLAUDE.local.md` §UI | Di mesin yang `.env`-nya menunjuk produksi, `composer dev` memproses antrean produksi | Tambah satu peringatan di `AGENTS.md` §Perintah: "jangan di mesin yang `.env`-nya menunjuk database produksi". Belum dimasukkan ke draft `01` karena menambah aturan baru |
| **K-I6** Obsidian | Pola vault dimuat tiap sesi di semua proyek, padahal hanya dipakai saat pemicu | `~/.claude/CLAUDE.md` | ±1,5 KB tiap sesi | Pilih: panduan `~/.claude/panduan/obsidian-vault.md` (draft `02`) **atau** skill `obsidian-vault` |
| **K-I7** Aturan OCR | Aturan OCR global relevan langsung untuk SIDIK (OCR lembar kerja) | `rules/inventory-ocr-and-workflows.md` | Kalau dipindah ke panduan, aturan OCR hanya terbaca saat sesi ingat membukanya | Pilih: tetap di `inti.md` (+±1 KB) atau di panduan |
| **K-I8** Nomor PRD | Berkas 30 Sep meminta "tambah §41" ke `permintaan-user-7.md` | berkas 30 Sep §0.1, T0.2 | §41 (permintaan kalibrasi pelanggan) dan §42 (koreksi) sudah terpakai | Pakai §43 |
| **K-I9** Durasi suite | MySQL "3 jam 3 menit" (24 Sep) vs "~2 jam" | `AGENTS.md` §Test · memory `suite-penuh-butuh-dua-jam` | Perkiraan waktu verifikasi meleset | Pakai angka `AGENTS.md` (lebih baru); perbarui atau hapus memory lama |

## 2. Ukuran sebelum/sesudah

Byte diukur dengan `wc -c`. "Sesudah" untuk `AGENTS.md` dan aturan global = teks draft
yang sudah ditulis. `CLAUDE.local.md` sesudah = estimasi (drafnya berupa daftar, bukan
teks utuh).

| Berkas yang dimuat tiap sesi | Sebelum | Sesudah |
|---|---|---|
| `AGENTS.md` | 43.522 | 19.388 (−55%) |
| `CLAUDE.md` proyek | 938 | 938 |
| `CLAUDE.local.md` | 8.115 | ±3.000 (estimasi) |
| `docs/aturan-akses-database.md` | 3.113 | 3.113 |
| `~/.claude/CLAUDE.md` | 2.175 | 657 |
| `~/.claude/rules/*.md` | 17.575 | 8.017 |
| `MEMORY.md` | 3.314 | 3.314 |
| **Total** | **78.752** | **±38.400 (−51%)** |

Yang pindah ke panduan on-demand: ±18,8 KB proyek (`docs/panduan/`), 3,7 KB lokal, ±5,5 KB
global. Tidak ada yang hilang. Isinya dibaca saat pemicunya terjadi.

**Estimasi token**, memakai rasio dari `/context` sesi 1 Okt (78.752 byte ≈ 35,5 ribu
token, ±2,2 byte/token): Memory files dari ±35,5 ribu jadi ±17–18 ribu token, dan
`AGENTS.md` dari ±21,5 ribu jadi ±9–10 ribu. **Ini estimasi.** Penghematan nyata hanya
terukur lewat `/context` di sesi baru setelah diterapkan, karena tokenizer tidak linear
terhadap byte dan system prompt/tools/skill tidak ikut berubah.

## 3. Cara menerapkan (sesudah disetujui)

Tiga lapis diterapkan dan bisa dikembalikan **terpisah**.

### Lapis proyek (`AGENTS.md` + `docs/panduan/`) — ter-track, repo PUBLIC

1. Branch baru dari `main` terbaru, mis. `docs/rapikan-instruksi`.
2. Buat enam berkas `docs/panduan/*.md` dengan **menyalin rentang baris** dari
   `git show 0beb5bb:AGENTS.md` (`sed -n 'A,Bp'`), sesuai tabel `03`. Bukan diketik ulang.
3. Ganti `AGENTS.md` dengan teks di bawah `--- MULAI ---` pada `01`, setelah konflik
   `[K-I1]` dan `[K-I4]` diputuskan.
4. Verifikasi sebelum commit:
   - setiap rentang di `03` ada utuh di panduannya (`diff` rentang lama vs isi panduan);
   - daftar kata kunci aturan mengikat tetap ada di `AGENTS.md` baru: `IsolasiPerusahaanTest`,
     `KonteksPerusahaan`, `404, bukan 403`, `formula_version_id`, `STATUS_ARSIP`,
     `type_b_components`, `Nilai antara tidak dibulatkan`, `Master Data`,
     `akun:super-admin`, `User::roles()`, `rolesInternal`, `HakTulisPanel`,
     `JejakLintasOrganisasi`, `GERBANG_PENGESAHAN`, `PintuDaftarMandiriTertutupTest`,
     `pengajuan_akun_pelanggan`, `asmo_dev`, `gh repo view`, `SATU PUSH`,
     `@docs/aturan-akses-database.md`, `HitungUlangSesi`, `IFERROR`, `EquipmentFactory`;
   - tidak ada `@docs/panduan/` di `AGENTS.md` (panduan tidak boleh diimpor).
5. Commit, PR, CI. Merge sesuai keputusan K-I1. Merge ke `main` memicu deploy walau hanya
   dokumen, jadi ikuti SATU PUSH, SATU DEPLOY.

### Lapis lokal (`CLAUDE.local.md` + `.claude/panduan-ui-lokal.md`) — tidak ter-track

1. Salin `CLAUDE.local.md` ke cadangan (lihat §4).
2. Tulis `.claude/panduan-ui-lokal.md` (pindahan bagian UI) dan **tambahkan pathnya ke
   `.git/info/exclude`**. `.claude/settings.json` ter-track, jadi berkas baru di `.claude/`
   bisa ikut ter-stage tanpa sengaja.
3. Ringkas `CLAUDE.local.md` sesuai `03` §B.1.

### Lapis global (`~/.claude/`)

1. Salin `~/.claude/CLAUDE.md` dan seluruh `~/.claude/rules/` ke cadangan.
2. Tulis `~/.claude/rules/inti.md` (draft `02` §B) dan dua panduan di `~/.claude/panduan/`.
3. **Pindahkan** (bukan hapus) tujuh berkas rules lama ke folder cadangan.
4. Ganti `~/.claude/CLAUDE.md` dengan draft `02` §A.

### Verifikasi sesudah menerapkan

Di sesi **baru**: `/memory` hanya menampilkan berkas yang diharapkan (tidak ada panduan),
lalu `/context` untuk angka token nyata. Bandingkan dengan baseline 35,5 ribu token.

## 4. Cara mengembalikan

| Lapis | Cara | Waktu |
|---|---|---|
| Proyek | `git revert <commit>` lewat PR; atau `git show 0beb5bb:AGENTS.md > AGENTS.md` lalu hapus `docs/panduan/` | menit |
| Lokal | Salin balik `CLAUDE.local.md` dari cadangan; hapus `.claude/panduan-ui-lokal.md` | detik |
| Global | Salin balik tujuh berkas rules + `CLAUDE.md` dari cadangan; hapus `inti.md` dan `~/.claude/panduan/` | detik |

Lokasi cadangan yang diusulkan: `~/.claude/backups/instruksi-2026-10-01/` (folder
`~/.claude/backups/` sudah ada). Isinya hanya teks aturan, tanpa rahasia.

Tanda untuk mengembalikan: sesi baru melanggar aturan yang dulu dipatuhi, panduan tidak
dibaca saat pemicunya terjadi, atau `/context` tidak menunjukkan penghematan.

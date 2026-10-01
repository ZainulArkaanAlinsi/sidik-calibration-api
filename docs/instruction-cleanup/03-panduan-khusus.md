# 03 — Panduan khusus (dibaca saat relevan, tidak diimpor)

> **DRAFT, belum berlaku.** Tidak ada berkas aktif yang diubah.
>
> Tiap panduan di bawah **memindahkan teks `AGENTS.md` apa adanya**, kecuali perubahan
> yang ditulis eksplisit di kolom "Ubahan saat dipindah". Pemindahan apa adanya dipilih
> supaya tidak ada aturan atau alasan yang hilang lewat parafrase. Rentang baris mengacu
> ke `AGENTS.md` di commit `0beb5bb` (744 baris) dan sudah dicek dengan `sed -n`.
>
> Panduan **tidak** ditulis dengan `@` di `AGENTS.md`. Import `@` memuat isinya ke setiap
> sesi dan membatalkan penghematannya. Yang tertinggal di `AGENTS.md` hanya penunjuk dan
> pemicu.

## A. Panduan proyek (`docs/panduan/`, ter-track di repo)

| Berkas tujuan | Pemicu baca | Sumber (dipindah apa adanya) | Ubahan saat dipindah | Yang tetap di inti |
|---|---|---|---|---|
| `test-mysql-lokal.md` | Menyiapkan suite MySQL di mesin baru; koneksi test ditolak | §Test baris 29–57: alasan dua suite (versi panjang), SQL `CREATE DATABASE`/`CREATE USER`/`GRANT`, alasan hak sempit (mesin memegang `sidik_db` + lima DB lain), pembungkus `jalankan-test-mysql.ps1`, catatan mesin 24 Sep (MySQL 8.0.44, `MySQL80`, port 3306), durasi | — | Dua suite wajib + alasan singkat; DB & user khusus; sandi tidak di repo; durasi |
| `super-admin.md` | Menyentuh rute, panel, channel, izin, atau perintah yang melibatkan `super_admin` | §Keadaan nyata `super_admin` baris 417–503: tabel 12 pintu, test penjaga (`SuperAdminAksesTest`, `SuperAdminLintasOrganisasiTest`, `PerintahAkunSuperAdminTest`), alasan lahir lewat artisan, dua daftar peran, kenapa baca saja, pengecualian 29 Sep, `HakTulisPanel`, keputusan lintas organisasi (panel dibuka, API ditunda, ±60 penyaring di 15 controller), `JejakLintasOrganisasi`, konstanta & migrasi ENUM | Tambah baris pembuka: "Aturan mengikat ada di `AGENTS.md` §super_admin; berkas ini rincian & alasan." | Tujuh butir mengikat (lihat `01`) |
| `akun-undangan.md` | Menyentuh auth, OTP, pendaftaran, undangan, atau pengelolaan akun | §Akun Lahir dari Undangan baris 504–559: tabel siapa/lahir dari/yang dicabut, alasan (antrean bisa dibanjiri, klaim "dari PT X"), daftar yang sengaja ditinggal beserta alasannya, celah sandi awal, penutupan 24 Sep (`->profile()`, kegagalan `MAIL_MAILER=log` 7 Sep) | — | Larangan pendaftaran mandiri, larangan membangun ulang, daftar yang ditinggal, celah yang diterima |
| `riwayat-git-publik.md` | Membahas visibilitas repo, sanitasi riwayat, rotasi kredensial, atau force-push | §Data sumber baris 181–200 (paragraf "JANGAN percaya status repo" + kejadian `HydrometerSeeder` 19 Sep) dan §Sebelum repo dibalik jadi PUBLIK baris 210–249 (tabel tiga temuan riwayat, penjelasan `rahasia123`, urutan tiga langkah) | Judul diubah jadi "Riwayat git & visibilitas repo". **Tambah fakta 1 Okt 2026:** `gh repo view` mengembalikan `PUBLIC` untuk ketiga repo (API, mobile, pelanggan) | Cek visibilitas via `gh`; sapu nama sebelum `git add`; `asmo_dev` dianggap bocor; force-push hanya dengan perintah eksplisit; `rahasia123` bukan rahasia |
| `deploy-satu-push.md` | Langkah `Pastiin versi barunya beneran naik` merah; merencanakan beberapa push | §SATU PUSH, SATU DEPLOY baris 570–603: kejadian 19 Sep (lima commit, `a9a1ed0` tidak pernah disajikan), kenapa riwayat deploy harus cocok dengan riwayat commit, cara membaca CI merah | — | Dua aturan + cara diagnosis singkat |
| `mcp.md` | Ditawari atau menimbang pemasangan MCP | §MCP baris 609–657: alasan Aiven tidak dipasang (tidak bisa SQL; bisa menghapus database), dua syarat keras, Render baca saja + kejadian `ARSIP_DRIVER` 1 Sep, aturan umum | — | Ringkasan keputusan + syarat keras |

### Bagian `AGENTS.md` yang dibuang karena basi (bukan dipindah)

| Baris | Isi | Alasan dibuang | Pengganti |
|---|---|---|---|
| 252–256 | "Per 16 Sep 2026 **belum ada satu baris pun** [modul pelanggan] … paket rancangannya (`docs/pelanggan/`) juga belum masuk repo" | Sudah tidak benar: `routes/api_pelanggan.php`, `IsolasiPerusahaanTest`, `PintuDaftarMandiriTertutupTest`, dan `docs/pelanggan/00`–`09` + ADR-001 ada (dicek 1 Okt 2026) | Tujuh aturan tetap di inti, tanpa kalimat status |
| 78 | "24 perintah, dihitung 24 Sep 2026" | Sekarang 25 berkas di `app/Console/Commands/` (1 Okt 2026); angka akan terus basi | Inti hanya menunjuk direktorinya |
| 324–331 | Sensus "288 dari 288 berstempel" + produksi "2 sesi nyata, nol `uncertainty_calculations`" (23 Sep) | Angka sensus bertanggal; aturannya tetap | Inti menyimpan aturannya ("tanpa stempel = cacat jalur penyimpanan") |
| 722–731 | Rincian kejadian `db:seed` 18 Sep (26 seeder, enam sesi, `SapuSertifikatTertunda`) | Cerita kejadian; aturannya tetap | Inti menyimpan aturan + penjaga + status "belum diputuskan". Ceritanya tetap ada di riwayat git `AGENTS.md` |

## B. Panduan lokal (`.claude/`, tidak ter-track)

### B.1 `CLAUDE.local.md` (8.115 byte) → versi ringkas + satu panduan

Yang **tetap** di `CLAUDE.local.md` (dimuat tiap sesi, ±3 KB, estimasi):

- Daftar berkas yang tidak boleh masuk commit/push: `graphify-out/`,
  `.claude/settings.json.graphify-bak`, `CLAUDE.local.md`, hook graphify. Jangan akali
  blokir dengan `--no-verify`.
- `.claude/settings.json` dan `.gitattributes` diubah setup graphify → tanya user sebelum
  ikut di-commit; tunjukkan `git status --short`.
- Pull/merge: bentrok di dua berkas itu → `git stash` / pull / `git stash pop`, jangan
  `reset --hard` atau `checkout --`. `graph.json` bentrok → `graphify update .`.
- **Jangan jalankan `graphify claude install`** (menempel ke `CLAUDE.md` yang ter-track).
  Kalau telanjur: `graphify claude uninstall` lalu `git diff CLAUDE.md`.
- Satu baris: "Pekerjaan UI → baca `.claude/panduan-ui-lokal.md` dulu."

Yang **dipindah** ke `.claude/panduan-ui-lokal.md` (untracked, 3.706 byte): seluruh bagian
"Aturan kerja UI / desain (lokal)" — permukaan UI, token desain proyek menang, referensi
brand, screenshot → kode, dan delapan langkah verifikasi. Termasuk langkah 0: minta izin
baca database karena `SESSION_DRIVER`/`CACHE_STORE=database` menulis ke produksi; jangan
`composer dev`; jangan submit form saat verifikasi visual.

Yang **dibuang karena duplikat**:

| Teks | Alasan | Pengganti |
|---|---|---|
| Lima baris "Rules: For codebase questions, first run `graphify query` …" | Sudah ditegakkan hook PreToolUse graphify di tiap Grep/Read/Bash | Hook graphify |
| Catatan sejarah "19 Sep 2026 — `CLAUDE.md` dicabut dari daftar ini" (±900 byte) | Cerita perpindahan; aturannya sudah tercermin | Komentar di kepala `CLAUDE.md` yang ter-track |

### B.2 `MEMORY.md` (3.314 byte)

Tidak diubah oleh paket ini; dikelola lewat mekanisme memory. Catatan: beberapa baris
indeks (mis. "Lanjutan 26 Sep", "Lanjutan 28 Sep") menunjuk pekerjaan yang sudah selesai.
Merapikannya keputusan terpisah.

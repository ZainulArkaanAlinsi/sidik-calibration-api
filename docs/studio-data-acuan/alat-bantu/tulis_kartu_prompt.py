"""Tulis KARTU.md per paket, INDEX katalog, dan prompt Claude Code per alat.

Dijalankan sesudah bangun_katalog.py:
    python3 -I tulis_kartu_prompt.py <folder-keluaran>
"""
import json, os, sys

OUT = sys.argv[1]
KAT = os.path.join(OUT, 'katalog')
paket = []
for kode in sorted(os.listdir(os.path.join(KAT, 'paket'))):
    paket.append(json.load(open(os.path.join(KAT, 'paket', kode, 'skema.json'), encoding='utf-8')))

URUT_KELOMPOK = ['Panjang', 'Massa', 'Waktu', 'Gaya', 'Aliran', 'Volume', 'Tekanan', 'Suhu', 'Lintas alat',
                 'Analitik', 'Massa jenis', 'Suhu & tekanan']
def kunci_urut(p):
    return (0 if p.get('pilot') else 1, ['json', 'seed-standards', 'seed-db', 'konstanta-php'].index(p['jenis']),
            URUT_KELOMPOK.index(p['kelompok']) if p['kelompok'] in URUT_KELOMPOK else 99, p['kode'])
paket.sort(key=kunci_urut)

GOL = {
    'acuan_lapis1': 'tab data acuan (bisa disunting lewat versi)',
    'bentuk_lembar': 'tab Bentuk Lembar (lapis 3)',
    'rumus_baca_saja': 'tab Rumus — baca saja (lapis 2)',
    'pratinjau_sertifikat': 'pratinjau sertifikat di layar Simulasi',
    'versi_workbook': 'sumber nomor versi workbook (F1)',
    'database_internal_jangan_disalin_kecuali_sel_terpetakan': 'hanya sel terpetakan (mis. pita CMC); data pelanggan TIDAK PERNAH',
    'dokumen_bukan_data': 'bukan data — tidak masuk Studio',
}
STATUS = {
    'json': 'Gelombang 3 — pindah ke SumberAcuan (prompt P7)',
    'seed-standards': 'Lintas alat — tampilkan & versikan dari tabel `standards` (prompt P7, mode seed)',
    'seed-db': 'Lintas alat — versikan `calibration_capabilities` (prompt P7, mode seed)',
    'konstanta-php': 'Gelombang 4 — ekstrak konstanta dari kode dulu (prompt P8)',
}

def md_kode(xs):
    return ', '.join(f'`{x}`' for x in xs) if xs else '—'

def kartu(p):
    L = []
    L.append(f"# {p['nama']} — paket `{p['kode']}`\n")
    if p.get('pilot'):
        L.append('> **Alat pilot.** Dikerjakan pertama di Gelombang 2 (prompt P2–P4), sebelum alat lain.\n')
    L.append('| | |\n|---|---|')
    L.append(f"| Kelompok | {p['kelompok']} |")
    L.append(f"| Sumber data acuan hari ini | {p['jenis']} — {md_kode(['database/data/' + j for j in p['json']])} |")
    L.append(f"| Status di Studio | {'Pilot (Gelombang 2)' if p.get('pilot') else STATUS[p['jenis']]} |")
    L.append(f"| Profil | {md_kode(p['profil'])} |")
    L.append(f"| Kalkulator | {md_kode(p['kalkulator'])} |")
    L.append(f"| Kelas tabel | {md_kode(p['kelas'])} |")
    L.append(f"| Formulir resmi | {', '.join(p['formulir_pdf']) or '— (tidak ada di worksheet_alat_calibration/)'} |")
    L.append(f"| Folder master | {md_kode(p['master'])} |")
    L.append(f"| Generator | {md_kode(['docs/skrip/' + g for g in p['generator']])} |")
    L.append(f"| Fixture master | {md_kode(['database/data/' + f for f in p['fixture_ada']])} |")
    L.append(f"| Pertanyaan lab | {md_kode(p['pertanyaan_lab'])} |")
    L.append(f"| Serah-terima frontend | {md_kode(p['perintah_frontend'])} |")
    L.append('')
    if p.get('catatan_master'):
        L.append(f"**Catatan:** {p['catatan_master']}\n")
    if p.get('pakai_juga'):
        L.append(f"**Juga membaca paket:** {md_kode(p['pakai_juga'])} — simulasi wajib menjalankan pemakai paket itu.\n")

    dipakai = {k: v for k, v in p.get('kelas_dipakai_oleh', {}).items() if v}
    if dipakai:
        L.append('**Kelas tabel ini dibaca juga oleh** (simulasi & test mereka ikut wajib):\n')
        for k, v in dipakai.items():
            L.append(f"- `{k}` ← {', '.join(f'`{x}`' for x in v)}")
        L.append('')
    L.append('## Berkas kode yang terlibat\n')
    for f in p['berkas_kode']:
        L.append(f'- `{f}`')
    L.append('\n## Test yang wajib tetap hijau tanpa diubah\n')
    for t in p['test_terkait'] or ['— (belum ada test yang menyebut kelas/profil ini; buat test setara versi 1 dulu)']:
        L.append(f'- `{t}`' if t.startswith('tests') else f'- {t}')

    L.append('\n## Peta Excel → Studio\n')
    if not p['sheet_master']:
        L.append('Tidak ada CSV master di `alat-alat-Pt-Sidik/` untuk paket ini.\n')
    else:
        L.append('| Workbook (folder) | Sheet | Jadi apa di Studio | Baris CSV |\n|---|---|---|---|')
        for s in p['sheet_master']:
            if not s.get('ada', True) and 'sheet' not in s:
                L.append(f"| `{s['master']}` | (folder tidak ditemukan) | — | — |"); continue
            L.append(f"| `{s['master'].split('/')[-1]}` | {s['sheet']} | {GOL[s['golongan']]} | {s['jumlah_baris_csv']} |")
        L.append('')
        tampil = [s for s in p['sheet_master'] if s.get('cuplikan_atas')]
        if tampil:
            L.append('### Cuplikan atas sheet acuan (nilai CSV, tanpa rumus)\n')
            L.append('Dipakai untuk mengenali judul kolom & tata letak. **Rumus dan alamat sel resmi diambil dari `.xlsm` asli, bukan dari sini.**\n')
            for s in tampil[:12]:
                L.append(f"**{s['master'].split('/')[-1]} › {s['sheet']}**\n")
                L.append('```')
                L.extend(s['cuplikan_atas'][:6])
                L.append('```')

    if p['lembar']:
        L.append('\n## Lembar data acuan (dari JSON — 100% sel tercakup)\n')
        for jf, c in p['cakupan'].items():
            L.append(f"- `{jf}`: {c['tercakup']}/{c['daun']} sel data terwakili lembar di bawah.")
        L.append('\n| Jalur lembar | Bentuk | Baris | Sel | Tampilan | Kolom (kunci · tipe · satuan tebakan) |\n|---|---|---|---|---|---|')
        for l in p['lembar']:
            kol = []
            if l['bentuk'] == 'peta':
                for r in l.get('baris', [])[:12]:
                    kol.append(f"{r['kunci']}·{r['tipe']}" + (f"·{r['satuan_tebakan']}" if r.get('satuan_tebakan') else ''))
                if len(l.get('baris', [])) > 12: kol.append(f"… +{len(l['baris']) - 12}")
            else:
                for c in l.get('kolom', [])[:10]:
                    kol.append(f"{c['kunci']}·{c['tipe']}" + (f"·{c['satuan_tebakan']}" if c.get('satuan_tebakan') else ''))
                if len(l.get('kolom', [])) > 10: kol.append(f"… +{len(l['kolom']) - 10}")
            L.append(f"| `{l['jalur']}` | {l['bentuk']} | {l['jumlah_baris']} | {l['jumlah_sel_data']} | {l['tampilan_usulan']} | {'; '.join(kol).replace('|', '/')} |")
        L.append('\nRincian lengkap tiap kolom & contoh nilai: `skema.json` di folder ini.\n')

    if p.get('kandidat_konstanta'):
        L.append('\n## Kandidat nilai acuan yang masih ditulis di kode\n')
        L.append('Dikumpulkan otomatis (konstanta kelas + literal `ci`/`vi`/`u`/`titik`/… di method). '
                 '**Belum digolongkan.** Pakai aturan AGENTS.md §Olah data: nilai standar, koreksi, CMC, MPE, '
                 'tabel koefisien, konstanta fisika metode → lapis 1 (pindah ke paket); ekspresi & struktur budget → '
                 'lapis 2 (tetap kode); kode dokumen, label, satuan tampil → lapis 3.\n')
        L.append('| Berkas:baris | Nama | Nilai awal | Jenis |\n|---|---|---|---|')
        for c in p['kandidat_konstanta']:
            L.append(f"| `{c['berkas'].split('/')[-1]}:{c['baris']}` | `{c['nama']}` | `{c['nilai_awal'].replace('|', '/')}` | {c['jenis']} |")

    if p['rujukan_sel_ditemukan']:
        L.append('\n## Rujukan sel master yang ditemukan di generator/kode\n')
        L.append('Belum dipetakan ke kolom satu-satu. Pakai sebagai titik awal peta sel (`kolom_asal` di skema), konfirmasi di `.xlsm`.\n')
        L.append(', '.join(f'`{r}`' for r in p['rujukan_sel_ditemukan']))

    if p['metadata_json']:
        L.append('\n## Catatan yang sudah tertulis di JSON acuan\n')
        for k, v in list(p['metadata_json'].items())[:8]:
            L.append(f"- **{k}**: {str(v)[:600]}")

    L.append('\n## Yang wajib dikonfirmasi sebelum paket ini "selesai"\n')
    L.append('- Satuan tiap kolom (kolom `satuan_tebakan` hanya tebakan dari nama kunci).')
    L.append('- Alamat sel asal tiap kolom dari `.xlsm` asli + sha256 workbook.')
    L.append('- `kontrakInput()` profil: field lembar yang dibaca rumus.')
    L.append('- Toleransi uji pembanding per besaran — ditetapkan Lab (06-Test-Plan §3).')
    return '\n'.join(L) + '\n'

def prompt_alat(p, no):
    k = p['kode']
    if p['jenis'] == 'konstanta-php':
        mode = 'P8 — ekstrak konstanta dari kode ke paket'
    elif p['jenis'] in ('seed-standards', 'seed-db'):
        mode = 'P7 (mode seed) — versikan data yang sudah ada di tabel database'
    else:
        mode = 'P7 — pindahkan tabel JSON ke SumberAcuan ber-versi'
    tests = '\n'.join(f'   - `{t}`' for t in p['test_terkait']) or '   - (belum ada — buat test setara versi 1 lebih dulu)'
    berkas = '\n'.join(f'   - `{f}`' for f in p['berkas_kode']) or '   - (lihat KARTU)'
    lab = ', '.join(f'`{x}`' for x in p['pertanyaan_lab']) or '—'
    L = f"""# Prompt Claude Code {no:02d} — paket `{k}` ({p['nama']})

Mode: **{mode}**. Tempel seluruh isi berkas ini ke Claude Code yang dibuka di repo
`sidik-calibration-api`.

---

Kerjakan pemindahan paket data acuan **`{k}`** ke Studio Data Acuan.

## 0. Gerbang — periksa dulu, berhenti kalau gagal

1. `git pull origin main`, lalu buat branch `feat/acuan-{k.replace('_', '-')}`. Jangan bekerja di `main`.
2. Pastikan Gelombang 1 & 2 sudah mendarat: ada model `PaketAcuan`, `PaketAcuanVersi`, kelas
   `App\\Services\\Calibration\\SumberAcuan`, perintah `acuan:impor-awal`, dan `TabelAcuanSetaraJsonTest`.
   Kalau salah satu belum ada: **berhenti**, laporkan yang kurang, jangan membangunnya diam-diam di prompt ini.

## 1. Baca sebelum mengetik

- `AGENTS.md` (§Olah data — aturan keras, §Aturan yang Lahir dari Kesalahan Nyata)
- `docs/studio-data-acuan/03-SDD-ADR.md` §2.6, §4, ADR-03, ADR-04, ADR-08
- `docs/studio-data-acuan/katalog/paket/{k}/KARTU.md` dan `skema.json` — **ini peta paketnya**
- Berkas kode:
{berkas}
- Pertanyaan lab terbuka: {lab}

Tulis daftar berkas yang AKAN diubah sebelum mulai (AGENTS.md §Alur Kerja 3).

## 2. Kerjakan
"""
    if p['jenis'] == 'konstanta-php':
        L += f"""
1. Golongkan setiap baris di KARTU §"Kandidat nilai acuan yang masih ditulis di kode" menjadi
   lapis 1 / 2 / 3 memakai aturan AGENTS.md §Olah data butir 1. Tulis tabel golongannya di laporan.
   Yang ragu → tulis sebagai pertanyaan bernomor di `docs/pertanyaan-lab-{k.replace('_', '-')}.md`, jangan diputuskan sendiri.
2. Buat `database/data/acuan-{k.replace('_', '-')}.json` berisi nilai lapis 1 **persis** seperti di kode
   (angka sebagai string kanonik, ADR-08). Tiap nilai diberi `_asal` = berkas:baris kode hari ini.
3. Refactor profil supaya nilai lapis 1 dibaca lewat `SumberAcuan` (bukan konstanta). Perilaku tidak boleh berubah.
4. Tambahkan `skemaAcuan()` di profil, cocok dengan isi JSON baru.
5. `php artisan acuan:impor-awal {k}` → versi 1. Tambahkan kasus `{k}` ke `TabelAcuanSetaraJsonTest`
   yang membuktikan float yang sampai ke kalkulator identik bit dengan konstanta lama.
6. Nilai yang sudah ada di tabel `standards` (U, k, drift standar) **jangan diduplikasi** ke paket — rujuk saja.
"""
    elif p['jenis'] in ('seed-standards', 'seed-db'):
        L += f"""
1. Data paket ini SUDAH berada di tabel database sesudah seed ({'`standards`' if p['jenis'] == 'seed-standards' else '`calibration_capabilities`'}).
   **Jangan membuat salinan kedua.** Versi 1 paket dibangun dari baris tabel itu, bukan dari JSON seed.
2. Rancang `skemaAcuan()` khusus paket lintas alat ini sesuai `skema.json`, lalu jalur baca yang
   memakai versi berlaku pada `tanggal_kalibrasi` sesi (pola `RumusKalibrasi`).
3. Penyuntingan lewat Studio harus memperbarui tabel sumbernya melalui alur versi (draf → simulasi →
   sahkan), bukan menulis langsung. Simulasi wajib menjalankan **semua profil** yang memakai paket ini.
4. Tulis test yang membuktikan seeder lama dan versi 1 menghasilkan angka identik.
"""
    else:
        L += f"""
1. Tambahkan `skemaAcuan()` pada profil {md_kode(p['profil'])}: lembar, kolom, tipe, kunci baris, boleh tambah
   baris atau tidak — sesuai `skema.json`. Satuan diambil dari komentar/kode/workbook, **bukan** dari
   `satuan_tebakan`. Kolom yang asal selnya belum terbukti diberi `asal: perlu_konfirmasi`.
2. Ubah {md_kode(p['kelas'])} supaya membaca isi versi lewat `SumberAcuan`. **Buang cache statis per
   proses** (`private static ?array $data`) — ganti cache per (versi_id, sha256) (03-SDD §4).
3. `php artisan acuan:impor-awal {k}` → versi 1 `aktif`; sha256 kanonik isi = sha256 kanonik
   `database/data/{', '.join(p['json'])}`.
4. Tambahkan kasus `{k}` ke `TabelAcuanSetaraJsonTest`: setiap float yang diberikan ke kalkulator
   identik bit antara jalur JSON lama dan jalur versi.
5. Bila paket ini dibaca profil lain ({md_kode(p.get('pakai_juga', []))}), jalankan juga test mereka.
"""
    L += f"""
## 3. Jangan

- Mengubah rumus, kalkulator, urutan hitung, pembulatan, atau satu angka acuan pun.
- Menyunting `database/data/*.json` dengan tangan (generator saja yang boleh menulisnya).
- Memasukkan nama/alamat pelanggan dari workbook ke berkas mana pun.
- Commit atau push ke `main`. Push branch boleh hanya bila diminta.

## 4. Selesai bila

- Test berikut hijau **tanpa diubah**, di SQLite DAN MySQL (`php artisan test -c phpunit.mysql.xml`):
{tests}
- `TabelAcuanSetaraJsonTest` kasus `{k}` hijau.
- `skemaAcuan()` mencakup 100% sel data paket (skrip `alat-bantu/validasi_katalog.py` di ZIP).

## 5. Laporan (tempel balik ke Zainul)

Berkas yang diubah; perintah test + hasilnya (SQLite & MySQL); sha256 versi 1; kolom yang masih
`perlu_konfirmasi`; pertanyaan lab baru (bernomor); risiko tersisa.
"""
    return L

os.makedirs(os.path.join(OUT, 'prompts', 'alat'), exist_ok=True)
idx = ['# Katalog paket data acuan — semua alat\n',
       'Dibangkitkan dari repo `sidik-calibration-api` (8 Okt 2026) oleh `alat-bantu/bangun_katalog.py`.\n',
       '| No | Paket | Alat | Kelompok | Sumber hari ini | Lembar | Sel data | Sheet master | Kartu | Prompt |',
       '|---|---|---|---|---|---|---|---|---|---|']
for i, p in enumerate(paket, 1):
    open(os.path.join(KAT, 'paket', p['kode'], 'KARTU.md'), 'w', encoding='utf-8').write(kartu(p))
    nama_prompt = f"{i:02d}-{p['kode']}.md"
    open(os.path.join(OUT, 'prompts', 'alat', nama_prompt), 'w', encoding='utf-8').write(prompt_alat(p, i))
    sel = sum(c['daun'] for c in p['cakupan'].values())
    idx.append(f"| {i} | `{p['kode']}` | {p['nama']} | {p['kelompok']} | {p['jenis']} | {len(p['lembar'])} | {sel or '—'} | "
               f"{len([s for s in p['sheet_master'] if 'sheet' in s])} | [KARTU](paket/{p['kode']}/KARTU.md) | "
               f"[prompt](../prompts/alat/{nama_prompt}) |")
idx.append('\n**Arti sumber:** `json` = tabel di `database/data/` (siap dipindah); `seed-standards`/`seed-db` = data '
           'sudah di tabel database; `konstanta-php` = nilai masih ditulis di kelas profil (diekstrak dulu).')
open(os.path.join(KAT, 'INDEX.md'), 'w', encoding='utf-8').write('\n'.join(idx) + '\n')
print('kartu & prompt:', len(paket))

<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\ExcelImporter;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

/**
 * Import Excel buat masa transisi (spesifikasi poin 12C).
 *
 * **Dua langkah, dan itu penjagaannya**: unggah → UJI COBA → baca ringkasannya
 * → baru TERAPKAN. Tombol Terapkan nggak aktif sebelum uji coba jalan. Satu
 * tombol yang langsung nulis ke master data dari file Excel orang lain itu
 * cara paling cepat ngerusak data, dan yang rusak baru ketahuan
 * berminggu-minggu kemudian waktu ada sertifikat yang datanya aneh.
 *
 * Uji coba di `ExcelImporter` tetap NULIS beneran dulu lalu di-rollback — cuma
 * dengan begitu "sudah ada / belum" & error constraint kelihatan apa adanya,
 * bukan ditebak.
 */
class ImportExcel extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Pengaturan';

    protected static ?string $navigationLabel = 'Import Excel';

    protected static ?string $title = 'Import Excel';

    protected string $view = 'filament.pages.import-excel';

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * Hasil uji coba terakhir. Null = belum pernah uji coba, jadi Terapkan
     * masih dikunci.
     *
     * @var array<string, mixed>|null
     */
    public ?array $hasil = null;

    public function mount(): void
    {
        abort_unless(User::yangLogin()?->isAdmin() ?? false, 403);

        $this->form->fill(['tipe' => ExcelImporter::TIPE[0]]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Berkas')
                    ->description('Urutan import: pelanggan → standar → alat. '
                        .'Alat butuh PT-nya sudah ada duluan, jadi kalau kebalik barisnya kelewat semua.')
                    ->schema([
                        Select::make('tipe')
                            ->label('Mau import apa?')
                            ->options([
                                'customers' => 'Pelanggan / PT',
                                'standards' => 'Standar acuan',
                                'equipments' => 'Alat',
                            ])
                            ->required()
                            // Ganti tipe = ringkasan lama nggak berlaku lagi.
                            ->live()
                            ->afterStateUpdated(fn () => $this->hasil = null),
                        FileUpload::make('berkas')
                            ->label('File Excel / CSV')
                            // Disknya DIPATOK, jangan dihapus.
                            //
                            // Tanpa `->disk()`, Filament ngambil disk dari
                            // `config('filament.default_filesystem_disk')`, dan
                            // repo ini nggak punya config/filament.php sendiri —
                            // jadi yang berlaku bawaan vendor: FILESYSTEM_DISK.
                            //
                            // Akibatnya berkas naik ke disk mana pun yang lagi
                            // disetel di environment, sementara `jalankan()` di
                            // bawah bacanya selalu dari `local`. Waktu dua-duanya
                            // beda, import mati dengan notifikasi yang nyalahin
                            // penggunanya — "unggah ulang ya" — dan diunggah
                            // ulang berapa kali pun hasilnya sama.
                            ->disk('local')
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                                'text/csv',
                                'text/plain',
                            ])
                            ->maxSize(10240)
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn () => $this->hasil = null)
                            ->helperText('Maksimal 10 MB. Header dicocokin tanpa peduli huruf besar/kecil & spasi.'),
                    ]),
            ])
            ->statePath('data');
    }

    public function ujiCoba(): void
    {
        $this->jalankan(ujiCoba: true);
    }

    public function terapkan(): void
    {
        // Penjagaan intinya: nggak ada jalan pintas dari "pilih file" ke
        // "tulis ke database". Uji coba wajib jalan duluan.
        if ($this->hasil === null) {
            Notification::make()
                ->title('Jalankan uji coba dulu sebelum menerapkan.')
                ->warning()
                ->send();

            return;
        }

        $this->jalankan(ujiCoba: false);
    }

    private function jalankan(bool $ujiCoba): void
    {
        abort_unless(User::yangLogin()?->isAdmin() ?? false, 403);

        $data = $this->form->getState();
        $berkas = $data['berkas'] ?? null;

        // FileUpload nyimpen sebagai array [key => path] atau string, tergantung
        // multiple-nya. Diratain di sini biar nggak nebak di dua tempat.
        $path = is_array($berkas) ? reset($berkas) : $berkas;

        if (! $path) {
            Notification::make()->title('Pilih filenya dulu.')->warning()->send();

            return;
        }

        // Isinya DISALIN ke berkas sementara, bukan dibaca lewat path disk.
        //
        // `Storage::disk(...)->path()` cuma bermakna di driver `local`. Di S3/R2
        // dia nggak error — dia balikin kunci bucket sebagai string biasa, dan
        // `is_file()` atas string itu selalu false. Hasilnya import kelihatan
        // seperti "file hilang" padahal berkasnya ada, cuma bukan di filesystem.
        //
        // `get()` jalan di semua driver. PhpSpreadsheet tetap butuh berkas nyata,
        // makanya disalin ke temp dulu — bukan karena disknya lokal, tapi karena
        // pembacanya yang minta.
        $isi = Storage::disk('local')->get($path);

        if (! filled($isi)) {
            Notification::make()
                ->title('File-nya nggak kebaca di server. Unggah ulang ya.')
                ->danger()
                ->send();

            return;
        }

        // Ekstensinya ikut dibawa: PhpSpreadsheet milih pembaca dari ekstensi,
        // dan berkas tanpa ekstensi ditolak sebagai format tak dikenal.
        $ekstensi = pathinfo($path, PATHINFO_EXTENSION) ?: 'xlsx';
        $absolut = sys_get_temp_dir().DIRECTORY_SEPARATOR.'import-'.bin2hex(random_bytes(8)).'.'.$ekstensi;

        file_put_contents($absolut, $isi);

        try {
            $this->hasil = app(ExcelImporter::class)->jalankan(
                $absolut,
                $data['tipe'],
                User::yangLogin()->organization_id,
                $ujiCoba,
            );
        } catch (\Throwable $e) {
            $this->hasil = null;

            Notification::make()
                ->title('Import gagal.')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        } finally {
            // Berkas Excel pelanggan nggak boleh ngendon di /tmp sesudah
            // dipakai. `finally` biar kehapus juga waktu import-nya gagal.
            @unlink($absolut);
        }

        $r = $this->hasil['ringkasan'];

        Notification::make()
            ->title($ujiCoba
                ? 'Uji coba selesai — belum ada yang disimpan.'
                : 'Import diterapkan.')
            ->body(sprintf(
                '%d dibaca · %d dibuat · %d diperbarui · %d dilewati',
                $r['dibaca'] ?? 0,
                $r['dibuat'] ?? 0,
                $r['diperbarui'] ?? 0,
                $r['dilewati'] ?? 0,
            ))
            ->status($ujiCoba ? 'warning' : 'success')
            ->send();
    }

    public static function canAccess(): bool
    {
        return User::yangLogin()?->isAdmin() ?? false;
    }
}

<?php

namespace App\Filament\Resources\Users;

use App\Filament\Concerns\ScopesToOrganization;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UserResource extends Resource
{
    // Alias, bukan `use` polos: `getEloquentQuery()` di bawah menimpa method
    // milik trait, dan tanpa alias penyaringan per-organisasinya ikut hilang.
    use ScopesToOrganization {
        getEloquentQuery as private kueriSeorganisasi;
    }

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'Pengguna';

    protected static ?string $modelLabel = 'pengguna';

    protected static ?string $pluralModelLabel = 'pengguna';

    protected static ?string $recordTitleAttribute = 'name';

    protected static string|\UnitEnum|null $navigationGroup = 'Pengaturan';

    /**
     * Cuma akun INTERNAL yang kelihatan di layar Pengguna.
     *
     * ## Kenapa disaring di kueri, bukan di tabelnya
     *
     * Baris ber-role `pelanggan` duduk di organisasi yang sama dengan akun lab
     * (mereka memang pelanggan PT Sidik), jadi penyaringan per-organisasi nggak
     * menyentuhnya sama sekali. Sebelum ini akibatnya: akun pelanggan nongol di
     * daftar pengguna internal, dan `role` di formnya `->required()` dengan cuma
     * TIGA pilihan — admin/teknisi/viewer. Admin yang cuma mau membetulkan nomor
     * telepon seorang pelanggan TERPAKSA memilih salah satunya buat bisa
     * menyimpan, dan akun pelanggan itu berubah jadi akun lab. Dua klik, tanpa
     * satu pun peringatan.
     *
     * Itu persis risiko R-D02 yang ditulis di docblock `User`: orang dari PT A
     * melihat sertifikat PT B. Sisi API sudah dijaga `Rule::in(User::roles())`;
     * form Filament-nya nggak ikut terjaga.
     *
     * Disaring di `getEloquentQuery()` supaya barisnya nggak bisa dicapai lewat
     * jalur mana pun — Filament menyelesaikan record halaman Edit lewat kueri
     * yang sama, jadi menebak URL-nya pun dapat 404, bukan form yang kebuka.
     * Kalau disaring di tabelnya saja, barisnya cuma hilang dari daftar.
     */
    public static function getEloquentQuery(): Builder
    {
        return static::kueriSeorganisasi()->whereIn('role', User::roles());
    }

    /** Lencana angka di menu: berapa akun yang nunggu disetujui. */
    public static function getNavigationBadge(): ?string
    {
        $jumlah = static::getEloquentQuery()->where('status', User::STATUS_PENDING)->count();

        return $jumlah > 0 ? (string) $jumlah : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'employee_id', 'email'];
    }

    /**
     * @param  User  $record
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Role' => ucfirst($record->role),
            'Status' => ucfirst($record->status),
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}

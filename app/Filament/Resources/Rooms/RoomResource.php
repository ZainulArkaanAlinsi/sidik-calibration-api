<?php

namespace App\Filament\Resources\Rooms;

use App\Filament\Concerns\ScopesToOrganization;
use App\Filament\Resources\Rooms\Pages\CreateRoom;
use App\Filament\Resources\Rooms\Pages\EditRoom;
use App\Filament\Resources\Rooms\Pages\ListRooms;
use App\Filament\Resources\Rooms\Schemas\RoomForm;
use App\Filament\Resources\Rooms\Tables\RoomsTable;
use App\Models\Room;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Ruangan lab — "Calibration Location" di sertifikat.
 *
 * Sebelum ini ruangan cuma bisa diurus lewat `api/rooms`, artinya cuma dari HP.
 * Yang mendaftarin nama ruangan justru pemilik lab yang kerjanya di depan
 * komputer, jadi dia kepaksa nitip ke teknisi atau nembak API manual.
 */
class RoomResource extends Resource
{
    use ScopesToOrganization;

    protected static ?string $model = Room::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Ruangan Lab';

    protected static ?string $modelLabel = 'ruangan lab';

    protected static ?string $pluralModelLabel = 'ruangan lab';

    protected static ?string $recordTitleAttribute = 'nama';

    protected static string|\UnitEnum|null $navigationGroup = 'Master Data';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return RoomForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RoomsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    /**
     * Dicari pakai nama ATAU kode, alasan yang sama kayak `RoomController@index`:
     * orang lab hafalnya "R-01", yang kebaca di layar "Ruang Kalibrasi Massa".
     *
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['nama', 'kode'];
    }

    /**
     * @param  Room  $record
     * @return array<string, string>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            'Kode' => (string) $record->kode,
            'Aktif' => $record->aktif ? 'Ya' : 'Tidak',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRooms::route('/'),
            'create' => CreateRoom::route('/create'),
            'edit' => EditRoom::route('/{record}/edit'),
        ];
    }

    /**
     * Ruangan yang terlanjur kehapus lewat `DELETE api/rooms/{room}` tetap harus
     * bisa dibuka dari panel biar bisa dipulihin — tanpa ini route binding-nya
     * 404 dan satu-satunya jalan balik cuma lewat SQL.
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}

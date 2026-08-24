<?php

namespace App\Filament\Resources\Rooms\Pages;

use App\Filament\Resources\Rooms\RoomResource;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditRoom extends EditRecord
{
    protected static string $resource = RoomResource::class;

    /**
     * Sengaja tanpa `DeleteAction` & `ForceDeleteAction` — beda dari resource
     * master data lain di panel ini. Alasannya ditulis lengkap di aksi
     * `ubahStatus` punya `RoomsTable`: ruangan dinonaktifin, bukan dihapus,
     * karena sesi lama nunjuk ke sini lewat `belongsTo` tanpa `withTrashed()`.
     *
     * `RestoreAction` tetap ada buat mulangin ruangan yang terlanjur kehapus
     * lewat `DELETE api/rooms/{room}`.
     */
    protected function getHeaderActions(): array
    {
        return [
            RestoreAction::make(),
        ];
    }
}

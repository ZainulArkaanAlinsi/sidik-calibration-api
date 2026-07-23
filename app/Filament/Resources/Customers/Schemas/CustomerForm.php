<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\User;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Organisasi diisi otomatis dari admin yang login, bukan dipilih —
                // satu instalasi = satu PT.
                Hidden::make('organization_id')
                    ->default(fn () => User::yangLogin()?->organization_id),

                TextInput::make('nama')
                    ->label('Nama pelanggan')
                    ->required()
                    ->maxLength(255),
                TextInput::make('contact_person')
                    ->label('Narahubung')
                    ->maxLength(255),
                TextInput::make('telepon')
                    ->tel()
                    ->maxLength(50),
                TextInput::make('email')
                    ->email()
                    ->maxLength(255),
                TextInput::make('alamat')
                    ->columnSpanFull()
                    ->maxLength(255),
            ]);
    }
}

<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Hidden::make('organization_id')
                    ->default(fn () => auth()->user()->organization_id),

                TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->maxLength(255),
                TextInput::make('employee_id')
                    ->label('ID pegawai')
                    ->helperText('Dipakai buat login, harus unik.')
                    ->maxLength(50),
                TextInput::make('department')
                    ->label('Departemen')
                    ->maxLength(255),
                TextInput::make('email')
                    ->email()
                    ->required()
                    ->maxLength(255),
                Select::make('role')
                    ->options([
                        User::ROLE_ADMIN => 'Admin',
                        User::ROLE_TEKNISI => 'Teknisi',
                        User::ROLE_VIEWER => 'Viewer',
                    ])
                    ->default(User::ROLE_TEKNISI)
                    ->required(),
                Select::make('status')
                    ->options([
                        User::STATUS_AKTIF => 'Aktif',
                        User::STATUS_PENDING => 'Pending',
                        User::STATUS_NONAKTIF => 'Nonaktif',
                    ])
                    ->default(User::STATUS_PENDING)
                    ->required(),
                // Wajib pas bikin baru; pas edit, dikosongin = password nggak diubah.
                // Model cast 'hashed' yang ngehash — jangan sampai ke-hash dua kali.
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(255),
            ]);
    }
}

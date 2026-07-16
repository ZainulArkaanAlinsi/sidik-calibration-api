<?php

namespace App\Filament\Resources\Users\Tables;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('employee_id')
                    ->label('ID pegawai')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Nama')
                    ->searchable(),
                TextColumn::make('department')
                    ->label('Departemen')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('role')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        User::ROLE_ADMIN => 'danger',
                        User::ROLE_TEKNISI => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        User::STATUS_AKTIF => 'success',
                        User::STATUS_PENDING => 'warning',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        User::STATUS_AKTIF => 'Aktif',
                        User::STATUS_PENDING => 'Pending',
                        User::STATUS_NONAKTIF => 'Nonaktif',
                    ]),
                SelectFilter::make('role')
                    ->options([
                        User::ROLE_ADMIN => 'Admin',
                        User::ROLE_TEKNISI => 'Teknisi',
                        User::ROLE_VIEWER => 'Viewer',
                    ]),
            ])
            ->recordActions([
                // Setujuin pendaftar — cuma nongol buat akun yang masih pending.
                Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (User $record): bool => $record->status === User::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $record->update(['status' => User::STATUS_AKTIF]);
                        Notification::make()->title("Akun {$record->name} disetujui.")->success()->send();
                    }),

                // Tolak pendaftar + cabut sesi tokennya.
                Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (User $record): bool => $record->status === User::STATUS_PENDING)
                    ->requiresConfirmation()
                    ->action(function (User $record): void {
                        $record->update(['status' => User::STATUS_NONAKTIF]);
                        $record->tokens()->delete();
                        Notification::make()->title("Akun {$record->name} ditolak.")->warning()->send();
                    }),

                // Reset password manual — buat kasus yang /forgot-password nggak
                // bisa tolong (emailnya salah ketik). Semua sesi lama dicabut.
                Action::make('resetPassword')
                    ->label('Reset password')
                    ->icon('heroicon-o-key')
                    ->color('warning')
                    ->schema([
                        TextInput::make('password')
                            ->label('Password baru')
                            ->password()
                            ->revealable()
                            ->required()
                            ->minLength(8),
                    ])
                    ->action(function (User $record, array $data): void {
                        $record->update(['password' => Hash::make($data['password'])]);
                        $record->tokens()->delete();
                        Notification::make()
                            ->title("Password {$record->name} diganti. Kasih tahu langsung ke orangnya.")
                            ->success()
                            ->send();
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}

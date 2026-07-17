<?php

namespace App\Filament\Resources\Certificates\Schemas;

use App\Models\Certificate;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CertificateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Informasi Sertifikat')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('nomor')
                            ->label('Nomor sertifikat')
                            ->placeholder('—'),
                        TextEntry::make('status')
                            ->badge()
                            ->color(fn (string $state): string => match ($state) {
                                Certificate::STATUS_TERBIT => 'success',
                                Certificate::STATUS_MENUNGGU_GENERATE => 'warning',
                                Certificate::STATUS_GAGAL => 'danger',
                                default => 'gray',
                            }),
                        TextEntry::make('diterbitkan_pada')
                            ->label('Diterbitkan')
                            ->date('d F Y')
                            ->placeholder('—'),
                        TextEntry::make('berlaku_sampai')
                            ->label('Berlaku sampai')
                            ->date('d F Y')
                            ->placeholder('—'),
                    ]),

                Section::make('Sesi & Alat')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('session.nomor_sesi')
                            ->label('Sesi kalibrasi')
                            ->placeholder('—'),
                        TextEntry::make('session.equipment.nama_alat')
                            ->label('Alat')
                            ->placeholder('—'),
                    ]),

                Section::make('QR & Revisi')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('qr_token')
                            ->label('Token QR')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('revisionOf.nomor')
                            ->label('Revisi dari sertifikat')
                            ->placeholder('Bukan revisi'),
                        TextEntry::make('alasan_revisi')
                            ->label('Alasan revisi')
                            ->placeholder('—')
                            ->columnSpanFull()
                            ->visible(fn (Certificate $record): bool => filled($record->alasan_revisi)),
                    ]),
            ]);
    }
}

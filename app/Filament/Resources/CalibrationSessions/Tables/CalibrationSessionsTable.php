<?php

namespace App\Filament\Resources\CalibrationSessions\Tables;

use App\Jobs\GenerateCertificate;
use App\Models\CalibrationSession;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CalibrationSessionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('nomor_sesi')
                    ->label('Nomor sesi')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('equipment.nama_alat')
                    ->label('Alat')
                    ->searchable(),
                TextColumn::make('teknisi.name')
                    ->label('Teknisi')
                    ->searchable(),
                TextColumn::make('tanggal_kalibrasi')
                    ->label('Tanggal')
                    ->date()
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        CalibrationSession::STATUS_DISETUJUI => 'success',
                        CalibrationSession::STATUS_MENUNGGU_APPROVAL => 'warning',
                        CalibrationSession::STATUS_PERLU_REVISI => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('keputusan')
                    ->badge()
                    ->color(fn (?string $state): string => $state === 'PASS' ? 'success' : 'danger'),
                TextColumn::make('certificate.nomor')
                    ->label('Sertifikat')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->options([
                    CalibrationSession::STATUS_DRAFT => 'Draft',
                    CalibrationSession::STATUS_MENUNGGU_APPROVAL => 'Menunggu approval',
                    CalibrationSession::STATUS_DISETUJUI => 'Disetujui',
                    CalibrationSession::STATUS_PERLU_REVISI => 'Perlu revisi',
                ]),
                // Sesi FAIL = alat "tidak laik pakai" — perlu gampang
                // ditemukan lintas status, bukan cuma keliatan badge-nya doang
                // pas udah scroll ke barisnya.
                SelectFilter::make('keputusan')->options([
                    'PASS' => 'PASS',
                    'FAIL' => 'FAIL',
                ]),
            ])
            ->recordActions([
                ViewAction::make()
                    ->schema(fn (Schema $schema): Schema => self::detail($schema)),

                // Approve → sesi disetujui + sertifikat digenerate di queue.
                // Nongol cuma buat sesi yang emang lagi nunggu approval.
                Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (CalibrationSession $record): bool => $record->status === CalibrationSession::STATUS_MENUNGGU_APPROVAL)
                    ->requiresConfirmation()
                    ->modalDescription('Sesi disetujui dan sertifikat langsung diterbitkan. Sesi FAIL pun tetap terbit (hasil "tidak laik pakai").')
                    ->action(function (CalibrationSession $record): void {
                        $record->update([
                            'status' => CalibrationSession::STATUS_DISETUJUI,
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                            'catatan_revisi' => null,
                        ]);
                        GenerateCertificate::dispatch($record->id, auth()->id());
                        Notification::make()->title('Sesi disetujui. Sertifikat sedang diterbitkan.')->success()->send();
                    }),

                // Reject → perlu_revisi + catatan buat teknisi.
                Action::make('reject')
                    ->label('Tolak')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (CalibrationSession $record): bool => $record->status === CalibrationSession::STATUS_MENUNGGU_APPROVAL)
                    ->schema([
                        Textarea::make('catatan_revisi')
                            ->label('Catatan revisi')
                            ->required()
                            ->minLength(5)
                            ->helperText('Teknisi perlu tahu apa yang harus dibenerin.'),
                    ])
                    ->action(function (CalibrationSession $record, array $data): void {
                        $record->update([
                            'status' => CalibrationSession::STATUS_PERLU_REVISI,
                            'reviewed_by' => auth()->id(),
                            'reviewed_at' => now(),
                            'catatan_revisi' => $data['catatan_revisi'],
                        ]);
                        Notification::make()->title('Sesi dikembalikan ke teknisi buat revisi.')->warning()->send();
                    }),
            ]);
    }

    private static function detail(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Ringkasan')
                ->columns(2)
                ->schema([
                    TextEntry::make('nomor_sesi')->label('Nomor sesi'),
                    TextEntry::make('equipment.nama_alat')->label('Alat'),
                    TextEntry::make('teknisi.name')->label('Teknisi'),
                    TextEntry::make('standard.nama')->label('Standar acuan'),
                    TextEntry::make('tanggal_kalibrasi')->label('Tanggal')->date(),
                    TextEntry::make('keputusan')->badge()
                        ->color(fn (?string $state): string => $state === 'PASS' ? 'success' : 'danger'),
                    TextEntry::make('catatan_revisi')->label('Catatan revisi')->placeholder('—')->columnSpanFull(),
                ]),

            // Rincian GUM per titik — biar admin approve/reject nggak cuma
            // percaya badge PASS/FAIL doang, tapi bisa liat angka yang
            // ngebentuk keputusannya (mepet atau nggak ke batas toleransi).
            Section::make('Titik Ukur & Ketidakpastian')
                ->schema([
                    RepeatableEntry::make('uncertaintyCalculations')
                        ->label('')
                        ->table([
                            TableColumn::make('Titik'),
                            TableColumn::make('Nilai ukur'),
                            TableColumn::make('Rata-rata'),
                            TableColumn::make('Error'),
                            TableColumn::make('U diperluas'),
                            TableColumn::make('Toleransi'),
                            TableColumn::make('Keputusan'),
                        ])
                        ->schema([
                            TextEntry::make('titik_ke'),
                            TextEntry::make('titik_ukur')->numeric(4),
                            TextEntry::make('rata_rata')->numeric(4),
                            TextEntry::make('error')->numeric(4),
                            TextEntry::make('ketidakpastian_diperluas')
                                ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : '± '.number_format($state, 4)),
                            TextEntry::make('toleransi')
                                ->formatStateUsing(fn (?float $state): string => $state === null ? '—' : '± '.number_format($state, 4)),
                            TextEntry::make('keputusan')
                                ->badge()
                                ->color(fn (?string $state): string => $state === 'PASS' ? 'success' : 'danger'),
                        ]),
                ])
                ->collapsible(),
        ]);
    }
}

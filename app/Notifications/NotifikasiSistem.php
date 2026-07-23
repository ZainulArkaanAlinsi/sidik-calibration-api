<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Induk semua notifikasi aplikasi.
 *
 * SATU payload dipakai dua tampilan: lonceng panel admin (Filament) dan halaman
 * notifikasi di mobile. Makanya bentuk datanya ngikutin apa yang dibaca
 * `Filament\Notifications\Notification::fromArray()` (title, body, icon,
 * iconColor, color, …) — Filament ngabaikan kunci yang nggak dia kenal, jadi
 * kunci tambahan buat mobile (`kategori`, `tautan`) aman nebeng di baris yang
 * sama.
 *
 * Kalau dipisah jadi dua sistem, admin bakal lihat notifikasi yang nggak muncul
 * di HP-nya — dan itu bikin orang berhenti percaya sama notifikasinya.
 */
abstract class NotifikasiSistem extends Notification
{
    use Queueable;

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            // --- Dibaca lonceng Filament ---
            'title' => $this->judul(),
            'body' => $this->isi(),
            'icon' => $this->ikon(),
            'iconColor' => $this->warna(),
            'color' => null,
            'status' => null,
            'duration' => 'persistent',
            'actions' => [],
            'view' => null,
            'viewData' => [],

            // --- Dibaca halaman notifikasi mobile ---
            // `kategori` yang dipakai mobile buat milih ikon & tujuan navigasi;
            // `tautan` nunjuk layar mana yang harus kebuka waktu diketuk.
            'kategori' => $this->kategori(),
            'tautan' => $this->tautan(),
        ];
    }

    abstract protected function judul(): string;

    abstract protected function isi(): string;

    /** Dipakai mobile buat routing & pengelompokan, bukan cuma hiasan. */
    abstract protected function kategori(): string;

    /** @return array<string, mixed>|null */
    protected function tautan(): ?array
    {
        return null;
    }

    protected function ikon(): string
    {
        return 'heroicon-o-bell';
    }

    /** Warna Filament: primary/success/warning/danger/info/gray. */
    protected function warna(): string
    {
        return 'info';
    }
}

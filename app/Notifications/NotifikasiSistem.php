<?php

namespace App\Notifications;

use App\Notifications\Channels\SaluranPush;
use App\Services\PenjagaNotifikasiUlang;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\BroadcastMessage;
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
        // `database` = lonceng Filament + halaman notifikasi mobile (jejak permanen).
        // `broadcast` = push realtime biar lonceng nyala BARENGAN di HP & desktop
        // tanpa refresh (channel privat App.Models.User.{id}). Butuh driver
        // broadcast aktif (Reverb); dengan driver `log`/`null` aman jadi no-op.
        // `push` = notifikasi sistem operasi buat HP yang aplikasinya KETUTUP
        // TOTAL — satu-satunya celah yang websocket nggak bisa tutup. Tanpa
        // pengirim yang disetel, saluran ini diam (`PengirimPushMati`).
        return ['database', 'broadcast', SaluranPush::class];
    }

    /**
     * Payload buat notifikasi sistem operasi di HP.
     *
     * Sengaja cuma judul, isi, dan tautan — BUKAN seluruh payload database.
     * Push mendarat di layar kunci, kebaca siapa pun yang megang HP-nya, dan
     * yang mesti sampai ke situ cuma "ada yang perlu kamu lihat". Rinciannya
     * ditarik aplikasi lewat REST ber-otorisasi sesudah dibuka, sama seperti
     * yang sudah berlaku buat payload broadcast.
     *
     * @return array{judul: string, isi: string, data: array<string, string>}
     */
    public function toPush(object $notifiable): array
    {
        $tautan = $this->tautan();

        return [
            'judul' => $this->judul(),
            'isi' => $this->isi(),
            'data' => [
                'kategori' => $this->kategori(),
                'tautan_tipe' => (string) ($tautan['tipe'] ?? ''),
                'tautan_id' => (string) ($tautan['id'] ?? ''),
            ],
        ];
    }

    /** Payload broadcast = sama persis dengan yang disimpen di database. */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toDatabase($notifiable));
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

            // --- Dipakai backend, diabaikan Filament & mobile ---
            // Ringkasan isi buat nahan pengulangan (`PenjagaNotifikasiUlang`).
            // Nebeng di payload biar nggak perlu tabel/kolom baru.
            PenjagaNotifikasiUlang::KEY_TANDA_TANGAN => $this->tandaTangan(),
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

    /**
     * Ringkasan isi notifikasi, buat nahan pengulangan yang isinya sama.
     *
     * Cuma perlu diisi notifikasi yang dipicu BERULANG (scheduler harian). Yang
     * dipicu satu kejadian — sesi disetujui, akun baru daftar — nggak perlu:
     * tiap kejadiannya beneran beda, dan nahan salah satunya berarti ada kabar
     * yang nggak nyampe.
     */
    protected function tandaTangan(): ?string
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

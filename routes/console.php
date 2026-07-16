<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Tiap pagi kabarin admin soal alat yang lewat/mendekati jatuh tempo.
// Butuh `php artisan schedule:work` (dev) atau cron ke `schedule:run` (prod).
Schedule::command('alat:cek-jatuh-tempo')->dailyAt('07:00');

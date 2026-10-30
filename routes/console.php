<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('billing:aggregate')->hourly()->withoutOverlapping();
Schedule::command('billing:invoice')->dailyAt('00:30')->timezone('UTC')->withoutOverlapping();

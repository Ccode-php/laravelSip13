<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Models\Campaign;
use App\Services\CampaignDispatcher;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');




// Har daqiqada faol kampaniyalarni tekshirish va bo'sh kanallarni to'ldirish
Schedule::call(function () {
    $runningCampaigns = Campaign::whereIn('status', ['queued', 'running'])->get();
    $dispatcher = app(CampaignDispatcher::class);

    foreach ($runningCampaigns as $campaign) {
        $dispatcher->dispatch($campaign->id);
    }
})->everyMinute();
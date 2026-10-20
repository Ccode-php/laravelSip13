<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SipTrunkController;
use App\Http\Controllers\SubscriberController;
use App\Http\Controllers\SubscriberGroupController;
use App\Http\Controllers\Api\AudioFileController;
use App\Http\Controllers\Api\CampaignController;

Route::post('/calls/webhook', [CampaignController::class, 'handleAsteriskWebhook']);


Route::post(
    'campaigns/{campaign}/start',
    [CampaignController::class, 'start']
);

Route::get(
    'subscriber-groups-tree',
    [SubscriberGroupController::class, 'tree']
);

Route::apiResource('campaigns', CampaignController::class);

Route::apiResource('audio-files',AudioFileController::class);

Route::post(
    '/subscribers/import',
    [SubscriberController::class, 'import']
);

Route::apiResource('subscriber-groups',SubscriberGroupController::class);

Route::apiResource('subscribers', SubscriberController::class);

Route::apiResource('sip-trunks', SipTrunkController::class);


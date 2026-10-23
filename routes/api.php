<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SipTrunkController;
use App\Http\Controllers\SubscriberController;
use App\Http\Controllers\SubscriberGroupController;
use App\Http\Controllers\Api\AudioFileController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\CallResultController;

/*
 * Eski /calls/webhook olib tashlandi: natijalarni endi `php artisan asterisk:listen`
 * jarayoni Asterisk hodisalaridan o‘zi oladi.
 *
 * DIQQAT: login tizimi yo‘q. API ni internetga ochishdan oldin bu marshrutlarni
 * Route::middleware('auth:sanctum')->group(function () { ... }) ichiga oling.
 */

// Campaign
Route::post('campaigns/{campaign}/start', [CampaignController::class, 'start']);
Route::post('campaigns/{campaign}/stop', [CampaignController::class, 'stop']);
Route::get('campaigns/{campaign}/stats', [CampaignController::class, 'stats']);
Route::get('campaigns/{campaign}/recipients', [CampaignController::class, 'recipients']);
Route::apiResource('campaigns', CampaignController::class);

// Qo'ng'iroq natijalari (hamma kampaniyalar bo'yicha)
Route::get('call-results', [CallResultController::class, 'index']);

// Audio
Route::get('audio-files/{audioFile}/stream', [AudioFileController::class, 'stream']);
Route::apiResource('audio-files', AudioFileController::class);

// Abonentlar
Route::get('subscriber-groups-tree', [SubscriberGroupController::class, 'tree']);
Route::post('subscribers/import', [SubscriberController::class, 'import']);
Route::apiResource('subscriber-groups', SubscriberGroupController::class);
Route::apiResource('subscribers', SubscriberController::class);

// SIP trunklar
Route::apiResource('sip-trunks', SipTrunkController::class);

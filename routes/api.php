<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\SipTrunkController;
use App\Http\Controllers\SubscriberController;
use App\Http\Controllers\SubscriberGroupController;
use App\Http\Controllers\Api\AudioFileController;
use App\Http\Controllers\Api\CampaignController;


Route::middleware('auth:sanctum')->group(function () {
   
});
Route::post('campaigns/{campaign}/start', [CampaignController::class, 'start']);
Route::post('campaigns/{campaign}/stop', [CampaignController::class, 'stop']);
Route::get('audio-files/{audioFile}/stream', [AudioFileController::class, 'stream']);

Route::get('subscriber-groups-tree', [SubscriberGroupController::class, 'tree']);
Route::post('/subscribers/import', [SubscriberController::class, 'import']);

Route::apiResource('campaigns', CampaignController::class);
Route::apiResource('audio-files', AudioFileController::class);
Route::apiResource('subscriber-groups', SubscriberGroupController::class);
Route::apiResource('subscribers', SubscriberController::class);
Route::apiResource('sip-trunks', SipTrunkController::class);


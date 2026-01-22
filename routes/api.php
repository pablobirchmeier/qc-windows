<?php

use App\Http\Controllers\Api\DeviceTestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

Route::prefix('device')->group(function () {
    Route::get('/health', [DeviceTestController::class, 'health']);
    Route::post('/scrape', [DeviceTestController::class, 'scrapeData']);
    Route::post('/test-ethernet', [DeviceTestController::class, 'testEthernet']);
    Route::post('/test-wifi', [DeviceTestController::class, 'testWifi']);
});

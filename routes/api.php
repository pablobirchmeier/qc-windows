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
    Route::get('/test-wifi-sse', [DeviceTestController::class, 'testWifiSSE']);
    Route::get('/wifi-diagnostics', [DeviceTestController::class, 'wifiDiagnostics']);
    Route::post('/config-gpon', [DeviceTestController::class, 'configGpon']);
    Route::post('/reset-gpon', [DeviceTestController::class, 'resetGpon']);
    Route::post('/speedtest', [DeviceTestController::class, 'speedTest']);
});

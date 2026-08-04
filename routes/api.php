<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\FacturaController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware(['throttle:60,1', 'sistema.auth', 'throttle:sistema'])->group(function () {
    Route::post('/facturas', [FacturaController::class, 'store']);
    Route::post('/facturas/cafc', [FacturaController::class, 'cafc']);
    Route::get('/facturas/{factura}', [FacturaController::class, 'show']);
    Route::post('/facturas/{factura}/anular', [FacturaController::class, 'anular']);
});

<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\FacturaController;

Route::middleware(['throttle:60,1', 'sistema.auth', 'throttle:sistema'])->group(function () {
    Route::post('/facturas', [FacturaController::class, 'store']);
    Route::post('/facturas/cafc', [FacturaController::class, 'cafc']);
    Route::get('/facturas/{factura}', [FacturaController::class, 'show']);
    Route::post('/facturas/{factura}/anular', [FacturaController::class, 'anular']);
    Route::post('/facturas/{factura}/revertir-anulacion', [FacturaController::class, 'revertirAnulacion']);
});

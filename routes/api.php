<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\FacturaController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('sistema.auth')->group(function () {
    Route::post('/facturas', [FacturaController::class, 'store']);
    Route::get('/facturas/{factura}', [FacturaController::class, 'show']);
});

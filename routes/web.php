<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\FacturaController;


Route::post('/facturas', [FacturaController::class, 'store']);
Route::get('/facturas/{factura}', [FacturaController::class, 'show']);
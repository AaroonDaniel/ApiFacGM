<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\RepresentacionGraficaController;

// Herramienta de prueba manual del endpoint de facturas. Solo disponible
// en local — en cualquier otro entorno responde 404, para no dejar una
// página sin protección que facilite entender la forma de la API.
Route::get('/test-factura.html', function () {
    abort_unless(app()->environment('local'), 404);

    return response()
        ->file(resource_path('dev/test-factura.html'), ['Content-Type' => 'text/html; charset=UTF-8']);
});

// Representación Gráfica pública — ver FacturaAceptadaMail y
// RepresentacionGraficaController. Sin sistema.auth a propósito: quien la
// abre desde el correo no tiene api key; el CUF ya funciona como token de
// acceso (no adivinable). throttle liviano contra scraping/abuso.
Route::get('/factura/ver/{cuf}', [RepresentacionGraficaController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('factura.ver');
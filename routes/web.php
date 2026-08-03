<?php

use Illuminate\Support\Facades\Route;

// Herramienta de prueba manual del endpoint de facturas. Solo disponible
// en local — en cualquier otro entorno responde 404, para no dejar una
// página sin protección que facilite entender la forma de la API.
Route::get('/test-factura.html', function () {
    abort_unless(app()->environment('local'), 404);

    return response()
        ->file(resource_path('dev/test-factura.html'), ['Content-Type' => 'text/html; charset=UTF-8']);
});
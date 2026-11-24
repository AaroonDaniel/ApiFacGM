<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PanelConfiguracionController;
use App\Http\Controllers\RepresentacionGraficaController;

// Panel administrativo local para Sistemas, Emisores, Cajas y Códigos SIAT
Route::prefix('panel')->name('panel.')->group(function () {
    Route::get('/', [PanelConfiguracionController::class, 'index'])->name('index');
    Route::post('/sistemas', [PanelConfiguracionController::class, 'storeSistema'])->name('sistemas.store');
    Route::post('/emisores', [PanelConfiguracionController::class, 'storeEmisor'])->name('emisores.store');
    Route::post('/puntos-venta', [PanelConfiguracionController::class, 'storePuntoVenta'])->name('puntos-venta.store');
    Route::post('/emisores/{emisor}/vincular', [PanelConfiguracionController::class, 'vincularSistema'])->name('emisores.vincular');
    Route::post('/siat/cuis', [PanelConfiguracionController::class, 'gestionarCuis'])->name('siat.cuis');
    Route::post('/siat/cufd', [PanelConfiguracionController::class, 'generarCufd'])->name('siat.cufd');
    Route::post('/siat/catalogos', [PanelConfiguracionController::class, 'sincronizarCatalogos'])->name('siat.catalogos');

    Route::post('/sistemas/{sistema}/regenerar-key', [PanelConfiguracionController::class, 'regenerarApiKey'])->name('sistemas.regenerar-key');
    Route::put('/sistemas/{sistema}', [PanelConfiguracionController::class, 'updateSistema'])->name('sistemas.update');
    Route::delete('/sistemas/{sistema}', [PanelConfiguracionController::class, 'destroySistema'])->name('sistemas.destroy');
});

// Herramienta de prueba manual del endpoint de facturas.
Route::get('/test-factura.html', function () {
    abort_unless(app()->environment('local'), 404);

    return response()
        ->file(resource_path('dev/test-factura.html'), ['Content-Type' => 'text/html; charset=UTF-8']);
});

// Representación Gráfica pública
Route::get('/factura/ver/{cuf}', [RepresentacionGraficaController::class, 'show'])
    ->middleware('throttle:30,1')
    ->name('factura.ver');
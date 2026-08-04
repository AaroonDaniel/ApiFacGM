<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reconciliación/validación de contingencias offline: no depende de que
// llegue una factura nueva. Requiere `php artisan schedule:work` (o el cron
// de producción) corriendo para que efectivamente se dispare.
Schedule::command('siat:procesar-contingencias')->everyFiveMinutes()->withoutOverlapping();

// Sincronización diaria de catálogos paramétricos (actividades, productos,
// unidades de medida, leyendas, tipos de documento, métodos de pago,
// motivos de anulación) contra el SIAT.
Schedule::command('siat:sincronizar-catalogos')->daily()->withoutOverlapping();

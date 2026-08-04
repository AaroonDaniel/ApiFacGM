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

// Renovación proactiva del CUFD (vigencia ~24h) por punto de venta. Corre
// cada hora en vez de una vez al día: getActiveCufd() es idempotente y
// barato cuando el CUFD sigue vigente (sin llamada SOAP), así que el costo
// extra es mínimo y evita quedar sin margen si la renovación diaria cae
// justo cerca del límite de las 24h.
Schedule::command('siat:renovar-cufd')->hourly()->withoutOverlapping();

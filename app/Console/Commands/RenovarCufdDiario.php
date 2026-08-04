<?php

namespace App\Console\Commands;

use App\Models\PuntoVenta;
use App\Services\SiatService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Renueva proactivamente el CUFD (vigencia ~24h) de cada punto de venta
 * activo, para que la primera factura del día no pague el costo de la
 * renovación. SiatService::getActiveCufd() ya es idempotente — si el CUFD
 * vigente todavía no venció, no pide uno nuevo — así que correr esto varias
 * veces seguidas es seguro.
 */
class RenovarCufdDiario extends Command
{
    protected $signature = 'siat:renovar-cufd';

    protected $description = 'Renueva el CUFD de cada punto de venta activo, para todos los emisores.';

    public function handle(): int
    {
        $puntosVenta = PuntoVenta::where('pvest', true)
            ->whereHas('emisor', fn ($q) => $q->where('emiest', true))
            ->with('emisor')
            ->get();

        if ($puntosVenta->isEmpty()) {
            $this->warn('No hay puntos de venta activos; nada que renovar.');
            return self::SUCCESS;
        }

        foreach ($puntosVenta as $pv) {
            $emisor = $pv->emisor;
            $siat   = new SiatService($emisor, $pv->pvsuc, $pv->pvpdv);

            try {
                $cufd = $siat->getActiveCufd();
                if ($cufd) {
                    $this->info("Emisor {$emisor->eminit} (suc {$pv->pvsuc}, PDV {$pv->pvpdv}): CUFD vigente hasta {$cufd->scoven}.");
                } else {
                    $this->error("Emisor {$emisor->eminit} (suc {$pv->pvsuc}, PDV {$pv->pvpdv}): no se pudo renovar el CUFD — ver storage/logs/laravel.log.");
                }
            } catch (Throwable $e) {
                $this->error("Emisor {$emisor->eminit} (suc {$pv->pvsuc}, PDV {$pv->pvpdv}): falló — " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\PuntoVenta;
use App\Services\SiatService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Alarma + renovación proactiva del CUIS (vigencia ~365 días) de cada punto
 * de venta activo, con 30 días de aviso — a diferencia de getActiveCuis()
 * (que solo renueva cuando el actual YA venció), esto adelanta la
 * renovación antes del corte real. Corre diario: hay meses de margen, no
 * hace falta más frecuencia que la del CUFD.
 */
class RenovarCuisPeriodico extends Command
{
    protected $signature = 'siat:renovar-cuis';

    protected $description = 'Renueva el CUIS de cada punto de venta activo si está por vencer dentro de 30 días, para todos los emisores.';

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
                $resultado = $siat->renovarCuisSiProximoAExpirar();

                if (isset($resultado['error'])) {
                    $this->error("Emisor {$emisor->eminit} (suc {$pv->pvsuc}, PDV {$pv->pvpdv}): {$resultado['error']}");
                } elseif ($resultado['renovado']) {
                    $this->info("Emisor {$emisor->eminit} (suc {$pv->pvsuc}, PDV {$pv->pvpdv}): CUIS renovado, vigente hasta {$resultado['vigente_hasta']}.");
                } else {
                    $this->line("Emisor {$emisor->eminit} (suc {$pv->pvsuc}, PDV {$pv->pvpdv}): CUIS vigente hasta {$resultado['vigente_hasta']}, sin necesidad de renovar todavía.");
                }
            } catch (Throwable $e) {
                $this->error("Emisor {$emisor->eminit} (suc {$pv->pvsuc}, PDV {$pv->pvpdv}): falló — " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}

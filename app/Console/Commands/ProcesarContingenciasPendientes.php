<?php

namespace App\Console\Commands;

use App\Models\EventosSignificativo;
use App\Models\Factura;
use App\Services\ContingenciaService;
use App\Services\SiatService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Corre periódicamente (ver routes/console.php) para no depender de que
 * llegue una factura nueva para reconciliar una contingencia o validar un
 * paquete ya enviado. Sin esto, un emisor de bajo volumen podría quedarse
 * con facturas offline sin regularizar por días.
 *
 * Reutiliza ContingenciaService::reconciliar()/validar() tal cual, que ya
 * son reanudables (ver su docblock) — correr este comando varias veces
 * seguidas es seguro, no duplica trabajo.
 */
class ProcesarContingenciasPendientes extends Command
{
    protected $signature = 'siat:procesar-contingencias';

    protected $description = 'Reconcilia eventos significativos pendientes y valida paquetes offline ya enviados, para todos los emisores.';

    public function handle(ContingenciaService $contingencia): int
    {
        $this->reconciliarPendientes($contingencia);
        $this->validarPaquetesEnviados($contingencia);

        return self::SUCCESS;
    }

    /**
     * Eventos activos o cerrados sin paquete enviado: si el emisor ya tiene
     * conexión con el SIAT, se reconcilian (registrar evento + empaquetar +
     * enviar). Si sigue caído, se omiten hasta la próxima corrida.
     */
    private function reconciliarPendientes(ContingenciaService $contingencia): void
    {
        $eventos = EventosSignificativo::whereIn('eveest', [
            EventosSignificativo::ESTADO_ACTIVO,
            EventosSignificativo::ESTADO_CERRADO,
        ])->whereNull('evecodrecpaq')->get();

        foreach ($eventos as $evento) {
            $emisor = $evento->emisor;
            if (!$emisor) {
                continue;
            }

            $siat = new SiatService($emisor);
            $comunicacion = $siat->verifyCommunication();

            if (!($comunicacion['success'] ?? false)) {
                $this->line("Emisor {$emisor->eminit}: evento {$evento->eveid} sigue sin conexión, se omite.");
                continue;
            }

            $this->info("Emisor {$emisor->eminit}: reconciliando evento {$evento->eveid}...");
            try {
                $contingencia->reconciliar($evento, $emisor);
                $this->info("  OK.");
            } catch (Throwable $e) {
                $this->error("  Falló: " . $e->getMessage());
            }
        }
    }

    /**
     * Paquetes ya enviados (evecodrecpaq presente) cuyas facturas siguen en
     * 'empaquetada': se consulta si el SIAT ya terminó de validarlos.
     */
    private function validarPaquetesEnviados(ContingenciaService $contingencia): void
    {
        $eventos = EventosSignificativo::whereNotNull('evecodrecpaq')
            ->whereHas('facturas', fn ($q) => $q->where('facsiatest', Factura::SIAT_EMPAQUETADA))
            ->get();

        foreach ($eventos as $evento) {
            $emisor = $evento->emisor;
            if (!$emisor) {
                continue;
            }

            $this->info("Emisor {$emisor->eminit}: validando paquete del evento {$evento->eveid}...");
            try {
                $status = $contingencia->validar($evento, $emisor);
                $this->info("  Estado: {$status}");
            } catch (Throwable $e) {
                $this->error("  Falló: " . $e->getMessage());
            }
        }
    }
}

<?php

namespace App\Console\Commands;

use App\Models\EventosSignificativo;
use App\Models\Factura;
use App\Services\ContingenciaService;
use App\Services\SiatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
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
        $this->enviarPaquetesCafcPendientes($contingencia);
        $this->validarPaquetesCafcEnviados($contingencia);

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

            // El plazo normativo para terminar de enviar el paquete offline
            // es 48h desde que se registró el evento (evefin). No hay
            // remedio automático más allá de seguir reintentando — esto es
            // solo para que alguien se entere y pueda intervenir a mano.
            if ($evento->excedioPlazoEnvioPaquete()) {
                Log::critical("Emisor {$emisor->eminit}: evento {$evento->eveid} superó las "
                    . EventosSignificativo::LIMITE_HORAS_ENVIO_PAQUETE . "h para terminar de enviar el "
                    . "paquete offline (cerrado el {$evento->evefin}) — requiere intervención manual.");
            }

            $siat = new SiatService($emisor);
            $comunicacion = $siat->verifyCommunication();

            if (!($comunicacion['success'] ?? false)) {
                $this->line("Emisor {$emisor->eminit}: evento {$evento->eveid} sigue sin conexión, se omite.");
                continue;
            }

            $this->info("Emisor {$emisor->eminit}: reconciliando evento {$evento->eveid}...");
            try {
                $ok = $contingencia->reconciliar($evento, $emisor);
                if ($ok) {
                    $this->info("  OK.");
                } else {
                    $this->error("  No se completó — revisar storage/logs/laravel.log para el motivo exacto.");
                }
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
            ->whereHas('facturas', fn ($q) => $q->where('facsiatest', Factura::SIAT_EMPAQUETADA)->whereNull('faccafc'))
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

    /**
     * Facturas CAFC (transcritas de talón preimpreso) ya acopladas a un
     * evento registrado, pero cuyo paquete CAFC todavía no se envió — se
     * empaqueta y envía por separado del offline normal (ver
     * ContingenciaService::enviarPaqueteCafc()).
     */
    private function enviarPaquetesCafcPendientes(ContingenciaService $contingencia): void
    {
        $eventos = EventosSignificativo::whereNotNull('evecodrec')
            ->whereHas('facturas', fn ($q) => $q->where('facsiatest', Factura::SIAT_OFFLINE)->whereNotNull('faccafc'))
            ->get();

        foreach ($eventos as $evento) {
            $emisor = $evento->emisor;
            if (!$emisor) {
                continue;
            }

            // 72h desde que se registró el evento para terminar de enviar
            // el paquete CAFC — ventana más amplia que el offline normal
            // porque de por medio hay transcripción manual.
            if ($evento->excedioPlazoEnvioCafc()) {
                Log::critical("Emisor {$emisor->eminit}: evento {$evento->eveid} superó las "
                    . EventosSignificativo::LIMITE_HORAS_ENVIO_CAFC . "h para terminar de enviar el "
                    . "paquete CAFC (cerrado el {$evento->evefin}) — requiere intervención manual.");
            }

            $this->info("Emisor {$emisor->eminit}: enviando paquete CAFC del evento {$evento->eveid}...");
            try {
                $ok = $contingencia->enviarPaqueteCafc($evento, $emisor);
                $this->info($ok ? '  OK.' : '  No se completó — revisar storage/logs/laravel.log.');
            } catch (Throwable $e) {
                $this->error("  Falló: " . $e->getMessage());
            }
        }
    }

    /**
     * Paquetes CAFC ya enviados (evecodrecpaqcafc presente) cuyas facturas
     * siguen en 'empaquetada': se consulta si el SIAT ya terminó de
     * validarlos.
     */
    private function validarPaquetesCafcEnviados(ContingenciaService $contingencia): void
    {
        $eventos = EventosSignificativo::whereNotNull('evecodrecpaqcafc')
            ->whereHas('facturas', fn ($q) => $q->where('facsiatest', Factura::SIAT_EMPAQUETADA)->whereNotNull('faccafc'))
            ->get();

        foreach ($eventos as $evento) {
            $emisor = $evento->emisor;
            if (!$emisor) {
                continue;
            }

            $this->info("Emisor {$emisor->eminit}: validando paquete CAFC del evento {$evento->eveid}...");
            try {
                $status = $contingencia->validarCafc($evento, $emisor);
                $this->info("  Estado: {$status}");
            } catch (Throwable $e) {
                $this->error("  Falló: " . $e->getMessage());
            }
        }
    }
}

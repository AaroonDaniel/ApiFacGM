<?php

namespace App\Console\Commands;

use App\Mail\AlertaPlazoContingenciaMail;
use App\Models\EventosSignificativo;
use App\Models\Factura;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Corre cada hora (ver routes/console.php) para avisar por correo al
 * administrador cuando un evento significativo supera el plazo normativo
 * para terminar de enviar su paquete offline (48h) o su paquete CAFC (72h)
 * — ver EventosSignificativo::excedioPlazoEnvioPaquete()/excedioPlazoEnvioCafc().
 *
 * ProcesarContingenciasPendientes ya deja constancia de esto en el log de
 * aplicación (Log::critical), pero un log no garantiza que alguien lo vea a
 * tiempo. Este comando además notifica por correo — una sola vez por evento
 * y por tipo de plazo (evealertapaq/evealertacafc), para no reenviar el
 * mismo aviso en cada corrida mientras el evento siga sin regularizar.
 */
class AlertarPlazosContingencia extends Command
{
    protected $signature = 'siat:alertar-plazos';

    protected $description = 'Envía un correo de urgencia al administrador cuando un evento de contingencia supera su plazo de envío (48h paquete / 72h CAFC).';

    public function handle(): int
    {
        $this->alertarPaquete();
        $this->alertarCafc();

        return self::SUCCESS;
    }

    /**
     * Eventos que todavía necesitan reconciliarse en el canal offline
     * normal (mismo criterio que ProcesarContingenciasPendientes) y que ya
     * superaron las 48h desde que se cerraron, sin haber avisado antes.
     */
    private function alertarPaquete(): void
    {
        $eventos = EventosSignificativo::necesitaReconciliacionNormal()
            ->whereNull('evealertapaq')
            ->get()
            ->filter(fn (EventosSignificativo $e) => $e->excedioPlazoEnvioPaquete());

        foreach ($eventos as $evento) {
            $this->enviar($evento, 'paquete', EventosSignificativo::LIMITE_HORAS_ENVIO_PAQUETE, 'evealertapaq');
        }
    }

    /**
     * Eventos con facturas CAFC transcritas todavía sin empaquetar/enviar,
     * que ya superaron las 72h desde que se registraron, sin haber avisado
     * antes. Mismo criterio de selección que
     * ProcesarContingenciasPendientes::enviarPaquetesCafcPendientes().
     */
    private function alertarCafc(): void
    {
        $eventos = EventosSignificativo::whereNotNull('evecodrec')
            ->whereNull('evealertacafc')
            ->whereHas('facturas', fn ($q) => $q->where('facsiatest', Factura::SIAT_OFFLINE)->whereNotNull('faccafc'))
            ->get()
            ->filter(fn (EventosSignificativo $e) => $e->excedioPlazoEnvioCafc());

        foreach ($eventos as $evento) {
            $this->enviar($evento, 'cafc', EventosSignificativo::LIMITE_HORAS_ENVIO_CAFC, 'evealertacafc');
        }
    }

    private function enviar(EventosSignificativo $evento, string $tipo, int $limiteHoras, string $columna): void
    {
        $emisor = $evento->emisor;
        if (!$emisor) {
            return;
        }

        Log::critical("Emisor {$emisor->eminit}: evento {$evento->eveid} superó las {$limiteHoras}h para el plazo "
            . "'{$tipo}' — requiere intervención manual.");

        $destino = config('siat.admin_email');
        if (!$destino) {
            $this->warn("SIAT_ADMIN_EMAIL no configurado; se omite el correo para el evento {$evento->eveid} ({$tipo}).");
            return;
        }

        try {
            Mail::to($destino)->queue(new AlertaPlazoContingenciaMail($evento, $tipo, $limiteHoras));
            $evento->update([$columna => now()]);
            $this->info("Alerta '{$tipo}' enviada para el evento {$evento->eveid}.");
        } catch (Throwable $e) {
            Log::error("No se pudo encolar la alerta de plazo ({$tipo}) para el evento {$evento->eveid}. " . $e->getMessage());
        }
    }
}

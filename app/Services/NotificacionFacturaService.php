<?php

namespace App\Services;

use App\Mail\FacturaAceptadaMail;
use App\Models\Factura;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envío automático del XML al comprador cuando su factura queda aceptada.
 * Un fallo acá nunca debe tumbar el flujo de emisión/reconciliación que lo
 * dispara — solo se loguea.
 */
class NotificacionFacturaService
{
    public function notificarSiCorresponde(Factura $factura): void
    {
        if (!$factura->facemail) {
            return;
        }

        try {
            Mail::to($factura->facemail)->queue(new FacturaAceptadaMail($factura));
        } catch (Throwable $e) {
            Log::error("Factura #{$factura->facnro}: no se pudo encolar el correo al comprador ({$factura->facemail}). "
                . $e->getMessage());
        }
    }
}

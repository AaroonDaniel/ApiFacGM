<?php

namespace App\Services;

use App\Models\Factura;
use App\Models\MotivoAnulacion;
use Exception;

/**
 * Anula una factura ya aceptada por el SIAT. A diferencia de la emisión,
 * NO hay modo offline aquí: si el SIAT no responde, la factura se queda
 * como estaba — marcarla anulada localmente sin confirmación del SIAT
 * dejaría nuestra base desincronizada con el padrón real.
 */
class AnulacionService
{
    /**
     * @throws Exception si la factura no es anulable o el SIAT rechaza la anulación.
     */
    public function anular(Factura $factura, int $codigoMotivo): Factura
    {
        if ($factura->facest === Factura::ESTADO_ANULADA) {
            throw new Exception("La factura #{$factura->facnro} ya está anulada.");
        }

        if ($factura->facsiatest !== Factura::SIAT_ACEPTADA) {
            throw new Exception("Solo se pueden anular facturas aceptadas por el SIAT (estado actual: {$factura->facsiatest}).");
        }

        if (!MotivoAnulacion::where('moacod', $codigoMotivo)->exists()) {
            throw new Exception("Código de motivo de anulación inválido: {$codigoMotivo}.");
        }

        $emisor = $factura->emisor;
        // El mismo punto de venta bajo el que se emitió — no el (0,0) por
        // defecto del emisor, que puede no ser el correcto si tiene varios.
        $siat = new SiatService($emisor, $factura->facsuc, $factura->facpdv);

        $cuis = $siat->getActiveCuis();
        $cufd = $siat->getActiveCufd();

        if (!$cuis || !$cufd) {
            throw new Exception('No se pudo obtener CUIS/CUFD vigente para anular la factura.');
        }

        $resp = $siat->anularFactura($cuis, $cufd->scovalor, $factura->faccuf, $codigoMotivo, $factura->facid);

        if (($resp['status'] ?? null) !== 'accepted') {
            throw new Exception('El SIAT rechazó la anulación: ' . ($resp['mensaje'] ?? 'sin detalle'));
        }

        $factura->update([
            'facest'     => Factura::ESTADO_ANULADA,
            'facmotanul' => $codigoMotivo,
            'facfchanul' => now(),
        ]);

        return $factura;
    }
}

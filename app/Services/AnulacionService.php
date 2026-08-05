<?php

namespace App\Services;

use App\Models\Factura;
use App\Models\MotivoAnulacion;
use Carbon\Carbon;
use Exception;

/**
 * Anula (o revierte la anulación de) una factura ya aceptada por el SIAT.
 * A diferencia de la emisión, NO hay modo offline aquí: si el SIAT no
 * responde, la factura se queda como estaba — marcarla anulada localmente
 * sin confirmación del SIAT dejaría nuestra base desincronizada con el
 * padrón real.
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

        $limite = $this->fechaLimiteAnulacion($factura);
        if (now()->greaterThan($limite)) {
            throw new Exception("No se puede anular la factura #{$factura->facnro}: venció el plazo "
                . "(hasta el {$limite->toDateString()}, día 10 del mes siguiente a la emisión).");
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

    /**
     * Día 10 del mes siguiente al de emisión (facfch), fin del día — plazo
     * normativo máximo para anular. No hay evidencia de que el SIAT lo
     * comunique de otra forma que rechazando la anulación fuera de plazo;
     * este chequeo evita gastar la llamada SOAP y da un mensaje más claro.
     */
    private function fechaLimiteAnulacion(Factura $factura): Carbon
    {
        return Carbon::parse($factura->facfch)->startOfMonth()->addMonthNoOverflow()->addDays(9)->endOfDay();
    }

    /**
     * Revierte una anulación hecha por error. Sin evidencia de un plazo
     * normativo específico para la reversión (a diferencia de la anulación,
     * cuyo día 10 sí está documentado) — no se valida ninguno acá; si el
     * SIAT lo exige, lo rechazará él mismo con un mensaje.
     *
     * @throws Exception si la factura no está anulada o el SIAT rechaza la reversión.
     */
    public function revertirAnulacion(Factura $factura): Factura
    {
        if ($factura->facest !== Factura::ESTADO_ANULADA) {
            throw new Exception("La factura #{$factura->facnro} no está anulada.");
        }

        $emisor = $factura->emisor;
        $siat = new SiatService($emisor, $factura->facsuc, $factura->facpdv);

        $cuis = $siat->getActiveCuis();
        $cufd = $siat->getActiveCufd();

        if (!$cuis || !$cufd) {
            throw new Exception('No se pudo obtener CUIS/CUFD vigente para revertir la anulación.');
        }

        $resp = $siat->reversionAnulacionFactura($cuis, $cufd->scovalor, $factura->faccuf, $factura->facid);

        if (($resp['status'] ?? null) !== 'accepted') {
            throw new Exception('El SIAT rechazó la reversión de anulación: ' . ($resp['mensaje'] ?? 'sin detalle'));
        }

        $factura->update([
            'facest'     => Factura::ESTADO_VIGENTE,
            'facmotanul' => null,
            'facfchanul' => null,
        ]);

        return $factura;
    }
}

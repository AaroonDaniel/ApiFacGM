<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Emisor;
use App\Models\PuntoVenta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Compartido entre los controllers que reciben emisor_nit + punto_venta en
 * el body (FacturaController, EventoSignificativoController).
 */
trait ResuelveEmisorYPuntoVenta
{
    /**
     * Resuelve emisor + punto de venta a partir de la petición, aplicando
     * la exclusividad opcional emisor↔sistema cliente. Devuelve
     * [Emisor, PuntoVenta], o una JsonResponse con el error (422/403) para
     * que el caller la devuelva tal cual.
     *
     * @return array{0: Emisor, 1: PuntoVenta}|JsonResponse
     */
    private function resolverEmisorYPuntoVenta(Request $request, string $emisorNit, ?array $puntoVentaDatos): array|JsonResponse
    {
        $emisor = Emisor::where('eminit', $emisorNit)
            ->where('emiest', true)
            ->first();

        if (!$emisor) {
            return response()->json([
                'exito' => false,
                'error' => "No existe un emisor activo con NIT {$emisorNit}.",
            ], 422);
        }

        // Exclusividad opcional: si el emisor tiene un sistema dueño
        // asignado (emisores.sisid), solo ESE sistema puede facturar con
        // él. Si sisid está vacío, el emisor queda libre para cualquier
        // sistema autenticado (útil para pruebas) — es responsabilidad de
        // quien administra los emisores decidir cuándo cerrarlo.
        $sistemaCliente = $request->attributes->get('sistemaCliente');
        if ($emisor->sisid !== null && $emisor->sisid !== $sistemaCliente->sisid) {
            return response()->json([
                'exito' => false,
                'error' => 'Tu sistema no tiene autorización para facturar con este emisor.',
            ], 403);
        }

        // Punto de venta: el indicado en la petición, o el (0,0) por
        // defecto del emisor si no se especifica ninguno.
        $sucursal = $puntoVentaDatos['sucursal'] ?? $emisor->emisuc;
        $pdv      = $puntoVentaDatos['punto_venta'] ?? $emisor->emipdv;

        $pv = PuntoVenta::where('emiid', $emisor->emiid)
            ->where('pvsuc', $sucursal)
            ->where('pvpdv', $pdv)
            ->where('pvest', true)
            ->first();

        if (!$pv) {
            return response()->json([
                'exito' => false,
                'error' => "No existe un punto de venta activo (sucursal {$sucursal}, PDV {$pdv}) para este emisor.",
            ], 422);
        }

        return [$emisor, $pv];
    }
}

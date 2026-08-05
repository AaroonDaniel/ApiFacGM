<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ResuelveEmisorYPuntoVenta;
use App\Http\Controllers\Controller;
use App\Models\EventosSignificativo;
use App\Services\ContingenciaService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Throwable;

class EventoSignificativoController extends Controller
{
    use ResuelveEmisorYPuntoVenta;

    public function __construct(
        private ContingenciaService $contingenciaService,
    ) {
    }

    /**
     * POST /api/eventos-significativos — reporta manualmente una
     * contingencia que el sistema no puede detectar solo (corte de
     * energía, falla de software/hardware, etc.). Se reporta DESPUÉS de
     * que ya pasó — inicio y fin conocidos — porque si el corte apagó el
     * propio servidor, nadie puede llamar a este endpoint hasta que vuelva
     * la luz. Para "SIAT inaccesible" no hace falta esto: se detecta y
     * registra solo (ver ContingenciaService::acoplar()).
     */
    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'emisor_nit'               => 'required|string',
            'punto_venta.sucursal'     => 'nullable|integer|min:0',
            'punto_venta.punto_venta'  => 'nullable|integer|min:0',
            'codigo_motivo'            => ['required', 'integer', Rule::in([
                EventosSignificativo::COD_CORTE_INTERNET,
                EventosSignificativo::COD_SIN_INACCESIBLE,
                EventosSignificativo::COD_ZONA_SIN_INTERNET,
                EventosSignificativo::COD_VENTA_SIN_INTERNET,
                EventosSignificativo::COD_FALLA_SOFTWARE,
                EventosSignificativo::COD_CAMBIO_INFRA,
                EventosSignificativo::COD_CORTE_ENERGIA,
            ])],
            'descripcion'              => 'required|string|max:500',
            'fecha_inicio'             => 'required|date|before_or_equal:now',
            'fecha_fin'                => 'required|date|after:fecha_inicio|before_or_equal:now',
        ]);

        $resuelto = $this->resolverEmisorYPuntoVenta($request, $datos['emisor_nit'], $datos['punto_venta'] ?? null);
        if ($resuelto instanceof JsonResponse) {
            return $resuelto;
        }
        [$emisor, $pv] = $resuelto;

        try {
            $evento = $this->contingenciaService->registrarManual(
                $emisor,
                $pv,
                $datos['codigo_motivo'],
                $datos['descripcion'],
                Carbon::parse($datos['fecha_inicio']),
                Carbon::parse($datos['fecha_fin']),
            );
        } catch (Throwable $e) {
            return response()->json([
                'exito' => false,
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'exito'  => true,
            'evento' => $this->payload($evento),
        ], 201);
    }

    private function payload(EventosSignificativo $evento): array
    {
        return [
            'id'               => $evento->eveid,
            'codigo_motivo'    => $evento->evecod,
            'descripcion'      => $evento->evedesc,
            'fecha_inicio'     => $evento->eveini->format('Y-m-d\TH:i:s.v'),
            'fecha_fin'        => $evento->evefin?->format('Y-m-d\TH:i:s.v'),
            'estado'           => $evento->eveest,
            'codigo_recepcion' => $evento->evecodrec,
            'registrado_ante_siat' => $evento->evecodrec !== null,
        ];
    }
}

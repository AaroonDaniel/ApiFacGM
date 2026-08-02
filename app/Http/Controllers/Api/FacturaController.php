<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Emisor;
use App\Models\Factura;
use App\Services\EmisionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Throwable;

class FacturaController extends Controller
{
    public function __construct(private EmisionService $emisionService)
    {
    }

    /**
     * POST /api/facturas — emitir una factura.
     */
    public function store(Request $request): JsonResponse
    {
        // 1. Validar la estructura del JSON entrante.
        $datos = $request->validate([
            'emisor_nit'                      => 'required|string',
            'referencia_externa'              => 'nullable|string|max:100',
            'metodo_pago'                     => 'required|integer',
            'descuento_adicional'             => 'nullable|numeric|min:0',
            'cliente'                         => 'required|array',
            'cliente.nombre_razon_social'     => 'required|string|max:255',
            'cliente.tipo_documento'          => 'required|integer',
            'cliente.numero_documento'        => 'required|string|max:30',
            'cliente.complemento'             => 'nullable|string|max:5',
            'detalle'                         => 'required|array|min:1',
            'detalle.*.actividad_economica'   => 'required|string',
            'detalle.*.codigo_producto_sin'   => 'required|string',
            'detalle.*.codigo_producto'       => 'required|string',
            'detalle.*.descripcion'           => 'required|string|max:255',
            'detalle.*.cantidad'              => 'required|numeric|min:0',
            'detalle.*.unidad_medida'         => 'required|integer',
            'detalle.*.precio_unitario'       => 'required|numeric|min:0',
            'detalle.*.descuento'             => 'nullable|numeric|min:0',
        ]);

        // 2. Resolver el emisor por su NIT.
        $emisor = Emisor::where('eminit', $datos['emisor_nit'])
            ->where('emiest', true)
            ->first();

        if (!$emisor) {
            return response()->json([
                'exito' => false,
                'error' => "No existe un emisor activo con NIT {$datos['emisor_nit']}.",
            ], 422);
        }

        // 3. Delegar la emisión al servicio.
        try {
            $factura = $this->emisionService->emitir($emisor, [
                'cliente'             => $datos['cliente'],
                'detalle'             => $datos['detalle'],
                'metodo_pago'         => $datos['metodo_pago'],
                'descuento_adicional' => $datos['descuento_adicional'] ?? 0,
                'referencia_externa'  => $datos['referencia_externa'] ?? null,
                'sistema_origen'      => 'pendiente', // luego vendrá del sistema autenticado (JWT)
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'exito' => false,
                'error' => 'Error al emitir la factura: ' . $e->getMessage(),
            ], 500);
        }

        // 4. Responder según el estado final.
        return $this->respuesta($factura);
    }

    /**
     * GET /api/facturas/{factura} — consultar una factura.
     */
    public function show(Factura $factura): JsonResponse
    {
        return $this->respuesta($factura->load('detalles'));
    }

    /**
     * Arma la respuesta JSON a partir del estado de la factura.
     */
    private function respuesta(Factura $factura): JsonResponse
    {
        $codigo = match ($factura->facsiatest) {
            Factura::SIAT_ACEPTADA => 201,
            Factura::SIAT_OFFLINE  => 200,
            Factura::SIAT_RECHAZADA => 422,
            default                => 200,
        };

        return response()->json([
            'exito'   => in_array($factura->facsiatest, [Factura::SIAT_ACEPTADA, Factura::SIAT_OFFLINE]),
            'factura' => [
                'id'               => $factura->facid,
                'numero'           => $factura->facnro,
                'cuf'              => $factura->faccuf,
                'estado'           => $factura->facest,
                'siat_estado'      => $factura->facsiatest,
                'codigo_recepcion' => $factura->faccodrec,
                'fecha_emision'    => $factura->fachora?->format('Y-m-d\TH:i:s.v'),
            ],
        ], $codigo);
    }
}
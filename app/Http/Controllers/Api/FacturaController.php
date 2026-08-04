<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Emisor;
use App\Models\Factura;
use App\Models\PuntoVenta;
use App\Services\AnulacionService;
use App\Services\EmisionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Throwable;

class FacturaController extends Controller
{
    public function __construct(
        private EmisionService $emisionService,
        private AnulacionService $anulacionService,
    ) {
    }

    /**
     * POST /api/facturas — emitir una factura.
     */
    public function store(Request $request): JsonResponse
    {
        // 1. Validar la estructura del JSON entrante.
        $datos = $request->validate([
            'emisor_nit'                      => 'required|string',
            'punto_venta.sucursal'            => 'nullable|integer|min:0',
            'punto_venta.punto_venta'         => 'nullable|integer|min:0',
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

        // 2a. Exclusividad opcional: si el emisor tiene un sistema dueño
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

        // 2b. Resolver el punto de venta: el indicado en la petición, o el
        // (0,0) por defecto del emisor si no se especifica ninguno.
        $sucursal = $datos['punto_venta']['sucursal'] ?? $emisor->emisuc;
        $pdv      = $datos['punto_venta']['punto_venta'] ?? $emisor->emipdv;

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

        // 3. Delegar la emisión al servicio.
        try {
            $factura = $this->emisionService->emitir($emisor, $pv, [
                'cliente'             => $datos['cliente'],
                'detalle'             => $datos['detalle'],
                'metodo_pago'         => $datos['metodo_pago'],
                'descuento_adicional' => $datos['descuento_adicional'] ?? 0,
                'referencia_externa'  => $datos['referencia_externa'] ?? null,
                'sistema_origen'      => $sistemaCliente->siscod,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'exito' => false,
                'error' => 'Error al emitir la factura: ' . $e->getMessage(),
            ], 500);
        }

        // 4. Responder según el estado final (201/200/422 — recién creada).
        $codigo = match ($factura->facsiatest) {
            Factura::SIAT_ACEPTADA  => 201,
            Factura::SIAT_OFFLINE   => 200,
            Factura::SIAT_RECHAZADA => 422,
            default                 => 200,
        };

        return response()->json($this->payload($factura), $codigo);
    }

    /**
     * GET /api/facturas/{factura} — consultar una factura.
     *
     * Siempre responde 200: se trata de una lectura, no de un intento de
     * emisión, así que el estado SIAT de la factura no debe alterar el
     * código HTTP.
     */
    public function show(Request $request, Factura $factura): JsonResponse
    {
        if ($factura->facsisorig !== $request->attributes->get('sistemaCliente')->siscod) {
            return response()->json([
                'exito' => false,
                'error' => 'No tienes acceso a esta factura.',
            ], 403);
        }

        return response()->json($this->payload($factura->load('detalles')), 200);
    }

    /**
     * POST /api/facturas/{factura}/anular — anular una factura ya aceptada.
     */
    public function anular(Request $request, Factura $factura): JsonResponse
    {
        if ($factura->facsisorig !== $request->attributes->get('sistemaCliente')->siscod) {
            return response()->json([
                'exito' => false,
                'error' => 'No tienes acceso a esta factura.',
            ], 403);
        }

        $datos = $request->validate([
            'codigo_motivo' => 'required|integer',
        ]);

        try {
            $factura = $this->anulacionService->anular($factura, $datos['codigo_motivo']);
        } catch (Throwable $e) {
            return response()->json([
                'exito' => false,
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json($this->payload($factura), 200);
    }

    /**
     * Arma el cuerpo JSON con el estado de la factura.
     */
    private function payload(Factura $factura): array
    {
        return [
            'exito'   => in_array($factura->facsiatest, [Factura::SIAT_ACEPTADA, Factura::SIAT_OFFLINE]),
            'factura' => [
                'id'               => $factura->facid,
                'numero'           => $factura->facnro,
                'cuf'              => $factura->faccuf,
                'cufd'             => $factura->faccufd,
                'hash_sha256'      => $factura->facxmlhash,
                'estado'           => $factura->facest,
                'siat_estado'      => $factura->facsiatest,
                'codigo_recepcion' => $factura->faccodrec,
                'fecha_emision'    => $factura->fachora?->format('Y-m-d\TH:i:s.v'),
                'motivo_anulacion' => $factura->facmotanul,
                'fecha_anulacion'  => $factura->facfchanul?->format('Y-m-d\TH:i:s.v'),
            ],
            'representacion_grafica' => $this->representacionGrafica($factura),
        ];
    }

    /**
     * Datos que el sistema cliente necesita para renderizar la factura
     * (QR + leyendas) sin tener que calcular nada por su cuenta. Sin CUF
     * (rechazada, pendiente, error) todavía no hay nada que representar.
     */
    private function representacionGrafica(Factura $factura): ?array
    {
        if (!$factura->faccuf) {
            return null;
        }

        return [
            'qr_url'   => $this->qrUrl($factura),
            'leyendas' => [
                config('siat.leyenda_defecto'),
                'Esta factura contribuye al desarrollo del país. El uso ilícito de este documento será pasible a sanción de acuerdo a Ley.',
                $factura->facsiatest === Factura::SIAT_OFFLINE
                    ? 'FACTURA EMITIDA EN CONTINGENCIA'
                    : 'FACTURA EMITIDA EN LÍNEA',
            ],
        ];
    }

    private function qrUrl(Factura $factura): string
    {
        $emisor = $factura->emisor;
        $urls   = config('siat.qr_urls');
        $base   = $urls[$emisor->emiamb] ?? $urls[Emisor::AMBIENTE_PILOTO];

        return $base . '?' . http_build_query([
            'nit'    => $emisor->eminit,
            'cuf'    => $factura->faccuf,
            'numero' => $factura->facnro,
            't'      => 1, // rollo/térmica; el sistema cliente puede pedir t=2 (carta) si imprime distinto
        ]);
    }
}
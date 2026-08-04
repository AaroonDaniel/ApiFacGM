<?php

namespace App\Services;

use App\Models\Emisor;
use App\Models\EventosSignificativo;
use App\Models\Factura;
use App\Models\PuntoVenta;
use App\Models\Secuencia;
use App\Models\SiatCodigo;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;
use Throwable;

/**
 * Orquesta la emisión de una factura con el patrón de DOS FASES:
 *  FASE 1 — persistir la factura (siempre queda guardada).
 *  FASE 2 — intentar enviarla al SIAT.
 */
class EmisionService
{
    /**
     * Emite una factura para un emisor y un punto de venta (sucursal +
     * punto de venta) específico a partir de datos ya validados.
     *
     * @param Emisor     $emisor
     * @param PuntoVenta $puntoVenta  Dosificación a la que pertenece la factura.
     * @param array      $datos  ['cliente' => [...], 'detalle' => [...], 'metodo_pago' => int,
     *                            'descuento_adicional' => float, 'referencia_externa' => ?string,
     *                            'sistema_origen' => string]
     */
    public function emitir(Emisor $emisor, PuntoVenta $puntoVenta, array $datos): Factura
    {
        // Idempotencia: si un sistema cliente reintenta la misma petición
        // (ej. por timeout de red) con la misma referencia_externa, se
        // devuelve la factura ya existente en vez de crear otra. Chequeo
        // "optimista" aquí (evita gastar un número de secuencia en el caso
        // común); la restricción única de la BD cubre la carrera real si
        // dos peticiones idénticas llegan casi al mismo tiempo (más abajo).
        $refExt = $datos['referencia_externa'] ?? null;
        if ($refExt) {
            $existente = Factura::where('emiid', $emisor->emiid)
                ->where('facsisorig', $datos['sistema_origen'])
                ->where('facrefext', $refExt)
                ->first();

            if ($existente) {
                Log::info("Factura duplicada evitada: emiid={$emisor->emiid} sistema={$datos['sistema_origen']} ref={$refExt} -> factura #{$existente->facnro}.");
                return $existente;
            }
        }

        // -------- FASE 1: persistir --------
        try {
            $factura = DB::transaction(function () use ($emisor, $puntoVenta, $datos) {
                $numero = $this->siguienteNumero($emisor, $puntoVenta);

                $totales = $this->calcularTotales($datos['detalle'], $datos['descuento_adicional'] ?? 0);

                $factura = Factura::create([
                    'emiid'        => $emisor->emiid,
                    'facsuc'       => $puntoVenta->pvsuc,
                    'facpdv'       => $puntoVenta->pvpdv,
                    'facnro'       => $numero,
                    'facfch'       => now()->format('Y-m-d'),
                    'fachora'      => now(),
                    'facnomrazon'  => strtoupper($datos['cliente']['nombre_razon_social']),
                    'facnumdoc'    => $datos['cliente']['numero_documento'],
                    'factipodoc'   => $datos['cliente']['tipo_documento'],
                    'faccompl'     => $datos['cliente']['complemento'] ?? null,
                    'facmetpag'    => $datos['metodo_pago'],
                    'facmonto'     => $totales['total'],
                    'facmontoiva'  => $totales['sujeto_iva'],
                    'facdesc'      => $datos['descuento_adicional'] ?? 0,
                    'facest'       => Factura::ESTADO_VIGENTE,
                    'facsiatest'   => Factura::SIAT_PENDIENTE,
                    'facsisorig'   => $datos['sistema_origen'],
                    'facrefext'    => $datos['referencia_externa'] ?? null,
                ]);

                foreach ($datos['detalle'] as $linea) {
                    $subtotal = ($linea['cantidad'] * $linea['precio_unitario']) - ($linea['descuento'] ?? 0);
                    $factura->detalles()->create([
                        'facdetacteco'  => $linea['actividad_economica'],
                        'facdetprodsin' => $linea['codigo_producto_sin'],
                        'facdetcodprod' => $linea['codigo_producto'],
                        'facdetdesc'    => $linea['descripcion'],
                        'facdetcnt'     => $linea['cantidad'],
                        'facdetunimed'  => $linea['unidad_medida'],
                        'facdetprc'     => $linea['precio_unitario'],
                        'facdetdscto'   => $linea['descuento'] ?? null,
                        'facdetsub'     => $subtotal,
                    ]);
                }

                return $factura;
            });
        } catch (QueryException $e) {
            // Violó facturas_dedup_unico: dos peticiones idénticas llegaron
            // casi al mismo tiempo y la otra ganó la carrera. Devolvemos la
            // suya en vez de la nuestra (que nunca llegó a persistirse).
            if ($refExt && str_contains($e->getMessage(), 'facturas_dedup_unico')) {
                $existente = Factura::where('emiid', $emisor->emiid)
                    ->where('facsisorig', $datos['sistema_origen'])
                    ->where('facrefext', $refExt)
                    ->first();

                if ($existente) {
                    Log::warning("Condición de carrera evitada en emisión duplicada: emiid={$emisor->emiid} ref={$refExt} -> factura #{$existente->facnro}.");
                    return $existente;
                }
            }
            throw $e;
        }

        // -------- FASE 2: enviar al SIAT (fuera de la transacción) --------
        $this->enviarAlSiat($emisor, $puntoVenta, $factura);

        return $factura->refresh();
    }

    /**
     * FASE 2: construye XML + CUF y lo envía. Actualiza el estado según la respuesta.
     *
     * Nunca deja propagar una excepción: la factura de FASE 1 ya está
     * persistida (con su número de secuencia ya consumido), así que si algo
     * inesperado revienta acá, se marca 'error' en vez de dejarla en
     * 'pendiente' para siempre sin que el cliente se entere de su ID.
     */
    private function enviarAlSiat(Emisor $emisor, PuntoVenta $puntoVenta, Factura $factura): void
    {
        try {
            $this->procesarEnvio($emisor, $puntoVenta, $factura);
        } catch (Throwable $e) {
            $factura->update(['facsiatest' => Factura::SIAT_ERROR]);
            Log::error("Factura #{$factura->facnro}: error inesperado en FASE 2, marcada como error. " . $e->getMessage());
        }
    }

    private function procesarEnvio(Emisor $emisor, PuntoVenta $puntoVenta, Factura $factura): void
    {
        $siat = new SiatService($emisor, $puntoVenta->pvsuc, $puntoVenta->pvpdv);
        $contingencia = new ContingenciaService();

        // Ya hay una contingencia declarada para este emisor+punto de venta:
        // antes de irnos offline de nuevo, hacemos un chequeo liviano de
        // comunicación. Si sigue caído, evitamos la danza completa de
        // CUFD/CUIS/envío. Si responde, dejamos caer al flujo normal de
        // abajo — al aceptarse la factura, eso dispara la reconciliación
        // del evento pendiente.
        $eventoActivo = EventosSignificativo::activoDe($emisor->emiid, $puntoVenta->pvsuc, $puntoVenta->pvpdv)->first();
        if ($eventoActivo) {
            $comunicacion = $siat->verifyCommunication();
            if (!($comunicacion['success'] ?? false)) {
                // La extensión de vigencia del CUFD para offline solo cubre
                // 72h desde que arrancó la contingencia. Pasado eso, seguir
                // emitiendo con el mismo CUFD generaría facturas inválidas
                // que el SIAT rechazaría recién al validar el paquete
                // completo — arrastrando también a las facturas offline
                // que sí llegaron a tiempo. Se corta acá: requiere
                // facturación manual (CAFC, todavía no implementada) o
                // cerrar el evento a mano.
                if ($eventoActivo->excedioLimiteOffline()) {
                    $factura->update(['facsiatest' => Factura::SIAT_ERROR]);
                    Log::critical("Factura #{$factura->facnro}: evento {$eventoActivo->eveid} superó las "
                        . EventosSignificativo::LIMITE_HORAS_OFFLINE . 'h de contingencia offline (inicio '
                        . "{$eventoActivo->eveini}) — requiere facturación manual (CAFC), no se puede seguir "
                        . 'emitiendo con este CUFD.');
                    return;
                }

                $this->emitirOffline($factura, $emisor, $eventoActivo->evecufd, $eventoActivo->evecufdctrl, $contingencia);
                Log::warning("Factura #{$factura->facnro} en offline (contingencia activa, evento {$eventoActivo->eveid}).");
                return;
            }
        }

        // Resolver CUFD vigente del emisor (intenta red).
        $cufd = $siat->getActiveCufd();

        if (!$cufd instanceof SiatCodigo) {
            // ¿Nunca hubo un CUFD para este emisor (config incompleta), o el
            // SIAT está inalcanzable y ya teníamos uno antes? Solo en el
            // segundo caso se autoriza el modo offline.
            $ultimoConocido = SiatCodigo::where('emiid', $emisor->emiid)
                ->where('scotipo', SiatCodigo::TIPO_CUFD)
                ->where('scosuc', $puntoVenta->pvsuc)
                ->where('scopdv', $puntoVenta->pvpdv)
                ->latest('scoemi')
                ->first();

            if (!$ultimoConocido) {
                $factura->update(['facsiatest' => Factura::SIAT_ERROR]);
                Log::error("Factura #{$factura->facnro}: no se pudo obtener CUFD y nunca hubo uno previo para el emisor {$emisor->eminit}.");
                return;
            }

            $this->emitirOffline($factura, $emisor, $ultimoConocido->scovalor, $ultimoConocido->scocodctrl, $contingencia);
            Log::warning("Factura #{$factura->facnro} en offline (SIAT inalcanzable al pedir CUFD).");
            return;
        }

        // Construir CUF + XML.
        $factura->load('detalles');
        $builder = new SiatXmlBuilder($factura, $emisor, $cufd->scovalor, $cufd->scocodctrl);
        $xml     = $builder->buildXml();
        $gzip    = $builder->getGzipArchive();
        $cuf     = $builder->generateCuf();
        $hash    = hash('sha256', $xml);

        $factura->update(['faccuf' => $cuf, 'faccufd' => $cufd->scovalor, 'facxmlhash' => $hash]);

        // Obtener CUIS y enviar.
        $cuis = $siat->getActiveCuis();
        if (!$cuis) {
            $factura->update(['facsiatest' => Factura::SIAT_ERROR]);
            Log::error("Factura #{$factura->facnro}: sin CUIS para emitir.");
            return;
        }

        $resp = $siat->receiveInvoice($cuis, $cufd->scovalor, $gzip, now()->format('Y-m-d\TH:i:s.v'), $hash, $factura->facid);

        switch ($resp['status']) {
            case 'accepted':
                $path = $this->guardarXml($gzip, $factura);
                $factura->update([
                    'facsiatest' => Factura::SIAT_ACEPTADA,
                    'faccodrec'  => $resp['codigoRecepcion'] ?? null,
                    'facxmlpath' => $path,
                ]);

                // Si veníamos de una contingencia, esto prueba que la
                // conexión volvió: reconciliar el evento pendiente (incluye
                // eventos ya cerrados cuyo envío del paquete falló antes).
                $eventoPendiente = EventosSignificativo::pendienteDeEnvio($emisor->emiid, $puntoVenta->pvsuc, $puntoVenta->pvpdv)->first();
                if ($eventoPendiente) {
                    try {
                        if (!$contingencia->reconciliar($eventoPendiente, $emisor)) {
                            Log::warning("Reconciliación automática del evento {$eventoPendiente->eveid} no se completó — ver log anterior para el motivo.");
                        }
                    } catch (Throwable $e) {
                        Log::error("Reconciliación del evento {$eventoPendiente->eveid} falló: " . $e->getMessage());
                    }
                }
                break;

            case 'offline':
                $path = $this->guardarXml($gzip, $factura);
                $factura->update(['facsiatest' => Factura::SIAT_OFFLINE, 'facxmlpath' => $path]);
                $contingencia->acoplar($factura, $emisor, $cufd->scovalor, $cufd->scocodctrl);
                Log::warning("Factura #{$factura->facnro} en offline (SIAT inaccesible).");
                break;

            default: // rejected
                $factura->update(['facsiatest' => Factura::SIAT_RECHAZADA]);
                Log::warning("Factura #{$factura->facnro} rechazada: " . ($resp['mensaje'] ?? ''));
        }
    }

    /**
     * Registra una factura CAFC: transcripción de un talón físico
     * preimpreso, usado cuando una contingencia superó las 72h de extensión
     * offline del CUFD (ver EventosSignificativo::excedioLimiteOffline()).
     *
     * A diferencia de emitir(): el número de factura NO sale de la
     * secuencia digital normal (Secuencia) — es el que ya venía impreso en
     * el papel y hay que respetarlo tal cual (la restricción única
     * emiid+facsuc+facpdv+facnro sigue protegiendo contra duplicados). Y
     * requiere que ya exista un evento significativo para ese punto de
     * venta: no tiene sentido transcribir un CAFC sin una contingencia real
     * detrás.
     *
     * @param array $datos ['cliente'=>[...], 'detalle'=>[...], 'metodo_pago'=>int,
     *                      'descuento_adicional'=>float, 'referencia_externa'=>?string,
     *                      'sistema_origen'=>string, 'numero_factura'=>int,
     *                      'codigo_cafc'=>string, 'fecha_emision'=>\Carbon\CarbonInterface]
     */
    public function emitirCafc(Emisor $emisor, PuntoVenta $puntoVenta, array $datos): Factura
    {
        $refExt = $datos['referencia_externa'] ?? null;
        if ($refExt) {
            $existente = Factura::where('emiid', $emisor->emiid)
                ->where('facsisorig', $datos['sistema_origen'])
                ->where('facrefext', $refExt)
                ->first();

            if ($existente) {
                Log::info("Factura CAFC duplicada evitada: emiid={$emisor->emiid} sistema={$datos['sistema_origen']} ref={$refExt} -> factura #{$existente->facnro}.");
                return $existente;
            }
        }

        $evento = EventosSignificativo::where('emiid', $emisor->emiid)
            ->where('evesuc', $puntoVenta->pvsuc)
            ->where('evepdv', $puntoVenta->pvpdv)
            ->whereIn('eveest', [EventosSignificativo::ESTADO_ACTIVO, EventosSignificativo::ESTADO_CERRADO])
            ->latest('eveini')
            ->first();

        if (!$evento) {
            throw new Exception("No hay ningún evento de contingencia registrado para sucursal {$puntoVenta->pvsuc}, "
                . "PDV {$puntoVenta->pvpdv}; no se puede transcribir una factura CAFC sin una contingencia asociada.");
        }

        try {
            $factura = DB::transaction(function () use ($emisor, $puntoVenta, $evento, $datos) {
                $totales = $this->calcularTotales($datos['detalle'], $datos['descuento_adicional'] ?? 0);

                $factura = Factura::create([
                    'emiid'        => $emisor->emiid,
                    'facsuc'       => $puntoVenta->pvsuc,
                    'facpdv'       => $puntoVenta->pvpdv,
                    'facnro'       => $datos['numero_factura'],
                    'facfch'       => $datos['fecha_emision']->format('Y-m-d'),
                    'fachora'      => $datos['fecha_emision'],
                    'facnomrazon'  => strtoupper($datos['cliente']['nombre_razon_social']),
                    'facnumdoc'    => $datos['cliente']['numero_documento'],
                    'factipodoc'   => $datos['cliente']['tipo_documento'],
                    'faccompl'     => $datos['cliente']['complemento'] ?? null,
                    'facmetpag'    => $datos['metodo_pago'],
                    'facmonto'     => $totales['total'],
                    'facmontoiva'  => $totales['sujeto_iva'],
                    'facdesc'      => $datos['descuento_adicional'] ?? 0,
                    'facest'       => Factura::ESTADO_VIGENTE,
                    'facsiatest'   => Factura::SIAT_PENDIENTE,
                    'facsisorig'   => $datos['sistema_origen'],
                    'facrefext'    => $datos['referencia_externa'] ?? null,
                    'faceveid'     => $evento->eveid,
                    'faccafc'      => $datos['codigo_cafc'],
                ]);

                foreach ($datos['detalle'] as $linea) {
                    $subtotal = ($linea['cantidad'] * $linea['precio_unitario']) - ($linea['descuento'] ?? 0);
                    $factura->detalles()->create([
                        'facdetacteco'  => $linea['actividad_economica'],
                        'facdetprodsin' => $linea['codigo_producto_sin'],
                        'facdetcodprod' => $linea['codigo_producto'],
                        'facdetdesc'    => $linea['descripcion'],
                        'facdetcnt'     => $linea['cantidad'],
                        'facdetunimed'  => $linea['unidad_medida'],
                        'facdetprc'     => $linea['precio_unitario'],
                        'facdetdscto'   => $linea['descuento'] ?? null,
                        'facdetsub'     => $subtotal,
                    ]);
                }

                return $factura;
            });
        } catch (QueryException $e) {
            if ($refExt && str_contains($e->getMessage(), 'facturas_dedup_unico')) {
                $existente = Factura::where('emiid', $emisor->emiid)
                    ->where('facsisorig', $datos['sistema_origen'])
                    ->where('facrefext', $refExt)
                    ->first();

                if ($existente) {
                    Log::warning("Condición de carrera evitada en transcripción CAFC duplicada: emiid={$emisor->emiid} ref={$refExt} -> factura #{$existente->facnro}.");
                    return $existente;
                }
            }
            throw $e;
        }

        $this->construirXmlCafc($emisor, $puntoVenta, $factura);

        return $factura->refresh();
    }

    /**
     * Construye el XML/CUF de una factura CAFC con el CUFD vigente ACTUAL
     * — la transcripción ocurre por definición ya con la conexión de
     * vuelta — y la deja en 'offline', lista para empaquetarse aparte en
     * el próximo paquete CAFC (ver ContingenciaService::enviarPaqueteCafc()).
     */
    private function construirXmlCafc(Emisor $emisor, PuntoVenta $puntoVenta, Factura $factura): void
    {
        try {
            $siat = new SiatService($emisor, $puntoVenta->pvsuc, $puntoVenta->pvpdv);
            $cufd = $siat->getActiveCufd();

            if (!$cufd instanceof SiatCodigo) {
                $factura->update(['facsiatest' => Factura::SIAT_ERROR]);
                Log::error("Factura CAFC #{$factura->facnro}: sin CUFD vigente para transcribir (se requiere estar en línea).");
                return;
            }

            $factura->load('detalles');
            $builder = new SiatXmlBuilder(
                $factura, $emisor, $cufd->scovalor, $cufd->scocodctrl,
                SiatXmlBuilder::EMISION_OFFLINE, $factura->faccafc
            );
            $xml  = $builder->buildXml();
            $gzip = $builder->getGzipArchive();
            $cuf  = $builder->generateCuf();
            $hash = hash('sha256', $xml);

            $factura->update(['faccuf' => $cuf, 'faccufd' => $cufd->scovalor, 'facxmlhash' => $hash]);

            $path = $this->guardarXml($gzip, $factura);
            $factura->update(['facsiatest' => Factura::SIAT_OFFLINE, 'facxmlpath' => $path]);
        } catch (Throwable $e) {
            $factura->update(['facsiatest' => Factura::SIAT_ERROR]);
            Log::error("Factura CAFC #{$factura->facnro}: error al construir el XML. " . $e->getMessage());
        }
    }

    /**
     * Construye el XML/CUF con un CUFD dado (vigente o de contingencia) y
     * guarda la factura como offline, acoplándola al evento significativo.
     */
    private function emitirOffline(
        Factura $factura,
        Emisor $emisor,
        string $cufdValor,
        string $controlCode,
        ContingenciaService $contingencia
    ): void {
        $factura->load('detalles');
        $builder = new SiatXmlBuilder($factura, $emisor, $cufdValor, $controlCode);
        $xml     = $builder->buildXml();
        $gzip    = $builder->getGzipArchive();
        $cuf     = $builder->generateCuf();
        $hash    = hash('sha256', $xml);

        $factura->update(['faccuf' => $cuf, 'faccufd' => $cufdValor, 'facxmlhash' => $hash]);

        $path = $this->guardarXml($gzip, $factura);
        $factura->update(['facsiatest' => Factura::SIAT_OFFLINE, 'facxmlpath' => $path]);

        $contingencia->acoplar($factura, $emisor, $cufdValor, $controlCode);
    }

    private function siguienteNumero(Emisor $emisor, PuntoVenta $puntoVenta): int
    {
        $secuencia = Secuencia::where('emiid', $emisor->emiid)
            ->where('secsuc', $puntoVenta->pvsuc)
            ->where('secpdv', $puntoVenta->pvpdv)
            ->where('sectipodoc', 1)
            ->lockForUpdate()
            ->first();

        if (!$secuencia) {
            throw new Exception("No hay secuencia configurada para el punto de venta (sucursal {$puntoVenta->pvsuc}, PDV {$puntoVenta->pvpdv}).");
        }

        $nuevoNumero = $secuencia->secultimo + 1;
        $secuencia->update(['secultimo' => $nuevoNumero]);

        return $nuevoNumero;
    }

    private function calcularTotales(array $detalle, float $descuentoAdicional): array
    {
        $total = 0;
        foreach ($detalle as $linea) {
            $total += ($linea['cantidad'] * $linea['precio_unitario']) - ($linea['descuento'] ?? 0);
        }
        $total -= $descuentoAdicional;

        return ['total' => $total, 'sujeto_iva' => $total];
    }

    private function guardarXml(string $gzip, Factura $factura): string
    {
        $fileName = "factura_{$factura->facsuc}_{$factura->facpdv}_{$factura->facnro}_" . time() . ".xml.gz";
        $path = "siat/facturas/{$fileName}";
        Storage::disk('local')->put($path, $gzip);
        return $path;
    }
}
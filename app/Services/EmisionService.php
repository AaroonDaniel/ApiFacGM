<?php

namespace App\Services;

use App\Models\Actividad;
use App\Models\Emisor;
use App\Models\EventosSignificativo;
use App\Models\Factura;
use App\Models\MetodoPago;
use App\Models\Moneda;
use App\Models\ProductoServicio;
use App\Models\PuntoVenta;
use App\Models\RangoCafc;
use App\Models\Secuencia;
use App\Models\SiatCodigo;
use App\Models\TipoDocumentoIdentidad;
use App\Models\UnidadMedida;
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

        $this->validarCatalogos($emisor, $datos);
        $moneda = $this->resolverMoneda($datos);
        $montoGiftCard = (float) ($datos['monto_gift_card'] ?? 0);
        $this->validarGiftCard($datos['metodo_pago'], $montoGiftCard);

        // -------- FASE 1: persistir --------
        try {
            $factura = DB::transaction(function () use ($emisor, $puntoVenta, $datos, $moneda, $montoGiftCard) {
                $numero = $this->siguienteNumero($emisor, $puntoVenta);

                $totales = $this->calcularTotales($datos['detalle'], $datos['descuento_adicional'] ?? 0, $montoGiftCard);

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
                    'facemail'     => $datos['email'] ?? null,
                    'facmetpag'    => $datos['metodo_pago'],
                    'facmonto'     => $totales['total'],
                    'facmontoiva'  => $totales['sujeto_iva'],
                    'facdesc'      => $datos['descuento_adicional'] ?? 0,
                    'faccodmon'       => $moneda['codigo'],
                    'factipocambio'   => $moneda['tipo_cambio'],
                    'facmontogiftcard' => $montoGiftCard > 0 ? $montoGiftCard : null,
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

        $factura->update([
            'faccuf'      => $cuf,
            'faccufd'     => $cufd->scovalor,
            'facxmlhash'  => $hash,
            'facleyenda'  => $builder->getLeyendaUsada(),
        ]);

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
                (new NotificacionFacturaService())->notificarSiCorresponde($factura);

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

        $rango = $this->validarRangoCafc($emisor, $puntoVenta, $datos['codigo_cafc'], $datos['numero_factura']);
        $this->validarCatalogos($emisor, $datos);
        $moneda = $this->resolverMoneda($datos);
        $montoGiftCard = (float) ($datos['monto_gift_card'] ?? 0);
        $this->validarGiftCard($datos['metodo_pago'], $montoGiftCard);

        try {
            $factura = DB::transaction(function () use ($emisor, $puntoVenta, $evento, $datos, $moneda, $montoGiftCard) {
                $totales = $this->calcularTotales($datos['detalle'], $datos['descuento_adicional'] ?? 0, $montoGiftCard);

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
                    'facemail'     => $datos['email'] ?? null,
                    'facmetpag'    => $datos['metodo_pago'],
                    'facmonto'     => $totales['total'],
                    'facmontoiva'  => $totales['sujeto_iva'],
                    'facdesc'      => $datos['descuento_adicional'] ?? 0,
                    'faccodmon'       => $moneda['codigo'],
                    'factipocambio'   => $moneda['tipo_cambio'],
                    'facmontogiftcard' => $montoGiftCard > 0 ? $montoGiftCard : null,
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

        // Control local informativo — no afecta la validación en sí (que
        // ya pasó arriba), solo deja registro de hasta dónde se consumió
        // el rango.
        if (($rango->rcfultimo ?? 0) < $datos['numero_factura']) {
            $rango->update(['rcfultimo' => $datos['numero_factura']]);
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

            $factura->update([
                'faccuf'     => $cuf,
                'faccufd'    => $cufd->scovalor,
                'facxmlhash' => $hash,
                'facleyenda' => $builder->getLeyendaUsada(),
            ]);

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

        $factura->update([
            'faccuf'     => $cuf,
            'faccufd'    => $cufdValor,
            'facxmlhash' => $hash,
            'facleyenda' => $builder->getLeyendaUsada(),
        ]);

        $path = $this->guardarXml($gzip, $factura);
        $factura->update(['facsiatest' => Factura::SIAT_OFFLINE, 'facxmlpath' => $path]);

        $contingencia->acoplar($factura, $emisor, $cufdValor, $controlCode);
    }

    /**
     * Valida que el número de factura transcrito caiga dentro de un rango
     * CAFC autorizado y vigente para este emisor+punto de venta.
     *
     * El rango en sí es 100% administrativo — el SIN lo entrega por el
     * portal del SIAT junto al talonario físico preimpreso, no hay
     * operación SOAP para "consultar" esto — pero validarlo LOCALMENTE
     * antes de construir el XML es indispensable: un error de tipeo al
     * transcribir (ej. un número que se pasa del rango físico autorizado
     * del talonario) recién se detectaría cuando el SIAT rechace el
     * paquete offline completo, arrastrando a las demás facturas del
     * mismo envío — mucho después y con mucho menos contexto que un 422
     * inmediato acá.
     */
    private function validarRangoCafc(Emisor $emisor, PuntoVenta $puntoVenta, string $codigoCafc, int $numeroFactura): RangoCafc
    {
        $rango = RangoCafc::where('emiid', $emisor->emiid)
            ->where('rcfsuc', $puntoVenta->pvsuc)
            ->where('rcfpdv', $puntoVenta->pvpdv)
            ->where('rcfcafc', $codigoCafc)
            ->where('rcfdesde', '<=', $numeroFactura)
            ->where('rcfhasta', '>=', $numeroFactura)
            ->where('rcflimite', '>=', now())
            ->where('rcfest', true)
            ->first();

        if (!$rango) {
            throw new Exception("El número de factura {$numeroFactura} o el código CAFC '{$codigoCafc}' no corresponden "
                . "a un rango físico autorizado y vigente para este punto de venta (sucursal {$puntoVenta->pvsuc}, "
                . "PDV {$puntoVenta->pvpdv}) del emisor {$emisor->eminit}.");
        }

        return $rango;
    }

    /**
     * Valida que los códigos del detalle y del cliente existan en los
     * catálogos reales sincronizados del SIAT (ver comando
     * siat:sincronizar-catalogos) — evita construir un CUF/XML con un
     * código inventado que de todas formas terminaría rechazado por el
     * SIAT, con un mensaje más claro y sin gastar el número de secuencia.
     *
     * Actividad/ProductoServicio son POR EMISOR (dependen de qué tiene
     * habilitado ese NIT); UnidadMedida/MetodoPago/TipoDocumentoIdentidad
     * son catálogos globales del SIN.
     */
    private function validarCatalogos(Emisor $emisor, array $datos): void
    {
        if (!TipoDocumentoIdentidad::where('tdicod', $datos['cliente']['tipo_documento'])->exists()) {
            throw new Exception("Tipo de documento de identidad '{$datos['cliente']['tipo_documento']}' no es un código válido del SIN.");
        }

        if (!MetodoPago::where('mpacod', $datos['metodo_pago'])->exists()) {
            throw new Exception("Método de pago '{$datos['metodo_pago']}' no es un código válido del SIN.");
        }

        foreach ($datos['detalle'] as $linea) {
            if (!Actividad::where('emiid', $emisor->emiid)->where('actcod', $linea['actividad_economica'])->where('actest', true)->exists()) {
                throw new Exception("Actividad económica '{$linea['actividad_economica']}' no está habilitada para el emisor {$emisor->eminit}.");
            }

            if (!ProductoServicio::where('emiid', $emisor->emiid)
                ->where('proactcod', $linea['actividad_economica'])
                ->where('procodsin', $linea['codigo_producto_sin'])
                ->exists()) {
                throw new Exception("Código de producto SIN '{$linea['codigo_producto_sin']}' no está habilitado para "
                    . "la actividad '{$linea['actividad_economica']}' del emisor {$emisor->eminit}.");
            }

            if (!UnidadMedida::where('unicod', $linea['unidad_medida'])->exists()) {
                throw new Exception("Unidad de medida '{$linea['unidad_medida']}' no es un código válido del SIN.");
            }
        }
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

    private function calcularTotales(array $detalle, float $descuentoAdicional, float $montoGiftCard = 0): array
    {
        $total = 0;
        foreach ($detalle as $linea) {
            $total += ($linea['cantidad'] * $linea['precio_unitario']) - ($linea['descuento'] ?? 0);
        }
        $total -= $descuentoAdicional;

        if ($montoGiftCard > $total) {
            throw new Exception("El monto de gift card ({$montoGiftCard}) no puede superar el monto total de la factura ({$total}).");
        }

        // montoTotalSujetoIva = montoTotal - montoGiftCard (fórmula del
        // XSD): sin gift card, montoGiftCard es 0 y sujeto_iva == total.
        return ['total' => $total, 'sujeto_iva' => $total - $montoGiftCard];
    }

    /**
     * Resuelve codigoMoneda/tipoCambio de los datos de entrada. BOLIVIANO
     * (moncod=1) es el único caso real de producción y no requiere
     * tipoCambio explícito (se fija en 1.0); cualquier otra moneda —
     * relevante solo para los casos de prueba de moneda extranjera de la
     * certificación Fase 1 del SIN— exige que el tipo de cambio venga
     * explícito y mayor a 0, porque montoTotalMoneda = montoTotal /
     * tipoCambio y una división por 0 o un valor inventado haría que el
     * validador del SIN rechace el XML por inconsistencia aritmética.
     */
    private function resolverMoneda(array $datos): array
    {
        $codigoMoneda = (int) ($datos['moneda']['codigo'] ?? Moneda::BOLIVIANO);

        if (!Moneda::where('moncod', $codigoMoneda)->exists()) {
            throw new Exception("Código de moneda '{$codigoMoneda}' no es un código válido del SIN.");
        }

        if ($codigoMoneda === Moneda::BOLIVIANO) {
            $tipoCambio = 1.0;
        } else {
            $tipoCambio = (float) ($datos['moneda']['tipo_cambio'] ?? 0);
            if ($tipoCambio <= 0) {
                throw new Exception("El tipo de cambio es obligatorio y debe ser mayor a 0 cuando la moneda no es BOLIVIANO (código {$codigoMoneda}).");
            }
        }

        return ['codigo' => $codigoMoneda, 'tipo_cambio' => $tipoCambio];
    }

    /**
     * El SIN valida que el pago con gift card corresponda al método de
     * pago declarado — confirmado en vivo contra el SIAT piloto real: un
     * montoGiftCard > 0 con un método de pago que no incluye "GIFT" (ej.
     * DÉBITO AUTOMÁTICO - VALE, código 299) se rechaza con "MONTO GIFT
     * CARD NO CORRESPONDE AL METODO DE PAGO". Se valida acá con el mismo
     * criterio (mpadesc contiene GIFT) para dar un 422 inmediato en vez de
     * esperar el rechazo remoto.
     */
    private function validarGiftCard(int $metodoPago, float $montoGiftCard): void
    {
        if ($montoGiftCard <= 0) {
            return;
        }

        $valido = MetodoPago::where('mpacod', $metodoPago)
            ->where('mpadesc', 'like', '%GIFT%')
            ->exists();

        if (!$valido) {
            throw new Exception("El monto de gift card solo es válido con un método de pago que incluya GIFT-CARD; "
                . "el método de pago '{$metodoPago}' no corresponde.");
        }
    }

    private function guardarXml(string $gzip, Factura $factura): string
    {
        $fileName = "factura_{$factura->facsuc}_{$factura->facpdv}_{$factura->facnro}_" . time() . ".xml.gz";
        $path = "siat/facturas/{$fileName}";
        Storage::disk('local')->put($path, $gzip);
        return $path;
    }
}
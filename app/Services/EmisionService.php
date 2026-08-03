<?php

namespace App\Services;

use App\Models\Emisor;
use App\Models\EventosSignificativo;
use App\Models\Factura;
use App\Models\Secuencia;
use App\Models\SiatCodigo;
use Carbon\Carbon;
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
     * Emite una factura para un emisor a partir de datos ya validados.
     *
     * @param Emisor $emisor
     * @param array  $datos  ['cliente' => [...], 'detalle' => [...], 'metodo_pago' => int,
     *                        'descuento_adicional' => float, 'referencia_externa' => ?string,
     *                        'sistema_origen' => string]
     */
    public function emitir(Emisor $emisor, array $datos): Factura
    {
        // -------- FASE 1: persistir --------
        $factura = DB::transaction(function () use ($emisor, $datos) {
            $numero = $this->siguienteNumero($emisor);

            $totales = $this->calcularTotales($datos['detalle'], $datos['descuento_adicional'] ?? 0);

            $factura = Factura::create([
                'emiid'        => $emisor->emiid,
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

        // -------- FASE 2: enviar al SIAT (fuera de la transacción) --------
        $this->enviarAlSiat($emisor, $factura);

        return $factura->refresh();
    }

    /**
     * FASE 2: construye XML + CUF y lo envía. Actualiza el estado según la respuesta.
     */
    private function enviarAlSiat(Emisor $emisor, Factura $factura): void
    {
        $siat = new SiatService($emisor);
        $contingencia = new ContingenciaService();

        // Ya hay una contingencia declarada para este emisor: antes de irnos
        // offline de nuevo, hacemos un chequeo liviano de comunicación. Si
        // sigue caído, evitamos la danza completa de CUFD/CUIS/envío. Si
        // responde, dejamos caer al flujo normal de abajo — al aceptarse la
        // factura, eso dispara la reconciliación del evento pendiente.
        $eventoActivo = EventosSignificativo::activoDe($emisor->emiid)->first();
        if ($eventoActivo) {
            $comunicacion = $siat->verifyCommunication();
            if (!($comunicacion['success'] ?? false)) {
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

        $factura->update(['faccuf' => $cuf, 'faccufd' => $cufd->scovalor]);

        // Obtener CUIS y enviar.
        $cuis = $siat->getActiveCuis();
        if (!$cuis) {
            $factura->update(['facsiatest' => Factura::SIAT_ERROR]);
            Log::error("Factura #{$factura->facnro}: sin CUIS para emitir.");
            return;
        }

        $resp = $siat->receiveInvoice($cuis, $cufd->scovalor, $gzip, now()->format('Y-m-d\TH:i:s.v'), $hash);

        switch ($resp['status']) {
            case 'accepted':
                $factura->update([
                    'facsiatest' => Factura::SIAT_ACEPTADA,
                    'faccodrec'  => $resp['codigoRecepcion'] ?? null,
                ]);

                // Si veníamos de una contingencia, esto prueba que la
                // conexión volvió: reconciliar el evento pendiente (incluye
                // eventos ya cerrados cuyo envío del paquete falló antes).
                $eventoPendiente = EventosSignificativo::pendienteDeEnvio($emisor->emiid)->first();
                if ($eventoPendiente) {
                    try {
                        $contingencia->reconciliar($eventoPendiente, $emisor);
                    } catch (Throwable $e) {
                        Log::error("Reconciliación del evento {$eventoPendiente->eveid} falló: " . $e->getMessage());
                    }
                }
                break;

            case 'offline':
                $path = $this->guardarXmlOffline($gzip, $factura);
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
        $gzip    = $builder->getGzipArchive();
        $cuf     = $builder->generateCuf();

        $factura->update(['faccuf' => $cuf, 'faccufd' => $cufdValor]);

        $path = $this->guardarXmlOffline($gzip, $factura);
        $factura->update(['facsiatest' => Factura::SIAT_OFFLINE, 'facxmlpath' => $path]);

        $contingencia->acoplar($factura, $emisor, $cufdValor, $controlCode);
    }

    private function siguienteNumero(Emisor $emisor): int
    {
        $secuencia = Secuencia::where('emiid', $emisor->emiid)
            ->where('secsuc', $emisor->emisuc)
            ->where('secpdv', $emisor->emipdv)
            ->where('sectipodoc', 1)
            ->lockForUpdate()
            ->first();

        if (!$secuencia) {
            throw new Exception('No hay secuencia configurada para este emisor.');
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

    private function guardarXmlOffline(string $gzip, Factura $factura): string
    {
        $fileName = "factura_offline_{$factura->facnro}_" . time() . ".xml.gz";
        $path = "siat/offline/{$fileName}";
        Storage::disk('local')->put($path, $gzip);
        return $path;
    }
}
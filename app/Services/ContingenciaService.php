<?php

namespace App\Services;

use App\Models\Emisor;
use App\Models\EventosSignificativo;
use App\Models\Factura;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Orquesta el ciclo de vida de un Evento Significativo por caída del SIAT:
 *
 *  1. find-or-create: EmisionService acopla cada factura offline al evento
 *     'activo' del emisor (uno solo por emisor a la vez).
 *  2. reconciliar(): al recuperar conexión, cierra el evento, lo registra
 *     ante el SIAT (retroactivo, con el período real de la caída), empaqueta
 *     las facturas acopladas y envía el paquete.
 *  3. validar(): consulta el resultado de validación del paquete y
 *     actualiza el estado final de cada factura.
 *
 * ESTADO: code-complete, pendiente de prueba en vivo end-to-end.
 * Verificado en vivo contra el SIAT piloto (2026-08-03): detección de caída
 * real, creación del evento 'activo', y acoplamiento de varias facturas
 * offline al mismo evento (acoplar()).
 * NO verificado en vivo todavía: reconciliar() completo (registrarEventoSignificativo
 * + empaquetado + envío) y validar(). Quedó bloqueado por un rechazo del
 * SIAT piloto ("API KEY NO VALIDO") al pedir CUIS/CUFD nuevos — no relacionado
 * con este código (verificarComunicacion() y el uso de códigos ya emitidos
 * seguían funcionando bien en ese momento). Las facturas #9, #10, #11 y #13
 * del entorno de pruebas quedaron reales y correctamente acopladas al evento
 * #1, esperando esa reconciliación.
 */
class ContingenciaService
{
    /**
     * Devuelve el evento 'activo' del emisor, o crea uno nuevo si no existe.
     * Se usa el CUFD/CUFD-control que realmente se usó para construir el
     * XML de la factura que disparó la contingencia.
     */
    public function acoplar(Factura $factura, Emisor $emisor, string $cufdUsado, string $controlCodeUsado): EventosSignificativo
    {
        return DB::transaction(function () use ($factura, $emisor, $cufdUsado, $controlCodeUsado) {
            $evento = EventosSignificativo::activoDe($emisor->emiid)->lockForUpdate()->first();

            if (!$evento) {
                $evento = EventosSignificativo::create([
                    'emiid'       => $emisor->emiid,
                    'evecod'      => EventosSignificativo::COD_SIN_INACCESIBLE,
                    'evedesc'     => 'SIAT inaccesible (detectado automáticamente)',
                    'eveini'      => $factura->fachora ?? now(),
                    'evecufd'     => $cufdUsado,
                    'evecufdctrl' => $controlCodeUsado,
                    'eveest'      => EventosSignificativo::ESTADO_ACTIVO,
                ]);
            }

            $factura->update(['faceveid' => $evento->eveid]);

            return $evento;
        });
    }

    /**
     * Cierra el evento activo, lo registra ante el SIAT y envía el paquete
     * con todas las facturas acopladas. Se llama cuando se detecta que la
     * conexión volvió (una factura logró emitirse online normalmente).
     */
    public function reconciliar(EventosSignificativo $evento, Emisor $emisor): void
    {
        $facturas = $evento->facturas()->where('facsiatest', Factura::SIAT_OFFLINE)->get();

        if ($facturas->isEmpty()) {
            // Evento sin facturas asociadas (no debería pasar); lo cerramos igual.
            $evento->update(['eveest' => EventosSignificativo::ESTADO_CERRADO, 'evefin' => now()]);
            return;
        }

        $siat = new SiatService($emisor);

        $cuis = $siat->getActiveCuis();
        if (!$cuis) {
            Log::error("Contingencia emisor {$emisor->eminit}: sin CUIS para reconciliar evento {$evento->eveid}.");
            return;
        }

        $cufdActual = $siat->getActiveCufd();
        if (!$cufdActual) {
            Log::error("Contingencia emisor {$emisor->eminit}: sin CUFD vigente para reconciliar evento {$evento->eveid}.");
            return;
        }

        $inicio = $evento->eveini;
        $fin    = $facturas->max('fachora') ?? now();

        $registro = $siat->registrarEventoSignificativo(
            $cuis,
            $cufdActual->scovalor,
            $evento->evecod,
            $evento->evedesc,
            $inicio,
            $fin,
            $evento->evecufd
        );

        if (!($registro['success'] ?? false)) {
            Log::error("Contingencia emisor {$emisor->eminit}: no se pudo registrar el evento {$evento->eveid} ante el SIAT. "
                . ($registro['mensaje'] ?? ''));
            return;
        }

        $evento->update([
            'eveest'    => EventosSignificativo::ESTADO_CERRADO,
            'evefin'    => $fin,
            'evecodrec' => $registro['codigoRecepcion'],
        ]);

        // --- Empaquetar y enviar ---
        $paquete = [];
        foreach ($facturas as $factura) {
            if (!$factura->facxmlpath || !Storage::disk('local')->exists($factura->facxmlpath)) {
                Log::error("Contingencia: factura #{$factura->facnro} sin XML offline guardado, se omite del paquete.");
                continue;
            }
            $xml = gzdecode(Storage::disk('local')->get($factura->facxmlpath));
            $paquete[] = ['cuf' => $factura->faccuf, 'xml' => $xml];
        }

        if (empty($paquete)) {
            Log::error("Contingencia emisor {$emisor->eminit}: evento {$evento->eveid} registrado pero sin XML válidos para empaquetar.");
            return;
        }

        $builder = new SiatOfflinePackageBuilder();
        $armado  = $builder->construir($paquete);

        $envio = $siat->enviarPaqueteOffline(
            $cuis,
            $cufdActual->scovalor,
            $armado['archivo'],
            now()->format('Y-m-d\TH:i:s.v'),
            $armado['hash'],
            $armado['cantidad'],
            (string) $registro['codigoRecepcion']
        );

        if (($envio['status'] ?? null) !== 'received') {
            Log::error("Contingencia emisor {$emisor->eminit}: paquete del evento {$evento->eveid} no fue recibido. "
                . ($envio['mensaje'] ?? ''));
            return;
        }

        $evento->update(['evecodrecpaq' => $envio['codigoRecepcion']]);
        $facturas->each(fn (Factura $f) => $f->update(['facsiatest' => Factura::SIAT_EMPAQUETADA]));

        Log::info("Contingencia emisor {$emisor->eminit}: evento {$evento->eveid} reconciliado, "
            . "paquete {$envio['codigoRecepcion']} enviado con {$armado['cantidad']} factura(s).");
    }

    /**
     * Consulta el resultado de validación de un paquete ya enviado y
     * actualiza el estado final de sus facturas.
     */
    public function validar(EventosSignificativo $evento, Emisor $emisor): string
    {
        if (!$evento->evecodrecpaq) {
            throw new \Exception("El evento {$evento->eveid} no tiene un paquete enviado que validar.");
        }

        $siat = new SiatService($emisor);
        $cuis = $siat->getActiveCuis();
        $cufd = $siat->getActiveCufd();

        if (!$cuis || !$cufd) {
            throw new \Exception('Sin CUIS/CUFD vigente para validar el paquete.');
        }

        $resultado = $siat->validarPaqueteOffline($cuis, $cufd->scovalor, (string) $evento->evecodrecpaq);

        $facturas = Factura::where('faceveid', $evento->eveid)
            ->where('facsiatest', Factura::SIAT_EMPAQUETADA)
            ->get();

        switch ($resultado['status']) {
            case 'accepted':
                $facturas->each(fn (Factura $f) => $f->update(['facsiatest' => Factura::SIAT_ACEPTADA]));
                break;
            case 'rejected':
                $facturas->each(fn (Factura $f) => $f->update(['facsiatest' => Factura::SIAT_RECHAZADA]));
                break;
            // 'observed' / 'processing': se deja igual, hay que reintentar la validación después.
        }

        return $resultado['status'];
    }
}

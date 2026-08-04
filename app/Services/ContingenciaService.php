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
 * ESTADO: verificado en vivo end-to-end contra el SIAT piloto (2026-08-03).
 * Ciclo completo probado con datos reales: caída detectada → 3 facturas
 * offline acopladas al mismo evento → reconexión → reconciliar() registra
 * el evento retroactivo, arma y envía el paquete → validar() confirma
 * 'accepted' → las 4 facturas quedan 'aceptada'. reconciliar() es
 * reanudable: si el envío del paquete falla tras registrar el evento
 * (evecodrec ya seteado), un reintento no vuelve a registrar el evento.
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
            $evento = EventosSignificativo::activoDe($emisor->emiid, $factura->facsuc, $factura->facpdv)
                ->lockForUpdate()->first();

            if (!$evento) {
                $evento = EventosSignificativo::create([
                    'emiid'       => $emisor->emiid,
                    'evesuc'      => $factura->facsuc,
                    'evepdv'      => $factura->facpdv,
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
     *
     * Devuelve true solo si de verdad se avanzó (registro y/o envío del
     * paquete confirmados por el SIAT) — false en cualquier salida
     * temprana. No basta con "no lanzó excepción": antes esta función
     * podía terminar sin hacer nada real y el llamador lo reportaba como
     * éxito igual.
     */
    public function reconciliar(EventosSignificativo $evento, Emisor $emisor): bool
    {
        // whereNull('faccafc'): las transcritas de talón preimpreso van en
        // un paquete aparte — ver enviarPaqueteCafc().
        $facturas = $evento->facturas()->where('facsiatest', Factura::SIAT_OFFLINE)
            ->whereNull('faccafc')
            ->get();

        if ($facturas->isEmpty()) {
            // Evento sin facturas asociadas (no debería pasar); lo cerramos igual.
            Log::warning("Contingencia emisor {$emisor->eminit}: evento {$evento->eveid} no tiene facturas offline asociadas, se cierra sin registrar.");
            $evento->update(['eveest' => EventosSignificativo::ESTADO_CERRADO, 'evefin' => now()]);
            return false;
        }

        $siat = new SiatService($emisor, $evento->evesuc, $evento->evepdv);

        $cuis = $siat->getActiveCuis();
        if (!$cuis) {
            Log::error("Contingencia emisor {$emisor->eminit}: sin CUIS para reconciliar evento {$evento->eveid}.");
            return false;
        }

        $cufdActual = $siat->getActiveCufd();
        if (!$cufdActual) {
            Log::error("Contingencia emisor {$emisor->eminit}: sin CUFD vigente para reconciliar evento {$evento->eveid}.");
            return false;
        }

        // Reanudable: si ya se registró el evento ante el SIAT en un intento
        // previo (p. ej. el envío del paquete falló después), no se vuelve
        // a registrar — solo se reintenta el empaquetado y envío.
        if ($evento->evecodrec) {
            Log::info("Contingencia emisor {$emisor->eminit}: evento {$evento->eveid} ya registrado (codigoRecepcion={$evento->evecodrec}), reintentando solo el envío del paquete.");
        } else {
            $inicio = $evento->eveini;
            $fin    = $facturas->max('fachora') ?? now();

            // El SIAT rechaza un evento con duración cero (rango de fechas
            // inválido) — pasa con eventos de una sola factura, donde
            // inicio y fin serían el mismo instante exacto.
            if ($fin->lessThanOrEqualTo($inicio)) {
                $fin = $inicio->copy()->addSecond();
            }

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
                return false;
            }

            $evento->update([
                'eveest'    => EventosSignificativo::ESTADO_CERRADO,
                'evefin'    => $fin,
                'evecodrec' => $registro['codigoRecepcion'],
            ]);
        }

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
            return false;
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
            (string) $evento->evecodrec
        );

        if (($envio['status'] ?? null) !== 'received') {
            Log::error("Contingencia emisor {$emisor->eminit}: paquete del evento {$evento->eveid} no fue recibido. "
                . ($envio['mensaje'] ?? ''));
            return false;
        }

        $evento->update(['evecodrecpaq' => $envio['codigoRecepcion']]);
        $facturas->each(fn (Factura $f) => $f->update(['facsiatest' => Factura::SIAT_EMPAQUETADA]));

        Log::info("Contingencia emisor {$emisor->eminit}: evento {$evento->eveid} reconciliado, "
            . "paquete {$envio['codigoRecepcion']} enviado con {$armado['cantidad']} factura(s).");

        return true;
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

        $siat = new SiatService($emisor, $evento->evesuc, $evento->evepdv);
        $cuis = $siat->getActiveCuis();
        $cufd = $siat->getActiveCufd();

        if (!$cuis || !$cufd) {
            throw new \Exception('Sin CUIS/CUFD vigente para validar el paquete.');
        }

        $resultado = $siat->validarPaqueteOffline($cuis, $cufd->scovalor, (string) $evento->evecodrecpaq);

        // whereNull('faccafc'): el paquete CAFC (si existe) es uno aparte,
        // con su propio codigoRecepcion — ver validarCafc(). No hay que
        // confundir el resultado de uno con las facturas del otro.
        $facturas = Factura::where('faceveid', $evento->eveid)
            ->where('facsiatest', Factura::SIAT_EMPAQUETADA)
            ->whereNull('faccafc')
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

    /**
     * Empaqueta y envía las facturas CAFC (transcritas de talones
     * preimpresos) acopladas a un evento YA REGISTRADO ante el SIAT — el
     * paquete CAFC referencia el mismo codigoEvento, pero es un envío
     * separado del offline normal porque el campo `cafc` vive a nivel de
     * paquete completo, no por factura (ver SiatService::enviarPaqueteOffline).
     *
     * Se puede llamar varias veces mientras se van transcribiendo más
     * facturas dentro de la ventana de 72h: cada corrida solo empaqueta las
     * que todavía no se enviaron (facsiatest = 'offline').
     */
    public function enviarPaqueteCafc(EventosSignificativo $evento, Emisor $emisor): bool
    {
        if (!$evento->evecodrec) {
            Log::error("Contingencia CAFC emisor {$emisor->eminit}: evento {$evento->eveid} todavía no está registrado ante el SIAT, no se puede enviar el paquete CAFC.");
            return false;
        }

        $facturas = Factura::where('faceveid', $evento->eveid)
            ->where('facsiatest', Factura::SIAT_OFFLINE)
            ->whereNotNull('faccafc')
            ->get();

        if ($facturas->isEmpty()) {
            return false;
        }

        $siat = new SiatService($emisor, $evento->evesuc, $evento->evepdv);

        $cuis = $siat->getActiveCuis();
        $cufdActual = $siat->getActiveCufd();
        if (!$cuis || !$cufdActual) {
            Log::error("Contingencia CAFC emisor {$emisor->eminit}: sin CUIS/CUFD vigente para enviar el paquete del evento {$evento->eveid}.");
            return false;
        }

        $paquete = [];
        foreach ($facturas as $factura) {
            if (!$factura->facxmlpath || !Storage::disk('local')->exists($factura->facxmlpath)) {
                Log::error("Contingencia CAFC: factura #{$factura->facnro} sin XML guardado, se omite del paquete.");
                continue;
            }
            $xml = gzdecode(Storage::disk('local')->get($factura->facxmlpath));
            $paquete[] = ['cuf' => $factura->faccuf, 'xml' => $xml];
        }

        if (empty($paquete)) {
            Log::error("Contingencia CAFC emisor {$emisor->eminit}: evento {$evento->eveid} sin XML válidos para empaquetar.");
            return false;
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
            (string) $evento->evecodrec,
            $facturas->first()->faccafc
        );

        if (($envio['status'] ?? null) !== 'received') {
            Log::error("Contingencia CAFC emisor {$emisor->eminit}: paquete del evento {$evento->eveid} no fue recibido. "
                . ($envio['mensaje'] ?? ''));
            return false;
        }

        $evento->update(['evecodrecpaqcafc' => $envio['codigoRecepcion']]);
        $facturas->each(fn (Factura $f) => $f->update(['facsiatest' => Factura::SIAT_EMPAQUETADA]));

        Log::info("Contingencia CAFC emisor {$emisor->eminit}: evento {$evento->eveid}, "
            . "paquete CAFC {$envio['codigoRecepcion']} enviado con {$armado['cantidad']} factura(s).");

        return true;
    }

    /**
     * Consulta el resultado de validación del paquete CAFC (separado del
     * paquete offline normal, ver enviarPaqueteCafc()).
     */
    public function validarCafc(EventosSignificativo $evento, Emisor $emisor): string
    {
        if (!$evento->evecodrecpaqcafc) {
            throw new \Exception("El evento {$evento->eveid} no tiene un paquete CAFC enviado que validar.");
        }

        $siat = new SiatService($emisor, $evento->evesuc, $evento->evepdv);
        $cuis = $siat->getActiveCuis();
        $cufd = $siat->getActiveCufd();

        if (!$cuis || !$cufd) {
            throw new \Exception('Sin CUIS/CUFD vigente para validar el paquete CAFC.');
        }

        $resultado = $siat->validarPaqueteOffline($cuis, $cufd->scovalor, (string) $evento->evecodrecpaqcafc);

        $facturas = Factura::where('faceveid', $evento->eveid)
            ->where('facsiatest', Factura::SIAT_EMPAQUETADA)
            ->whereNotNull('faccafc')
            ->get();

        switch ($resultado['status']) {
            case 'accepted':
                $facturas->each(fn (Factura $f) => $f->update(['facsiatest' => Factura::SIAT_ACEPTADA]));
                break;
            case 'rejected':
                $facturas->each(fn (Factura $f) => $f->update(['facsiatest' => Factura::SIAT_RECHAZADA]));
                break;
        }

        return $resultado['status'];
    }
}

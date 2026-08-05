<?php

namespace App\Services;

use App\Models\Emisor;
use App\Models\EventosSignificativo;
use App\Models\Factura;
use App\Models\PaqueteCafc;
use App\Models\PuntoVenta;
use Carbon\CarbonInterface;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

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
     * Registra manualmente un Evento Significativo que el sistema no puede
     * detectar solo — corte de energía, falla de software/hardware, etc.
     * (a diferencia de "SIAT inaccesible", que sí se detecta automático
     * cuando una factura no logra emitirse online, ver acoplar()).
     *
     * Se reporta DESPUÉS de que ya pasó (inicio y fin conocidos): tiene
     * sentido — si el corte apagó el propio servidor, nadie puede llamar
     * a este método hasta que vuelva la luz y con ella la conexión.
     *
     * Intenta registrar el evento ante el SIAT de inmediato; si falla (sin
     * red, sin CUIS/CUFD), el evento queda ACTIVO igual, y el ciclo normal
     * de reconciliación (cron de 5 min, o la próxima factura online
     * aceptada de ese punto de venta) lo reintenta solo — mismo mecanismo
     * reanudable que ya usan los eventos detectados automáticamente.
     *
     * @throws Exception si ya hay un evento sin resolver para ese punto de venta.
     */
    public function registrarManual(
        Emisor $emisor,
        PuntoVenta $puntoVenta,
        int $codigoMotivo,
        string $descripcion,
        CarbonInterface $inicio,
        CarbonInterface $fin
    ): EventosSignificativo {
        $existente = EventosSignificativo::activoDe($emisor->emiid, $puntoVenta->pvsuc, $puntoVenta->pvpdv)->first();
        if ($existente) {
            throw new Exception("Ya hay un evento significativo activo (#{$existente->eveid}) para este punto de venta "
                . '(sucursal ' . $puntoVenta->pvsuc . ", PDV {$puntoVenta->pvpdv}); no se puede registrar uno nuevo "
                . 'mientras el anterior no se resuelva.');
        }

        $siat = new SiatService($emisor, $puntoVenta->pvsuc, $puntoVenta->pvpdv);
        $cufdActual = $siat->getActiveCufd();

        if (!$cufdActual) {
            throw new Exception('No se pudo obtener un CUFD vigente para registrar el evento (se requiere estar en línea).');
        }

        $evento = EventosSignificativo::create([
            'emiid'       => $emisor->emiid,
            'evesuc'      => $puntoVenta->pvsuc,
            'evepdv'      => $puntoVenta->pvpdv,
            'evecod'      => $codigoMotivo,
            'evedesc'     => $descripcion,
            'eveini'      => $inicio,
            'evefin'      => $fin,
            // Sin facturas offline construidas durante este evento (por
            // definición: se reporta después de que ya pasó), no hay un
            // CUFD "usado en la contingencia" real que registrar — se
            // deja constancia del que estaba vigente al reportarlo.
            'evecufd'     => $cufdActual->scovalor,
            'evecufdctrl' => $cufdActual->scocodctrl,
            'eveest'      => EventosSignificativo::ESTADO_ACTIVO,
        ]);

        try {
            $this->reconciliar($evento, $emisor);
        } catch (Throwable $e) {
            Log::error("Evento manual #{$evento->eveid}: fallo al intentar registrar de inmediato — " . $e->getMessage());
        }

        return $evento->refresh();
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
        // un paquete aparte — ver enviarPaqueteCafc(). orderBy('facid'):
        // orden determinístico — hace falta para que facnumeroarchivo (la
        // posición dentro del .tar) sea reproducible al validar después.
        // whereNull('faccafc'): las transcritas de talón preimpreso van en
        // un paquete aparte — ver enviarPaqueteCafc(). orderBy('facid'):
        // orden determinístico — hace falta para que facnumeroarchivo (la
        // posición dentro del .tar) sea reproducible al validar después.
        $facturas = $evento->facturas()->where('facsiatest', Factura::SIAT_OFFLINE)
            ->whereNull('faccafc')
            ->orderBy('facid')
            ->get();

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

        $registradoAhora = false;

        // Reanudable: si ya se registró el evento ante el SIAT en un intento
        // previo (p. ej. el envío del paquete falló después), no se vuelve
        // a registrar — solo se reintenta el empaquetado y envío.
        if ($evento->evecodrec) {
            Log::info("Contingencia emisor {$emisor->eminit}: evento {$evento->eveid} ya registrado (codigoRecepcion={$evento->evecodrec}), reintentando solo el envío del paquete.");
        } else {
            $inicio = $evento->eveini;
            // Sin facturas offline "normales" (ej. un evento manual
            // puramente CAFC, ver registrarManual()), evefin ya viene
            // provisto de antemano; si tampoco hay eso, now() como último
            // recurso.
            $fin = $facturas->max('fachora') ?? $evento->evefin ?? now();

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
            $registradoAhora = true;
        }

        if ($facturas->isEmpty()) {
            // Nada que empaquetar en el canal normal — puede ser un evento
            // puramente CAFC (registrarManual() sin facturas offline
            // "normales" acopladas). El registro en sí ya es avance real
            // si se acaba de hacer en esta corrida. OJO: si ya estaba
            // registrado de antes, este evento se sigue viendo como
            // "pendiente de envío" (evecodrecpaq nunca se setea porque no
            // hay nada que empaquetar acá) y el ciclo normal lo va a seguir
            // reintentando sin efecto — inocuo, pero no se resuelve solo.
            return $registradoAhora;
        }

        // --- Empaquetar y enviar ---
        $paquete = [];
        $numeroArchivo = 0;
        foreach ($facturas as $factura) {
            if (!$factura->facxmlpath || !Storage::disk('local')->exists($factura->facxmlpath)) {
                Log::error("Contingencia: factura #{$factura->facnro} sin XML offline guardado, se omite del paquete.");
                continue;
            }
            $numeroArchivo++;
            $factura->update(['facnumeroarchivo' => $numeroArchivo]);
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

        $this->aplicarResultadoValidacion($facturas, $resultado, new NotificacionFacturaService());

        return $resultado['status'];
    }

    /**
     * Aplica el resultado de validación a las facturas de un paquete.
     *
     * Si el estado agregado es 'rejected' pero el SIAT mandó mensajesList
     * con numeroArchivo (posición dentro del .tar, ver
     * Factura::facnumeroarchivo), se usa ese detalle para NO tumbar
     * documentos que en realidad no tuvieron un error real — solo el/los
     * marcados ahí se rechazan, el resto queda aceptado. Sin ese detalle
     * granular (mensajesList vacío), se mantiene el comportamiento
     * conservador anterior: rechazar el paquete completo. No está
     * confirmado en vivo que el SIAT realmente distinga documentos dentro
     * de un paquete rechazado — este es el fallback seguro mientras tanto.
     */
    private function aplicarResultadoValidacion($facturas, array $resultado, NotificacionFacturaService $notificacion): void
    {
        switch ($resultado['status']) {
            case 'accepted':
                $facturas->each(function (Factura $f) use ($notificacion) {
                    $f->update(['facsiatest' => Factura::SIAT_ACEPTADA]);
                    $notificacion->notificarSiCorresponde($f);
                });
                break;

            case 'rejected':
                $archivosConError = collect($resultado['mensajes'] ?? [])
                    ->filter(fn ($m) => !$m['advertencia'] && $m['numeroArchivo'] !== null)
                    ->pluck('numeroArchivo')
                    ->unique();

                if ($archivosConError->isEmpty()) {
                    // Sin detalle granular real: rechazar todo el paquete,
                    // como antes.
                    $facturas->each(fn (Factura $f) => $f->update(['facsiatest' => Factura::SIAT_RECHAZADA]));
                    break;
                }

                $facturas->each(function (Factura $f) use ($archivosConError, $notificacion) {
                    // Sin facnumeroarchivo (dato no registrado) se rechaza
                    // por seguridad — no hay forma de confirmar que este
                    // documento puntual no fue uno de los marcados.
                    if ($f->facnumeroarchivo === null || $archivosConError->contains($f->facnumeroarchivo)) {
                        $f->update(['facsiatest' => Factura::SIAT_RECHAZADA]);
                        return;
                    }
                    $f->update(['facsiatest' => Factura::SIAT_ACEPTADA]);
                    $notificacion->notificarSiCorresponde($f);
                });
                break;

            // 'observed' / 'processing': se deja igual, hay que reintentar la validación después.
        }
    }

    /**
     * Empaqueta y envía las facturas CAFC (transcritas de talones
     * preimpresos) acopladas a un evento YA REGISTRADO ante el SIAT — el
     * paquete CAFC referencia el mismo codigoEvento, pero es un envío
     * separado del offline normal porque el campo `cafc` vive a nivel de
     * paquete completo, no por factura (ver SiatService::enviarPaqueteOffline).
     *
     * Se puede llamar varias veces mientras se van transcribiendo más
     * facturas dentro de la ventana de 72h: cada corrida arma un
     * PaqueteCafc NUEVO solo con las que todavía no se enviaron
     * (facsiatest = 'offline'). Un evento puede terminar con varios
     * PaqueteCafc — cada uno se valida por separado (ver validarCafc()).
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
            ->orderBy('facid')
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
        $numeroArchivo = 0;
        foreach ($facturas as $factura) {
            if (!$factura->facxmlpath || !Storage::disk('local')->exists($factura->facxmlpath)) {
                Log::error("Contingencia CAFC: factura #{$factura->facnro} sin XML guardado, se omite del paquete.");
                continue;
            }
            $numeroArchivo++;
            $paquete[] = ['cuf' => $factura->faccuf, 'xml' => gzdecode(Storage::disk('local')->get($factura->facxmlpath))];
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

        $paqueteCafc = PaqueteCafc::create([
            'eveid'       => $evento->eveid,
            'paqcodrec'   => $envio['codigoRecepcion'],
            'paqcantidad' => $armado['cantidad'],
        ]);

        $numeroArchivo = 0;
        foreach ($facturas as $factura) {
            if (!$factura->facxmlpath || !Storage::disk('local')->exists($factura->facxmlpath)) {
                continue; // ya logueado arriba; no formó parte del paquete enviado.
            }
            $numeroArchivo++;
            $factura->update([
                'facsiatest'       => Factura::SIAT_EMPAQUETADA,
                'facnumeroarchivo' => $numeroArchivo,
                'facpaquetecafcid' => $paqueteCafc->paqid,
            ]);
        }

        Log::info("Contingencia CAFC emisor {$emisor->eminit}: evento {$evento->eveid}, "
            . "paquete CAFC #{$paqueteCafc->paqid} ({$envio['codigoRecepcion']}) enviado con {$armado['cantidad']} factura(s).");

        return true;
    }

    /**
     * Consulta el resultado de validación de un paquete CAFC puntual
     * (separado del paquete offline normal y de los demás paquetes CAFC del
     * mismo evento, ver enviarPaqueteCafc()).
     */
    public function validarCafc(PaqueteCafc $paqueteCafc, Emisor $emisor): string
    {
        $evento = $paqueteCafc->evento;

        $siat = new SiatService($emisor, $evento->evesuc, $evento->evepdv);
        $cuis = $siat->getActiveCuis();
        $cufd = $siat->getActiveCufd();

        if (!$cuis || !$cufd) {
            throw new \Exception('Sin CUIS/CUFD vigente para validar el paquete CAFC.');
        }

        $resultado = $siat->validarPaqueteOffline($cuis, $cufd->scovalor, $paqueteCafc->paqcodrec);

        $facturas = $paqueteCafc->facturas()
            ->where('facsiatest', Factura::SIAT_EMPAQUETADA)
            ->get();

        $this->aplicarResultadoValidacion($facturas, $resultado, new NotificacionFacturaService());

        // 'observed'/'processing' se dejan sin resolver — se reintenta
        // después. Solo un estado final marca el paquete como resuelto.
        if (in_array($resultado['status'], ['accepted', 'rejected'], true)) {
            $paqueteCafc->update(['paqestado' => $resultado['status']]);
        }

        return $resultado['status'];
    }
}

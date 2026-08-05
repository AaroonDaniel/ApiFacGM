<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventosSignificativo extends Model
{
    protected $table = 'eventos_significativos';
    protected $primaryKey = 'eveid';

    /**
     * Motivos de Evento Significativo — códigos verificados en vivo contra
     * `sincronizarParametricaEventosSignificativos` (piloto, 2026-08-04).
     * Antes del 3 en adelante estaban mal (ej. COD_CORTE_ENERGIA valía 3
     * cuando el código real del SIN es 7) — no se habían detectado porque
     * en el código solo se usa COD_SIN_INACCESIBLE, que sí coincidía.
     */
    public const COD_CORTE_INTERNET = 1;          // CORTE DEL SERVICIO DE INTERNET
    public const COD_SIN_INACCESIBLE = 2;         // INACCESIBILIDAD AL SERVICIO WEB DE LA ADMINISTRACIÓN TRIBUTARIA
    public const COD_ZONA_SIN_INTERNET = 3;       // INGRESO A ZONAS SIN INTERNET POR DESPLIEGUE DE PUNTO DE VENTA
    public const COD_VENTA_SIN_INTERNET = 4;      // VENTA EN LUGARES SIN INTERNET
    public const COD_FALLA_SOFTWARE = 5;          // VIRUS INFORMÁTICO O FALLA DE SOFTWARE
    public const COD_CAMBIO_INFRA = 6;            // CAMBIO DE INFRAESTRUCTURA DE SISTEMA O FALLA DE HARDWARE
    public const COD_CORTE_ENERGIA = 7;           // CORTE DE SUMINISTRO DE ENERGÍA ELÉCTRICA

    public const ESTADO_ACTIVO = 'activo';
    public const ESTADO_CERRADO = 'cerrado';

    /**
     * La extensión de vigencia del CUFD para facturar offline ante una
     * caída solo cubre 72h — pasado ese plazo, el CUFD usado ya no es
     * válido para el SIAT y hay que pasar a facturación manual (CAFC).
     */
    public const LIMITE_HORAS_OFFLINE = 72;

    /**
     * Una vez cerrado el evento (registrado ante el SIAT), hay 48h para
     * terminar de enviar el paquete offline.
     */
    public const LIMITE_HORAS_ENVIO_PAQUETE = 48;

    /**
     * El paquete de facturas CAFC (transcritas de talón preimpreso) tiene
     * una ventana más amplia que el offline normal — 72h desde que se
     * registró el evento — porque de por medio hay trabajo manual de
     * transcripción.
     */
    public const LIMITE_HORAS_ENVIO_CAFC = 72;

    protected $fillable = [
        'emiid',
        'evesuc',
        'evepdv',
        'evecod',
        'evedesc',
        'eveini',
        'evefin',
        'evecufd',
        'evecufdctrl',
        'evecodrec',
        'evecodrecpaq',
        'eveest',
        'eveusr',
        'evereg',
    ];

    protected $casts = [
        'evesuc' => 'integer',
        'evepdv' => 'integer',
        'eveini' => 'datetime',
        'evefin' => 'datetime',
        'evereg' => 'datetime',
        'evecod' => 'integer'
    ];

    public function emisor(): BelongsTo
    {
        return $this->belongsTo(Emisor::class, 'emiid', 'emiid');
    }

    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class, 'faceveid', 'eveid');
    }

    /**
     * Un evento puede tener varios paquetes CAFC (la transcripción manual
     * puede llegar en tandas dentro de la ventana de 72h) — ver
     * ContingenciaService::enviarPaqueteCafc().
     */
    public function paquetesCafc(): HasMany
    {
        return $this->hasMany(PaqueteCafc::class, 'eveid', 'eveid');
    }

    /**
     * Evento activo de un emisor PARA UN PUNTO DE VENTA ESPECÍFICO — cada
     * sucursal/PDV tiene su propia dosificación (y su propio CUFD), así
     * que una caída en uno no debe mezclarse con la de otro.
     */
    public function scopeActivoDe($query, int $emiid, int $suc = 0, int $pdv = 0)
    {
        return $query->where('emiid', $emiid)
            ->where('evesuc', $suc)
            ->where('evepdv', $pdv)
            ->where('eveest', self::ESTADO_ACTIVO)
            ->latest('eveini');
    }

    /**
     * Eventos de un emisor+punto de venta que todavía no terminaron de
     * reconciliarse (registrados o no ante el SIAT, pero sin paquete
     * enviado con éxito). A diferencia de scopeActivoDe(), incluye los ya
     * cerrados localmente cuyo envío del paquete falló y quedó pendiente
     * de reintento.
     */
    public function scopePendienteDeEnvio($query, int $emiid, int $suc = 0, int $pdv = 0)
    {
        return $query->where('emiid', $emiid)
            ->where('evesuc', $suc)
            ->where('evepdv', $pdv)
            ->whereIn('eveest', [self::ESTADO_ACTIVO, self::ESTADO_CERRADO])
            ->whereNull('evecodrecpaq')
            ->latest('eveini');
    }

    /**
     * True si ya pasaron más de 72h desde que empezó la contingencia
     * (eveini) — la extensión offline del CUFD ya no cubre este evento y no
     * se debe seguir emitiendo con él.
     */
    public function excedioLimiteOffline(): bool
    {
        return $this->eveini->diffInHours(now()) >= self::LIMITE_HORAS_OFFLINE;
    }

    /**
     * True si el evento ya se cerró/registró (evefin) hace más de 48h y
     * todavía no se terminó de enviar el paquete offline — el registro del
     * evento ocurre justo cuando se detecta la reconexión (ver
     * ContingenciaService::reconciliar()), así que evefin es un proxy
     * razonable del momento en que arrancó el plazo de 48h.
     */
    public function excedioPlazoEnvioPaquete(): bool
    {
        return $this->evefin !== null
            && $this->evefin->diffInHours(now()) >= self::LIMITE_HORAS_ENVIO_PAQUETE;
    }

    /**
     * True si ya pasaron más de 72h desde que se cerró/registró el evento
     * (evefin) y todavía no se terminó de enviar el paquete CAFC. Mismo
     * proxy que excedioPlazoEnvioPaquete(), con la ventana más amplia que
     * le corresponde al CAFC.
     */
    public function excedioPlazoEnvioCafc(): bool
    {
        return $this->evefin !== null
            && $this->evefin->diffInHours(now()) >= self::LIMITE_HORAS_ENVIO_CAFC;
    }
}

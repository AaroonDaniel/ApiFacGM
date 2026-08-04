<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EventosSignificativo extends Model
{
    protected $table = 'eventos_significativos';
    protected $primaryKey = 'eveid';

    public const COD_CORTE_INTERNET = 1;
    public const COD_SIN_INACCESIBLE = 2;
    public const COD_CORTE_ENERGIA = 3;
    public const COD_FALLA_SOFTWARE = 4;
    public const COD_CAMBIO_INFRA = 5;
    public const COD_FALLA_COMMS = 6;
    public const COD_FUERZA_MAYOR = 7;

    public const ESTADO_ACTIVO = 'activo';
    public const ESTADO_CERRADO = 'cerrado';
    public const ESTADO_REGISTRADO = 'registrado';
    public const ESTADO_FALLIDO = 'fallido';

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

    public function scopeDisponibleParaAcoplar($query)
    {
        return $query->whereIn('eveest', [self::ESTADO_ACTIVO, self::ESTADO_CERRADO])->whereNull('evecodrec');
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
}

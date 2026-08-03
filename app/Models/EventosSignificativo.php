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
}

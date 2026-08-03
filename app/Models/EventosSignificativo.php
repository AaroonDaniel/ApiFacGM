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

    public function scopeActivoDe($query, int $emiid)
    {
        return $query->where('emiid', $emiid)->where('eveest', self::ESTADO_ACTIVO)->latest('eveini');
    }
}

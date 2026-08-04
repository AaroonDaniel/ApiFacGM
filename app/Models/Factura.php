<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Factura extends Model
{
    protected $table = 'facturas';
    protected $primaryKey = 'facid';

    public const ESTADO_VIGENTE = 'vigente';
    public const ESTADO_ANULADA = 'anulada';

    public const SIAT_PENDIENTE = 'pendiente';
    public const SIAT_ACEPTADA = 'aceptada';
    public const SIAT_RECHAZADA = 'rechazada';
    public const SIAT_OFFLINE = 'offline';
    public const SIAT_ERROR = 'error';
    public const SIAT_EMPAQUETADA = 'empaquetada'; // offline, paquete ya enviado, esperando validación

    protected $fillable = [
        'emiid',
        'facsuc',
        'facpdv',
        'facnro',
        'faccuf',
        'faccufd',
        'facxmlhash',
        'faccafc',
        'facfch',
        'fachora',
        'facnomrazon',
        'facnumdoc',
        'factipodoc',
        'faccompl',
        'facmetpag',
        'facnrotarj',
        'facmonto',
        'facmontoiva',
        'facdesc',
        'faccodrec',
        'facest',
        'facsiatest',
        'facsisorig',
        'facrefext',
        'faceveid',
        'facxmlpath',
        'facmotanul',
        'facfchanul'
    ];

    protected $casts = [
        'facsuc'      => 'integer',
        'facpdv'      => 'integer',
        'facfch'      => 'date',
        'fachora'     => 'datetime',
        'facfchanul'  => 'datetime',
        'facmonto'    => 'decimal:2',
        'facmontoiva' => 'decimal:2',
        'facdesc'     => 'decimal:2',
        'factipodoc'  => 'integer',
        'facmetpag'   => 'integer',
        'facmotanul'  => 'integer',
    ];

    public function emisor(): BelongsTo
    {
        return $this->belongsTo(Emisor::class, 'emiid', 'emiid');
    }

    public function detalles(): HasMany
    {
        return $this->hasMany(FacturaDetalle::class, 'facid', 'facid');
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(EventosSignificativo::class, 'faceveid', 'eveid'); // era 'eveid','eveid'
    }

    public function scopeHuerfano($query)
    {
        return $query->where('facsiatest', self::SIAT_OFFLINE)->whereNull('faceveid');
    }
}

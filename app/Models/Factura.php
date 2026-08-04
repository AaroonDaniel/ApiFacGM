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
        'facemail',
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

    /**
     * URL del validador público del SIN para el QR de la representación
     * gráfica. Dominio distinto al de los servicios SOAP (config
     * 'siat.ambiente_urls') — ver config('siat.qr_urls').
     */
    public function qrUrl(): string
    {
        $urls = config('siat.qr_urls');
        $base = $urls[$this->emisor->emiamb] ?? $urls[Emisor::AMBIENTE_PILOTO];

        return $base . '?' . http_build_query([
            'nit'    => $this->emisor->eminit,
            'cuf'    => $this->faccuf,
            'numero' => $this->facnro,
            't'      => 1, // rollo/térmica por defecto
        ]);
    }

    /**
     * Leyendas obligatorias de la representación gráfica.
     */
    public function leyendas(): array
    {
        return [
            config('siat.leyenda_defecto'),
            'Esta factura contribuye al desarrollo del país. El uso ilícito de este documento será pasible a sanción de acuerdo a Ley.',
            $this->facsiatest === self::SIAT_OFFLINE
                ? 'FACTURA EMITIDA EN CONTINGENCIA'
                : 'FACTURA EMITIDA EN LÍNEA',
        ];
    }
}

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
        'facleyenda',
        'facnumeroarchivo',
        'facpaquetecafcid',
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
        'faccodmon',
        'factipocambio',
        'facmontogiftcard',
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
        'faccodmon'       => 'integer',
        'factipocambio'   => 'decimal:5',
        'facmontogiftcard' => 'decimal:2',
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

    public function paqueteCafc(): BelongsTo
    {
        return $this->belongsTo(PaqueteCafc::class, 'facpaquetecafcid', 'paqid');
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
     * Leyendas obligatorias de la representación gráfica — 3 categorías
     * distintas exigidas por la RND, no una sola:
     *  1. Consumidor (Ley N° 453), rotación aleatoria — la que realmente
     *     quedó en el nodo <leyenda> del XML enviado (facleyenda, ver
     *     SiatXmlBuilder::resolverLeyenda()). No se vuelve a elegir al azar
     *     acá: debe ser la misma que ya está en el documento fiscal. Sin
     *     facleyenda (factura rechazada antes de construir el XML), cae al
     *     texto fijo de config.
     *  2. Institucional del SIN, texto fijo.
     *  3. Técnica de emisión (en línea / fuera de línea), texto fijo —
     *     depende de si la factura terminó offline o no.
     */
    public function leyendas(): array
    {
        return [
            $this->facleyenda ?? config('siat.leyenda_defecto'),
            'Esta factura contribuye al desarrollo del país, el uso ilícito será sancionado penalmente de acuerdo a Ley.',
            $this->facsiatest === self::SIAT_OFFLINE
                ? 'Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido fuera de línea, '
                    . 'verifique su envío con su proveedor o en la página web www.impuestos.gob.bo'
                : 'Este documento es la Representación Gráfica de un Documento Fiscal Digital emitido en una modalidad '
                    . 'de facturación en línea',
        ];
    }
}

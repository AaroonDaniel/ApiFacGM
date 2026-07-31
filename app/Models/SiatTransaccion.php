<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiatTransaccion extends Model
{
    protected $table = 'siat_transacciones';
    protected $primaryKey = 'stxid';

    public const ESTADO_EXITO   = 'exito';
    public const ESTADO_RECHAZO = 'rechazo';
    public const ESTADO_OFFLINE = 'offline';

    protected $fillable = [
        'emiid', 'stxfacid', 'stxserv', 'stxpet', 'stxresp', 'stxest', 'stxfch',
    ];

    protected $casts = ['stxfch' => 'datetime'];

    public function emisor(): BelongsTo
    {
        return $this->belongsTo(Emisor::class, 'emiid', 'emiid');
    }

    public function factura(): BelongsTo
    {
        return $this->belongsTo(Factura::class, 'stxfacid', 'facid');
    }
}
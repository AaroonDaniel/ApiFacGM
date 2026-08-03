<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PuntoVenta extends Model
{
    protected $table = 'puntos_venta';
    protected $primaryKey = 'pvid';

    protected $fillable = [
        'emiid',
        'pvsuc',
        'pvpdv',
        'pvest',
    ];

    protected $casts = [
        'pvsuc' => 'integer',
        'pvpdv' => 'integer',
        'pvest' => 'boolean',
    ];

    public function emisor(): BelongsTo
    {
        return $this->belongsTo(Emisor::class, 'emiid', 'emiid');
    }
}

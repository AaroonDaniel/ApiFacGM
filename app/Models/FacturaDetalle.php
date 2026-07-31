<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FacturaDetalle extends Model
{
    protected $table = 'factura_detalles';
    protected $primaryKey = 'facdetid';

    protected $fillable = [
        'facid',
        'facdetacteco',
        'facdetprodsin',
        'facdetcodprod',
        'facdetdesc',
        'facdetcnt',
        'facdetunimed',
        'facdetprc',
        'facdetdscto',
        'facdetsub'
    ];

    protected $casts = [
        'facdetcnt' => 'decimal:2',
        'facdetprc' => 'decimal:2',
        'facdetdscto' => 'decimal:2',
        'facdetsub' => 'decimal:2',
        'facdetunimed' => 'integer'
    ];

    public function factura(): BelongsTo {
        return $this->belongsTo(Factura::class, 'facid', 'facid');
    }
}

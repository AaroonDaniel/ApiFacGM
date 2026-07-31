<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductoServicio extends Model
{
    protected $table = 'productos_servicios';
    protected $primaryKey = 'proid';

    protected $fillable = ['emiid', 'proactcod', 'procodsin', 'prodesc'];

    public function emisor(): BelongsTo
    {
        return $this->belongsTo(Emisor::class, 'emiid', 'emiid');
    }
}
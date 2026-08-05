<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaqueteCafc extends Model
{
    protected $table = 'paquetes_cafc';
    protected $primaryKey = 'paqid';

    protected $fillable = [
        'eveid',
        'paqcodrec',
        'paqcantidad',
        'paqestado',
    ];

    protected $casts = [
        'paqcantidad' => 'integer',
    ];

    public function evento(): BelongsTo
    {
        return $this->belongsTo(EventosSignificativo::class, 'eveid', 'eveid');
    }

    public function facturas(): HasMany
    {
        return $this->hasMany(Factura::class, 'facpaquetecafcid', 'paqid');
    }

    public function scopePendienteDeValidar($query)
    {
        return $query->whereNull('paqestado');
    }
}

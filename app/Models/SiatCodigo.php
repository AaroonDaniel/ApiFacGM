<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiatCodigo extends Model
{

    protected $table = 'siat_codigos';
    protected $primaryKey = 'scoid';

    public const TIPO_CUIS = 'cuis';
    public const TIPO_CUFD = 'cufd';

    protected $fillable = [
        'scoemi' => 'datetime',
        'scoven' => 'datetime',
        'scoest' => 'boolean',
        'scoamb' => 'integer',
        'scosuc' => 'integer',
        'scopdv' => 'integer'
    ];

    public function emisor(): BelongsTo {
        return $this->belongsTo(Emisor::class, 'emiid', 'emiid');
    }

    public function scopeVigente(Builder $query): Builder{
        return $query->where('scoest', true)->where('scoven', '>', now());
    }
    
}

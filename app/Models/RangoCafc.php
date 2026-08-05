<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RangoCafc extends Model
{
    protected $table = 'rangos_cafc';
    protected $primaryKey = 'rcfid';

    protected $fillable = [
        'emiid',
        'rcfsuc',
        'rcfpdv',
        'rcfcafc',
        'rcfdesde',
        'rcfhasta',
        'rcfultimo',
        'rcflimite',
        'rcfest',
    ];

    protected $casts = [
        'rcfsuc'    => 'integer',
        'rcfpdv'    => 'integer',
        'rcfdesde'  => 'integer',
        'rcfhasta'  => 'integer',
        'rcfultimo' => 'integer',
        'rcflimite' => 'datetime',
        'rcfest'    => 'boolean',
    ];

    public function emisor(): BelongsTo
    {
        return $this->belongsTo(Emisor::class, 'emiid', 'emiid');
    }
}

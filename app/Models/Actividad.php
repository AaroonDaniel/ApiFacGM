<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Actividad extends Model
{
    protected $table = 'actividades';
    protected $primaryKey = 'actid';

    protected $fillable = ['emiid', 'actcod', 'actdesc', 'actest'];

    protected $casts = ['actest' => 'boolean'];

    public function emisor(): BelongsTo
    {
        return $this->belongsTo(Emisor::class, 'emiid', 'emiid');
    }
}
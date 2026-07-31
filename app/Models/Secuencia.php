<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Secuencia extends Model
{
    protected $table = 'secuencias';
    protected $primaryKey = 'secid';

    protected $fillable = [
        'emiid',
        'secsuc',
        'secpdv',
        'sectipodoc',
        'secultimo'
    ];

    protected $casts = [
        'secsuc' => 'interger',
        'secpdv' => 'integer',
        'sectipodoc' => 'integer',
        'secultimo' => 'integer'
    ];

    public function emisor(): BelongsTo {
        return $this->belongsTo(Emisor::class, 'emiid', 'emiid');
    }
}

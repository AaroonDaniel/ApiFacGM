<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MotivoEventoSignificativo extends Model
{
    protected $table = 'motivos_evento_significativo';
    protected $primaryKey = 'mevid';

    protected $fillable = ['mevcod', 'mevdesc'];

    protected $casts = ['mevcod' => 'integer'];
}

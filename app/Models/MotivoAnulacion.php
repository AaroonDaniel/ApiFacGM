<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MotivoAnulacion extends Model
{
    protected $table = 'motivos_anulacion';
    protected $primaryKey = 'moaid';

    protected $fillable = ['moacod', 'moadesc'];

    protected $casts = ['moacod' => 'integer'];
}
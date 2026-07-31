<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UnidadMedida extends Model
{
    protected $table = 'unidades_medida';
    protected $primaryKey = 'uniid';

    protected $fillable = ['unicod', 'unidesc'];

    protected $casts = ['unicod' => 'integer'];
}
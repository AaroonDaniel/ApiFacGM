<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MetodoPago extends Model
{
    protected $table = 'metodos_pago';
    protected $primaryKey = 'mpaid';

    protected $fillable = ['mpacod', 'mpadesc'];

    protected $casts = ['mpacod' => 'integer'];
}
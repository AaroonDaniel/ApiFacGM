<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Moneda extends Model
{
    protected $table = 'monedas';
    protected $primaryKey = 'monid';

    protected $fillable = ['moncod', 'mondesc'];

    protected $casts = ['moncod' => 'integer'];
}

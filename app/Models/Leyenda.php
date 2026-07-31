<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Leyenda extends Model
{
    protected $table = 'leyendas';
    protected $primaryKey = 'leyid';

    protected $fillable = ['leyactcod', 'leydesc'];
}
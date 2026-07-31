<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoDocumentoIdentidad extends Model
{
    protected $table = 'tipos_documento_identidad';
    protected $primaryKey = 'tdiid';

    protected $fillable = ['tdicod', 'tdidesc'];

    protected $casts = ['tdicod' => 'integer'];
}
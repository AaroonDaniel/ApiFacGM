<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoDocumentoSector extends Model
{
    protected $table = 'tipos_documento_sector';
    protected $primaryKey = 'tdsid';

    protected $fillable = ['tdscod', 'tdsdesc'];

    protected $casts = ['tdscod' => 'integer'];
}

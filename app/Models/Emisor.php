<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Emisor extends Model
{
    protected $table = 'emisores';
    protected $primaryKey = 'emiid';

    public const MODALIDAD_ELECTRONICA = 1;
    public const MODALIDAD_COMPUTARIZADA = 2;

    public const AMBIENTE_PRODUCCION = 1;
    public const AMBIENTE_PILOTO = 2;

    protected $fillable = [
        'sisid',
        'eminit',
        'emirazsoc',
        'emimun',
        'emidir',
        'emitel',
        'emisis',
        'emisuc',
        'emipdv', 
        'emitoken',
        'emimod',
        'emiamb',
        'emiest'
    ];

    protected $casts = [
        'emitoken' => 'encrypted',
        'emisis' => 'integer',
        'emisuc' => 'integer',
        'emipdv' => 'integer',
        'emimod' => 'integer',
        'emiamb' => 'integer',
        'emiest' => 'integer',
    ];

    protected $hidden = ['emitoken'];

    public function sistema(): BelongsTo {
        return $this->BelongsTo(SistemaCliente::class, 'sisid', 'sisid');
    }

    public function codigos(): HasMany {
        return $this->hasMany(SiatCodigo::class, 'emiid', 'emiid');
    }

    public function secuencias(): HasMany {
        return $this->hasMany(Secuencia::class, 'emiid', 'emiid');
    }

    public function facturas(): HasMany {
        return $this->hasMany(Factura::class, 'emiid', 'emiid');
    }

    public function actividades(): hasMany {
        return $this->hasMany(Actividad::class, 'emiid', 'emiid');
    }


}

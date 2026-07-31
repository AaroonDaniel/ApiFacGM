<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SistemaCliente extends Model
{
    protected $table = 'sistemas_cliente';
    protected $primaryKey = 'sisid';

    protected $fillable = [
        'siscod',
        'sisnom',
        'sisapikey',
        'sisest'
    ];

    protected $casts = [
        'sisest' => 'boolean'
    ];

    protected $hidden = [
        'sisapikey'
    ];

    public function emisores(): HasMany
    {
        return $this->hasMany(Emisor::class, 'sisid', 'sisid');
    }

}

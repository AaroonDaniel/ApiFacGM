<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Moneda extends Model
{
    // Confirmado contra el catálogo real sincronizado (tinker): moncod=1 =
    // BOLIVIANO. Es la única moneda que realmente circula en producción;
    // el resto de códigos solo importan para los casos de prueba de moneda
    // extranjera que exige la certificación Fase 1 del SIN.
    public const BOLIVIANO = 1;

    protected $table = 'monedas';
    protected $primaryKey = 'monid';

    protected $fillable = ['moncod', 'mondesc'];

    protected $casts = ['moncod' => 'integer'];
}

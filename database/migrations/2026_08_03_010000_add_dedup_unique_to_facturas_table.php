<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evita facturas duplicadas cuando un sistema cliente reintenta la misma
 * petición (ej. por timeout de red). NULL en facrefext no colisiona con
 * otros NULL (comportamiento estándar de índices únicos en Postgres),
 * así que no afecta a las facturas sin referencia_externa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->unique(['emiid', 'facsisorig', 'facrefext'], 'facturas_dedup_unico');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropUnique('facturas_dedup_unico');
        });
    }
};

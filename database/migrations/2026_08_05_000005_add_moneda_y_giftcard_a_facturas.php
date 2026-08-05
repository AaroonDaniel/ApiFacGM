<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moneda extranjera y pago con Gift Card: aunque el uso real de este
 * sistema es mono-moneda (bolivianos) y sin gift card, el Plan de Pruebas
 * de certificación del SIN (Fase 1) asigna casos de prueba obligatorios
 * como "emitir en dólares" o "pago parcial con gift card" — sin estas
 * fórmulas implementadas, el XML se rechaza por inconsistencia
 * aritmética y el software no avanza a la Fase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->integer('faccodmon')->default(1)->after('facdesc'); // 1 = BOLIVIANO (catálogo real)
            $table->decimal('factipocambio', 10, 5)->default(1)->after('faccodmon');
            $table->decimal('facmontogiftcard', 12, 2)->nullable()->after('factipocambio');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn(['faccodmon', 'factipocambio', 'facmontogiftcard']);
        });
    }
};

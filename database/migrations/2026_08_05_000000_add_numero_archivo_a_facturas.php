<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posición (1-indexado) de cada factura dentro del .tar del paquete
 * offline/CAFC que la incluyó. El SIAT identifica documentos específicos
 * de un paquete por "numeroArchivo" en mensajesList al validar — sin
 * registrar qué factura fue cada posición, no hay forma confiable de
 * mapear ese mensaje de vuelta a una factura concreta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->unsignedInteger('facnumeroarchivo')->nullable()->after('faccafc');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn('facnumeroarchivo');
        });
    }
};

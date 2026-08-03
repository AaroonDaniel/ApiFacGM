<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La numeración de facturas es independiente POR DOSIFICACIÓN (sucursal +
 * punto de venta), no solo por emisor: la sucursal 1 puede tener su propia
 * factura #1 sin chocar con la #1 de la sucursal 0. La restricción única
 * original (emiid, facnro) no contemplaba esto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropUnique('facturas_emisor_numero_unico');
            $table->unique(['emiid', 'facsuc', 'facpdv', 'facnro'], 'facturas_dosificacion_numero_unica');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropUnique('facturas_dosificacion_numero_unica');
            $table->unique(['emiid', 'facnro'], 'facturas_emisor_numero_unico');
        });
    }
};

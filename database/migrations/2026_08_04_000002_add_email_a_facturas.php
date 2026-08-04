<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correo del comprador (congelado, igual que el resto de sus datos) — para
 * el envío automático del XML al aceptarse la factura, si el sistema
 * cliente lo mandó. Opcional: sin este campo no hay a quién mandarle nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->string('facemail', 255)->nullable()->after('faccompl');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn('facemail');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            // Huella digital SHA-256 del XML enviado al SIAT (Modalidad
            // Computarizada). Se calcula al emitir pero antes no se
            // persistía; hace falta para exponerla en la representación
            // gráfica de la factura.
            $table->string('facxmlhash', 64)->nullable()->after('faccufd');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn('facxmlhash');
        });
    }
};

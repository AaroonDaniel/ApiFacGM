<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soporte para Facturación Manual por Contingencia (CAFC): facturas
 * transcritas de talones físicos preimpresos, usadas cuando el corte supera
 * las 72h de extensión offline del CUFD. Se envían en un paquete SEPARADO
 * del offline normal (el campo `cafc` del WSDL vive a nivel de paquete, no
 * por factura individual), así que el evento necesita su propio código de
 * recepción de paquete para poder validarlo de forma independiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->string('faccafc', 50)->nullable()->after('facxmlhash');
        });

        Schema::table('eventos_significativos', function (Blueprint $table) {
            $table->string('evecodrecpaqcafc', 100)->nullable()->after('evecodrecpaq');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn('faccafc');
        });

        Schema::table('eventos_significativos', function (Blueprint $table) {
            $table->dropColumn('evecodrecpaqcafc');
        });
    }
};

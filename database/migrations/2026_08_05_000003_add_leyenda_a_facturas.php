<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La leyenda real usada en el XML enviado al SIAT (ver
 * SiatXmlBuilder::resolverLeyenda()) — se persiste para que la
 * representación gráfica muestre la MISMA leyenda que quedó en el
 * documento fiscal, no una nueva elegida al azar cada vez que se consulta
 * la factura. El catálogo real tiene varias leyendas por actividad
 * económica que deben alternarse, pero una vez emitida la factura, la
 * elegida ya es un hecho fijo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->text('facleyenda')->nullable()->after('faccafc');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn('facleyenda');
        });
    }
};

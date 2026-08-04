<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3 catálogos globales del SIAT que faltaban sincronizar: tipos de moneda,
 * tipos de documento sector (qué Sector/Documento tiene habilitado emitir
 * el NIT), y motivos de Evento Significativo (el catálogo de motivos —
 * distinto de `eventos_significativos`, que son los eventos ya ocurridos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monedas', function (Blueprint $table) {
            $table->bigIncrements('monid');
            $table->integer('moncod')->unique();
            $table->string('mondesc', 100);
            $table->timestamps();
        });

        Schema::create('tipos_documento_sector', function (Blueprint $table) {
            $table->bigIncrements('tdsid');
            $table->integer('tdscod')->unique();
            $table->string('tdsdesc', 150);
            $table->timestamps();
        });

        Schema::create('motivos_evento_significativo', function (Blueprint $table) {
            $table->bigIncrements('mevid');
            $table->integer('mevcod')->unique();
            $table->string('mevdesc', 150);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monedas');
        Schema::dropIfExists('tipos_documento_sector');
        Schema::dropIfExists('motivos_evento_significativo');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rangos CAFC autorizados por el SIN — trámite puramente administrativo
 * (portal del SIAT, sin operación SOAP para esto en ningún WSDL real). Sin
 * validar esto localmente, un error de tipeo al transcribir (ej. un
 * número fuera del rango físico autorizado del talonario) no se detecta
 * hasta que el SIAT rechaza el paquete offline completo — mucho después,
 * y arrastrando a las demás facturas del mismo envío.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rangos_cafc', function (Blueprint $table) {
            $table->bigIncrements('rcfid');
            $table->foreignId('emiid')->constrained('emisores', 'emiid')->cascadeOnDelete();
            $table->integer('rcfsuc')->default(0);
            $table->integer('rcfpdv')->default(0);
            $table->string('rcfcafc', 50);
            $table->integer('rcfdesde');
            $table->integer('rcfhasta');
            $table->integer('rcfultimo')->nullable(); // control local opcional, informativo
            $table->dateTime('rcflimite'); // fecha límite para emitir con este CAFC
            $table->boolean('rcfest')->default(true);
            $table->timestamps();

            $table->unique(['emiid', 'rcfsuc', 'rcfpdv', 'rcfcafc'], 'rangos_cafc_dosificacion_codigo_unico');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rangos_cafc');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('eventos_significativos', function (Blueprint $table) {
            $table->bigIncrements('eveid');

            $table->foreignId('emiid')
                ->constrained('emisores', 'emiid')->cascadeOnDelete();

            $table->smallInteger('evecod');            // 1..7 (motivo)
            $table->string('evedesc', 500);
            $table->dateTime('eveini');                // inicio
            $table->dateTime('evefin')->nullable();    // fin

            $table->string('evecufd', 255);            // CUFD del evento (usado offline)
            $table->string('evecufdctrl', 100);        // control de ese CUFD

            $table->string('evecodrec', 100)->nullable();     // recepción del EVENTO
            $table->string('evecodrecpaq', 100)->nullable();  // recepción del PAQUETE

            $table->string('eveest', 15)->default('activo');
            $table->unsignedBigInteger('eveusr')->nullable();
            $table->dateTime('evereg')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eventos_significativos');
    }
};

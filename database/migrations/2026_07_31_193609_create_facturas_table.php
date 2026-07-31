<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturas', function (Blueprint $table) {
            $table->bigIncrements('facid');

            $table->foreignId('emiid')
                ->constrained('emisores', 'emiid')->restrictOnDelete();

            $table->integer('facnro');                       // correlativo (de secuencias)
            $table->string('faccuf', 100)->nullable();       // CUF
            $table->string('faccufd', 255)->nullable();      // CUFD usado (auditoría)

            $table->date('facfch');
            $table->dateTime('fachora');

            // --- Comprador (congelado) ---
            $table->string('facnomrazon', 255);
            $table->string('facnumdoc', 30);
            $table->smallInteger('factipodoc')->default(1);
            $table->string('faccompl', 5)->nullable();

            // --- Pago ---
            $table->smallInteger('facmetpag');
            $table->string('facnrotarj', 20)->nullable();

            // --- Montos ---
            $table->decimal('facmonto', 12, 2);
            $table->decimal('facmontoiva', 12, 2);
            $table->decimal('facdesc', 12, 2)->default(0);

            $table->string('faccodrec', 100)->nullable();

            // --- Estados ---
            $table->string('facest', 15)->default('vigente');
            $table->string('facsiatest', 15)->default('pendiente');

            // --- Origen (des-hotelización) ---
            $table->string('facsisorig', 50);
            $table->string('facrefext', 100)->nullable();

            // --- Contingencia ---
            $table->foreignId('faceveid')->nullable()
                ->constrained('eventos_significativos', 'eveid')->nullOnDelete();
            $table->string('facxmlpath', 255)->nullable();

            // --- Anulación ---
            $table->smallInteger('facmotanul')->nullable();
            $table->dateTime('facfchanul')->nullable();

            $table->timestamps();

            $table->unique(['emiid', 'facnro'], 'facturas_emisor_numero_unico');
            $table->index('facsiatest');
            $table->index(['facsisorig', 'facrefext']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facturas');
    }
};
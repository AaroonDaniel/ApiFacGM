<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un evento puede necesitar MÁS de un paquete CAFC: la transcripción de
 * talones preimpresos es manual y puede llegar en tandas dentro de la
 * ventana de 72h, y ContingenciaService::enviarPaqueteCafc() se puede
 * llamar varias veces (cada corrida empaqueta solo lo pendiente). El
 * diseño anterior (una sola columna eventos_significativos.evecodrecpaqcafc)
 * solo alcanzaba para un paquete: un segundo envío pisaba el codigoRecepcion
 * del primero, y ese primer paquete quedaba sin forma de validarse nunca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paquetes_cafc', function (Blueprint $table) {
            $table->bigIncrements('paqid');
            $table->foreignId('eveid')->constrained('eventos_significativos', 'eveid')->cascadeOnDelete();
            $table->string('paqcodrec', 100);
            $table->integer('paqcantidad');
            // null = enviado, pendiente de validar. 'accepted'/'rejected' una
            // vez que el SIAT confirma un estado final ('observed'/'processing'
            // se reintentan después, no se consideran resueltos).
            $table->string('paqestado', 20)->nullable();
            $table->timestamps();
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->foreignId('facpaquetecafcid')->nullable()
                ->after('facnumeroarchivo')
                ->constrained('paquetes_cafc', 'paqid')->nullOnDelete();
        });

        Schema::table('eventos_significativos', function (Blueprint $table) {
            $table->dropColumn('evecodrecpaqcafc');
        });
    }

    public function down(): void
    {
        Schema::table('eventos_significativos', function (Blueprint $table) {
            $table->string('evecodrecpaqcafc', 100)->nullable()->after('evecodrecpaq');
        });

        Schema::table('facturas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('facpaquetecafcid');
        });

        Schema::dropIfExists('paquetes_cafc');
    }
};

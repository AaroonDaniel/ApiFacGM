<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de sucursales/puntos de venta válidos por emisor. Un mismo NIT
 * (fila de `emisores`, que guarda el token compartido de todo el sistema)
 * puede tener varias dosificaciones — cada una es una fila acá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('puntos_venta', function (Blueprint $table) {
            $table->bigIncrements('pvid');
            $table->foreignId('emiid')->constrained('emisores', 'emiid')->cascadeOnDelete();
            $table->integer('pvsuc')->default(0);
            $table->integer('pvpdv')->default(0);
            $table->boolean('pvest')->default(true);
            $table->timestamps();

            $table->unique(['emiid', 'pvsuc', 'pvpdv'], 'puntos_venta_dosificacion_unica');
        });

        // Backfill: cada emisor existente ya tenía una única dosificación
        // fija (emisuc/emipdv) — se convierte en su punto de venta principal.
        DB::table('emisores')->select('emiid', 'emisuc', 'emipdv')->orderBy('emiid')->each(function ($emisor) {
            DB::table('puntos_venta')->insert([
                'emiid'      => $emisor->emiid,
                'pvsuc'      => $emisor->emisuc,
                'pvpdv'      => $emisor->emipdv,
                'pvest'      => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puntos_venta');
    }
};

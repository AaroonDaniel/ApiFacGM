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
        Schema::create('secuencias', function (Blueprint $table) {
            $table->bigIncrements('secid');
            $table->foreignId('emiid')->constrained('emisores', 'emiid')->cascadeOnDelete();
            $table->integer('secsuc')->default(0);
            $table->integer('secpdv')->default(0);
            $table->integer('sectipodoc')->default(1);
            $table->integer('secultimo')->default(0);
            $table->timestamps();
            $table->unique(['emiid', 'secsuc', 'secpdv', 'sectipodoc'], 'secuencias_dosificacion_unica');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('secuencias');
    }
};

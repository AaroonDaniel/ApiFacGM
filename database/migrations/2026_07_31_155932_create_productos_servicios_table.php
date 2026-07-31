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
        Schema::create('productos_servicios', function (Blueprint $table) {
            $table->bigIncrements('proid');
            $table->foreignId('emiid')->constrained('emisores', 'emiid')->cascadeOnDelete();
            $table->string('proactcod', 15);
            $table->string('procodsin', 15);
            $table->string('prodesc', 255);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('productos_servicios');
    }
};

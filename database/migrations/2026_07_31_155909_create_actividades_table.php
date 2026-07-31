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
        Schema::create('actividades', function (Blueprint $table) {
            $table->bigIncrements('actid');
            $table->foreignId('emiid')->constrained('emisores', 'emiid')->cascadeOnDelete();
            
            $table->string('actcod',15);
            $table->string('actdesc', 255);
            $table->boolean('actest')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('actividades');
    }
};

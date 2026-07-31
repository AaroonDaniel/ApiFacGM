<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('siat_codigos', function (Blueprint $table) {
            $table->bigIncrements('scoid');
            $table->foreignId('emiid')->constrained('emisores', 'emiid')->cascadeOnDelete();
            $table->string('scotipo', 10);
            $table->string('scovalor', 255);
            $table->string('scocodctrl', 100)->nullable();

            $table->smallInteger('scoamb');
            $table->integer('scosuc')->default(0);
            $table->integer('scopdv')->default(0);

            $table->dateTime('scoemi');
            $table->dateTime('scoven');
            $table->boolean('scoest')->default(true);
            $table->timestamps();
        });

        DB::statement(
            'CREATE UNIQUE INDEX siat_codigos_vigente_unico '
            . 'ON siat_codigos (emiid, scotipo, scoamb, scosuc, scopdv) '
            . 'WHERE scoest = true'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('siat_codigos');
    }
};

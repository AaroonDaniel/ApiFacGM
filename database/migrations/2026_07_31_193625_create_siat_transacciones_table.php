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
        Schema::create('siat_transacciones', function (Blueprint $table) {
            $table->bigIncrements('stxid');

            $table->foreignId('emiid')->nullable()
                ->constrained('emisores', 'emiid')->nullOnDelete();
            $table->foreignId('stxfacid')->nullable()
                ->constrained('facturas', 'facid')->nullOnDelete();

            $table->string('stxserv', 100);
            $table->text('stxpet')->nullable();
            $table->text('stxresp')->nullable();
            $table->string('stxest', 15);
            $table->dateTime('stxfch');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('siat_transacciones');
    }
};

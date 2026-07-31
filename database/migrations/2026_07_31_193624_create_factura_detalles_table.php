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
        Schema::create('factura_detalles', function (Blueprint $table) {
            $table->bigIncrements('facdetid');

            $table->foreignId('facid')
                ->constrained('facturas', 'facid')->cascadeOnDelete();

            $table->string('facdetacteco', 15);
            $table->string('facdetprodsin', 15);
            $table->string('facdetcodprod', 50);
            $table->string('facdetdesc', 255);

            $table->decimal('facdetcnt', 12, 2);
            $table->integer('facdetunimed');
            $table->decimal('facdetprc', 12, 2);
            $table->decimal('facdetdscto', 12, 2)->nullable();
            $table->decimal('facdetsub', 12, 2);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('factura_detalles');
    }
};

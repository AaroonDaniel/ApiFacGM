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
        Schema::create('emisores', function (Blueprint $table) {
            $table->bigIncrements('emiid');
            $table->foreignId('sisid')->nullable()->constrained('sistemas_cliente', 'sisid')->nullOnDelete();
            $table->string('eminit', 20)->unique();
            $table->string('emirazsoc', 255);
            $table->string('eminum', 100);
            $table->string('emidir', 255);
            $table->string('emitel', 30)->nullable();

            $table->integer('emisis');
            $table->integer('emisuc')->default(0);
            $table->integer('emipdv')->default(0);

            $table->text('emitoken');
            $table->smallInteger('emimod')->default(2);
            $table->smallInteger('emiamb')->default(2);

            $table->boolean('emiest')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('emisores');
    }
};

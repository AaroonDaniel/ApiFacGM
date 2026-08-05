<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum nunca se conectó a nada real en este proyecto (el modelo User no
 * usa HasApiTokens, la única ruta que dependía de él — /api/user — no la
 * consumía ningún sistema cliente real). La autenticación real de la API
 * es sistema.auth (AutenticarSistemaCliente + SistemaCliente.sisapikey),
 * sin relación con esta tabla. Tabla vacía al momento de esta migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }

    public function down(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }
};

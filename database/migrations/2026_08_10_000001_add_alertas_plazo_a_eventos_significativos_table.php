<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marca de cuándo se avisó por última vez que un evento superó su
     * plazo de envío (48h para paquete offline normal, 72h para paquete
     * CAFC) — sin esto, el cron de alertas (siat:alertar-plazos, cada hora)
     * reenviaría el correo de urgencia al administrador en cada corrida
     * mientras el evento siga sin regularizarse.
     */
    public function up(): void
    {
        Schema::table('eventos_significativos', function (Blueprint $table) {
            $table->dateTime('evealertapaq')->nullable()->after('evecodrecpaq');
            $table->dateTime('evealertacafc')->nullable()->after('evealertapaq');
        });
    }

    public function down(): void
    {
        Schema::table('eventos_significativos', function (Blueprint $table) {
            $table->dropColumn(['evealertapaq', 'evealertacafc']);
        });
    }
};

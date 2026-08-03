<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventos_significativos', function (Blueprint $table) {
            $table->integer('evesuc')->default(0)->after('emiid');
            $table->integer('evepdv')->default(0)->after('evesuc');
        });
    }

    public function down(): void
    {
        Schema::table('eventos_significativos', function (Blueprint $table) {
            $table->dropColumn(['evesuc', 'evepdv']);
        });
    }
};

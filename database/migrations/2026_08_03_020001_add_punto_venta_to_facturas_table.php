<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            // default 0 cubre exactamente el mismo valor que ya tenían
            // implícitamente todas las facturas existentes (vía el emisor).
            $table->integer('facsuc')->default(0)->after('emiid');
            $table->integer('facpdv')->default(0)->after('facsuc');
        });
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table) {
            $table->dropColumn(['facsuc', 'facpdv']);
        });
    }
};

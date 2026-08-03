<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El codigoSistema real del SIAT es alfanumérico (ej. "373643577039EEE99D0E"),
 * no un entero. La columna se creó como integer por error; se convierte a
 * string sin perder los datos ya cargados.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE emisores ALTER COLUMN emisis TYPE VARCHAR(50) USING emisis::VARCHAR(50)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE emisores ALTER COLUMN emisis TYPE INTEGER USING emisis::INTEGER');
    }
};

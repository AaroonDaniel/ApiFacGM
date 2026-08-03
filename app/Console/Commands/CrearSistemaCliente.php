<?php

namespace App\Console\Commands;

use App\Models\SistemaCliente;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * La api key solo existe en texto plano en este momento: se muestra una
 * vez en pantalla y se guarda hasheada (sha256, igual que
 * AutenticarSistemaCliente). Si se pierde, no hay forma de recuperarla,
 * solo de generar una nueva con --regenerar.
 */
class CrearSistemaCliente extends Command
{
    protected $signature = 'sistema:crear
        {siscod : Código corto y único del sistema (ej. ERP_ACME)}
        {sisnom : Nombre descriptivo del sistema}
        {--regenerar : Si el siscod ya existe, genera una nueva api key para él}';

    protected $description = 'Registra un sistema cliente externo y genera su api key de acceso a la API de facturación.';

    public function handle(): int
    {
        $siscod = $this->argument('siscod');
        $sisnom = $this->argument('sisnom');

        $sistema = SistemaCliente::where('siscod', $siscod)->first();

        if ($sistema && !$this->option('regenerar')) {
            $this->error("Ya existe un sistema con código '{$siscod}'. Usa --regenerar si quieres reemplazar su api key.");
            return self::FAILURE;
        }

        $key = Str::random(64);

        if ($sistema) {
            $sistema->update([
                'sisnom'    => $sisnom,
                'sisapikey' => hash('sha256', $key),
                'sisest'    => true,
            ]);
            $this->info("Api key regenerada para '{$siscod}'.");
        } else {
            SistemaCliente::create([
                'siscod'    => $siscod,
                'sisnom'    => $sisnom,
                'sisapikey' => hash('sha256', $key),
                'sisest'    => true,
            ]);
            $this->info("Sistema '{$siscod}' creado.");
        }

        $this->newLine();
        $this->line("Api key (cópiala ahora, no se volverá a mostrar):");
        $this->line("  {$key}");
        $this->newLine();
        $this->comment("Entrega esta key al sistema externo para el header: Authorization: Bearer <key>");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Emisor;
use App\Models\SistemaCliente;
use Illuminate\Console\Command;

/**
 * Cambia el dueño exclusivo de un emisor (emisores.sisid), el interruptor
 * que FacturaController::store() usa para decidir si el emisor está
 * abierto a cualquier sistema autenticado o cerrado a uno solo.
 */
class VincularEmisor extends Command
{
    protected $signature = 'emisor:vincular
        {nit : NIT del emisor}
        {siscod? : Código del sistema cliente dueño (omitir junto con --libre para dejarlo compartido)}
        {--libre : Quita el dueño exclusivo — el emisor queda abierto a cualquier sistema autenticado}';

    protected $description = 'Asigna o quita el dueño exclusivo de un emisor (compartido vs. único para pruebas).';

    public function handle(): int
    {
        $emisor = Emisor::where('eminit', $this->argument('nit'))->first();

        if (!$emisor) {
            $this->error("No existe ningún emisor con NIT {$this->argument('nit')}.");
            return self::FAILURE;
        }

        if ($this->option('libre')) {
            $emisor->update(['sisid' => null]);
            $this->info("Emisor {$emisor->eminit} ({$emisor->emirazsoc}) ahora es COMPARTIDO — cualquier sistema autenticado puede facturar con él.");
            return self::SUCCESS;
        }

        $siscod = $this->argument('siscod');
        if (!$siscod) {
            $this->error('Indica el siscod del sistema dueño, o usa --libre para dejarlo compartido.');
            return self::FAILURE;
        }

        $sistema = SistemaCliente::where('siscod', $siscod)->first();
        if (!$sistema) {
            $this->error("No existe ningún sistema cliente con código '{$siscod}'.");
            return self::FAILURE;
        }

        $emisor->update(['sisid' => $sistema->sisid]);
        $this->info("Emisor {$emisor->eminit} ({$emisor->emirazsoc}) ahora es EXCLUSIVO de '{$sistema->siscod}' — cualquier otro sistema recibirá 403.");

        return self::SUCCESS;
    }
}

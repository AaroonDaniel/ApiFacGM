<?php

namespace App\Console\Commands;


use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SiatCuisManualCommand extends Command
{
    protected $signature = 'siat:cuis-manual 
                            {nit : NIT del emisor (eminit)} 
                            {cuis : Valor del código CUIS (ej. 9BDCABFB)} 
                            {--sucursal=0 : Código de sucursal} 
                            {--pdv=0 : Código de punto de venta} 
                            {--vencimiento= : Fecha de vencimiento (YYYY-MM-DD HH:MM:SS), por defecto +1 año}
                            {--estado=ACTIVO : Estado del código (scoest)}';

    protected $description = 'Agrega o edita manualmente el código CUIS de un emisor en siat_codigos';

    public function handle(): int
    {
        $nit = $this->argument('nit');
        $cuis = strtoupper(trim($this->argument('cuis')));
        $sucursal = (int) $this->option('sucursal');
        $pdv = (int) $this->option('pdv');
        $estado = $this->option('estado');

        $emisor = DB::table('emisores')->where('eminit', $nit)->first();

        if (!$emisor) {
            $this->error("No se encontró ningún emisor con el NIT {$nit}.");
            return self::FAILURE;
        }

        $fechaVencimiento = $this->option('vencimiento') 
            ? Carbon::parse($this->option('vencimiento')) 
            : now()->addYear();

        // Buscamos si ya existe un registro CUIS para ese emisor, sucursal, pdv y ambiente
        $registroExistente = DB::table('siat_codigos')
            ->where('emiid', $emisor->emiid)
            ->where('scotipo', 'CUIS')
            ->where('scosuc', $sucursal)
            ->where('scopdv', $pdv)
            ->where('scoamb', $emisor->emiamb)
            ->first();

        if ($registroExistente) {
            DB::table('siat_codigos')
                ->where('scoid', $registroExistente->scoid)
                ->update([
                    'scovalor'   => $cuis,
                    'scoven'     => $fechaVencimiento,
                    'scoest'     => $estado,
                    'updated_at' => now(),
                ]);

            $this->info("CUIS actualizado correctamente (scoid={$registroExistente->scoid}) para el NIT {$nit} (Suc: {$sucursal}, PDV: {$pdv}) -> Nuevo valor: {$cuis}");
        } else {
            $id = DB::table('siat_codigos')->insertGetId([
                'emiid'      => $emisor->emiid,
                'scotipo'    => 'CUIS',
                'scovalor'   => $cuis,
                'scocodctrl' => null,
                'scoamb'     => $emisor->emiamb,
                'scosuc'     => $sucursal,
                'scopdv'     => $pdv,
                'scoemi'     => now(),
                'scoven'     => $fechaVencimiento,
                'scoest'     => $estado,
                'created_at' => now(),
                'updated_at' => now(),
            ], 'scoid');

            $this->info("CUIS registrado correctamente (scoid={$id}) para el NIT {$nit} (Suc: {$sucursal}, PDV: {$pdv}) -> Valor: {$cuis}");
        }

        return self::SUCCESS;
    }
}
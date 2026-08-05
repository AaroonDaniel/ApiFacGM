<?php

namespace App\Console\Commands;

use App\Models\Emisor;
use App\Models\PuntoVenta;
use App\Models\Secuencia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Da de alta (o actualiza con --actualizar) un emisor, y de paso su
 * dosificación principal + secuencia inicial — sin eso, EmisionService
 * rechaza cualquier factura para ese punto de venta con "No hay secuencia
 * configurada". Reemplaza al script manual de tinker
 * database/scripts/actualizar_emisor.php, igual que sistema:crear
 * reemplazó a crear_sistema_cliente.php.
 */
class CrearEmisor extends Command
{
    protected $signature = 'emisor:crear
        {nit : NIT del emisor}
        {razon_social : Razón social}
        {municipio : Municipio}
        {direccion : Dirección}
        {codigo_sistema : Código de sistema (alfanumérico) otorgado por el SIN}
        {token : Token de la API del SIAT}
        {--telefono= : Teléfono}
        {--sucursal=0 : Código de sucursal de la dosificación principal}
        {--punto-venta=0 : Código de punto de venta de la dosificación principal}
        {--modalidad=2 : Modalidad SIAT (1=electrónica, 2=computarizada)}
        {--ambiente=2 : Ambiente SIAT (1=producción, 2=piloto)}
        {--secuencia-desde=0 : Último número ya emitido para la dosificación principal}
        {--actualizar : Si el NIT ya existe, actualiza sus datos en vez de fallar}';

    protected $description = 'Da de alta o actualiza un emisor, con su punto de venta principal y secuencia.';

    public function handle(): int
    {
        $nit       = $this->argument('nit');
        $modalidad = (int) $this->option('modalidad');
        $ambiente  = (int) $this->option('ambiente');

        if (!in_array($modalidad, [Emisor::MODALIDAD_ELECTRONICA, Emisor::MODALIDAD_COMPUTARIZADA], true)) {
            $this->error('--modalidad debe ser 1 (electrónica) o 2 (computarizada).');
            return self::FAILURE;
        }

        if (!in_array($ambiente, [Emisor::AMBIENTE_PRODUCCION, Emisor::AMBIENTE_PILOTO], true)) {
            $this->error('--ambiente debe ser 1 (producción) o 2 (piloto).');
            return self::FAILURE;
        }

        $existente = Emisor::where('eminit', $nit)->first();
        if ($existente && !$this->option('actualizar')) {
            $this->error("Ya existe un emisor con NIT {$nit} (emiid={$existente->emiid}). Usa --actualizar si querés reemplazar sus datos.");
            return self::FAILURE;
        }

        $suc = (int) $this->option('sucursal');
        $pdv = (int) $this->option('punto-venta');

        $emisor = DB::transaction(function () use ($nit, $suc, $pdv, $modalidad, $ambiente) {
            $emisor = Emisor::updateOrCreate(['eminit' => $nit], [
                'emirazsoc' => $this->argument('razon_social'),
                'emimun'    => $this->argument('municipio'),
                'emidir'    => $this->argument('direccion'),
                'emitel'    => $this->option('telefono'),
                'emisis'    => $this->argument('codigo_sistema'),
                'emisuc'    => $suc,
                'emipdv'    => $pdv,
                'emitoken'  => $this->argument('token'),
                'emimod'    => $modalidad,
                'emiamb'    => $ambiente,
                'emiest'    => true,
            ]);

            PuntoVenta::firstOrCreate(
                ['emiid' => $emisor->emiid, 'pvsuc' => $suc, 'pvpdv' => $pdv],
                ['pvest' => true]
            );

            Secuencia::firstOrCreate(
                ['emiid' => $emisor->emiid, 'secsuc' => $suc, 'secpdv' => $pdv, 'sectipodoc' => 1],
                ['secultimo' => (int) $this->option('secuencia-desde')]
            );

            return $emisor;
        });

        $this->info(($emisor->wasRecentlyCreated ? 'Emisor creado' : 'Emisor actualizado')
            . " (emiid={$emisor->emiid}, eminit={$emisor->eminit}, emisis={$emisor->emisis}, emiamb={$emisor->emiamb}). "
            . "Punto de venta principal (suc {$suc}, PDV {$pdv}) listo.");

        return self::SUCCESS;
    }
}

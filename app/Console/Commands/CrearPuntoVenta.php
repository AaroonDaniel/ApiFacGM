<?php

namespace App\Console\Commands;

use App\Models\Emisor;
use App\Models\PuntoVenta;
use App\Models\Secuencia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Da de alta una sucursal/punto de venta adicional para un emisor que ya
 * existe (mismo NIT, mismo token — compartido por todo el sistema) y crea
 * su Secuencia inicial. Sin esto, EmisionService rechaza cualquier
 * factura para esa dosificación con "No hay secuencia configurada".
 */
class CrearPuntoVenta extends Command
{
    protected $signature = 'punto-venta:crear
        {nit : NIT del emisor ya existente}
        {sucursal : Código de sucursal}
        {punto_venta : Código de punto de venta}
        {--desde=0 : Último número ya emitido para esta dosificación (la próxima factura será este +1)}';

    protected $description = 'Registra un punto de venta adicional para un emisor y su secuencia inicial.';

    public function handle(): int
    {
        $nit = $this->argument('nit');
        $suc = (int) $this->argument('sucursal');
        $pdv = (int) $this->argument('punto_venta');
        $desde = (int) $this->option('desde');

        $emisor = Emisor::where('eminit', $nit)->first();

        if (!$emisor) {
            $this->error("No existe ningún emisor con NIT {$nit}.");
            return self::FAILURE;
        }

        $existente = PuntoVenta::where('emiid', $emisor->emiid)
            ->where('pvsuc', $suc)->where('pvpdv', $pdv)->first();

        if ($existente) {
            $this->error("Ya existe el punto de venta (sucursal {$suc}, PDV {$pdv}) para este emisor.");
            return self::FAILURE;
        }

        DB::transaction(function () use ($emisor, $suc, $pdv, $desde) {
            PuntoVenta::create([
                'emiid' => $emisor->emiid,
                'pvsuc' => $suc,
                'pvpdv' => $pdv,
                'pvest' => true,
            ]);

            Secuencia::create([
                'emiid'      => $emisor->emiid,
                'secsuc'     => $suc,
                'secpdv'     => $pdv,
                'sectipodoc' => 1,
                'secultimo'  => $desde,
            ]);
        });

        $this->info("Punto de venta (sucursal {$suc}, PDV {$pdv}) creado para {$emisor->emirazsoc}. Próxima factura: #" . ($desde + 1));

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\Emisor;
use App\Models\RangoCafc;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Registra localmente un rango CAFC ya autorizado por el SIN (trámite
 * administrativo del portal del SIAT, no algo que se pida por SOAP) — sin
 * esto, EmisionService::emitirCafc() rechaza cualquier transcripción para
 * ese código, ver validarRangoCafc().
 */
class CrearRangoCafc extends Command
{
    protected $signature = 'rango-cafc:crear
        {nit : NIT del emisor ya existente}
        {codigo_cafc : Código CAFC otorgado por el SIN}
        {desde : Número inicial autorizado del talonario}
        {hasta : Número final autorizado del talonario}
        {fecha_limite : Fecha límite para emitir con este CAFC (Y-m-d)}
        {--sucursal=0 : Código de sucursal}
        {--punto-venta=0 : Código de punto de venta}';

    protected $description = 'Registra un rango CAFC autorizado (desde-hasta) para un emisor+punto de venta.';

    public function handle(): int
    {
        $nit = $this->argument('nit');
        $emisor = Emisor::where('eminit', $nit)->first();

        if (!$emisor) {
            $this->error("No existe ningún emisor con NIT {$nit}.");
            return self::FAILURE;
        }

        $desde = (int) $this->argument('desde');
        $hasta = (int) $this->argument('hasta');

        if ($hasta < $desde) {
            $this->error("'hasta' ({$hasta}) no puede ser menor que 'desde' ({$desde}).");
            return self::FAILURE;
        }

        $suc = (int) $this->option('sucursal');
        $pdv = (int) $this->option('punto-venta');
        $codigoCafc = $this->argument('codigo_cafc');

        $existente = RangoCafc::where('emiid', $emisor->emiid)
            ->where('rcfsuc', $suc)->where('rcfpdv', $pdv)->where('rcfcafc', $codigoCafc)
            ->first();

        if ($existente) {
            $this->error("Ya existe un rango para el CAFC '{$codigoCafc}' en este punto de venta (rcfid={$existente->rcfid}).");
            return self::FAILURE;
        }

        $rango = RangoCafc::create([
            'emiid'     => $emisor->emiid,
            'rcfsuc'    => $suc,
            'rcfpdv'    => $pdv,
            'rcfcafc'   => $codigoCafc,
            'rcfdesde'  => $desde,
            'rcfhasta'  => $hasta,
            'rcflimite' => Carbon::parse($this->argument('fecha_limite'))->endOfDay(),
            'rcfest'    => true,
        ]);

        $this->info("Rango CAFC '{$codigoCafc}' registrado para {$emisor->emirazsoc} (sucursal {$suc}, PDV {$pdv}): "
            . "{$desde}-{$hasta}, vigente hasta {$rango->rcflimite->toDateString()}.");

        return self::SUCCESS;
    }
}

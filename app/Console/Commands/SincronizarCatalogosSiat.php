<?php

namespace App\Console\Commands;

use App\Models\Actividad;
use App\Models\Emisor;
use App\Models\Leyenda;
use App\Models\MetodoPago;
use App\Models\Moneda;
use App\Models\MotivoAnulacion;
use App\Models\MotivoEventoSignificativo;
use App\Models\ProductoServicio;
use App\Models\TipoDocumentoIdentidad;
use App\Models\TipoDocumentoSector;
use App\Models\UnidadMedida;
use App\Services\SiatService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Closure;

/**
 * Sincroniza los catálogos paramétricos del SIAT (FacturacionSincronizacion),
 * verificado contra el WSDL real del piloto. Cada catálogo se REEMPLAZA
 * entero en cada corrida (no hay upsert incremental): el SIN publica listas
 * completas, no deltas, y ninguna otra tabla referencia estas filas por FK
 * (los campos de facturas guardan el código SIN crudo, no un id local), así
 * que no hay nada que se rompa al vaciarlas y recargarlas.
 */
class SincronizarCatalogosSiat extends Command
{
    protected $signature = 'siat:sincronizar-catalogos';

    protected $description = 'Sincroniza actividades/productos por emisor, los catálogos globales (unidades de medida, leyendas, tipos de documento, métodos de pago, monedas, tipos de documento sector, motivos de anulación/evento) y la deriva de reloj contra el SIAT.';

    public function handle(): int
    {
        $emisores = Emisor::where('emiest', true)->get();

        if ($emisores->isEmpty()) {
            $this->warn('No hay emisores activos; nada que sincronizar.');
            return self::SUCCESS;
        }

        foreach ($emisores as $emisor) {
            $this->sincronizarPorEmisor($emisor);
        }

        // Catálogos globales: el SIAT los devuelve igual sin importar qué
        // NIT autentique la llamada, así que basta con pedirlos una vez
        // usando las credenciales del primer emisor activo.
        $siat = new SiatService($emisores->first());
        $this->sincronizarGlobales($siat);
        $this->verificarDerivaDeReloj($siat);

        return self::SUCCESS;
    }

    private function sincronizarPorEmisor(Emisor $emisor): void
    {
        $siat = new SiatService($emisor);

        $actividades = $siat->sincronizarActividades();
        if ($actividades['success']) {
            DB::transaction(function () use ($emisor, $actividades) {
                Actividad::where('emiid', $emisor->emiid)->delete();
                foreach ($actividades['items'] as $item) {
                    Actividad::create([
                        'emiid'   => $emisor->emiid,
                        'actcod'  => $item->codigoCaeb,
                        'actdesc' => $item->descripcion,
                        'actest'  => true,
                    ]);
                }
            });
            $this->info("Emisor {$emisor->eminit}: " . count($actividades['items']) . ' actividades sincronizadas.');
        } else {
            $this->error("Emisor {$emisor->eminit}: fallo al sincronizar actividades — " . ($actividades['mensaje'] ?? ''));
        }

        $productos = $siat->sincronizarProductosServicios();
        if ($productos['success']) {
            DB::transaction(function () use ($emisor, $productos) {
                ProductoServicio::where('emiid', $emisor->emiid)->delete();
                foreach ($productos['items'] as $item) {
                    ProductoServicio::create([
                        'emiid'     => $emisor->emiid,
                        'proactcod' => $item->codigoActividad,
                        'procodsin' => (string) $item->codigoProducto,
                        'prodesc'   => $item->descripcionProducto,
                    ]);
                }
            });
            $this->info("Emisor {$emisor->eminit}: " . count($productos['items']) . ' productos sincronizados.');
        } else {
            $this->error("Emisor {$emisor->eminit}: fallo al sincronizar productos — " . ($productos['mensaje'] ?? ''));
        }
    }

    private function sincronizarGlobales(SiatService $siat): void
    {
        $this->reemplazarCatalogo($siat->sincronizarUnidadesMedida(), UnidadMedida::class, fn ($item) => [
            'unicod'  => (int) $item->codigoClasificador,
            'unidesc' => $item->descripcion,
        ], 'unidades de medida');

        $this->reemplazarCatalogo($siat->sincronizarLeyendas(), Leyenda::class, fn ($item) => [
            'leyactcod' => $item->codigoActividad,
            'leydesc'   => $item->descripcionLeyenda,
        ], 'leyendas');

        $this->reemplazarCatalogo($siat->sincronizarTiposDocumentoIdentidad(), TipoDocumentoIdentidad::class, fn ($item) => [
            'tdicod'  => (int) $item->codigoClasificador,
            'tdidesc' => $item->descripcion,
        ], 'tipos de documento de identidad');

        $this->reemplazarCatalogo($siat->sincronizarMetodosPago(), MetodoPago::class, fn ($item) => [
            'mpacod'  => (int) $item->codigoClasificador,
            'mpadesc' => $item->descripcion,
        ], 'métodos de pago');

        $this->reemplazarCatalogo($siat->sincronizarMotivosAnulacion(), MotivoAnulacion::class, fn ($item) => [
            'moacod'  => (int) $item->codigoClasificador,
            'moadesc' => $item->descripcion,
        ], 'motivos de anulación');

        $this->reemplazarCatalogo($siat->sincronizarTiposMoneda(), Moneda::class, fn ($item) => [
            'moncod'  => (int) $item->codigoClasificador,
            'mondesc' => $item->descripcion,
        ], 'tipos de moneda');

        $this->reemplazarCatalogo($siat->sincronizarTiposDocumentoSector(), TipoDocumentoSector::class, fn ($item) => [
            'tdscod'  => (int) $item->codigoClasificador,
            'tdsdesc' => $item->descripcion,
        ], 'tipos de documento sector');

        $this->reemplazarCatalogo($siat->sincronizarMotivosEventoSignificativo(), MotivoEventoSignificativo::class, fn ($item) => [
            'mevcod'  => (int) $item->codigoClasificador,
            'mevdesc' => $item->descripcion,
        ], 'motivos de evento significativo');
    }

    /**
     * Deriva de reloj: si el servidor local se desalinea de la hora oficial
     * del SIN, las facturas empiezan a rechazarse por fechaEmision fuera de
     * tolerancia. Solo se loguea — no hay nada automático que "corregir" el
     * reloj del sistema operativo desde acá.
     */
    private function verificarDerivaDeReloj(SiatService $siat): void
    {
        $resultado = $siat->sincronizarFechaHora();

        if (!$resultado['success']) {
            $this->error('Fallo al consultar la hora del SIN — ' . ($resultado['mensaje'] ?? ''));
            return;
        }

        $deriva = $resultado['deriva_segundos'];
        $this->info("Hora del SIN: {$resultado['fechaHora']} (deriva: {$deriva}s).");

        if (abs($deriva) >= 60) {
            Log::critical("Deriva de reloj de {$deriva}s contra la hora oficial del SIN ({$resultado['fechaHora']}) "
                . '— riesgo de rechazo de facturas por fechaEmision fuera de tolerancia.');
        }
    }

    /**
     * @param class-string<Model> $modelo
     */
    private function reemplazarCatalogo(array $resultado, string $modelo, Closure $mapear, string $etiqueta): void
    {
        if (!$resultado['success']) {
            $this->error("Fallo al sincronizar {$etiqueta} — " . ($resultado['mensaje'] ?? ''));
            return;
        }

        DB::transaction(function () use ($modelo, $resultado, $mapear) {
            $modelo::query()->delete();
            foreach ($resultado['items'] as $item) {
                $modelo::create($mapear($item));
            }
        });

        $this->info(count($resultado['items']) . " {$etiqueta} sincronizados.");
    }
}

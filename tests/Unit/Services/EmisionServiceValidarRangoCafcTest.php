<?php

namespace Tests\Unit\Services;

use App\Services\EmisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

/**
 * EmisionService::validarRangoCafc() evita transcribir un número de
 * factura CAFC que no corresponda a un rango físico autorizado y vigente
 * — sin esto, un error de tipeo del recepcionista recién se detecta
 * cuando el SIAT rechaza el paquete offline completo, mucho después.
 */
class EmisionServiceValidarRangoCafcTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    private function datos(array $overrides = []): array
    {
        return array_merge([
            'cliente' => [
                'nombre_razon_social' => 'Cliente Test',
                'tipo_documento'      => 1,
                'numero_documento'    => '999',
            ],
            'detalle' => [[
                'actividad_economica' => '620000',
                'codigo_producto_sin' => '83131',
                'codigo_producto'     => 'P001',
                'descripcion'         => 'Servicio',
                'cantidad'            => 1,
                'unidad_medida'       => 57,
                'precio_unitario'     => 100.00,
            ]],
            'metodo_pago'         => 1,
            'descuento_adicional' => 0,
            'referencia_externa'  => null,
            'sistema_origen'      => 'test',
            'numero_factura'      => 501,
            'codigo_cafc'         => 'CAFC-TEST',
            'fecha_emision'       => now()->subHours(50),
        ], $overrides);
    }

    public function test_rechaza_numero_fuera_del_rango_autorizado(): void
    {
        $emisor = $this->crearEmisor();
        $pv = $this->crearPuntoVenta($emisor);
        $this->crearEventoSignificativo($emisor);
        $this->crearCatalogosBasicos($emisor);
        $this->crearRangoCafc($emisor, ['rcfdesde' => 1, 'rcfhasta' => 100]);

        $this->expectExceptionMessageMatches('/no corresponden a un rango físico autorizado/');
        (new EmisionService())->emitirCafc($emisor, $pv, $this->datos(['numero_factura' => 105]));
    }

    public function test_rechaza_codigo_cafc_que_no_coincide_con_ningun_rango(): void
    {
        $emisor = $this->crearEmisor();
        $pv = $this->crearPuntoVenta($emisor);
        $this->crearEventoSignificativo($emisor);
        $this->crearCatalogosBasicos($emisor);
        $this->crearRangoCafc($emisor, ['rcfcafc' => 'CAFC-REAL']);

        $this->expectExceptionMessageMatches('/no corresponden a un rango físico autorizado/');
        (new EmisionService())->emitirCafc($emisor, $pv, $this->datos(['codigo_cafc' => 'CAFC-INVENTADO']));
    }

    public function test_rechaza_rango_vencido(): void
    {
        $emisor = $this->crearEmisor();
        $pv = $this->crearPuntoVenta($emisor);
        $this->crearEventoSignificativo($emisor);
        $this->crearCatalogosBasicos($emisor);
        $this->crearRangoCafc($emisor, ['rcflimite' => now()->subDay()]);

        $this->expectExceptionMessageMatches('/no corresponden a un rango físico autorizado/');
        (new EmisionService())->emitirCafc($emisor, $pv, $this->datos());
    }

    public function test_rechaza_rango_desactivado(): void
    {
        $emisor = $this->crearEmisor();
        $pv = $this->crearPuntoVenta($emisor);
        $this->crearEventoSignificativo($emisor);
        $this->crearCatalogosBasicos($emisor);
        $this->crearRangoCafc($emisor, ['rcfest' => false]);

        $this->expectExceptionMessageMatches('/no corresponden a un rango físico autorizado/');
        (new EmisionService())->emitirCafc($emisor, $pv, $this->datos());
    }

    public function test_rango_de_otro_punto_de_venta_no_habilita_al_actual(): void
    {
        $emisor = $this->crearEmisor();
        $pv = $this->crearPuntoVenta($emisor, 0, 0);
        $this->crearEventoSignificativo($emisor);
        $this->crearCatalogosBasicos($emisor);
        // Rango autorizado para la sucursal 1, no la (0,0) que se usa acá.
        $this->crearRangoCafc($emisor, ['rcfsuc' => 1]);

        $this->expectExceptionMessageMatches('/no corresponden a un rango físico autorizado/');
        (new EmisionService())->emitirCafc($emisor, $pv, $this->datos());
    }

    public function test_dentro_del_rango_vigente_pasa_la_validacion(): void
    {
        $emisor = $this->crearEmisor();
        $pv = $this->crearPuntoVenta($emisor);
        $this->crearEventoSignificativo($emisor);
        $this->crearCatalogosBasicos($emisor);
        $this->crearRangoCafc($emisor);

        // No debe lanzar por el rango — falla más adelante por red (host de
        // pruebas inválido), pero eso ya no es responsabilidad de este test.
        $factura = (new EmisionService())->emitirCafc($emisor, $pv, $this->datos());

        $this->assertNotNull($factura->facid);
    }

    public function test_transcribir_actualiza_el_ultimo_utilizado_del_rango(): void
    {
        $emisor = $this->crearEmisor();
        $pv = $this->crearPuntoVenta($emisor);
        $this->crearEventoSignificativo($emisor);
        $this->crearCatalogosBasicos($emisor);
        $rango = $this->crearRangoCafc($emisor, ['rcfultimo' => null]);

        (new EmisionService())->emitirCafc($emisor, $pv, $this->datos(['numero_factura' => 42]));

        $this->assertSame(42, $rango->fresh()->rcfultimo);
    }
}

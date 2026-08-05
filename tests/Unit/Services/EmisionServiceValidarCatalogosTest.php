<?php

namespace Tests\Unit\Services;

use App\Models\EventosSignificativo;
use App\Models\MetodoPago;
use App\Models\PuntoVenta;
use App\Models\TipoDocumentoIdentidad;
use App\Services\EmisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

/**
 * EmisionService::validarCatalogos() es lo que impide construir un CUF/XML
 * con un código que el SIN no reconoce — antes de esto, cualquier string se
 * mandaba tal cual y el rechazo recién llegaba (o no) del lado del SIAT.
 * Se prueba vía emitirCafc() porque no depende de red para llegar hasta la
 * validación (a diferencia de emitir(), que si el detalle es válido sigue
 * de largo hasta intentar tocar el SIAT).
 */
class EmisionServiceValidarCatalogosTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    private function datos(array $detalleOverrides = [], array $overrides = []): array
    {
        return array_merge([
            'cliente' => [
                'nombre_razon_social' => 'Cliente Test',
                'tipo_documento'      => 1,
                'numero_documento'    => '999',
            ],
            'detalle' => [array_merge([
                'actividad_economica' => '620000',
                'codigo_producto_sin' => '83131',
                'codigo_producto'     => 'P001',
                'descripcion'         => 'Servicio',
                'cantidad'            => 1,
                'unidad_medida'       => 57,
                'precio_unitario'     => 100.00,
            ], $detalleOverrides)],
            'metodo_pago'         => 1,
            'descuento_adicional' => 0,
            'referencia_externa'  => null,
            'sistema_origen'      => 'test',
            'numero_factura'      => 501,
            'codigo_cafc'         => 'CAFC-TEST',
            'fecha_emision'       => now()->subHours(50),
        ], $overrides);
    }

    private function puntoVentaConEvento()
    {
        $emisor = $this->crearEmisor();
        $pv = $this->crearPuntoVenta($emisor);
        $this->crearEventoSignificativo($emisor);

        return [$emisor, $pv];
    }

    public function test_rechaza_actividad_economica_no_habilitada_para_el_emisor(): void
    {
        [$emisor, $pv] = $this->puntoVentaConEvento();
        // Solo los catálogos globales que se validan ANTES del detalle —
        // ninguna actividad/producto está habilitada para este emisor.
        TipoDocumentoIdentidad::create(['tdicod' => 1, 'tdidesc' => 'CI']);
        MetodoPago::create(['mpacod' => 1, 'mpadesc' => 'EFECTIVO']);

        $this->expectExceptionMessageMatches('/Actividad económica.*no está habilitada/');
        (new EmisionService())->emitirCafc($emisor, $pv, $this->datos());
    }

    public function test_rechaza_codigo_producto_sin_no_habilitado(): void
    {
        [$emisor, $pv] = $this->puntoVentaConEvento();
        $this->crearCatalogosBasicos($emisor);

        $this->expectExceptionMessageMatches('/Código de producto SIN.*no está habilitado/');
        (new EmisionService())->emitirCafc($emisor, $pv, $this->datos(['codigo_producto_sin' => '99999']));
    }

    public function test_rechaza_unidad_de_medida_inexistente(): void
    {
        [$emisor, $pv] = $this->puntoVentaConEvento();
        $this->crearCatalogosBasicos($emisor);

        $this->expectExceptionMessageMatches('/Unidad de medida.*no es un código válido/');
        (new EmisionService())->emitirCafc($emisor, $pv, $this->datos(['unidad_medida' => 9999]));
    }

    public function test_rechaza_metodo_de_pago_inexistente(): void
    {
        [$emisor, $pv] = $this->puntoVentaConEvento();
        $this->crearCatalogosBasicos($emisor);

        $this->expectExceptionMessageMatches('/Método de pago.*no es un código válido/');
        (new EmisionService())->emitirCafc($emisor, $pv, $this->datos([], ['metodo_pago' => 9999]));
    }

    public function test_rechaza_tipo_de_documento_inexistente(): void
    {
        [$emisor, $pv] = $this->puntoVentaConEvento();
        $this->crearCatalogosBasicos($emisor);

        $datos = $this->datos();
        $datos['cliente']['tipo_documento'] = 9999;

        $this->expectExceptionMessageMatches('/Tipo de documento de identidad.*no es un código válido/');
        (new EmisionService())->emitirCafc($emisor, $pv, $datos);
    }

    public function test_actividad_de_otro_emisor_no_habilita_al_actual(): void
    {
        [$emisor, $pv] = $this->puntoVentaConEvento();
        $otroEmisor = $this->crearEmisor(['eminit' => '9999999999']);
        $this->crearCatalogosBasicos($otroEmisor); // habilitado para OTRO emisor, no el de la prueba.

        $this->expectExceptionMessageMatches('/Actividad económica.*no está habilitada/');
        (new EmisionService())->emitirCafc($emisor, $pv, $this->datos());
    }

    public function test_con_catalogos_habilitados_pasa_la_validacion(): void
    {
        [$emisor, $pv] = $this->puntoVentaConEvento();
        $this->crearCatalogosBasicos($emisor);

        // No debe lanzar por catálogos — falla más adelante por red (host
        // de pruebas inválido), pero eso ya no es responsabilidad de este test.
        $factura = (new EmisionService())->emitirCafc($emisor, $pv, $this->datos());

        $this->assertNotNull($factura->facid);
    }
}

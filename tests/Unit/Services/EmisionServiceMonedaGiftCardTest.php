<?php

namespace Tests\Unit\Services;

use App\Services\EmisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

/**
 * Moneda extranjera y Gift Card: en producción el emisor real solo factura
 * en bolivianos y sin gift card, pero el Plan de Pruebas de certificación
 * del SIN (Fase 1) asigna casos de prueba obligatorios de moneda extranjera
 * y pago mixto con gift card — sin las fórmulas montoTotalMoneda =
 * montoTotal / tipoCambio y montoTotalSujetoIva = montoTotal - montoGiftCard
 * bien resueltas ANTES de construir el XML, esos casos se rechazan por
 * inconsistencia aritmética. Se prueba vía emitirCafc() porque no depende
 * de red para llegar hasta la persistencia (a diferencia de emitir(), que
 * si el detalle es válido sigue de largo hasta intentar tocar el SIAT).
 */
class EmisionServiceMonedaGiftCardTest extends TestCase
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

    private function puntoVentaConEvento()
    {
        $emisor = $this->crearEmisor();
        $pv = $this->crearPuntoVenta($emisor);
        $this->crearEventoSignificativo($emisor);
        $this->crearRangoCafc($emisor);
        $this->crearCatalogosBasicos($emisor);

        return [$emisor, $pv];
    }

    public function test_sin_moneda_explicita_asume_boliviano_con_tipo_de_cambio_uno(): void
    {
        [$emisor, $pv] = $this->puntoVentaConEvento();

        $factura = (new EmisionService())->emitirCafc($emisor, $pv, $this->datos());

        $this->assertSame(1, $factura->faccodmon);
        $this->assertEquals(1.0, (float) $factura->factipocambio);
        $this->assertEquals(100.00, (float) $factura->facmonto);
        $this->assertEquals(100.00, (float) $factura->facmontoiva);
    }

    public function test_rechaza_moneda_extranjera_sin_tipo_de_cambio(): void
    {
        [$emisor, $pv] = $this->puntoVentaConEvento();

        $this->expectExceptionMessageMatches('/tipo de cambio.*obligatorio/i');
        (new EmisionService())->emitirCafc($emisor, $pv, $this->datos([
            'moneda' => ['codigo' => 46],
        ]));
    }

    public function test_rechaza_codigo_de_moneda_que_no_existe_en_el_catalogo(): void
    {
        [$emisor, $pv] = $this->puntoVentaConEvento();

        $this->expectExceptionMessageMatches('/Código de moneda.*no es un código válido/');
        (new EmisionService())->emitirCafc($emisor, $pv, $this->datos([
            'moneda' => ['codigo' => 9999, 'tipo_cambio' => 6.96],
        ]));
    }

    public function test_acepta_moneda_extranjera_con_tipo_de_cambio_valido(): void
    {
        [$emisor, $pv] = $this->puntoVentaConEvento();

        $factura = (new EmisionService())->emitirCafc($emisor, $pv, $this->datos([
            'moneda' => ['codigo' => 46, 'tipo_cambio' => 6.96],
        ]));

        $this->assertSame(46, $factura->faccodmon);
        $this->assertEquals(6.96, (float) $factura->factipocambio);
        // montoTotal se sigue calculando/guardando en bolivianos; el XML
        // deriva montoTotalMoneda = montoTotal / tipoCambio al construirse.
        $this->assertEquals(100.00, (float) $factura->facmonto);
    }

    public function test_gift_card_reduce_el_monto_sujeto_a_iva_sin_alterar_el_monto_total(): void
    {
        [$emisor, $pv] = $this->puntoVentaConEvento();

        $factura = (new EmisionService())->emitirCafc($emisor, $pv, $this->datos([
            'metodo_pago'     => 27, // GIFT-CARD (catálogo real)
            'monto_gift_card' => 30,
        ]));

        $this->assertEquals(100.00, (float) $factura->facmonto);
        $this->assertEquals(70.00, (float) $factura->facmontoiva);
        $this->assertEquals(30.00, (float) $factura->facmontogiftcard);
    }

    public function test_rechaza_gift_card_mayor_al_monto_total(): void
    {
        [$emisor, $pv] = $this->puntoVentaConEvento();

        $this->expectExceptionMessageMatches('/gift card.*no puede superar/');
        (new EmisionService())->emitirCafc($emisor, $pv, $this->datos([
            'metodo_pago'     => 27,
            'monto_gift_card' => 200,
        ]));
    }

    public function test_rechaza_gift_card_con_un_metodo_de_pago_que_no_la_incluye(): void
    {
        // Confirmado en vivo contra el SIAT piloto real: un montoGiftCard
        // > 0 con un método de pago que no incluye "GIFT" se rechaza con
        // "MONTO GIFT CARD NO CORRESPONDE AL METODO DE PAGO".
        [$emisor, $pv] = $this->puntoVentaConEvento();

        $this->expectExceptionMessageMatches('/gift card solo es válido con un método de pago/');
        (new EmisionService())->emitirCafc($emisor, $pv, $this->datos([
            'metodo_pago'     => 1, // EFECTIVO, no incluye gift card
            'monto_gift_card' => 30,
        ]));
    }

    public function test_sin_gift_card_el_campo_queda_nulo(): void
    {
        [$emisor, $pv] = $this->puntoVentaConEvento();

        $factura = (new EmisionService())->emitirCafc($emisor, $pv, $this->datos());

        $this->assertNull($factura->facmontogiftcard);
    }
}

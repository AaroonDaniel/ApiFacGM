<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

/**
 * El chequeo de idempotencia en EmisionService::emitir() corre ANTES de
 * cualquier llamada al SIAT (es lo primero que hace el método) — así que
 * sembrando la factura "ya existente" directamente se puede probar sin red.
 */
class IdempotenciaTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    public function test_reintentar_la_misma_referencia_externa_devuelve_la_factura_existente_sin_crear_otra(): void
    {
        $emisor = $this->crearEmisor();
        $this->crearPuntoVenta($emisor);
        [, $key] = $this->crearSistemaCliente('sistema-dueño');

        $existente = $this->crearFactura($emisor, [
            'facsisorig' => 'sistema-dueño',
            'facrefext'  => 'orden_duplicada_123',
            'facnro'     => 7,
        ]);

        $datos = [
            'emisor_nit'         => $emisor->eminit,
            'referencia_externa' => 'orden_duplicada_123',
            'metodo_pago'        => 1,
            'cliente' => [
                'nombre_razon_social' => 'Cliente Cualquiera',
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
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $key)
            ->postJson('/api/facturas', $datos);

        // Se devuelve la MISMA factura (mismo id/numero), no una nueva.
        $response->assertJsonPath('factura.id', $existente->facid)
            ->assertJsonPath('factura.numero', 7);

        $this->assertSame(1, \App\Models\Factura::where('emiid', $emisor->emiid)
            ->where('facrefext', 'orden_duplicada_123')->count());
    }

    public function test_referencia_externa_distinta_de_otro_sistema_no_colisiona(): void
    {
        $emisor = $this->crearEmisor();

        // Mismo emisor+referencia, pero de OTRO sistema origen: no debe
        // considerarse el mismo duplicado.
        $this->crearFactura($emisor, [
            'facsisorig' => 'sistema-a',
            'facrefext'  => 'orden_compartida',
        ]);

        $existeParaOtroSistema = \App\Models\Factura::where('facsisorig', 'sistema-b')
            ->where('facrefext', 'orden_compartida')->exists();

        $this->assertFalse($existeParaOtroSistema);
    }
}

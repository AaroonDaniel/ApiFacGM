<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

class ValidacionFacturaTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    private function datosValidos(): array
    {
        return [
            'emisor_nit'         => '3327479013',
            'referencia_externa' => 'orden_test',
            'metodo_pago'        => 1,
            'cliente'            => [
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
        ];
    }

    private function conAuth()
    {
        [, $key] = $this->crearSistemaCliente();
        return $this->withHeader('Authorization', 'Bearer ' . $key);
    }

    public function test_falta_emisor_nit_devuelve_422(): void
    {
        $datos = $this->datosValidos();
        unset($datos['emisor_nit']);

        $this->conAuth()->postJson('/api/facturas', $datos)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['emisor_nit']);
    }

    public function test_falta_detalle_devuelve_422(): void
    {
        $datos = $this->datosValidos();
        unset($datos['detalle']);

        $this->conAuth()->postJson('/api/facturas', $datos)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['detalle']);
    }

    public function test_detalle_vacio_devuelve_422(): void
    {
        $datos = $this->datosValidos();
        $datos['detalle'] = [];

        $this->conAuth()->postJson('/api/facturas', $datos)
            ->assertStatus(422);
    }

    public function test_cantidad_negativa_devuelve_422(): void
    {
        $datos = $this->datosValidos();
        $datos['detalle'][0]['cantidad'] = -1;

        $this->conAuth()->postJson('/api/facturas', $datos)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['detalle.0.cantidad']);
    }

    public function test_precio_unitario_negativo_devuelve_422(): void
    {
        $datos = $this->datosValidos();
        $datos['detalle'][0]['precio_unitario'] = -5;

        $this->conAuth()->postJson('/api/facturas', $datos)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['detalle.0.precio_unitario']);
    }

    public function test_nit_de_emisor_inexistente_devuelve_422_con_mensaje_claro(): void
    {
        $datos = $this->datosValidos();
        $datos['emisor_nit'] = '0000000000';

        $this->conAuth()->postJson('/api/facturas', $datos)
            ->assertStatus(422)
            ->assertJson(['exito' => false]);
    }

    public function test_emisor_desactivado_se_trata_como_inexistente(): void
    {
        $emisor = $this->crearEmisor(['emiest' => false]);
        $this->crearPuntoVenta($emisor);

        $this->conAuth()->postJson('/api/facturas', $this->datosValidos())
            ->assertStatus(422);
    }

    public function test_punto_de_venta_inexistente_devuelve_422(): void
    {
        $emisor = $this->crearEmisor();
        $this->crearPuntoVenta($emisor); // solo (0,0)

        $datos = $this->datosValidos();
        $datos['punto_venta'] = ['sucursal' => 99, 'punto_venta' => 0];

        $this->conAuth()->postJson('/api/facturas', $datos)
            ->assertStatus(422)
            ->assertJsonFragment(['exito' => false]);
    }
}

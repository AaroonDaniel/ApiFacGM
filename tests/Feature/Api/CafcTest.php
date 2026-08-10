<?php

namespace Tests\Feature\Api;

use App\Models\Factura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

/**
 * POST /api/facturas/cafc — transcripción de facturas manuales de
 * contingencia (talón preimpreso). No depende del SIAT real: la
 * construcción del XML falla contra el host inválido de pruebas
 * (SIAT_URL_PILOTO en phpunit.xml), así que la factura queda en 'error' —
 * lo que importa acá es que se persista con el número/CAFC/evento
 * correctos ANTES de ese intento de red, que es la parte de transcripción
 * en sí (ver EmisionService::emitirCafc()).
 */
class CafcTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    private function datosValidos(): array
    {
        return [
            'emisor_nit'     => '3327479013',
            'numero_factura' => 501,
            'codigo_cafc'    => 'CAFC-0001-ABCD',
            'fecha_emision'  => now()->subHours(50)->format('Y-m-d H:i:s'),
            'metodo_pago'    => 1,
            'cliente'        => [
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

    private ?array $sistemaAuth = null;

    /** @return array{0: \App\Models\SistemaCliente, 1: string} */
    private function sistemaAuth(): array
    {
        return $this->sistemaAuth ??= $this->crearSistemaCliente();
    }

    private function conAuth()
    {
        [, $key] = $this->sistemaAuth();
        return $this->withHeader('Authorization', 'Bearer ' . $key);
    }

    /** Emisor vinculado (emisores.sisid) al sistema autenticado por conAuth(). */
    private function crearEmisorAutorizado(array $overrides = []): \App\Models\Emisor
    {
        [$sistema] = $this->sistemaAuth();
        return $this->crearEmisor(array_merge(['sisid' => $sistema->sisid], $overrides));
    }

    public function test_falta_numero_factura_devuelve_422(): void
    {
        $datos = $this->datosValidos();
        unset($datos['numero_factura']);

        $this->conAuth()->postJson('/api/facturas/cafc', $datos)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['numero_factura']);
    }

    public function test_falta_codigo_cafc_devuelve_422(): void
    {
        $datos = $this->datosValidos();
        unset($datos['codigo_cafc']);

        $this->conAuth()->postJson('/api/facturas/cafc', $datos)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['codigo_cafc']);
    }

    public function test_fecha_emision_futura_devuelve_422(): void
    {
        $datos = $this->datosValidos();
        $datos['fecha_emision'] = now()->addDay()->format('Y-m-d H:i:s');

        $this->conAuth()->postJson('/api/facturas/cafc', $datos)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_emision']);
    }

    public function test_sin_evento_de_contingencia_devuelve_422(): void
    {
        $emisor = $this->crearEmisorAutorizado();
        $this->crearPuntoVenta($emisor);
        // Sin crearEventoSignificativo(): no hay contingencia registrada.

        $this->conAuth()->postJson('/api/facturas/cafc', $this->datosValidos())
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertDatabaseCount('facturas', 0);
    }

    public function test_transcribe_la_factura_con_el_numero_y_cafc_exactos(): void
    {
        $emisor = $this->crearEmisorAutorizado();
        $this->crearPuntoVenta($emisor);
        $this->crearCatalogosBasicos($emisor);
        $this->crearRangoCafc($emisor, ['rcfcafc' => 'CAFC-0001-ABCD']);
        $evento = $this->crearEventoSignificativo($emisor);

        // 200: 'error' no está mapeado a un código especial (mismo criterio
        // que store()) — sin red real, termina en 'error'; ver docblock.
        $this->conAuth()->postJson('/api/facturas/cafc', $this->datosValidos())
            ->assertStatus(200)
            ->assertJson(['exito' => false]);

        $factura = Factura::first();
        $this->assertNotNull($factura);
        $this->assertSame(501, $factura->facnro);
        $this->assertSame('CAFC-0001-ABCD', $factura->faccafc);
        $this->assertSame($evento->eveid, $factura->faceveid);
        $this->assertSame(Factura::SIAT_ERROR, $factura->facsiatest);
    }

    public function test_numero_de_factura_duplicado_en_la_misma_dosificacion_falla(): void
    {
        $emisor = $this->crearEmisorAutorizado();
        $this->crearPuntoVenta($emisor);
        $this->crearEventoSignificativo($emisor);
        $this->crearFactura($emisor, ['facnro' => 501]);

        $this->conAuth()->postJson('/api/facturas/cafc', $this->datosValidos())
            ->assertStatus(422)
            ->assertJson(['exito' => false]);

        $this->assertDatabaseCount('facturas', 1);
    }
}

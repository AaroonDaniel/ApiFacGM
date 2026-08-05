<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

/**
 * POST /api/eventos-significativos — registro manual de una contingencia
 * que el sistema no detecta solo (corte de energía, falla de software,
 * etc.). No depende del SIAT real para las validaciones de negocio (se
 * resuelven antes de tocar la red); el intento real de registro sí
 * requiere red, así que en test siempre falla ahí (host inválido de
 * pruebas) — lo que importa acá es que llegue limpio hasta ese punto.
 */
class EventoSignificativoTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    private function datosValidos(): array
    {
        return [
            'emisor_nit'    => '3327479013',
            'codigo_motivo' => 7, // COD_CORTE_ENERGIA
            'descripcion'   => 'Corte de energía eléctrica en el local por 4 horas',
            'fecha_inicio'  => now()->subHours(6)->format('Y-m-d H:i:s'),
            'fecha_fin'     => now()->subHours(2)->format('Y-m-d H:i:s'),
        ];
    }

    private function conAuth()
    {
        [, $key] = $this->crearSistemaCliente();
        return $this->withHeader('Authorization', 'Bearer ' . $key);
    }

    public function test_falta_codigo_motivo_devuelve_422(): void
    {
        $datos = $this->datosValidos();
        unset($datos['codigo_motivo']);

        $this->conAuth()->postJson('/api/eventos-significativos', $datos)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['codigo_motivo']);
    }

    public function test_codigo_motivo_fuera_del_catalogo_real_devuelve_422(): void
    {
        $datos = $this->datosValidos();
        $datos['codigo_motivo'] = 99;

        $this->conAuth()->postJson('/api/eventos-significativos', $datos)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['codigo_motivo']);
    }

    public function test_fecha_fin_anterior_a_fecha_inicio_devuelve_422(): void
    {
        $datos = $this->datosValidos();
        $datos['fecha_inicio'] = now()->subHours(2)->format('Y-m-d H:i:s');
        $datos['fecha_fin']    = now()->subHours(6)->format('Y-m-d H:i:s');

        $this->conAuth()->postJson('/api/eventos-significativos', $datos)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_fin']);
    }

    public function test_fecha_fin_futura_devuelve_422(): void
    {
        $datos = $this->datosValidos();
        $datos['fecha_fin'] = now()->addHour()->format('Y-m-d H:i:s');

        $this->conAuth()->postJson('/api/eventos-significativos', $datos)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fecha_fin']);
    }

    public function test_falta_descripcion_devuelve_422(): void
    {
        $datos = $this->datosValidos();
        unset($datos['descripcion']);

        $this->conAuth()->postJson('/api/eventos-significativos', $datos)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['descripcion']);
    }

    public function test_no_se_puede_registrar_si_ya_hay_un_evento_activo_para_el_punto_de_venta(): void
    {
        $emisor = $this->crearEmisor();
        $this->crearPuntoVenta($emisor);
        $this->crearEventoSignificativo($emisor); // ya activo para (0,0)

        $response = $this->conAuth()->postJson('/api/eventos-significativos', $this->datosValidos());

        $response->assertStatus(422)->assertJson(['exito' => false]);
        $this->assertStringContainsString('Ya hay un evento significativo activo', $response->json('error'));

        // No se creó un segundo evento.
        $this->assertDatabaseCount('eventos_significativos', 1);
    }

    public function test_sin_evento_previo_intenta_registrar_y_falla_por_red_no_por_validacion(): void
    {
        $emisor = $this->crearEmisor();
        $this->crearPuntoVenta($emisor);

        $response = $this->conAuth()->postJson('/api/eventos-significativos', $this->datosValidos());

        // Sin red real disponible en test, falla al pedir el CUFD — pero
        // eso confirma que pasó todas las validaciones de negocio previas
        // (evento duplicado, fechas, motivo válido) sin problema.
        $response->assertStatus(422)->assertJson(['exito' => false]);
        $this->assertStringContainsString('CUFD vigente', $response->json('error'));

        // Tampoco quedó un evento a medio crear: registrarManual() solo
        // inserta la fila después de obtener el CUFD con éxito.
        $this->assertDatabaseCount('eventos_significativos', 0);
    }

    public function test_emisor_inexistente_devuelve_422(): void
    {
        $datos = $this->datosValidos();
        $datos['emisor_nit'] = '0000000000';

        $this->conAuth()->postJson('/api/eventos-significativos', $datos)
            ->assertStatus(422)
            ->assertJson(['exito' => false]);
    }
}

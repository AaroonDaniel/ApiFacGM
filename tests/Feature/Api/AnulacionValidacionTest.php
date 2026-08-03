<?php

namespace Tests\Feature\Api;

use App\Models\Factura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

/**
 * Todas las reglas de negocio de anulación se resuelven ANTES de llamar
 * al SIAT (AnulacionService::anular), así que se pueden probar completas
 * sin red — el único caso que sí necesita el SIAT real (motivo válido +
 * factura aceptada -> el SIAT confirma la anulación) se verificó en vivo,
 * no acá.
 */
class AnulacionValidacionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    private function conAuth(string $siscod = 'sistema-dueño')
    {
        [, $key] = $this->crearSistemaCliente($siscod);
        return $this->withHeader('Authorization', 'Bearer ' . $key);
    }

    public function test_no_se_puede_anular_una_factura_ya_anulada(): void
    {
        $emisor = $this->crearEmisor();
        $this->crearMotivoAnulacion();
        $factura = $this->crearFactura($emisor, [
            'facsisorig' => 'sistema-dueño',
            'facest'     => Factura::ESTADO_ANULADA,
        ]);

        $this->conAuth()->postJson("/api/facturas/{$factura->facid}/anular", ['codigo_motivo' => 1])
            ->assertStatus(422)
            ->assertJsonFragment(['exito' => false]);
    }

    public function test_no_se_puede_anular_una_factura_offline(): void
    {
        $emisor = $this->crearEmisor();
        $this->crearMotivoAnulacion();
        $factura = $this->crearFactura($emisor, [
            'facsisorig' => 'sistema-dueño',
            'facsiatest' => Factura::SIAT_OFFLINE,
        ]);

        $this->conAuth()->postJson("/api/facturas/{$factura->facid}/anular", ['codigo_motivo' => 1])
            ->assertStatus(422);
    }

    public function test_no_se_puede_anular_una_factura_rechazada(): void
    {
        $emisor = $this->crearEmisor();
        $this->crearMotivoAnulacion();
        $factura = $this->crearFactura($emisor, [
            'facsisorig' => 'sistema-dueño',
            'facsiatest' => Factura::SIAT_RECHAZADA,
        ]);

        $this->conAuth()->postJson("/api/facturas/{$factura->facid}/anular", ['codigo_motivo' => 1])
            ->assertStatus(422);
    }

    public function test_motivo_de_anulacion_inexistente_es_rechazado(): void
    {
        $emisor = $this->crearEmisor();
        $this->crearMotivoAnulacion(1, 'FACTURA MAL EMITIDA');
        $factura = $this->crearFactura($emisor, [
            'facsisorig' => 'sistema-dueño',
            'facsiatest' => Factura::SIAT_ACEPTADA,
        ]);

        $this->conAuth()->postJson("/api/facturas/{$factura->facid}/anular", ['codigo_motivo' => 999])
            ->assertStatus(422)
            ->assertJsonFragment(['exito' => false]);
    }

    public function test_falta_codigo_motivo_devuelve_422(): void
    {
        $emisor = $this->crearEmisor();
        $factura = $this->crearFactura($emisor, ['facsisorig' => 'sistema-dueño']);

        $this->conAuth()->postJson("/api/facturas/{$factura->facid}/anular", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['codigo_motivo']);
    }
}

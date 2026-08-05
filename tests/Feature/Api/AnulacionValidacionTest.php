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

    public function test_no_se_puede_anular_pasado_el_dia_10_del_mes_siguiente(): void
    {
        $emisor = $this->crearEmisor();
        $this->crearMotivoAnulacion();
        $factura = $this->crearFactura($emisor, [
            'facsisorig' => 'sistema-dueño',
            'facsiatest' => Factura::SIAT_ACEPTADA,
            'facfch'     => now()->subMonths(3)->startOfMonth()->format('Y-m-d'),
        ]);

        $response = $this->conAuth()->postJson("/api/facturas/{$factura->facid}/anular", ['codigo_motivo' => 1]);

        $response->assertStatus(422)->assertJsonFragment(['exito' => false]);
        $this->assertStringContainsString('plazo', $response->json('error'));
    }

    public function test_dentro_del_plazo_no_se_bloquea_por_fecha(): void
    {
        $emisor = $this->crearEmisor();
        $this->crearMotivoAnulacion();
        $factura = $this->crearFactura($emisor, [
            'facsisorig' => 'sistema-dueño',
            'facsiatest' => Factura::SIAT_ACEPTADA,
            'facfch'     => now()->format('Y-m-d'),
        ]);

        // Falla igual (sin SIAT real que confirme CUIS/CUFD en test), pero
        // el motivo NO debe ser el plazo vencido — confirma que la fecha en
        // sí no está bloqueando de más.
        $response = $this->conAuth()->postJson("/api/facturas/{$factura->facid}/anular", ['codigo_motivo' => 1]);

        $response->assertStatus(422);
        $this->assertStringNotContainsString('plazo', $response->json('error'));
    }

    public function test_no_se_puede_revertir_una_factura_no_anulada(): void
    {
        $emisor = $this->crearEmisor();
        $factura = $this->crearFactura($emisor, [
            'facsisorig' => 'sistema-dueño',
            'facest'     => Factura::ESTADO_VIGENTE,
        ]);

        $this->conAuth()->postJson("/api/facturas/{$factura->facid}/revertir-anulacion")
            ->assertStatus(422)
            ->assertJsonFragment(['exito' => false]);
    }

    public function test_un_sistema_no_puede_revertir_la_anulacion_de_otro(): void
    {
        $emisor = $this->crearEmisor();
        $factura = $this->crearFactura($emisor, [
            'facsisorig' => 'otro-sistema',
            'facest'     => Factura::ESTADO_ANULADA,
        ]);

        $this->conAuth()->postJson("/api/facturas/{$factura->facid}/revertir-anulacion")
            ->assertStatus(403);
    }
}

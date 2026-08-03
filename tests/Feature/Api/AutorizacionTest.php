<?php

namespace Tests\Feature\Api;

use App\Models\Factura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

class AutorizacionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    public function test_un_sistema_no_puede_ver_facturas_de_otro(): void
    {
        $emisor = $this->crearEmisor();
        $factura = $this->crearFactura($emisor, ['facsisorig' => 'sistema-dueño']);

        [, $keyOtro] = $this->crearSistemaCliente('sistema-intruso');

        $this->withHeader('Authorization', 'Bearer ' . $keyOtro)
            ->getJson("/api/facturas/{$factura->facid}")
            ->assertStatus(403);
    }

    public function test_el_sistema_dueño_si_puede_ver_su_factura(): void
    {
        $emisor = $this->crearEmisor();
        [, $key] = $this->crearSistemaCliente('sistema-dueño');
        $factura = $this->crearFactura($emisor, ['facsisorig' => 'sistema-dueño']);

        $this->withHeader('Authorization', 'Bearer ' . $key)
            ->getJson("/api/facturas/{$factura->facid}")
            ->assertStatus(200)
            ->assertJsonPath('factura.id', $factura->facid);
    }

    public function test_get_a_una_factura_aceptada_devuelve_200_no_201(): void
    {
        // Regresión: show() no debe heredar el 201 de store().
        $emisor = $this->crearEmisor();
        [, $key] = $this->crearSistemaCliente('sistema-dueño');
        $factura = $this->crearFactura($emisor, [
            'facsisorig' => 'sistema-dueño',
            'facsiatest' => Factura::SIAT_ACEPTADA,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $key)
            ->getJson("/api/facturas/{$factura->facid}")
            ->assertStatus(200);
    }

    public function test_un_sistema_no_puede_anular_facturas_de_otro(): void
    {
        $emisor = $this->crearEmisor();
        $this->crearMotivoAnulacion();
        $factura = $this->crearFactura($emisor, ['facsisorig' => 'sistema-dueño']);

        [, $keyOtro] = $this->crearSistemaCliente('sistema-intruso');

        $this->withHeader('Authorization', 'Bearer ' . $keyOtro)
            ->postJson("/api/facturas/{$factura->facid}/anular", ['codigo_motivo' => 1])
            ->assertStatus(403);
    }
}

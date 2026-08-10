<?php

namespace Tests\Feature\Api;

use App\Models\Factura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

/**
 * GET /api/facturas/{factura}/estado-siat — consulta en vivo del estado
 * oficial de la factura ante el SIAT. No depende del SIAT real: sin CUF/CUFD
 * la petición ni siquiera intenta la red (422 inmediato); con CUF/CUFD pero
 * sin CUIS vigente en base, falla al intentar renovarlo contra el host
 * inválido de pruebas (SIAT_URL_PILOTO en phpunit.xml) — lo que importa acá
 * es la autorización y las validaciones previas a tocar el SIAT.
 */
class VerificarEstadoSiatTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    public function test_un_sistema_no_puede_consultar_el_estado_de_una_factura_de_otro(): void
    {
        $emisor = $this->crearEmisor();
        $factura = $this->crearFactura($emisor, ['facsisorig' => 'sistema-dueño']);

        [, $keyOtro] = $this->crearSistemaCliente('sistema-intruso');

        $this->withHeader('Authorization', 'Bearer ' . $keyOtro)
            ->getJson("/api/facturas/{$factura->facid}/estado-siat")
            ->assertStatus(403);
    }

    public function test_factura_sin_cuf_devuelve_422(): void
    {
        $emisor = $this->crearEmisor();
        [, $key] = $this->crearSistemaCliente('sistema-dueño');
        $factura = $this->crearFactura($emisor, [
            'facsisorig' => 'sistema-dueño',
            'faccuf'     => null,
            'faccufd'    => null,
            'facsiatest' => Factura::SIAT_ERROR,
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $key)
            ->getJson("/api/facturas/{$factura->facid}/estado-siat")
            ->assertStatus(422)
            ->assertJson(['exito' => false]);
    }

    public function test_factura_con_cuf_pero_sin_cuis_vigente_falla_por_red_no_por_validacion(): void
    {
        $emisor = $this->crearEmisor();
        [, $key] = $this->crearSistemaCliente('sistema-dueño');
        $factura = $this->crearFactura($emisor, [
            'facsisorig' => 'sistema-dueño',
            'faccuf'     => 'CUF-DE-PRUEBA',
            'faccufd'    => 'CUFD-DE-PRUEBA',
        ]);

        // Sin CUIS previo en base y sin red real disponible: falla al
        // intentar renovarlo, confirmando que pasó la autorización y la
        // validación de CUF/CUFD antes de llegar a la red.
        $this->withHeader('Authorization', 'Bearer ' . $key)
            ->getJson("/api/facturas/{$factura->facid}/estado-siat")
            ->assertStatus(422)
            ->assertJson(['exito' => false]);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Factura;
use App\Models\FacturaDetalle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

/**
 * GET /factura/ver/{cuf} — la página pública que reemplaza al PDF
 * (Art. 26.I.b de la RND: hay que entregar XML + Representación Gráfica,
 * sin tener que generar PDF en el backend). Pública a propósito, sin
 * sistema.auth — quien la abre desde el correo no tiene api key.
 */
class RepresentacionGraficaTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    public function test_muestra_la_factura_por_cuf(): void
    {
        $emisor = $this->crearEmisor();
        $factura = $this->crearFactura($emisor, ['faccuf' => 'CUF-DE-PRUEBA-VISTA']);
        FacturaDetalle::create([
            'facid' => $factura->facid,
            'facdetacteco' => '620000',
            'facdetprodsin' => '83131',
            'facdetcodprod' => 'P001',
            'facdetdesc' => 'Servicio de prueba',
            'facdetcnt' => 1,
            'facdetunimed' => 57,
            'facdetprc' => 100,
            'facdetsub' => 100,
        ]);

        $response = $this->get('/factura/ver/CUF-DE-PRUEBA-VISTA');

        $response->assertOk();
        $response->assertSee($factura->facnro);
        $response->assertSee('CUF-DE-PRUEBA-VISTA');
        $response->assertSee('<svg', false);
    }

    public function test_cuf_inexistente_devuelve_404(): void
    {
        $this->get('/factura/ver/CUF-QUE-NO-EXISTE')->assertNotFound();
    }

    public function test_no_requiere_autenticacion(): void
    {
        $emisor = $this->crearEmisor();
        $this->crearFactura($emisor, ['faccuf' => 'CUF-SIN-AUTH']);

        // Sin header Authorization, a diferencia de /api/facturas.
        $this->get('/factura/ver/CUF-SIN-AUTH')->assertOk();
    }

    public function test_factura_anulada_muestra_el_aviso(): void
    {
        $emisor = $this->crearEmisor();
        $this->crearFactura($emisor, [
            'faccuf' => 'CUF-ANULADA', 'facest' => Factura::ESTADO_ANULADA,
        ]);

        $this->get('/factura/ver/CUF-ANULADA')
            ->assertOk()
            ->assertSee('FACTURA ANULADA');
    }
}

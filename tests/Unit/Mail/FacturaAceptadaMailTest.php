<?php

namespace Tests\Unit\Mail;

use App\Mail\FacturaAceptadaMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

class FacturaAceptadaMailTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    public function test_adjunta_el_xml_decodificado_del_storage(): void
    {
        Storage::fake('local');
        $xml = '<facturaComputarizadaCompraVenta>contenido de prueba</facturaComputarizadaCompraVenta>';
        Storage::disk('local')->put('siat/facturas/prueba.xml.gz', gzencode($xml));

        $emisor  = $this->crearEmisor();
        $factura = $this->crearFactura($emisor, [
            'facxmlpath' => 'siat/facturas/prueba.xml.gz',
            'faccuf'     => 'CUF-TEST-123',
        ]);

        $attachments = (new FacturaAceptadaMail($factura))->attachments();

        $this->assertCount(1, $attachments);
    }

    public function test_sin_xml_guardado_no_agrega_adjuntos(): void
    {
        $emisor  = $this->crearEmisor();
        $factura = $this->crearFactura($emisor, ['facxmlpath' => null]);

        $this->assertSame([], (new FacturaAceptadaMail($factura))->attachments());
    }

    public function test_el_contenido_se_renderiza_sin_errores(): void
    {
        $emisor  = $this->crearEmisor();
        $factura = $this->crearFactura($emisor, ['facxmlpath' => null, 'faccuf' => 'CUF-XYZ-789']);

        $html = (new FacturaAceptadaMail($factura))->render();

        $this->assertStringContainsString('CUF-XYZ-789', $html);
    }
}

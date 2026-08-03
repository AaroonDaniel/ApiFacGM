<?php

namespace Tests\Unit\Services;

use App\Models\Emisor;
use App\Models\Factura;
use App\Models\FacturaDetalle;
use App\Services\SiatXmlBuilder;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

/**
 * Construye modelos EN MEMORIA (sin guardarlos, sin BD) y arma el XML.
 * Usa Tests\TestCase (no PHPUnit\Framework\TestCase puro) porque
 * SiatXmlBuilder llama a config(), que necesita la app de Laravel booteada.
 */
class SiatXmlBuilderTest extends TestCase
{
    private function emisorDePrueba(): Emisor
    {
        $emisor = new Emisor([
            'eminit'    => '3327479013',
            'emirazsoc' => 'EMISOR DE PRUEBA',
            'emimun'    => 'La Paz',
            'emidir'    => 'Calle Falsa 123',
            'emitel'    => '70000000',
            'emisis'    => 'SISTEMATEST',
            'emisuc'    => 0,
            'emipdv'    => 0,
            'emimod'    => 2,
            'emiamb'    => 2,
        ]);
        $emisor->emiid = 1;

        return $emisor;
    }

    private function facturaDePrueba(array $overrides = []): Factura
    {
        $factura = new Factura(array_merge([
            'facsuc'      => 0,
            'facpdv'      => 0,
            'facnro'      => 42,
            'fachora'     => now(),
            'facnomrazon' => 'JUAN PEREZ',
            'facnumdoc'   => '123',
            'factipodoc'  => 1,
            'facmetpag'   => 1,
            'facmonto'    => 100.00,
            'facmontoiva' => 100.00,
            'facdesc'     => 0,
        ], $overrides));
        $factura->facid = 1;

        $detalle = new FacturaDetalle([
            'facdetacteco'  => '620000',
            'facdetprodsin' => '83131',
            'facdetcodprod' => 'P001',
            'facdetdesc'    => 'Servicio de prueba',
            'facdetcnt'     => 1,
            'facdetunimed'  => 57,
            'facdetprc'     => 100.00,
            'facdetsub'     => 100.00,
        ]);

        $factura->setRelation('detalles', new Collection([$detalle]));

        return $factura;
    }

    public function test_el_xml_incluye_los_datos_del_emisor_y_la_factura(): void
    {
        $builder = new SiatXmlBuilder($this->facturaDePrueba(), $this->emisorDePrueba(), 'CUFD-DE-PRUEBA', 'CONTROL123');
        $xml = $builder->buildXml();

        $this->assertStringContainsString('<nitEmisor>3327479013</nitEmisor>', $xml);
        $this->assertStringContainsString('<razonSocialEmisor>EMISOR DE PRUEBA</razonSocialEmisor>', $xml);
        $this->assertStringContainsString('<numeroFactura>42</numeroFactura>', $xml);
        $this->assertStringContainsString('<nombreRazonSocial>JUAN PEREZ</nombreRazonSocial>', $xml);
        $this->assertStringContainsString('<montoTotal>100.00</montoTotal>', $xml);
    }

    public function test_el_xml_usa_la_sucursal_y_pdv_de_la_factura_no_del_emisor(): void
    {
        // Emisor con dosificación (0,0), pero la factura pertenece a otro
        // punto de venta (sucursal 1) — el XML debe reflejar el de la
        // factura, no el del emisor (regresión del soporte multi-sucursal).
        $emisor  = $this->emisorDePrueba();
        $factura = $this->facturaDePrueba(['facsuc' => 1, 'facpdv' => 5]);

        $builder = new SiatXmlBuilder($factura, $emisor, 'CUFD', 'CONTROL');
        $xml = $builder->buildXml();

        $this->assertStringContainsString('<codigoSucursal>1</codigoSucursal>', $xml);
        $this->assertStringContainsString('<codigoPuntoVenta>5</codigoPuntoVenta>', $xml);
    }

    public function test_punto_de_venta_cero_se_marca_nil_no_texto_cero(): void
    {
        $builder = new SiatXmlBuilder($this->facturaDePrueba(['facpdv' => 0]), $this->emisorDePrueba(), 'CUFD', 'CONTROL');
        $xml = $builder->buildXml();

        $this->assertStringContainsString('<codigoPuntoVenta xsi:nil="true"/>', $xml);
    }

    public function test_sin_detalles_lanza_excepcion(): void
    {
        $factura = $this->facturaDePrueba();
        $factura->setRelation('detalles', new Collection());

        $builder = new SiatXmlBuilder($factura, $this->emisorDePrueba(), 'CUFD', 'CONTROL');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('no tiene detalles');
        $builder->buildXml();
    }

    public function test_el_cuf_generado_coincide_con_el_del_nodo_cuf_del_xml(): void
    {
        $builder = new SiatXmlBuilder($this->facturaDePrueba(), $this->emisorDePrueba(), 'CUFD', 'CONTROLXYZ');
        $xml = $builder->buildXml();
        $cuf = $builder->generateCuf();

        $this->assertStringContainsString("<cuf>{$cuf}</cuf>", $xml);
        $this->assertStringEndsWith('CONTROLXYZ', $cuf);
    }

    public function test_caracteres_especiales_en_la_razon_social_se_escapan(): void
    {
        $factura = $this->facturaDePrueba(['facnomrazon' => 'JUAN & PEREZ <SRL>']);
        $builder = new SiatXmlBuilder($factura, $this->emisorDePrueba(), 'CUFD', 'CONTROL');
        $xml = $builder->buildXml();

        $this->assertStringNotContainsString('JUAN & PEREZ <SRL>', $xml);
        $this->assertStringContainsString('JUAN &amp; PEREZ &lt;SRL&gt;', $xml);
    }
}

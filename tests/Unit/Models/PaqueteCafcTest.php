<?php

namespace Tests\Unit\Models;

use App\Models\Factura;
use App\Models\PaqueteCafc;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

/**
 * Antes, un evento solo podía trackear UN paquete CAFC a la vez
 * (eventos_significativos.evecodrecpaqcafc) — un segundo envío pisaba el
 * codigoRecepcion del primero, que quedaba sin forma de validarse. Estos
 * tests confirman que paquetes_cafc resuelve eso: varios paquetes por
 * evento, cada uno con sus propias facturas y estado, sin pisarse.
 */
class PaqueteCafcTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    public function test_un_evento_puede_tener_varios_paquetes_cafc_independientes(): void
    {
        $emisor = $this->crearEmisor();
        $evento = $this->crearEventoSignificativo($emisor);

        PaqueteCafc::create(['eveid' => $evento->eveid, 'paqcodrec' => 'REC-001', 'paqcantidad' => 2]);
        PaqueteCafc::create(['eveid' => $evento->eveid, 'paqcodrec' => 'REC-002', 'paqcantidad' => 1]);

        $this->assertCount(2, $evento->paquetesCafc);
    }

    public function test_pendiente_de_validar_solo_trae_paquetes_sin_estado_resuelto(): void
    {
        $emisor = $this->crearEmisor();
        $evento = $this->crearEventoSignificativo($emisor);

        $pendiente = PaqueteCafc::create(['eveid' => $evento->eveid, 'paqcodrec' => 'REC-001', 'paqcantidad' => 1]);
        $resuelto  = PaqueteCafc::create([
            'eveid' => $evento->eveid, 'paqcodrec' => 'REC-002', 'paqcantidad' => 1, 'paqestado' => 'accepted',
        ]);

        $pendientes = PaqueteCafc::pendienteDeValidar()->get();

        $this->assertTrue($pendientes->contains($pendiente));
        $this->assertFalse($pendientes->contains($resuelto));
    }

    public function test_las_facturas_quedan_ligadas_al_paquete_que_las_envio_no_a_otro_del_mismo_evento(): void
    {
        $emisor = $this->crearEmisor();
        $evento = $this->crearEventoSignificativo($emisor);

        $paquete1 = PaqueteCafc::create(['eveid' => $evento->eveid, 'paqcodrec' => 'REC-001', 'paqcantidad' => 1]);
        $paquete2 = PaqueteCafc::create(['eveid' => $evento->eveid, 'paqcodrec' => 'REC-002', 'paqcantidad' => 1]);

        // Mismo numeroarchivo (1) en ambos paquetes a propósito: antes de
        // este fix esto habría sido ambiguo dentro de un mismo evento.
        $f1 = $this->crearFactura($emisor, [
            'facsiatest' => Factura::SIAT_EMPAQUETADA, 'faccafc' => 'CAFC-1',
            'facpaquetecafcid' => $paquete1->paqid, 'facnumeroarchivo' => 1,
        ]);
        $f2 = $this->crearFactura($emisor, [
            'facsiatest' => Factura::SIAT_EMPAQUETADA, 'faccafc' => 'CAFC-1',
            'facpaquetecafcid' => $paquete2->paqid, 'facnumeroarchivo' => 1,
        ]);

        $this->assertTrue($paquete1->facturas->first()->is($f1));
        $this->assertTrue($paquete2->facturas->first()->is($f2));
        $this->assertCount(1, $paquete1->facturas);
        $this->assertCount(1, $paquete2->facturas);
    }
}

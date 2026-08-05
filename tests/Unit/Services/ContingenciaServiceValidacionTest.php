<?php

namespace Tests\Unit\Services;

use App\Models\Factura;
use App\Services\ContingenciaService;
use App\Services\NotificacionFacturaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use ReflectionMethod;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

/**
 * ContingenciaService::aplicarResultadoValidacion() es el punto donde se
 * decide, factura por factura, si queda aceptada o rechazada tras validar
 * un paquete — la parte crítica del hallazgo de "un documento malo no debe
 * tumbar todo el paquete". Se prueba vía reflection porque el método es
 * privado y ContingenciaService no tiene SiatService inyectable para
 * mockear el flujo público completo.
 */
class ContingenciaServiceValidacionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    private function aplicar($facturas, array $resultado): void
    {
        $metodo = new ReflectionMethod(ContingenciaService::class, 'aplicarResultadoValidacion');
        $metodo->setAccessible(true);
        $metodo->invoke(new ContingenciaService(), $facturas, $resultado, new NotificacionFacturaService());
    }

    public function test_accepted_marca_todas_las_facturas_como_aceptadas(): void
    {
        Mail::fake();
        $emisor = $this->crearEmisor();
        $f1 = $this->crearFactura($emisor, ['facsiatest' => Factura::SIAT_EMPAQUETADA]);
        $f2 = $this->crearFactura($emisor, ['facsiatest' => Factura::SIAT_EMPAQUETADA]);

        $this->aplicar(collect([$f1, $f2]), ['status' => 'accepted']);

        $this->assertSame(Factura::SIAT_ACEPTADA, $f1->fresh()->facsiatest);
        $this->assertSame(Factura::SIAT_ACEPTADA, $f2->fresh()->facsiatest);
    }

    public function test_rejected_sin_mensajes_granulares_rechaza_todo_el_paquete(): void
    {
        $emisor = $this->crearEmisor();
        $f1 = $this->crearFactura($emisor, ['facsiatest' => Factura::SIAT_EMPAQUETADA, 'facnumeroarchivo' => 1]);
        $f2 = $this->crearFactura($emisor, ['facsiatest' => Factura::SIAT_EMPAQUETADA, 'facnumeroarchivo' => 2]);

        $this->aplicar(collect([$f1, $f2]), ['status' => 'rejected', 'mensajes' => []]);

        $this->assertSame(Factura::SIAT_RECHAZADA, $f1->fresh()->facsiatest);
        $this->assertSame(Factura::SIAT_RECHAZADA, $f2->fresh()->facsiatest);
    }

    public function test_rejected_con_mensaje_granular_solo_rechaza_el_documento_marcado(): void
    {
        Mail::fake();
        $emisor = $this->crearEmisor();
        $f1 = $this->crearFactura($emisor, ['facsiatest' => Factura::SIAT_EMPAQUETADA, 'facnumeroarchivo' => 1]);
        $f2 = $this->crearFactura($emisor, ['facsiatest' => Factura::SIAT_EMPAQUETADA, 'facnumeroarchivo' => 2]);

        $this->aplicar(collect([$f1, $f2]), [
            'status'   => 'rejected',
            'mensajes' => [
                ['codigo' => 123, 'descripcion' => 'CUF inválido', 'advertencia' => false, 'numeroArchivo' => 2, 'numeroDetalle' => null],
            ],
        ]);

        $this->assertSame(Factura::SIAT_ACEPTADA, $f1->fresh()->facsiatest);
        $this->assertSame(Factura::SIAT_RECHAZADA, $f2->fresh()->facsiatest);
    }

    public function test_solo_advertencias_sin_error_real_cae_al_fallback_conservador(): void
    {
        $emisor = $this->crearEmisor();
        $f1 = $this->crearFactura($emisor, ['facsiatest' => Factura::SIAT_EMPAQUETADA, 'facnumeroarchivo' => 1]);

        // Un mensaje con advertencia=true no cuenta como error real; sin
        // ningún error real detectado, no hay base para salvar documentos
        // — cae al mismo fallback conservador que "sin mensajes".
        $this->aplicar(collect([$f1]), [
            'status'   => 'rejected',
            'mensajes' => [
                ['codigo' => 1, 'descripcion' => 'Aviso menor', 'advertencia' => true, 'numeroArchivo' => 1, 'numeroDetalle' => null],
            ],
        ]);

        $this->assertSame(Factura::SIAT_RECHAZADA, $f1->fresh()->facsiatest);
    }

    public function test_factura_sin_numeroarchivo_se_rechaza_por_seguridad(): void
    {
        $emisor = $this->crearEmisor();
        $f1 = $this->crearFactura($emisor, ['facsiatest' => Factura::SIAT_EMPAQUETADA, 'facnumeroarchivo' => null]);
        $f2 = $this->crearFactura($emisor, ['facsiatest' => Factura::SIAT_EMPAQUETADA, 'facnumeroarchivo' => 2]);

        $this->aplicar(collect([$f1, $f2]), [
            'status'   => 'rejected',
            'mensajes' => [
                ['codigo' => 123, 'descripcion' => 'Error', 'advertencia' => false, 'numeroArchivo' => 2, 'numeroDetalle' => null],
            ],
        ]);

        // Sin facnumeroarchivo no hay forma de confirmar que este documento
        // no era el marcado, así que se rechaza por seguridad.
        $this->assertSame(Factura::SIAT_RECHAZADA, $f1->fresh()->facsiatest);
        $this->assertSame(Factura::SIAT_RECHAZADA, $f2->fresh()->facsiatest);
    }
}

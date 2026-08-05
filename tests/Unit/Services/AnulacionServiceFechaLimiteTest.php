<?php

namespace Tests\Unit\Services;

use App\Models\Factura;
use App\Services\AnulacionService;
use Carbon\Carbon;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AnulacionService::fechaLimiteAnulacion() es privado y puro (solo Carbon),
 * pero instanciar un Eloquent Model necesita la app de Laravel booteada
 * (resolver de conexión) aunque no se toque la BD — por eso Tests\TestCase
 * y no PHPUnit\Framework\TestCase puro. Se prueba vía reflection para fijar
 * el borde exacto del día 10, sin depender de la fecha real de hoy.
 */
class AnulacionServiceFechaLimiteTest extends TestCase
{
    private function limite(string $facfch): Carbon
    {
        $metodo = new ReflectionMethod(AnulacionService::class, 'fechaLimiteAnulacion');
        $metodo->setAccessible(true);

        $factura = new Factura(['facfch' => $facfch]);

        return $metodo->invoke(new AnulacionService(), $factura);
    }

    public function test_limite_es_el_dia_10_del_mes_siguiente(): void
    {
        $limite = $this->limite('2026-03-15');
        $this->assertSame('2026-04-10', $limite->toDateString());
    }

    public function test_limite_respeta_el_cambio_de_año(): void
    {
        $limite = $this->limite('2026-12-20');
        $this->assertSame('2027-01-10', $limite->toDateString());
    }

    public function test_limite_es_fin_del_dia_10_no_su_inicio(): void
    {
        $limite = $this->limite('2026-06-01');
        $this->assertSame('2026-07-10 23:59:59', $limite->format('Y-m-d H:i:s'));
    }
}

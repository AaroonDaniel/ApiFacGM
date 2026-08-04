<?php

namespace Tests\Unit\Models;

use App\Models\EventosSignificativo;
use Tests\TestCase;

/**
 * Los plazos de contingencia (72h offline, 48h para enviar el paquete) son
 * normativos, no ajustables — si estos cálculos se rompen, el sistema deja
 * de bloquear (o de alertar) justo cuando más importa.
 */
class EventosSignificativoTest extends TestCase
{
    private function eventoConInicioHace(int $horas): EventosSignificativo
    {
        return new EventosSignificativo(['eveini' => now()->subHours($horas)]);
    }

    public function test_no_excede_limite_offline_dentro_de_las_72h(): void
    {
        $evento = $this->eventoConInicioHace(71);
        $this->assertFalse($evento->excedioLimiteOffline());
    }

    public function test_excede_limite_offline_pasadas_las_72h(): void
    {
        $evento = $this->eventoConInicioHace(73);
        $this->assertTrue($evento->excedioLimiteOffline());
    }

    public function test_evento_recien_creado_no_excede_ningun_limite(): void
    {
        $evento = new EventosSignificativo(['eveini' => now()]);
        $this->assertFalse($evento->excedioLimiteOffline());
        $this->assertFalse($evento->excedioPlazoEnvioPaquete());
    }

    public function test_sin_evefin_no_excede_plazo_de_envio(): void
    {
        $evento = new EventosSignificativo(['eveini' => now()->subDays(10)]);
        $this->assertNull($evento->evefin);
        $this->assertFalse($evento->excedioPlazoEnvioPaquete());
    }

    public function test_no_excede_plazo_de_envio_dentro_de_las_48h_desde_evefin(): void
    {
        $evento = new EventosSignificativo(['evefin' => now()->subHours(47)]);
        $this->assertFalse($evento->excedioPlazoEnvioPaquete());
    }

    public function test_excede_plazo_de_envio_pasadas_las_48h_desde_evefin(): void
    {
        $evento = new EventosSignificativo(['evefin' => now()->subHours(49)]);
        $this->assertTrue($evento->excedioPlazoEnvioPaquete());
    }
}

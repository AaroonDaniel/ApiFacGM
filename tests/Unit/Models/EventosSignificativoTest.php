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

    public function test_sin_evefin_no_excede_plazo_cafc(): void
    {
        $evento = new EventosSignificativo(['eveini' => now()->subDays(10)]);
        $this->assertFalse($evento->excedioPlazoEnvioCafc());
    }

    public function test_no_excede_plazo_cafc_dentro_de_las_72h_desde_evefin(): void
    {
        $evento = new EventosSignificativo(['evefin' => now()->subHours(71)]);
        $this->assertFalse($evento->excedioPlazoEnvioCafc());
    }

    public function test_excede_plazo_cafc_pasadas_las_72h_desde_evefin(): void
    {
        $evento = new EventosSignificativo(['evefin' => now()->subHours(73)]);
        $this->assertTrue($evento->excedioPlazoEnvioCafc());
    }

    public function test_a_las_50h_excede_plazo_normal_pero_no_el_cafc(): void
    {
        // Confirma que las dos ventanas son independientes: a 50h del
        // cierre, el paquete normal (48h) ya está fuera de plazo pero el
        // CAFC (72h) todavía no.
        $evento = new EventosSignificativo(['evefin' => now()->subHours(50)]);
        $this->assertTrue($evento->excedioPlazoEnvioPaquete());
        $this->assertFalse($evento->excedioPlazoEnvioCafc());
    }
}

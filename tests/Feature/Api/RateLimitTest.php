<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

class RateLimitTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    protected function setUp(): void
    {
        parent::setUp();
        // El store 'array' de cache/RateLimiter persiste entre tests dentro
        // del mismo proceso de PHPUnit (no lo resetea RefreshDatabase, que
        // solo hace rollback de la BD) — sin este flush, peticiones de
        // OTROS tests contra /api/facturas dejarían el contador ya gastado
        // antes de que este test empiece a contar.
        Cache::flush();
    }

    public function test_pasadas_60_peticiones_por_minuto_desde_la_misma_ip_corta_con_429(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/facturas', [])->assertStatus(401);
        }

        $this->postJson('/api/facturas', [])->assertStatus(429);
    }
}

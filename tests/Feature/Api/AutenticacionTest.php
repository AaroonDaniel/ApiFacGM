<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

class AutenticacionTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    public function test_sin_header_authorization_devuelve_401(): void
    {
        $response = $this->postJson('/api/facturas', []);

        $response->assertStatus(401)
            ->assertJson(['exito' => false]);
    }

    public function test_api_key_invalida_devuelve_401(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer esta-key-no-existe')
            ->postJson('/api/facturas', []);

        $response->assertStatus(401);
    }

    public function test_api_key_de_sistema_inactivo_devuelve_401(): void
    {
        [$sistema, $key] = $this->crearSistemaCliente();
        $sistema->update(['sisest' => false]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $key)
            ->postJson('/api/facturas', []);

        $response->assertStatus(401);
    }

    public function test_api_key_valida_pasa_la_autenticacion(): void
    {
        [, $key] = $this->crearSistemaCliente();

        // Sin body válido, pero con key válida: debe llegar a la
        // validación de datos (422), no quedarse en 401.
        $response = $this->withHeader('Authorization', 'Bearer ' . $key)
            ->postJson('/api/facturas', []);

        $response->assertStatus(422);
    }
}

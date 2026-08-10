<?php

namespace Tests\Unit\Commands;

use App\Mail\AlertaPlazoContingenciaMail;
use App\Models\EventosSignificativo;
use App\Models\Factura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

class AlertarPlazosContingenciaTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    public function test_avisa_por_correo_cuando_un_evento_supera_el_plazo_de_paquete(): void
    {
        Mail::fake();
        config(['siat.admin_email' => 'admin@example.com']);

        $emisor = $this->crearEmisor();
        $evento = $this->crearEventoSignificativo($emisor, [
            'eveest' => EventosSignificativo::ESTADO_CERRADO,
            'evefin' => now()->subHours(50), // > 48h
            'evecodrec' => 'REC-EVENTO-1',
        ]);
        // Le falta el paquete: sigue necesitando reconciliación normal.
        $this->crearFactura($emisor, [
            'facsiatest' => Factura::SIAT_OFFLINE,
            'faceveid'   => $evento->eveid,
        ]);

        $this->artisan('siat:alertar-plazos')->assertExitCode(0);

        Mail::assertQueued(AlertaPlazoContingenciaMail::class, fn ($mail) => $mail->evento->is($evento) && $mail->tipoPlazo === 'paquete');
        $this->assertNotNull($evento->fresh()->evealertapaq);
    }

    public function test_no_reenvia_la_alerta_si_ya_se_habia_enviado(): void
    {
        Mail::fake();
        config(['siat.admin_email' => 'admin@example.com']);

        $emisor = $this->crearEmisor();
        $evento = $this->crearEventoSignificativo($emisor, [
            'eveest' => EventosSignificativo::ESTADO_CERRADO,
            'evefin' => now()->subHours(50),
            'evecodrec' => 'REC-EVENTO-1',
            'evealertapaq' => now()->subHour(),
        ]);
        $this->crearFactura($emisor, [
            'facsiatest' => Factura::SIAT_OFFLINE,
            'faceveid'   => $evento->eveid,
        ]);

        $this->artisan('siat:alertar-plazos')->assertExitCode(0);

        Mail::assertNothingQueued();
    }

    public function test_no_avisa_si_todavia_no_supera_el_plazo(): void
    {
        Mail::fake();
        config(['siat.admin_email' => 'admin@example.com']);

        $emisor = $this->crearEmisor();
        $evento = $this->crearEventoSignificativo($emisor, [
            'eveest' => EventosSignificativo::ESTADO_CERRADO,
            'evefin' => now()->subHours(2), // < 48h
            'evecodrec' => 'REC-EVENTO-1',
        ]);
        $this->crearFactura($emisor, [
            'facsiatest' => Factura::SIAT_OFFLINE,
            'faceveid'   => $evento->eveid,
        ]);

        $this->artisan('siat:alertar-plazos')->assertExitCode(0);

        Mail::assertNothingQueued();
    }

    public function test_avisa_por_correo_cuando_un_paquete_cafc_supera_su_plazo(): void
    {
        Mail::fake();
        config(['siat.admin_email' => 'admin@example.com']);

        $emisor = $this->crearEmisor();
        $evento = $this->crearEventoSignificativo($emisor, [
            'eveest' => EventosSignificativo::ESTADO_CERRADO,
            'evefin' => now()->subHours(80), // > 72h
            'evecodrec' => 'REC-EVENTO-1',
        ]);
        $this->crearFactura($emisor, [
            'facsiatest' => Factura::SIAT_OFFLINE,
            'faceveid'   => $evento->eveid,
            'faccafc'    => 'CAFC-0001',
        ]);

        $this->artisan('siat:alertar-plazos')->assertExitCode(0);

        Mail::assertQueued(AlertaPlazoContingenciaMail::class, fn ($mail) => $mail->evento->is($evento) && $mail->tipoPlazo === 'cafc');
        $this->assertNotNull($evento->fresh()->evealertacafc);
    }
}

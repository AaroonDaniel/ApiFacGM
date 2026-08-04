<?php

namespace Tests\Unit\Services;

use App\Mail\FacturaAceptadaMail;
use App\Services\NotificacionFacturaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

class NotificacionFacturaServiceTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    public function test_encola_el_correo_cuando_la_factura_tiene_email(): void
    {
        Mail::fake();

        $emisor  = $this->crearEmisor();
        $factura = $this->crearFactura($emisor, ['facemail' => 'cliente@example.com']);

        (new NotificacionFacturaService())->notificarSiCorresponde($factura);

        Mail::assertQueued(FacturaAceptadaMail::class, fn ($mail) => $mail->factura->is($factura));
    }

    public function test_no_encola_nada_si_la_factura_no_tiene_email(): void
    {
        Mail::fake();

        $emisor  = $this->crearEmisor();
        $factura = $this->crearFactura($emisor, ['facemail' => null]);

        (new NotificacionFacturaService())->notificarSiCorresponde($factura);

        Mail::assertNothingQueued();
    }
}

<?php

namespace Tests\Unit\Models;

use App\Models\EventosSignificativo;
use App\Models\Factura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithFacturacion;
use Tests\TestCase;

/**
 * scopeNecesitaReconciliacionNormal() (y scopePendienteDeEnvio(), que lo
 * reutiliza) es lo que decide si ContingenciaService::reconciliar() todavía
 * tiene algo que hacer en el canal offline NORMAL. Antes de este fix, un
 * evento puramente CAFC (ContingenciaService::registrarManual() sin
 * facturas offline "normales") quedaba reintentándose sin efecto para
 * siempre, porque evecodrecpaq nunca se setea cuando no hay nada que
 * empaquetar ahí.
 */
class EventosSignificativoScopesTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithFacturacion;

    public function test_evento_sin_registrar_necesita_reconciliacion(): void
    {
        $emisor = $this->crearEmisor();
        $evento = $this->crearEventoSignificativo($emisor, ['evecodrec' => null]);

        $this->assertTrue(
            EventosSignificativo::necesitaReconciliacionNormal()->whereKey($evento->eveid)->exists()
        );
    }

    public function test_evento_registrado_con_factura_offline_normal_sin_empaquetar_necesita_reconciliacion(): void
    {
        $emisor = $this->crearEmisor();
        $evento = $this->crearEventoSignificativo($emisor, ['evecodrec' => 'REC-001']);
        $this->crearFactura($emisor, [
            'faceveid' => $evento->eveid, 'facsiatest' => Factura::SIAT_OFFLINE, 'faccafc' => null,
        ]);

        $this->assertTrue(
            EventosSignificativo::necesitaReconciliacionNormal()->whereKey($evento->eveid)->exists()
        );
    }

    public function test_evento_registrado_con_paquete_ya_enviado_no_necesita_reconciliacion(): void
    {
        $emisor = $this->crearEmisor();
        $evento = $this->crearEventoSignificativo($emisor, ['evecodrec' => 'REC-001', 'evecodrecpaq' => 'PAQ-001']);
        $this->crearFactura($emisor, [
            'faceveid' => $evento->eveid, 'facsiatest' => Factura::SIAT_EMPAQUETADA, 'faccafc' => null,
        ]);

        $this->assertFalse(
            EventosSignificativo::necesitaReconciliacionNormal()->whereKey($evento->eveid)->exists()
        );
    }

    public function test_evento_puramente_cafc_ya_registrado_no_necesita_reconciliacion(): void
    {
        // El caso que motivó el fix: registrado, sin facturas offline
        // "normales" (solo CAFC, o ninguna), evecodrecpaq nunca se setea
        // porque no hay nada que empaquetar en este canal.
        $emisor = $this->crearEmisor();
        $evento = $this->crearEventoSignificativo($emisor, ['evecodrec' => 'REC-001', 'evecodrecpaq' => null]);
        $this->crearFactura($emisor, [
            'faceveid' => $evento->eveid, 'facsiatest' => Factura::SIAT_OFFLINE, 'faccafc' => 'CAFC-001',
        ]);

        $this->assertFalse(
            EventosSignificativo::necesitaReconciliacionNormal()->whereKey($evento->eveid)->exists()
        );
    }

    public function test_evento_registrado_sin_ninguna_factura_no_necesita_reconciliacion(): void
    {
        $emisor = $this->crearEmisor();
        $evento = $this->crearEventoSignificativo($emisor, ['evecodrec' => 'REC-001', 'evecodrecpaq' => null]);

        $this->assertFalse(
            EventosSignificativo::necesitaReconciliacionNormal()->whereKey($evento->eveid)->exists()
        );
    }
}

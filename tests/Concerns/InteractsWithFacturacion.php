<?php

namespace Tests\Concerns;

use App\Models\Emisor;
use App\Models\Factura;
use App\Models\MotivoAnulacion;
use App\Models\PuntoVenta;
use App\Models\Secuencia;
use App\Models\SistemaCliente;

/**
 * Fixtures compartidos entre tests de Feature. Nada de esto habla con el
 * SIAT real — son solo filas de base de datos para poder probar la capa
 * de validación/autenticación/autorización/reglas de negocio, que es todo
 * lo que se puede probar sin red.
 */
trait InteractsWithFacturacion
{
    protected function crearEmisor(array $overrides = []): Emisor
    {
        return Emisor::create(array_merge([
            'eminit'    => '3327479013',
            'emirazsoc' => 'EMISOR DE PRUEBA',
            'emimun'    => 'La Paz',
            'emidir'    => 'Calle Falsa 123',
            'emitel'    => '70000000',
            'emisis'    => 'CODIGOSISTEMATEST',
            'emisuc'    => 0,
            'emipdv'    => 0,
            'emitoken'  => 'token-de-prueba-no-real',
            'emimod'    => 2,
            'emiamb'    => 2,
            'emiest'    => true,
        ], $overrides));
    }

    protected function crearPuntoVenta(Emisor $emisor, int $suc = 0, int $pdv = 0, int $secultimo = 0): PuntoVenta
    {
        $pv = PuntoVenta::create([
            'emiid' => $emisor->emiid,
            'pvsuc' => $suc,
            'pvpdv' => $pdv,
            'pvest' => true,
        ]);

        Secuencia::create([
            'emiid'      => $emisor->emiid,
            'secsuc'     => $suc,
            'secpdv'     => $pdv,
            'sectipodoc' => 1,
            'secultimo'  => $secultimo,
        ]);

        return $pv;
    }

    /**
     * @return array{0: SistemaCliente, 1: string} el sistema y su api key en texto plano.
     */
    protected function crearSistemaCliente(string $siscod = 'sistema-test'): array
    {
        $apiKeyPlano = bin2hex(random_bytes(32));

        $sistema = SistemaCliente::create([
            'siscod'    => $siscod,
            'sisnom'    => 'Sistema de prueba',
            'sisapikey' => hash('sha256', $apiKeyPlano),
            'sisest'    => true,
        ]);

        return [$sistema, $apiKeyPlano];
    }

    protected function crearMotivoAnulacion(int $codigo = 1, string $descripcion = 'FACTURA MAL EMITIDA'): MotivoAnulacion
    {
        return MotivoAnulacion::create(['moacod' => $codigo, 'moadesc' => $descripcion]);
    }

    /**
     * Inserta una factura directamente (sin pasar por EmisionService, que
     * llamaría al SIAT real) — útil para probar autorización/anulación
     * sobre una factura ya en un estado conocido.
     */
    protected function crearFactura(Emisor $emisor, array $overrides = []): Factura
    {
        return Factura::create(array_merge([
            'emiid'       => $emisor->emiid,
            'facsuc'      => 0,
            'facpdv'      => 0,
            'facnro'      => random_int(1000, 999999),
            'facfch'      => now()->format('Y-m-d'),
            'fachora'     => now(),
            'facnomrazon' => 'CLIENTE DE PRUEBA',
            'facnumdoc'   => '123',
            'factipodoc'  => 1,
            'facmetpag'   => 1,
            'facmonto'    => 100,
            'facmontoiva' => 100,
            'facdesc'     => 0,
            'facest'      => Factura::ESTADO_VIGENTE,
            'facsiatest'  => Factura::SIAT_ACEPTADA,
            'facsisorig'  => 'sistema-test',
            'faccuf'      => 'CUF-DE-PRUEBA-' . random_int(1000, 999999),
        ], $overrides));
    }
}

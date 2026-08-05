<?php

namespace Tests\Concerns;

use App\Models\Actividad;
use App\Models\Emisor;
use App\Models\EventosSignificativo;
use App\Models\Factura;
use App\Models\MetodoPago;
use App\Models\MotivoAnulacion;
use App\Models\ProductoServicio;
use App\Models\PuntoVenta;
use App\Models\RangoCafc;
use App\Models\Secuencia;
use App\Models\SistemaCliente;
use App\Models\TipoDocumentoIdentidad;
use App\Models\UnidadMedida;

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
     * Catálogos mínimos que EmisionService::validarCatalogos() exige para
     * poder emitir — actividad/producto '620000'/'83131' (los que usan los
     * fixtures de detalle en los tests), unidad de medida 57, método de
     * pago 1, tipo de documento 1. Sin esto, cualquier test que pase por
     * emitir()/emitirCafc() de verdad es rechazado antes de tocar el SIAT.
     */
    protected function crearCatalogosBasicos(Emisor $emisor): void
    {
        Actividad::create([
            'emiid' => $emisor->emiid, 'actcod' => '620000', 'actdesc' => 'Actividad de prueba', 'actest' => true,
        ]);
        ProductoServicio::create([
            'emiid' => $emisor->emiid, 'proactcod' => '620000', 'procodsin' => '83131', 'prodesc' => 'Producto de prueba',
        ]);
        UnidadMedida::firstOrCreate(['unicod' => 57], ['unidesc' => 'UNIDAD (SERVICIOS)']);
        MetodoPago::firstOrCreate(['mpacod' => 1], ['mpadesc' => 'EFECTIVO']);
        TipoDocumentoIdentidad::firstOrCreate(['tdicod' => 1], ['tdidesc' => 'CI - CEDULA DE IDENTIDAD']);
    }

    /**
     * Rango CAFC autorizado que EmisionService::validarRangoCafc() exige
     * para poder transcribir — por defecto cubre el 'numero_factura'/
     * 'codigo_cafc' que usan los fixtures de CAFC en los tests.
     */
    protected function crearRangoCafc(Emisor $emisor, array $overrides = []): RangoCafc
    {
        return RangoCafc::create(array_merge([
            'emiid'     => $emisor->emiid,
            'rcfsuc'    => 0,
            'rcfpdv'    => 0,
            'rcfcafc'   => 'CAFC-TEST',
            'rcfdesde'  => 1,
            'rcfhasta'  => 1000,
            'rcflimite' => now()->addDays(30),
            'rcfest'    => true,
        ], $overrides));
    }

    protected function crearEventoSignificativo(Emisor $emisor, array $overrides = []): EventosSignificativo
    {
        return EventosSignificativo::create(array_merge([
            'emiid'       => $emisor->emiid,
            'evesuc'      => 0,
            'evepdv'      => 0,
            'evecod'      => EventosSignificativo::COD_SIN_INACCESIBLE,
            'evedesc'     => 'SIAT inaccesible (de prueba)',
            'eveini'      => now()->subHours(80),
            'evecufd'     => 'CUFD-DE-PRUEBA',
            'evecufdctrl' => 'CONTROL-DE-PRUEBA',
            'eveest'      => EventosSignificativo::ESTADO_ACTIVO,
        ], $overrides));
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

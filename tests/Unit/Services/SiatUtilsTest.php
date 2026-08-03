<?php

namespace Tests\Unit\Services;

use App\Services\SiatUtils;
use Carbon\Carbon;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * SiatUtils::buildCuf() es el algoritmo compliance-crítico: si se rompe
 * en silencio, todas las facturas emitidas quedan con un CUF inválido.
 * Los valores de referencia acá salieron de correr el código real contra
 * datos fijos, no de la especificación (no tenemos vectores oficiales del
 * SIN) — protegen contra REGRESIONES, no validan el algoritmo en sí (eso
 * ya lo hizo el SIAT real al aceptar facturas de verdad, ver ContingenciaService).
 */
class SiatUtilsTest extends TestCase
{
    public function test_convert_base_10_a_16(): void
    {
        $this->assertSame('FF', SiatUtils::convertBase('255', 10, 16));
        $this->assertSame('0', SiatUtils::convertBase('0', 10, 16));
        $this->assertSame('A', SiatUtils::convertBase('10', 10, 16));
    }

    public function test_to_base_16_siempre_en_mayusculas(): void
    {
        $this->assertSame('FF', SiatUtils::toBase16('255'));
        $this->assertSame('0', SiatUtils::toBase16('0'));
    }

    public function test_modulo_11_es_deterministico(): void
    {
        $base = str_repeat('1', 53);
        $this->assertSame('9', SiatUtils::modulo11($base, 1, 9, false));
        $this->assertSame('9', SiatUtils::modulo11($base, 1, 9, false), 'debe dar el mismo resultado siempre para la misma entrada');
    }

    public function test_modulo_11_devuelve_un_solo_digito(): void
    {
        // Si el resto fuera 10, el dígito debe ser "1" (regla especial),
        // nunca "10" de dos caracteres.
        foreach (['111', '999999999', '123456789012345'] as $entrada) {
            $digito = SiatUtils::modulo11($entrada, 1, 9, false);
            $this->assertSame(1, strlen($digito));
            $this->assertMatchesRegularExpression('/^[0-9]$/', $digito);
        }
    }

    public function test_build_cuf_produce_el_valor_esperado_para_datos_fijos(): void
    {
        $fecha = Carbon::create(2026, 8, 3, 10, 30, 0, 0);

        $cuf = SiatUtils::buildCuf('ABCDEF1234567', $fecha, [
            'nit'          => '3327479013',
            'branch'       => 0,
            'modality'     => 2,
            'emissionType' => 1,
            'invoiceType'  => 1,
            'sectorDoc'    => 1,
            'number'       => 42,
            'pos'          => 0,
        ]);

        $this->assertSame('E3ACE3F0A8D8AD3359D2A58728101019917DC49643ABCDEF1234567', $cuf);
    }

    public function test_build_cuf_termina_con_el_codigo_de_control_literal(): void
    {
        $fecha = Carbon::now();

        $cuf = SiatUtils::buildCuf('MICODIGOCONTROL', $fecha, [
            'nit' => '3327479013', 'branch' => 0, 'modality' => 2,
            'emissionType' => 1, 'invoiceType' => 1, 'sectorDoc' => 1,
            'number' => 1, 'pos' => 0,
        ]);

        $this->assertStringEndsWith('MICODIGOCONTROL', $cuf);
    }

    public function test_build_cuf_cambia_si_cambia_el_numero_de_factura(): void
    {
        $fecha = Carbon::create(2026, 8, 3, 10, 30, 0, 0);
        $datosBase = [
            'nit' => '3327479013', 'branch' => 0, 'modality' => 2,
            'emissionType' => 1, 'invoiceType' => 1, 'sectorDoc' => 1, 'pos' => 0,
        ];

        $cuf1 = SiatUtils::buildCuf('ABC', $fecha, [...$datosBase, 'number' => 1]);
        $cuf2 = SiatUtils::buildCuf('ABC', $fecha, [...$datosBase, 'number' => 2]);

        $this->assertNotSame($cuf1, $cuf2);
    }

    public function test_build_cuf_rechaza_codigo_de_control_vacio(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('El CUFD activo no tiene código de control');

        SiatUtils::buildCuf('', Carbon::now(), [
            'nit' => '3327479013', 'branch' => 0, 'modality' => 2,
            'emissionType' => 1, 'invoiceType' => 1, 'sectorDoc' => 1,
            'number' => 1, 'pos' => 0,
        ]);
    }

    public function test_build_cuf_rechaza_nit_con_caracteres_no_numericos(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('no numéricos');

        SiatUtils::buildCuf('ABC', Carbon::now(), [
            'nit' => 'NIT-INVALIDO', 'branch' => 0, 'modality' => 2,
            'emissionType' => 1, 'invoiceType' => 1, 'sectorDoc' => 1,
            'number' => 1, 'pos' => 0,
        ]);
    }
}

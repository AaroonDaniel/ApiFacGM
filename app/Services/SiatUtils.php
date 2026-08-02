<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Exception;

class SiatUtils
{
    /**
     * Algoritmo Módulo 11 para el dígito verificador del CUF.
     * Para el CUF se invoca con: numDig=1, limMult=9, x10=false.
     *
     * OJO: el verificador es (suma % 11) DIRECTO, no 11 - (suma % 11).
     * Si el resto es 10, el dígito es "1". Validado contra el SIAT real.
     */
    public static function modulo11(string $str, int $numDig, int $limMult, bool $x10): string
    {
        $mult = 2;
        $sum  = 0;

        for ($i = strlen($str) - 1; $i >= 0; $i--) {
            $sum += ((int) $str[$i]) * $mult;
            $mult++;
            if ($mult > $limMult) {
                $mult = 2;
            }
        }

        if ($x10) {
            $dig = (($sum * 10) % 11) % 10;
            return (string) $dig;
        }

        $resto = $sum % 11;

        if ($resto === 10) {
            return '1';
        }

        return (string) $resto;
    }

    /**
     * Convierte una cadena numérica (base 10) a Base 16 en mayúsculas.
     */
    public static function toBase16(string $numberStr): string
    {
        return strtoupper(self::convertBase($numberStr, 10, 16));
    }

    /**
     * Construye el CUF a partir de los datos del emisor y la factura.
     *
     * Cadena base (53 dígitos):
     *  NIT (13) + Fecha/hora (17) + Sucursal (4) + Modalidad (1) +
     *  Tipo emisión (1) + Tipo factura (1) + Doc. sector (2) +
     *  Nº factura (10) + Punto de venta (4)
     *
     * Sobre esos 53 dígitos: verificador Módulo 11, se concatena,
     * se convierte a Base 16 y se anexa el codigoControl del CUFD.
     *
     * @param string          $controlCode  codigoControl del CUFD vigente
     * @param CarbonInterface  $issuedAt     Fecha/hora real de emisión
     * @param array            $datos        nit, branch, modality, emissionType,
     *                                        invoiceType, sectorDoc, number, pos
     */
    public static function buildCuf(string $controlCode, CarbonInterface $issuedAt, array $datos): string
    {
        $controlCode = trim($controlCode);
        if ($controlCode === '') {
            throw new Exception('El CUFD activo no tiene código de control; no se puede generar el CUF.');
        }

        $nit          = str_pad((string) $datos['nit'], 13, '0', STR_PAD_LEFT);
        $date         = $issuedAt->format('YmdHisv'); // 17 dígitos (con milisegundos)
        $branch       = str_pad((string) $datos['branch'], 4, '0', STR_PAD_LEFT);
        $modality     = (string) ($datos['modality'] ?? 2);
        $emissionType = (string) ($datos['emissionType'] ?? 1);
        $invoiceType  = (string) ($datos['invoiceType'] ?? 1);
        $sectorDoc    = str_pad((string) ($datos['sectorDoc'] ?? 1), 2, '0', STR_PAD_LEFT);
        $number       = str_pad((string) $datos['number'], 10, '0', STR_PAD_LEFT);
        $pos          = str_pad((string) ($datos['pos'] ?? 0), 4, '0', STR_PAD_LEFT);

        $base = $nit . $date . $branch . $modality . $emissionType
            . $invoiceType . $sectorDoc . $number . $pos;

        if (strlen($base) !== 53) {
            throw new Exception(
                'Cadena base del CUF inválida: se esperaban 53 dígitos, se obtuvieron '
                    . strlen($base) . " ({$base})"
            );
        }

        if (!ctype_digit($base)) {
            throw new Exception('La cadena base del CUF contiene caracteres no numéricos.');
        }

        $verifier = self::modulo11($base, 1, 9, false);
        $cufHex   = self::toBase16($base . $verifier);

        return $cufHex . $controlCode;
    }

    /**
     * Convierte un número entre bases usando BC Math (soporta enteros muy grandes).
     */
    public static function convertBase(string $number, int $fromBase, int $toBase): string
    {
        $chars  = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $base10 = '0';

        $length = strlen($number);
        for ($i = 0; $i < $length; $i++) {
            $pos = strpos($chars, $number[$i]);
            if ($pos === false) {
                throw new Exception("Carácter inválido '{$number[$i]}' para base {$fromBase}.");
            }
            $base10 = bcadd(bcmul($base10, (string) $fromBase), (string) $pos);
        }

        if (bccomp($base10, '0') === 0) {
            return '0';
        }

        $result = '';
        while (bccomp($base10, '0') > 0) {
            $modIndex = (int) bcmod($base10, (string) $toBase);
            $result   = $chars[$modIndex] . $result;
            $base10   = bcdiv($base10, (string) $toBase, 0);
        }

        return $result;
    }
}
<?php

namespace App\Services;

use Exception;
use PharData;

/**
 * Empaqueta varios XML de facturas offline en un único .tar.gz, con el
 * formato que exige el SIAT: un archivo por factura dentro del .tar,
 * nombrado "<cuf>.xml".
 */
class SiatOfflinePackageBuilder
{
    /**
     * @param array<int, array{cuf: string, xml: string}> $facturas
     * @return array{archivo: string, hash: string, cantidad: int}
     *         'archivo' es binario CRUDO (sin base64) — SiatService/SoapClient
     *         lo codifica solo, igual que con el XML de una factura individual.
     *         'hash' se calcula sobre el .tar SIN comprimir, para igualar el
     *         patrón de receiveInvoice() (que hashea el XML sin comprimir,
     *         no el gzip que se envía).
     */
    public function construir(array $facturas): array
    {
        if (empty($facturas)) {
            throw new Exception('No hay facturas para empaquetar.');
        }

        $stamp   = now()->format('Ymd_His');
        $tarName = "paquete_offline_{$stamp}.tar";
        $tmpDir  = storage_path("app/siat/tmp_{$stamp}");
        $tarPath = "{$tmpDir}/{$tarName}";

        if (!is_dir($tmpDir) && !mkdir($tmpDir, 0755, true) && !is_dir($tmpDir)) {
            throw new Exception("No se pudo crear el directorio temporal {$tmpDir}.");
        }

        try {
            $tar = new PharData($tarPath, 0, null, \Phar::TAR);
            foreach ($facturas as $factura) {
                if (empty($factura['cuf']) || empty($factura['xml'])) {
                    throw new Exception('Cada factura offline debe traer cuf y xml.');
                }
                $tar->addFromString("{$factura['cuf']}.xml", $factura['xml']);
            }
            unset($tar);

            if (!file_exists($tarPath)) {
                throw new Exception('No se generó el archivo .tar del paquete.');
            }

            $tarBinario = file_get_contents($tarPath);
            $binary     = gzencode($tarBinario, 9);

            return [
                'archivo'  => $binary,
                'hash'     => hash('sha256', $tarBinario),
                'cantidad' => count($facturas),
            ];
        } finally {
            if (file_exists($tarPath)) {
                @unlink($tarPath);
            }
            if (is_dir($tmpDir)) {
                @rmdir($tmpDir);
            }
        }
    }
}

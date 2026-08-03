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
     * @return array{archivo: string, hash: string, cantidad: int} archivo en base64
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
        $gzPath  = "{$tarPath}.gz";

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

            $tar->compress(\Phar::GZ);
            unset($tar);
            @unlink($tarPath);

            if (!file_exists($gzPath)) {
                throw new Exception('No se generó el archivo .tar.gz del paquete.');
            }

            $binary = file_get_contents($gzPath);

            return [
                'archivo'  => base64_encode($binary),
                'hash'     => hash('sha256', $binary),
                'cantidad' => count($facturas),
            ];
        } finally {
            if (file_exists($gzPath)) {
                @unlink($gzPath);
            }
            if (file_exists($tarPath)) {
                @unlink($tarPath);
            }
            if (is_dir($tmpDir)) {
                @rmdir($tmpDir);
            }
        }
    }
}

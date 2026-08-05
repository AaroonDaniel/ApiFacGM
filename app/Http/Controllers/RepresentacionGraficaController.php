<?php

namespace App\Http\Controllers;

use App\Models\Factura;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\View\View;

/**
 * Vista web pública de la Representación Gráfica de una factura — la
 * respuesta al Art. 26.I.b) de la RND 102100000011 (enviar al comprador el
 * XML Y su Representación Gráfica) sin tener que generar PDF en el
 * backend: se manda el XML adjunto por correo (ver FacturaAceptadaMail) +
 * un link a esta página, que el navegador del usuario puede imprimir o
 * "Guardar como PDF" por su cuenta.
 *
 * Pública a propósito (sin sistema.auth): quien recibe la factura por
 * correo no tiene una api key. El CUF ya es efectivamente el token de
 * acceso — es una cadena larga con verificador Módulo 11, no adivinable,
 * el mismo criterio que usa el propio SIN para su validador público por
 * QR (que ya expone el cuf en la URL).
 */
class RepresentacionGraficaController extends Controller
{
    public function show(string $cuf): View
    {
        $factura = Factura::where('faccuf', $cuf)->with(['detalles', 'emisor'])->firstOrFail();

        $qrSvg = (new Builder(
            writer: new SvgWriter(),
            data: $factura->qrUrl(),
            size: 220,
            margin: 5,
        ))->build()->getString();

        return view('factura.representacion-grafica', [
            'factura' => $factura,
            'qrSvg'   => $qrSvg,
            'leyendas' => $factura->leyendas(),
        ]);
    }
}

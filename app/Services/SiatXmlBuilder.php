<?php

namespace App\Services;

use App\Models\Factura;
use App\Models\Emisor;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use DOMDocument;
use DOMElement;
use Exception;

/**
 * SiatXmlBuilder
 *
 * Construye el XML de la Factura Electrónica Computarizada
 * (Sector 1 - Compra Venta) según el XSD del SIAT.
 *
 * DES-HOTELIZADO: recibe una Factura genérica de la pasarela y su Emisor.
 * Todos los datos del comprador salen de la propia factura (congelados);
 * los del emisor, de la fila `emisores`. No hay Guest ni Checkin.
 */
class SiatXmlBuilder
{
    public const EMISION_ONLINE  = 1;
    public const EMISION_OFFLINE = 2;

    protected Factura $factura;
    protected Emisor $emisor;
    protected string $controlCode;    // codigoControl del CUFD
    protected string $cufdCode;       // el CUFD en sí (para el nodo <cufd>)
    protected CarbonInterface $issuedAt;
    protected DOMDocument $xmlDocument;
    protected int $emissionType;

    /**
     * @param Factura  $factura      Factura ya persistida con sus detalles.
     * @param Emisor   $emisor       Emisor dueño (NIT y datos fiscales).
     * @param string   $cufdCode     Código CUFD vigente.
     * @param string   $controlCode  codigoControl del CUFD.
     * @param int      $emissionType EMISION_ONLINE | EMISION_OFFLINE
     */
    public function __construct(
        Factura $factura,
        Emisor $emisor,
        string $cufdCode,
        string $controlCode,
        int $emissionType = self::EMISION_ONLINE
    ) {
        $this->factura      = $factura;
        $this->emisor       = $emisor;
        $this->cufdCode     = $cufdCode;
        $this->controlCode  = $controlCode;
        $this->emissionType = $this->normalizeEmissionType($emissionType);

        // Fecha/hora real de la factura. Un solo instante para CUF, fechaEmision y fechaEnvio.
        $this->issuedAt = $factura->fachora
            ? Carbon::parse($factura->fachora)
            : Carbon::now();

        $this->xmlDocument = new DOMDocument('1.0', 'UTF-8');
        $this->xmlDocument->formatOutput = true;
    }

    private function normalizeEmissionType(int $type): int
    {
        if (!in_array($type, [self::EMISION_ONLINE, self::EMISION_OFFLINE], true)) {
            throw new Exception("Tipo de emisión inválido: {$type}. Use 1 (online) o 2 (offline).");
        }
        return $type;
    }

    // =========================================================
    // 1. GENERACIÓN DEL CUF
    // =========================================================

    public function generateCuf(): string
    {
        return SiatUtils::buildCuf($this->controlCode, $this->issuedAt, [
            'nit'          => $this->emisor->eminit,
            'branch'       => $this->factura->facsuc,
            'modality'     => $this->emisor->emimod,
            'emissionType' => $this->emissionType,
            'invoiceType'  => 1, // 1 = Factura con Derecho a Crédito Fiscal
            'sectorDoc'    => 1, // 01 = Compra Venta
            'number'       => $this->factura->facnro,
            'pos'          => $this->factura->facpdv,
        ]);
    }

    // =========================================================
    // 2. CONSTRUCCIÓN DEL XML
    // =========================================================

    public function buildXml(): string
    {
        $this->xmlDocument = new DOMDocument('1.0', 'UTF-8');
        $this->xmlDocument->formatOutput = true;

        $root = $this->xmlDocument->createElement('facturaComputarizadaCompraVenta');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $root->setAttribute('xsi:noNamespaceSchemaLocation', 'facturaComputarizadaCompraVenta.xsd');
        $this->xmlDocument->appendChild($root);

        $root->appendChild($this->buildHeader());

        if ($this->factura->detalles->isEmpty()) {
            throw new Exception("La factura #{$this->factura->facnro} no tiene detalles; el XML sería inválido.");
        }

        foreach ($this->factura->detalles as $item) {
            $root->appendChild($this->buildDetail($item));
        }

        return $this->xmlDocument->saveXML();
    }

    /**
     * Nodo <cabecera>. Orden estricto según XSD.
     */
    protected function buildHeader(): DOMElement
    {
        $header = $this->xmlDocument->createElement('cabecera');
        $e = $this->emisor;
        $f = $this->factura;

        // --- Emisor (de la tabla emisores) ---
        $this->appendText($header, 'nitEmisor', (string) $e->eminit);
        $this->appendText($header, 'razonSocialEmisor', $e->emirazsoc);
        $this->appendText($header, 'municipio', $e->emimun);
        $this->appendText($header, 'telefono', (string) ($e->emitel ?? ''));
        $this->appendText($header, 'numeroFactura', (string) $f->facnro);
        $this->appendText($header, 'cuf', $this->generateCuf());
        $this->appendText($header, 'cufd', $this->cufdCode);
        $this->appendText($header, 'codigoSucursal', (string) $f->facsuc);
        $this->appendText($header, 'direccion', $e->emidir);

        // Punto de venta: nil si es 0 (casa matriz).
        if ((int) $f->facpdv === 0) {
            $this->appendNil($header, 'codigoPuntoVenta');
        } else {
            $this->appendText($header, 'codigoPuntoVenta', (string) $f->facpdv);
        }

        $this->appendText($header, 'fechaEmision', $this->issuedAt->format('Y-m-d\TH:i:s.v'));

        // --- Comprador (de la factura, congelado) ---
        $this->appendText($header, 'nombreRazonSocial', $f->facnomrazon);
        $this->appendText($header, 'codigoTipoDocumentoIdentidad', (string) $f->factipodoc);
        $this->appendText($header, 'numeroDocumento', $f->facnumdoc);

        if (!empty($f->faccompl)) {
            $this->appendText($header, 'complemento', $f->faccompl);
        } else {
            $this->appendNil($header, 'complemento');
        }

        // codigoCliente = documento del comprador (confirmado con facturas reales).
        $this->appendText($header, 'codigoCliente', $f->facnumdoc);

        // --- Pago ---
        $this->appendText($header, 'codigoMetodoPago', (string) $f->facmetpag);

        if ((int) $f->facmetpag === 2 && !empty($f->facnrotarj)) {
            $this->appendText($header, 'numeroTarjeta', $f->facnrotarj);
        } else {
            $this->appendNil($header, 'numeroTarjeta');
        }

        // --- Montos ---
        $this->appendText($header, 'montoTotal', $this->money($f->facmonto));
        $this->appendText($header, 'montoTotalSujetoIva', $this->money($f->facmontoiva));
        $this->appendText($header, 'codigoMoneda', '1');
        $this->appendText($header, 'tipoCambio', '1');
        $this->appendText($header, 'montoTotalMoneda', $this->money($f->facmonto));
        $this->appendNil($header, 'montoGiftCard');
        $this->appendText($header, 'descuentoAdicional', $this->money($f->facdesc ?? 0));
        $this->appendText($header, 'codigoExcepcion', '0');
        $this->appendNil($header, 'cafc');

        $this->appendText(
            $header,
            'leyenda',
            config('siat.leyenda_defecto', 'Ley N° 453: El proveedor deberá entregar el producto en las modalidades y términos ofertados.')
        );

        // Usuario: por ahora la razón social del emisor (la pasarela no tiene operadores aún).
        $this->appendText($header, 'usuario', $e->emirazsoc);
        $this->appendText($header, 'codigoDocumentoSector', '1');

        return $header;
    }

    protected function buildDetail($item): DOMElement
    {
        $detail = $this->xmlDocument->createElement('detalle');

        // Actividad, producto SIN y unidad salen de CADA LÍNEA (factura_detalles).
        $this->appendText($detail, 'actividadEconomica', (string) $item->facdetacteco);
        $this->appendText($detail, 'codigoProductoSin', (string) $item->facdetprodsin);
        $this->appendText($detail, 'codigoProducto', (string) $item->facdetcodprod);
        $this->appendText($detail, 'descripcion', $item->facdetdesc);
        $this->appendText($detail, 'cantidad', $this->money($item->facdetcnt));
        $this->appendText($detail, 'unidadMedida', (string) $item->facdetunimed);
        $this->appendText($detail, 'precioUnitario', $this->money($item->facdetprc));

        if (!empty($item->facdetdscto) && $item->facdetdscto > 0) {
            $this->appendText($detail, 'montoDescuento', $this->money($item->facdetdscto));
        } else {
            $this->appendNil($detail, 'montoDescuento');
        }

        $this->appendText($detail, 'subTotal', $this->money($item->facdetsub));
        $this->appendNil($detail, 'numeroSerie');
        $this->appendNil($detail, 'numeroImei');

        return $detail;
    }

    // =========================================================
    // 3. HELPERS
    // =========================================================

    protected function money($value): string
    {
        return number_format((float) $value, 2, '.', '');
    }

    protected function appendText(DOMElement $parent, string $tag, string $value): void
    {
        $parent->appendChild(
            $this->xmlDocument->createElement($tag, htmlspecialchars($value, ENT_XML1))
        );
    }

    protected function appendNil(DOMElement $parent, string $tag): void
    {
        $node = $this->xmlDocument->createElement($tag);
        $node->setAttribute('xsi:nil', 'true');
        $parent->appendChild($node);
    }

    // =========================================================
    // 4. SALIDA
    // =========================================================

    public function getRawXml(): string
    {
        return $this->buildXml();
    }

    public function getGzipArchive(): string
    {
        return gzencode($this->buildXml(), 9);
    }
}
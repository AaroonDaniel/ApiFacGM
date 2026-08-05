<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="robots" content="noindex, nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Factura N&deg; {{ $factura->facnro }} - {{ $factura->emisor->emirazsoc }}</title>
    <style>
        /* RND: mínimo 8pt para datos tributarios obligatorios, prohibida
           la cursiva en los datos clave de facturación — todo el body
           respeta esto; nada de font-style: italic en ningún lado. */
        * { box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            color: #111;
            max-width: 720px;
            margin: 20px auto;
            padding: 0 16px;
        }
        h1 { font-size: 13pt; margin-bottom: 4px; }
        .emisor-datos, .factura-datos { font-size: 9pt; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 9pt; }
        th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        th { background: #f2f2f2; }
        .totales { text-align: right; font-size: 10pt; font-weight: bold; margin-top: 8px; }
        .qr-bloque { text-align: center; margin: 24px 0; }
        /* Tamaño físico fijo (no en px): la inspección de la Fase 2 mide
           el QR impreso con regla — 3.5cm de lado asegura que quede
           dentro del rango legible/escaneable exigido, sin depender de la
           resolución de pantalla ni del tamaño intrínseco del SVG. Al ser
           vectorial, escala sin pérdida de nitidez. */
        .qr-bloque svg { background: #fff; width: 3.5cm; height: 3.5cm; }
        .leyendas p { font-size: 8pt; color: #333; margin: 4px 0; }
        .estado-banner {
            font-size: 9pt;
            font-weight: bold;
            padding: 6px 10px;
            margin-bottom: 12px;
            border-radius: 3px;
        }
        .estado-anulada { background: #fde2e2; color: #8a1f1f; }
        .estado-rechazada { background: #fde2e2; color: #8a1f1f; }
        @media print {
            body { margin: 0; }
        }
    </style>
</head>
<body>
    @if ($factura->facest === \App\Models\Factura::ESTADO_ANULADA)
        <div class="estado-banner estado-anulada">FACTURA ANULADA</div>
    @elseif ($factura->facsiatest === \App\Models\Factura::SIAT_RECHAZADA)
        <div class="estado-banner estado-rechazada">FACTURA RECHAZADA POR EL SIN</div>
    @endif

    <h1>{{ $factura->emisor->emirazsoc }}</h1>
    <div class="emisor-datos">
        NIT: {{ $factura->emisor->eminit }}<br>
        {{ $factura->emisor->emidir }}, {{ $factura->emisor->emimun }}<br>
        @if ($factura->emisor->emitel)
            Tel: {{ $factura->emisor->emitel }}<br>
        @endif
    </div>

    <hr>

    <div class="factura-datos">
        <strong>FACTURA N&deg; {{ $factura->facnro }}</strong><br>
        CUF: {{ $factura->faccuf }}<br>
        CUFD: {{ $factura->faccufd }}<br>
        Fecha de emisi&oacute;n: {{ $factura->fachora?->format('d/m/Y H:i:s') }}<br>
        <br>
        Cliente: {{ $factura->facnomrazon }}<br>
        NIT/CI: {{ $factura->facnumdoc }}@if ($factura->faccompl)-{{ $factura->faccompl }}@endif
    </div>

    <table>
        <thead>
            <tr>
                <th>Descripci&oacute;n</th>
                <th>Cantidad</th>
                <th>Precio unitario</th>
                <th>Subtotal</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($factura->detalles as $item)
                <tr>
                    <td>{{ $item->facdetdesc }}</td>
                    <td>{{ number_format((float) $item->facdetcnt, 2) }}</td>
                    <td>{{ number_format((float) $item->facdetprc, 2) }}</td>
                    <td>{{ number_format((float) $item->facdetsub, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="totales">
        TOTAL: Bs {{ number_format((float) $factura->facmonto, 2) }}
    </div>

    <div class="qr-bloque">
        {!! $qrSvg !!}
    </div>

    <div class="leyendas">
        @foreach ($leyendas as $leyenda)
            <p>{{ $leyenda }}</p>
        @endforeach
    </div>
</body>
</html>

<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; color: #222;">
    <h2>Factura N&deg; {{ $factura->facnro }}</h2>

    <p>Hola {{ $factura->facnomrazon }}, adjuntamos el XML de tu factura electr&oacute;nica.</p>

    <ul>
        <li><strong>CUF:</strong> {{ $factura->faccuf }}</li>
        <li><strong>Fecha de emisi&oacute;n:</strong> {{ $factura->fachora?->format('d/m/Y H:i') }}</li>
        <li><strong>Monto total:</strong> Bs {{ number_format((float) $factura->facmonto, 2) }}</li>
    </ul>

    <p>
        <a href="{{ $factura->qrUrl() }}">Verificar factura en el SIN</a>
    </p>

    @foreach ($factura->leyendas() as $leyenda)
        <p style="font-size: 12px; color: #555;">{{ $leyenda }}</p>
    @endforeach

    <p>Gracias,<br>{{ $factura->emisor->emirazsoc }}</p>
</body>
</html>

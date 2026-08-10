<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; color: #222;">
    <h2 style="color: #b00020;">⚠ Plazo de contingencia superado</h2>

    <p>
        El evento significativo <strong>#{{ $evento->eveid }}</strong> del emisor
        <strong>{{ $evento->emisor->emirazsoc }}</strong> (NIT {{ $evento->emisor->eminit }},
        sucursal {{ $evento->evesuc }}, PDV {{ $evento->evepdv }}) superó las
        <strong>{{ $limiteHoras }} horas</strong> permitidas para terminar de enviar su
        paquete {{ $tipoPlazo === 'cafc' ? 'CAFC (facturas manuales transcritas)' : 'offline' }} al SIAT.
    </p>

    <ul>
        <li><strong>Motivo del evento:</strong> {{ $evento->evedesc }}</li>
        <li><strong>Inicio de la contingencia:</strong> {{ $evento->eveini?->format('d/m/Y H:i') }}</li>
        <li><strong>Cierre/registro del evento:</strong> {{ $evento->evefin?->format('d/m/Y H:i') ?? 'aún no registrado' }}</li>
    </ul>

    <p>
        Esto requiere <strong>intervención manual</strong>: revisar por qué el paquete no se terminó
        de enviar/validar y regularizar la situación ante el SIN cuanto antes para evitar sanciones.
    </p>
</body>
</html>

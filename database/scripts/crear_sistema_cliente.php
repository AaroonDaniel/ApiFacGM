<?php

// Script de una sola vez para crear un sistema cliente y generar su API key.
// Se corre DENTRO de tinker (ver instrucciones).
//
// Rellena 'siscod' y 'sisnom' con tus datos antes de correrlo. El API key
// se genera automáticamente y SOLO se muestra una vez en pantalla: el
// sistema cliente debe guardarlo, porque en la base solo se guarda su hash
// (no se puede recuperar después).

$siscod = 'TU-CODIGO-DE-SISTEMA'; // identificador corto único, ej. "erp-principal"
$sisnom = 'TU-NOMBRE-DE-SISTEMA'; // nombre descriptivo

$apiKeyPlano = bin2hex(random_bytes(32)); // 64 caracteres hex

$sistema = App\Models\SistemaCliente::updateOrCreate(
    ['siscod' => $siscod],
    [
        'sisnom'    => $sisnom,
        'sisapikey' => hash('sha256', $apiKeyPlano),
        'sisest'    => true,
    ]
);

echo "Sistema cliente listo (sisid={$sistema->sisid}, siscod={$sistema->siscod})" . PHP_EOL;
echo "API KEY (guárdala ahora, no se puede volver a ver):" . PHP_EOL;
echo $apiKeyPlano . PHP_EOL;

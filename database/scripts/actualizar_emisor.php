<?php

// Script de una sola vez para crear o actualizar TODOS los datos de un
// emisor. Se corre DENTRO de tinker (ver instrucciones).
//
// Rellena los valores de abajo con tus datos reales antes de correrlo.
// Busca el emisor por 'eminit': si existe, lo actualiza; si no, lo crea.

$datos = [
    'eminit'    => 'TU-NIT-AQUI',       // NIT del emisor
    'emirazsoc' => 'TU-RAZON-SOCIAL',   // Razón social
    'emimun'    => 'TU-MUNICIPIO',      // Municipio
    'emidir'    => 'TU-DIRECCION',      // Dirección
    'emitel'    => 'TU-TELEFONO',       // Teléfono
    'emisis'    => 'TU-CODIGO-SISTEMA', // Código de sistema (alfanumérico, con comillas)
    'emisuc'    => 0,                    // Código de sucursal (0 = casa matriz)
    'emipdv'    => 0,                    // Código de punto de venta
    'emitoken'  => 'TU-TOKEN-AQUI',      // Token real de la API del SIAT
    'emimod'    => 2,                    // 1 = electrónica, 2 = computarizada
    'emiamb'    => 2,                    // 1 = producción, 2 = piloto

    // Dueño exclusivo (opcional). null = libre, cualquier sistema
    // autenticado puede facturar con este emisor (útil para pruebas).
    // Si le pones el sisid de un sistema (ver `sistemas_cliente`), la API
    // rechaza con 403 a cualquier OTRO sistema que intente usarlo.
    'sisid'     => null,
];

$emisor = App\Models\Emisor::updateOrCreate(
    ['eminit' => $datos['eminit']],
    collect($datos)->except('eminit')->all()
);

echo ($emisor->wasRecentlyCreated ? "Emisor creado" : "Emisor actualizado")
    . " (emiid={$emisor->emiid}, eminit={$emisor->eminit}, emisis={$emisor->emisis}, emiamb={$emisor->emiamb})"
    . PHP_EOL;

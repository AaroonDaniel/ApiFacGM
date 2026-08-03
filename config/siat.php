<?php

use App\Models\Emisor;

return [

    /*
    |--------------------------------------------------------------------------
    | URLs base del SIAT por ambiente
    |--------------------------------------------------------------------------
    |
    | Indexado por Emisor::AMBIENTE_PRODUCCION (1) y Emisor::AMBIENTE_PILOTO (2).
    | Cada emisor trae su propio 'emiamb'; SiatService elige la URL según eso.
    |
    */
    'ambiente_urls' => [
        Emisor::AMBIENTE_PRODUCCION => env('SIAT_URL_PRODUCCION', 'https://siat.impuestos.gob.bo/v2/'),
        Emisor::AMBIENTE_PILOTO     => env('SIAT_URL_PILOTO', 'https://pilotosiat.impuestos.gob.bo/v2/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeout de conexión (segundos)
    |--------------------------------------------------------------------------
    */
    'timeout' => env('SIAT_TIMEOUT', 10),

    /*
    |--------------------------------------------------------------------------
    | Verificación SSL
    |--------------------------------------------------------------------------
    |
    | Desactivar solo en desarrollo si el WSDL del piloto da problemas de
    | certificado. En producción debe quedar en true.
    |
    */
    'verify_ssl' => env('SIAT_VERIFY_SSL', true),

    /*
    |--------------------------------------------------------------------------
    | Caché de WSDL
    |--------------------------------------------------------------------------
    */
    'wsdl_cache_dir' => storage_path('app/siat/wsdl'),
    'wsdl_cache_ttl' => env('SIAT_WSDL_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | Leyenda por defecto para el XML
    |--------------------------------------------------------------------------
    */
    'leyenda_defecto' => env(
        'SIAT_LEYENDA_DEFECTO',
        'Ley N° 453: El proveedor deberá entregar el producto en las modalidades y términos ofertados.'
    ),

];

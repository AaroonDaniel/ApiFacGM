<?php

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    /*
    | En producción, ningún origen queda permitido por defecto — hay que
    | listarlos explícitamente en CORS_ALLOWED_ORIGINS (separados por coma),
    | ej: https://app.tucliente.com,https://otro-sistema.com
    */
    'allowed_origins' => array_values(array_filter(
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))
    )),

    /*
    | En local, cualquier puerto de localhost/127.0.0.1 queda permitido
    | automáticamente (para probar con otro sistema corriendo en local).
    */
    'allowed_origins_patterns' => env('APP_ENV', 'production') === 'local'
        ? ['#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#']
        : [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];

<?php

namespace App\Http\Middleware;

use App\Models\SistemaCliente;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica al sistema cliente que llama a la API (des-hotelizado: puede
 * ser cualquier sistema externo, no solo el hotel). Se identifica con un
 * API key en el header Authorization: Bearer <key>.
 *
 * El key se guarda hasheado (sha256) en sisapikey; nunca se compara en
 * texto plano ni se puede recuperar el original.
 */
class AutenticarSistemaCliente
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->bearerToken();

        if (!$key) {
            return response()->json([
                'exito' => false,
                'error' => 'Falta el header Authorization: Bearer <api_key>.',
            ], 401);
        }

        $sistema = SistemaCliente::where('sisapikey', hash('sha256', $key))
            ->where('sisest', true)
            ->first();

        if (!$sistema) {
            return response()->json([
                'exito' => false,
                'error' => 'API key inválida o sistema inactivo.',
            ], 401);
        }

        $request->attributes->set('sistemaCliente', $sistema);

        return $next($request);
    }
}

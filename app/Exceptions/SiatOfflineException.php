<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza ÚNICAMENTE cuando el SIAT es genuinamente inalcanzable: un fallo
 * de red o un SoapFault de conexión comprobado (ver SiatService::isNetworkFailure).
 *
 * Es la única condición que autoriza a EmisionService a marcar una factura
 * como 'offline' y acoplarla a un Evento Significativo. Cualquier otra
 * excepción (datos faltantes, fallos de SiatXmlBuilder, credenciales mal
 * configuradas) debe abortar y registrarse como error, no como offline.
 */
class SiatOfflineException extends RuntimeException
{
}

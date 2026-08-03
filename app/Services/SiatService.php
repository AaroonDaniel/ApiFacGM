<?php

namespace App\Services;

use App\Models\Emisor;
use App\Models\SiatCodigo;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use SoapClient;
use SoapFault;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SiatService
{
    protected Emisor $emisor;

    protected string $token;
    protected string $systemCode;
    protected string $nit;
    protected int $branchCode;
    protected int $posCode;
    protected int $environment;
    protected int $modality;

    protected string $baseUrl;
    protected int $timeout;

    /**
     * Se construye POR EMISOR. Las credenciales salen de la fila `emisores`,
     * no de config/siat.php.
     */
    public function __construct(Emisor $emisor)
    {
        $this->emisor      = $emisor;

        $this->token       = $emisor->emitoken;   // descifrado automáticamente por el cast
        $this->systemCode  = $emisor->emisis;
        $this->nit         = $emisor->eminit;
        $this->branchCode  = $emisor->emisuc;
        $this->posCode     = $emisor->emipdv;
        $this->environment = $emisor->emiamb;
        $this->modality    = $emisor->emimod;

        $this->timeout = (int) config('siat.timeout', 10);

        // URL base según el ambiente del emisor (global, de config).
        $urls = config('siat.ambiente_urls');
        $this->baseUrl = $urls[$this->environment] ?? $urls[2];
    }

    /**
     * Crea el cliente SOAP. Pre-descarga el WSDL a disco para resolver
     * SSL/apikey/timeout, y el SoapClient solo lee un archivo local.
     */
    private function getSoapClient(string $endpoint): SoapClient
    {
        $url       = $this->baseUrl . $endpoint . '?wsdl';
        $verifySsl = (bool) config('siat.verify_ssl', true);

        $cacheDir = config('siat.wsdl_cache_dir', storage_path('app/siat/wsdl'));
        $ttl      = (int) config('siat.wsdl_cache_ttl', 3600);

        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
            throw new SoapFault('CLIENT', "No se pudo crear el directorio de caché WSDL: {$cacheDir}");
        }

        // El WSDL se cachea por emisor+endpoint (el token va en la descarga).
        $wsdlFile   = $cacheDir . DIRECTORY_SEPARATOR . md5($url . $this->token) . '.wsdl';
        $needsFetch = !file_exists($wsdlFile) || (time() - filemtime($wsdlFile)) > $ttl;

        if ($needsFetch) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['apikey: TokenApi ' . $this->token],
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_TIMEOUT        => $this->timeout * 2,
                CURLOPT_SSL_VERIFYPEER => $verifySsl,
                CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT      => 'pasarela-facturacion/1.0',
            ]);

            $body     = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($body === false || $httpCode !== 200 || empty($body)) {
                if (file_exists($wsdlFile)) {
                    Log::warning("WSDL no descargable ({$httpCode} {$curlErr}); usando caché previa.");
                } else {
                    throw new SoapFault('HTTP',
                        "No se pudo descargar el WSDL de {$url}. HTTP {$httpCode}. cURL: {$curlErr}.");
                }
            } else {
                file_put_contents($wsdlFile, $body);
            }
        }

        $context = stream_context_create([
            'http'  => ['header' => "apikey: TokenApi " . $this->token, 'timeout' => $this->timeout],
            'https' => ['header' => "apikey: TokenApi " . $this->token, 'timeout' => $this->timeout],
            'ssl'   => [
                'verify_peer'       => $verifySsl,
                'verify_peer_name'  => $verifySsl,
                'allow_self_signed' => !$verifySsl,
            ],
        ]);

        return new SoapClient($wsdlFile, [
            'stream_context'     => $context,
            'cache_wsdl'         => WSDL_CACHE_NONE,
            'trace'              => true,
            'exceptions'         => true,
            'connection_timeout' => $this->timeout,
            'location'           => $this->baseUrl . $endpoint,
        ]);
    }

    /**
     * Parámetros base que casi todas las peticiones del SIAT necesitan.
     */
    private function getBaseParameters(bool $includeModality = true): array
    {
        $params = [
            'codigoAmbiente'   => $this->environment,
            'codigoPuntoVenta' => $this->posCode,
            'codigoSistema'    => $this->systemCode,
            'codigoSucursal'   => $this->branchCode,
            'nit'              => $this->nit,
        ];

        if ($includeModality) {
            $params['codigoModalidad'] = $this->modality;
        }

        return $params;
    }

    /**
     * Detecta si un SoapFault es caída de red (dispara modo offline)
     * vs. un rechazo lógico del SIAT.
     */
    private function isNetworkFailure(SoapFault $fault): bool
    {
        $msg = strtolower($fault->getMessage());
        $signals = [
            // Fallos de red local (nuestra conectividad).
            'could not connect', 'timed out', 'timeout', 'failed to load external entity',
            'connection refused', 'name or service not known', 'could not resolve host',
            'temporary failure in name resolution', 'ssl', 'could not fetch http headers',
            'no se pudo descargar el wsdl',
            // Caída del lado del SIAT (5xx en la respuesta HTTP del propio SIN).
            // OJO: 401/403 NO se incluyen a propósito — son rechazos de
            // autenticación/autorización (credenciales, límites, etc.), no
            // evidencia de que el SIAT esté caído; tratarlos como offline
            // generaría eventos de contingencia falsos.
            'http 500', 'http 502', 'http 503', 'http 504',
        ];

        foreach ($signals as $s) {
            if (str_contains($msg, $s)) {
                return true;
            }
        }
        return false;
    }

    // ==========================================
    // COMUNICACIÓN Y CÓDIGOS (CUIS / CUFD)
    // ==========================================

    public function verifyCommunication(): array
    {
        try {
            $client = $this->getSoapClient('FacturacionCodigos');
            $result = $client->verificarComunicacion();
            return ['success' => true, 'message' => 'Comunicación Exitosa', 'data' => $result];
        } catch (SoapFault $fault) {
            Log::error('SIAT verifyCommunication: ' . $fault->getMessage());
            return ['success' => false, 'message' => $fault->getMessage(), 'offline' => $this->isNetworkFailure($fault)];
        }
    }

    public function getCuis(): array
    {
        try {
            $client   = $this->getSoapClient('FacturacionCodigos');
            $params   = ['SolicitudCuis' => $this->getBaseParameters()];
            $response = $client->cuis($params);

            if (isset($response->RespuestaCuis->transaccion) && $response->RespuestaCuis->transaccion) {
                return ['success' => true, 'codigo' => $response->RespuestaCuis->codigo];
            }
            return ['success' => false, 'error' => 'Rechazado por SIAT',
                'detalles' => $response->RespuestaCuis->mensajesList ?? 'Error desconocido'];
        } catch (SoapFault $fault) {
            Log::error('SIAT getCuis: ' . $fault->getMessage());
            return ['success' => false, 'error' => 'Fallo de conexión SOAP',
                'mensaje' => $fault->getMessage(), 'offline' => $this->isNetworkFailure($fault)];
        }
    }

    public function getCufd(string $cuis): array
    {
        try {
            $client   = $this->getSoapClient('FacturacionCodigos');
            $params   = ['SolicitudCufd' => array_merge($this->getBaseParameters(), ['cuis' => $cuis])];
            $response = $client->cufd($params);

            if (isset($response->RespuestaCufd->transaccion) && $response->RespuestaCufd->transaccion) {
                return [
                    'success'       => true,
                    'codigo'        => $response->RespuestaCufd->codigo,
                    'codigoControl' => $response->RespuestaCufd->codigoControl,
                    'fechaVigencia' => $response->RespuestaCufd->fechaVigencia,
                ];
            }
            return ['success' => false, 'error' => 'Rechazado por SIAT',
                'detalles' => $response->RespuestaCufd->mensajesList ?? 'Error desconocido'];
        } catch (SoapFault $fault) {
            Log::error('SIAT getCufd: ' . $fault->getMessage());
            return ['success' => false, 'error' => 'Fallo de conexión SOAP',
                'mensaje' => $fault->getMessage(), 'offline' => $this->isNetworkFailure($fault)];
        }
    }

    // ==========================================
    // PERSISTENCIA DE CÓDIGOS (por emisor)
    // ==========================================

    /**
     * Guarda una credencial de forma atómica, respetando el índice único
     * parcial: primero desactiva las vigentes del mismo emisor+tipo+dosificación,
     * luego inserta la nueva activa.
     */
    private function storeCredential(string $type, string $code, ?string $controlCode, $issuedAt, $expiresAt): SiatCodigo
    {
        return DB::transaction(function () use ($type, $code, $controlCode, $issuedAt, $expiresAt) {
            SiatCodigo::query()
                ->where('emiid', $this->emisor->emiid)
                ->where('scotipo', $type)
                ->where('scoamb', $this->environment)
                ->where('scosuc', $this->branchCode)
                ->where('scopdv', $this->posCode)
                ->where('scoest', true)
                ->update(['scoest' => false]);

            return SiatCodigo::create([
                'emiid'      => $this->emisor->emiid,
                'scotipo'    => $type,
                'scovalor'   => $code,
                'scocodctrl' => $controlCode,
                'scoamb'     => $this->environment,
                'scosuc'     => $this->branchCode,
                'scopdv'     => $this->posCode,
                'scoemi'     => $issuedAt,
                'scoven'     => $expiresAt,
                'scoest'     => true,
            ]);
        });
    }

    /**
     * CUIS vigente del emisor; si no hay, lo pide al SIAT y lo persiste.
     * Vigencia legal ~1 año; usamos 11 meses de margen.
     */
    public function getActiveCuis(): ?string
    {
        $cred = SiatCodigo::query()
            ->where('emiid', $this->emisor->emiid)
            ->where('scotipo', SiatCodigo::TIPO_CUIS)
            ->where('scoamb', $this->environment)
            ->where('scosuc', $this->branchCode)
            ->where('scopdv', $this->posCode)
            ->where('scoest', true)
            ->where('scoven', '>', now())
            ->latest('scoemi')
            ->first();

        if ($cred) {
            return $cred->scovalor;
        }

        $response = $this->getCuis();
        if (!($response['success'] ?? false)) {
            Log::error('SIAT: no se pudo renovar CUIS para emisor ' . $this->emisor->eminit);
            return null;
        }

        try {
            $cred = $this->storeCredential(
                SiatCodigo::TIPO_CUIS, $response['codigo'], null, now(), now()->addMonths(11)
            );
            return $cred->scovalor;
        } catch (Exception $e) {
            Log::error('SIAT: fallo al persistir CUIS. ' . $e->getMessage());
            return null;
        }
    }

    /**
     * CUFD vigente del emisor (modelo SiatCodigo); si no hay, lo renueva.
     * Vigencia ~24h.
     */
    public function getActiveCufd(): ?SiatCodigo
    {
        $cufd = SiatCodigo::query()
            ->where('emiid', $this->emisor->emiid)
            ->where('scotipo', SiatCodigo::TIPO_CUFD)
            ->where('scoest', true)
            ->where('scoven', '>', Carbon::now())
            ->latest('scoven')
            ->first();

        if ($cufd) {
            return $cufd;
        }

        $cuis = $this->getActiveCuis();
        if (!$cuis) {
            Log::error('SIAT getActiveCufd: sin CUIS para renovar el CUFD.');
            return null;
        }

        $response = $this->getCufd($cuis);
        if (empty($response['success']) || empty($response['fechaVigencia'])) {
            Log::error('SIAT getActiveCufd: fallo al renovar CUFD.');
            return null;
        }

        try {
            return $this->storeCredential(
                SiatCodigo::TIPO_CUFD, $response['codigo'], $response['codigoControl'],
                Carbon::now(), Carbon::parse($response['fechaVigencia'])
            );
        } catch (Exception $e) {
            Log::error('SIAT: fallo al persistir CUFD. ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Lee el CUFD vigente sin intentar renovarlo (útil offline).
     */
    public function peekActiveCufd(): ?SiatCodigo
    {
        return SiatCodigo::query()
            ->where('emiid', $this->emisor->emiid)
            ->where('scotipo', SiatCodigo::TIPO_CUFD)
            ->where('scoamb', $this->environment)
            ->where('scoest', true)
            ->where('scoven', '>', now())
            ->latest('scoemi')
            ->first();
    }

    // ==========================================
    // ENVÍO DE FACTURA
    // ==========================================

    /**
     * Envía la factura al SIAT (online). Devuelve estado normalizado:
     *  status: 'accepted' | 'rejected' | 'offline'
     */
    public function receiveInvoice(string $cuis, string $cufd, string $archive, string $issueDate, string $hashArchive): array
    {
        try {
            $client = $this->getSoapClient('ServicioFacturacionCompraVenta');

            $params = ['SolicitudServicioRecepcionFactura' => array_merge($this->getBaseParameters(), [
                'codigoDocumentoSector' => 1,
                'codigoEmision'         => 1,
                'tipoFacturaDocumento'  => 1,
                'cuis'                  => $cuis,
                'cufd'                  => $cufd,
                'archivo'               => $archive,
                'fechaEnvio'            => $issueDate,
                'hashArchivo'           => $hashArchive,
            ])];

            $response = $client->recepcionFactura($params);
            $r = $response->RespuestaServicioFacturacion ?? null;

            if ($r && !empty($r->transaccion)) {
                return ['status' => 'accepted', 'codigoRecepcion' => $r->codigoRecepcion ?? null,
                    'mensaje' => 'Factura recibida por el SIAT', 'raw' => $response];
            }

            return ['status' => 'rejected', 'codigoRecepcion' => null,
                'mensaje' => $this->extractSiatMessage($r), 'raw' => $response];
        } catch (SoapFault $fault) {
            Log::error('SIAT receiveInvoice: ' . $fault->getMessage());
            return [
                'status'  => $this->isNetworkFailure($fault) ? 'offline' : 'rejected',
                'codigoRecepcion' => null, 'mensaje' => $fault->getMessage(), 'raw' => null,
            ];
        }
    }

    // ==========================================
    // CONTINGENCIA (EVENTOS SIGNIFICATIVOS Y PAQUETES OFFLINE)
    // ==========================================

    /**
     * Registra ante el SIAT un Evento Significativo ya CERRADO (con inicio
     * y fin conocidos). En nuestro modelo el evento se declara de forma
     * retroactiva: se detecta la falla, se sigue facturando offline, y solo
     * al recuperar conexión se informa al SIAT el período que estuvo caído.
     *
     * $cufdAuth: CUFD vigente AHORA (para autenticar esta llamada, ya en línea).
     * $cufdEvento: el CUFD que realmente se usó para las facturas offline
     *              durante la contingencia (el que ya no era válido en ese momento).
     */
    public function registrarEventoSignificativo(
        string $cuis,
        string $cufdAuth,
        int $codigoMotivo,
        string $descripcion,
        CarbonInterface $inicio,
        CarbonInterface $fin,
        string $cufdEvento
    ): array {
        try {
            $client = $this->getSoapClient('FacturacionOperaciones');

            $params = ['SolicitudEventoSignificativo' => array_merge($this->getBaseParameters(false), [
                'codigoMotivoEvento'     => $codigoMotivo,
                'cufd'                   => $cufdAuth,
                'cufdEvento'             => $cufdEvento,
                'cuis'                   => $cuis,
                'descripcion'            => $descripcion,
                'fechaHoraInicioEvento'  => $inicio->format('Y-m-d\TH:i:s.v'),
                'fechaHoraFinEvento'     => $fin->format('Y-m-d\TH:i:s.v'),
            ])];

            $response = $client->registroEventoSignificativo($params);
            $r = $response->RespuestaListaEventos ?? null;

            if ($r && !empty($r->transaccion)) {
                return [
                    'success'         => true,
                    'codigoRecepcion' => $r->codigoRecepcionEventoSignificativo ?? null,
                ];
            }

            return ['success' => false, 'mensaje' => $this->extractSiatMessage($r)];
        } catch (SoapFault $fault) {
            Log::error('SIAT registrarEventoSignificativo: ' . $fault->getMessage());
            return [
                'success' => false,
                'offline' => $this->isNetworkFailure($fault),
                'mensaje' => $fault->getMessage(),
            ];
        }
    }

    /**
     * Envía el paquete (.tar.gz de varias facturas offline) referenciando
     * el código de recepción del Evento Significativo ya registrado.
     */
    public function enviarPaqueteOffline(
        string $cuis,
        string $cufd,
        string $archivoBase64,
        string $issueDate,
        string $hashArchivo,
        int $cantidadFacturas,
        string $codigoEvento
    ): array {
        try {
            $client = $this->getSoapClient('ServicioFacturacionCompraVenta');

            $params = ['SolicitudServicioRecepcionPaquete' => array_merge($this->getBaseParameters(), [
                'codigoDocumentoSector' => 1,
                'codigoEmision'         => 2, // offline
                'tipoFacturaDocumento'  => 1,
                'cuis'                  => $cuis,
                'cufd'                  => $cufd,
                'archivo'               => $archivoBase64,
                'fechaEnvio'            => $issueDate,
                'hashArchivo'           => $hashArchivo,
                'cantidadFacturas'      => $cantidadFacturas,
                'codigoEvento'          => $codigoEvento,
            ])];

            $response = $client->recepcionPaqueteFactura($params);
            $r = $response->RespuestaServicioFacturacion ?? null;

            if ($r && !empty($r->transaccion)) {
                return ['status' => 'received', 'codigoRecepcion' => $r->codigoRecepcion ?? null,
                    'mensaje' => 'Paquete recibido por el SIAT, pendiente de validación', 'raw' => $response];
            }

            return ['status' => 'rejected', 'codigoRecepcion' => null,
                'mensaje' => $this->extractSiatMessage($r), 'raw' => $response];
        } catch (SoapFault $fault) {
            Log::error('SIAT enviarPaqueteOffline: ' . $fault->getMessage());
            return [
                'status'  => $this->isNetworkFailure($fault) ? 'offline' : 'rejected',
                'codigoRecepcion' => null, 'mensaje' => $fault->getMessage(), 'raw' => null,
            ];
        }
    }

    /**
     * Consulta el resultado de validación de un paquete ya enviado.
     * status: 'accepted' | 'observed' | 'rejected' | 'processing'
     */
    public function validarPaqueteOffline(string $cuis, string $cufd, string $codigoRecepcionPaquete): array
    {
        try {
            $client = $this->getSoapClient('ServicioFacturacionCompraVenta');

            $params = ['SolicitudServicioValidacionRecepcionPaquete' => array_merge($this->getBaseParameters(), [
                'codigoDocumentoSector' => 1,
                'codigoEmision'         => 2,
                'tipoFacturaDocumento'  => 1,
                'cuis'                  => $cuis,
                'cufd'                  => $cufd,
                'codigoRecepcion'       => $codigoRecepcionPaquete,
            ])];

            $response = $client->validacionRecepcionPaqueteFactura($params);
            $r = $response->RespuestaServicioFacturacion ?? null;

            $codigoDescripcion = strtoupper($r->codigoDescripcion ?? '');

            return [
                'status' => match (true) {
                    $codigoDescripcion === 'VALIDADA'   => 'accepted',
                    $codigoDescripcion === 'OBSERVADA'  => 'observed',
                    $codigoDescripcion === 'RECHAZADA'  => 'rejected',
                    $codigoDescripcion === 'EN PROCESO' => 'processing',
                    default                              => 'rejected',
                },
                'mensaje' => $this->extractSiatMessage($r),
                'raw'     => $response,
            ];
        } catch (SoapFault $fault) {
            Log::error('SIAT validarPaqueteOffline: ' . $fault->getMessage());
            return [
                'status'  => $this->isNetworkFailure($fault) ? 'offline' : 'rejected',
                'mensaje' => $fault->getMessage(),
                'raw'     => null,
            ];
        }
    }

    /**
     * Extrae un mensaje legible de la respuesta del SIAT.
     */
    private function extractSiatMessage($response): string
    {
        if (!$response) {
            return 'Sin respuesta del SIAT';
        }
        if (isset($response->mensajesList)) {
            $msg = $response->mensajesList;
            if (is_array($msg)) {
                return collect($msg)->map(fn($m) => $m->descripcion ?? '')->implode(' | ');
            }
            return $msg->descripcion ?? json_encode($msg);
        }
        return $response->codigoDescripcion ?? 'Respuesta sin mensaje';
    }
}
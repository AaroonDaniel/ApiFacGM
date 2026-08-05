<?php

namespace App\Services;

use App\Models\Emisor;
use App\Models\SiatCodigo;
use App\Models\SiatTransaccion;
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
     * Se construye POR EMISOR (el token es compartido por NIT, sale de la
     * fila `emisores`, no de config/siat.php) y opcionalmente por un punto
     * de venta específico — si no se indica, usa el (0,0) del emisor, que
     * sigue siendo válido para emisores con una sola dosificación.
     */
    public function __construct(Emisor $emisor, ?int $branchCode = null, ?int $posCode = null)
    {
        $this->emisor      = $emisor;

        $this->token       = $emisor->emitoken;   // descifrado automáticamente por el cast
        $this->systemCode  = $emisor->emisis;
        $this->nit         = $emisor->eminit;
        $this->branchCode  = $branchCode ?? $emisor->emisuc;
        $this->posCode     = $posCode ?? $emisor->emipdv;
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

    /**
     * Audita cada llamada real al SIAT. Nunca debe romper el flujo
     * principal: un fallo al guardar el log solo se registra en el log
     * de aplicación, no se propaga.
     */
    private function registrarTransaccion(
        string $servicio,
        array $peticion,
        mixed $respuesta,
        string $estado,
        ?int $facturaId = null
    ): void {
        try {
            SiatTransaccion::create([
                'emiid'    => $this->emisor->emiid,
                'stxfacid' => $facturaId,
                'stxserv'  => $servicio,
                'stxpet'   => json_encode($this->sanitizarBinariosParaLog($peticion)),
                'stxresp'  => is_string($respuesta) ? $respuesta : json_encode($respuesta),
                'stxest'   => $estado,
                'stxfch'   => now(),
            ]);
        } catch (Exception $e) {
            Log::error("No se pudo registrar la transacción SIAT ({$servicio}): " . $e->getMessage());
        }
    }

    /**
     * Reemplaza campos binarios (ej. 'archivo', gzip crudo) por un
     * placeholder legible antes de loguear — json_encode() falla en
     * silencio con bytes que no son UTF-8 válido.
     */
    private function sanitizarBinariosParaLog(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitizarBinariosParaLog($value);
            } elseif ($key === 'archivo' && is_string($value)) {
                $data[$key] = '<binario, ' . strlen($value) . ' bytes>';
            }
        }
        return $data;
    }

    // ==========================================
    // COMUNICACIÓN Y CÓDIGOS (CUIS / CUFD)
    // ==========================================

    public function verifyCommunication(): array
    {
        try {
            $client = $this->getSoapClient('FacturacionCodigos');
            $result = $client->verificarComunicacion();
            $this->registrarTransaccion('verificarComunicacion', [], $result, SiatTransaccion::ESTADO_EXITO);
            return ['success' => true, 'message' => 'Comunicación Exitosa', 'data' => $result];
        } catch (SoapFault $fault) {
            Log::error('SIAT verifyCommunication: ' . $fault->getMessage());
            $offline = $this->isNetworkFailure($fault);
            $this->registrarTransaccion('verificarComunicacion', [], $fault->getMessage(),
                $offline ? SiatTransaccion::ESTADO_OFFLINE : SiatTransaccion::ESTADO_RECHAZO);
            return ['success' => false, 'message' => $fault->getMessage(), 'offline' => $offline];
        }
    }

    public function getCuis(): array
    {
        $params = ['SolicitudCuis' => $this->getBaseParameters()];
        try {
            $client   = $this->getSoapClient('FacturacionCodigos');
            $response = $client->cuis($params);

            if (isset($response->RespuestaCuis->transaccion) && $response->RespuestaCuis->transaccion) {
                $this->registrarTransaccion('cuis', $params, $response, SiatTransaccion::ESTADO_EXITO);
                return ['success' => true, 'codigo' => $response->RespuestaCuis->codigo];
            }
            $this->registrarTransaccion('cuis', $params, $response, SiatTransaccion::ESTADO_RECHAZO);
            return ['success' => false, 'error' => 'Rechazado por SIAT',
                'detalles' => $response->RespuestaCuis->mensajesList ?? 'Error desconocido'];
        } catch (SoapFault $fault) {
            Log::error('SIAT getCuis: ' . $fault->getMessage());
            $offline = $this->isNetworkFailure($fault);
            $this->registrarTransaccion('cuis', $params, $fault->getMessage(),
                $offline ? SiatTransaccion::ESTADO_OFFLINE : SiatTransaccion::ESTADO_RECHAZO);
            return ['success' => false, 'error' => 'Fallo de conexión SOAP',
                'mensaje' => $fault->getMessage(), 'offline' => $offline];
        }
    }

    public function getCufd(string $cuis): array
    {
        $params = ['SolicitudCufd' => array_merge($this->getBaseParameters(), ['cuis' => $cuis])];
        try {
            $client   = $this->getSoapClient('FacturacionCodigos');
            $response = $client->cufd($params);

            if (isset($response->RespuestaCufd->transaccion) && $response->RespuestaCufd->transaccion) {
                $this->registrarTransaccion('cufd', $params, $response, SiatTransaccion::ESTADO_EXITO);
                return [
                    'success'       => true,
                    'codigo'        => $response->RespuestaCufd->codigo,
                    'codigoControl' => $response->RespuestaCufd->codigoControl,
                    'fechaVigencia' => $response->RespuestaCufd->fechaVigencia,
                ];
            }
            $this->registrarTransaccion('cufd', $params, $response, SiatTransaccion::ESTADO_RECHAZO);
            return ['success' => false, 'error' => 'Rechazado por SIAT',
                'detalles' => $response->RespuestaCufd->mensajesList ?? 'Error desconocido'];
        } catch (SoapFault $fault) {
            Log::error('SIAT getCufd: ' . $fault->getMessage());
            $offline = $this->isNetworkFailure($fault);
            $this->registrarTransaccion('cufd', $params, $fault->getMessage(),
                $offline ? SiatTransaccion::ESTADO_OFFLINE : SiatTransaccion::ESTADO_RECHAZO);
            return ['success' => false, 'error' => 'Fallo de conexión SOAP',
                'mensaje' => $fault->getMessage(), 'offline' => $offline];
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
     * Renueva el CUIS de forma proactiva si está por vencer dentro de
     * $diasAviso días (o si ya no hay uno vigente). A diferencia de
     * getActiveCuis() — que solo renueva cuando el actual YA venció — esto
     * adelanta la renovación antes del corte real de ~365 días, para no
     * depender de que la primera factura después del vencimiento pague el
     * costo justo cuando ya es tarde.
     *
     * @return array{renovado: bool, vigente_hasta: ?string, error?: string}
     */
    public function renovarCuisSiProximoAExpirar(int $diasAviso = 30): array
    {
        $cuis = SiatCodigo::query()
            ->where('emiid', $this->emisor->emiid)
            ->where('scotipo', SiatCodigo::TIPO_CUIS)
            ->where('scoamb', $this->environment)
            ->where('scosuc', $this->branchCode)
            ->where('scopdv', $this->posCode)
            ->where('scoest', true)
            ->latest('scoemi')
            ->first();

        $vigente = $cuis && $cuis->scoven->isFuture();

        if ($vigente) {
            $diasRestantes = (int) now()->diffInDays($cuis->scoven);
            if ($diasRestantes > $diasAviso) {
                return ['renovado' => false, 'vigente_hasta' => $cuis->scoven->toDateString()];
            }
            Log::warning("SIAT: CUIS del emisor {$this->emisor->eminit} (suc {$this->branchCode}, PDV {$this->posCode}) "
                . "vence en {$diasRestantes} día(s) ({$cuis->scoven->toDateString()}); renovando proactivamente.");
        } else {
            Log::warning("SIAT: emisor {$this->emisor->eminit} (suc {$this->branchCode}, PDV {$this->posCode}) sin CUIS vigente; renovando.");
        }

        $response = $this->getCuis();
        if (!($response['success'] ?? false)) {
            $mensaje = "No se pudo renovar el CUIS del emisor {$this->emisor->eminit} (suc {$this->branchCode}, PDV {$this->posCode}).";
            Log::critical($mensaje . ' ' . ($response['error'] ?? ''));
            return ['renovado' => false, 'vigente_hasta' => $cuis?->scoven?->toDateString(), 'error' => $mensaje];
        }

        try {
            $nuevo = $this->storeCredential(SiatCodigo::TIPO_CUIS, $response['codigo'], null, now(), now()->addMonths(11));
            return ['renovado' => true, 'vigente_hasta' => $nuevo->scoven->toDateString()];
        } catch (Exception $e) {
            Log::error('SIAT: fallo al persistir la renovación proactiva del CUIS. ' . $e->getMessage());
            return ['renovado' => false, 'vigente_hasta' => $cuis?->scoven?->toDateString(), 'error' => $e->getMessage()];
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
            ->where('scoamb', $this->environment)
            ->where('scosuc', $this->branchCode)
            ->where('scopdv', $this->posCode)
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
    public function receiveInvoice(
        string $cuis,
        string $cufd,
        string $archive,
        string $issueDate,
        string $hashArchive,
        ?int $facturaId = null
    ): array {
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

        try {
            $client   = $this->getSoapClient('ServicioFacturacionCompraVenta');
            $response = $client->recepcionFactura($params);
            $r = $response->RespuestaServicioFacturacion ?? null;

            if ($r && !empty($r->transaccion)) {
                $this->registrarTransaccion('recepcionFactura', $params, $response, SiatTransaccion::ESTADO_EXITO, $facturaId);
                return ['status' => 'accepted', 'codigoRecepcion' => $r->codigoRecepcion ?? null,
                    'mensaje' => 'Factura recibida por el SIAT', 'raw' => $response];
            }

            $this->registrarTransaccion('recepcionFactura', $params, $response, SiatTransaccion::ESTADO_RECHAZO, $facturaId);
            return ['status' => 'rejected', 'codigoRecepcion' => null,
                'mensaje' => $this->extractSiatMessage($r), 'raw' => $response];
        } catch (SoapFault $fault) {
            Log::error('SIAT receiveInvoice: ' . $fault->getMessage());
            $offline = $this->isNetworkFailure($fault);
            $this->registrarTransaccion('recepcionFactura', $params, $fault->getMessage(),
                $offline ? SiatTransaccion::ESTADO_OFFLINE : SiatTransaccion::ESTADO_RECHAZO, $facturaId);
            return [
                'status'  => $offline ? 'offline' : 'rejected',
                'codigoRecepcion' => null, 'mensaje' => $fault->getMessage(), 'raw' => null,
            ];
        }
    }

    /**
     * Anula una factura ya aceptada por el SIAT.
     * status: 'accepted' | 'rejected' | 'offline'
     */
    public function anularFactura(string $cuis, string $cufd, string $cuf, int $codigoMotivo, ?int $facturaId = null): array
    {
        $params = ['SolicitudServicioAnulacionFactura' => array_merge($this->getBaseParameters(), [
            'codigoDocumentoSector' => 1,
            'codigoEmision'         => 1,
            'tipoFacturaDocumento'  => 1,
            'cuis'                  => $cuis,
            'cufd'                  => $cufd,
            'cuf'                   => $cuf,
            'codigoMotivo'          => $codigoMotivo,
        ])];

        try {
            $client   = $this->getSoapClient('ServicioFacturacionCompraVenta');
            $response = $client->anulacionFactura($params);
            $r = $response->RespuestaServicioFacturacion ?? null;

            if ($r && !empty($r->transaccion)) {
                $this->registrarTransaccion('anulacionFactura', $params, $response, SiatTransaccion::ESTADO_EXITO, $facturaId);
                return ['status' => 'accepted', 'mensaje' => 'Factura anulada ante el SIAT', 'raw' => $response];
            }

            $this->registrarTransaccion('anulacionFactura', $params, $response, SiatTransaccion::ESTADO_RECHAZO, $facturaId);
            return ['status' => 'rejected', 'mensaje' => $this->extractSiatMessage($r), 'raw' => $response];
        } catch (SoapFault $fault) {
            Log::error('SIAT anularFactura: ' . $fault->getMessage());
            $offline = $this->isNetworkFailure($fault);
            $this->registrarTransaccion('anulacionFactura', $params, $fault->getMessage(),
                $offline ? SiatTransaccion::ESTADO_OFFLINE : SiatTransaccion::ESTADO_RECHAZO, $facturaId);
            return [
                'status'  => $offline ? 'offline' : 'rejected',
                'mensaje' => $fault->getMessage(), 'raw' => null,
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
        $params = ['SolicitudEventoSignificativo' => array_merge($this->getBaseParameters(false), [
            'codigoMotivoEvento'     => $codigoMotivo,
            'cufd'                   => $cufdAuth,
            'cufdEvento'             => $cufdEvento,
            'cuis'                   => $cuis,
            'descripcion'            => $descripcion,
            'fechaHoraInicioEvento'  => $inicio->format('Y-m-d\TH:i:s.v'),
            'fechaHoraFinEvento'     => $fin->format('Y-m-d\TH:i:s.v'),
        ])];

        try {
            $client   = $this->getSoapClient('FacturacionOperaciones');
            $response = $client->registroEventoSignificativo($params);
            $r = $response->RespuestaListaEventos ?? null;

            if ($r && !empty($r->transaccion)) {
                $this->registrarTransaccion('registroEventoSignificativo', $params, $response, SiatTransaccion::ESTADO_EXITO);
                return [
                    'success'         => true,
                    'codigoRecepcion' => $r->codigoRecepcionEventoSignificativo ?? null,
                ];
            }

            $this->registrarTransaccion('registroEventoSignificativo', $params, $response, SiatTransaccion::ESTADO_RECHAZO);
            return ['success' => false, 'mensaje' => $this->extractSiatMessage($r)];
        } catch (SoapFault $fault) {
            Log::error('SIAT registrarEventoSignificativo: ' . $fault->getMessage());
            $offline = $this->isNetworkFailure($fault);
            $this->registrarTransaccion('registroEventoSignificativo', $params, $fault->getMessage(),
                $offline ? SiatTransaccion::ESTADO_OFFLINE : SiatTransaccion::ESTADO_RECHAZO);
            return [
                'success' => false,
                'offline' => $offline,
                'mensaje' => $fault->getMessage(),
            ];
        }
    }

    /**
     * Envía el paquete (.tar.gz de varias facturas offline) referenciando
     * el código de recepción del Evento Significativo ya registrado.
     *
     * $cafc: código CAFC cuando el paquete son facturas manuales transcritas
     * (talón preimpreso, ver ContingenciaService::enviarPaqueteCafc()); null
     * para un paquete de offline normal. El campo existe en el WSDL real
     * (SolicitudServicioRecepcionPaquete.cafc, opcional) — verificado
     * bajando el esquema del piloto, no se adivinó.
     */
    public function enviarPaqueteOffline(
        string $cuis,
        string $cufd,
        string $archivoBinario,
        string $issueDate,
        string $hashArchivo,
        int $cantidadFacturas,
        string $codigoEvento,
        ?string $cafc = null
    ): array {
        $body = array_merge($this->getBaseParameters(), [
            'codigoDocumentoSector' => 1,
            'codigoEmision'         => 2, // offline
            'tipoFacturaDocumento'  => 1,
            'cuis'                  => $cuis,
            'cufd'                  => $cufd,
            'archivo'               => $archivoBinario,
            'fechaEnvio'            => $issueDate,
            'hashArchivo'           => $hashArchivo,
            'cantidadFacturas'      => $cantidadFacturas,
            'codigoEvento'          => $codigoEvento,
        ]);

        if ($cafc !== null) {
            $body['cafc'] = $cafc;
        }

        $params = ['SolicitudServicioRecepcionPaquete' => $body];

        try {
            $client   = $this->getSoapClient('ServicioFacturacionCompraVenta');
            $response = $client->recepcionPaqueteFactura($params);
            $r = $response->RespuestaServicioFacturacion ?? null;

            if ($r && !empty($r->transaccion)) {
                $this->registrarTransaccion('recepcionPaqueteFactura', $params, $response, SiatTransaccion::ESTADO_EXITO);
                return ['status' => 'received', 'codigoRecepcion' => $r->codigoRecepcion ?? null,
                    'mensaje' => 'Paquete recibido por el SIAT, pendiente de validación', 'raw' => $response];
            }

            $this->registrarTransaccion('recepcionPaqueteFactura', $params, $response, SiatTransaccion::ESTADO_RECHAZO);
            return ['status' => 'rejected', 'codigoRecepcion' => null,
                'mensaje' => $this->extractSiatMessage($r), 'raw' => $response];
        } catch (SoapFault $fault) {
            Log::error('SIAT enviarPaqueteOffline: ' . $fault->getMessage());
            $offline = $this->isNetworkFailure($fault);
            $this->registrarTransaccion('recepcionPaqueteFactura', $params, $fault->getMessage(),
                $offline ? SiatTransaccion::ESTADO_OFFLINE : SiatTransaccion::ESTADO_RECHAZO);
            return [
                'status'  => $offline ? 'offline' : 'rejected',
                'codigoRecepcion' => null, 'mensaje' => $fault->getMessage(), 'raw' => null,
            ];
        }
    }

    /**
     * Consulta el resultado de validación de un paquete ya enviado.
     * status: 'accepted' | 'observed' | 'rejected' | 'processing'
     *
     * 'mensajes': mensajesList normalizado — cada uno con numeroArchivo
     * (posición del documento dentro del .tar, ver Factura::facnumeroarchivo)
     * y advertencia (si es solo un aviso, no un rechazo real de ese
     * documento puntual). Permite a ContingenciaService distinguir, dentro
     * de un paquete RECHAZADA/OBSERVADA, cuáles documentos puntuales
     * tuvieron un error real — sin esto, un solo documento malo tumbaría
     * todo el paquete completo.
     */
    public function validarPaqueteOffline(string $cuis, string $cufd, string $codigoRecepcionPaquete): array
    {
        $params = ['SolicitudServicioValidacionRecepcionPaquete' => array_merge($this->getBaseParameters(), [
            'codigoDocumentoSector' => 1,
            'codigoEmision'         => 2,
            'tipoFacturaDocumento'  => 1,
            'cuis'                  => $cuis,
            'cufd'                  => $cufd,
            'codigoRecepcion'       => $codigoRecepcionPaquete,
        ])];

        try {
            $client   = $this->getSoapClient('ServicioFacturacionCompraVenta');
            $response = $client->validacionRecepcionPaqueteFactura($params);
            $r = $response->RespuestaServicioFacturacion ?? null;

            $codigoDescripcion = strtoupper($r->codigoDescripcion ?? '');
            $status = match (true) {
                $codigoDescripcion === 'VALIDADA'   => 'accepted',
                $codigoDescripcion === 'OBSERVADA'  => 'observed',
                $codigoDescripcion === 'RECHAZADA'  => 'rejected',
                $codigoDescripcion === 'EN PROCESO' => 'processing',
                default                              => 'rejected',
            };

            $this->registrarTransaccion('validacionRecepcionPaqueteFactura', $params, $response,
                $status === 'accepted' ? SiatTransaccion::ESTADO_EXITO : SiatTransaccion::ESTADO_RECHAZO);

            return [
                'status'   => $status,
                'mensaje'  => $this->extractSiatMessage($r),
                'mensajes' => $this->normalizarMensajesRecepcion($r),
                'raw'      => $response,
            ];
        } catch (SoapFault $fault) {
            Log::error('SIAT validarPaqueteOffline: ' . $fault->getMessage());
            $offline = $this->isNetworkFailure($fault);
            $this->registrarTransaccion('validacionRecepcionPaqueteFactura', $params, $fault->getMessage(),
                $offline ? SiatTransaccion::ESTADO_OFFLINE : SiatTransaccion::ESTADO_RECHAZO);
            return [
                'status'  => $offline ? 'offline' : 'rejected',
                'mensaje' => $fault->getMessage(),
                'raw'     => null,
            ];
        }
    }

    // ==========================================
    // SINCRONIZACIÓN DE CATÁLOGOS PARAMÉTRICOS
    // ==========================================

    /**
     * Llama una operación de sincronización genérica y normaliza su
     * resultado. Todas comparten el mismo request (SolicitudSincronizacion)
     * y el mismo patrón de respuesta (nodo con .transaccion + una lista),
     * verificado contra el WSDL real de FacturacionSincronizacion — solo
     * cambia el nombre del nodo de respuesta y el de su campo de lista.
     */
    private function sincronizar(string $operacion, string $nodoRespuesta, string $campoLista): array
    {
        $cuis = $this->getActiveCuis();
        if (!$cuis) {
            return ['success' => false, 'mensaje' => 'Sin CUIS para sincronizar.'];
        }

        $params = ['SolicitudSincronizacion' => array_merge($this->getBaseParameters(false), ['cuis' => $cuis])];

        try {
            $client   = $this->getSoapClient('FacturacionSincronizacion');
            $response = $client->$operacion($params);
            $r = $response->$nodoRespuesta ?? null;

            if (!$r || empty($r->transaccion)) {
                $this->registrarTransaccion($operacion, $params, $response, SiatTransaccion::ESTADO_RECHAZO);
                return ['success' => false, 'mensaje' => $this->extractSiatMessage($r)];
            }

            $this->registrarTransaccion($operacion, $params, $response, SiatTransaccion::ESTADO_EXITO);

            // El SoapClient colapsa un array de un solo elemento a un
            // objeto suelto en vez de devolver una lista de 1.
            $items = $r->$campoLista ?? [];
            if (is_object($items)) {
                $items = [$items];
            }

            return ['success' => true, 'items' => $items];
        } catch (SoapFault $fault) {
            Log::error("SIAT {$operacion}: " . $fault->getMessage());
            $offline = $this->isNetworkFailure($fault);
            $this->registrarTransaccion($operacion, $params, $fault->getMessage(),
                $offline ? SiatTransaccion::ESTADO_OFFLINE : SiatTransaccion::ESTADO_RECHAZO);
            return ['success' => false, 'offline' => $offline, 'mensaje' => $fault->getMessage()];
        }
    }

    /** Actividades económicas asociadas al NIT del emisor (por emisor). */
    public function sincronizarActividades(): array
    {
        return $this->sincronizar('sincronizarActividades', 'RespuestaListaActividades', 'listaActividades');
    }

    /** Productos/servicios homologados por el SIN para las actividades del emisor (por emisor). */
    public function sincronizarProductosServicios(): array
    {
        return $this->sincronizar('sincronizarListaProductosServicios', 'RespuestaListaProductos', 'listaCodigos');
    }

    /** Catálogo de unidades de medida (global). */
    public function sincronizarUnidadesMedida(): array
    {
        return $this->sincronizar('sincronizarParametricaUnidadMedida', 'RespuestaListaParametricas', 'listaCodigos');
    }

    /** Leyendas obligatorias por actividad económica, para alternar en la representación gráfica (global). */
    public function sincronizarLeyendas(): array
    {
        return $this->sincronizar('sincronizarListaLeyendasFactura', 'RespuestaListaParametricasLeyendas', 'listaLeyendas');
    }

    /** Catálogo de tipos de documento de identidad (global). */
    public function sincronizarTiposDocumentoIdentidad(): array
    {
        return $this->sincronizar('sincronizarParametricaTipoDocumentoIdentidad', 'RespuestaListaParametricas', 'listaCodigos');
    }

    /** Catálogo de métodos de pago (global). */
    public function sincronizarMetodosPago(): array
    {
        return $this->sincronizar('sincronizarParametricaTipoMetodoPago', 'RespuestaListaParametricas', 'listaCodigos');
    }

    /** Catálogo de motivos de anulación (global). */
    public function sincronizarMotivosAnulacion(): array
    {
        return $this->sincronizar('sincronizarParametricaMotivoAnulacion', 'RespuestaListaParametricas', 'listaCodigos');
    }

    /** Catálogo de tipos de moneda (global). */
    public function sincronizarTiposMoneda(): array
    {
        return $this->sincronizar('sincronizarParametricaTipoMoneda', 'RespuestaListaParametricas', 'listaCodigos');
    }

    /** Catálogo de tipos de documento sector (global) — qué Sector/Documento tiene habilitado emitir el NIT. */
    public function sincronizarTiposDocumentoSector(): array
    {
        return $this->sincronizar('sincronizarParametricaTipoDocumentoSector', 'RespuestaListaParametricas', 'listaCodigos');
    }

    /** Catálogo de motivos de Evento Significativo (global) — distinto de la tabla de eventos ya ocurridos. */
    public function sincronizarMotivosEventoSignificativo(): array
    {
        return $this->sincronizar('sincronizarParametricaEventosSignificativos', 'RespuestaListaParametricas', 'listaCodigos');
    }

    /**
     * Deriva de reloj: compara la hora oficial del SIN contra la hora local
     * del servidor. No es un catálogo — es un chequeo de diagnóstico para
     * detectar un desfase que provocaría rechazos de fechaEmision.
     *
     * @return array{success: bool, fechaHora?: string, deriva_segundos?: int, mensaje?: string}
     */
    public function sincronizarFechaHora(): array
    {
        $cuis = $this->getActiveCuis();
        if (!$cuis) {
            return ['success' => false, 'mensaje' => 'Sin CUIS para consultar la hora del SIN.'];
        }

        $params = ['SolicitudSincronizacion' => array_merge($this->getBaseParameters(false), ['cuis' => $cuis])];

        try {
            $client   = $this->getSoapClient('FacturacionSincronizacion');
            $response = $client->sincronizarFechaHora($params);
            $r = $response->RespuestaFechaHora ?? null;

            if (!$r || empty($r->transaccion) || empty($r->fechaHora)) {
                $this->registrarTransaccion('sincronizarFechaHora', $params, $response, SiatTransaccion::ESTADO_RECHAZO);
                return ['success' => false, 'mensaje' => $this->extractSiatMessage($r)];
            }

            $this->registrarTransaccion('sincronizarFechaHora', $params, $response, SiatTransaccion::ESTADO_EXITO);

            $horaSin   = Carbon::parse($r->fechaHora);
            $derivaSeg = (int) round(Carbon::now()->diffInSeconds($horaSin, false));

            return ['success' => true, 'fechaHora' => $r->fechaHora, 'deriva_segundos' => $derivaSeg];
        } catch (SoapFault $fault) {
            Log::error('SIAT sincronizarFechaHora: ' . $fault->getMessage());
            $offline = $this->isNetworkFailure($fault);
            $this->registrarTransaccion('sincronizarFechaHora', $params, $fault->getMessage(),
                $offline ? SiatTransaccion::ESTADO_OFFLINE : SiatTransaccion::ESTADO_RECHAZO);
            return ['success' => false, 'offline' => $offline, 'mensaje' => $fault->getMessage()];
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

    /**
     * Normaliza mensajesList (mensajeRecepcion: codigo, descripcion,
     * advertencia, numeroArchivo, numeroDetalle — confirmado en el WSDL
     * real de ServicioFacturacionCompraVenta) a un array plano de arrays.
     * El SoapClient colapsa un solo elemento a un objeto suelto en vez de
     * un array de 1, igual que en sincronizar().
     *
     * @return array<int, array{codigo: ?int, descripcion: ?string, advertencia: bool, numeroArchivo: ?int, numeroDetalle: ?int}>
     */
    private function normalizarMensajesRecepcion($response): array
    {
        if (!$response || empty($response->mensajesList)) {
            return [];
        }

        $mensajes = $response->mensajesList;
        if (is_object($mensajes)) {
            $mensajes = [$mensajes];
        }

        return collect($mensajes)->map(fn ($m) => [
            'codigo'        => $m->codigo ?? null,
            'descripcion'   => $m->descripcion ?? null,
            'advertencia'   => (bool) ($m->advertencia ?? false),
            'numeroArchivo' => isset($m->numeroArchivo) ? (int) $m->numeroArchivo : null,
            'numeroDetalle' => isset($m->numeroDetalle) ? (int) $m->numeroDetalle : null,
        ])->all();
    }
}
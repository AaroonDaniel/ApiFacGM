# Pasarela de Facturación Electrónica (SIN Bolivia)

API REST que conecta cualquier sistema (ERP, POS, e-commerce, etc.) con el
Sistema de Facturación Virtual del Servicio de Impuestos Nacionales (SIN)
de Bolivia. Un solo endpoint recibe los datos de una venta y se encarga de
todo lo demás: numeración, CUF, XML, envío al SIAT, y — si el SIAT está
caído — contingencia offline automática con reconciliación posterior.

Diseño multi-tenant: cualquier cantidad de **emisores** (NITs), cada uno
con sus propias credenciales, y cualquier cantidad de **sistemas cliente**
externos autenticados con su propia API key.

## Índice

- [Requisitos y arranque](#requisitos-y-arranque)
- [Autenticación](#autenticación)
- [Endpoints](#endpoints)
  - [POST /api/facturas](#post-apifacturas--emitir-una-factura)
  - [GET /api/facturas/{id}](#get-apifacturasid--consultar-una-factura)
  - [POST /api/facturas/{id}/anular](#post-apifacturasidanular--anular-una-factura)
- [Códigos de actividad y producto válidos](#códigos-de-actividad-y-producto-válidos)
- [Múltiples puntos de venta](#múltiples-puntos-de-venta)
- [Contingencia offline](#contingencia-offline)
- [Comandos artisan](#comandos-artisan)
- [Tests](#tests)
- [Checklist antes de producción](#checklist-antes-de-producción)

## Requisitos y arranque

- PHP 8.2+, extensión `soap` habilitada, PostgreSQL.
- `composer install`
- Copiar `.env.example` a `.env`, configurar la conexión a PostgreSQL y
  correr `php artisan key:generate`.
- `php artisan migrate`
- `php artisan serve`

Sembrar el primer emisor (ver `database/scripts/actualizar_emisor.php`
para un script reutilizable) y crear el sistema cliente que va a consumir
la API:

```bash
php artisan sistema:crear MI_ERP "Mi sistema ERP"
```

Esto imprime una API key **una sola vez** — se guarda hasheada (SHA-256),
no hay forma de recuperarla después; solo de regenerarla con `--regenerar`.

## Autenticación

Todas las rutas bajo `/api/facturas` requieren un header:

```
Authorization: Bearer <api_key>
```

| Situación | Respuesta |
|---|---|
| Sin header | `401` — `"Falta el header Authorization: Bearer <api_key>."` |
| Key inválida o sistema inactivo | `401` — `"API key inválida o sistema inactivo."` |

Además hay **rate limiting** en dos capas: 60 peticiones/minuto por IP
(antes de autenticar, corta abuso/fuerza bruta) y 120/minuto por sistema
cliente ya autenticado. Al superarlo: `429 Too Many Requests`.

## Endpoints

### `POST /api/facturas` — emitir una factura

```json
{
  "emisor_nit": "3327479013",
  "punto_venta": { "sucursal": 0, "punto_venta": 0 },
  "referencia_externa": "orden_12345",
  "metodo_pago": 1,
  "descuento_adicional": 0,
  "cliente": {
    "nombre_razon_social": "Juan Perez",
    "tipo_documento": 1,
    "numero_documento": "1234567",
    "complemento": null
  },
  "detalle": [
    {
      "actividad_economica": "620000",
      "codigo_producto_sin": "83131",
      "codigo_producto": "P001",
      "descripcion": "Servicio de consultoría TI",
      "cantidad": 1,
      "unidad_medida": 57,
      "precio_unitario": 100.00,
      "descuento": 0
    }
  ]
}
```

Campos opcionales: `punto_venta` (por defecto usa el (0,0) del emisor —
ver [Múltiples puntos de venta](#múltiples-puntos-de-venta)),
`referencia_externa`, `descuento_adicional`, `cliente.complemento`,
`detalle[].descuento`.

**`referencia_externa` habilita idempotencia**: reenviar la misma
petición (mismo emisor + mismo sistema cliente + misma referencia) NO
crea una factura duplicada — devuelve la que ya existe.

Respuesta (siempre con el mismo formato, cambia el `siat_estado`):

```json
{
  "exito": true,
  "factura": {
    "id": 42,
    "numero": 17,
    "cuf": "E3ACE3F0A8D8AD...",
    "estado": "vigente",
    "siat_estado": "aceptada",
    "codigo_recepcion": "66c30840-...",
    "fecha_emision": "2026-08-03T15:27:42.000",
    "motivo_anulacion": null,
    "fecha_anulacion": null
  }
}
```

| `siat_estado` | HTTP | `exito` | Significado |
|---|---|---|---|
| `aceptada` | 201 | `true` | El SIAT recibió y aceptó la factura. |
| `offline` | 200 | `true` | El SIAT estaba inalcanzable — la factura quedó resguardada y se reenviará sola cuando vuelva la conexión (ver [Contingencia offline](#contingencia-offline)). |
| `empaquetada` | 200 | `false` | Offline ya reconciliado y el paquete fue enviado al SIAT; falta la validación final. |
| `rechazada` | 422 | `false` | El SIAT rechazó la factura (datos inválidos según el padrón del contribuyente — ver más abajo). |
| `error` | 200 | `false` | Fallo interno inesperado. La factura quedó creada (con su `id`/`numero` visibles) para poder darle seguimiento. |
| `pendiente` | — | — | Estado transitorio, no debería verse en una respuesta HTTP. |

Errores antes de intentar emitir:

| Caso | HTTP |
|---|---|
| Validación de campos (faltantes, tipos, negativos) | 422 |
| NIT de emisor inexistente o inactivo | 422 |
| Punto de venta inexistente o inactivo | 422 |

### `GET /api/facturas/{id}` — consultar una factura

Mismo formato de respuesta que arriba, siempre `HTTP 200` (es una lectura,
no un intento de emisión). `403` si la factura pertenece a otro sistema
cliente.

### `POST /api/facturas/{id}/anular` — anular una factura

```json
{ "codigo_motivo": 1 }
```

Motivos válidos (tabla `motivos_anulacion`, sembrada con los reales del
SIN vía `sincronizarParametricaMotivoAnulacion`):

| Código | Descripción |
|---|---|
| 1 | Factura mal emitida |
| 2 | Nota de crédito-débito mal emitida |
| 3 | Datos de emisión incorrectos |
| 4 | Factura o nota de crédito-débito devuelta |

Solo se pueden anular facturas con `siat_estado: aceptada`. Si el SIAT
no responde al intentar anular, la factura **no** se marca anulada
localmente (evita desincronizar la base del padrón real) y se devuelve
`422` con el motivo del rechazo.

## Códigos de actividad y producto válidos

`actividad_economica` y `codigo_producto_sin` **no son libres** — tienen
que ser los que el SIN tiene registrados para el NIT del emisor,
o el SIAT rechaza la factura entera. No hay lista fija: cada NIT tiene
las suyas, según su Padrón. Para consultarlas (requiere CUIS activo del
emisor):

```php
php artisan tinker
```
```php
$emisor = App\Models\Emisor::find(1);
$siat = new App\Services\SiatService($emisor);
$cuis = $siat->getActiveCuis();

$ref = new ReflectionClass(App\Services\SiatService::class);
$m = $ref->getMethod('getSoapClient'); $m->setAccessible(true);
$client = $m->invoke($siat, 'FacturacionSincronizacion');

$params = ['SolicitudSincronizacion' => [
    'codigoAmbiente' => $emisor->emiamb, 'codigoPuntoVenta' => 0,
    'codigoSistema' => $emisor->emisis, 'codigoSucursal' => 0,
    'cuis' => $cuis, 'nit' => (int) $emisor->eminit,
]];

print_r($client->sincronizarActividades($params['SolicitudSincronizacion']));
print_r($client->sincronizarListaProductosServicios($params['SolicitudSincronizacion']));
```

## Múltiples puntos de venta

Un mismo NIT (fila de `emisores`, con el token compartido por todo el
sistema) puede tener varias sucursales/puntos de venta — cada uno con su
propia numeración y credenciales CUFD ante el SIAT.

Dar de alta uno nuevo:

```bash
php artisan punto-venta:crear <NIT> <sucursal> <punto_venta> --desde=0
```

`--desde` es el último número ya emitido para esa dosificación (la
próxima factura será ese +1). Una vez creado, se referencia en el
request como `"punto_venta": {"sucursal": N, "punto_venta": M}`. Si se
omite, usa el (0,0) del emisor.

## Contingencia offline

Si el SIAT está inalcanzable (red caída, servidor del SIN caído — no
credenciales inválidas, eso se trata como error real), el sistema:

1. Guarda la factura como `offline` con su XML en disco.
2. La acopla a un **evento significativo** (uno por emisor+punto de
   venta+corte, se reutiliza mientras la caída siga activa).
3. Al detectar que la conexión volvió (la siguiente factura se acepta
   online), reconcilia solo: registra el evento ante el SIAT de forma
   retroactiva (con el período real de la caída), arma el `.tar.gz` con
   todas las facturas acopladas, lo envía, y consulta su validación.

Todo esto es automático — no requiere ninguna acción manual. Pero si un
emisor no vuelve a facturar pronto después de una caída (bajo volumen),
nada dispararía la reconciliación por sí solo; para eso existe:

```bash
php artisan siat:procesar-contingencias
```

Agendado cada 5 minutos en `routes/console.php`. Para que efectivamente
corra solo, hace falta tener corriendo `php artisan schedule:work` (dev)
o un cron real apuntando a `php artisan schedule:run` cada minuto (prod).

## Comandos artisan

| Comando | Qué hace |
|---|---|
| `sistema:crear <siscod> <sisnom> [--regenerar]` | Da de alta un sistema cliente y genera su API key. |
| `punto-venta:crear <nit> <sucursal> <pdv> [--desde=N]` | Da de alta un punto de venta adicional y su secuencia. |
| `siat:procesar-contingencias` | Reconcilia eventos offline pendientes y valida paquetes ya enviados. Agendado cada 5 min. |

## Tests

```bash
composer test
```

Requiere una base Postgres de test dedicada (`dbfacturacion_test` por
defecto, ver `phpunit.xml`) — las migraciones usan SQL específico de
Postgres, no corren en sqlite.

Los tests cubren todo lo que **no** requiere hablar con el SIAT real:
algoritmo del CUF, estructura del XML, autenticación, validación,
autorización, reglas de negocio de anulación, idempotencia, rate
limiting. Que una factura sea *genuinamente aceptada* por el SIAT solo
se verifica en vivo contra el ambiente piloto — automatizarlo
implicaría simular al SIAT, lo que le quitaría el valor a la prueba.

## Checklist antes de producción

- [ ] `APP_DEBUG=false` — con `true`, las respuestas de error exponen
      stack traces completos con rutas del servidor.
- [ ] Confirmar `SIAT_URL_PRODUCCION` probando su WSDL en vivo (no
      asumir por analogía con la de piloto — ya hubo un caso real de
      un host que no seguía el mismo patrón).
- [ ] `CORS_ALLOWED_ORIGINS` con los orígenes reales permitidos (en
      `local` cualquier `localhost`/`127.0.0.1` pasa automático; fuera
      de `local`, por defecto no se permite ningún origen).
- [ ] Emisor(es) reales cargados con `emiamb=1` (producción) solo
      después de confirmar credenciales y URL reales — probar primero
      en piloto (`emiamb=2`).
- [ ] `php artisan schedule:run` corriendo por cron (o equivalente) para
      que la reconciliación de contingencias sea efectivamente automática.

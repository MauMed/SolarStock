# Integración con el SIAT — Documentación técnica

Facturación computarizada en línea (modalidad 2) contra los servicios web del
Servicio de Impuestos Nacionales de Bolivia, implementada en `src/Siat/`.

> **Alcance**: esta guía documenta lo implementado en SolarStock: emisión en
> línea del documento sector **1 (compra-venta)**, contingencia por paquetes,
> anulación, lectura de facturas recibidas y conciliación de inventario.
> Otros documentos sector (hoteles, educación, notas de crédito-débito) se
> agregan extendiendo `XmlFactura`.

---

## Índice

1. [Arquitectura del módulo](#1-arquitectura-del-módulo)
2. [Requisitos previos ante el SIN](#2-requisitos-previos-ante-el-sin)
3. [Configuración en SolarStock](#3-configuración-en-solarstock)
4. [Credenciales: token, CUIS y CUFD](#4-credenciales-token-cuis-y-cufd)
5. [El algoritmo del CUF](#5-el-algoritmo-del-cuf)
6. [Construcción del XML](#6-construcción-del-xml)
7. [Flujo de emisión en línea](#7-flujo-de-emisión-en-línea)
8. [Contingencia y eventos significativos](#8-contingencia-y-eventos-significativos)
9. [Anulación de facturas](#9-anulación-de-facturas)
10. [Validación de clientes](#10-validación-de-clientes)
11. [Código QR reglamentario e impresión](#11-código-qr-reglamentario-e-impresión)
12. [Lectura de facturas recibidas](#12-lectura-de-facturas-recibidas)
13. [Conciliación de inventario contra el SIN](#13-conciliación-de-inventario-contra-el-sin)
14. [Cron jobs](#14-cron-jobs)
15. [Estados del sistema y monitoreo](#15-estados-del-sistema-y-monitoreo)
16. [Endpoints REST del módulo](#16-endpoints-rest-del-módulo)
17. [Homologación y pruebas](#17-homologación-y-pruebas)
18. [Solución de problemas](#18-solución-de-problemas)
19. [Glosario](#19-glosario)

---

## 1. Arquitectura del módulo

```
┌─────────────────────────────────────────────────────────────┐
│                        SolarStock                            │
│                                                              │
│  Facturador ──► Cufd ──► SoapSin ─────► WS SIAT (SOAP)      │
│      │            │                       │                  │
│      ├─► Cuf      └─► siat_cufd (hist.)   ├─ FacturacionCodigos
│      ├─► XmlFactura                       ├─ FacturacionSincronizacion
│      │                                    ├─ FacturacionOperaciones
│  Contingencia ◄── cron 30 min             └─ ServicioFacturacionCompraVenta
│  EstadoSiat   ◄── badge de la app                            │
│  LectorFacturas / Conciliacion  (facturas recibidas / SIN)   │
└─────────────────────────────────────────────────────────────┘
```

| Clase | Responsabilidad |
|---|---|
| `SoapSin` | Cliente SOAP: token en cabecera, timeouts, reintentos, clasificación de errores (¿activa contingencia?) |
| `Cufd` | Solicitud y vigencia de CUIS (anual) y CUFD (24 h), con historial inmutable |
| `Cuf` | Cálculo del Código Único de Facturación (padding → Módulo 11 → hex BCMath → + código de control) |
| `XmlFactura` | Armado del XML compra-venta, limpieza de caracteres, `xsi:nil`, URL del QR |
| `Facturador` | Orquestación de la emisión, anulación y actualización de estados |
| `Contingencia` | Eventos significativos, empaquetado `.tar.gz` y envío diferido |
| `LectorFacturas` | Registro de facturas **recibidas** (QR e importación CSV) |
| `Conciliacion` | Cruce del registro de ventas del SIN contra el inventario |
| `EstadoSiat` | Estado real de la conexión para la interfaz (nunca se asume "en línea") |

**Tablas**: `siat_config`, `siat_cufd`, `factura`, `factura_recibida`,
`siat_evento`, `siat_log`, `siat_actividad`, `siat_producto_sin`,
`siat_parametro`, `sin_venta_externa`, `sin_venta_externa_item`.

---

## 2. Requisitos previos ante el SIN

Antes de tocar SolarStock, el contribuyente debe (en el portal SIAT del SIN):

1. **Registrar el sistema de facturación** (obtiene el **código de sistema**).
2. **Generar el Token Delegado** — vigencia limitada; SolarStock alerta 15
   días antes del vencimiento y **bloquea la emisión** cuando expira.
3. Contar con las **sucursales y puntos de venta** dados de alta en el SIN.
   La Casa Matriz siempre es **código 0**.
4. Conocer sus **actividades económicas** y los **códigos de producto SIN**
   (homologación) para mapearlos al catálogo.

> Las **URLs WSDL** de `config/config.php` (ambientes `HOMOLOGACION` y
> `PRODUCCION`) y los **nombres exactos de los campos de respuesta SOAP**
> deben verificarse contra la documentación técnica que el SIN entrega al
> registrar el sistema: varían por versión del servicio.

---

## 3. Configuración en SolarStock

Todo se administra desde la pantalla **SIAT** (permiso `siat.gestionar`):

1. **Activar el módulo** — `empresa.facturacion_activa = 1`. Apagado, el
   sistema opera como inventario + invoice sin tocar al SIN.
2. **Cargar credenciales por sucursal**: NIT emisor, código de sistema,
   token delegado, fecha de vencimiento del token y ambiente. Cada sucursal
   (y opcionalmente cada punto de venta) tiene su propia fila en `siat_config`.
3. **Probar conexión** — ping SOAP real (`sincronizarFechaHora`) con latencia.
4. **Solicitar CUIS** (una vez, dura ~1 año) y **Solicitar CUFD** (el cron lo
   renueva luego automáticamente).
5. **Homologar productos**: llenar `codigo_actividad_sin` y
   `codigo_producto_sin` en cada producto. La emisión falla con
   `HOMOLOGACION_FALTANTE` si un ítem no los tiene.

---

## 4. Credenciales: token, CUIS y CUFD

| Credencial | Vigencia | Manejo en SolarStock |
|---|---|---|
| **Token Delegado** | Meses (definida al crearlo) | Cabecera `apikey: TokenApi <token>` en cada llamada. Alerta a 15 días (`siat_log`), bloqueo al vencer (`TOKEN_EXPIRADO`). |
| **CUIS** (Código Único de Inicio de Sistema) | ~1 año | `Cufd::solicitarCuis()`. Guardado en `siat_config.cuis` + fecha. |
| **CUFD** (Código Único de Facturación Diaria) | 24 horas | `Cufd::asegurar()`: renueva si vence en < 60 min. **El historial nunca se sobrescribe** (`siat_cufd`): el `codigo_control` histórico es necesario para auditar facturas antiguas. |

Regla operativa clave: si el SIN está caído y el CUFD venció, **se emite en
contingencia con el último CUFD almacenado** y se regulariza después.

---

## 5. El algoritmo del CUF

Implementado en `Cuf::generar()` (requiere extensión **BCMath**):

**Paso 1 — Formatear 9 variables** con ceros a la izquierda:

| # | Variable | Longitud | Ejemplo |
|---|---|---|---|
| 1 | NIT emisor | 13 | `0000123456789` |
| 2 | Fecha/hora `YmdHisvvv` (con milisegundos) | 17 | `20260808153045123` |
| 3 | Código de sucursal | 4 | `0000` |
| 4 | Modalidad (2 = computarizada en línea) | 1 | `2` |
| 5 | Tipo de emisión (1 en línea / 2 contingencia) | 1 | `1` |
| 6 | Tipo de factura (1 con crédito fiscal) | 1 | `1` |
| 7 | Documento sector (1 compra-venta) | 2 | `01` |
| 8 | Número de factura (correlativo) | 10 | `0000000001` |
| 9 | Código punto de venta | 4 | `0000` |

**Paso 2 — Concatenar** en ese orden estricto.

**Paso 3 — Dígito Módulo 11**: pesos cíclicos 2..9 de derecha a izquierda;
si el resultado es 10 → `1`, si es 11 → `0`. Se anexa a la cadena.

**Paso 4 — Base 16**: la cadena numérica completa (¡53 dígitos!) se convierte
a hexadecimal con BCMath (`Cuf::base16()`), porque excede los enteros nativos.

**Paso 5 — Código de control**: se concatena el `codigoControl` del **CUFD
vigente al momento de la emisión**.

```
CUF = HEX( cadena + dígitoMod11 ) + codigoControl
```

El flag `$aplicarSha256` cubre variantes de sectores que exigen SHA-256 sobre
la cadena final — **validar contra el set de pruebas de homologación** cuál
aplica a tu registro.

Ejemplo verificado por los tests del proyecto:

```
fechaCuf('2026-08-08 15:30:45.123') = 20260808153045123   (17 caracteres)
base16('255') = FF
CUF(...) = 8727F63A194B8D26D7C94A235AFC63965208606A5A1B2C3D4E5F6
```

---

## 6. Construcción del XML

`XmlFactura::compraVenta()` genera `facturaComputarizadaCompraVenta` con:

- **Cabecera**: emisor, cliente, CUF, CUFD, fecha ISO 8601 **con
  milisegundos** (`Y-m-d\TH:i:s.v`), montos con 2 decimales, leyenda Ley 453.
- **Detalle** por ítem: actividad económica, código producto SIN, código
  interno, glosa (`nombre - descripción` del producto), cantidad, unidad,
  precio, descuento y subtotal.
- **Campos vacíos** → `xsi:nil="true"` (nunca etiquetas vacías).
- **Limpieza obligatoria** (`XmlFactura::limpiar()`): mayúsculas, sin
  `" < >`, `&` → `Y`, espacios colapsados. Ej.:
  `Empresa "X" & Cía <SRL>` → `EMPRESA X Y CÍA SRL`.

El XML **exacto** enviado se guarda en `factura.xml` (LONGTEXT) — es el mismo
que se reempaqueta si la factura termina yendo por contingencia.

---

## 7. Flujo de emisión en línea

`Facturador::emitirDesdeVenta($ventaId)` — disparado por
`POST /api/siat/emitir` o automáticamente desde `POST /api/v1/ventas` con
`"facturar": true`:

```
venta ──► 1. ¿cliente NIT? → verificarNit (rechaza INEXISTENTE/INACTIVO)
      ──► 2. CUFD vigente (renueva si hace falta; si SIN caído → contingencia)
      ──► 3. correlativo por siat_config (sucursal + PDV)
      ──► 4. CUF (algoritmo §5)
      ──► 5. XML (§6) + validación de homologación por ítem
      ──► 6. INSERT factura (estado PENDIENTE, XML guardado)
      ──► 7. gzip(XML) + hash SHA-256 ──► recepcionFactura (SOAP)
              ├─ "VALIDA"    → estado VALIDA + codigo_recepcion
              ├─ rechazo     → estado RECHAZADA + mensajesList en error_descripcion
              └─ SIN caído   → tipo_emision = 2, evento abierto, queda PENDIENTE
```

**Clasificación de caída** (`SoapSin::llamar`): timeout, `502`, `503`,
`could not connect`, código `902` o fallo de carga del WSDL → se considera
caída del SIN y habilita contingencia. Cualquier otro `SoapFault` es un error
de validación y **no** activa contingencia.

Estados de `factura`:

```
PENDIENTE → EN_PROCESAMIENTO → VALIDA
     │              │
     └──────────────┴────────→ RECHAZADA        VALIDA → ANULADA
```

---

## 8. Contingencia y eventos significativos

Cuando el SIN no responde, la venta **no se detiene**: la factura se emite con
`tipoEmision = 2` usando el último CUFD disponible, y `Contingencia::abrirEvento()`
registra un evento significativo local (códigos: `1` corte de internet,
`2` caída del servicio del SIN, `3` corte del WS de la Administración Tributaria).

El **cron de 30 minutos** (`Contingencia::procesar`) ejecuta el ciclo completo:

1. **Ping** al SIN (`sincronizarFechaHora`). Si sigue caído, termina.
2. **Cierra** el evento abierto (registra `fecha_fin`).
3. **Reporta** el evento (`registroEventoSignificativo`) → obtiene el
   `codigoRecepcionEventoSignificativo`.
4. **Empaqueta** todos los XML `PENDIENTE`/`tipo_emision=2` en un `.tar.gz`
   (PharData) y lo envía por `recepcionPaqueteFactura` (con hash, cantidad de
   facturas y el código del evento). Las facturas pasan a `EN_PROCESAMIENTO`.
5. **Valida** los paquetes enviados (`validacionRecepcionPaqueteFactura`):
   `VALIDA` en bloque, o `RECHAZADA` con los mensajes de error por factura.
6. **Alerta de plazo**: si hay facturas con más de **36 horas** sin regularizar
   (el límite legal es **48 h** desde el fin del evento), se escribe una
   alerta en `siat_log`.

Si el envío del paquete falla, las facturas **vuelven a PENDIENTE** y se
reintenta en el siguiente ciclo.

---

## 9. Anulación de facturas

`Facturador::anular($facturaId, $codigoMotivo)` — `POST /api/siat/anular`:

- Solo facturas en estado `VALIDA`.
- **Plazo**: hasta el **día 9 del mes siguiente** a la emisión (23:59:59).
  Pasado el plazo el sistema responde `PLAZO_VENCIDO` y corresponde nota de
  crédito/débito.
- **Motivos paramétricos** (`siat_parametro`, grupo `MOTIVO_ANULACION`):
  `1` factura mal emitida · `2` nota de crédito/devolución · `3` totalmente
  devuelto · `4` error de tipeo o sistema.
- La llamada `anulacionFactura` usa el **CUFD del día actual**, no el de la
  emisión original.
- Al confirmarse: `factura.estado = ANULADA`, la venta asociada pasa a
  `ANULADA` y **el stock se revierte** con movimientos `DEVOLUCION` en el
  kardex (referencia `anulacion_factura`).

---

## 10. Validación de clientes

Tipos de documento (grupo `TIPO_DOC`): `1` CI · `2` CI extranjero ·
`3` Pasaporte · `4` NIT · `5` DNI.

- **NIT (tipo 4)**: antes de emitir se llama `verificarNit`. `INEXISTENTE` o
  `INACTIVO` → error `NIT_INVALIDO` y no se emite. Si el SIN está caído, se
  confía en el dato del cajero y se emite en contingencia.
- **Complemento de CI**: máximo 3 caracteres, para carnets duplicados
  (`1A`, `1B`...). **Nunca** es la extensión de ciudad (LP, SC, CB).
- **Venta sin datos**: cliente genérico `99001 / CONTROL TRIBUTARIO`
  (aplicado automáticamente cuando la venta no tiene cliente). Existe también
  el genérico `99002`.
- El snapshot completo del cliente queda en `factura.cliente_snapshot` (JSON)
  para auditoría, aunque el cliente se edite después.

---

## 11. Código QR reglamentario e impresión

URL del QR (`XmlFactura::urlQr`):

```
{qr_base}?nit={nitEmisor}&cuf={CUF}&numero={nroFactura}&t={montoTotal}
```

`qr_base` depende del ambiente (`config.php`). En impresión térmica el QR se
genera **nativo ESC/POS** (`EscPos::qr`, comando GS ( k) con corrección de
errores **M** y tamaño de módulo 6 — cumple el mínimo de 2,5 cm en papel de
80 mm. El ticket incluye las leyendas obligatorias (Ley 453 y "ESTA FACTURA
CONTRIBUYE AL DESARROLLO DEL PAÍS...").

Rutas de impresión: térmica de **red** (socket TCP al puerto RAW 9100 desde el
servidor), térmica **Bluetooth** (bytes ESC/POS en base64 transmitidos por Web
Bluetooth desde Chrome/Android, chunks de 180 bytes), y **láser/tinta o PDF**
(vista imprimible carta/80 mm con la marca del cliente).

---

## 12. Lectura de facturas recibidas

Para el crédito fiscal del contribuyente (`LectorFacturas`):

- **Escaneo del QR** de la factura del proveedor (cámara o lector):
  `POST /api/siat/facturas-recibidas/qr` con `qr_url`. Se parsean
  `nit, cuf, numero, monto` y se registra en `factura_recibida`
  (origen `QR_SCAN`), opcionalmente **enlazada a una compra** — el módulo de
  Compras permite escanear el QR en el mismo flujo de la compra.
- **Importación masiva**: `LectorFacturas::importarCsv()` procesa el CSV del
  **registro de compras** exportado de la oficina virtual del SIN (separador
  `;`, tolerante a variantes de cabecera). Origen `IMPORT_SIAT`.

`INSERT IGNORE` + índice único `(empresa, nit, número, cuf)` garantizan
idempotencia: reimportar no duplica.

---

## 13. Conciliación de inventario contra el SIN

Caso de uso: el cliente **también factura desde otro sistema** (otro POS, la
oficina virtual). Esas ventas no descontaron stock en SolarStock.
`Siat\Conciliacion` lo resuelve:

1. **Entrada** — dos vías:
   - CSV del **registro de ventas** del SIN (`POST /api/conciliacion/import`).
   - Push por API con detalle de ítems
     (`POST /api/v1/conciliacion/ventas`, cuerpo `{cuf, numero_factura,
     monto_total, items:[{codigo, cantidad}]}`).
2. **Cruce** (`conciliar()`): si la factura ya existe en `factura` (match por
   CUF o número) → `CONCILIADA_INTERNA` (el stock ya se descontó al vender).
3. **Descuento automático**: facturas externas cuyos ítems se resuelven por
   `codigo_interno`, `codigo_barras` o `externo_ref` → movimiento `VENTA` en
   el kardex (referencia `conciliacion_sin`) y estado `DESCONTADA`.
4. **Asignación manual**: sin ítems o con códigos no reconocidos →
   `EXTERNA_PENDIENTE`; el operador asigna productos desde la pantalla
   Conciliar (`POST /api/conciliacion/asignar`).
5. **Cuadre** (`GET /api/conciliacion/cuadre`): stock disponible por producto
   + resumen por estado + pendientes.

> El CSV del registro de ventas del SIN trae **totales, no ítems**: el
> descuento 100 % automático requiere que el sistema externo empuje sus
> ventas por API con los códigos de producto.

---

## 14. Cron jobs

| Cron | Frecuencia | Qué hace |
|---|---|---|
| `cron/cufd_renew.php` | cada 10-15 min | Renueva el CUFD si vence en < 60 min; alerta de vencimiento del token (15 días) y bloqueo al expirar; reprocesa webhooks pendientes |
| `cron/contingencia_send.php` | cada 30 min | Ciclo completo de contingencia (§8) + validación de paquetes + alerta de 36 h |

En hosting compartido: panel de cron de cPanel o https://cron-job.org
apuntando a los scripts por CLI (`php /ruta/cron/...`).

---

## 15. Estados del sistema y monitoreo

`GET /api/siat/estado` (`EstadoSiat::calcular`) devuelve el estado **real**,
derivado de datos — el badge de la aplicación lo refresca cada minuto:

| Estado | Significado | Acción |
|---|---|---|
| `DESACTIVADO` | Módulo apagado para la empresa | Activar en pantalla SIAT |
| `NO_CONFIGURADO` | Sin credenciales | Cargar `siat_config` |
| `TOKEN_VENCIDO` | Token delegado expirado (emisión bloqueada) | Renovar en el portal SIAT |
| `SIN_CUIS` | Falta solicitar el CUIS | Botón "Solicitar CUIS" |
| `SIN_CUFD` | Sin código diario vigente | Esperar cron o "Solicitar CUFD" |
| `CONTINGENCIA` | Evento abierto o errores de conexión < 15 min | Automático: el cron regulariza |
| `EN_LINEA` | Operativo (incluye días restantes del token) | — |

El **panel de errores** (`GET /api/siat/panel-errores`) lista facturas
rechazadas con su motivo, pendientes con horas en cola y el `siat_log`
completo (gravedades `CONEXION`, `VALIDACION`, `INFO`).

---

## 16. Endpoints REST del módulo

Autenticación `Authorization: Bearer <token>` salvo indicación. Permiso
requerido entre paréntesis.

| Método | Ruta | Descripción |
|---|---|---|
| `GET` | `/api/siat/estado` | Estado real de la conexión |
| `GET/POST` | `/api/siat/config` | Listar / guardar credenciales por sucursal (`siat.gestionar`) |
| `POST` | `/api/siat/activar` | Encender/apagar el módulo (`siat.gestionar`) |
| `POST` | `/api/siat/config/{id}/probar` | Ping SOAP con latencia (`siat.gestionar`) |
| `POST` | `/api/siat/config/{id}/cuis` | Solicitar CUIS (`siat.gestionar`) |
| `POST` | `/api/siat/config/{id}/cufd` | Solicitar CUFD (`siat.gestionar`) |
| `POST` | `/api/siat/emitir` | Emitir factura desde una venta (`facturas.emitir`) |
| `POST` | `/api/siat/anular` | Anular factura con motivo (`facturas.anular`) |
| `POST` | `/api/siat/verificar-nit` | Verificación de NIT en línea |
| `GET` | `/api/siat/panel-errores` | Rechazadas, pendientes y logs |
| `GET/POST` | `/api/siat/facturas-recibidas[/qr]` | Facturas de proveedores (`facturas.leer`) |
| `POST` | `/api/conciliacion/import` | Importar CSV del registro de ventas (`conciliacion.ejecutar`) |
| `POST` | `/api/conciliacion/ejecutar` | Re-ejecutar el cruce |
| `POST` | `/api/conciliacion/asignar` | Asignación manual de ítems |
| `GET` | `/api/conciliacion/cuadre` | Inventario cuadrado (`conciliacion.ver`) |
| `POST` | `/api/v1/conciliacion/ventas` | Push externo de ventas facturadas (`X-Api-Key`, permiso `ventas.crear`) |
| `POST` | `/api/v1/ventas` | Venta externa con `"facturar": true` (`X-Api-Key`) |

---

## 17. Homologación y pruebas

Checklist antes de pasar a producción:

- [ ] Sistema registrado en el SIN; código de sistema y token cargados en ambiente `HOMOLOGACION`.
- [ ] URLs WSDL verificadas contra la documentación oficial entregada.
- [ ] CUIS y CUFD obtenidos desde la pantalla SIAT (botones de diagnóstico).
- [ ] **NIT de prueba `123456789`** (activo) emite factura `VALIDA`.
- [ ] **NIT `999999999`** (inexistente) es rechazado por `verificarNit`.
- [ ] CI con **complemento** (`1A`) emite correctamente.
- [ ] Algoritmo CUF validado contra el set de pruebas del SIN (activar
      `$aplicarSha256` solo si tu documentación de sector lo exige).
- [ ] Prueba de **contingencia**: cortar la salida a internet, vender, y
      verificar que el cron reporta el evento y envía el paquete al volver.
- [ ] **Anulación** dentro y fuera de plazo (la segunda debe fallar).
- [ ] QR verificado en el portal de consulta del SIN (escanear el ticket).
- [ ] Monto de error provocado y demás casos del set oficial de homologación.
- [ ] Cambiar `ambiente = 'PRODUCCION'` en `siat_config` recién al aprobar.

---

## 18. Solución de problemas

| Síntoma / código | Causa probable | Solución |
|---|---|---|
| `TOKEN_EXPIRADO` | Token delegado vencido | Renovar en el portal SIAT y actualizar en pantalla SIAT |
| `SIN_CUFD: no existe ningún CUFD almacenado` | Nunca se solicitó CUFD y el SIN no responde | Botón "Solicitar CUFD" con el SIN en línea al menos una vez |
| `HOMOLOGACION_FALTANTE: producto X` | Producto sin códigos SIN | Completar `codigo_actividad_sin` y `codigo_producto_sin` |
| `NIT_INVALIDO` | El NIT del cliente no existe/inactivo | Corregir el documento o usar CI |
| `PLAZO_VENCIDO` al anular | Pasó el día 9 del mes siguiente | Emitir nota de crédito/débito |
| Facturas `RECHAZADA` | Ver `error_descripcion` (mensajesList del SIN) | Corregir el dato observado y reemitir |
| Badge en `CONTINGENCIA` permanente | Errores `CONEXION` en `siat_log` cada ciclo | Revisar salida HTTPS del hosting hacia los dominios del SIN, y el token |
| Alerta "facturas a punto de vencer 48h" | Paquete no enviado/validado | Revisar `siat_log`, ejecutar el cron manualmente: `php cron/contingencia_send.php` |
| `Extension BCMath requerida` | PHP sin bcmath | Habilitar en cPanel → Select PHP Version → Extensions |
| SOAP no conecta pero hay internet | WSDL o cabecera de token incorrectos | Verificar URLs y formato `apikey: TokenApi <token>` contra la doc oficial |

Toda llamada fallida queda en `siat_log` con origen, gravedad y descripción.

---

## 19. Glosario

| Término | Definición |
|---|---|
| **SIAT** | Sistema Integrado de Administración Tributaria del SIN |
| **SIN** | Servicio de Impuestos Nacionales (Bolivia) |
| **CUF** | Código Único de Facturación: identificador criptográfico de cada factura |
| **CUFD** | Código Único de Facturación Diaria (24 h), incluye el código de control |
| **CUIS** | Código Único de Inicio de Sistema (anual) |
| **Token Delegado** | Credencial que autoriza al sistema a operar a nombre del contribuyente |
| **Evento significativo** | Registro formal de una interrupción que justifica emisión fuera de línea |
| **Contingencia** | Emisión con `tipoEmision = 2` y envío diferido por paquete (plazo 48 h) |
| **Homologación** | Proceso de certificación del sistema ante el SIN, y mapeo de productos a códigos SIN |
| **Código de control** | Componente del CUFD que se concatena al CUF |
| **Documento sector** | Tipo de documento fiscal (1 = factura compra-venta) |

---

*SolarStock · una solución de Alfa Solaris. Documento técnico interno — verificar siempre contra la normativa y documentación vigente del SIN.*

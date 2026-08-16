<h1 align="center">SolarStock</h1>

<p align="center">
  Control de inventarios, punto de venta y facturación electrónica SIAT (Bolivia) en un solo sistema.<br>
  <em>Una solución de Alfa Solaris</em>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white" alt="PHP 8.1+">
  <img src="https://img.shields.io/badge/MySQL-5.7%2B%20%2F%20MariaDB%2010.3%2B-4479A1?logo=mysql&logoColor=white" alt="MySQL">
  <img src="https://img.shields.io/badge/frontend-PWA%20vanilla%20JS-F7DF1E?logo=javascript&logoColor=black" alt="PWA">
  <img src="https://img.shields.io/badge/SIAT-facturaci%C3%B3n%20computarizada%20en%20l%C3%ADnea-1B6DA8" alt="SIAT">
  <img src="https://img.shields.io/badge/licencia-propietaria-lightgrey" alt="Licencia">
</p>

---

## ¿Qué es SolarStock?

SolarStock es un sistema web **mobile-first** de gestión comercial pensado para pymes bolivianas de cualquier rubro (ferretería, warehouse, plásticos, farmacia, importadoras...). Reúne en una sola aplicación:

- **Inventario en tiempo real** con kardex por movimiento y bloqueo anti-sobreventa
- **Punto de venta táctil** con catálogo visual, escáner y precio negociable
- **Facturación electrónica SIAT** (módulo opcional, activable por empresa)
- **Conciliación automática** contra el registro de ventas del SIN
- **Cuentas por cobrar / pagar** con control de mora a 30 días
- **API abierta** para integrarse con ERPs, CRMs, POS y eCommerce

Corre en hosting compartido (cPanel/Namecheap) sin dependencias de Composer: PHP puro + MySQL + un frontend PWA sin frameworks.

## Características

| Módulo | Qué incluye |
|---|---|
| 📦 **Productos** | Categorías, fotografías, descripciones, códigos de barras, homologación SIN, carga masiva CSV con stock inicial, soft delete |
| 🏪 **Punto de venta** | Grilla visual 3 columnas con fotos, buscador, lector HID y cámara (BarcodeDetector + fallback ZXing), precio editable con trazabilidad, cliente con tipos de documento SIAT |
| 🛒 **Compras** | Entrada de stock con escaneo, proveedores, almacén destino, QR de la factura del proveedor enlazado a la compra |
| 🏬 **Almacenes** | Sucursales alineadas al SIAT (casa matriz = código 0) y almacenes internos ilimitados; ajustes con motivo y traspasos |
| 🧾 **Facturación SIAT** | Emisión en línea, CUFD automático, contingencia con paquetes, anulación en norma, QR reglamentario, lectura de facturas recibidas — ver [documentación completa](docs/SIAT.md) |
| 🔄 **Conciliación** | Importa el registro de ventas del SIN o recibe ventas por API; cruza, ubica productos por código y descuenta stock solo |
| 💰 **Pagos** | Toda venta nace como CxC y toda compra como CxP; botón "Pagada", mora automática a +30 días |
| 📊 **Informes** | 9 informes a nivel de producto (ventas, compras, margen, kardex, inventario valorizado, facturas, CxC, CxP), resumen contable con 15 KPIs, export CSV compatible con Excel |
| 👥 **Usuarios** | 5 roles, 20+ permisos granulares por persona, reset de contraseña por correo |
| 🎨 **Marca blanca** | Logo, colores, nombre comercial y color del menú por cliente; comprobantes e invoice PDF con la identidad del cliente |
| 🖨️ **Periféricos** | Térmicas de red (RAW 9100) y Bluetooth (Web Bluetooth), láser/tinta vía impresión del navegador, formatos carta y 80 mm |
| 🔌 **Integraciones** | API keys con permisos, webhooks firmados HMAC-SHA256, asistente de conexión con SolarCRM sin código, playground de pruebas |

## Estructura del proyecto

```
inventario-sin/
├── public/               # Document root
│   ├── index.php         # Front controller + router REST
│   ├── app.html          # Aplicación PWA (móvil + escritorio)
│   ├── assets/           # scanner.js, print-bt.js
│   └── uploads/          # Logos y fotos de productos
├── config/config.php     # BD, correo y URLs SOAP del SIAT
├── database/
│   ├── schema.sql        # Esquema base
│   └── upgrade_v2..v7.sql# Migraciones incrementales
├── src/
│   ├── Core/             # DB (PDO), Auth + Permisos, Response
│   ├── Api/              # Inventario (kardex, ventas)
│   ├── Siat/             # Cuf, Cufd, SoapSin, XmlFactura, Facturador,
│   │                     # Contingencia, LectorFacturas, Conciliacion, EstadoSiat
│   ├── Print/            # EscPos (tickets, QR nativo, socket 9100)
│   └── Integrations/     # Webhooks
├── cron/
│   ├── cufd_renew.php    # cada 10-15 min
│   └── contingencia_send.php  # cada 30 min
└── storage/              # xml/, pdf/, logs/
```

## Requisitos

- PHP **8.1+** con extensiones: `pdo_mysql`, `soap`, `bcmath`, `mbstring`, `xmlwriter`, `curl`, `phar`, `zlib`
- MySQL 5.7+ / MariaDB 10.3+
- HTTPS (obligatorio para cámara, Web Bluetooth y PWA)
- 2 cron jobs (panel del hosting o cron-job.org)

## Instalación rápida

```bash
# 1. Clonar y apuntar el document root a /public
git clone https://github.com/alfa-solaris/solarstock.git
# 2. Base de datos
mysql -u root -p < database/schema.sql
for f in database/upgrade_v*.sql; do mysql -u root -p < "$f"; done
# 3. Configurar credenciales (o variables de entorno DB_HOST, DB_NAME, DB_USER, DB_PASS)
nano config/config.php
```

Crear la empresa y el primer usuario administrador:

```sql
INSERT INTO empresa (nit, razon_social) VALUES ('123456789', 'MI EMPRESA SRL');
INSERT INTO sucursal (empresa_id, codigo_sin, nombre) VALUES (1, 0, 'Casa Matriz');
INSERT INTO punto_venta (sucursal_id, codigo_sin, nombre) VALUES (1, 0, 'Caja 1');
INSERT INTO almacen (sucursal_id, nombre) VALUES (1, 'Principal');
INSERT INTO usuario (empresa_id, nombre, email, pass_hash, rol)
VALUES (1, 'Admin', 'admin@miempresa.com', '<HASH>', 'admin');
-- <HASH>: php -r "echo password_hash('MiClaveSegura', PASSWORD_DEFAULT);"
```

Registrar los cron jobs:

```cron
*/15 * * * *  php /ruta/al/proyecto/cron/cufd_renew.php
*/30 * * * *  php /ruta/al/proyecto/cron/contingencia_send.php
```

Entrar a `https://tudominio.com/` → pantalla de login. El sistema funciona en la raíz del dominio, en subdirectorios (`/inve/public/`) o en subdominios sin configuración extra.

## Facturación electrónica (SIAT)

El módulo de facturación es **opcional** y se administra desde la pantalla **SIAT** de la aplicación: credenciales por sucursal, prueba de conexión con latencia, solicitud de CUIS/CUFD y activación con un clic. El estado del badge (`EN_LINEA`, `CONTINGENCIA`, `TOKEN_VENCIDO`, etc.) se deriva de datos reales, nunca se asume.

📘 **[Documentación completa de la integración SIAT →](docs/SIAT.md)**

> ⚠️ Antes de facturar en producción, el sistema debe completar el ciclo de
> homologación ante el SIN. Las URLs WSDL y los nombres exactos de los campos
> SOAP deben verificarse contra la documentación técnica entregada al
> registrar el sistema.

## API para integraciones

Autenticación por cabecera `X-Api-Key` (llaves con permisos granulares creadas desde la app):

| Método | Endpoint | Uso |
|---|---|---|
| `GET` | `/api/v1/productos` | Catálogo con stock y fotos |
| `POST` | `/api/v1/productos/sync` | Upsert masivo desde un ERP (por `externo_ref`) |
| `GET` | `/api/v1/stock` | Stock por almacén |
| `POST` | `/api/v1/ventas` | Venta externa: descuenta stock y factura opcionalmente |
| `POST` | `/api/v1/conciliacion/ventas` | Empuja ventas facturadas en otro sistema (con ítems) |

**Webhooks** salientes (`stock.bajo`, `venta.creada`, `factura.valida`, `producto.actualizado`) con firma `X-Firma = HMAC-SHA256(payload, secreto)` y reintentos exponenciales. La pantalla **API** de la app incluye un playground en vivo y el asistente de integración con **SolarCRM** en 3 pasos sin código.

## Capturas

| Dashboard | Punto de venta | Pagos |
|---|---|---|
| ![Dashboard](docs/img/dashboard.png) | ![POS](docs/img/pos.png) | ![Pagos](docs/img/pagos.png) |

## Historial de versiones

Ver [CHANGELOG.md](CHANGELOG.md). Migraciones incrementales en `database/upgrade_v*.sql` — ejecutarlas en orden.

## Ecosistema Alfa Solaris

| Producto | Rol |
|---|---|
| **SolarStock** | Inventario + POS + facturación (este repositorio) |
| **SolarCRM** | CRM cuyo catálogo se alimenta desde SolarStock |
| **SolarPOS** | Punto de venta dedicado *(en desarrollo — origen `POS` reservado)* |

## Licencia

Software propietario de **Alfa Solaris**. Distribución bajo licencia comercial por cliente (marca blanca). Contacto: `ventas@alfasolaris.com`.

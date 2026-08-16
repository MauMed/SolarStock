-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Servidor: 127.0.0.1
-- Tiempo de generación: 16-08-2026 a las 04:20:25
-- Versión del servidor: 10.4.32-MariaDB
-- Versión de PHP: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de datos: `inventario_sin`
--

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `almacen`
--

CREATE TABLE `almacen` (
  `id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `almacen`
--

INSERT INTO `almacen` (`id`, `sucursal_id`, `nombre`) VALUES
(1, 1, 'Principal');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `api_key`
--

CREATE TABLE `api_key` (
  `id` int(11) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `llave` char(64) NOT NULL,
  `permisos` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`permisos`)),
  `activa` tinyint(1) DEFAULT 1,
  `ultimo_uso` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `api_key`
--

INSERT INTO `api_key` (`id`, `empresa_id`, `nombre`, `llave`, `permisos`, `activa`, `ultimo_uso`) VALUES
(1, 1, 'SolarCRM (http://localhost/crm)', '6b390dad463b51d90727f0ae3c9abaaeeb91980bcbec4b5c11a728270f2834e9', '[\"productos.leer\",\"stock.leer\",\"ventas.crear\"]', 1, '2026-08-13 20:13:50'),
(2, 1, 'SolarCRM (http://localhost/crm)', '8cc5cbc99f6c6c2c200bab532c88e031f0f72d2ef9ea5dba5544ee15bcdabe73', '[\"productos.leer\",\"stock.leer\",\"ventas.crear\"]', 1, '2026-08-13 02:52:56');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `categoria`
--

CREATE TABLE `categoria` (
  `id` int(11) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `padre_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `categoria`
--

INSERT INTO `categoria` (`id`, `empresa_id`, `nombre`, `padre_id`) VALUES
(1, 1, 'Impresora', NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `cliente`
--

CREATE TABLE `cliente` (
  `id` int(11) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `tipo_doc_sin` int(11) NOT NULL DEFAULT 1,
  `num_documento` varchar(20) NOT NULL,
  `complemento` varchar(3) DEFAULT NULL,
  `razon_social` varchar(200) NOT NULL,
  `email` varchar(150) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `externo_ref` varchar(80) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `compra`
--

CREATE TABLE `compra` (
  `id` bigint(20) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `proveedor_id` int(11) DEFAULT NULL,
  `almacen_id` int(11) NOT NULL,
  `nro_documento` varchar(40) DEFAULT NULL,
  `total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `estado` enum('BORRADOR','RECIBIDA','ANULADA') DEFAULT 'RECIBIDA',
  `usuario_id` int(11) DEFAULT NULL,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
  `pagada` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_pago` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `compra`
--

INSERT INTO `compra` (`id`, `empresa_id`, `sucursal_id`, `proveedor_id`, `almacen_id`, `nro_documento`, `total`, `estado`, `usuario_id`, `fecha`, `pagada`, `fecha_pago`) VALUES
(1, 1, 1, NULL, 1, '', 225.00, 'RECIBIDA', 1, '2026-08-09 01:29:36', 0, NULL),
(2, 1, 1, 1, 1, '1332343535446', 169.00, 'RECIBIDA', 1, '2026-08-11 01:43:15', 0, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `compra_detalle`
--

CREATE TABLE `compra_detalle` (
  `id` bigint(20) NOT NULL,
  `compra_id` bigint(20) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(14,3) NOT NULL,
  `costo_unit` decimal(14,4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `compra_detalle`
--

INSERT INTO `compra_detalle` (`id`, `compra_id`, `producto_id`, `cantidad`, `costo_unit`) VALUES
(1, 1, 1, 15.000, 15.0000),
(2, 2, 1, 13.000, 13.0000);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `empresa`
--

CREATE TABLE `empresa` (
  `id` int(11) NOT NULL,
  `nit` varchar(15) NOT NULL,
  `razon_social` varchar(200) NOT NULL,
  `nombre_comercial` varchar(120) DEFAULT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `municipio` varchar(100) DEFAULT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `facturacion_activa` tinyint(1) NOT NULL DEFAULT 0,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp(),
  `color_primario` char(7) NOT NULL DEFAULT '#b91c1c',
  `color_acento` char(7) NOT NULL DEFAULT '#f59e0b',
  `color_menu` char(7) NOT NULL DEFAULT '#71717a',
  `fuente_menu` varchar(120) NOT NULL DEFAULT 'system-ui',
  `rubro` varchar(60) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `empresa`
--

INSERT INTO `empresa` (`id`, `nit`, `razon_social`, `nombre_comercial`, `direccion`, `telefono`, `municipio`, `logo_url`, `facturacion_activa`, `creado_en`, `color_primario`, `color_acento`, `color_menu`, `fuente_menu`, `rubro`) VALUES
(1, '123456789', 'MI EMPRESA', 'Importadora De Prueba SRL', NULL, NULL, NULL, 'uploads/logo_1.png', 0, '2026-08-08 23:09:23', '#025b91', '#f50a0a', '#71717a', 'system-ui', 'Importadora');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `factura`
--

CREATE TABLE `factura` (
  `id` bigint(20) NOT NULL,
  `venta_id` bigint(20) DEFAULT NULL,
  `siat_config_id` int(11) NOT NULL,
  `cufd_id` bigint(20) NOT NULL,
  `numero_factura` bigint(20) NOT NULL,
  `cuf` varchar(80) NOT NULL,
  `fecha_emision` datetime(3) NOT NULL,
  `tipo_emision` int(11) NOT NULL DEFAULT 1,
  `tipo_factura` int(11) NOT NULL DEFAULT 1,
  `doc_sector` int(11) NOT NULL DEFAULT 1,
  `cliente_snapshot` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cliente_snapshot`)),
  `monto_total` decimal(14,2) NOT NULL,
  `estado` enum('PENDIENTE','EN_PROCESAMIENTO','VALIDA','RECHAZADA','ANULADA') NOT NULL DEFAULT 'PENDIENTE',
  `codigo_recepcion` varchar(80) DEFAULT NULL,
  `error_codigo` varchar(20) DEFAULT NULL,
  `error_descripcion` text DEFAULT NULL,
  `motivo_anulacion` int(11) DEFAULT NULL,
  `xml` longtext DEFAULT NULL,
  `pdf_path` varchar(255) DEFAULT NULL,
  `evento_id` bigint(20) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `factura_recibida`
--

CREATE TABLE `factura_recibida` (
  `id` bigint(20) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `nit_proveedor` varchar(15) NOT NULL,
  `razon_social` varchar(200) DEFAULT NULL,
  `numero_factura` bigint(20) DEFAULT NULL,
  `cuf` varchar(80) DEFAULT NULL,
  `fecha_emision` datetime DEFAULT NULL,
  `monto_total` decimal(14,2) DEFAULT NULL,
  `codigo_control` varchar(60) DEFAULT NULL,
  `origen` enum('QR_SCAN','IMPORT_SIAT','MANUAL') DEFAULT 'QR_SCAN',
  `compra_id` bigint(20) DEFAULT NULL,
  `verificada` tinyint(1) DEFAULT 0,
  `datos_raw` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`datos_raw`)),
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `impresora`
--

CREATE TABLE `impresora` (
  `id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `tipo` enum('TERMICA_RED','TERMICA_BT','LASER_TINTA') NOT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `puerto` int(11) DEFAULT 9100,
  `ancho_mm` int(11) DEFAULT 80,
  `predeterminada` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `movimiento`
--

CREATE TABLE `movimiento` (
  `id` bigint(20) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `almacen_id` int(11) NOT NULL,
  `tipo` enum('COMPRA','VENTA','AJUSTE+','AJUSTE-','TRASPASO_IN','TRASPASO_OUT','DEVOLUCION') NOT NULL,
  `cantidad` decimal(14,3) NOT NULL,
  `costo_unit` decimal(14,4) DEFAULT 0.0000,
  `saldo_resultante` decimal(14,3) NOT NULL,
  `referencia_tipo` varchar(30) DEFAULT NULL,
  `referencia_id` bigint(20) DEFAULT NULL,
  `usuario_id` int(11) DEFAULT NULL,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `movimiento`
--

INSERT INTO `movimiento` (`id`, `empresa_id`, `producto_id`, `almacen_id`, `tipo`, `cantidad`, `costo_unit`, `saldo_resultante`, `referencia_tipo`, `referencia_id`, `usuario_id`, `fecha`) VALUES
(1, 1, 1, 1, 'COMPRA', 15.000, 15.0000, 15.000, 'compra', 1, 1, '2026-08-09 01:29:36'),
(2, 1, 1, 1, 'VENTA', 4.000, 0.0000, 11.000, 'venta', 1, 1, '2026-08-09 01:30:18'),
(3, 1, 1, 1, 'VENTA', 4.000, 0.0000, 7.000, 'venta', 2, 1, '2026-08-09 01:31:56'),
(4, 1, 1, 1, 'VENTA', 1.000, 0.0000, 6.000, 'venta', 3, 1, '2026-08-09 01:48:48'),
(5, 1, 1, 1, 'VENTA', 2.000, 0.0000, 4.000, 'venta', 4, 1, '2026-08-09 01:49:49'),
(6, 1, 1, 1, 'VENTA', 1.000, 0.0000, 3.000, 'venta', 5, 1, '2026-08-09 01:50:13'),
(7, 1, 1, 1, 'VENTA', 1.000, 0.0000, 2.000, 'venta', 6, 1, '2026-08-09 02:10:52'),
(8, 1, 1, 1, 'VENTA', 2.000, 0.0000, 0.000, 'venta', 7, 1, '2026-08-10 14:25:26'),
(9, 1, 1, 1, 'COMPRA', 13.000, 13.0000, 13.000, 'compra', 2, 1, '2026-08-11 01:43:15'),
(10, 1, 1, 1, 'VENTA', 3.000, 0.0000, 10.000, 'venta', 8, 1, '2026-08-11 01:45:46'),
(11, 1, 1, 1, 'VENTA', 2.000, 0.0000, 8.000, 'venta', 9, 1, '2026-08-11 22:14:07'),
(12, 1, 1, 1, 'VENTA', 2.000, 0.0000, 6.000, 'venta', 10, NULL, '2026-08-13 02:58:53'),
(13, 1, 1, 1, 'VENTA', 2.000, 0.0000, 4.000, 'venta', 11, 1, '2026-08-14 20:42:06'),
(14, 1, 1, 1, 'VENTA', 2.000, 0.0000, 2.000, 'venta', 12, 1, '2026-08-14 20:42:08');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `permiso`
--

CREATE TABLE `permiso` (
  `clave` varchar(60) NOT NULL,
  `modulo` varchar(40) NOT NULL,
  `descripcion` varchar(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `permiso`
--

INSERT INTO `permiso` (`clave`, `modulo`, `descripcion`) VALUES
('almacenes.gestionar', 'Almacenes', 'Crear y editar sucursales (SIAT) y almacenes'),
('api.gestionar', 'Integraciones', 'Crear API keys y webhooks'),
('compras.crear', 'Compras', 'Registrar compras (con escaneo)'),
('compras.ver', 'Compras', 'Ver compras'),
('conciliacion.ejecutar', 'Conciliacion', 'Importar ventas del SIN y descontar stock'),
('conciliacion.ver', 'Conciliacion', 'Ver conciliacion con el SIN'),
('dashboard.ver', 'Reportes', 'Ver dashboard y KPIs'),
('facturas.anular', 'Facturacion', 'Anular facturas SIAT'),
('facturas.emitir', 'Facturacion', 'Emitir facturas SIAT'),
('facturas.leer', 'Facturacion', 'Leer/registrar facturas recibidas'),
('informes.exportar', 'Informes', 'Exportar informes a CSV'),
('informes.ver', 'Informes', 'Ver el modulo de informes'),
('inventario.ajustar', 'Inventario', 'Registrar ajustes de inventario'),
('inventario.ver', 'Inventario', 'Consultar stock y kardex'),
('marca.editar', 'Configuracion', 'Editar marca blanca (logo, colores)'),
('pagos.gestionar', 'Pagos', 'Marcar ventas y compras como pagadas'),
('pagos.ver', 'Pagos', 'Ver cuentas por cobrar y por pagar'),
('productos.crear', 'Productos', 'Crear y editar productos'),
('productos.importar', 'Productos', 'Carga masiva de productos'),
('productos.ver', 'Productos', 'Ver catalogo de productos'),
('siat.gestionar', 'Facturacion', 'Configurar la conexion con el SIAT (credenciales, CUIS, CUFD)'),
('usuarios.gestionar', 'Usuarios', 'Crear usuarios y asignar permisos'),
('ventas.anular', 'Ventas', 'Anular ventas'),
('ventas.crear', 'Ventas', 'Vender en el POS');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `producto`
--

CREATE TABLE `producto` (
  `id` int(11) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `categoria_id` int(11) DEFAULT NULL,
  `codigo_interno` varchar(50) NOT NULL,
  `codigo_barras` varchar(64) DEFAULT NULL,
  `nombre` varchar(200) NOT NULL,
  `descripcion` text DEFAULT NULL,
  `unidad_medida_sin` int(11) DEFAULT 58,
  `precio_compra` decimal(14,4) DEFAULT 0.0000,
  `precio_venta` decimal(14,4) NOT NULL DEFAULT 0.0000,
  `stock_minimo` decimal(12,3) DEFAULT 0.000,
  `maneja_lotes` tinyint(1) DEFAULT 0,
  `imagen_url` varchar(255) DEFAULT NULL,
  `codigo_actividad_sin` varchar(20) DEFAULT NULL,
  `codigo_producto_sin` varchar(20) DEFAULT NULL,
  `activo` tinyint(1) DEFAULT 1,
  `externo_ref` varchar(80) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `producto`
--

INSERT INTO `producto` (`id`, `empresa_id`, `categoria_id`, `codigo_interno`, `codigo_barras`, `nombre`, `descripcion`, `unidad_medida_sin`, `precio_compra`, `precio_venta`, `stock_minimo`, `maneja_lotes`, `imagen_url`, `codigo_actividad_sin`, `codigo_producto_sin`, `activo`, `externo_ref`, `creado_en`) VALUES
(1, 1, 1, '12345', '123456789123456789', 'Impresora Térmica', 'KLNXAKLZNM<KLMN<KLM', 58, 15.0000, 25.0000, 10.000, 0, 'uploads/prod_1.png', '12345', '12345', 1, NULL, '2026-08-09 01:29:12');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `proveedor`
--

CREATE TABLE `proveedor` (
  `id` int(11) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `nit` varchar(20) DEFAULT NULL,
  `razon_social` varchar(200) NOT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `proveedor`
--

INSERT INTO `proveedor` (`id`, `empresa_id`, `nit`, `razon_social`, `telefono`, `email`) VALUES
(1, 1, '212455669', 'BOMESCO S.R.L.', NULL, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `punto_venta`
--

CREATE TABLE `punto_venta` (
  `id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `codigo_sin` int(11) NOT NULL DEFAULT 0,
  `nombre` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `punto_venta`
--

INSERT INTO `punto_venta` (`id`, `sucursal_id`, `codigo_sin`, `nombre`) VALUES
(1, 1, 0, 'Caja 1');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `sesion`
--

CREATE TABLE `sesion` (
  `token` char(64) NOT NULL,
  `usuario_id` int(11) NOT NULL,
  `expira` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `sesion`
--

INSERT INTO `sesion` (`token`, `usuario_id`, `expira`) VALUES
('462c70fd72f1886f0c44c147f1f1c27027d84883431021f97b4fcc750858389c', 1, '2026-08-14 03:24:00'),
('497eef4eba44dbfe43ab8f491ef9a5f41dc104a40f560f93443800e7edd9274c', 1, '2026-08-12 10:05:44'),
('91d34208f7d8b36048bd424243df0bd6deeb06c0343204c04c171044742e75a7', 1, '2026-08-15 08:41:37'),
('d07ef9a5cf991efeab309c7d3ac5fa09831d3f940e59d96afe59b8a775c76214', 1, '2026-08-11 02:24:51'),
('e9e54144c16e77675089955cd04e9a7758d6813895e1b61910b9738f79d1d62c', 1, '2026-08-13 14:08:37');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `siat_actividad`
--

CREATE TABLE `siat_actividad` (
  `id` int(11) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `codigo_actividad` varchar(20) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `siat_config`
--

CREATE TABLE `siat_config` (
  `id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `punto_venta_id` int(11) DEFAULT NULL,
  `nit_emisor` varchar(15) NOT NULL,
  `codigo_sistema` varchar(40) NOT NULL,
  `token_delegado` text NOT NULL,
  `token_expira` date NOT NULL,
  `ambiente` enum('HOMOLOGACION','PRODUCCION') DEFAULT 'HOMOLOGACION',
  `modalidad` int(11) DEFAULT 2,
  `cuis` varchar(30) DEFAULT NULL,
  `cuis_expira` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `siat_cufd`
--

CREATE TABLE `siat_cufd` (
  `id` bigint(20) NOT NULL,
  `siat_config_id` int(11) NOT NULL,
  `codigo_cufd` varchar(120) NOT NULL,
  `codigo_control` varchar(60) NOT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `fecha_inicio` datetime NOT NULL,
  `fecha_fin` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `siat_evento`
--

CREATE TABLE `siat_evento` (
  `id` bigint(20) NOT NULL,
  `siat_config_id` int(11) NOT NULL,
  `codigo_evento` int(11) NOT NULL,
  `fecha_inicio` datetime NOT NULL,
  `fecha_fin` datetime DEFAULT NULL,
  `codigo_recepcion_evento` varchar(80) DEFAULT NULL,
  `estado` enum('ABIERTO','CERRADO','REPORTADO') DEFAULT 'ABIERTO'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `siat_log`
--

CREATE TABLE `siat_log` (
  `id` bigint(20) NOT NULL,
  `factura_id` bigint(20) DEFAULT NULL,
  `origen` varchar(40) NOT NULL,
  `gravedad` enum('CONEXION','VALIDACION','INFO') NOT NULL,
  `codigo_error` varchar(20) DEFAULT NULL,
  `descripcion` text DEFAULT NULL,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `siat_parametro`
--

CREATE TABLE `siat_parametro` (
  `id` int(11) NOT NULL,
  `grupo` varchar(40) NOT NULL,
  `codigo` varchar(10) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `siat_parametro`
--

INSERT INTO `siat_parametro` (`id`, `grupo`, `codigo`, `descripcion`) VALUES
(1, 'TIPO_DOC', '1', 'Cedula de Identidad'),
(2, 'TIPO_DOC', '2', 'CI Extranjero'),
(3, 'TIPO_DOC', '3', 'Pasaporte'),
(4, 'TIPO_DOC', '4', 'NIT'),
(5, 'TIPO_DOC', '5', 'DNI'),
(6, 'MOTIVO_ANULACION', '1', 'Factura mal emitida'),
(7, 'MOTIVO_ANULACION', '2', 'Nota de credito/devolucion'),
(8, 'MOTIVO_ANULACION', '3', 'Totalmente devuelto'),
(9, 'MOTIVO_ANULACION', '4', 'Error de tipeo o sistema'),
(10, 'EVENTO', '1', 'Corte de internet'),
(11, 'EVENTO', '2', 'Caida del servicio del SIN'),
(12, 'EVENTO', '3', 'Corte de servicio web de la Administracion Tributaria');

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `siat_producto_sin`
--

CREATE TABLE `siat_producto_sin` (
  `id` int(11) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `codigo_actividad` varchar(20) NOT NULL,
  `codigo_producto` varchar(20) NOT NULL,
  `descripcion` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `sin_venta_externa`
--

CREATE TABLE `sin_venta_externa` (
  `id` bigint(20) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `cuf` varchar(80) DEFAULT NULL,
  `numero_factura` bigint(20) DEFAULT NULL,
  `nit_cliente` varchar(20) DEFAULT NULL,
  `razon_social` varchar(200) DEFAULT NULL,
  `fecha_emision` datetime DEFAULT NULL,
  `monto_total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `origen` enum('IMPORT_CSV','API','MANUAL') DEFAULT 'IMPORT_CSV',
  `estado` enum('CONCILIADA_INTERNA','EXTERNA_PENDIENTE','DESCONTADA','IGNORADA') NOT NULL DEFAULT 'EXTERNA_PENDIENTE',
  `almacen_id` int(11) DEFAULT NULL,
  `datos_raw` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`datos_raw`)),
  `creado_en` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `sin_venta_externa_item`
--

CREATE TABLE `sin_venta_externa_item` (
  `id` bigint(20) NOT NULL,
  `venta_externa_id` bigint(20) NOT NULL,
  `codigo_producto` varchar(64) NOT NULL,
  `descripcion` varchar(200) DEFAULT NULL,
  `cantidad` decimal(14,3) NOT NULL,
  `producto_id` int(11) DEFAULT NULL,
  `descontado` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `stock`
--

CREATE TABLE `stock` (
  `producto_id` int(11) NOT NULL,
  `almacen_id` int(11) NOT NULL,
  `cantidad` decimal(14,3) NOT NULL DEFAULT 0.000
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `stock`
--

INSERT INTO `stock` (`producto_id`, `almacen_id`, `cantidad`) VALUES
(1, 1, 2.000);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `sucursal`
--

CREATE TABLE `sucursal` (
  `id` int(11) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `codigo_sin` int(11) NOT NULL DEFAULT 0,
  `nombre` varchar(100) NOT NULL,
  `direccion` varchar(255) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `activa` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `sucursal`
--

INSERT INTO `sucursal` (`id`, `empresa_id`, `codigo_sin`, `nombre`, `direccion`, `telefono`, `activa`) VALUES
(1, 1, 0, 'Casa Matriz', NULL, NULL, 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuario`
--

CREATE TABLE `usuario` (
  `id` int(11) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `sucursal_id` int(11) DEFAULT NULL,
  `nombre` varchar(120) NOT NULL,
  `email` varchar(150) NOT NULL,
  `pass_hash` varchar(255) NOT NULL,
  `rol` enum('admin','gerente','cajero','almacen','auditor') NOT NULL DEFAULT 'cajero',
  `activo` tinyint(1) DEFAULT 1,
  `ultimo_login` timestamp NULL DEFAULT NULL,
  `reset_token` char(64) DEFAULT NULL,
  `reset_expira` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `usuario`
--

INSERT INTO `usuario` (`id`, `empresa_id`, `sucursal_id`, `nombre`, `email`, `pass_hash`, `rol`, `activo`, `ultimo_login`, `reset_token`, `reset_expira`) VALUES
(1, 1, NULL, 'Admin', 'admin@midominio.com', '$2y$10$DwASrSxFt1JY.yozV4fyKu8IzNhVW6X4ncMKdyk3w2PyTrhhgH.lu', 'admin', 1, '2026-08-14 20:41:37', NULL, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `usuario_permiso`
--

CREATE TABLE `usuario_permiso` (
  `usuario_id` int(11) NOT NULL,
  `permiso_clave` varchar(60) NOT NULL,
  `concedido` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `usuario_permiso`
--

INSERT INTO `usuario_permiso` (`usuario_id`, `permiso_clave`, `concedido`) VALUES
(1, 'compras.crear', 1),
(1, 'compras.ver', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `venta`
--

CREATE TABLE `venta` (
  `id` bigint(20) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `sucursal_id` int(11) NOT NULL,
  `punto_venta_id` int(11) DEFAULT NULL,
  `almacen_id` int(11) NOT NULL,
  `cliente_id` int(11) DEFAULT NULL,
  `subtotal` decimal(14,2) NOT NULL,
  `descuento` decimal(14,2) DEFAULT 0.00,
  `total` decimal(14,2) NOT NULL,
  `metodo_pago_sin` int(11) DEFAULT 1,
  `estado` enum('COMPLETADA','ANULADA') DEFAULT 'COMPLETADA',
  `origen` enum('INV','POS','API','ECOMMERCE','CRM') NOT NULL DEFAULT 'INV',
  `usuario_id` int(11) DEFAULT NULL,
  `fecha` timestamp NOT NULL DEFAULT current_timestamp(),
  `pagada` tinyint(1) NOT NULL DEFAULT 0,
  `fecha_pago` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `venta`
--

INSERT INTO `venta` (`id`, `empresa_id`, `sucursal_id`, `punto_venta_id`, `almacen_id`, `cliente_id`, `subtotal`, `descuento`, `total`, `metodo_pago_sin`, `estado`, `origen`, `usuario_id`, `fecha`, `pagada`, `fecha_pago`) VALUES
(1, 1, 1, 1, 1, NULL, 72.00, 0.00, 72.00, 1, 'COMPLETADA', 'POS', 1, '2026-08-09 01:30:18', 0, NULL),
(2, 1, 1, 1, 1, NULL, 72.00, 0.00, 72.00, 1, 'COMPLETADA', 'POS', 1, '2026-08-09 01:31:55', 0, NULL),
(3, 1, 1, 1, 1, NULL, 18.00, 0.00, 18.00, 1, 'COMPLETADA', 'POS', 1, '2026-08-09 01:48:48', 0, NULL),
(4, 1, 1, 1, 1, NULL, 36.00, 0.00, 36.00, 1, 'COMPLETADA', 'POS', 1, '2026-08-09 01:49:49', 0, NULL),
(5, 1, 1, 1, 1, NULL, 18.00, 0.00, 18.00, 1, 'COMPLETADA', 'POS', 1, '2026-08-09 01:50:13', 0, NULL),
(6, 1, 1, 1, 1, NULL, 18.00, 0.00, 18.00, 1, 'COMPLETADA', 'POS', 1, '2026-08-09 02:10:52', 0, NULL),
(7, 1, 1, 1, 1, NULL, 36.00, 0.00, 36.00, 1, 'COMPLETADA', 'POS', 1, '2026-08-10 14:25:26', 0, NULL),
(8, 1, 1, 1, 1, NULL, 54.00, 0.00, 54.00, 1, 'COMPLETADA', 'POS', 1, '2026-08-11 01:45:46', 0, NULL),
(9, 1, 1, 1, 1, NULL, 50.00, 0.00, 50.00, 1, 'COMPLETADA', 'POS', 1, '2026-08-11 22:14:07', 0, NULL),
(10, 1, 1, NULL, 1, NULL, 50.00, 0.00, 50.00, 1, 'COMPLETADA', 'API', NULL, '2026-08-13 02:58:53', 0, NULL),
(11, 1, 1, 1, 1, NULL, 50.00, 0.00, 50.00, 1, 'COMPLETADA', 'INV', 1, '2026-08-14 20:42:06', 0, NULL),
(12, 1, 1, 1, 1, NULL, 50.00, 0.00, 50.00, 1, 'COMPLETADA', 'INV', 1, '2026-08-14 20:42:08', 0, NULL);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `venta_detalle`
--

CREATE TABLE `venta_detalle` (
  `id` bigint(20) NOT NULL,
  `venta_id` bigint(20) NOT NULL,
  `producto_id` int(11) NOT NULL,
  `cantidad` decimal(14,3) NOT NULL,
  `precio_unit` decimal(14,4) NOT NULL,
  `descuento` decimal(14,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `venta_detalle`
--

INSERT INTO `venta_detalle` (`id`, `venta_id`, `producto_id`, `cantidad`, `precio_unit`, `descuento`) VALUES
(1, 1, 1, 4.000, 18.0000, 0.00),
(2, 2, 1, 4.000, 18.0000, 0.00),
(3, 3, 1, 1.000, 18.0000, 0.00),
(4, 4, 1, 2.000, 18.0000, 0.00),
(5, 5, 1, 1.000, 18.0000, 0.00),
(6, 6, 1, 1.000, 18.0000, 0.00),
(7, 7, 1, 2.000, 18.0000, 0.00),
(8, 8, 1, 3.000, 18.0000, 0.00),
(9, 9, 1, 2.000, 25.0000, 0.00),
(10, 10, 1, 2.000, 25.0000, 0.00),
(11, 11, 1, 2.000, 25.0000, 0.00),
(12, 12, 1, 2.000, 25.0000, 0.00);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `webhook`
--

CREATE TABLE `webhook` (
  `id` int(11) NOT NULL,
  `empresa_id` int(11) NOT NULL,
  `evento` varchar(60) NOT NULL,
  `url` varchar(255) NOT NULL,
  `secreto` varchar(64) NOT NULL,
  `activo` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `webhook`
--

INSERT INTO `webhook` (`id`, `empresa_id`, `evento`, `url`, `secreto`, `activo`) VALUES
(1, 1, 'producto.actualizado', 'http://localhost/crm/api/webhooks/solarstock', '2a23aac7079c5cdbbb86a81bd1e0b495d4adbbaf8718019a', 1),
(2, 1, 'venta.creada', 'http://localhost/crm/api/webhooks/solarstock', '2a23aac7079c5cdbbb86a81bd1e0b495d4adbbaf8718019a', 1),
(3, 1, 'stock.bajo', 'http://localhost/crm/api/webhooks/solarstock', '2a23aac7079c5cdbbb86a81bd1e0b495d4adbbaf8718019a', 1),
(4, 1, 'producto.actualizado', 'http://localhost/crm/api/webhooks/solarstock', '1b130cb42ee53f1d35cd5394138acc044bc7e0720ec7b06c', 1),
(5, 1, 'venta.creada', 'http://localhost/crm/api/webhooks/solarstock', '1b130cb42ee53f1d35cd5394138acc044bc7e0720ec7b06c', 1),
(6, 1, 'stock.bajo', 'http://localhost/crm/api/webhooks/solarstock', '1b130cb42ee53f1d35cd5394138acc044bc7e0720ec7b06c', 1);

-- --------------------------------------------------------

--
-- Estructura de tabla para la tabla `webhook_intento`
--

CREATE TABLE `webhook_intento` (
  `id` bigint(20) NOT NULL,
  `webhook_id` int(11) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `http_status` int(11) DEFAULT NULL,
  `intentos` int(11) DEFAULT 0,
  `estado` enum('PENDIENTE','OK','FALLIDO') DEFAULT 'PENDIENTE',
  `proximo_intento` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Volcado de datos para la tabla `webhook_intento`
--

INSERT INTO `webhook_intento` (`id`, `webhook_id`, `payload`, `http_status`, `intentos`, `estado`, `proximo_intento`) VALUES
(1, 1, '{\"id\":1,\"codigo_interno\":\"12345\",\"codigo_barras\":\"123456789123456789\",\"nombre\":\"Impresora Térmica\",\"descripcion\":\"KLNXAKLZNM<KLMN<KLM\",\"categoria_id\":1,\"precio_compra\":15,\"precio_venta\":25,\"stock_minimo\":10,\"codigo_actividad_sin\":\"12345\",\"codigo_producto_sin\":\"12345\"}', 404, 3, 'PENDIENTE', '2026-08-14 20:50:06'),
(2, 4, '{\"id\":1,\"codigo_interno\":\"12345\",\"codigo_barras\":\"123456789123456789\",\"nombre\":\"Impresora Térmica\",\"descripcion\":\"KLNXAKLZNM<KLMN<KLM\",\"categoria_id\":1,\"precio_compra\":15,\"precio_venta\":25,\"stock_minimo\":10,\"codigo_actividad_sin\":\"12345\",\"codigo_producto_sin\":\"12345\"}', 404, 3, 'PENDIENTE', '2026-08-14 20:50:07'),
(3, 3, '{\"producto_id\":1,\"nombre\":\"Impresora Térmica\",\"almacen_id\":1,\"stock\":6,\"minimo\":\"10.000\"}', 404, 2, 'PENDIENTE', '2026-08-14 20:46:07'),
(4, 6, '{\"producto_id\":1,\"nombre\":\"Impresora Térmica\",\"almacen_id\":1,\"stock\":6,\"minimo\":\"10.000\"}', 404, 2, 'PENDIENTE', '2026-08-14 20:46:07'),
(5, 2, '{\"id\":10,\"empresa_id\":1,\"sucursal_id\":1,\"punto_venta_id\":null,\"almacen_id\":1,\"cliente_id\":null,\"subtotal\":\"50.00\",\"descuento\":\"0.00\",\"total\":\"50.00\",\"metodo_pago_sin\":1,\"estado\":\"COMPLETADA\",\"origen\":\"\",\"usuario_id\":null,\"fecha\":\"2026-08-12 22:58:53\"}', 404, 2, 'PENDIENTE', '2026-08-14 20:46:07'),
(6, 5, '{\"id\":10,\"empresa_id\":1,\"sucursal_id\":1,\"punto_venta_id\":null,\"almacen_id\":1,\"cliente_id\":null,\"subtotal\":\"50.00\",\"descuento\":\"0.00\",\"total\":\"50.00\",\"metodo_pago_sin\":1,\"estado\":\"COMPLETADA\",\"origen\":\"\",\"usuario_id\":null,\"fecha\":\"2026-08-12 22:58:53\"}', 404, 2, 'PENDIENTE', '2026-08-14 20:46:08'),
(7, 3, '{\"producto_id\":1,\"nombre\":\"Impresora Térmica\",\"almacen_id\":1,\"stock\":4,\"minimo\":\"10.000\"}', 404, 1, 'PENDIENTE', '2026-08-14 20:44:08'),
(8, 6, '{\"producto_id\":1,\"nombre\":\"Impresora Térmica\",\"almacen_id\":1,\"stock\":4,\"minimo\":\"10.000\"}', 404, 1, 'PENDIENTE', '2026-08-14 20:44:08'),
(9, 2, '{\"id\":11,\"empresa_id\":1,\"sucursal_id\":1,\"punto_venta_id\":1,\"almacen_id\":1,\"cliente_id\":null,\"subtotal\":\"50.00\",\"descuento\":\"0.00\",\"total\":\"50.00\",\"metodo_pago_sin\":1,\"estado\":\"COMPLETADA\",\"origen\":\"INV\",\"usuario_id\":1,\"fecha\":\"2026-08-14 16:42:06\",\"pagada\":0,\"fecha_pago\":null}', 404, 1, 'PENDIENTE', '2026-08-14 20:44:08'),
(10, 5, '{\"id\":11,\"empresa_id\":1,\"sucursal_id\":1,\"punto_venta_id\":1,\"almacen_id\":1,\"cliente_id\":null,\"subtotal\":\"50.00\",\"descuento\":\"0.00\",\"total\":\"50.00\",\"metodo_pago_sin\":1,\"estado\":\"COMPLETADA\",\"origen\":\"INV\",\"usuario_id\":1,\"fecha\":\"2026-08-14 16:42:06\",\"pagada\":0,\"fecha_pago\":null}', 404, 2, 'PENDIENTE', '2026-08-14 20:46:09'),
(11, 3, '{\"producto_id\":1,\"nombre\":\"Impresora Térmica\",\"almacen_id\":1,\"stock\":2,\"minimo\":\"10.000\"}', 404, 1, 'PENDIENTE', '2026-08-14 20:44:09'),
(12, 6, '{\"producto_id\":1,\"nombre\":\"Impresora Térmica\",\"almacen_id\":1,\"stock\":2,\"minimo\":\"10.000\"}', 404, 1, 'PENDIENTE', '2026-08-14 20:44:09'),
(13, 2, '{\"id\":12,\"empresa_id\":1,\"sucursal_id\":1,\"punto_venta_id\":1,\"almacen_id\":1,\"cliente_id\":null,\"subtotal\":\"50.00\",\"descuento\":\"0.00\",\"total\":\"50.00\",\"metodo_pago_sin\":1,\"estado\":\"COMPLETADA\",\"origen\":\"INV\",\"usuario_id\":1,\"fecha\":\"2026-08-14 16:42:08\",\"pagada\":0,\"fecha_pago\":null}', 404, 1, 'PENDIENTE', '2026-08-14 20:44:09'),
(14, 5, '{\"id\":12,\"empresa_id\":1,\"sucursal_id\":1,\"punto_venta_id\":1,\"almacen_id\":1,\"cliente_id\":null,\"subtotal\":\"50.00\",\"descuento\":\"0.00\",\"total\":\"50.00\",\"metodo_pago_sin\":1,\"estado\":\"COMPLETADA\",\"origen\":\"INV\",\"usuario_id\":1,\"fecha\":\"2026-08-14 16:42:08\",\"pagada\":0,\"fecha_pago\":null}', 404, 1, 'PENDIENTE', '2026-08-14 20:44:09');

--
-- Índices para tablas volcadas
--

--
-- Indices de la tabla `almacen`
--
ALTER TABLE `almacen`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sucursal_id` (`sucursal_id`);

--
-- Indices de la tabla `api_key`
--
ALTER TABLE `api_key`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `llave` (`llave`),
  ADD KEY `empresa_id` (`empresa_id`);

--
-- Indices de la tabla `categoria`
--
ALTER TABLE `categoria`
  ADD PRIMARY KEY (`id`),
  ADD KEY `empresa_id` (`empresa_id`);

--
-- Indices de la tabla `cliente`
--
ALTER TABLE `cliente`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_doc` (`empresa_id`,`num_documento`);

--
-- Indices de la tabla `compra`
--
ALTER TABLE `compra`
  ADD PRIMARY KEY (`id`),
  ADD KEY `empresa_id` (`empresa_id`);

--
-- Indices de la tabla `compra_detalle`
--
ALTER TABLE `compra_detalle`
  ADD PRIMARY KEY (`id`),
  ADD KEY `compra_id` (`compra_id`);

--
-- Indices de la tabla `empresa`
--
ALTER TABLE `empresa`
  ADD PRIMARY KEY (`id`);

--
-- Indices de la tabla `factura`
--
ALTER TABLE `factura`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cuf` (`cuf`),
  ADD KEY `idx_estado` (`estado`),
  ADD KEY `cufd_id` (`cufd_id`);

--
-- Indices de la tabla `factura_recibida`
--
ALTER TABLE `factura_recibida`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fr` (`empresa_id`,`nit_proveedor`,`numero_factura`,`cuf`);

--
-- Indices de la tabla `impresora`
--
ALTER TABLE `impresora`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sucursal_id` (`sucursal_id`);

--
-- Indices de la tabla `movimiento`
--
ALTER TABLE `movimiento`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_prod_fecha` (`producto_id`,`fecha`);

--
-- Indices de la tabla `permiso`
--
ALTER TABLE `permiso`
  ADD PRIMARY KEY (`clave`);

--
-- Indices de la tabla `producto`
--
ALTER TABLE `producto`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_prod` (`empresa_id`,`codigo_interno`),
  ADD KEY `idx_barras` (`codigo_barras`);

--
-- Indices de la tabla `proveedor`
--
ALTER TABLE `proveedor`
  ADD PRIMARY KEY (`id`),
  ADD KEY `empresa_id` (`empresa_id`);

--
-- Indices de la tabla `punto_venta`
--
ALTER TABLE `punto_venta`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sucursal_id` (`sucursal_id`);

--
-- Indices de la tabla `sesion`
--
ALTER TABLE `sesion`
  ADD PRIMARY KEY (`token`),
  ADD KEY `usuario_id` (`usuario_id`);

--
-- Indices de la tabla `siat_actividad`
--
ALTER TABLE `siat_actividad`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_act` (`empresa_id`,`codigo_actividad`);

--
-- Indices de la tabla `siat_config`
--
ALTER TABLE `siat_config`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cfg` (`sucursal_id`,`punto_venta_id`);

--
-- Indices de la tabla `siat_cufd`
--
ALTER TABLE `siat_cufd`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vigencia` (`siat_config_id`,`fecha_fin`);

--
-- Indices de la tabla `siat_evento`
--
ALTER TABLE `siat_evento`
  ADD PRIMARY KEY (`id`),
  ADD KEY `siat_config_id` (`siat_config_id`);

--
-- Indices de la tabla `siat_log`
--
ALTER TABLE `siat_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fac` (`factura_id`);

--
-- Indices de la tabla `siat_parametro`
--
ALTER TABLE `siat_parametro`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_par` (`grupo`,`codigo`);

--
-- Indices de la tabla `siat_producto_sin`
--
ALTER TABLE `siat_producto_sin`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_ps` (`empresa_id`,`codigo_actividad`,`codigo_producto`);

--
-- Indices de la tabla `sin_venta_externa`
--
ALTER TABLE `sin_venta_externa`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sve` (`empresa_id`,`cuf`,`numero_factura`);

--
-- Indices de la tabla `sin_venta_externa_item`
--
ALTER TABLE `sin_venta_externa_item`
  ADD PRIMARY KEY (`id`),
  ADD KEY `venta_externa_id` (`venta_externa_id`);

--
-- Indices de la tabla `stock`
--
ALTER TABLE `stock`
  ADD PRIMARY KEY (`producto_id`,`almacen_id`),
  ADD KEY `almacen_id` (`almacen_id`);

--
-- Indices de la tabla `sucursal`
--
ALTER TABLE `sucursal`
  ADD PRIMARY KEY (`id`),
  ADD KEY `empresa_id` (`empresa_id`);

--
-- Indices de la tabla `usuario`
--
ALTER TABLE `usuario`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `empresa_id` (`empresa_id`);

--
-- Indices de la tabla `usuario_permiso`
--
ALTER TABLE `usuario_permiso`
  ADD PRIMARY KEY (`usuario_id`,`permiso_clave`),
  ADD KEY `permiso_clave` (`permiso_clave`);

--
-- Indices de la tabla `venta`
--
ALTER TABLE `venta`
  ADD PRIMARY KEY (`id`),
  ADD KEY `empresa_id` (`empresa_id`);

--
-- Indices de la tabla `venta_detalle`
--
ALTER TABLE `venta_detalle`
  ADD PRIMARY KEY (`id`),
  ADD KEY `venta_id` (`venta_id`);

--
-- Indices de la tabla `webhook`
--
ALTER TABLE `webhook`
  ADD PRIMARY KEY (`id`),
  ADD KEY `empresa_id` (`empresa_id`);

--
-- Indices de la tabla `webhook_intento`
--
ALTER TABLE `webhook_intento`
  ADD PRIMARY KEY (`id`),
  ADD KEY `webhook_id` (`webhook_id`);

--
-- AUTO_INCREMENT de las tablas volcadas
--

--
-- AUTO_INCREMENT de la tabla `almacen`
--
ALTER TABLE `almacen`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `api_key`
--
ALTER TABLE `api_key`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `categoria`
--
ALTER TABLE `categoria`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `cliente`
--
ALTER TABLE `cliente`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `compra`
--
ALTER TABLE `compra`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `compra_detalle`
--
ALTER TABLE `compra_detalle`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT de la tabla `empresa`
--
ALTER TABLE `empresa`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `factura`
--
ALTER TABLE `factura`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `factura_recibida`
--
ALTER TABLE `factura_recibida`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `impresora`
--
ALTER TABLE `impresora`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `movimiento`
--
ALTER TABLE `movimiento`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT de la tabla `producto`
--
ALTER TABLE `producto`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `proveedor`
--
ALTER TABLE `proveedor`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `punto_venta`
--
ALTER TABLE `punto_venta`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `siat_actividad`
--
ALTER TABLE `siat_actividad`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `siat_config`
--
ALTER TABLE `siat_config`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `siat_cufd`
--
ALTER TABLE `siat_cufd`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `siat_evento`
--
ALTER TABLE `siat_evento`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `siat_log`
--
ALTER TABLE `siat_log`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `siat_parametro`
--
ALTER TABLE `siat_parametro`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT de la tabla `siat_producto_sin`
--
ALTER TABLE `siat_producto_sin`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `sin_venta_externa`
--
ALTER TABLE `sin_venta_externa`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `sin_venta_externa_item`
--
ALTER TABLE `sin_venta_externa_item`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT de la tabla `sucursal`
--
ALTER TABLE `sucursal`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `usuario`
--
ALTER TABLE `usuario`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT de la tabla `venta`
--
ALTER TABLE `venta`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT de la tabla `venta_detalle`
--
ALTER TABLE `venta_detalle`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT de la tabla `webhook`
--
ALTER TABLE `webhook`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT de la tabla `webhook_intento`
--
ALTER TABLE `webhook_intento`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- Restricciones para tablas volcadas
--

--
-- Filtros para la tabla `almacen`
--
ALTER TABLE `almacen`
  ADD CONSTRAINT `almacen_ibfk_1` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursal` (`id`);

--
-- Filtros para la tabla `api_key`
--
ALTER TABLE `api_key`
  ADD CONSTRAINT `api_key_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`);

--
-- Filtros para la tabla `categoria`
--
ALTER TABLE `categoria`
  ADD CONSTRAINT `categoria_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`);

--
-- Filtros para la tabla `cliente`
--
ALTER TABLE `cliente`
  ADD CONSTRAINT `cliente_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`);

--
-- Filtros para la tabla `compra`
--
ALTER TABLE `compra`
  ADD CONSTRAINT `compra_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`);

--
-- Filtros para la tabla `compra_detalle`
--
ALTER TABLE `compra_detalle`
  ADD CONSTRAINT `compra_detalle_ibfk_1` FOREIGN KEY (`compra_id`) REFERENCES `compra` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `factura`
--
ALTER TABLE `factura`
  ADD CONSTRAINT `factura_ibfk_1` FOREIGN KEY (`cufd_id`) REFERENCES `siat_cufd` (`id`);

--
-- Filtros para la tabla `factura_recibida`
--
ALTER TABLE `factura_recibida`
  ADD CONSTRAINT `factura_recibida_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`);

--
-- Filtros para la tabla `impresora`
--
ALTER TABLE `impresora`
  ADD CONSTRAINT `impresora_ibfk_1` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursal` (`id`);

--
-- Filtros para la tabla `movimiento`
--
ALTER TABLE `movimiento`
  ADD CONSTRAINT `movimiento_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `producto` (`id`);

--
-- Filtros para la tabla `producto`
--
ALTER TABLE `producto`
  ADD CONSTRAINT `producto_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`);

--
-- Filtros para la tabla `proveedor`
--
ALTER TABLE `proveedor`
  ADD CONSTRAINT `proveedor_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`);

--
-- Filtros para la tabla `punto_venta`
--
ALTER TABLE `punto_venta`
  ADD CONSTRAINT `punto_venta_ibfk_1` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursal` (`id`);

--
-- Filtros para la tabla `sesion`
--
ALTER TABLE `sesion`
  ADD CONSTRAINT `sesion_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `siat_config`
--
ALTER TABLE `siat_config`
  ADD CONSTRAINT `siat_config_ibfk_1` FOREIGN KEY (`sucursal_id`) REFERENCES `sucursal` (`id`);

--
-- Filtros para la tabla `siat_cufd`
--
ALTER TABLE `siat_cufd`
  ADD CONSTRAINT `siat_cufd_ibfk_1` FOREIGN KEY (`siat_config_id`) REFERENCES `siat_config` (`id`);

--
-- Filtros para la tabla `siat_evento`
--
ALTER TABLE `siat_evento`
  ADD CONSTRAINT `siat_evento_ibfk_1` FOREIGN KEY (`siat_config_id`) REFERENCES `siat_config` (`id`);

--
-- Filtros para la tabla `sin_venta_externa`
--
ALTER TABLE `sin_venta_externa`
  ADD CONSTRAINT `sin_venta_externa_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`);

--
-- Filtros para la tabla `sin_venta_externa_item`
--
ALTER TABLE `sin_venta_externa_item`
  ADD CONSTRAINT `sin_venta_externa_item_ibfk_1` FOREIGN KEY (`venta_externa_id`) REFERENCES `sin_venta_externa` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `stock`
--
ALTER TABLE `stock`
  ADD CONSTRAINT `stock_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `producto` (`id`),
  ADD CONSTRAINT `stock_ibfk_2` FOREIGN KEY (`almacen_id`) REFERENCES `almacen` (`id`);

--
-- Filtros para la tabla `sucursal`
--
ALTER TABLE `sucursal`
  ADD CONSTRAINT `sucursal_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`);

--
-- Filtros para la tabla `usuario`
--
ALTER TABLE `usuario`
  ADD CONSTRAINT `usuario_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`);

--
-- Filtros para la tabla `usuario_permiso`
--
ALTER TABLE `usuario_permiso`
  ADD CONSTRAINT `usuario_permiso_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuario` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `usuario_permiso_ibfk_2` FOREIGN KEY (`permiso_clave`) REFERENCES `permiso` (`clave`) ON DELETE CASCADE;

--
-- Filtros para la tabla `venta`
--
ALTER TABLE `venta`
  ADD CONSTRAINT `venta_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`);

--
-- Filtros para la tabla `venta_detalle`
--
ALTER TABLE `venta_detalle`
  ADD CONSTRAINT `venta_detalle_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `venta` (`id`) ON DELETE CASCADE;

--
-- Filtros para la tabla `webhook`
--
ALTER TABLE `webhook`
  ADD CONSTRAINT `webhook_ibfk_1` FOREIGN KEY (`empresa_id`) REFERENCES `empresa` (`id`);

--
-- Filtros para la tabla `webhook_intento`
--
ALTER TABLE `webhook_intento`
  ADD CONSTRAINT `webhook_intento_ibfk_1` FOREIGN KEY (`webhook_id`) REFERENCES `webhook` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;

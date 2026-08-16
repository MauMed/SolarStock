-- =====================================================================
-- SISTEMA DE CONTROL DE INVENTARIOS + FACTURACION SIAT (Bolivia)
-- MySQL 5.7+ / MariaDB 10.3+  |  utf8mb4
-- =====================================================================
SET NAMES utf8mb4;
CREATE DATABASE IF NOT EXISTS inventario_sin CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE inventario_sin;

CREATE TABLE empresa (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nit VARCHAR(15) NOT NULL,
  razon_social VARCHAR(200) NOT NULL,
  direccion VARCHAR(255), telefono VARCHAR(30), municipio VARCHAR(100),
  logo_url VARCHAR(255),
  facturacion_activa TINYINT(1) NOT NULL DEFAULT 0,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE sucursal (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  codigo_sin INT NOT NULL DEFAULT 0,
  nombre VARCHAR(100) NOT NULL,
  direccion VARCHAR(255), telefono VARCHAR(30),
  activa TINYINT(1) DEFAULT 1,
  FOREIGN KEY (empresa_id) REFERENCES empresa(id)
) ENGINE=InnoDB;

CREATE TABLE punto_venta (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sucursal_id INT NOT NULL,
  codigo_sin INT NOT NULL DEFAULT 0,
  nombre VARCHAR(100) NOT NULL,
  FOREIGN KEY (sucursal_id) REFERENCES sucursal(id)
) ENGINE=InnoDB;

CREATE TABLE usuario (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  sucursal_id INT NULL,
  nombre VARCHAR(120) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  pass_hash VARCHAR(255) NOT NULL,
  rol ENUM('admin','gerente','cajero','almacen','auditor') NOT NULL DEFAULT 'cajero',
  activo TINYINT(1) DEFAULT 1,
  ultimo_login TIMESTAMP NULL,
  FOREIGN KEY (empresa_id) REFERENCES empresa(id)
) ENGINE=InnoDB;

CREATE TABLE sesion (
  token CHAR(64) PRIMARY KEY,
  usuario_id INT NOT NULL,
  expira TIMESTAMP NOT NULL,
  FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE categoria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  padre_id INT NULL,
  FOREIGN KEY (empresa_id) REFERENCES empresa(id)
) ENGINE=InnoDB;

CREATE TABLE producto (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  categoria_id INT NULL,
  codigo_interno VARCHAR(50) NOT NULL,
  codigo_barras VARCHAR(64) NULL,
  nombre VARCHAR(200) NOT NULL,
  descripcion TEXT,
  unidad_medida_sin INT DEFAULT 58,
  precio_compra DECIMAL(14,4) DEFAULT 0,
  precio_venta DECIMAL(14,4) NOT NULL DEFAULT 0,
  stock_minimo DECIMAL(12,3) DEFAULT 0,
  maneja_lotes TINYINT(1) DEFAULT 0,
  imagen_url VARCHAR(255),
  codigo_actividad_sin VARCHAR(20) NULL,
  codigo_producto_sin  VARCHAR(20) NULL,
  activo TINYINT(1) DEFAULT 1,
  externo_ref VARCHAR(80) NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_prod (empresa_id, codigo_interno),
  KEY idx_barras (codigo_barras),
  FOREIGN KEY (empresa_id) REFERENCES empresa(id)
) ENGINE=InnoDB;

CREATE TABLE almacen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sucursal_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  FOREIGN KEY (sucursal_id) REFERENCES sucursal(id)
) ENGINE=InnoDB;

CREATE TABLE stock (
  producto_id INT NOT NULL,
  almacen_id INT NOT NULL,
  cantidad DECIMAL(14,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (producto_id, almacen_id),
  FOREIGN KEY (producto_id) REFERENCES producto(id),
  FOREIGN KEY (almacen_id) REFERENCES almacen(id)
) ENGINE=InnoDB;

CREATE TABLE movimiento (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  producto_id INT NOT NULL,
  almacen_id INT NOT NULL,
  tipo ENUM('COMPRA','VENTA','AJUSTE+','AJUSTE-','TRASPASO_IN','TRASPASO_OUT','DEVOLUCION') NOT NULL,
  cantidad DECIMAL(14,3) NOT NULL,
  costo_unit DECIMAL(14,4) DEFAULT 0,
  saldo_resultante DECIMAL(14,3) NOT NULL,
  referencia_tipo VARCHAR(30) NULL,
  referencia_id BIGINT NULL,
  usuario_id INT NULL,
  fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_prod_fecha (producto_id, fecha),
  FOREIGN KEY (producto_id) REFERENCES producto(id)
) ENGINE=InnoDB;

CREATE TABLE proveedor (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  nit VARCHAR(20), razon_social VARCHAR(200) NOT NULL,
  telefono VARCHAR(30), email VARCHAR(150),
  FOREIGN KEY (empresa_id) REFERENCES empresa(id)
) ENGINE=InnoDB;

CREATE TABLE compra (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL, sucursal_id INT NOT NULL,
  proveedor_id INT NULL, almacen_id INT NOT NULL,
  nro_documento VARCHAR(40), total DECIMAL(14,2) NOT NULL DEFAULT 0,
  estado ENUM('BORRADOR','RECIBIDA','ANULADA') DEFAULT 'RECIBIDA',
  usuario_id INT, fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresa(id)
) ENGINE=InnoDB;

CREATE TABLE compra_detalle (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  compra_id BIGINT NOT NULL, producto_id INT NOT NULL,
  cantidad DECIMAL(14,3) NOT NULL, costo_unit DECIMAL(14,4) NOT NULL,
  FOREIGN KEY (compra_id) REFERENCES compra(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE cliente (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  tipo_doc_sin INT NOT NULL DEFAULT 1,
  num_documento VARCHAR(20) NOT NULL,
  complemento VARCHAR(3) NULL,
  razon_social VARCHAR(200) NOT NULL,
  email VARCHAR(150), telefono VARCHAR(30),
  externo_ref VARCHAR(80) NULL,
  KEY idx_doc (empresa_id, num_documento),
  FOREIGN KEY (empresa_id) REFERENCES empresa(id)
) ENGINE=InnoDB;

CREATE TABLE venta (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL, sucursal_id INT NOT NULL,
  punto_venta_id INT NULL, almacen_id INT NOT NULL,
  cliente_id INT NULL,
  subtotal DECIMAL(14,2) NOT NULL, descuento DECIMAL(14,2) DEFAULT 0,
  total DECIMAL(14,2) NOT NULL,
  metodo_pago_sin INT DEFAULT 1,
  estado ENUM('COMPLETADA','ANULADA') DEFAULT 'COMPLETADA',
  origen ENUM('POS','API','ECOMMERCE') DEFAULT 'POS',
  usuario_id INT, fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresa(id)
) ENGINE=InnoDB;

CREATE TABLE venta_detalle (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  venta_id BIGINT NOT NULL, producto_id INT NOT NULL,
  cantidad DECIMAL(14,3) NOT NULL, precio_unit DECIMAL(14,4) NOT NULL,
  descuento DECIMAL(14,2) DEFAULT 0,
  FOREIGN KEY (venta_id) REFERENCES venta(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE siat_config (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sucursal_id INT NOT NULL, punto_venta_id INT NULL,
  nit_emisor VARCHAR(15) NOT NULL,
  codigo_sistema VARCHAR(40) NOT NULL,
  token_delegado TEXT NOT NULL,
  token_expira DATE NOT NULL,
  ambiente ENUM('HOMOLOGACION','PRODUCCION') DEFAULT 'HOMOLOGACION',
  modalidad INT DEFAULT 2,
  cuis VARCHAR(30) NULL, cuis_expira DATE NULL,
  UNIQUE KEY uq_cfg (sucursal_id, punto_venta_id),
  FOREIGN KEY (sucursal_id) REFERENCES sucursal(id)
) ENGINE=InnoDB;

CREATE TABLE siat_cufd (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  siat_config_id INT NOT NULL,
  codigo_cufd VARCHAR(120) NOT NULL,
  codigo_control VARCHAR(60) NOT NULL,
  direccion VARCHAR(255) NULL,
  fecha_inicio DATETIME NOT NULL,
  fecha_fin DATETIME NOT NULL,
  KEY idx_vigencia (siat_config_id, fecha_fin),
  FOREIGN KEY (siat_config_id) REFERENCES siat_config(id)
) ENGINE=InnoDB;

CREATE TABLE siat_actividad (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  codigo_actividad VARCHAR(20) NOT NULL,
  descripcion VARCHAR(255),
  UNIQUE KEY uq_act (empresa_id, codigo_actividad)
) ENGINE=InnoDB;

CREATE TABLE siat_producto_sin (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  codigo_actividad VARCHAR(20) NOT NULL,
  codigo_producto VARCHAR(20) NOT NULL,
  descripcion VARCHAR(255),
  UNIQUE KEY uq_ps (empresa_id, codigo_actividad, codigo_producto)
) ENGINE=InnoDB;

CREATE TABLE siat_parametro (
  id INT AUTO_INCREMENT PRIMARY KEY,
  grupo VARCHAR(40) NOT NULL,
  codigo VARCHAR(10) NOT NULL,
  descripcion VARCHAR(255),
  UNIQUE KEY uq_par (grupo, codigo)
) ENGINE=InnoDB;

CREATE TABLE factura (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  venta_id BIGINT NULL,
  siat_config_id INT NOT NULL,
  cufd_id BIGINT NOT NULL,
  numero_factura BIGINT NOT NULL,
  cuf VARCHAR(80) NOT NULL,
  fecha_emision DATETIME(3) NOT NULL,
  tipo_emision INT NOT NULL DEFAULT 1,
  tipo_factura INT NOT NULL DEFAULT 1,
  doc_sector INT NOT NULL DEFAULT 1,
  cliente_snapshot JSON NULL,
  monto_total DECIMAL(14,2) NOT NULL,
  estado ENUM('PENDIENTE','EN_PROCESAMIENTO','VALIDA','RECHAZADA','ANULADA') NOT NULL DEFAULT 'PENDIENTE',
  codigo_recepcion VARCHAR(80) NULL,
  error_codigo VARCHAR(20) NULL, error_descripcion TEXT NULL,
  motivo_anulacion INT NULL,
  xml LONGTEXT NULL,
  pdf_path VARCHAR(255) NULL,
  evento_id BIGINT NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cuf (cuf),
  KEY idx_estado (estado),
  FOREIGN KEY (cufd_id) REFERENCES siat_cufd(id)
) ENGINE=InnoDB;

CREATE TABLE factura_recibida (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  nit_proveedor VARCHAR(15) NOT NULL,
  razon_social VARCHAR(200),
  numero_factura BIGINT, cuf VARCHAR(80),
  fecha_emision DATETIME,
  monto_total DECIMAL(14,2),
  codigo_control VARCHAR(60) NULL,
  origen ENUM('QR_SCAN','IMPORT_SIAT','MANUAL') DEFAULT 'QR_SCAN',
  compra_id BIGINT NULL,
  verificada TINYINT(1) DEFAULT 0,
  datos_raw JSON NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fr (empresa_id, nit_proveedor, numero_factura, cuf),
  FOREIGN KEY (empresa_id) REFERENCES empresa(id)
) ENGINE=InnoDB;

CREATE TABLE siat_evento (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  siat_config_id INT NOT NULL,
  codigo_evento INT NOT NULL,
  fecha_inicio DATETIME NOT NULL,
  fecha_fin DATETIME NULL,
  codigo_recepcion_evento VARCHAR(80) NULL,
  estado ENUM('ABIERTO','CERRADO','REPORTADO') DEFAULT 'ABIERTO',
  FOREIGN KEY (siat_config_id) REFERENCES siat_config(id)
) ENGINE=InnoDB;

CREATE TABLE siat_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  factura_id BIGINT NULL,
  origen VARCHAR(40) NOT NULL,
  gravedad ENUM('CONEXION','VALIDACION','INFO') NOT NULL,
  codigo_error VARCHAR(20), descripcion TEXT,
  fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_fac (factura_id)
) ENGINE=InnoDB;

CREATE TABLE api_key (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  llave CHAR(64) NOT NULL UNIQUE,
  permisos JSON NOT NULL,
  activa TINYINT(1) DEFAULT 1,
  ultimo_uso TIMESTAMP NULL,
  FOREIGN KEY (empresa_id) REFERENCES empresa(id)
) ENGINE=InnoDB;

CREATE TABLE webhook (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  evento VARCHAR(60) NOT NULL,
  url VARCHAR(255) NOT NULL,
  secreto VARCHAR(64) NOT NULL,
  activo TINYINT(1) DEFAULT 1,
  FOREIGN KEY (empresa_id) REFERENCES empresa(id)
) ENGINE=InnoDB;

CREATE TABLE webhook_intento (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  webhook_id INT NOT NULL,
  payload JSON, http_status INT NULL, intentos INT DEFAULT 0,
  estado ENUM('PENDIENTE','OK','FALLIDO') DEFAULT 'PENDIENTE',
  proximo_intento TIMESTAMP NULL,
  FOREIGN KEY (webhook_id) REFERENCES webhook(id)
) ENGINE=InnoDB;

CREATE TABLE impresora (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sucursal_id INT NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  tipo ENUM('TERMICA_RED','TERMICA_BT','LASER_TINTA') NOT NULL,
  ip VARCHAR(45) NULL, puerto INT DEFAULT 9100,
  ancho_mm INT DEFAULT 80,
  predeterminada TINYINT(1) DEFAULT 0,
  FOREIGN KEY (sucursal_id) REFERENCES sucursal(id)
) ENGINE=InnoDB;

INSERT INTO siat_parametro (grupo,codigo,descripcion) VALUES
('TIPO_DOC','1','Cedula de Identidad'),('TIPO_DOC','2','CI Extranjero'),
('TIPO_DOC','3','Pasaporte'),('TIPO_DOC','4','NIT'),('TIPO_DOC','5','DNI'),
('MOTIVO_ANULACION','1','Factura mal emitida'),('MOTIVO_ANULACION','2','Nota de credito/devolucion'),
('MOTIVO_ANULACION','3','Totalmente devuelto'),('MOTIVO_ANULACION','4','Error de tipeo o sistema'),
('EVENTO','1','Corte de internet'),('EVENTO','2','Caida del servicio del SIN'),
('EVENTO','3','Corte de servicio web de la Administracion Tributaria');

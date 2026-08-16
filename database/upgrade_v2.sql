-- =====================================================================
-- V2: marca blanca, permisos granulares, conciliacion SIN, compras+
-- =====================================================================
USE inventario_sin;

-- Marca blanca (personalizacion por cliente)
ALTER TABLE empresa
  ADD COLUMN nombre_comercial VARCHAR(120) NULL AFTER razon_social,
  ADD COLUMN color_primario CHAR(7) NOT NULL DEFAULT '#b91c1c',
  ADD COLUMN color_acento  CHAR(7) NOT NULL DEFAULT '#f59e0b',
  ADD COLUMN fuente_menu VARCHAR(120) NOT NULL DEFAULT 'system-ui',
  ADD COLUMN rubro VARCHAR(60) NULL;   -- ferreteria, warehouse, plasticos, etc. (informativo)

-- ------------------- PERMISOS GRANULARES -------------------
-- El rol da los permisos por defecto; usuario_permiso los ajusta
-- individualmente (concedido = 1 agrega, concedido = 0 revoca).
CREATE TABLE permiso (
  clave VARCHAR(60) PRIMARY KEY,
  modulo VARCHAR(40) NOT NULL,
  descripcion VARCHAR(200) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE usuario_permiso (
  usuario_id INT NOT NULL,
  permiso_clave VARCHAR(60) NOT NULL,
  concedido TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (usuario_id, permiso_clave),
  FOREIGN KEY (usuario_id) REFERENCES usuario(id) ON DELETE CASCADE,
  FOREIGN KEY (permiso_clave) REFERENCES permiso(clave) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO permiso (clave, modulo, descripcion) VALUES
('dashboard.ver','Reportes','Ver dashboard y KPIs'),
('productos.ver','Productos','Ver catalogo de productos'),
('productos.crear','Productos','Crear y editar productos'),
('productos.importar','Productos','Carga masiva de productos'),
('inventario.ver','Inventario','Consultar stock y kardex'),
('inventario.ajustar','Inventario','Registrar ajustes de inventario'),
('compras.ver','Compras','Ver compras'),
('compras.crear','Compras','Registrar compras (con escaneo)'),
('ventas.crear','Ventas','Vender en el POS'),
('ventas.anular','Ventas','Anular ventas'),
('facturas.emitir','Facturacion','Emitir facturas SIAT'),
('facturas.anular','Facturacion','Anular facturas SIAT'),
('facturas.leer','Facturacion','Leer/registrar facturas recibidas'),
('conciliacion.ver','Conciliacion','Ver conciliacion con el SIN'),
('conciliacion.ejecutar','Conciliacion','Importar ventas del SIN y descontar stock'),
('usuarios.gestionar','Usuarios','Crear usuarios y asignar permisos'),
('marca.editar','Configuracion','Editar marca blanca (logo, colores)'),
('api.gestionar','Integraciones','Crear API keys y webhooks');

-- ---------------- CONCILIACION CONTRA EL SIN ----------------
-- Ventas detectadas en el registro del SIN que NO nacieron en este
-- sistema (facturadas desde otro aplicativo). Al identificar los
-- productos por codigo, se descuenta stock automaticamente y el
-- inventario queda "cuadrado".
CREATE TABLE sin_venta_externa (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  cuf VARCHAR(80) NULL,
  numero_factura BIGINT NULL,
  nit_cliente VARCHAR(20) NULL,
  razon_social VARCHAR(200) NULL,
  fecha_emision DATETIME NULL,
  monto_total DECIMAL(14,2) NOT NULL DEFAULT 0,
  origen ENUM('IMPORT_CSV','API','MANUAL') DEFAULT 'IMPORT_CSV',
  estado ENUM('CONCILIADA_INTERNA','EXTERNA_PENDIENTE','DESCONTADA','IGNORADA') NOT NULL DEFAULT 'EXTERNA_PENDIENTE',
  almacen_id INT NULL,                -- de donde se desconto
  datos_raw JSON NULL,
  creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sve (empresa_id, cuf, numero_factura),
  FOREIGN KEY (empresa_id) REFERENCES empresa(id)
) ENGINE=InnoDB;

CREATE TABLE sin_venta_externa_item (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  venta_externa_id BIGINT NOT NULL,
  codigo_producto VARCHAR(64) NOT NULL,   -- codigo interno o de barras del XML externo
  descripcion VARCHAR(200) NULL,
  cantidad DECIMAL(14,3) NOT NULL,
  producto_id INT NULL,                   -- resuelto al conciliar
  descontado TINYINT(1) DEFAULT 0,
  FOREIGN KEY (venta_externa_id) REFERENCES sin_venta_externa(id) ON DELETE CASCADE
) ENGINE=InnoDB;

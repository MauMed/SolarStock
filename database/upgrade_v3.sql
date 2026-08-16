-- V3: permiso de gestion SIAT + logo local
USE inventario_sin;
INSERT INTO permiso (clave, modulo, descripcion) VALUES
('siat.gestionar','Facturacion','Configurar la conexion con el SIAT (credenciales, CUIS, CUFD)');

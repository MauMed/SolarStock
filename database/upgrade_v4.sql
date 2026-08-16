-- V4: color del menu + permisos de informes
USE inventario_sin;
ALTER TABLE empresa ADD COLUMN color_menu CHAR(7) NOT NULL DEFAULT '#71717a' AFTER color_acento;
INSERT INTO permiso (clave, modulo, descripcion) VALUES
('informes.ver','Informes','Ver el modulo de informes'),
('informes.exportar','Informes','Exportar informes a CSV');

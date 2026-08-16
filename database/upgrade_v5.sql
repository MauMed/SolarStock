-- V5: restablecimiento de contrasena
USE inventario_sin;
ALTER TABLE usuario
  ADD COLUMN reset_token CHAR(64) NULL,
  ADD COLUMN reset_expira DATETIME NULL;

<?php
namespace Api;

use Core\DB;
use Integrations\Webhooks;

/**
 * Logica central de inventario. Toda entrada/salida pasa por movimiento()
 * para que el kardex sea la unica fuente de verdad.
 */
class Inventario
{
    public static function movimiento(int $empresaId, int $productoId, int $almacenId,
        string $tipo, float $cantidad, float $costo = 0,
        ?string $refTipo = null, ?int $refId = null, ?int $usuarioId = null): float
    {
        return DB::tx(function () use ($empresaId, $productoId, $almacenId, $tipo, $cantidad, $costo, $refTipo, $refId, $usuarioId) {
            $signo = in_array($tipo, ['COMPRA', 'AJUSTE+', 'TRASPASO_IN', 'DEVOLUCION']) ? 1 : -1;
            DB::q('INSERT INTO stock (producto_id, almacen_id, cantidad) VALUES (?,?,0)
                   ON DUPLICATE KEY UPDATE cantidad = cantidad', [$productoId, $almacenId]);
            // Lock pesimista para evitar sobreventa con cajas concurrentes
            $st = DB::q('SELECT cantidad FROM stock WHERE producto_id = ? AND almacen_id = ? FOR UPDATE',
                        [$productoId, $almacenId])->fetch();
            $nuevo = (float)$st['cantidad'] + $signo * $cantidad;
            if ($nuevo < 0) throw new \RuntimeException('STOCK_INSUFICIENTE');
            DB::q('UPDATE stock SET cantidad = ? WHERE producto_id = ? AND almacen_id = ?',
                  [$nuevo, $productoId, $almacenId]);
            DB::q('INSERT INTO movimiento (empresa_id, producto_id, almacen_id, tipo, cantidad, costo_unit,
                     saldo_resultante, referencia_tipo, referencia_id, usuario_id)
                   VALUES (?,?,?,?,?,?,?,?,?,?)',
                  [$empresaId, $productoId, $almacenId, $tipo, $cantidad, $costo, $nuevo, $refTipo, $refId, $usuarioId]);

            $prod = DB::q('SELECT nombre, stock_minimo FROM producto WHERE id = ?', [$productoId])->fetch();
            if ($nuevo <= (float)$prod['stock_minimo']) {
                Webhooks::disparar($empresaId, 'stock.bajo', [
                    'producto_id' => $productoId, 'nombre' => $prod['nombre'],
                    'almacen_id' => $almacenId, 'stock' => $nuevo, 'minimo' => $prod['stock_minimo'],
                ]);
            }
            return $nuevo;
        });
    }

    public static function crearVenta(array $u, array $in): array
    {
        $ventaId = DB::tx(function () use ($u, $in) {
            $sub = 0;
            foreach ($in['items'] as $it) $sub += $it['cantidad'] * $it['precio_unit'] - ($it['descuento'] ?? 0);
            $desc = (float)($in['descuento'] ?? 0);
            DB::q('INSERT INTO venta (empresa_id, sucursal_id, punto_venta_id, almacen_id, cliente_id,
                     subtotal, descuento, total, metodo_pago_sin, origen, usuario_id)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                  [$u['empresa_id'], $in['sucursal_id'], $in['punto_venta_id'] ?? null,
                   $in['almacen_id'], $in['cliente_id'] ?? null,
                   $sub, $desc, $sub - $desc, $in['metodo_pago_sin'] ?? 1,
                   $in['origen'] ?? 'POS', $u['id'] ?? null]);
            $ventaId = DB::insertId();
            foreach ($in['items'] as $it) {
                DB::q('INSERT INTO venta_detalle (venta_id, producto_id, cantidad, precio_unit, descuento)
                       VALUES (?,?,?,?,?)',
                      [$ventaId, $it['producto_id'], $it['cantidad'], $it['precio_unit'], $it['descuento'] ?? 0]);
            }
            return $ventaId;
        });
        // Movimientos fuera de la tx principal (cada uno con su propia tx y lock)
        foreach ($in['items'] as $it) {
            self::movimiento($u['empresa_id'], $it['producto_id'], $in['almacen_id'],
                'VENTA', $it['cantidad'], 0, 'venta', $ventaId, $u['id'] ?? null);
        }
        $venta = DB::q('SELECT * FROM venta WHERE id = ?', [$ventaId])->fetch();
        Webhooks::disparar($u['empresa_id'], 'venta.creada', $venta);
        return $venta;
    }
}

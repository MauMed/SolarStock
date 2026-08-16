<?php
namespace Siat;

use Core\DB;
use Api\Inventario;

/**
 * CONCILIACION DE INVENTARIO CONTRA EL SIN.
 *
 * Escenario: el cliente factura tambien desde OTRO sistema (un POS ajeno,
 * la oficina virtual, etc.). Esas ventas no pasaron por este inventario,
 * asi que el stock queda inflado. Este modulo:
 *
 *  1. Importa el registro de VENTAS del SIN (CSV de la oficina virtual
 *     o payload via API v1/conciliacion/ventas).
 *  2. Cruza cada factura contra las emitidas por este sistema
 *     (match por CUF o numero) -> CONCILIADA_INTERNA (ya descontada).
 *  3. Las que no cruzan quedan EXTERNA_PENDIENTE. Si traen detalle de
 *     items con codigo de producto, se resuelven contra el catalogo
 *     (codigo_interno o codigo_barras) y se descuenta stock automatico
 *     -> DESCONTADA. Si no hay codigo reconocible, quedan pendientes
 *     para asignacion manual en pantalla.
 *  4. resumenCuadre() muestra el inventario cuadrado: stock fisico,
 *     pendientes de conciliar y diferencias.
 */
class Conciliacion
{
    /** Importar registro de ventas del SIN (CSV oficina virtual, separador ;) */
    public static function importarVentasCsv(int $empresaId, string $rutaCsv): array
    {
        $fh = fopen($rutaCsv, 'r') ?: throw new \RuntimeException('No se pudo abrir el CSV');
        $cab = null; $n = 0;
        while (($fila = fgetcsv($fh, 0, ';')) !== false) {
            if (!$cab) { $cab = array_map(fn($c) => strtolower(trim($c)), $fila); continue; }
            $r = @array_combine($cab, $fila);
            if (!$r) continue;
            $cuf = $r['codigo de autorizacion'] ?? $r['cuf'] ?? null;
            $nro = (int)($r['nro. factura'] ?? $r['numero factura'] ?? $r['numero'] ?? 0);
            if (!$cuf && !$nro) continue;
            DB::q('INSERT IGNORE INTO sin_venta_externa
                     (empresa_id, cuf, numero_factura, nit_cliente, razon_social,
                      fecha_emision, monto_total, origen, datos_raw)
                   VALUES (?,?,?,?,?,?,?,?,?)',
                  [$empresaId, $cuf, $nro,
                   $r['nit / ci cliente'] ?? $r['nit cliente'] ?? null,
                   $r['nombre o razon social'] ?? $r['razon social'] ?? null,
                   self::fecha($r['fecha de la factura'] ?? $r['fecha'] ?? null),
                   (float)str_replace(',', '.', $r['importe total de la venta'] ?? $r['monto'] ?? 0),
                   'IMPORT_CSV', json_encode($r, JSON_UNESCAPED_UNICODE)]);
            $n++;
        }
        fclose($fh);
        return ['importadas' => $n] + self::conciliar($empresaId);
    }

    /** Registro directo via API (otro aplicativo empuja sus ventas con detalle) */
    public static function registrarVentaApi(int $empresaId, array $v): array
    {
        DB::q('INSERT IGNORE INTO sin_venta_externa
                 (empresa_id, cuf, numero_factura, nit_cliente, razon_social,
                  fecha_emision, monto_total, origen, datos_raw)
               VALUES (?,?,?,?,?,?,?,?,?)',
              [$empresaId, $v['cuf'] ?? null, $v['numero_factura'] ?? null,
               $v['nit_cliente'] ?? null, $v['razon_social'] ?? null,
               self::fecha($v['fecha_emision'] ?? null), (float)($v['monto_total'] ?? 0),
               'API', json_encode($v, JSON_UNESCAPED_UNICODE)]);
        $id = (int)(DB::q('SELECT id FROM sin_venta_externa WHERE empresa_id = ? AND
                           (cuf = ? OR numero_factura = ?) ORDER BY id DESC LIMIT 1',
                          [$empresaId, $v['cuf'] ?? '', $v['numero_factura'] ?? 0])->fetch()['id'] ?? 0);
        foreach (($v['items'] ?? []) as $it) {
            DB::q('INSERT INTO sin_venta_externa_item (venta_externa_id, codigo_producto, descripcion, cantidad)
                   VALUES (?,?,?,?)',
                  [$id, $it['codigo'], $it['descripcion'] ?? null, (float)$it['cantidad']]);
        }
        return self::conciliar($empresaId);
    }

    /** Motor de cruce y descuento automatico */
    public static function conciliar(int $empresaId, ?int $almacenDefecto = null): array
    {
        $res = ['internas' => 0, 'descontadas' => 0, 'pendientes' => 0];
        $almacenDefecto ??= (int)(DB::q(
            'SELECT a.id FROM almacen a JOIN sucursal s ON s.id = a.sucursal_id
             WHERE s.empresa_id = ? ORDER BY a.id LIMIT 1', [$empresaId])->fetch()['id'] ?? 0);

        $pend = DB::q('SELECT * FROM sin_venta_externa WHERE empresa_id = ? AND estado = "EXTERNA_PENDIENTE"',
                      [$empresaId])->fetchAll();
        foreach ($pend as $v) {
            // Paso 1: ya la emitio este sistema?
            $interna = DB::q('SELECT id FROM factura WHERE (cuf = ? AND ? != "") OR (numero_factura = ? AND ? > 0) LIMIT 1',
                             [$v['cuf'] ?? '', $v['cuf'] ?? '', $v['numero_factura'] ?? 0, $v['numero_factura'] ?? 0])->fetch();
            if ($interna) {
                DB::q('UPDATE sin_venta_externa SET estado = "CONCILIADA_INTERNA" WHERE id = ?', [$v['id']]);
                $res['internas']++;
                continue;
            }
            // Paso 2: resolver items por codigo y descontar
            $items = DB::q('SELECT * FROM sin_venta_externa_item WHERE venta_externa_id = ? AND descontado = 0',
                           [$v['id']])->fetchAll();
            if (!$items) { $res['pendientes']++; continue; }   // sin detalle: asignacion manual

            $todosResueltos = true;
            foreach ($items as $it) {
                $p = DB::q('SELECT id FROM producto WHERE empresa_id = ? AND
                              (codigo_interno = ? OR codigo_barras = ? OR externo_ref = ?) LIMIT 1',
                           [$empresaId, $it['codigo_producto'], $it['codigo_producto'], $it['codigo_producto']])->fetch();
                if (!$p) { $todosResueltos = false; continue; }
                try {
                    Inventario::movimiento($empresaId, (int)$p['id'], $almacenDefecto,
                        'VENTA', (float)$it['cantidad'], 0, 'conciliacion_sin', (int)$v['id']);
                    DB::q('UPDATE sin_venta_externa_item SET producto_id = ?, descontado = 1 WHERE id = ?',
                          [$p['id'], $it['id']]);
                } catch (\RuntimeException $e) {
                    // STOCK_INSUFICIENTE: descontar hasta 0 no; dejar pendiente y loguear
                    DB::q('INSERT INTO siat_log (origen, gravedad, descripcion) VALUES (?,?,?)',
                          ['conciliacion', 'VALIDACION',
                           "Venta externa #{$v['id']} item {$it['codigo_producto']}: {$e->getMessage()}"]);
                    $todosResueltos = false;
                }
            }
            if ($todosResueltos) {
                DB::q('UPDATE sin_venta_externa SET estado = "DESCONTADA", almacen_id = ? WHERE id = ?',
                      [$almacenDefecto, $v['id']]);
                $res['descontadas']++;
            } else {
                $res['pendientes']++;
            }
        }
        return $res;
    }

    /** Asignacion manual: el operador indica que productos componen la venta externa */
    public static function asignarItems(int $ventaExternaId, array $items, int $almacenId): array
    {
        $v = DB::q('SELECT * FROM sin_venta_externa WHERE id = ?', [$ventaExternaId])->fetch()
            ?: throw new \RuntimeException('Venta externa no encontrada');
        foreach ($items as $it) {
            Inventario::movimiento((int)$v['empresa_id'], (int)$it['producto_id'], $almacenId,
                'VENTA', (float)$it['cantidad'], 0, 'conciliacion_manual', $ventaExternaId);
            DB::q('INSERT INTO sin_venta_externa_item
                     (venta_externa_id, codigo_producto, cantidad, producto_id, descontado)
                   VALUES (?,?,?,?,1)',
                  [$ventaExternaId, (string)$it['producto_id'], (float)$it['cantidad'], (int)$it['producto_id']]);
        }
        DB::q('UPDATE sin_venta_externa SET estado = "DESCONTADA", almacen_id = ? WHERE id = ?',
              [$almacenId, $ventaExternaId]);
        return ['ok' => true];
    }

    /** Inventario cuadrado: foto final del stock + estado de conciliacion */
    public static function resumenCuadre(int $empresaId): array
    {
        return [
            'stock' => DB::q(
                'SELECT p.id, p.codigo_interno, p.nombre, p.stock_minimo,
                        COALESCE(SUM(s.cantidad),0) disponible
                 FROM producto p LEFT JOIN stock s ON s.producto_id = p.id
                 WHERE p.empresa_id = ? AND p.activo = 1
                 GROUP BY p.id ORDER BY p.nombre', [$empresaId])->fetchAll(),
            'conciliacion' => DB::q(
                'SELECT estado, COUNT(*) c, COALESCE(SUM(monto_total),0) monto
                 FROM sin_venta_externa WHERE empresa_id = ? GROUP BY estado', [$empresaId])->fetchAll(),
            'pendientes' => DB::q(
                'SELECT * FROM sin_venta_externa WHERE empresa_id = ? AND estado = "EXTERNA_PENDIENTE"
                 ORDER BY fecha_emision DESC LIMIT 50', [$empresaId])->fetchAll(),
        ];
    }

    private static function fecha(?string $f): ?string
    {
        if (!$f) return null;
        $ts = strtotime(str_replace('/', '-', $f));
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    }
}

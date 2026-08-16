<?php
/**
 * Front controller / Router REST.
 * Rutas de usuario (Bearer) + rutas de integracion externa (X-Api-Key).
 * Compatible con hosting compartido (Namecheap): .htaccess redirige aqui.
 */
declare(strict_types=1);
error_reporting(E_ALL);

spl_autoload_register(function ($clase) {
    $mapa = ['Core' => 'Core', 'Api' => 'Api', 'Siat' => 'Siat',
             'Print_' => 'Print', 'Integrations' => 'Integrations'];
    [$ns, $nombre] = explode('\\', $clase, 2) + [null, null];
    if (!isset($mapa[$ns])) return;
    $f = dirname(__DIR__) . "/src/{$mapa[$ns]}/{$nombre}.php";
    if (is_file($f)) require $f;
});

use Core\{DB, Auth, Response};
use Api\Inventario;
use Siat\{Facturador, Cufd, LectorFacturas, Contingencia, Conciliacion, EstadoSiat};
use Core\Permisos;
use Print_\EscPos;
use Integrations\Webhooks;

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') Response::json([], 204);

$metodo = $_SERVER['REQUEST_METHOD'];

// Deteccion automatica del subdirectorio de instalacion.
// Funciona en la raiz (dominio.com), en subcarpetas (localhost/inve/public)
// y detras de alias/virtual hosts: se resta la carpeta donde vive index.php.
$uri  = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
if ($base !== '' && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base));
}
$ruta = trim($uri, '/');
$ruta = preg_replace('#^(api/)#', '', $ruta);
$in = json_decode(file_get_contents('php://input'), true) ?: [];

try {
    switch (true) {



        // ------------------ RAIZ: servir la aplicacion ------------------
        case ($ruta === '' || $ruta === 'index.php') && $metodo === 'GET':
            header('Content-Type: text/html; charset=utf-8');
            readfile(__DIR__ . '/app.html');
            exit;

        // -------------------- MARCA BLANCA (publico) --------------------
        case $ruta === 'branding' && $metodo === 'GET':
            $e = DB::q('SELECT nombre_comercial, razon_social, logo_url, color_primario,
                          color_acento, color_menu, fuente_menu, rubro FROM empresa ORDER BY id LIMIT 1')->fetch();
            Response::ok($e + ['anio' => date('Y'),
                'creditos' => ($e['nombre_comercial'] ?: $e['razon_social']) . ' - ' . date('Y') .
                              ' | SolarStock, una solucion de Alfa Solaris']);

        case $ruta === 'branding' && $metodo === 'PUT':
            $u = Auth::requireUser(); Permisos::exigir($u, 'marca.editar');
            DB::q('UPDATE empresa SET nombre_comercial = ?, color_primario = ?,
                     color_acento = ?, color_menu = ?, rubro = ? WHERE id = ?',
                  [$in['nombre_comercial'] ?? null,
                   $in['color_primario'] ?? '#b91c1c', $in['color_acento'] ?? '#f59e0b',
                   $in['color_menu'] ?? '#71717a', $in['rubro'] ?? null, $u['empresa_id']]);
            Response::ok(true);


        // ---------------- LOGO: subida de archivo (base64) ----------------
        case $ruta === 'branding/logo' && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'marca.editar');
            $b64 = $in['imagen'] ?? '';
            if (preg_match('#^data:image/(png|jpe?g|webp|svg\+xml);base64,(.+)$#', $b64, $m)) {
                $ext = ['png'=>'png','jpg'=>'jpg','jpeg'=>'jpg','webp'=>'webp','svg+xml'=>'svg'][$m[1]];
                $bin = base64_decode($m[2]);
                if (strlen($bin) > 2 * 1024 * 1024) Response::error('Logo maximo 2 MB');
                @mkdir(__DIR__ . '/uploads', 0775, true);
                $nombre = 'uploads/logo_' . $u['empresa_id'] . '.' . $ext;
                file_put_contents(__DIR__ . '/' . $nombre, $bin);
                DB::q('UPDATE empresa SET logo_url = ? WHERE id = ?', [$nombre, $u['empresa_id']]);
                Response::ok(['logo_url' => $nombre]);
            }
            Response::error('Formato de imagen no valido (png, jpg, webp, svg)');

        // ============== MODULO SIAT: gestion de la conexion ==============
        case $ruta === 'siat/estado' && $metodo === 'GET':
            $u = Auth::requireUser();
            Response::ok(EstadoSiat::calcular((int)$u['empresa_id']));

        case $ruta === 'siat/config' && $metodo === 'GET':
            $u = Auth::requireUser(); Permisos::exigir($u, 'siat.gestionar');
            $rows = DB::q('SELECT sc.id, sc.sucursal_id, sc.punto_venta_id, sc.nit_emisor,
                             sc.codigo_sistema, sc.token_expira, sc.ambiente, sc.modalidad,
                             sc.cuis, sc.cuis_expira, s.nombre sucursal,
                             LEFT(sc.token_delegado, 12) token_inicio
                           FROM siat_config sc JOIN sucursal s ON s.id = sc.sucursal_id
                           WHERE s.empresa_id = ?', [$u['empresa_id']])->fetchAll();
            foreach ($rows as &$r) {
                $c = Cufd::vigente((int)$r['id']);
                $r['cufd_vigente'] = $c ? $c['fecha_fin'] : null;
            }
            Response::ok(['configs' => $rows,
                'sucursales' => DB::q('SELECT id, nombre FROM sucursal WHERE empresa_id = ?', [$u['empresa_id']])->fetchAll(),
                'facturacion_activa' => (int)DB::q('SELECT facturacion_activa FROM empresa WHERE id = ?',
                    [$u['empresa_id']])->fetch()['facturacion_activa']]);

        case $ruta === 'siat/config' && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'siat.gestionar');
            DB::q('INSERT INTO siat_config (sucursal_id, punto_venta_id, nit_emisor, codigo_sistema,
                     token_delegado, token_expira, ambiente)
                   VALUES (?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE nit_emisor = VALUES(nit_emisor),
                     codigo_sistema = VALUES(codigo_sistema), token_delegado = VALUES(token_delegado),
                     token_expira = VALUES(token_expira), ambiente = VALUES(ambiente)',
                  [$in['sucursal_id'], $in['punto_venta_id'] ?? null, $in['nit_emisor'],
                   $in['codigo_sistema'], $in['token_delegado'], $in['token_expira'],
                   $in['ambiente'] ?? 'HOMOLOGACION']);
            Response::ok(true);

        case $ruta === 'siat/activar' && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'siat.gestionar');
            DB::q('UPDATE empresa SET facturacion_activa = ? WHERE id = ?',
                  [(int)$in['activa'], $u['empresa_id']]);
            Response::ok(true);

        case preg_match('#^siat/config/(\d+)/probar$#', $ruta, $m) && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'siat.gestionar');
            Response::ok(EstadoSiat::probar((int)$m[1]));

        case preg_match('#^siat/config/(\d+)/cuis$#', $ruta, $m) && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'siat.gestionar');
            $cfg = DB::q('SELECT * FROM siat_config WHERE id = ?', [(int)$m[1]])->fetch();
            $cuis = Cufd::solicitarCuis($cfg);
            $cuis ? Response::ok(['cuis' => $cuis]) : Response::error('El SIAT no devolvio CUIS (revisar credenciales)');

        case preg_match('#^siat/config/(\d+)/cufd$#', $ruta, $m) && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'siat.gestionar');
            $cfg = DB::q('SELECT * FROM siat_config WHERE id = ?', [(int)$m[1]])->fetch();
            $c = Cufd::solicitar($cfg);
            $c ? Response::ok($c) : Response::error('El SIAT no devolvio CUFD (revisar CUIS y token)');



        case $ruta === 'informes/resumen' && $metodo === 'GET':
            $u = Auth::requireUser(); Permisos::exigir($u, 'informes.ver');
            $eid = $u['empresa_id'];
            $desde = $_GET['desde'] ?? date('Y-m-01');
            $hasta = ($_GET['hasta'] ?? date('Y-m-d')) . ' 23:59:59';
            $ventas = DB::q('SELECT COUNT(DISTINCT v.id) n, COALESCE(SUM(vd.cantidad * vd.precio_unit - vd.descuento),0) total,
                  COALESCE(SUM(vd.cantidad * p.precio_compra),0) cmv
                FROM venta v JOIN venta_detalle vd ON vd.venta_id = v.id
                JOIN producto p ON p.id = vd.producto_id
                WHERE v.empresa_id = ? AND v.fecha BETWEEN ? AND ? AND v.estado = "COMPLETADA"',
                [$eid, $desde, $hasta])->fetch();
            $compras = DB::q('SELECT COALESCE(SUM(total),0) t FROM compra
                WHERE empresa_id = ? AND fecha BETWEEN ? AND ? AND estado != "ANULADA"',
                [$eid, $desde, $hasta])->fetch()['t'];
            $inv = DB::q('SELECT COALESCE(SUM(st.cantidad * p.precio_compra),0) v FROM stock st
                JOIN producto p ON p.id = st.producto_id WHERE p.empresa_id = ? AND p.activo = 1',
                [$eid])->fetch()['v'];
            $facturado = DB::q('SELECT COALESCE(SUM(f.monto_total),0) t FROM factura f
                JOIN siat_config sc ON sc.id = f.siat_config_id JOIN sucursal su ON su.id = sc.sucursal_id
                WHERE su.empresa_id = ? AND f.fecha_emision BETWEEN ? AND ? AND f.estado = "VALIDA"',
                [$eid, $desde, $hasta])->fetch()['t'];
            $facRecibidas = DB::q('SELECT COALESCE(SUM(monto_total),0) t FROM factura_recibida
                WHERE empresa_id = ? AND (fecha_emision BETWEEN ? AND ? OR fecha_emision IS NULL)',
                [$eid, $desde, $hasta])->fetch()['t'];
            $margen = (float)$ventas['total'] - (float)$ventas['cmv'];
            Response::ok([
                'kpis' => [
                    'ventas' => round((float)$ventas['total'], 2),
                    'num_ventas' => (int)$ventas['n'],
                    'ticket_promedio' => $ventas['n'] ? round($ventas['total'] / $ventas['n'], 2) : 0,
                    'cmv' => round((float)$ventas['cmv'], 2),                       // costo de mercaderia vendida
                    'margen_bruto' => round($margen, 2),
                    'margen_pct' => $ventas['total'] > 0 ? round($margen / $ventas['total'] * 100, 1) : 0,
                    'compras' => round((float)$compras, 2),
                    'valor_inventario' => round((float)$inv, 2),                    // activo realizable
                    'facturado_siat' => round((float)$facturado, 2),
                    'iva_debito_estimado' => round($facturado * 0.13, 2),           // estimacion 13%
                    'iva_credito_estimado' => round($facRecibidas * 0.13, 2),       // estimacion 13%
                    'cuentas_por_cobrar' => (float)DB::q('SELECT COALESCE(SUM(total),0) t FROM venta
                        WHERE empresa_id = ? AND pagada = 0 AND estado = "COMPLETADA"', [$eid])->fetch()['t'],
                    'cuentas_por_pagar' => (float)DB::q('SELECT COALESCE(SUM(total),0) t FROM compra
                        WHERE empresa_id = ? AND pagada = 0 AND estado != "ANULADA"', [$eid])->fetch()['t'],
                    'mora_por_cobrar' => (float)DB::q('SELECT COALESCE(SUM(total),0) t FROM venta
                        WHERE empresa_id = ? AND pagada = 0 AND estado = "COMPLETADA"
                        AND fecha < DATE_SUB(NOW(), INTERVAL 30 DAY)', [$eid])->fetch()['t'],
                    'mora_por_pagar' => (float)DB::q('SELECT COALESCE(SUM(total),0) t FROM compra
                        WHERE empresa_id = ? AND pagada = 0 AND estado != "ANULADA"
                        AND fecha < DATE_SUB(NOW(), INTERVAL 30 DAY)', [$eid])->fetch()['t'],
                ],
                'serie_ventas_compras' => DB::q('
                    SELECT d, SUM(v) ventas, SUM(c) compras FROM (
                      SELECT DATE(fecha) d, total v, 0 c FROM venta
                        WHERE empresa_id = ? AND fecha BETWEEN ? AND ? AND estado = "COMPLETADA"
                      UNION ALL
                      SELECT DATE(fecha) d, 0 v, total c FROM compra
                        WHERE empresa_id = ? AND fecha BETWEEN ? AND ? AND estado != "ANULADA"
                    ) x GROUP BY d ORDER BY d', [$eid, $desde, $hasta, $eid, $desde, $hasta])->fetchAll(),
                'top_margen' => DB::q('SELECT p.nombre,
                      ROUND(SUM(vd.cantidad * (vd.precio_unit - p.precio_compra)), 2) margen
                    FROM venta_detalle vd JOIN venta v ON v.id = vd.venta_id
                    JOIN producto p ON p.id = vd.producto_id
                    WHERE v.empresa_id = ? AND v.fecha BETWEEN ? AND ? AND v.estado = "COMPLETADA"
                    GROUP BY p.id ORDER BY margen DESC LIMIT 5', [$eid, $desde, $hasta])->fetchAll(),
            ]);


        // ================= CUENTAS POR COBRAR / PAGAR =================
        // Criterio: toda venta (factura o invoice) nace como cuenta por
        // cobrar; toda compra de stock nace como cuenta por pagar. Mora:
        // > 30 dias desde la emision sin marcar como pagada.
        case $ruta === 'pagos' && $metodo === 'GET':
            $u = Auth::requireUser(); Permisos::exigir($u, 'pagos.ver');
            $eid = $u['empresa_id'];
            $cxc = DB::q('SELECT v.id, v.fecha, v.total, v.origen, v.pagada, v.fecha_pago,
                  COALESCE(c.razon_social, "S/N") tercero,
                  DATEDIFF(NOW(), v.fecha) dias,
                  (DATEDIFF(NOW(), v.fecha) > 30 AND v.pagada = 0) mora,
                  (SELECT f.numero_factura FROM factura f WHERE f.venta_id = v.id
                     AND f.estado != "ANULADA" LIMIT 1) nro_factura
                FROM venta v LEFT JOIN cliente c ON c.id = v.cliente_id
                WHERE v.empresa_id = ? AND v.estado = "COMPLETADA"
                ORDER BY v.pagada, v.fecha DESC LIMIT 300', [$eid])->fetchAll();
            $cxp = DB::q('SELECT co.id, co.fecha, co.total, co.pagada, co.fecha_pago,
                  COALESCE(pr.razon_social, "S/N") tercero, co.nro_documento,
                  DATEDIFF(NOW(), co.fecha) dias,
                  (DATEDIFF(NOW(), co.fecha) > 30 AND co.pagada = 0) mora
                FROM compra co LEFT JOIN proveedor pr ON pr.id = co.proveedor_id
                WHERE co.empresa_id = ? AND co.estado != "ANULADA"
                ORDER BY co.pagada, co.fecha DESC LIMIT 300', [$eid])->fetchAll();
            $tot = fn($rows, $cond) => round(array_sum(array_map(
                fn($r) => $cond($r) ? (float)$r['total'] : 0, $rows)), 2);
            Response::ok(['cxc' => $cxc, 'cxp' => $cxp, 'kpis' => [
                'por_cobrar' => $tot($cxc, fn($r) => !$r['pagada']),
                'por_pagar' => $tot($cxp, fn($r) => !$r['pagada']),
                'mora_cobrar' => $tot($cxc, fn($r) => $r['mora']),
                'mora_pagar' => $tot($cxp, fn($r) => $r['mora']),
            ]]);

        case preg_match('#^(ventas|compras)/(\d+)/pago$#', $ruta, $m) && $metodo === 'PUT':
            $u = Auth::requireUser(); Permisos::exigir($u, 'pagos.gestionar');
            $tabla = $m[1] === 'ventas' ? 'venta' : 'compra';
            $pagada = (int)($in['pagada'] ?? 1);
            DB::q("UPDATE $tabla SET pagada = ?, fecha_pago = " . ($pagada ? 'NOW()' : 'NULL') . "
                   WHERE id = ? AND empresa_id = ?", [$pagada, (int)$m[2], $u['empresa_id']]);
            Response::ok(true);

        // ==================== INFORMES (con export CSV) ====================
        case preg_match('#^informes/(ventas|compras|inventario|kardex|facturas|margen|cxc|cxp)$#', $ruta, $m) && $metodo === 'GET':
            $u = Auth::requireUser(); Permisos::exigir($u, 'informes.ver');
            $desde = $_GET['desde'] ?? date('Y-m-01');
            $hasta = ($_GET['hasta'] ?? date('Y-m-d')) . ' 23:59:59';
            $eid = $u['empresa_id'];
            switch ($m[1]) {
                case 'ventas':
                    $rows = DB::q('SELECT v.id venta, v.fecha, COALESCE(c.razon_social, "S/N") cliente,
                          p.codigo_interno codigo, p.nombre producto, vd.cantidad, vd.precio_unit,
                          ROUND(vd.cantidad * vd.precio_unit - vd.descuento, 2) total,
                          v.origen, v.estado,
                          (SELECT COUNT(*) FROM factura f WHERE f.venta_id = v.id AND f.estado != "ANULADA") facturada
                        FROM venta_detalle vd JOIN venta v ON v.id = vd.venta_id
                        JOIN producto p ON p.id = vd.producto_id
                        LEFT JOIN cliente c ON c.id = v.cliente_id
                        WHERE v.empresa_id = ? AND v.fecha BETWEEN ? AND ?
                        ORDER BY v.fecha DESC', [$eid, $desde, $hasta])->fetchAll();
                    break;
                case 'margen':
                    // Ventas con margen obtenido: (precio venta - costo del producto) x cantidad
                    $rows = DB::q('SELECT v.fecha, p.codigo_interno codigo, p.nombre producto,
                          vd.cantidad, vd.precio_unit, p.precio_compra costo_unit,
                          ROUND(vd.cantidad * vd.precio_unit, 2) venta_total,
                          ROUND(vd.cantidad * p.precio_compra, 2) costo_total,
                          ROUND(vd.cantidad * (vd.precio_unit - p.precio_compra), 2) margen,
                          IF(vd.precio_unit > 0,
                             ROUND((vd.precio_unit - p.precio_compra) / vd.precio_unit * 100, 1), 0) margen_pct
                        FROM venta_detalle vd JOIN venta v ON v.id = vd.venta_id
                        JOIN producto p ON p.id = vd.producto_id
                        WHERE v.empresa_id = ? AND v.fecha BETWEEN ? AND ? AND v.estado = "COMPLETADA"
                        ORDER BY margen DESC', [$eid, $desde, $hasta])->fetchAll();
                    break;
                case 'compras':
                    $rows = DB::q('SELECT co.id compra, co.fecha, COALESCE(pr.razon_social, "S/N") proveedor,
                          co.nro_documento, p.codigo_interno codigo, p.nombre producto,
                          cd.cantidad, cd.costo_unit,
                          ROUND(cd.cantidad * cd.costo_unit, 2) total, co.estado
                        FROM compra_detalle cd JOIN compra co ON co.id = cd.compra_id
                        JOIN producto p ON p.id = cd.producto_id
                        LEFT JOIN proveedor pr ON pr.id = co.proveedor_id
                        WHERE co.empresa_id = ? AND co.fecha BETWEEN ? AND ?
                        ORDER BY co.fecha DESC', [$eid, $desde, $hasta])->fetchAll();
                    break;
                case 'inventario':
                    $rows = DB::q('SELECT p.codigo_interno, p.codigo_barras, p.nombre,
                          COALESCE(SUM(s.cantidad),0) stock, p.stock_minimo,
                          p.precio_compra, p.precio_venta,
                          ROUND(COALESCE(SUM(s.cantidad),0) * p.precio_compra, 2) valor_costo,
                          ROUND(COALESCE(SUM(s.cantidad),0) * p.precio_venta, 2) valor_venta
                        FROM producto p LEFT JOIN stock s ON s.producto_id = p.id
                        WHERE p.empresa_id = ? AND p.activo = 1
                        GROUP BY p.id ORDER BY p.nombre', [$eid])->fetchAll();
                    break;
                case 'kardex':
                    $extra = isset($_GET['producto_id']) ? ' AND m.producto_id = ' . (int)$_GET['producto_id'] : '';
                    $rows = DB::q('SELECT m.fecha, p.codigo_interno codigo, p.nombre producto, m.tipo,
                          a.nombre almacen, m.cantidad, m.costo_unit, m.saldo_resultante,
                          m.referencia_tipo, COALESCE(us.nombre, "-") usuario
                        FROM movimiento m JOIN producto p ON p.id = m.producto_id
                        LEFT JOIN almacen a ON a.id = m.almacen_id
                        LEFT JOIN usuario us ON us.id = m.usuario_id
                        WHERE m.empresa_id = ? AND m.fecha BETWEEN ? AND ?' . $extra . '
                        ORDER BY m.fecha DESC LIMIT 5000', [$eid, $desde, $hasta])->fetchAll();
                    break;
                case 'cxc':
                    $rows = DB::q('SELECT v.id venta, v.fecha, COALESCE(c.razon_social,"S/N") cliente,
                          v.total, v.origen, IF(v.pagada, "PAGADA", "PENDIENTE") estado_pago,
                          v.fecha_pago, DATEDIFF(COALESCE(v.fecha_pago, NOW()), v.fecha) dias,
                          IF(DATEDIFF(NOW(), v.fecha) > 30 AND v.pagada = 0, "SI", "NO") mora
                        FROM venta v LEFT JOIN cliente c ON c.id = v.cliente_id
                        WHERE v.empresa_id = ? AND v.fecha BETWEEN ? AND ? AND v.estado = "COMPLETADA"
                        ORDER BY v.pagada, v.fecha', [$eid, $desde, $hasta])->fetchAll();
                    break;
                case 'cxp':
                    $rows = DB::q('SELECT co.id compra, co.fecha, COALESCE(pr.razon_social,"S/N") proveedor,
                          co.nro_documento, co.total, IF(co.pagada, "PAGADA", "PENDIENTE") estado_pago,
                          co.fecha_pago, DATEDIFF(COALESCE(co.fecha_pago, NOW()), co.fecha) dias,
                          IF(DATEDIFF(NOW(), co.fecha) > 30 AND co.pagada = 0, "SI", "NO") mora
                        FROM compra co LEFT JOIN proveedor pr ON pr.id = co.proveedor_id
                        WHERE co.empresa_id = ? AND co.fecha BETWEEN ? AND ? AND co.estado != "ANULADA"
                        ORDER BY co.pagada, co.fecha', [$eid, $desde, $hasta])->fetchAll();
                    break;
                case 'facturas':
                    $rows = DB::q('SELECT f.numero_factura, f.cuf, f.fecha_emision, f.tipo_emision,
                          f.monto_total, f.estado, f.error_descripcion
                        FROM factura f JOIN siat_config sc ON sc.id = f.siat_config_id
                        JOIN sucursal su ON su.id = sc.sucursal_id
                        WHERE su.empresa_id = ? AND f.fecha_emision BETWEEN ? AND ?
                        ORDER BY f.fecha_emision DESC', [$eid, $desde, $hasta])->fetchAll();
                    break;
            }
            // Export CSV (Excel-friendly: BOM UTF-8 + separador ;)
            if (($_GET['formato'] ?? '') === 'csv') {
                Permisos::exigir($u, 'informes.exportar');
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="informe_' . $m[1] . '_' . date('Ymd') . '.csv"');
                echo "\xEF\xBB\xBF";
                $out = fopen('php://output', 'w');
                if ($rows) {
                    fputcsv($out, array_keys($rows[0]), ';');
                    foreach ($rows as $r) fputcsv($out, array_map(fn($v) => $v ?? '', array_values($r)), ';');
                }
                fclose($out);
                exit;
            }
            $totales = [];
            foreach (['total','monto_total','valor_costo','valor_venta','stock','margen','venta_total','costo_total'] as $col) {
                if ($rows && array_key_exists($col, $rows[0])) {
                    $totales[$col] = round(array_sum(array_column($rows, $col)), 2);
                }
            }
            Response::ok(['filas' => $rows, 'totales' => $totales, 'desde' => $desde, 'hasta' => substr($hasta, 0, 10)]);

        // ---------------- PRODUCTOS: carga masiva CSV ----------------
        // POST /productos/import  (multipart 'archivo' o JSON {csv: "..."})
        // Columnas: codigo_interno;codigo_barras;nombre;precio_compra;precio_venta;
        //           stock_inicial;stock_minimo;codigo_actividad_sin;codigo_producto_sin
        case $ruta === 'productos/import' && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'productos.importar');
            $csv = $in['csv'] ?? (isset($_FILES['archivo']) ? file_get_contents($_FILES['archivo']['tmp_name']) : '');
            if (!$csv) Response::error('Enviar archivo CSV o campo csv');
            $almacenId = (int)($in['almacen_id'] ?? $_POST['almacen_id'] ?? 0);
            $lineas = array_filter(array_map('trim', explode("\n", $csv)));
            $cab = str_getcsv(strtolower(array_shift($lineas)), ';');
            $n = 0; $errores = [];
            foreach ($lineas as $i => $l) {
                $r = @array_combine($cab, str_getcsv($l, ';'));
                if (!$r || empty($r['codigo_interno']) || empty($r['nombre'])) {
                    $errores[] = 'Linea ' . ($i + 2) . ': datos incompletos'; continue;
                }
                DB::q('INSERT INTO producto (empresa_id, codigo_interno, codigo_barras, nombre,
                         precio_compra, precio_venta, stock_minimo, codigo_actividad_sin, codigo_producto_sin)
                       VALUES (?,?,?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), codigo_barras = VALUES(codigo_barras),
                         precio_compra = VALUES(precio_compra), precio_venta = VALUES(precio_venta),
                         stock_minimo = VALUES(stock_minimo)',
                      [$u['empresa_id'], $r['codigo_interno'], $r['codigo_barras'] ?? null, $r['nombre'],
                       (float)($r['precio_compra'] ?? 0), (float)($r['precio_venta'] ?? 0),
                       (float)($r['stock_minimo'] ?? 0), $r['codigo_actividad_sin'] ?? null,
                       $r['codigo_producto_sin'] ?? null]);
                $pid = (int)DB::q('SELECT id FROM producto WHERE empresa_id = ? AND codigo_interno = ?',
                                  [$u['empresa_id'], $r['codigo_interno']])->fetch()['id'];
                if ($almacenId && (float)($r['stock_inicial'] ?? 0) > 0) {
                    Inventario::movimiento($u['empresa_id'], $pid, $almacenId, 'AJUSTE+',
                        (float)$r['stock_inicial'], (float)($r['precio_compra'] ?? 0),
                        'import_masivo', null, $u['id']);
                }
                $n++;
            }
            Response::ok(['importados' => $n, 'errores' => $errores]);

        // ------------------- COMPRAS (con escaneo) -------------------
        case $ruta === 'compras' && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'compras.crear');
            $compraId = DB::tx(function () use ($u, $in) {
                $total = 0;
                foreach ($in['items'] as $it) $total += $it['cantidad'] * $it['costo_unit'];
                DB::q('INSERT INTO compra (empresa_id, sucursal_id, proveedor_id, almacen_id,
                         nro_documento, total, usuario_id)
                       VALUES (?,?,?,?,?,?,?)',
                      [$u['empresa_id'], $in['sucursal_id'], $in['proveedor_id'] ?? null,
                       $in['almacen_id'], $in['nro_documento'] ?? null, $total, $u['id']]);
                $cid = DB::insertId();
                foreach ($in['items'] as $it) {
                    DB::q('INSERT INTO compra_detalle (compra_id, producto_id, cantidad, costo_unit)
                           VALUES (?,?,?,?)', [$cid, $it['producto_id'], $it['cantidad'], $it['costo_unit']]);
                }
                return $cid;
            });
            foreach ($in['items'] as $it) {
                Inventario::movimiento($u['empresa_id'], (int)$it['producto_id'], (int)$in['almacen_id'],
                    'COMPRA', (float)$it['cantidad'], (float)$it['costo_unit'], 'compra', $compraId, $u['id']);
            }
            // Registrar la factura del proveedor si vino el QR escaneado
            if (!empty($in['qr_factura'])) {
                LectorFacturas::registrarDesdeQr($u['empresa_id'], $in['qr_factura'], $compraId);
            }
            Response::ok(['id' => $compraId]);

        case $ruta === 'compras' && $metodo === 'GET':
            $u = Auth::requireUser(); Permisos::exigir($u, 'compras.ver');
            Response::ok(DB::q('SELECT c.*, p.razon_social proveedor FROM compra c
                LEFT JOIN proveedor p ON p.id = c.proveedor_id
                WHERE c.empresa_id = ? ORDER BY c.id DESC LIMIT 100', [$u['empresa_id']])->fetchAll());

        // ---------------- CONCILIACION CONTRA EL SIN ----------------
        case $ruta === 'conciliacion/import' && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'conciliacion.ejecutar');
            if (isset($_FILES['archivo'])) {
                Response::ok(Conciliacion::importarVentasCsv($u['empresa_id'], $_FILES['archivo']['tmp_name']));
            }
            if (!empty($in['csv'])) {
                $tmp = tempnam(sys_get_temp_dir(), 'sin');
                file_put_contents($tmp, $in['csv']);
                $r = Conciliacion::importarVentasCsv($u['empresa_id'], $tmp);
                unlink($tmp);
                Response::ok($r);
            }
            Response::error('Enviar archivo CSV del registro de ventas del SIN');

        case $ruta === 'conciliacion/ejecutar' && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'conciliacion.ejecutar');
            Response::ok(Conciliacion::conciliar($u['empresa_id'], $in['almacen_id'] ?? null));

        case $ruta === 'conciliacion/asignar' && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'conciliacion.ejecutar');
            Response::ok(Conciliacion::asignarItems((int)$in['venta_externa_id'], $in['items'], (int)$in['almacen_id']));

        case $ruta === 'conciliacion/cuadre' && $metodo === 'GET':
            $u = Auth::requireUser(); Permisos::exigir($u, 'conciliacion.ver');
            Response::ok(Conciliacion::resumenCuadre($u['empresa_id']));

        // ------------------- USUARIOS Y PERMISOS -------------------
        case $ruta === 'usuarios' && $metodo === 'GET':
            $u = Auth::requireUser(); Permisos::exigir($u, 'usuarios.gestionar');
            $rows = DB::q('SELECT id, nombre, email, rol, activo, ultimo_login FROM usuario
                           WHERE empresa_id = ?', [$u['empresa_id']])->fetchAll();
            foreach ($rows as &$r) $r['permisos'] = Permisos::de($r + ['empresa_id' => $u['empresa_id']]);
            Response::ok(['usuarios' => $rows,
                          'catalogo_permisos' => DB::q('SELECT * FROM permiso ORDER BY modulo, clave')->fetchAll(),
                          'roles' => array_keys(Permisos::POR_ROL)]);

        case $ruta === 'usuarios' && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'usuarios.gestionar');
            DB::q('INSERT INTO usuario (empresa_id, sucursal_id, nombre, email, pass_hash, rol)
                   VALUES (?,?,?,?,?,?)',
                  [$u['empresa_id'], $in['sucursal_id'] ?? null, $in['nombre'], $in['email'],
                   password_hash($in['password'], PASSWORD_DEFAULT), $in['rol'] ?? 'cajero']);
            Response::ok(['id' => DB::insertId()]);

        case preg_match('#^usuarios/(\d+)/permisos$#', $ruta, $m) && $metodo === 'PUT':
            $u = Auth::requireUser(); Permisos::exigir($u, 'usuarios.gestionar');
            DB::q('DELETE FROM usuario_permiso WHERE usuario_id = ?', [(int)$m[1]]);
            foreach (($in['overrides'] ?? []) as $ov) {
                DB::q('INSERT INTO usuario_permiso (usuario_id, permiso_clave, concedido) VALUES (?,?,?)',
                      [(int)$m[1], $ov['permiso'], (int)$ov['concedido']]);
            }
            Response::ok(true);

        case $ruta === 'mi/permisos' && $metodo === 'GET':
            $u = Auth::requireUser();
            Response::ok(Permisos::de($u));

        // ---------------- API keys (gestion desde la app) ----------------
        case $ruta === 'apikeys' && $metodo === 'GET':
            $u = Auth::requireUser(); Permisos::exigir($u, 'api.gestionar');
            Response::ok(DB::q('SELECT id, nombre, llave, permisos, activa, ultimo_uso FROM api_key
                                WHERE empresa_id = ?', [$u['empresa_id']])->fetchAll());

        case $ruta === 'apikeys' && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'api.gestionar');
            $llave = bin2hex(random_bytes(32));
            DB::q('INSERT INTO api_key (empresa_id, nombre, llave, permisos) VALUES (?,?,?,?)',
                  [$u['empresa_id'], $in['nombre'], $llave,
                   json_encode($in['permisos'] ?? ['productos.leer', 'stock.leer'])]);
            Response::ok(['llave' => $llave]);

        // ---------------------- AUTENTICACION ----------------------
        case $ruta === 'auth/login' && $metodo === 'POST':
            $r = Auth::login($in['email'] ?? '', $in['password'] ?? '');
            $r ? Response::ok($r) : Response::error('Credenciales invalidas', 401);


        case $ruta === 'auth/logout' && $metodo === 'POST':
            $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (preg_match('/Bearer\s+([a-f0-9]{64})/i', $h, $mm)) {
                DB::q('DELETE FROM sesion WHERE token = ?', [$mm[1]]);
            }
            Response::ok(true);

        // -------- Restablecimiento de contrasena (disparado por el admin) --------
        case preg_match('#^usuarios/(\d+)/reset$#', $ruta, $m) && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'usuarios.gestionar');
            $obj = DB::q('SELECT * FROM usuario WHERE id = ? AND empresa_id = ?',
                         [(int)$m[1], $u['empresa_id']])->fetch()
                ?: Response::error('Usuario no encontrado', 404);
            $cfgApp = require dirname(__DIR__) . '/config/config.php';
            $tokenReset = bin2hex(random_bytes(32));
            DB::q('UPDATE usuario SET reset_token = ?, reset_expira = DATE_ADD(NOW(), INTERVAL ? HOUR) WHERE id = ?',
                  [$tokenReset, $cfgApp['mail']['reset_ttl_horas'], $obj['id']]);

            // URL absoluta hacia la app con el token
            $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $basePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
            $enlace = $esquema . '://' . $_SERVER['HTTP_HOST'] . $basePath . '/?reset=' . $tokenReset;

            $marca = DB::q('SELECT COALESCE(nombre_comercial, razon_social) n FROM empresa WHERE id = ?',
                           [$u['empresa_id']])->fetch()['n'];
            $asunto = "Restablece tu contrasena - $marca";
            $cuerpo = "Hola {$obj['nombre']},\n\n"
                    . "El administrador solicito restablecer tu contrasena en $marca.\n"
                    . "Ingresa al siguiente enlace para definir una nueva (valido por "
                    . $cfgApp['mail']['reset_ttl_horas'] . " horas):\n\n$enlace\n\n"
                    . "Si no lo solicitaste, ignora este mensaje.\n";
            $cab = 'From: ' . $cfgApp['mail']['from_nombre'] . ' <' . $cfgApp['mail']['from'] . ">\r\n"
                 . "Content-Type: text/plain; charset=UTF-8\r\n";
            $enviado = @mail($obj['email'], $asunto, $cuerpo, $cab);

            // Se devuelve el enlace siempre: si el hosting no tiene mail()
            // configurado, el admin puede copiarlo y enviarlo por otro canal.
            Response::ok(['correo_enviado' => (bool)$enviado, 'enlace' => $enlace,
                          'valido_hasta' => date('Y-m-d H:i', time() + $cfgApp['mail']['reset_ttl_horas'] * 3600)]);

        // El usuario define su nueva contrasena con el token del correo
        case $ruta === 'auth/reset' && $metodo === 'POST':
            $tokenIn = $in['token'] ?? ''; $pass = $in['password'] ?? '';
            if (strlen($pass) < 8) Response::error('La contrasena debe tener al menos 8 caracteres');
            $obj = DB::q('SELECT id FROM usuario WHERE reset_token = ? AND reset_expira > NOW() AND activo = 1',
                         [$tokenIn])->fetch()
                ?: Response::error('Enlace invalido o vencido. Pide al administrador uno nuevo.', 410);
            DB::q('UPDATE usuario SET pass_hash = ?, reset_token = NULL, reset_expira = NULL WHERE id = ?',
                  [password_hash($pass, PASSWORD_DEFAULT), $obj['id']]);
            DB::q('DELETE FROM sesion WHERE usuario_id = ?', [$obj['id']]);  // cerrar sesiones viejas
            Response::ok(true);

        // ------------------------ PRODUCTOS ------------------------
        case $ruta === 'productos' && $metodo === 'GET':
            $u = Auth::requireUser();
            $q = $_GET['q'] ?? '';
            $rows = DB::q(
                'SELECT p.*, COALESCE(SUM(s.cantidad),0) stock_total, c.nombre categoria
                 FROM producto p LEFT JOIN stock s ON s.producto_id = p.id
                 LEFT JOIN categoria c ON c.id = p.categoria_id
                 WHERE p.empresa_id = ? ' . (isset($_GET['todos']) ? '' : 'AND p.activo = 1 ') . '
                 AND (p.nombre LIKE ? OR p.codigo_interno LIKE ? OR p.codigo_barras = ?)
                 GROUP BY p.id ORDER BY p.nombre LIMIT 100',
                [$u['empresa_id'], "%$q%", "%$q%", $q])->fetchAll();
            Response::ok($rows);

        case $ruta === 'productos' && $metodo === 'POST':
            $u = Auth::requireUser(['admin', 'gerente', 'almacen']);
            DB::q('INSERT INTO producto (empresa_id, categoria_id, codigo_interno, codigo_barras, nombre,
                     descripcion, unidad_medida_sin, precio_compra, precio_venta, stock_minimo,
                     codigo_actividad_sin, codigo_producto_sin)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                  [$u['empresa_id'], $in['categoria_id'] ?? null, $in['codigo_interno'],
                   $in['codigo_barras'] ?? null, $in['nombre'], $in['descripcion'] ?? null,
                   $in['unidad_medida_sin'] ?? 58, $in['precio_compra'] ?? 0, $in['precio_venta'],
                   $in['stock_minimo'] ?? 0, $in['codigo_actividad_sin'] ?? null,
                   $in['codigo_producto_sin'] ?? null]);
            $nuevoId = DB::insertId();
            Webhooks::disparar($u['empresa_id'], 'producto.actualizado', ['id' => $nuevoId] + $in);
            Response::ok(['id' => $nuevoId]);


        case preg_match('#^productos/(\d+)$#', $ruta, $m) && $metodo === 'PUT':
            $u = Auth::requireUser(); Permisos::exigir($u, 'productos.crear');
            DB::q('UPDATE producto SET codigo_interno=?, codigo_barras=?, nombre=?, descripcion=?,
                     categoria_id=?, precio_compra=?, precio_venta=?, stock_minimo=?,
                     codigo_actividad_sin=?, codigo_producto_sin=?
                   WHERE id=? AND empresa_id=?',
                  [$in['codigo_interno'], $in['codigo_barras'] ?? null, $in['nombre'],
                   $in['descripcion'] ?? null, $in['categoria_id'] ?? null,
                   $in['precio_compra'] ?? 0, $in['precio_venta'],
                   $in['stock_minimo'] ?? 0, $in['codigo_actividad_sin'] ?? null,
                   $in['codigo_producto_sin'] ?? null, (int)$m[1], $u['empresa_id']]);
            Webhooks::disparar($u['empresa_id'], 'producto.actualizado', ['id' => (int)$m[1]] + $in);
            Response::ok(true);

        // Soft delete / reactivar (nunca se borra: el kardex lo referencia)
        case preg_match('#^productos/(\d+)/estado$#', $ruta, $m) && $metodo === 'PUT':
            $u = Auth::requireUser(); Permisos::exigir($u, 'productos.crear');
            DB::q('UPDATE producto SET activo = ? WHERE id = ? AND empresa_id = ?',
                  [(int)$in['activo'], (int)$m[1], $u['empresa_id']]);
            Response::ok(true);

        // ------------------------ PROVEEDORES ------------------------
        case $ruta === 'proveedores' && $metodo === 'GET':
            $u = Auth::requireUser();
            Response::ok(DB::q('SELECT * FROM proveedor WHERE empresa_id = ? ORDER BY razon_social',
                [$u['empresa_id']])->fetchAll());

        case $ruta === 'proveedores' && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'compras.crear');
            DB::q('INSERT INTO proveedor (empresa_id, nit, razon_social, telefono, email) VALUES (?,?,?,?,?)',
                  [$u['empresa_id'], $in['nit'] ?? null, $in['razon_social'],
                   $in['telefono'] ?? null, $in['email'] ?? null]);
            Response::ok(['id' => DB::insertId()]);

        // Detalle de compra (para pantalla y comprobante)
        case preg_match('#^compras/(\d+)$#', $ruta, $m) && $metodo === 'GET':
            $u = Auth::requireUser(); Permisos::exigir($u, 'compras.ver');
            $c = DB::q('SELECT c.*, pr.razon_social proveedor, pr.nit nit_proveedor, s.nombre sucursal,
                          us.nombre usuario, a.nombre almacen
                        FROM compra c LEFT JOIN proveedor pr ON pr.id = c.proveedor_id
                        JOIN sucursal s ON s.id = c.sucursal_id
                        LEFT JOIN usuario us ON us.id = c.usuario_id
                        LEFT JOIN almacen a ON a.id = c.almacen_id
                        WHERE c.id = ? AND c.empresa_id = ?', [(int)$m[1], $u['empresa_id']])->fetch()
                ?: Response::error('Compra no encontrada', 404);
            $c['items'] = DB::q('SELECT cd.*, p.nombre, p.codigo_interno FROM compra_detalle cd
                JOIN producto p ON p.id = cd.producto_id WHERE cd.compra_id = ?', [(int)$m[1]])->fetchAll();
            $c['factura_proveedor'] = DB::q('SELECT * FROM factura_recibida WHERE compra_id = ? LIMIT 1',
                [(int)$m[1]])->fetch() ?: null;
            Response::ok($c);

        // Detalle de venta (para comprobante / invoice)
        case preg_match('#^ventas/(\d+)$#', $ruta, $m) && $metodo === 'GET':
            $u = Auth::requireUser();
            $v = DB::q('SELECT v.*, c.razon_social cliente, c.num_documento, c.tipo_doc_sin,
                          s.nombre sucursal, us.nombre usuario
                        FROM venta v LEFT JOIN cliente c ON c.id = v.cliente_id
                        JOIN sucursal s ON s.id = v.sucursal_id
                        LEFT JOIN usuario us ON us.id = v.usuario_id
                        WHERE v.id = ? AND v.empresa_id = ?', [(int)$m[1], $u['empresa_id']])->fetch()
                ?: Response::error('Venta no encontrada', 404);
            $v['items'] = DB::q('SELECT vd.*, p.nombre, p.codigo_interno, p.descripcion FROM venta_detalle vd
                JOIN producto p ON p.id = vd.producto_id WHERE vd.venta_id = ?', [(int)$m[1]])->fetchAll();
            $v['factura'] = DB::q('SELECT id, numero_factura, cuf, estado, fecha_emision FROM factura
                WHERE venta_id = ? AND estado != "ANULADA" LIMIT 1', [(int)$m[1]])->fetch() ?: null;
            Response::ok($v);

        case $ruta === 'ventas' && $metodo === 'GET':
            $u = Auth::requireUser();
            Response::ok(DB::q('SELECT v.id, v.fecha, v.total, v.estado, v.origen,
                COALESCE(c.razon_social,"S/N") cliente,
                (SELECT COUNT(*) FROM factura f WHERE f.venta_id = v.id AND f.estado != "ANULADA") facturada
                FROM venta v LEFT JOIN cliente c ON c.id = v.cliente_id
                WHERE v.empresa_id = ? ORDER BY v.id DESC LIMIT 100', [$u['empresa_id']])->fetchAll());


        // ------------------------ CATEGORIAS ------------------------
        case $ruta === 'categorias' && $metodo === 'GET':
            $u = Auth::requireUser();
            Response::ok(DB::q('SELECT c.*, (SELECT COUNT(*) FROM producto p WHERE p.categoria_id = c.id) productos
                FROM categoria c WHERE c.empresa_id = ? ORDER BY c.nombre', [$u['empresa_id']])->fetchAll());

        case $ruta === 'categorias' && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'productos.crear');
            DB::q('INSERT INTO categoria (empresa_id, nombre) VALUES (?,?)', [$u['empresa_id'], $in['nombre']]);
            Response::ok(['id' => DB::insertId()]);

        case preg_match('#^categorias/(\d+)$#', $ruta, $m) && $metodo === 'PUT':
            $u = Auth::requireUser(); Permisos::exigir($u, 'productos.crear');
            DB::q('UPDATE categoria SET nombre = ? WHERE id = ? AND empresa_id = ?',
                  [$in['nombre'], (int)$m[1], $u['empresa_id']]);
            Response::ok(true);

        // Foto del producto (base64, viaja al POS)
        case preg_match('#^productos/(\d+)/foto$#', $ruta, $m) && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'productos.crear');
            if (!preg_match('#^data:image/(png|jpe?g|webp);base64,(.+)$#', $in['imagen'] ?? '', $mm)) {
                Response::error('Formato no valido (png, jpg, webp)');
            }
            $bin = base64_decode($mm[2]);
            if (strlen($bin) > 3 * 1024 * 1024) Response::error('Foto maxima 3 MB');
            @mkdir(__DIR__ . '/uploads', 0775, true);
            $ext = $mm[1] === 'jpeg' ? 'jpg' : $mm[1];
            $nombre = 'uploads/prod_' . (int)$m[1] . '.' . $ext;
            file_put_contents(__DIR__ . '/' . $nombre, $bin);
            DB::q('UPDATE producto SET imagen_url = ? WHERE id = ? AND empresa_id = ?',
                  [$nombre, (int)$m[1], $u['empresa_id']]);
            Response::ok(['imagen_url' => $nombre]);

        // ------------------------ WEBHOOKS (gestion) ------------------------
        case $ruta === 'webhooks' && $metodo === 'GET':
            $u = Auth::requireUser(); Permisos::exigir($u, 'api.gestionar');
            Response::ok(DB::q('SELECT id, evento, url, activo FROM webhook WHERE empresa_id = ?',
                [$u['empresa_id']])->fetchAll());

        case $ruta === 'webhooks' && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'api.gestionar');
            $secreto = $in['secreto'] ?? bin2hex(random_bytes(24));
            DB::q('INSERT INTO webhook (empresa_id, evento, url, secreto) VALUES (?,?,?,?)',
                  [$u['empresa_id'], $in['evento'], $in['url'], $secreto]);
            Response::ok(['id' => DB::insertId(), 'secreto' => $secreto]);

        // Busqueda por codigo de barras (lector fisico o camara)
        case preg_match('#^productos/barcode/(.+)$#', $ruta, $m) && $metodo === 'GET':
            $u = Auth::requireUser();
            $p = DB::q('SELECT p.*, COALESCE(SUM(s.cantidad),0) stock_total FROM producto p
                        LEFT JOIN stock s ON s.producto_id = p.id
                        WHERE p.empresa_id = ? AND (p.codigo_barras = ? OR p.codigo_interno = ?)
                        GROUP BY p.id', [$u['empresa_id'], $m[1], $m[1]])->fetch();
            $p ? Response::ok($p) : Response::error('Producto no encontrado', 404);

        // ------------------------ INVENTARIO ------------------------


        // ============ SUCURSALES (SIAT) Y ALMACENES ============
        // Nota SIAT: la Casa Matriz es codigo_sin = 0; las demas sucursales
        // usan el codigo que el SIN les asigno. Los almacenes son internos
        // (no existen para el SIN) y cuelgan de una sucursal.
        case $ruta === 'sucursales' && $metodo === 'GET':
            $u = Auth::requireUser();
            Response::ok(DB::q('SELECT s.*, 
                  (SELECT COUNT(*) FROM almacen a WHERE a.sucursal_id = s.id) almacenes,
                  (SELECT COUNT(*) FROM siat_config sc WHERE sc.sucursal_id = s.id) siat_configurada
                FROM sucursal s WHERE s.empresa_id = ? ORDER BY s.codigo_sin', [$u['empresa_id']])->fetchAll());

        case $ruta === 'sucursales' && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'almacenes.gestionar');
            DB::q('INSERT INTO sucursal (empresa_id, codigo_sin, nombre, direccion, telefono)
                   VALUES (?,?,?,?,?)',
                  [$u['empresa_id'], (int)($in['codigo_sin'] ?? 0), $in['nombre'],
                   $in['direccion'] ?? null, $in['telefono'] ?? null]);
            Response::ok(['id' => DB::insertId()]);

        case preg_match('#^sucursales/(\d+)$#', $ruta, $m) && $metodo === 'PUT':
            $u = Auth::requireUser(); Permisos::exigir($u, 'almacenes.gestionar');
            DB::q('UPDATE sucursal SET nombre = ?, codigo_sin = ?, direccion = ?, telefono = ?, activa = ?
                   WHERE id = ? AND empresa_id = ?',
                  [$in['nombre'], (int)($in['codigo_sin'] ?? 0), $in['direccion'] ?? null,
                   $in['telefono'] ?? null, (int)($in['activa'] ?? 1), (int)$m[1], $u['empresa_id']]);
            Response::ok(true);

        case $ruta === 'almacenes' && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'almacenes.gestionar');
            $suc = DB::q('SELECT id FROM sucursal WHERE id = ? AND empresa_id = ?',
                         [(int)$in['sucursal_id'], $u['empresa_id']])->fetch()
                ?: Response::error('Sucursal invalida');
            DB::q('INSERT INTO almacen (sucursal_id, nombre) VALUES (?,?)',
                  [(int)$in['sucursal_id'], $in['nombre']]);
            Response::ok(['id' => DB::insertId()]);

        case preg_match('#^almacenes/(\d+)$#', $ruta, $m) && $metodo === 'PUT':
            $u = Auth::requireUser(); Permisos::exigir($u, 'almacenes.gestionar');
            DB::q('UPDATE almacen a JOIN sucursal s ON s.id = a.sucursal_id
                   SET a.nombre = ?, a.sucursal_id = ?
                   WHERE a.id = ? AND s.empresa_id = ?',
                  [$in['nombre'], (int)$in['sucursal_id'], (int)$m[1], $u['empresa_id']]);
            Response::ok(true);

        case $ruta === 'almacenes' && $metodo === 'GET':
            $u = Auth::requireUser();
            Response::ok(DB::q('SELECT a.id, a.nombre, a.sucursal_id, s.nombre sucursal FROM almacen a
                JOIN sucursal s ON s.id = a.sucursal_id WHERE s.empresa_id = ? AND s.activa = 1',
                [$u['empresa_id']])->fetchAll());

        // Stock desglosado por almacen de un producto
        case preg_match('#^inventario/stock/(\d+)$#', $ruta, $m) && $metodo === 'GET':
            $u = Auth::requireUser(); Permisos::exigir($u, 'inventario.ver');
            Response::ok(DB::q('SELECT a.id almacen_id, a.nombre almacen, s2.nombre sucursal,
                  COALESCE(st.cantidad, 0) cantidad
                FROM almacen a JOIN sucursal s2 ON s2.id = a.sucursal_id
                LEFT JOIN stock st ON st.almacen_id = a.id AND st.producto_id = ?
                WHERE s2.empresa_id = ?', [(int)$m[1], $u['empresa_id']])->fetchAll());

        // Traspaso entre almacenes (salida origen + entrada destino, ambos al kardex)
        case $ruta === 'inventario/traspaso' && $metodo === 'POST':
            $u = Auth::requireUser(); Permisos::exigir($u, 'inventario.ajustar');
            if ((int)$in['origen_id'] === (int)$in['destino_id']) Response::error('Origen y destino iguales');
            Inventario::movimiento($u['empresa_id'], (int)$in['producto_id'], (int)$in['origen_id'],
                'TRASPASO_OUT', (float)$in['cantidad'], 0, 'traspaso', null, $u['id']);
            $saldo = Inventario::movimiento($u['empresa_id'], (int)$in['producto_id'], (int)$in['destino_id'],
                'TRASPASO_IN', (float)$in['cantidad'], 0, 'traspaso', null, $u['id']);
            Response::ok(['saldo_destino' => $saldo]);

        case $ruta === 'inventario/ajuste' && $metodo === 'POST':
            $u = Auth::requireUser(['admin', 'gerente', 'almacen']);
            $saldo = Inventario::movimiento($u['empresa_id'], (int)$in['producto_id'],
                (int)$in['almacen_id'], $in['cantidad'] > 0 ? 'AJUSTE+' : 'AJUSTE-',
                abs((float)$in['cantidad']), 0, 'ajuste', null, $u['id']);
            Response::ok(['saldo' => $saldo]);

        case $ruta === 'inventario/kardex' && $metodo === 'GET':
            $u = Auth::requireUser();
            Response::ok(DB::q('SELECT m.*, p.nombre producto, a.nombre almacen,
                  COALESCE(us.nombre, "-") usuario
                FROM movimiento m JOIN producto p ON p.id = m.producto_id
                LEFT JOIN almacen a ON a.id = m.almacen_id
                LEFT JOIN usuario us ON us.id = m.usuario_id
                WHERE m.empresa_id = ? ' . (isset($_GET['producto_id']) ? ' AND m.producto_id = ' . (int)$_GET['producto_id'] : '') . '
                ORDER BY m.id DESC LIMIT 200', [$u['empresa_id']])->fetchAll());

        // -------------------------- VENTAS --------------------------
        case $ruta === 'ventas' && $metodo === 'POST':
            $u = Auth::requireUser(['admin', 'gerente', 'cajero']);
            Response::ok(Inventario::crearVenta($u, $in));

        case $ruta === 'reportes/dashboard' && $metodo === 'GET':
            $u = Auth::requireUser();
            $eid = $u['empresa_id'];
            Response::ok([
                'ventas_hoy' => DB::q('SELECT COUNT(*) c, COALESCE(SUM(total),0) t FROM venta
                    WHERE empresa_id = ? AND DATE(fecha) = CURDATE() AND estado = "COMPLETADA"', [$eid])->fetch(),
                'ventas_mes' => DB::q('SELECT COALESCE(SUM(total),0) t FROM venta
                    WHERE empresa_id = ? AND fecha >= DATE_FORMAT(NOW(), "%Y-%m-01")
                    AND estado = "COMPLETADA"', [$eid])->fetch()['t'],
                'compras_mes' => DB::q('SELECT COALESCE(SUM(total),0) t FROM compra
                    WHERE empresa_id = ? AND fecha >= DATE_FORMAT(NOW(), "%Y-%m-01")
                    AND estado != "ANULADA"', [$eid])->fetch()['t'],
                'productos' => DB::q('SELECT COUNT(*) c FROM producto WHERE empresa_id = ? AND activo = 1',
                    [$eid])->fetch()['c'],
                'unidades_stock' => DB::q('SELECT COALESCE(SUM(s.cantidad),0) t FROM stock s
                    JOIN producto p ON p.id = s.producto_id WHERE p.empresa_id = ? AND p.activo = 1',
                    [$eid])->fetch()['t'],
                'valor_inventario' => DB::q('SELECT COALESCE(SUM(s.cantidad * p.precio_compra),0) costo,
                      COALESCE(SUM(s.cantidad * p.precio_venta),0) venta
                    FROM stock s JOIN producto p ON p.id = s.producto_id
                    WHERE p.empresa_id = ? AND p.activo = 1', [$eid])->fetch(),
                'stock_bajo' => DB::q('SELECT COUNT(DISTINCT p.id) c FROM producto p
                    JOIN stock s ON s.producto_id = p.id
                    WHERE p.empresa_id = ? GROUP BY p.id HAVING SUM(s.cantidad) <= MAX(p.stock_minimo)',
                    [$eid])->rowCount(),
                'ventas_7dias' => DB::q('SELECT DATE(fecha) d, SUM(total) t FROM venta
                    WHERE empresa_id = ? AND fecha >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                    AND estado = "COMPLETADA" GROUP BY DATE(fecha) ORDER BY d', [$eid])->fetchAll(),
                'top_productos' => DB::q('SELECT p.nombre, SUM(vd.cantidad) c FROM venta_detalle vd
                    JOIN venta v ON v.id = vd.venta_id JOIN producto p ON p.id = vd.producto_id
                    WHERE v.empresa_id = ? AND v.fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                    AND v.estado = "COMPLETADA"
                    GROUP BY p.id ORDER BY c DESC LIMIT 5', [$eid])->fetchAll(),
                'rotacion_baja' => DB::q('SELECT p.nombre, COALESCE(SUM(s.cantidad),0) stock FROM producto p
                    LEFT JOIN stock s ON s.producto_id = p.id
                    WHERE p.empresa_id = ? AND p.activo = 1 AND p.id NOT IN (
                      SELECT DISTINCT vd.producto_id FROM venta_detalle vd JOIN venta v ON v.id = vd.venta_id
                      WHERE v.empresa_id = ? AND v.fecha >= DATE_SUB(CURDATE(), INTERVAL 30 DAY))
                    GROUP BY p.id HAVING stock > 0 ORDER BY stock DESC LIMIT 5', [$eid, $eid])->fetchAll(),
                'stock_almacenes' => DB::q('SELECT a.nombre, s2.nombre sucursal,
                      COALESCE(SUM(st.cantidad),0) unidades,
                      COALESCE(SUM(st.cantidad * p.precio_compra),0) valor
                    FROM almacen a JOIN sucursal s2 ON s2.id = a.sucursal_id
                    LEFT JOIN stock st ON st.almacen_id = a.id
                    LEFT JOIN producto p ON p.id = st.producto_id AND p.activo = 1
                    WHERE s2.empresa_id = ? GROUP BY a.id ORDER BY unidades DESC', [$eid])->fetchAll(),
                'facturas_pendientes' => DB::q('SELECT COUNT(*) c FROM factura WHERE estado IN ("PENDIENTE","RECHAZADA")')->fetch()['c'],
            ]);

        // ----------------------- FACTURACION SIAT -----------------------
        case $ruta === 'siat/emitir' && $metodo === 'POST':
            $u = Auth::requireUser(['admin', 'gerente', 'cajero']);
            $emp = DB::q('SELECT facturacion_activa FROM empresa WHERE id = ?', [$u['empresa_id']])->fetch();
            if (!$emp['facturacion_activa']) Response::error('Modulo de facturacion desactivado. Activarlo en Configuracion.', 409);
            $fac = new Facturador((int)$in['siat_config_id']);
            $f = $fac->emitirDesdeVenta((int)$in['venta_id']);
            if ($f['estado'] === 'VALIDA') Webhooks::disparar($u['empresa_id'], 'factura.valida', $f);
            Response::ok($f);

        case $ruta === 'siat/anular' && $metodo === 'POST':
            $u = Auth::requireUser(['admin', 'gerente']);
            $fac = new Facturador((int)$in['siat_config_id']);
            Response::ok($fac->anular((int)$in['factura_id'], (int)$in['motivo']));

        case $ruta === 'siat/verificar-nit' && $metodo === 'POST':
            Auth::requireUser();
            $cfg = DB::q('SELECT * FROM siat_config WHERE id = ?', [(int)$in['siat_config_id']])->fetch();
            $soap = new \Siat\SoapSin($cfg);
            Response::ok($soap->verificarNit($in['nit']));

        case $ruta === 'siat/panel-errores' && $metodo === 'GET':
            Auth::requireUser(['admin', 'gerente', 'auditor']);
            Response::ok([
                'rechazadas' => DB::q('SELECT id, numero_factura, cuf, error_descripcion, creado_en
                    FROM factura WHERE estado = "RECHAZADA" ORDER BY id DESC LIMIT 50')->fetchAll(),
                'pendientes' => DB::q('SELECT id, numero_factura, tipo_emision, creado_en,
                    TIMESTAMPDIFF(HOUR, creado_en, NOW()) horas FROM factura
                    WHERE estado IN ("PENDIENTE","EN_PROCESAMIENTO") ORDER BY id')->fetchAll(),
                'logs' => DB::q('SELECT * FROM siat_log ORDER BY id DESC LIMIT 100')->fetchAll(),
            ]);

        // Lectura de facturas recibidas (QR o importacion)
        case $ruta === 'siat/facturas-recibidas/qr' && $metodo === 'POST':
            $u = Auth::requireUser();
            Response::ok(LectorFacturas::registrarDesdeQr($u['empresa_id'], $in['qr_url'], $in['compra_id'] ?? null));

        case $ruta === 'siat/facturas-recibidas' && $metodo === 'GET':
            $u = Auth::requireUser();
            Response::ok(DB::q('SELECT * FROM factura_recibida WHERE empresa_id = ? ORDER BY id DESC LIMIT 100',
                [$u['empresa_id']])->fetchAll());

        // -------------------------- IMPRESION --------------------------
        case $ruta === 'imprimir/ticket' && $metodo === 'POST':
            $u = Auth::requireUser();
            $f = DB::q('SELECT * FROM factura WHERE id = ?', [(int)$in['factura_id']])->fetch();
            $emp = DB::q('SELECT * FROM empresa WHERE id = ?', [$u['empresa_id']])->fetch();
            $items = DB::q('SELECT vd.*, p.nombre FROM venta_detalle vd
                JOIN producto p ON p.id = vd.producto_id WHERE vd.venta_id = ?', [$f['venta_id']])->fetchAll();
            $cfgApp = require dirname(__DIR__) . '/config/config.php';
            $scfg = DB::q('SELECT * FROM siat_config WHERE id = ?', [$f['siat_config_id']])->fetch();
            $urlQr = \Siat\XmlFactura::urlQr($cfgApp['siat'][$scfg['ambiente']]['qr_base'],
                $scfg['nit_emisor'], $f['cuf'], (int)$f['numero_factura'], (float)$f['monto_total']);
            $imp = DB::q('SELECT * FROM impresora WHERE id = ?', [(int)$in['impresora_id']])->fetch();
            $ticket = EscPos::ticketFactura($emp, $f, $items, $urlQr, (int)($imp['ancho_mm'] ?? 80));
            if ($imp && $imp['tipo'] === 'TERMICA_RED') {
                $ok = $ticket->enviarRed($imp['ip'], (int)$imp['puerto']);
                Response::ok(['enviado_red' => $ok]);
            }
            // TERMICA_BT o sin impresora: devolver bytes para Web Bluetooth
            Response::ok(['escpos_base64' => $ticket->base64(), 'url_qr' => $urlQr]);

        // ============ API PUBLICA para ERP / CRM / POS / eCommerce ============

        case $ruta === 'v1/conciliacion/ventas' && $metodo === 'POST':
            // Otro aplicativo (POS/facturador externo) empuja sus ventas facturadas
            // con detalle de items para que el inventario se descuente solo.
            $k = Auth::apiKey('ventas.crear') ?: Response::error('API key invalida', 401);
            Response::ok(Conciliacion::registrarVentaApi((int)$k['empresa_id'], $in));

        case $ruta === 'v1/productos' && $metodo === 'GET':
            $k = Auth::apiKey('productos.leer') ?: Response::error('API key invalida', 401);
            Response::ok(DB::q('SELECT p.*, COALESCE(SUM(s.cantidad),0) stock_total FROM producto p
                LEFT JOIN stock s ON s.producto_id = p.id WHERE p.empresa_id = ? AND p.activo = 1
                GROUP BY p.id', [$k['empresa_id']])->fetchAll());

        case $ruta === 'v1/productos/sync' && $metodo === 'POST':
            // Upsert masivo desde ERP externo (Odoo, etc.) por externo_ref
            $k = Auth::apiKey('productos.escribir') ?: Response::error('API key invalida', 401);
            $n = 0;
            foreach (($in['productos'] ?? []) as $p) {
                DB::q('INSERT INTO producto (empresa_id, codigo_interno, codigo_barras, nombre,
                         precio_venta, precio_compra, externo_ref, unidad_medida_sin)
                       VALUES (?,?,?,?,?,?,?,?)
                       ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), codigo_barras = VALUES(codigo_barras),
                         precio_venta = VALUES(precio_venta), precio_compra = VALUES(precio_compra),
                         externo_ref = VALUES(externo_ref)',
                      [$k['empresa_id'], $p['codigo_interno'], $p['codigo_barras'] ?? null, $p['nombre'],
                       $p['precio_venta'] ?? 0, $p['precio_compra'] ?? 0, $p['externo_ref'] ?? null,
                       $p['unidad_medida_sin'] ?? 58]);
                $n++;
            }
            Response::ok(['sincronizados' => $n]);

        case $ruta === 'v1/stock' && $metodo === 'GET':
            $k = Auth::apiKey('stock.leer') ?: Response::error('API key invalida', 401);
            Response::ok(DB::q('SELECT p.codigo_interno, p.externo_ref, s.almacen_id, s.cantidad
                FROM stock s JOIN producto p ON p.id = s.producto_id
                WHERE p.empresa_id = ?', [$k['empresa_id']])->fetchAll());

        case $ruta === 'v1/ventas' && $metodo === 'POST':
            // Un ecommerce o POS externo registra una venta (descuenta stock y opcionalmente factura)
            $k = Auth::apiKey('ventas.crear') ?: Response::error('API key invalida', 401);
            $u = ['empresa_id' => $k['empresa_id'], 'id' => null];
            // Tipificacion de origen: CRM si la llave pertenece a SolarCRM,
            // API en otros casos; el integrador puede enviarlo explicito.
            $in['origen'] = $in['origen']
                ?? (stripos($k['nombre'], 'solarcrm') !== false ? 'CRM' : 'API');
            $venta = Inventario::crearVenta($u, $in);
            if (!empty($in['facturar']) && !empty($in['siat_config_id'])) {
                $fac = new Facturador((int)$in['siat_config_id']);
                $venta['factura'] = $fac->emitirDesdeVenta((int)$venta['id']);
            }
            Response::ok($venta);

        default:
            Response::error("Ruta no encontrada: $metodo /$ruta", 404);
    }
} catch (\RuntimeException $e) {
    Response::error($e->getMessage(), 422);
} catch (\Throwable $e) {
    Response::error('Error interno', 500, $e->getMessage());
}

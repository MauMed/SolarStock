<?php
namespace Siat;

use Core\DB;

/**
 * LECTURA de facturas recibidas de proveedores.
 * Modo principal: escaneo del QR reglamentario (nit, cuf, numero, monto)
 * con la camara o lector fisico; se registra y opcionalmente se enlaza
 * a una compra para el registro de credito fiscal.
 */
class LectorFacturas
{
    /** Parsea la URL del QR de una factura boliviana */
    public static function parsearQr(string $url): ?array
    {
        $q = parse_url($url, PHP_URL_QUERY);
        if (!$q) return null;
        parse_str($q, $p);
        if (empty($p['nit']) || empty($p['cuf'])) return null;
        return [
            'nit' => $p['nit'],
            'cuf' => $p['cuf'],
            'numero' => (int)($p['numero'] ?? 0),
            'monto' => (float)($p['t'] ?? $p['monto'] ?? 0),
        ];
    }

    public static function registrarDesdeQr(int $empresaId, string $qrUrl, ?int $compraId = null): array
    {
        $d = self::parsearQr($qrUrl) ?: throw new \RuntimeException('QR no reconocido como factura SIAT');
        DB::q('INSERT IGNORE INTO factura_recibida
                 (empresa_id, nit_proveedor, numero_factura, cuf, monto_total, origen, compra_id, datos_raw)
               VALUES (?,?,?,?,?,?,?,?)',
              [$empresaId, $d['nit'], $d['numero'], $d['cuf'], $d['monto'], 'QR_SCAN', $compraId,
               json_encode(['url' => $qrUrl])]);
        return $d;
    }

    /**
     * Importacion masiva desde el registro de compras del SIN
     * (archivo CSV/TXT exportado de la oficina virtual, o via WS si el
     * contribuyente tiene el servicio habilitado).
     */
    public static function importarCsv(int $empresaId, string $rutaCsv): int
    {
        $fh = fopen($rutaCsv, 'r');
        $n = 0; $cab = null;
        while (($fila = fgetcsv($fh, 0, ';')) !== false) {
            if (!$cab) { $cab = array_map('strtolower', $fila); continue; }
            $r = array_combine($cab, $fila);
            DB::q('INSERT IGNORE INTO factura_recibida
                     (empresa_id, nit_proveedor, razon_social, numero_factura, cuf,
                      fecha_emision, monto_total, codigo_control, origen)
                   VALUES (?,?,?,?,?,?,?,?,?)',
                  [$empresaId, $r['nit proveedor'] ?? $r['nit'] ?? '',
                   $r['razon social'] ?? '', $r['nro. factura'] ?? $r['numero'] ?? 0,
                   $r['codigo de autorizacion'] ?? $r['cuf'] ?? '',
                   $r['fecha de la factura'] ?? null,
                   str_replace(',', '.', $r['importe total compra'] ?? $r['monto'] ?? 0),
                   $r['codigo de control'] ?? null, 'IMPORT_SIAT']);
            $n++;
        }
        fclose($fh);
        return $n;
    }
}

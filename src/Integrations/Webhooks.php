<?php
namespace Integrations;

use Core\DB;

/**
 * Notificaciones salientes hacia ERP/CRM/ecommerce.
 * Eventos: stock.bajo, venta.creada, factura.valida, factura.rechazada,
 * producto.actualizado. Firma HMAC-SHA256 en cabecera X-Firma.
 */
class Webhooks
{
    public static function disparar(int $empresaId, string $evento, array $payload): void
    {
        $hooks = DB::q('SELECT * FROM webhook WHERE empresa_id = ? AND evento = ? AND activo = 1',
                       [$empresaId, $evento])->fetchAll();
        foreach ($hooks as $h) {
            DB::q('INSERT INTO webhook_intento (webhook_id, payload, proximo_intento) VALUES (?,?,NOW())',
                  [$h['id'], json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        }
        self::procesarPendientes(); // en hosting compartido tambien lo cubre el cron
    }

    public static function procesarPendientes(int $limite = 20): void
    {
        $rows = DB::q('SELECT wi.*, w.url, w.secreto FROM webhook_intento wi
                       JOIN webhook w ON w.id = wi.webhook_id
                       WHERE wi.estado = "PENDIENTE" AND wi.proximo_intento <= NOW()
                       AND wi.intentos < 5 LIMIT ' . (int)$limite)->fetchAll();
        foreach ($rows as $r) {
            $firma = hash_hmac('sha256', $r['payload'], $r['secreto']);
            $ch = curl_init($r['url']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $r['payload'],
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Firma: ' . $firma],
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            ]);
            curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $ok = $status >= 200 && $status < 300;
            DB::q('UPDATE webhook_intento SET http_status = ?, intentos = intentos + 1,
                     estado = ?, proximo_intento = DATE_ADD(NOW(), INTERVAL POW(2, intentos) MINUTE)
                   WHERE id = ?',
                  [$status, $ok ? 'OK' : ($r['intentos'] >= 4 ? 'FALLIDO' : 'PENDIENTE'), $r['id']]);
        }
    }
}

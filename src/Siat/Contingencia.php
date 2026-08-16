<?php
namespace Siat;

use Core\DB;

/**
 * Eventos significativos y envio diferido por paquete (.tar.gz).
 * Ejecutado por cron/contingencia_send.php cada 30 min.
 * Plazo legal: 48h desde el fin del evento.
 */
class Contingencia
{
    public static function abrirEvento(int $siatConfigId, int $codigoEvento): int
    {
        $abierto = DB::q('SELECT id FROM siat_evento WHERE siat_config_id = ? AND estado = "ABIERTO"',
                         [$siatConfigId])->fetch();
        if ($abierto) return (int)$abierto['id'];
        DB::q('INSERT INTO siat_evento (siat_config_id, codigo_evento, fecha_inicio) VALUES (?,?,NOW())',
              [$siatConfigId, $codigoEvento]);
        return DB::insertId();
    }

    public static function cerrarEvento(int $eventoId): void
    {
        DB::q('UPDATE siat_evento SET fecha_fin = NOW(), estado = "CERRADO" WHERE id = ? AND estado = "ABIERTO"',
              [$eventoId]);
    }

    /** Ciclo completo del cron: ping SIN -> cerrar evento -> reportar -> empaquetar -> enviar -> validar */
    public static function procesar(int $siatConfigId): array
    {
        $cfg = DB::q('SELECT * FROM siat_config WHERE id = ?', [$siatConfigId])->fetch();
        $soap = new SoapSin($cfg);
        $log = [];

        // 1. Ping: sincronizar fecha/hora como prueba de vida del SIN
        $ping = $soap->llamar('sincronizacion', 'sincronizarFechaHora',
                              $soap->paramsBase() + ['cuis' => $cfg['cuis']]);
        if (!$ping['ok']) return ['estado' => 'SIN_CAIDO'];

        // 2. Cerrar evento abierto si existe
        $evento = DB::q('SELECT * FROM siat_evento WHERE siat_config_id = ? AND estado IN ("ABIERTO","CERRADO")
                         ORDER BY id DESC LIMIT 1', [$siatConfigId])->fetch();
        if ($evento && $evento['estado'] === 'ABIERTO') {
            self::cerrarEvento((int)$evento['id']);
            $evento['estado'] = 'CERRADO';
            $evento['fecha_fin'] = date('Y-m-d H:i:s');
        }

        $pendientes = DB::q('SELECT * FROM factura WHERE siat_config_id = ? AND estado = "PENDIENTE"
                             AND tipo_emision = 2', [$siatConfigId])->fetchAll();
        if (!$pendientes) return ['estado' => 'SIN_PENDIENTES'];

        // 3. Reportar evento significativo si aun no se reporto
        if ($evento && $evento['estado'] === 'CERRADO' && !$evento['codigo_recepcion_evento']) {
            $r = $soap->llamar('operaciones', 'registroEventoSignificativo', $soap->paramsBase() + [
                'cuis' => $cfg['cuis'],
                'cufdEvento' => '',   // CUFD vigente al momento del evento
                'codigoMotivoEvento' => (int)$evento['codigo_evento'],
                'descripcion' => 'Evento automatico',
                'fechaHoraInicioEvento' => date('Y-m-d\TH:i:s.v', strtotime($evento['fecha_inicio'])),
                'fechaHoraFinEvento' => date('Y-m-d\TH:i:s.v', strtotime($evento['fecha_fin'])),
            ]);
            if ($r['ok']) {
                $d = $r['data']->RespuestaListaEventos ?? $r['data'];
                $cre = (string)($d->codigoRecepcionEventoSignificativo ?? '');
                DB::q('UPDATE siat_evento SET codigo_recepcion_evento = ?, estado = "REPORTADO" WHERE id = ?',
                      [$cre, $evento['id']]);
                $evento['codigo_recepcion_evento'] = $cre;
            } else {
                return ['estado' => 'EVENTO_NO_REPORTADO', 'error' => $r['data']];
            }
        }

        // 4. Empaquetar XMLs en .tar.gz
        $storage = (require dirname(__DIR__, 2) . '/config/config.php')['app']['storage'];
        $dir = $storage . '/xml/paquete_' . time();
        mkdir($dir, 0775, true);
        foreach ($pendientes as $f) {
            file_put_contents("$dir/factura_{$f['numero_factura']}.xml", $f['xml']);
        }
        $tar = "$dir.tar";
        $p = new \PharData($tar);
        $p->buildFromDirectory($dir);
        $p->compress(\Phar::GZ);
        $paquete = file_get_contents("$tar.gz");

        $ids = array_column($pendientes, 'id');
        DB::q('UPDATE factura SET estado = "EN_PROCESAMIENTO" WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');

        // 5. Enviar paquete
        $cufd = Cufd::asegurar($cfg);
        $env = $soap->llamar('compraventa', 'recepcionPaqueteFactura', $soap->paramsBase() + [
            'cuis' => $cfg['cuis'], 'cufd' => $cufd['codigo_cufd'],
            'codigoDocumentoSector' => 1, 'codigoEmision' => 2, 'tipoFacturaDocumento' => 1,
            'archivo' => $paquete, 'fechaEnvio' => date('Y-m-d\TH:i:s.v'),
            'hashArchivo' => hash('sha256', $paquete),
            'cantidadFacturas' => count($pendientes),
            'codigoEvento' => $evento['codigo_recepcion_evento'] ?? '',
        ]);

        if ($env['ok']) {
            $d = $env['data']->RespuestaServicioFacturacion ?? $env['data'];
            $codRecepcion = (string)($d->codigoRecepcion ?? '');
            DB::q('UPDATE factura SET codigo_recepcion = ? WHERE id IN (' .
                  implode(',', array_map('intval', $ids)) . ')', [$codRecepcion]);
            $log[] = "Paquete enviado: $codRecepcion (" . count($pendientes) . " facturas)";
            return ['estado' => 'PAQUETE_ENVIADO', 'codigo_recepcion' => $codRecepcion, 'log' => $log];
        }
        // revertir para reintento en el siguiente ciclo
        DB::q('UPDATE factura SET estado = "PENDIENTE" WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        return ['estado' => 'ENVIO_FALLIDO', 'error' => $env['data']];
    }

    /** Segunda fase del cron: validar paquetes EN_PROCESAMIENTO */
    public static function validarPaquetes(int $siatConfigId): void
    {
        $cfg = DB::q('SELECT * FROM siat_config WHERE id = ?', [$siatConfigId])->fetch();
        $soap = new SoapSin($cfg);
        $grupos = DB::q('SELECT codigo_recepcion, COUNT(*) c FROM factura
                         WHERE siat_config_id = ? AND estado = "EN_PROCESAMIENTO" AND codigo_recepcion IS NOT NULL
                         GROUP BY codigo_recepcion', [$siatConfigId])->fetchAll();
        foreach ($grupos as $g) {
            $r = $soap->llamar('compraventa', 'validacionRecepcionPaqueteFactura', $soap->paramsBase() + [
                'cuis' => $cfg['cuis'], 'cufd' => Cufd::asegurar($cfg)['codigo_cufd'],
                'codigoDocumentoSector' => 1, 'codigoEmision' => 2, 'tipoFacturaDocumento' => 1,
                'codigoRecepcion' => $g['codigo_recepcion'],
            ]);
            if (!$r['ok']) continue;
            $d = $r['data']->RespuestaServicioFacturacion ?? $r['data'];
            $desc = strtoupper((string)($d->codigoDescripcion ?? ''));
            if (str_contains($desc, 'VALIDA')) {
                DB::q('UPDATE factura SET estado = "VALIDA" WHERE codigo_recepcion = ?', [$g['codigo_recepcion']]);
            } elseif (str_contains($desc, 'RECHAZ') || str_contains($desc, 'OBSERV')) {
                $err = json_encode($d->mensajesList ?? $d, JSON_UNESCAPED_UNICODE);
                DB::q('UPDATE factura SET estado = "RECHAZADA", error_descripcion = ? WHERE codigo_recepcion = ?',
                      [$err, $g['codigo_recepcion']]);
            }
        }
        // Alerta de cuello de botella: >36h en PENDIENTE/RECHAZADA (limite legal 48h)
        $atascadas = DB::q('SELECT COUNT(*) c FROM factura WHERE siat_config_id = ?
                            AND estado IN ("PENDIENTE","RECHAZADA")
                            AND creado_en < DATE_SUB(NOW(), INTERVAL 36 HOUR)', [$siatConfigId])->fetch();
        if ((int)$atascadas['c'] > 0) {
            DB::q('INSERT INTO siat_log (origen, gravedad, descripcion) VALUES (?,?,?)',
                  ['cron', 'VALIDACION', "ALERTA: {$atascadas['c']} facturas a punto de vencer el plazo de 48h"]);
        }
    }
}

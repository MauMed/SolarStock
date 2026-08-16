<?php
namespace Siat;

use Core\DB;

/**
 * Estado REAL de la conexion con el SIAT, gestionado desde el backend.
 * No asume "en linea": lo deriva de la configuracion, vigencias y logs,
 * y opcionalmente hace un ping SOAP (sincronizarFechaHora) bajo demanda.
 *
 * Estados posibles:
 *  DESACTIVADO      modulo de facturacion apagado para la empresa
 *  NO_CONFIGURADO   no existe siat_config
 *  TOKEN_VENCIDO    el token delegado expiro (bloqueo)
 *  SIN_CUIS         falta solicitar el CUIS
 *  CONTINGENCIA     hay un evento significativo abierto o errores de
 *                   conexion en los ultimos 15 minutos
 *  SIN_CUFD         no hay CUFD vigente (el cron aun no lo renovo)
 *  EN_LINEA         todo operativo
 */
class EstadoSiat
{
    public static function calcular(int $empresaId): array
    {
        $emp = DB::q('SELECT facturacion_activa FROM empresa WHERE id = ?', [$empresaId])->fetch();
        if (!$emp || !$emp['facturacion_activa']) {
            return self::r('DESACTIVADO', 'Modulo de facturacion desactivado');
        }
        $cfgs = DB::q(
            'SELECT sc.* FROM siat_config sc JOIN sucursal s ON s.id = sc.sucursal_id
             WHERE s.empresa_id = ?', [$empresaId])->fetchAll();
        if (!$cfgs) return self::r('NO_CONFIGURADO', 'Configurar credenciales en el modulo SIAT');

        $cfg = $cfgs[0];
        if (strtotime($cfg['token_expira']) < time()) {
            return self::r('TOKEN_VENCIDO', 'Renovar el token delegado en el portal del SIN', $cfg);
        }
        if (!$cfg['cuis']) return self::r('SIN_CUIS', 'Solicitar el CUIS desde el modulo SIAT', $cfg);

        $eventoAbierto = DB::q('SELECT id FROM siat_evento WHERE siat_config_id = ? AND estado = "ABIERTO"',
                               [$cfg['id']])->fetch();
        $errorReciente = DB::q('SELECT id FROM siat_log WHERE gravedad = "CONEXION"
                                AND fecha > DATE_SUB(NOW(), INTERVAL 15 MINUTE) LIMIT 1')->fetch();
        if ($eventoAbierto || $errorReciente) {
            return self::r('CONTINGENCIA', 'Operando fuera de linea; las facturas se enviaran automaticamente', $cfg);
        }
        $cufd = Cufd::vigente((int)$cfg['id']);
        if (!$cufd) return self::r('SIN_CUFD', 'Esperando renovacion del CUFD (cron cada 15 min)', $cfg);

        $diasToken = (int)((strtotime($cfg['token_expira']) - time()) / 86400);
        return self::r('EN_LINEA', 'Conexion operativa', $cfg) + [
            'cufd_vence' => $cufd['fecha_fin'],
            'token_dias_restantes' => $diasToken,
            'alerta_token' => $diasToken <= 15,
        ];
    }

    /** Ping SOAP real (bajo demanda desde el boton "Probar conexion") */
    public static function probar(int $siatConfigId): array
    {
        $cfg = DB::q('SELECT * FROM siat_config WHERE id = ?', [$siatConfigId])->fetch()
            ?: throw new \RuntimeException('Configuracion no encontrada');
        $soap = new SoapSin($cfg);
        $ini = microtime(true);
        $r = $soap->llamar('sincronizacion', 'sincronizarFechaHora',
                           $soap->paramsBase() + ['cuis' => $cfg['cuis']]);
        return [
            'conectado' => $r['ok'],
            'latencia_ms' => (int)((microtime(true) - $ini) * 1000),
            'respuesta' => $r['ok'] ? 'SIAT respondio correctamente' : $r['data'],
        ];
    }

    private static function r(string $estado, string $detalle, ?array $cfg = null): array
    {
        return ['estado' => $estado, 'detalle' => $detalle,
                'ambiente' => $cfg['ambiente'] ?? null, 'config_id' => $cfg['id'] ?? null];
    }
}

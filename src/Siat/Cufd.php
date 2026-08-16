<?php
namespace Siat;

use Core\DB;

/**
 * Gestion de CUIS (anual) y CUFD (diario, 24h).
 * El historial de CUFD nunca se sobrescribe: el codigo_control historico
 * es necesario para auditar facturas antiguas.
 */
class Cufd
{
    public static function vigente(int $siatConfigId): ?array
    {
        return DB::q(
            'SELECT * FROM siat_cufd WHERE siat_config_id = ? AND fecha_fin > NOW()
             ORDER BY fecha_fin DESC LIMIT 1', [$siatConfigId]
        )->fetch() ?: null;
    }

    /** Devuelve el CUFD vigente; si no hay o vence en < 60 min, solicita uno nuevo. */
    public static function asegurar(array $siatCfg): array
    {
        $actual = self::vigente((int)$siatCfg['id']);
        if ($actual && strtotime($actual['fecha_fin']) - time() > 3600) {
            return $actual;
        }
        return self::solicitar($siatCfg) ?? ($actual ?: throw new \RuntimeException(
            'Sin CUFD vigente y el SIN no responde. Emitir en contingencia con el ultimo CUFD.'
        ));
    }

    public static function solicitar(array $siatCfg): ?array
    {
        $soap = new SoapSin($siatCfg);
        $r = $soap->llamar('codigos', 'cufd', $soap->paramsBase() + ['cuis' => $siatCfg['cuis']]);
        if (!$r['ok']) return null;

        $d = $r['data']->RespuestaCufd ?? $r['data'];
        $codigo  = $d->codigo ?? null;
        $control = $d->codigoControl ?? null;
        $vigencia = $d->fechaVigencia ?? date('Y-m-d H:i:s', time() + 86400);
        if (!$codigo || !$control) return null;

        DB::q('INSERT INTO siat_cufd (siat_config_id, codigo_cufd, codigo_control, direccion, fecha_inicio, fecha_fin)
               VALUES (?,?,?,?,NOW(),?)',
              [$siatCfg['id'], $codigo, $control, $d->direccion ?? null, date('Y-m-d H:i:s', strtotime($vigencia))]);
        return self::vigente((int)$siatCfg['id']);
    }

    public static function solicitarCuis(array $siatCfg): ?string
    {
        $soap = new SoapSin($siatCfg);
        $r = $soap->llamar('codigos', 'cuis', $soap->paramsBase());
        if (!$r['ok']) return null;
        $d = $r['data']->RespuestaCuis ?? $r['data'];
        $cuis = $d->codigo ?? null;
        if ($cuis) {
            DB::q('UPDATE siat_config SET cuis = ?, cuis_expira = ? WHERE id = ?',
                  [$cuis, date('Y-m-d', strtotime('+1 year')), $siatCfg['id']]);
        }
        return $cuis;
    }
}

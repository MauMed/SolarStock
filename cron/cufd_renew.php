<?php
/**
 * CRON JOB 1 - cada 10-15 minutos (panel Namecheap o cron-jobs.org):
 *   php /ruta/cron/cufd_renew.php
 * o via URL con token: https://dominio.com/cron/cufd_renew.php?token=SECRETO
 *
 * 1. Renueva CUFD si vence en < 60 min o no existe para hoy.
 * 2. Verifica vencimiento del Token Delegado (alerta a 15 dias).
 * 3. Reprocesa webhooks pendientes.
 */
set_time_limit(280);
require_once dirname(__DIR__) . '/src/Core/DB.php';
spl_autoload_register(function ($c) {
    $m = ['Core'=>'Core','Siat'=>'Siat','Integrations'=>'Integrations'];
    [$ns,$n] = explode('\\',$c,2)+[null,null];
    if (isset($m[$ns])) require_once dirname(__DIR__)."/src/{$m[$ns]}/$n.php";
});

use Core\DB;
use Siat\Cufd;

$cfgApp = require dirname(__DIR__) . '/config/config.php';
$configs = DB::q('SELECT * FROM siat_config')->fetchAll();

foreach ($configs as $cfg) {
    // Token delegado: alerta y bloqueo
    $dias = (strtotime($cfg['token_expira']) - time()) / 86400;
    if ($dias <= 0) {
        DB::q('INSERT INTO siat_log (origen, gravedad, descripcion) VALUES (?,?,?)',
              ['cron_cufd', 'VALIDACION', "TOKEN EXPIRADO config #{$cfg['id']}: facturacion bloqueada"]);
        continue;
    }
    if ($dias <= $cfgApp['siat']['token_alerta_dias']) {
        DB::q('INSERT INTO siat_log (origen, gravedad, descripcion) VALUES (?,?,?)',
              ['cron_cufd', 'INFO', sprintf('Token config #%d expira en %.0f dias', $cfg['id'], $dias)]);
    }
    try {
        Cufd::asegurar($cfg);
        echo "config #{$cfg['id']}: CUFD OK\n";
    } catch (\Throwable $e) {
        echo "config #{$cfg['id']}: {$e->getMessage()}\n";
    }
}
\Integrations\Webhooks::procesarPendientes(50);
echo "fin\n";

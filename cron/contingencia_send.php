<?php
/**
 * CRON JOB 2 - cada 30 minutos:
 *   php /ruta/cron/contingencia_send.php
 *
 * 1. Hace ping al SIN; si revivio, cierra el evento significativo.
 * 2. Reporta el evento (registroEventoSignificativo).
 * 3. Empaqueta XMLs pendientes en .tar.gz y los envia (recepcionPaqueteFactura).
 * 4. Valida paquetes en procesamiento (validacionRecepcionPaqueteFactura).
 * 5. Alerta si hay facturas a punto de vencer el plazo legal de 48h.
 */
set_time_limit(280);
spl_autoload_register(function ($c) {
    $m = ['Core'=>'Core','Siat'=>'Siat','Integrations'=>'Integrations'];
    [$ns,$n] = explode('\\',$c,2)+[null,null];
    if (isset($m[$ns])) require_once dirname(__DIR__)."/src/{$m[$ns]}/$n.php";
});

use Core\DB;
use Siat\Contingencia;

foreach (DB::q('SELECT id FROM siat_config')->fetchAll() as $cfg) {
    try {
        $r = Contingencia::procesar((int)$cfg['id']);
        echo "config #{$cfg['id']}: {$r['estado']}\n";
        Contingencia::validarPaquetes((int)$cfg['id']);
    } catch (\Throwable $e) {
        echo "config #{$cfg['id']}: ERROR {$e->getMessage()}\n";
    }
}
echo "fin\n";

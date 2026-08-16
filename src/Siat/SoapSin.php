<?php
namespace Siat;

use Core\DB;

/**
 * Cliente SOAP hacia los Web Services del SIN.
 * Maneja token delegado (apikey en cabecera), timeouts y clasificacion
 * de errores para decidir si activar contingencia.
 */
class SoapSin
{
    private array $cfgApp;
    private array $siatCfg;   // fila de siat_config

    public function __construct(array $siatConfigRow)
    {
        $this->cfgApp = require dirname(__DIR__, 2) . '/config/config.php';
        $this->siatCfg = $siatConfigRow;
    }

    public function urls(): array
    {
        return $this->cfgApp['siat'][$this->siatCfg['ambiente']];
    }

    private function cliente(string $servicio): \SoapClient
    {
        $timeout = $this->cfgApp['siat']['soap_timeout'];
        ini_set('default_socket_timeout', (string)$timeout);
        $ctx = stream_context_create([
            'http' => [
                'header' => 'apikey: TokenApi ' . trim($this->siatCfg['token_delegado']),
                'timeout' => $timeout,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        return new \SoapClient($this->urls()[$servicio], [
            'stream_context' => $ctx,
            'connection_timeout' => $timeout,
            'cache_wsdl' => WSDL_CACHE_MEMORY,
            'exceptions' => true,
            'trace' => true,
        ]);
    }

    /**
     * Llamada con reintentos. Devuelve ['ok'=>bool,'data'=>..,'contingencia'=>bool]
     * 'contingencia' = true cuando el fallo es de red / mantenimiento del SIN
     * (HTTP 5xx, timeout, cod. 902) y corresponde emitir fuera de linea.
     */
    public function llamar(string $servicio, string $metodo, array $params): array
    {
        $intentos = $this->cfgApp['siat']['soap_reintentos'];
        $ultimoError = null;
        for ($i = 0; $i < $intentos; $i++) {
            try {
                $cli = $this->cliente($servicio);
                $resp = $cli->$metodo(['SolicitudServicio' . ucfirst($metodo) => $params] + $params);
                return ['ok' => true, 'data' => $resp, 'contingencia' => false];
            } catch (\SoapFault $e) {
                $ultimoError = $e;
                $msg = strtolower($e->getMessage());
                $esCaida = str_contains($msg, 'timed out') || str_contains($msg, 'timeout')
                    || str_contains($msg, '503') || str_contains($msg, '502')
                    || str_contains($msg, 'could not connect') || str_contains($msg, '902')
                    || str_contains($msg, 'failed to load');
                if (!$esCaida) {
                    return ['ok' => false, 'data' => $e->getMessage(), 'contingencia' => false];
                }
                usleep(500000); // 0.5s entre reintentos
            }
        }
        DB::q('INSERT INTO siat_log (origen, gravedad, descripcion) VALUES (?,?,?)',
              [$metodo, 'CONEXION', $ultimoError ? $ultimoError->getMessage() : 'timeout']);
        return ['ok' => false, 'data' => $ultimoError?->getMessage(), 'contingencia' => true];
    }

    public function verificarNit(string $nit): array
    {
        return $this->llamar('codigos', 'verificarNit', [
            'nitParaVerificacion' => (int)$nit,
            'codigoSistema' => $this->siatCfg['codigo_sistema'],
            'nit' => (int)$this->siatCfg['nit_emisor'],
            'cuis' => $this->siatCfg['cuis'],
            'codigoSucursal' => (int)$this->sucursalCodigo(),
            'codigoModalidad' => (int)$this->siatCfg['modalidad'],
            'codigoAmbiente' => $this->siatCfg['ambiente'] === 'PRODUCCION' ? 1 : 2,
        ]);
    }

    public function sucursalCodigo(): int
    {
        $r = DB::q('SELECT codigo_sin FROM sucursal WHERE id = ?', [$this->siatCfg['sucursal_id']])->fetch();
        return (int)($r['codigo_sin'] ?? 0);
    }

    public function puntoVentaCodigo(): int
    {
        if (!$this->siatCfg['punto_venta_id']) return 0;
        $r = DB::q('SELECT codigo_sin FROM punto_venta WHERE id = ?', [$this->siatCfg['punto_venta_id']])->fetch();
        return (int)($r['codigo_sin'] ?? 0);
    }

    public function paramsBase(): array
    {
        return [
            'codigoAmbiente' => $this->siatCfg['ambiente'] === 'PRODUCCION' ? 1 : 2,
            'codigoModalidad' => (int)$this->siatCfg['modalidad'],
            'codigoSistema' => $this->siatCfg['codigo_sistema'],
            'nit' => (int)$this->siatCfg['nit_emisor'],
            'codigoSucursal' => $this->sucursalCodigo(),
            'codigoPuntoVenta' => $this->puntoVentaCodigo(),
        ];
    }
}

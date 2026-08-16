<?php
namespace Siat;

use Core\DB;

/**
 * Orquestador de emision:
 *  venta -> validar cliente -> correlativo -> CUF -> XML -> gzip -> SOAP
 *  Si el SIN no responde: tipoEmision=2, estado PENDIENTE, evento abierto.
 */
class Facturador
{
    private array $siatCfg;
    private array $empresa;
    private SoapSin $soap;

    public function __construct(int $siatConfigId)
    {
        $this->siatCfg = DB::q('SELECT * FROM siat_config WHERE id = ?', [$siatConfigId])->fetch()
            ?: throw new \RuntimeException('Configuracion SIAT no encontrada');
        if (strtotime($this->siatCfg['token_expira']) < time()) {
            throw new \RuntimeException('TOKEN_EXPIRADO: renovar el Token Delegado en el portal SIAT');
        }
        $suc = DB::q('SELECT * FROM sucursal WHERE id = ?', [$this->siatCfg['sucursal_id']])->fetch();
        $this->empresa = DB::q('SELECT * FROM empresa WHERE id = ?', [$suc['empresa_id']])->fetch();
        $this->soap = new SoapSin($this->siatCfg);
    }

    public function emitirDesdeVenta(int $ventaId): array
    {
        $venta = DB::q('SELECT * FROM venta WHERE id = ?', [$ventaId])->fetch()
            ?: throw new \RuntimeException('Venta no encontrada');
        $cliente = $venta['cliente_id']
            ? DB::q('SELECT * FROM cliente WHERE id = ?', [$venta['cliente_id']])->fetch()
            : ['tipo_doc_sin' => 1, 'num_documento' => '99001', 'complemento' => '',
               'razon_social' => 'CONTROL TRIBUTARIO', 'id' => 0]; // venta ventanilla sin datos

        // 1. Validar NIT en linea (solo si es tipo 4 y hay conexion)
        $tipoEmision = 1;
        if ((int)$cliente['tipo_doc_sin'] === 4) {
            $v = $this->soap->verificarNit($cliente['num_documento']);
            if ($v['ok']) {
                $msj = json_encode($v['data']);
                if (stripos($msj, 'INEXISTENTE') !== false || stripos($msj, 'INACTIVO') !== false) {
                    throw new \RuntimeException('NIT_INVALIDO: el NIT del cliente no existe o esta inactivo en el SIN');
                }
            } elseif ($v['contingencia']) {
                $tipoEmision = 2; // sin conexion: confiar en datos del cajero
            }
        }

        // 2. CUFD del dia (si no hay conexion, usar el ultimo almacenado)
        $cufd = Cufd::vigente((int)$this->siatCfg['id']);
        if (!$cufd || strtotime($cufd['fecha_fin']) < time()) {
            $nuevo = Cufd::solicitar($this->siatCfg);
            if ($nuevo) { $cufd = $nuevo; }
            elseif ($cufd) { $tipoEmision = 2; }   // CUFD vencido pero SIN caido: contingencia
            else { throw new \RuntimeException('SIN_CUFD: no existe ningun CUFD almacenado'); }
        }

        // 3. Correlativo por configuracion (sucursal + PDV)
        $nro = (int) DB::q('SELECT COALESCE(MAX(numero_factura),0)+1 n FROM factura WHERE siat_config_id = ?',
                           [$this->siatCfg['id']])->fetch()['n'];

        // 4. CUF
        $ahora = new \DateTime();
        $cuf = Cuf::generar(
            $this->siatCfg['nit_emisor'], Cuf::fechaCuf($ahora),
            $this->soap->sucursalCodigo(), (int)$this->siatCfg['modalidad'],
            $tipoEmision, 1, 1, $nro, $this->soap->puntoVentaCodigo(),
            $cufd['codigo_control']
        );

        // 5. XML
        $detalles = DB::q(
            'SELECT vd.*, p.nombre, p.descripcion prod_desc, p.codigo_interno, p.unidad_medida_sin,
                    p.codigo_actividad_sin, p.codigo_producto_sin
             FROM venta_detalle vd JOIN producto p ON p.id = vd.producto_id
             WHERE vd.venta_id = ?', [$ventaId])->fetchAll();
        foreach ($detalles as $d) {
            if (!$d['codigo_actividad_sin'] || !$d['codigo_producto_sin']) {
                throw new \RuntimeException("HOMOLOGACION_FALTANTE: producto '{$d['nombre']}' sin codigos SIN");
            }
        }
        $items = array_map(fn($d) => [
            'actividadEconomica' => $d['codigo_actividad_sin'],
            'codigoProductoSin' => $d['codigo_producto_sin'],
            'codigoProducto' => $d['codigo_interno'],
            // Glosa SIAT: nombre + descripcion del producto (si existe)
            'descripcion' => $d['nombre'] . ($d['prod_desc'] ? ' - ' . $d['prod_desc'] : ''),
            'cantidad' => $d['cantidad'],
            'unidadMedida' => $d['unidad_medida_sin'],
            'precioUnitario' => $d['precio_unit'],
            'montoDescuento' => $d['descuento'],
        ], $detalles);

        $xml = XmlFactura::compraVenta([
            'nitEmisor' => $this->siatCfg['nit_emisor'],
            'razonSocialEmisor' => $this->empresa['razon_social'],
            'municipio' => $this->empresa['municipio'] ?? '',
            'telefono' => $this->empresa['telefono'] ?? '',
            'numeroFactura' => $nro,
            'cuf' => $cuf,
            'cufd' => $cufd['codigo_cufd'],
            'codigoSucursal' => $this->soap->sucursalCodigo(),
            'direccion' => $this->empresa['direccion'] ?? '',
            'codigoPuntoVenta' => $this->soap->puntoVentaCodigo(),
            'fechaEmision' => $ahora->format('Y-m-d\TH:i:s.v'),
            'nombreRazonSocial' => $cliente['razon_social'],
            'codigoTipoDocumentoIdentidad' => $cliente['tipo_doc_sin'],
            'numeroDocumento' => $cliente['num_documento'],
            'complemento' => $cliente['complemento'] ?? '',
            'codigoCliente' => (string)($cliente['id'] ?: $cliente['num_documento']),
            'codigoMetodoPago' => $venta['metodo_pago_sin'],
            'montoTotal' => $venta['total'],
            'descuentoAdicional' => $venta['descuento'],
            'usuario' => 'CAJA',
        ], $items);

        // 6. Registrar factura local
        $facturaId = DB::tx(function () use ($ventaId, $cufd, $nro, $cuf, $ahora, $tipoEmision, $cliente, $venta, $xml) {
            DB::q('INSERT INTO factura (venta_id, siat_config_id, cufd_id, numero_factura, cuf, fecha_emision,
                     tipo_emision, cliente_snapshot, monto_total, estado, xml)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                  [$ventaId, $this->siatCfg['id'], $cufd['id'], $nro, $cuf,
                   $ahora->format('Y-m-d H:i:s.v'), $tipoEmision,
                   json_encode($cliente, JSON_UNESCAPED_UNICODE), $venta['total'],
                   'PENDIENTE', $xml]);
            return DB::insertId();
        });

        // 7. Envio en linea (si aplica)
        if ($tipoEmision === 1) {
            $envio = $this->enviarXml($facturaId, $xml);
            if ($envio['contingencia']) {
                // El SIN cayo entre la validacion y el envio: pasar a contingencia
                DB::q('UPDATE factura SET tipo_emision = 2 WHERE id = ?', [$facturaId]);
                Contingencia::abrirEvento((int)$this->siatCfg['id'], 2);
            }
        } else {
            Contingencia::abrirEvento((int)$this->siatCfg['id'], 2);
        }

        $f = DB::q('SELECT * FROM factura WHERE id = ?', [$facturaId])->fetch();
        $f['url_qr'] = XmlFactura::urlQr($this->soap->urls()['qr_base'],
            $this->siatCfg['nit_emisor'], $cuf, $nro, (float)$venta['total']);
        return $f;
    }

    public function enviarXml(int $facturaId, string $xml): array
    {
        $gz = gzencode($xml, 9);
        $r = $this->soap->llamar('compraventa', 'recepcionFactura', $this->soap->paramsBase() + [
            'cuis' => $this->siatCfg['cuis'],
            'cufd' => $this->cufdVigenteCodigo(),
            'codigoDocumentoSector' => 1,
            'codigoEmision' => 1,
            'tipoFacturaDocumento' => 1,
            'archivo' => $gz,
            'fechaEnvio' => date('Y-m-d\TH:i:s.v'),
            'hashArchivo' => hash('sha256', $gz),
        ]);

        if ($r['ok']) {
            $d = $r['data']->RespuestaServicioFacturacion ?? $r['data'];
            $estado = strtoupper((string)($d->codigoDescripcion ?? ''));
            if (str_contains($estado, 'VALIDA')) {
                DB::q('UPDATE factura SET estado = "VALIDA", codigo_recepcion = ? WHERE id = ?',
                      [(string)($d->codigoRecepcion ?? ''), $facturaId]);
            } else {
                $errores = json_encode($d->mensajesList ?? $d, JSON_UNESCAPED_UNICODE);
                DB::q('UPDATE factura SET estado = "RECHAZADA", error_descripcion = ? WHERE id = ?',
                      [$errores, $facturaId]);
                DB::q('INSERT INTO siat_log (factura_id, origen, gravedad, descripcion) VALUES (?,?,?,?)',
                      [$facturaId, 'recepcionFactura', 'VALIDACION', $errores]);
            }
        }
        return $r;
    }

    /** Anulacion: valida plazo (hasta el 9 del mes siguiente), motivo parametrico y SOAP */
    public function anular(int $facturaId, int $codigoMotivo): array
    {
        $f = DB::q('SELECT * FROM factura WHERE id = ?', [$facturaId])->fetch()
            ?: throw new \RuntimeException('Factura no encontrada');
        if ($f['estado'] !== 'VALIDA') throw new \RuntimeException('Solo se anulan facturas VALIDAS');

        $limite = new \DateTime($f['fecha_emision']);
        $limite->modify('first day of next month')->setDate(
            (int)$limite->format('Y'), (int)$limite->format('m'), 9)->setTime(23, 59, 59);
        if (new \DateTime() > $limite) {
            throw new \RuntimeException('PLAZO_VENCIDO: anulacion solo hasta el dia 9 del mes siguiente. Usar nota de credito/debito.');
        }

        $r = $this->soap->llamar('compraventa', 'anulacionFactura', $this->soap->paramsBase() + [
            'cuis' => $this->siatCfg['cuis'],
            'cufd' => $this->cufdVigenteCodigo(),   // CUFD del DIA ACTUAL, no el original
            'codigoDocumentoSector' => 1,
            'codigoEmision' => 1,
            'tipoFacturaDocumento' => (int)$f['tipo_factura'],
            'codigoMotivo' => $codigoMotivo,
            'cuf' => $f['cuf'],
        ]);

        if ($r['ok']) {
            $d = $r['data']->RespuestaServicioFacturacion ?? $r['data'];
            if (str_contains(strtoupper((string)($d->codigoDescripcion ?? '')), 'ANULA')) {
                DB::tx(function () use ($f, $codigoMotivo) {
                    DB::q('UPDATE factura SET estado = "ANULADA", motivo_anulacion = ? WHERE id = ?',
                          [$codigoMotivo, $f['id']]);
                    // Liberar inventario de la venta asociada
                    if ($f['venta_id']) {
                        $venta = DB::q('SELECT * FROM venta WHERE id = ?', [$f['venta_id']])->fetch();
                        $dets = DB::q('SELECT * FROM venta_detalle WHERE venta_id = ?', [$f['venta_id']])->fetchAll();
                        foreach ($dets as $d2) {
                            DB::q('UPDATE stock SET cantidad = cantidad + ? WHERE producto_id = ? AND almacen_id = ?',
                                  [$d2['cantidad'], $d2['producto_id'], $venta['almacen_id']]);
                            $saldo = DB::q('SELECT cantidad FROM stock WHERE producto_id=? AND almacen_id=?',
                                           [$d2['producto_id'], $venta['almacen_id']])->fetch()['cantidad'];
                            DB::q('INSERT INTO movimiento (empresa_id, producto_id, almacen_id, tipo, cantidad,
                                     saldo_resultante, referencia_tipo, referencia_id)
                                   VALUES (?,?,?,?,?,?,?,?)',
                                  [$venta['empresa_id'], $d2['producto_id'], $venta['almacen_id'],
                                   'DEVOLUCION', $d2['cantidad'], $saldo, 'anulacion_factura', $f['id']]);
                        }
                        DB::q('UPDATE venta SET estado = "ANULADA" WHERE id = ?', [$f['venta_id']]);
                    }
                });
                return ['ok' => true];
            }
            return ['ok' => false, 'error' => json_encode($d, JSON_UNESCAPED_UNICODE)];
        }
        return ['ok' => false, 'error' => $r['data'], 'contingencia' => $r['contingencia']];
    }

    private function cufdVigenteCodigo(): string
    {
        $c = Cufd::vigente((int)$this->siatCfg['id']);
        return $c ? $c['codigo_cufd'] : '';
    }
}

<?php
namespace Siat;

/**
 * Constructor del XML de factura Compra-Venta (docSector 1).
 * Arquitectura de plantillas: cabecera comun + detalle; para otros sectores
 * (hoteles, educacion, etc.) extender esta clase e inyectar los campos extra.
 */
class XmlFactura
{
    /** Limpieza estricta exigida por el SIN antes de incrustar texto en el XML */
    public static function limpiar(string $texto): string
    {
        $texto = mb_strtoupper($texto, 'UTF-8');
        $texto = str_replace(['"', '<', '>', '&', "\r", "\n", "\t"], ['', '', '', 'Y', ' ', ' ', ' '], $texto);
        return trim(preg_replace('/\s+/', ' ', $texto));
    }

    public static function compraVenta(array $cab, array $items): string
    {
        $x = new \XMLWriter();
        $x->openMemory();
        $x->startDocument('1.0', 'UTF-8');
        $x->startElement('facturaComputarizadaCompraVenta');
        $x->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $x->writeAttribute('xsi:noNamespaceSchemaLocation', 'facturaComputarizadaCompraVenta.xsd');

        $x->startElement('cabecera');
        $campos = [
            'nitEmisor' => $cab['nitEmisor'],
            'razonSocialEmisor' => self::limpiar($cab['razonSocialEmisor']),
            'municipio' => self::limpiar($cab['municipio'] ?? ''),
            'telefono' => $cab['telefono'] ?? '',
            'numeroFactura' => $cab['numeroFactura'],
            'cuf' => $cab['cuf'],
            'cufd' => $cab['cufd'],
            'codigoSucursal' => $cab['codigoSucursal'],
            'direccion' => self::limpiar($cab['direccion'] ?? ''),
            'codigoPuntoVenta' => $cab['codigoPuntoVenta'],
            'fechaEmision' => $cab['fechaEmision'],            // ISO 8601 con milisegundos
            'nombreRazonSocial' => self::limpiar($cab['nombreRazonSocial']),
            'codigoTipoDocumentoIdentidad' => $cab['codigoTipoDocumentoIdentidad'],
            'numeroDocumento' => $cab['numeroDocumento'],
            'complemento' => $cab['complemento'] ?? '',
            'codigoCliente' => $cab['codigoCliente'],
            'codigoMetodoPago' => $cab['codigoMetodoPago'] ?? 1,
            'numeroTarjeta' => $cab['numeroTarjeta'] ?? null,
            'montoTotal' => number_format((float)$cab['montoTotal'], 2, '.', ''),
            'montoTotalSujetoIva' => number_format((float)($cab['montoTotalSujetoIva'] ?? $cab['montoTotal']), 2, '.', ''),
            'codigoMoneda' => $cab['codigoMoneda'] ?? 1,
            'tipoCambio' => number_format((float)($cab['tipoCambio'] ?? 1), 2, '.', ''),
            'montoTotalMoneda' => number_format((float)$cab['montoTotal'], 2, '.', ''),
            'montoGiftCard' => null,
            'descuentoAdicional' => number_format((float)($cab['descuentoAdicional'] ?? 0), 2, '.', ''),
            'codigoExcepcion' => $cab['codigoExcepcion'] ?? null,
            'cafc' => null,
            'leyenda' => $cab['leyenda'] ?? 'Ley N 453: Tienes derecho a recibir informacion sobre las caracteristicas y contenidos de los servicios que utilices.',
            'usuario' => self::limpiar($cab['usuario'] ?? 'SISTEMA'),
            'codigoDocumentoSector' => $cab['codigoDocumentoSector'] ?? 1,
        ];
        foreach ($campos as $tag => $val) {
            if ($val === null || $val === '') {
                $x->startElement($tag);
                $x->writeAttribute('xsi:nil', 'true');
                $x->endElement();
            } else {
                $x->writeElement($tag, (string)$val);
            }
        }
        $x->endElement(); // cabecera

        foreach ($items as $it) {
            $x->startElement('detalle');
            $x->writeElement('actividadEconomica', $it['actividadEconomica']);
            $x->writeElement('codigoProductoSin', $it['codigoProductoSin']);
            $x->writeElement('codigoProducto', $it['codigoProducto']);
            $x->writeElement('descripcion', self::limpiar($it['descripcion']));
            $x->writeElement('cantidad', number_format((float)$it['cantidad'], 2, '.', ''));
            $x->writeElement('unidadMedida', (string)($it['unidadMedida'] ?? 58));
            $x->writeElement('precioUnitario', number_format((float)$it['precioUnitario'], 2, '.', ''));
            $x->writeElement('montoDescuento', number_format((float)($it['montoDescuento'] ?? 0), 2, '.', ''));
            $x->writeElement('subTotal', number_format(
                (float)$it['cantidad'] * (float)$it['precioUnitario'] - (float)($it['montoDescuento'] ?? 0), 2, '.', ''));
            $x->startElement('numeroSerie'); $x->writeAttribute('xsi:nil','true'); $x->endElement();
            $x->startElement('numeroImei');  $x->writeAttribute('xsi:nil','true'); $x->endElement();
            $x->endElement();
        }

        $x->endElement();
        return $x->outputMemory();
    }

    /** URL reglamentaria del codigo QR */
    public static function urlQr(string $qrBase, string $nit, string $cuf, int $numero, float $monto): string
    {
        return $qrBase . http_build_query([
            'nit' => $nit, 'cuf' => $cuf, 'numero' => $numero,
            't' => number_format($monto, 2, '.', ''),
        ]);
    }
}

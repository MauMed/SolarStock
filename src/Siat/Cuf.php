<?php
namespace Siat;

/**
 * Calculo del CUF (Codigo Unico de Facturacion) segun especificacion SIAT:
 *  1) Formatear 9 variables con ceros a la izquierda a longitud fija
 *  2) Concatenar en orden estricto
 *  3) Calcular digito verificador Modulo 11 y anexarlo
 *  4) Convertir la cadena numerica a hexadecimal (BCMath, alta precision)
 *  5) Concatenar el Codigo de Control del CUFD vigente
 *
 * NOTA: en la modalidad computarizada vigente, el CUF que viaja en el XML es
 * (hex de la cadena + digito Mod11) + codigoControl. Verificar contra el set
 * de pruebas de homologacion; si la especificacion del sector exige ademas
 * SHA-256 sobre la cadena final, activar $aplicarSha256.
 */
class Cuf
{
    public static function generar(
        string $nit,
        string $fechaHora,      // 'YYYYmmddHHiissvvv' 17 chars (con milisegundos)
        int $sucursal,
        int $modalidad,         // 2 = computarizada en linea
        int $tipoEmision,       // 1 en linea | 2 contingencia
        int $tipoFactura,       // 1 con credito fiscal
        int $docSector,         // 1 compra-venta
        int $numeroFactura,
        int $puntoVenta,
        string $codigoControl,  // del CUFD del dia
        bool $aplicarSha256 = false
    ): string {
        $cadena =
            str_pad($nit, 13, '0', STR_PAD_LEFT) .
            $fechaHora .
            str_pad((string)$sucursal, 4, '0', STR_PAD_LEFT) .
            $modalidad .
            $tipoEmision .
            $tipoFactura .
            str_pad((string)$docSector, 2, '0', STR_PAD_LEFT) .
            str_pad((string)$numeroFactura, 10, '0', STR_PAD_LEFT) .
            str_pad((string)$puntoVenta, 4, '0', STR_PAD_LEFT);

        $cadena .= self::modulo11($cadena);
        $hex = strtoupper(self::base16($cadena)) . $codigoControl;

        return $aplicarSha256 ? strtoupper(hash('sha256', $hex)) : $hex;
    }

    /** Fecha de emision en el formato compacto del CUF (17 caracteres) */
    public static function fechaCuf(\DateTimeInterface $dt): string
    {
        return $dt->format('YmdHis') . substr($dt->format('v'), 0, 3);
    }

    /**
     * Modulo 11 con pesos ciclicos 2..9 de derecha a izquierda.
     * Si el resultado es 10 -> '1', si es 11 -> '0' (convencion SIAT).
     */
    public static function modulo11(string $cadena, int $numDig = 1, int $limMult = 9, bool $x10 = false): string
    {
        if (!$x10) $numDig = 1;
        for ($n = 1; $n <= $numDig; $n++) {
            $suma = 0; $mult = 2;
            for ($i = strlen($cadena) - 1; $i >= 0; $i--) {
                $suma += $mult * (int)$cadena[$i];
                if (++$mult > $limMult) $mult = 2;
            }
            if ($x10) {
                $dig = (($suma * 10) % 11) % 10;
            } else {
                $dig = $suma % 11;
                if ($dig === 10) $dig = 1;
                if ($dig === 11) $dig = 0;
            }
            $cadena .= $dig;
        }
        return substr($cadena, -$numDig);
    }

    /** Conversion decimal -> hexadecimal para numeros gigantes con BCMath */
    public static function base16(string $decimal): string
    {
        if (!function_exists('bcmod')) {
            throw new \RuntimeException('Extension BCMath requerida (habilitar en php.ini)');
        }
        $hex = '';
        $digitos = '0123456789ABCDEF';
        if (bccomp($decimal, '0') === 0) return '0';
        while (bccomp($decimal, '0') > 0) {
            $resto = bcmod($decimal, '16');
            $hex = $digitos[(int)$resto] . $hex;
            $decimal = bcdiv($decimal, '16', 0);
        }
        return $hex;
    }
}

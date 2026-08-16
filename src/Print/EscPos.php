<?php
namespace Print_;

/**
 * Generador ESC/POS puro (sin dependencias) para impresoras termicas.
 * - TERMICA_RED: envio directo por socket TCP al puerto RAW 9100.
 * - TERMICA_BT: los mismos bytes se devuelven en base64 al frontend,
 *   que los transmite via Web Bluetooth (ver assets/print-bt.js).
 * - LASER/TINTA: no usa esta clase; el frontend imprime el PDF/HTML
 *   con window.print() (hoja carta / media carta).
 */
class EscPos
{
    private string $buf = '';
    private int $anchoCols;

    public function __construct(int $anchoMm = 80)
    {
        $this->anchoCols = $anchoMm >= 80 ? 48 : 32;
        $this->buf .= "\x1B\x40";              // init
        $this->buf .= "\x1B\x74\x10";          // codepage CP1252 (tildes/enie)
    }

    public function texto(string $t, bool $negrita = false, bool $centrado = false, int $tam = 0): self
    {
        $this->buf .= $centrado ? "\x1B\x61\x01" : "\x1B\x61\x00";
        $this->buf .= $negrita ? "\x1B\x45\x01" : "\x1B\x45\x00";
        $this->buf .= "\x1D\x21" . chr($tam);  // 0 normal, 0x11 doble
        $this->buf .= iconv('UTF-8', 'CP1252//TRANSLIT', $t) . "\n";
        return $this;
    }

    public function linea(): self { return $this->texto(str_repeat('-', $this->anchoCols)); }

    public function fila(string $izq, string $der): self
    {
        $esp = max(1, $this->anchoCols - mb_strlen($izq) - mb_strlen($der));
        return $this->texto($izq . str_repeat(' ', $esp) . $der);
    }

    /** QR nativo ESC/POS (GS ( k). Tamano modulo 6, correccion M (49). */
    public function qr(string $data, int $modulo = 6): self
    {
        $len = strlen($data) + 3;
        $pL = chr($len % 256); $pH = chr(intdiv($len, 256));
        $this->buf .= "\x1B\x61\x01";
        $this->buf .= "\x1D\x28\x6B\x04\x00\x31\x41\x32\x00";           // modelo 2
        $this->buf .= "\x1D\x28\x6B\x03\x00\x31\x43" . chr($modulo);    // tamano modulo
        $this->buf .= "\x1D\x28\x6B\x03\x00\x31\x45\x31";               // correccion M
        $this->buf .= "\x1D\x28\x6B" . $pL . $pH . "\x31\x50\x30" . $data;
        $this->buf .= "\x1D\x28\x6B\x03\x00\x31\x51\x30";               // imprimir
        return $this;
    }

    public function codigoBarras(string $codigo): self
    {
        $this->buf .= "\x1B\x61\x01\x1D\x68\x50\x1D\x77\x02";
        $this->buf .= "\x1D\x6B\x49" . chr(strlen($codigo)) . $codigo;  // CODE128
        $this->buf .= "\n";
        return $this;
    }

    public function cortar(): self
    {
        $this->buf .= "\n\n\n\x1D\x56\x42\x00";
        return $this;
    }

    public function bytes(): string { return $this->buf; }
    public function base64(): string { return base64_encode($this->buf); }

    /** Envio directo a impresora termica de RED (puerto RAW 9100) */
    public function enviarRed(string $ip, int $puerto = 9100, int $timeout = 5): bool
    {
        $fp = @fsockopen($ip, $puerto, $errno, $errstr, $timeout);
        if (!$fp) return false;
        fwrite($fp, $this->buf);
        fclose($fp);
        return true;
    }

    /** Ticket de venta/factura estandar */
    public static function ticketFactura(array $empresa, array $factura, array $items, string $urlQr, int $anchoMm = 80): self
    {
        $p = new self($anchoMm);
        $p->texto($empresa['razon_social'], true, true, 0x11)
          ->texto('NIT: ' . $empresa['nit'], false, true)
          ->texto($empresa['direccion'] ?? '', false, true)
          ->linea()
          ->texto('FACTURA No ' . $factura['numero_factura'], true, true)
          ->texto('CUF: ' . $factura['cuf'], false, false)
          ->texto('Fecha: ' . $factura['fecha_emision'])
          ->linea();
        foreach ($items as $it) {
            $p->texto($it['nombre']);
            $p->fila("  {$it['cantidad']} x " . number_format($it['precio_unit'], 2),
                     number_format($it['cantidad'] * $it['precio_unit'], 2));
        }
        $p->linea()
          ->fila('TOTAL Bs', number_format($factura['monto_total'], 2))
          ->linea()
          ->texto('Ley N 453: Tienes derecho a recibir', false, true)
          ->texto('informacion sobre los servicios que utilices.', false, true)
          ->qr($urlQr)
          ->texto('ESTA FACTURA CONTRIBUYE AL DESARROLLO', false, true)
          ->texto('DEL PAIS. EL USO ILICITO SERA SANCIONADO.', false, true)
          ->cortar();
        return $p;
    }
}

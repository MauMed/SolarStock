/**
 * Impresion en el sistema — tres rutas:
 *
 * A) TERMICA DE RED: el backend abre socket TCP al puerto 9100 y envia
 *    los bytes ESC/POS. El frontend solo llama a /imprimir/ticket.
 *
 * B) TERMICA BLUETOOTH: el backend devuelve los bytes ESC/POS en base64
 *    y este modulo los transmite con Web Bluetooth (Chrome Android).
 *    La mayoria de termicas BT exponen un servicio serie con estas UUIDs.
 *
 * C) LASER / TINTA: se abre una vista HTML de la factura tamano carta
 *    o media carta y se lanza window.print() (el navegador usa el driver
 *    del sistema: impresoras locales, de red o compartidas).
 */
const Impresion = (() => {
  const BT_SERVICIOS = [
    '000018f0-0000-1000-8000-00805f9b34fb', // servicio comun impresoras ESC/POS BT
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
  ];
  let dispositivoBT = null, caracteristicaBT = null;

  async function conectarBluetooth() {
    dispositivoBT = await navigator.bluetooth.requestDevice({
      acceptAllDevices: true,
      optionalServices: BT_SERVICIOS,
    });
    const server = await dispositivoBT.gatt.connect();
    for (const uuid of BT_SERVICIOS) {
      try {
        const svc = await server.getPrimaryService(uuid);
        const chars = await svc.getCharacteristics();
        caracteristicaBT = chars.find(c => c.properties.write || c.properties.writeWithoutResponse);
        if (caracteristicaBT) return true;
      } catch (_) { /* probar siguiente servicio */ }
    }
    throw new Error('La impresora no expone un servicio de escritura compatible');
  }

  async function imprimirBluetooth(escposBase64) {
    if (!caracteristicaBT) await conectarBluetooth();
    const bytes = Uint8Array.from(atob(escposBase64), c => c.charCodeAt(0));
    const CHUNK = 180;   // BLE limita el MTU; enviar en bloques
    for (let i = 0; i < bytes.length; i += CHUNK) {
      const parte = bytes.slice(i, i + CHUNK);
      if (caracteristicaBT.properties.writeWithoutResponse) {
        await caracteristicaBT.writeValueWithoutResponse(parte);
      } else {
        await caracteristicaBT.writeValue(parte);
      }
      await new Promise(r => setTimeout(r, 25));
    }
  }

  /** Vista imprimible para laser/tinta (carta) */
  function imprimirHtml(html) {
    const w = window.open('', '_blank', 'width=800,height=1000');
    w.document.write(`<!DOCTYPE html><html><head><meta charset="utf-8">
      <style>
        body{font-family:Arial,sans-serif;font-size:12px;margin:20mm}
        table{width:100%;border-collapse:collapse}
        td,th{border-bottom:1px solid #ccc;padding:4px;text-align:left}
        .der{text-align:right}
        @media print{ .no-print{display:none} }
      </style></head><body>${html}
      <script>window.onload=()=>{window.print();setTimeout(()=>window.close(),400)}<\/script>
      </body></html>`);
    w.document.close();
  }

  return { conectarBluetooth, imprimirBluetooth, imprimirHtml };
})();

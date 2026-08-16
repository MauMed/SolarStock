/**
 * Lectura de codigos de barras y QR — dos vias complementarias:
 *
 * 1. LECTOR FISICO (USB/Bluetooth HID): actua como teclado. Se detecta por
 *    velocidad de tipeo (< 50 ms entre teclas) + Enter final. Funciona en
 *    cualquier pantalla sin foco en un input especifico.
 *
 * 2. CAMARA del celular: BarcodeDetector nativo (Chrome/Android) con
 *    fallback a la libreria ZXing via CDN para iOS/Safari.
 */
const Scanner = (() => {
  let buffer = '', ultimaTecla = 0, onScan = null;

  // --- Lector fisico HID ---
  document.addEventListener('keydown', (e) => {
    if (!onScan) return;
    const ahora = Date.now();
    if (ahora - ultimaTecla > 100) buffer = '';   // tipeo humano: reiniciar
    ultimaTecla = ahora;
    if (e.key === 'Enter') {
      if (buffer.length >= 4) { onScan(buffer, 'hid'); e.preventDefault(); }
      buffer = '';
    } else if (e.key.length === 1) {
      buffer += e.key;
    }
  });

  // --- Camara ---
  let stream = null, rafId = null, zxingReader = null;

  async function abrirCamara(videoEl, callback) {
    onScan = callback;
    stream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode: 'environment', width: { ideal: 1280 } }
    });
    videoEl.srcObject = stream;
    await videoEl.play();

    if ('BarcodeDetector' in window) {
      const det = new BarcodeDetector({
        formats: ['ean_13','ean_8','code_128','code_39','upc_a','qr_code']
      });
      const loop = async () => {
        try {
          const codes = await det.detect(videoEl);
          if (codes.length) { callback(codes[0].rawValue, 'camara'); cerrarCamara(videoEl); return; }
        } catch (_) {}
        rafId = requestAnimationFrame(loop);
      };
      loop();
    } else {
      // Fallback ZXing (iOS / Safari)
      if (!window.ZXing) {
        await new Promise((res) => {
          const s = document.createElement('script');
          s.src = 'https://cdnjs.cloudflare.com/ajax/libs/zxing-library/0.21.3/umd/index.min.js';
          s.onload = res; document.head.appendChild(s);
        });
      }
      zxingReader = new ZXing.BrowserMultiFormatReader();
      zxingReader.decodeFromVideoElement(videoEl, (result) => {
        if (result) { callback(result.getText(), 'camara'); cerrarCamara(videoEl); }
      });
    }
  }

  function cerrarCamara(videoEl) {
    if (rafId) cancelAnimationFrame(rafId);
    if (zxingReader) { zxingReader.reset(); zxingReader = null; }
    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
    if (videoEl) videoEl.srcObject = null;
  }

  return {
    escuchar: (cb) => { onScan = cb; },
    abrirCamara, cerrarCamara,
  };
})();

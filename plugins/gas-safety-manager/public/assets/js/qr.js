/**
 * Gas Safety — QR helper on top of qrcode-generator (Kazuhiko Arase, MIT,
 * vendor/qrcode-generator.js). Replaces the unmaintained qrcodejs.
 *
 * GSM.qrPng(text, px) -> PNG data URL, error correction M, 4-module quiet
 * zone, crisp modules (integer scale). Text is encoded as UTF-8 (the
 * library's default keeps only the low 8 bits of each character, which
 * would corrupt non-Latin URLs).
 */
(function () {
  'use strict';
  window.GSM = window.GSM || {};
  if (!window.qrcode) return;
  window.qrcode.stringToBytes = window.qrcode.stringToBytesFuncs['UTF-8'];

  GSM.qrPng = function (text, px) {
    var qr = window.qrcode(0, 'M'); // 0 = smallest version that fits
    qr.addData(String(text), 'Byte');
    qr.make();
    var n = qr.getModuleCount();
    var quiet = 4;
    var total = n + quiet * 2;
    var scale = Math.max(1, Math.floor((px || 180) / total));
    var size = total * scale;
    var c = document.createElement('canvas');
    c.width = size;
    c.height = size;
    var g = c.getContext('2d');
    g.fillStyle = '#ffffff';
    g.fillRect(0, 0, size, size);
    g.fillStyle = '#000000';
    for (var r = 0; r < n; r++) {
      for (var col = 0; col < n; col++) {
        if (qr.isDark(r, col)) g.fillRect((col + quiet) * scale, (r + quiet) * scale, scale, scale);
      }
    }
    return c.toDataURL('image/png');
  };
})();

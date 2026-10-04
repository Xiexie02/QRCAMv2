import QRCode from 'qrcode';

window.QRCode = {
    toCanvas: QRCode.toCanvas.bind(QRCode),
};

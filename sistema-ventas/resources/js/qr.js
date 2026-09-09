/**
 * Dibuja el QR del cobro en el mostrador.
 *
 * Se carga bajo demanda —igual que los gráficos— porque la biblioteca solo
 * hace falta en el punto de venta y solo cuando el cajero elige cobrar por QR.
 * Cargarla siempre engordaría todas las pantallas del sistema por una función
 * que la mayoría no usa.
 */
export async function dibujarQr(canvas, contenido) {
    if (!canvas || !contenido) return;

    const { default: QRCode } = await import('qrcode');

    await QRCode.toCanvas(canvas, contenido, {
        // Un QR de cobro se escanea a medio metro y con la pantalla en ángulo:
        // conviene grande y con buen margen.
        width: 260,
        margin: 2,
        // Corrección de errores media: aguanta un reflejo o un dedo encima sin
        // volverse ilegible, y no crece tanto como el nivel alto.
        errorCorrectionLevel: 'M',
        color: { dark: '#000000', light: '#ffffff' },
    });
}

// El mostrador lo llama desde Alpine, que vive fuera del módulo.
window.dibujarQr = dibujarQr;

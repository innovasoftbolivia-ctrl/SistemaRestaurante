import './bootstrap';

import Alpine from 'alpinejs';
import focus from '@alpinejs/focus';
import flatpickr from 'flatpickr';
import { Spanish } from 'flatpickr/dist/l10n/es.js';
import 'flatpickr/dist/flatpickr.min.css';

// ApexCharts es pesado: `graficos.js` lo importa bajo demanda y solo en las
// pantallas que declaran algún `[data-apexchart]`.
import { iniciarGraficos } from './graficos';

// Igual que los gráficos: el dibujante de QR se trae solo donde se usa.
import './qr';

window.Alpine = Alpine;
window.flatpickr = flatpickr;

flatpickr.localize(Spanish);

// `x-trap`: cuando se abre un modal, el foco entra, no se puede escapar de
// él con Tab, el resto de la página queda `inert` (invisible para el lector
// de pantalla, no solo visualmente) y al cerrar el foco vuelve a quien lo
// abrió. Sin esto, un modal es indistinguible del resto de la página para
// quien navega con teclado o lector de pantalla.
Alpine.plugin(focus);

Alpine.start();

/**
 * La rueda del ratón no cambia cantidades.
 *
 * Los navegadores suben y bajan el valor de un `<input type="number">` cuando
 * se gira la rueda estando el campo enfocado. En una pantalla de cobro eso es
 * una trampa: se escribe una cantidad, se rueda para seguir leyendo la página,
 * y el número cambia sin que nadie lo note. No hay aviso, no hay deshacer, y
 * el error viaja hasta el kardex.
 *
 * Se quita el foco en vez de cancelar el evento. Cancelarlo evitaría el cambio
 * pero también dejaría la página sin desplazarse, que es justo lo que la
 * persona quería hacer. Sin foco, el navegador ya no toca el valor y la página
 * rueda como en cualquier otro sitio.
 *
 * Va en `document` y no campo por campo porque hay campos numéricos en el
 * mostrador, en las compras, en los ajustes y en cada formulario del catálogo
 * —y los hay que Alpine crea después de cargar la página.
 */
document.addEventListener(
    'wheel',
    () => {
        const activo = document.activeElement;

        if (activo instanceof HTMLInputElement && activo.type === 'number') {
            activo.blur();
        }
    },
    { passive: true },
);

function iniciarPantalla() {
    iniciarGraficos();

    // Campos de fecha con el calendario de la plantilla.
    document.querySelectorAll('[data-flatpickr]').forEach((input) => {
        flatpickr(input, {
            dateFormat: 'Y-m-d',
            altInput: true,
            altFormat: 'd/m/Y',
            allowInput: true,
        });
    });
}

// Si el documento ya terminó de cargar, `DOMContentLoaded` no volverá a
// dispararse y la pantalla se quedaría sin gráficos ni calendarios.
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', iniciarPantalla);
} else {
    iniciarPantalla();
}

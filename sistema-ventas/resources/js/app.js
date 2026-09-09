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

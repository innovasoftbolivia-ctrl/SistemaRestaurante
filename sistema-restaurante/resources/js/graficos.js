/**
 * Gráficos de los reportes, con ApexCharts.
 *
 * La plantilla pone en `data-apexchart` solo los datos —nunca funciones, que
 * no sobreviven a JSON— y aquí se arman las opciones, incluidos los
 * formateadores de moneda y el tema claro/oscuro.
 *
 *   <div data-apexchart='{"tipo":"area","categorias":[...],"series":[...]}'></div>
 */

const PALETA = ['#0a5cff', '#12b76a', '#f79009', '#f04438', '#0ba5ec'];

function escaparHtml(valor) {
    return String(valor ?? '').replace(
        /[&<>"']/g,
        (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c],
    );
}

function esOscuro() {
    return document.documentElement.classList.contains('dark');
}

function formateador(moneda) {
    return (valor) =>
        // Con el mismo formato que los importes del servidor (`Config::importe`):
        // 1,234.56. Solo el separador; la moneda es la del negocio.
        `${moneda} ${Number(valor ?? 0).toLocaleString('es-PE', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        })}`;
}

function opciones(config) {
    const { tipo = 'area', categorias = [], series = [], moneda = 'Bs', dinero = true } = config;
    // Barras acostadas: el nombre de cada una va en su propio renglón y no se
    // pisa con el de al lado. Los importes pasan al eje de abajo.
    const acostado = tipo === 'bar' && config.horizontal === true;
    const importe = formateador(moneda);
    const oscuro = esOscuro();
    const tenue = oscuro ? '#98a2b3' : '#667085';

    return {
        chart: {
            type: tipo,
            height: config.alto ?? 320,
            fontFamily: 'Outfit, ui-sans-serif, system-ui, sans-serif',
            toolbar: { show: false },
            zoom: { enabled: false },
            background: 'transparent',
        },
        theme: { mode: oscuro ? 'dark' : 'light' },
        colors: config.colores ?? PALETA,
        series,
        // Ojo: la clave debe faltar del todo cuando no hay etiquetas, no
        // valer `undefined` — con `labels: undefined` puesto explícito,
        // ApexCharts revienta por dentro en los gráficos de tipo `bar`
        // («Cannot read properties of undefined (reading 'length')»,
        // en `parseDataAxisCharts`) y el gráfico queda en blanco.
        ...(config.etiquetas ? { labels: config.etiquetas } : {}),
        xaxis: config.etiquetas
            ? undefined
            : {
                  categories: categorias,
                  // Con treinta jornadas las fechas se pisaban ("27/0828/08"):
                  // en la línea de tiempo se muestra una de cada tanto. Las
                  // barras no, porque cada una es una categoría con nombre.
                  ...(tipo === 'area' && categorias.length > 12
                      ? { tickAmount: 10, tickPlacement: 'on' }
                      : {}),
                  axisBorder: { show: false },
                  axisTicks: { show: false },
                  labels: {
                      style: { colors: tenue, fontSize: '12px' },
                      ...(acostado ? { formatter: dinero ? importe : (v) => Math.round(v) } : {}),
                  },
              },
        yaxis: config.etiquetas
            ? undefined
            : {
                  labels: {
                      style: { colors: tenue, fontSize: '12px' },
                      ...(acostado
                          ? { minWidth: 150, maxWidth: 200, offsetX: 8 }
                          : { formatter: dinero ? importe : (v) => Math.round(v) }),
                  },
              },
        dataLabels: { enabled: false },
        // `monotoneCubic` y no `smooth`: la curva suave pasaba por debajo de
        // cero entre una jornada con ventas y otra sin ninguna, y parecía que
        // se había vendido en negativo.
        stroke: { curve: 'monotoneCubic', width: tipo === 'bar' ? 0 : 2 },
        fill:
            tipo === 'area'
                ? { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02 } }
                : { opacity: 1 },
        grid: {
            borderColor: oscuro ? '#1d2939' : '#f2f4f7',
            strokeDashArray: 4,
            xaxis: { lines: { show: false } },
        },
        plotOptions: {
            bar: { borderRadius: 6, columnWidth: '45%', horizontal: acostado, barHeight: '60%' },
            pie: { donut: { size: '62%' } },
        },
        legend: { show: config.leyenda ?? false, labels: { colors: tenue } },
        tooltip: {
            theme: oscuro ? 'dark' : 'light',
            // ApexCharts pone el título del tooltip con innerHTML: el nombre de
            // un plato (lo escribe quien edita el menú) va escapado, o un nombre
            // con HTML se ejecutaría al pasar el mouse.
            x: { formatter: escaparHtml },
            y: { formatter: dinero ? importe : (v) => Math.round(v) },
        },
        noData: {
            text: 'Sin datos en el período',
            style: { color: tenue, fontSize: '14px' },
        },
    };
}

/**
 * ApexCharts mide el contenedor una sola vez, al dibujar, y solo se reajusta
 * cuando cambia el tamaño de la ventana. Aquí el contenedor cambia de ancho sin
 * que la ventana se mueva —al plegar la barra lateral, o cuando el margen del
 * contenido entra después de la primera pintura—, y el SVG se quedaba con el
 * ancho viejo: más ancho que su caja, empujando toda la página a lo ancho.
 *
 * Con un ResizeObserver el gráfico sigue a su contenedor. Se compara el ancho
 * anterior para no redibujar cuando solo cambió el alto, y se espera al
 * siguiente fotograma para no reentrar en el propio observador.
 *
 * IMPORTANTE: hay que llamar a esto solo después de que `render()` termine
 * (su promesa se resuelva). `ResizeObserver` dispara su primer aviso casi
 * enseguida tras `observe()` —incluso sin que nada haya cambiado de
 * tamaño—, y si ese aviso llama a `updateOptions()` mientras `render()`
 * todavía está construyendo el gráfico por dentro, ApexCharts revienta con
 * «Cannot read properties of undefined (reading 'length')» y el gráfico se
 * queda en blanco, sin ningún aviso en pantalla.
 */
function seguirAlContenedor(el, grafico) {
    if (typeof ResizeObserver === 'undefined') {
        return;
    }

    let anchoPrevio = Math.round(el.clientWidth);
    let pendiente = false;

    const observador = new ResizeObserver(() => {
        const ancho = Math.round(el.clientWidth);

        if (ancho === anchoPrevio || ancho === 0 || pendiente) {
            return;
        }

        anchoPrevio = ancho;
        pendiente = true;

        requestAnimationFrame(() => {
            pendiente = false;
            // En píxeles y no en '100%': con el porcentaje ApexCharts conserva el
            // ancho que ya había calculado y el SVG no se mueve.
            grafico.updateOptions({ chart: { width: el.clientWidth } }, false, false);
        });
    });

    observador.observe(el);
}

export function iniciarGraficos() {
    const nodos = document.querySelectorAll('[data-apexchart]');

    if (!nodos.length) {
        return;
    }

    import('apexcharts').then(({ default: ApexCharts }) => {
        window.ApexCharts = ApexCharts;

        const dibujados = [];

        nodos.forEach((el) => {
            let config;

            try {
                config = JSON.parse(el.dataset.apexchart);
            } catch {
                return;
            }

            const grafico = new ApexCharts(el, opciones(config));

            // `seguirAlContenedor` no debe engancharse hasta que `render()`
            // termine de verdad (ver el comentario en esa función).
            grafico.render().then(() => seguirAlContenedor(el, grafico));

            dibujados.push({ grafico, config });
        });

        // El tema se cambia sin recargar, así que los gráficos se reajustan
        // cuando cambia la clase `dark` del documento.
        new MutationObserver(() => {
            dibujados.forEach(({ grafico, config }) =>
                grafico.updateOptions(opciones(config), true, false),
            );
        }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    });
}

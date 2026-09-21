@php
    use App\Support\Config;

    $venta = $comprobante->venta;
    $ticket = $formato === 'ticket';

    // Marcar una línea como exonerada solo dice algo si el documento llevaba
    // impuesto: si el total no desglosa ninguno, «exonerado» aparece en casi
    // todas las líneas sin distinguir nada de nada. Se mira el impuesto del
    // COMPROBANTE y no la tasa de hoy, por lo mismo que el resto del papel: lo
    // ya emitido no cambia porque el negocio cambie de régimen después.
    //
    // En el rollo no sale nunca, ni siquiera con impuesto: es un papel de
    // 80 mm que el cliente mira para comprobar lo que pagó, y el desglose
    // fiscal es asunto del A4.
    $llevaImpuesto = (float) $comprobante->impuesto > 0;

    // El modo de precio es el de la venta, no el de hoy.
    $ventaDoc = $comprobante->venta;
    $incluido = (bool) $ventaDoc?->impuesto_incluido;
    // El símbolo sale del código congelado en el documento, no de la
    // configuración de hoy: si el negocio cambió de moneda, lo ya emitido no.
    $moneda = Config::simbolo($comprobante->moneda);

    // El pedido que se cobró. Toda venta del mostrador trae uno —el cliente
    // reclama su plato con este papel—; una venta vieja, de antes de los
    // pedidos, no, y entonces no se imprime nada: ni la línea, ni un hueco.
    $pedido = $venta?->pedido;
@endphp

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $comprobante->numero_completo }}</title>

    <style>
        /* Documento imprimible: hoja en blanco, sin nada del panel. */
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 16px;
            background: #f2f4f7;
            color: #101828;
            font-family: ui-monospace, "Cascadia Mono", "Segoe UI Mono", Consolas, monospace;
            font-size: {{ $ticket ? '12px' : '13px' }};
            line-height: 1.45;
        }

        .hoja {
            margin: 0 auto;
            background: #fff;
            padding: {{ $ticket ? '16px' : '40px' }};
            width: {{ $ticket ? '80mm' : '210mm' }};
            max-width: 100%;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .12);
        }

        .centro { text-align: center; }
        .derecha { text-align: right; }
        .fuerte { font-weight: 700; }
        .tenue { color: #667085; }

        .regla {
            border: 0;
            border-top: 1px dashed #d0d5dd;
            margin: 10px 0;
        }

        h1 { font-size: {{ $ticket ? '14px' : '20px' }}; margin: 0 0 2px; }
        h2 { font-size: {{ $ticket ? '13px' : '16px' }}; margin: 10px 0 4px; }

        /* El ticket es con lo que el cliente reclama su plato: quien lo lleva
           canta el número y el cliente lo compara con su papel. Por eso el
           número del pedido es lo MÁS GRANDE del ticket —más que el total y
           que el número de documento—, en su recuadro, con «comer aquí» o
           «para llevar» debajo. */
        .pedido {
            margin: 10px 0 4px;
            padding: {{ $ticket ? '6px 4px 8px' : '8px 10px 10px' }};
            border: 2px solid #101828;
            font-weight: 700;
            line-height: 1.1;
        }

        .pedido-rotulo {
            display: block;
            font-size: {{ $ticket ? '12px' : '13px' }};
            letter-spacing: 3px;
        }

        .pedido-numero {
            display: block;
            font-size: {{ $ticket ? '46px' : '52px' }};
            font-weight: 800;
            letter-spacing: 1px;
        }

        .pedido-destino {
            display: block;
            margin-top: 2px;
            font-size: {{ $ticket ? '17px' : '19px' }};
            letter-spacing: 2px;
        }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 3px 0; vertical-align: top; }
        thead th { border-bottom: 1px solid #d0d5dd; font-size: 11px; text-align: left; }

        /* El detalle lleva anchos fijos y no automáticos.
           Con el ancho automático, el navegador reparte las cuatro columnas
           según lo que haya dentro, así que cambiaban de sitio de un ticket a
           otro: bastaba una descripción larga —o una cantidad con unidad, «5
           KG» donde antes iba «5»— para estrujar las de la derecha y partir
           «P. Unit» en dos líneas, dejando «Importe» a media altura. En 80 mm
           no sobra el espacio para negociarlo cada vez. */
        .lineas { table-layout: fixed; }

        /* Las tres de la derecha son cifras cortas: no se parten nunca, y así
           la cabecera queda siempre encima de su columna. */
        .lineas th, .lineas td { white-space: nowrap; padding-left: 4px; }

        /* La descripción es la única que puede crecer, y por eso es la única
           que parte. `anywhere` porque el nombre de un plato puede traer una
           palabra más larga que la columna y en fijo no habría dónde ponerla. */
        .lineas .desc {
            width: {{ $ticket ? '68%' : '55%' }};
            padding-left: 0;
            white-space: normal;
            overflow-wrap: anywhere;
        }

        .lineas .cifra { width: {{ $ticket ? '32%' : '15%' }}; }

        /* En el ticket, la cantidad y el precio unitario bajan a una segunda
           línea bajo la descripción en vez de ocupar columna propia.

           No es gusto: en 80 mm de papel quedan 270 px para repartir, y de los
           50 que le tocaban a cada columna de cifras, «1,234.00» ya pide 56.
           Cualquier venta de más de mil se montaba encima de la columna de al
           lado. Es además como está hecho cualquier recibo de rollo, y por este
           mismo motivo. En A4 sobra sitio y se quedan las cuatro columnas. */
        .lineas .detalle-linea {
            display: block;
            color: #667085;
            font-size: 11px;
        }

        .totales td { padding: 2px 0; }
        .total-final td {
            border-top: 1px solid #101828;
            font-size: {{ $ticket ? '15px' : '17px' }};
            font-weight: 700;
            padding-top: 6px;
        }

        .sello {
            border: 2px solid #d92d20;
            color: #d92d20;
            padding: 6px;
            margin-bottom: 12px;
            font-weight: 700;
            text-align: center;
            letter-spacing: 2px;
        }

        .acciones {
            max-width: {{ $ticket ? '80mm' : '210mm' }};
            margin: 0 auto 12px;
            display: flex;
            gap: 8px;
            font-family: system-ui, sans-serif;
        }

        .acciones a, .acciones button {
            flex: 1;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #d0d5dd;
            background: #fff;
            color: #344054;
            font-size: 13px;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
        }

        .acciones .principal { background: #0a5cff; border-color: #0a5cff; color: #fff; }

        /* No se le pone `display`: el atributo `hidden` tiene que poder
           esconderlo solo, y una regla de display acá lo anularía. */
        .aviso {
            max-width: {{ $ticket ? '80mm' : '210mm' }};
            margin: 0 auto 12px;
            padding: 10px 12px;
            border: 1px solid #f5c26b;
            border-radius: 8px;
            background: #fffaeb;
            color: #7a5b12;
            font-family: system-ui, sans-serif;
            font-size: 12px;
            line-height: 1.45;
        }

        @media print {
            body { background: #fff; padding: 0; }
            .hoja { box-shadow: none; padding: {{ $ticket ? '0' : '16mm' }}; width: auto; }
            .acciones, .aviso { display: none; }

            /* `size: 80mm auto` PARECE lo correcto y es lo que se lee en medio
               internet, pero es CSS inválido: la norma no permite mezclar una
               medida con la palabra `auto`. Chrome no avisa de nada: descarta
               la regla ENTERA y sale en el papel por defecto de la impresora,
               que casi siempre es A4. Por eso va una altura concreta, y el
               guion de más abajo la reemplaza por la que el ticket mide de
               verdad, para que la impresora de rollo no escupa papel en blanco. */
            @page { size: {{ $ticket ? '80mm 200mm' : 'A4' }}; margin: {{ $ticket ? '4mm' : '12mm' }}; }
        }
    </style>
</head>

<body>
    <div class="acciones">
        <button type="button" class="principal" onclick="window.print()">Imprimir</button>
        <a href="{{ route('comprobantes.imprimir', [$comprobante, 'formato' => $ticket ? 'a4' : 'ticket']) }}">
            {{ $ticket ? 'Ver en A4' : 'Ver como ticket' }}
        </a>
        <a href="{{ route('ventas.show', $venta) }}">Volver a la venta</a>
    </div>

    @if ($ticket)
        <script>
            /* La altura del papel se mide y se declara. Sin esto el ticket sale
               sobre una hoja de 200 mm fijos: uno corto deja un palmo de papel
               en blanco antes del corte, y uno largo se parte en dos. */
            (function () {
                const PX_A_MM = 25.4 / 96;      // 96 px por pulgada, por norma
                const ANCHO = 80, MARGEN = 4;   // los mismos que declara @page

                function altoImpreso() {
                    const hoja = document.querySelector('.hoja');

                    /* Se mide con la geometría de impresión y no con la de
                       pantalla, que lleva relleno y sombra. El `maxWidth` hay que
                       anularlo o no sirve fijar el ancho: la hoja tiene
                       `max-width: 100%` y en una ventana angosta se encoge, el
                       texto se parte en más renglones y la altura se dispara.
                       Medido: 410 mm con el tope puesto contra 185 mm reales. */
                    const antes = hoja.getAttribute('style') || '';
                    hoja.style.padding = '0';
                    hoja.style.maxWidth = 'none';
                    hoja.style.width = (ANCHO - 2 * MARGEN) + 'mm';
                    const mm = hoja.getBoundingClientRect().height * PX_A_MM;
                    hoja.setAttribute('style', antes);

                    return mm;
                }

                /* En `load` y no antes: la tipografía todavía puede cambiar el
                   alto, y una medida tomada demasiado pronto se queda corta. */
                window.addEventListener('load', function () {
                    const papel = Math.ceil(altoImpreso() + 2 * MARGEN + 2);
                    const regla = document.createElement('style');
                    regla.textContent =
                        '@media print { @page { size: ' + ANCHO + 'mm ' + papel + 'mm; } }';
                    document.head.appendChild(regla);
                });
            })();
        </script>
    @endif

    @if ($autoImprimir ?? false)
        <div class="aviso" hidden>
            <strong>Apareció la ventana de impresión.</strong>
            Al acceso directo <strong>Caja</strong> le falta la opción
            <code>--kiosk-printing</code>: clic derecho en el acceso directo,
            Propiedades, y agregarla en «Destino» después de
            <code>chrome.exe</code>. Mientras tanto el ticket sale igual,
            apretando Imprimir en esta ventana.
        </div>

        <script>
            /* Se espera a `load` y no a que el documento esté listo: si se
               dispara antes de que bajen las imágenes y la tipografía, la
               impresora saca el ticket a medio armar. El respiro de 150 ms es
               para el navegador, que a veces reporta `load` un instante antes
               de haber pintado. */
            window.addEventListener('load', () => setTimeout(() => {
                /* Solo se imprime sola en la ventana de la caja.

                   El acceso directo abre Chrome con `--app=`, y esas ventanas
                   se declaran `standalone`; una pestaña común se declara
                   `browser`. Medido en la máquina de la caja: --app da
                   STANDALONE y la ventana normal da BROWSER.

                   Así nadie recibe el diálogo de impresión encima por abrir el
                   comprobante desde un navegador cualquiera —mostrándole el
                   sistema a un cliente, por ejemplo—: ahí la hoja se ve y ya
                   está. Para imprimir igual está el botón «Imprimir». */
                if (! matchMedia('(display-mode: standalone)').matches) {
                    return;
                }

                /* `print()` es sincrónico: cuando el navegador muestra el
                   diálogo, no devuelve hasta que alguien lo cierra. Ese tiempo
                   es la única forma de saber, desde la página, si el ticket
                   salió solo o si hubo que apretar un botón. */
                const antes = performance.now();
                window.print();
                const demora = performance.now() - antes;

                if (demora < 1200) {
                    /* Salió solo. La pestaña se cierra: en un turno de cien
                       ventas, si no, la caja termina con cien tickets abiertos.
                       Para MIRAR el comprobante está el otro botón, que abre la
                       hoja y se queda. */
                    window.close();

                    return;
                }

                /* Hubo diálogo. La pestaña se queda abierta con el motivo, en
                   vez de cerrarse y dejar al cajero preguntándose qué fue esa
                   ventana que no pidió. */
                document.querySelector('.aviso').hidden = false;
            }, 150));
        </script>
    @endif

    <div class="hoja">
        @if ($comprobante->estado === 'ANULADO')
            <div class="sello">ANULADO</div>
        @elseif ($comprobante->estado === 'SUSTITUIDO')
            <div class="sello">SUSTITUIDO</div>
        @endif

        <div class="centro">
            <h1>{{ $negocio['nombre'] }}</h1>
            @if ($negocio['documento'])
                <div class="tenue">NIT {{ $negocio['documento'] }}</div>
            @endif
            @if ($negocio['direccion'])
                <div class="tenue">{{ $negocio['direccion'] }}</div>
            @endif
            @if ($negocio['telefono'])
                <div class="tenue">Tel. {{ $negocio['telefono'] }}</div>
            @endif

            <hr class="regla">

            <div class="fuerte">{{ mb_strtoupper($comprobante->nombre_tipo) }}</div>
            <div class="fuerte">{{ $comprobante->numero_completo }}</div>
            <div class="tenue">{{ $comprobante->fecha_emision?->format('d/m/Y H:i') }}</div>

            @if ($pedido)
                {{-- El número de la jornada, no el id: es el que se canta al
                     entregar. El id se queda en el atributo, que no lo lee
                     nadie, por si hay que rastrearlo. --}}
                <div class="pedido" data-pedido="{{ $pedido->id }}" data-numero-pedido="{{ $pedido->numero_dia }}">
                    <span class="pedido-rotulo">PEDIDO</span>
                    <span class="pedido-numero">#{{ $pedido->numero_dia }}</span>
                    <span class="pedido-destino">{{ mb_strtoupper($pedido->destino) }}</span>
                </div>
                @if ($pedido->nombre_cliente)
                    <div class="fuerte">{{ $pedido->nombre_cliente }}</div>
                @endif
            @endif
        </div>

        <hr class="regla">

        <div>
            <div><span class="tenue">Cliente:</span> {{ $comprobante->cliente_nombre }}</div>
            @if ($comprobante->cliente_documento)
                <div>
                    <span class="tenue">{{ $comprobante->cliente_tipo_documento }}:</span>
                    {{ $comprobante->cliente_documento }}
                </div>
            @endif
            @if ($comprobante->cliente_direccion)
                <div><span class="tenue">Dirección:</span> {{ $comprobante->cliente_direccion }}</div>
            @endif
            @if ($comprobante->representante_legal)
                <div><span class="tenue">Representante:</span> {{ $comprobante->representante_legal }}</div>
            @endif
            <div><span class="tenue">Atendió:</span> {{ $venta->usuario?->usuario }}</div>
        </div>

        <hr class="regla">

        <table class="lineas">
            <thead>
                <tr>
                    <th class="desc">Descripción</th>
                    @unless ($ticket)
                        <th class="cifra derecha">Cant.</th>
                        <th class="cifra derecha">P. Unit</th>
                    @endunless
                    <th class="cifra derecha">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($venta->detalle as $linea)
                    <tr>
                        <td class="desc">
                            {{ $linea->descripcion }}

                            @if ($ticket)
                                <span class="detalle-linea">
                                    {{ $linea->cantidad_visible }} ×
                                    {{ number_format((float) $linea->precio_unitario, 2) }}
                                </span>
                            @elseif ($llevaImpuesto && ! $linea->afecto_impuesto)
                                <span class="tenue">(exonerado)</span>
                            @endif
                        </td>
                        @unless ($ticket)
                            <td class="cifra derecha">{{ $linea->cantidad_visible }}</td>
                            <td class="cifra derecha">{{ number_format((float) $linea->precio_unitario, 2) }}</td>
                        @endunless
                        {{-- Con el impuesto incluido, la línea vale lo que pagó el cliente. --}}
                        <td class="cifra derecha">{{ number_format((float) ($incluido ? $linea->total_linea : $linea->importe), 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <hr class="regla">

        <table class="totales">
            @if ($incluido)
            <tr>
                <td class="tenue">Subtotal</td>
                <td class="derecha">{{ $moneda }} {{ number_format($ventaDoc->total_antes_del_descuento, 2) }}</td>
            </tr>
            @if ($ventaDoc->descuento_visible > 0)
                <tr>
                    <td class="tenue">Descuento</td>
                    <td class="derecha">− {{ $moneda }} {{ number_format($ventaDoc->descuento_visible, 2) }}</td>
                </tr>
            @endif
            <tr class="total-final">
                <td>TOTAL</td>
                <td class="derecha">{{ $moneda }} {{ number_format((float) $comprobante->total, 2) }}</td>
            </tr>
            @if ((float) $comprobante->impuesto > 0)
                <tr data-iva-incluido>
                    <td class="tenue">IVA incluido</td>
                    <td class="derecha">{{ $moneda }} {{ number_format((float) $comprobante->impuesto, 2) }}</td>
                </tr>
                <tr>
                    <td class="tenue">Importe base</td>
                    <td class="derecha">{{ $moneda }} {{ number_format((float) $comprobante->total - (float) $comprobante->impuesto, 2) }}</td>
                </tr>
            @endif
            @else
            <tr>
                <td class="tenue">@facturacion Subtotal (base imponible) @else Subtotal @endfacturacion</td>
                <td class="derecha">{{ $moneda }} {{ number_format((float) $comprobante->subtotal, 2) }}</td>
            </tr>
            @if ((float) $comprobante->descuento > 0)
                <tr>
                    <td class="tenue">Descuento</td>
                    <td class="derecha">− {{ $moneda }} {{ number_format((float) $comprobante->descuento, 2) }}</td>
                </tr>
            @endif
            {{-- Se guía por lo que ESTE documento cobró, no por la tasa de hoy:
                 un comprobante viejo con impuesto sigue mostrándolo aunque el
                 negocio haya dejado de facturar con impuesto después. --}}
            @if ((float) $comprobante->impuesto > 0)
                <tr>
                    <td class="tenue">Impuesto</td>
                    <td class="derecha">{{ $moneda }} {{ number_format((float) $comprobante->impuesto, 2) }}</td>
                </tr>
            @endif
            <tr class="total-final">
                <td>TOTAL</td>
                <td class="derecha">{{ $moneda }} {{ number_format((float) $comprobante->total, 2) }}</td>
            </tr>
            @endif
        </table>

        <hr class="regla">

        <table class="totales">
            @foreach ($venta->pagos as $pago)
                <tr>
                    <td class="tenue">
                        {{ $pago->metodoPago?->nombre }}
                        @if ($pago->referencia)
                            <span class="tenue">· {{ $pago->referencia }}</span>
                        @endif
                    </td>
                    <td class="derecha">
                        {{ $moneda }}
                        {{ number_format((float) ($pago->monto_recibido ?? $pago->monto), 2) }}
                    </td>
                </tr>
            @endforeach
            @if ($venta->vuelto > 0)
                <tr>
                    <td class="tenue">Vuelto</td>
                    <td class="derecha">{{ $moneda }} {{ number_format($venta->vuelto, 2) }}</td>
                </tr>
            @endif
        </table>

        <hr class="regla">

        <div class="centro tenue">
            <div>¡Gracias por su compra!</div>
        </div>
    </div>
</body>

</html>

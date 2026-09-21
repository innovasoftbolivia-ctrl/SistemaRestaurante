@php
    use App\Models\Pedido;
    use App\Support\Config;

    $cancelado = $pedido->estado === Pedido::CANCELADO;
    $vacia = $nuevas->isEmpty() && $canceladas->isEmpty();
@endphp

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comanda · {{ $pedido->numero_visible }}</title>

    {{-- La comanda se lee de pie, en la cocina, con las manos ocupadas: el
         número y el destino enormes, cada plato en su renglón y la nota en un
         recuadro que no se puede pasar por alto. Sin precios: a la cocina no le
         importan. Mismo esquema que el comprobante (80 mm o A4, window.print). --}}
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 16px;
            background: #f2f4f7;
            color: #000;
            font-family: ui-monospace, "Cascadia Mono", "Segoe UI Mono", Consolas, monospace;
            font-size: {{ $ticket ? '14px' : '16px' }};
            line-height: 1.35;
        }

        .hoja {
            margin: 0 auto;
            background: #fff;
            padding: {{ $ticket ? '14px' : '40px' }};
            width: {{ $ticket ? '80mm' : '210mm' }};
            max-width: 100%;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .12);
        }

        .centro { text-align: center; }
        .tenue { color: #444; }
        .regla { border: 0; border-top: 2px dashed #000; margin: 10px 0; }

        .sello {
            border: 3px solid #000;
            padding: 6px;
            margin-bottom: 10px;
            font-size: {{ $ticket ? '18px' : '22px' }};
            font-weight: 800;
            text-align: center;
            letter-spacing: 2px;
        }

        .rotulo { font-size: 13px; font-weight: 700; letter-spacing: 3px; }

        /* El número: lo más grande del papel. Es con lo que la cocina
           identifica el pedido y lo que se canta al entregar. */
        .numero {
            font-size: {{ $ticket ? '56px' : '72px' }};
            font-weight: 800;
            line-height: 1;
        }

        .destino {
            margin-top: 4px;
            padding: 4px 0;
            border: 2px solid #000;
            font-size: {{ $ticket ? '22px' : '28px' }};
            font-weight: 800;
            letter-spacing: 2px;
        }

        .quien { margin-top: 4px; font-size: {{ $ticket ? '18px' : '22px' }}; font-weight: 700; }

        .linea { padding: 6px 0; border-bottom: 1px solid #ccc; }
        .linea:last-child { border-bottom: 0; }

        .plato { font-size: {{ $ticket ? '20px' : '24px' }}; font-weight: 800; overflow-wrap: anywhere; }

        /* La nota es lo que el cocinero lee: recuadro grueso, en negrita y
           más grande que el texto común. */
        .nota {
            margin-top: 4px;
            padding: 4px 6px;
            border: 3px solid #000;
            font-size: {{ $ticket ? '18px' : '22px' }};
            font-weight: 800;
            overflow-wrap: anywhere;
        }

        .cancelado {
            margin: 6px 0;
            padding: 6px;
            border: 3px double #000;
            font-size: {{ $ticket ? '18px' : '22px' }};
            font-weight: 800;
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

        @media print {
            body { background: #fff; padding: 0; }
            .hoja { box-shadow: none; padding: {{ $ticket ? '0' : '16mm' }}; width: auto; }
            .acciones { display: none; }

            /* Una altura concreta y no `80mm auto`, que es CSS inválido y el
               navegador descarta en silencio (ver el comprobante). El guion de
               abajo la cambia por la que mide la comanda de verdad. */
            @page { size: {{ $ticket ? '80mm 200mm' : 'A4' }}; margin: {{ $ticket ? '4mm' : '12mm' }}; }
        }
    </style>
</head>

<body>
    <div class="acciones">
        <button type="button" class="principal" onclick="window.print()">Imprimir</button>
        <a href="{{ route('pedidos.comanda', array_filter([$pedido, 'marca' => request('marca'), 'reimpresion' => request('reimpresion'), 'formato' => $ticket ? 'a4' : null])) }}">
            {{ $ticket ? 'Ver en A4' : 'Ver en 80 mm' }}
        </a>
    </div>

    @if ($ticket)
        <script>
            /* La altura del papel se mide y se declara, igual que en el
               comprobante: si no, la impresora de rollo escupe papel en blanco. */
            (function () {
                const PX_A_MM = 25.4 / 96, ANCHO = 80, MARGEN = 4;

                window.addEventListener('load', function () {
                    const hoja = document.querySelector('.hoja');
                    const antes = hoja.getAttribute('style') || '';
                    hoja.style.padding = '0';
                    hoja.style.maxWidth = 'none';
                    hoja.style.width = (ANCHO - 2 * MARGEN) + 'mm';
                    const mm = hoja.getBoundingClientRect().height * PX_A_MM;
                    hoja.setAttribute('style', antes);

                    const regla = document.createElement('style');
                    regla.textContent = '@media print { @page { size: ' + ANCHO + 'mm ' + Math.ceil(mm + 2 * MARGEN + 2) + 'mm; } }';
                    document.head.appendChild(regla);
                });
            })();
        </script>
    @endif

    @if ($autoImprimir)
        <script>
            /* Recién pedida desde la caja o la cocina, la hoja abre la
               impresión sola. En la ventana de la caja con --kiosk-printing
               sale directo (ver scripts/caja/LEEME-impresion-directa.txt). */
            window.addEventListener('load', () => setTimeout(() => window.print(), 150));
        </script>
    @endif

    <div class="hoja" data-comanda="{{ $pedido->id }}" @if ($reimpresion) data-reimpresion @endif>
        @if ($reimpresion)
            <div class="sello">REIMPRESIÓN</div>
        @endif
        @if ($cancelado)
            <div class="sello">PEDIDO CANCELADO<br>NO PREPARAR</div>
        @elseif (! $reimpresion && $nuevas->isEmpty() && $canceladas->isNotEmpty())
            <div class="sello">AVISO DE CANCELACIÓN</div>
        @endif

        <div class="centro">
            <div class="rotulo">COMANDA · PEDIDO</div>
            <div class="numero" data-numero-pedido="{{ $pedido->numero_dia }}">#{{ $pedido->numero_dia }}</div>
            <div class="destino">{{ mb_strtoupper($pedido->destino) }}</div>
            @if ($pedido->quien)
                <div class="quien">{{ $pedido->quien }}</div>
            @endif
            <div class="tenue">
                Pedido {{ $pedido->fecha_apertura?->format('H:i') }}
                · impresa {{ $hora->format('H:i') }}
                @if ($pedido->usuario?->usuario)
                    · {{ $pedido->usuario->usuario }}
                @endif
            </div>
        </div>

        <hr class="regla">

        @foreach ($nuevas as $linea)
            <div class="linea">
                <div class="plato">{{ Config::cantidad($linea->cantidad) }} × {{ $linea->descripcion }}</div>
                @if ($linea->nota)
                    <div class="nota">» {{ $linea->nota }}</div>
                @endif
            </div>
        @endforeach

        {{-- Lo que ya estaba en papel en la cocina y se canceló: si no se avisa,
             el cocinero lo prepara igual. --}}
        @foreach ($canceladas as $linea)
            <div class="cancelado">
                CANCELADO: {{ Config::cantidad($linea->cantidad) }} × {{ $linea->descripcion }}
                @if ($linea->nota)
                    <div class="tenue">({{ $linea->nota }})</div>
                @endif
            </div>
        @endforeach

        @if ($vacia)
            <p class="centro tenue">Este pedido no tiene nada para la cocina.</p>
        @endif

        @if ($pedido->observacion && ! $cancelado)
            <hr class="regla">
            <div><span class="tenue">Obs.:</span> {{ $pedido->observacion }}</div>
        @endif

        <hr class="regla">
        <div class="centro tenue">
            {{ $negocio }} · jornada {{ $pedido->jornada?->format('d/m/Y') }}
        </div>
    </div>
</body>

</html>

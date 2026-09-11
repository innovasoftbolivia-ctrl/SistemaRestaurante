@php
    use App\Support\Config;

    $venta = $comprobante->venta;
    $ticket = $formato === 'ticket';
    // El símbolo sale del código congelado en el documento, no de la
    // configuración de hoy: si el negocio cambió de moneda, lo ya emitido no.
    $moneda = Config::simbolo($comprobante->moneda);
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

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 3px 0; vertical-align: top; }
        thead th { border-bottom: 1px solid #d0d5dd; font-size: 11px; text-align: left; }

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

        .obra {
            border: 1px dashed #b54708;
            color: #b54708;
            padding: 6px 8px;
            margin-top: 12px;
            font-size: 10px;
            line-height: 1.35;
        }

        .obra strong { letter-spacing: 1px; }

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

        .acciones .principal { background: #465fff; border-color: #465fff; color: #fff; }

        @media print {
            body { background: #fff; padding: 0; }
            .hoja { box-shadow: none; padding: {{ $ticket ? '0' : '16mm' }}; width: auto; }
            .acciones { display: none; }
            @page { size: {{ $ticket ? '80mm auto' : 'A4' }}; margin: {{ $ticket ? '4mm' : '12mm' }}; }
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

        <table>
            <thead>
                <tr>
                    <th>Descripción</th>
                    <th class="derecha">Cant.</th>
                    <th class="derecha">P. Unit</th>
                    <th class="derecha">Importe</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($venta->detalle as $linea)
                    <tr>
                        <td>
                            {{ $linea->descripcion }}
                            @unless ($linea->afecto_impuesto)
                                <span class="tenue">(exonerado)</span>
                            @endunless
                        </td>
                        {{-- Con la unidad: un «2.5» pelado no le dice nada a quien
                             compró dos kilos y medio de arroz, y comprobar lo que
                             le cobraron es justo para lo que sirve el papel. --}}
                        <td class="derecha">{{ $linea->cantidad_con_unidad }}</td>
                        <td class="derecha">{{ number_format((float) $linea->precio_unitario, 2) }}</td>
                        <td class="derecha">{{ number_format((float) $linea->importe, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <hr class="regla">

        <table class="totales">
            <tr>
                <td class="tenue">Subtotal (base imponible)</td>
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
            <div>{{ $comprobante->numero_completo }} · venta #{{ $venta->id }}</div>
        </div>

        {{-- El régimen tributario todavía no está definido: mientras tanto se
             dice en el propio documento, en vez de aparentar que ya lo está. --}}
        <div class="obra">
            <strong>EN CONSTRUCCIÓN</strong> — La parte tributaria de este documento está pendiente de definir:
            @if (App\Support\Config::tasaImpuesto() > 0)
                la tasa de impuesto ({{ number_format(App\Support\Config::tasaImpuesto() * 100, 0) }}%) y la
                identificación fiscal del negocio son provisionales.
            @else
                el precio cobrado no lleva impuesto desglosado, y la identificación fiscal del negocio es
                provisional.
            @endif
            Sin validez tributaria.
        </div>
    </div>
</body>

</html>

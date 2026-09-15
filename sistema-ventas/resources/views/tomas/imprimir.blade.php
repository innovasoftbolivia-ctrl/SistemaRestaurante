@php
    use App\Support\Config;

    // Abierta: planilla en blanco para contar en papel, SIN el stock del
    // sistema —se cuenta lo que hay, no se confirma un número—. Cerrada o
    // cancelada: el resultado, con las diferencias.
    $planilla = $toma->estaAbierta();
    $moneda = Config::moneda();
@endphp

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $planilla ? 'Planilla de conteo' : 'Resultado de la toma' }} #{{ $toma->id }} — {{ $toma->alcance }}</title>

    <style>
        /* Documento imprimible: mismo criterio que caja/imprimir.blade.php. */
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 16px;
            background: #f2f4f7;
            color: #101828;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 12px;
            line-height: 1.45;
        }

        .hoja {
            margin: 0 auto;
            background: #fff;
            padding: 32px;
            width: 210mm;
            max-width: 100%;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .12);
        }

        .centro { text-align: center; }
        .derecha { text-align: right; }
        .fuerte { font-weight: 700; }
        .tenue { color: #667085; }
        .falta { color: #b42318; }
        .sobra { color: #067647; }

        h1 { font-size: 18px; margin: 0 0 2px; }

        .regla { border: 0; border-top: 1px solid #d0d5dd; margin: 14px 0; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 6px 5px 0; vertical-align: top; }
        thead th { border-bottom: 1px solid #d0d5dd; font-size: 10px; text-align: left; color: #667085; text-transform: uppercase; letter-spacing: .03em; }
        tbody td { border-bottom: 1px solid #eaecf0; font-variant-numeric: tabular-nums; }

        .grupo td {
            padding-top: 14px;
            border-bottom: 1px solid #98a2b3;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: #344054;
        }

        /* La casilla donde se escribe a mano lo contado. */
        .casilla { width: 24mm; border-bottom: 1px solid #101828; }

        .cifras { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 14px 0 20px; }
        .cifra { border: 1px solid #eaecf0; border-radius: 8px; padding: 8px 10px; }
        .cifra .etiqueta { font-size: 9px; text-transform: uppercase; letter-spacing: .04em; color: #667085; }
        .cifra .valor { font-size: 15px; font-weight: 700; margin-top: 2px; }

        .firmas { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 48px; }
        .firma .linea { border-top: 1px solid #101828; margin-top: 40px; padding-top: 6px; font-size: 11px; }

        .acciones { max-width: 210mm; margin: 0 auto 12px; display: flex; gap: 8px; }
        .acciones a, .acciones button {
            flex: 1; padding: 10px; border-radius: 8px; border: 1px solid #d0d5dd;
            background: #fff; color: #344054; font-size: 13px; text-align: center;
            text-decoration: none; cursor: pointer; font-family: inherit;
        }
        .acciones .principal { background: #465fff; border-color: #465fff; color: #fff; }

        @media print {
            body { background: #fff; padding: 0; }
            .hoja { box-shadow: none; padding: 0; width: auto; }
            .acciones { display: none; }
            thead { display: table-header-group; }
            tr { break-inside: avoid; }
            @page { size: A4; margin: 12mm; }
        }
    </style>
</head>

<body>
    <div class="acciones">
        <button type="button" class="principal" onclick="window.print()">Imprimir</button>
        <a href="{{ route('tomas.show', $toma) }}">Volver a la toma</a>
    </div>

    <div class="hoja">
        <div class="centro">
            <h1>{{ $negocio }}</h1>
            <div class="fuerte" style="font-size: 14px;">
                {{ $planilla ? 'Planilla de conteo' : 'Resultado de la toma de inventario' }} #{{ $toma->id }}
            </div>
            <div class="tenue">{{ $toma->alcance }}@if ($toma->observacion) · {{ $toma->observacion }}@endif</div>
        </div>

        <hr class="regla">

        <table style="margin-bottom: 8px;">
            <tr>
                <td class="tenue" style="width: 22%;">Abierta</td>
                <td>{{ $toma->fecha_apertura?->format('d/m/Y H:i') }} · {{ $toma->usuarioApertura?->usuario }}</td>
            </tr>
            @if ($toma->fecha_cierre)
                <tr>
                    <td class="tenue">{{ $toma->estado === 'CERRADA' ? 'Cerrada' : 'Cancelada' }}</td>
                    <td>{{ $toma->fecha_cierre->format('d/m/Y H:i') }} · {{ $toma->usuarioCierre?->usuario }}</td>
                </tr>
            @endif
            @if ($planilla)
                <tr>
                    <td class="tenue">Contó</td>
                    <td>________________________________ &nbsp; Sector: ____________________</td>
                </tr>
                <tr>
                    <td class="tenue">Hora del conteo</td>
                    <td>______ : ______ &nbsp; <span class="tenue">Anótala: al cargar la planilla se indica en «Contado a las».</span></td>
                </tr>
            @endif
        </table>

        @unless ($planilla)
            <div class="cifras">
                <div class="cifra">
                    <div class="etiqueta">Contados</div>
                    <div class="valor">{{ number_format($resumen['contados']) }} / {{ number_format($resumen['total']) }}</div>
                </div>
                <div class="cifra">
                    <div class="etiqueta">Con diferencia</div>
                    <div class="valor">{{ number_format($resumen['con_diferencia']) }}</div>
                </div>
                <div class="cifra">
                    <div class="etiqueta">Falta (al costo)</div>
                    <div class="valor falta">{{ $moneda }} {{ number_format($resumen['faltante'], 2) }}</div>
                </div>
                <div class="cifra">
                    <div class="etiqueta">Sobra (al costo)</div>
                    <div class="valor sobra">{{ $moneda }} {{ number_format($resumen['sobrante'], 2) }}</div>
                </div>
            </div>
        @endunless

        <table>
            <thead>
                <tr>
                    <th style="width: 14%;">Código</th>
                    <th>Producto</th>
                    <th style="width: 8%;">Unidad</th>
                    @if ($planilla)
                        <th class="derecha" style="width: 26mm;">Contado</th>
                    @else
                        <th class="derecha" style="width: 11%;">Contado</th>
                        <th class="derecha" style="width: 11%;">Sistema</th>
                        <th class="derecha" style="width: 11%;">Diferencia</th>
                        <th class="derecha" style="width: 12%;">Valor</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach ($grupos as $categoria => $lineas)
                    <tr class="grupo">
                        <td colspan="{{ $planilla ? 4 : 7 }}">{{ $categoria ?: 'Sin categoría' }}</td>
                    </tr>
                    @foreach ($lineas as $linea)
                        <tr>
                            <td>{{ $linea->producto->codigo }}</td>
                            <td>
                                {{ $linea->producto->nombre }}
                                @if ($linea->producto->tieneEmpaque())
                                    <span class="tenue">· {{ $linea->producto->etiqueta_empaque }}</span>
                                @endif
                            </td>
                            <td>{{ $linea->producto->unidadMedida?->codigo }}</td>
                            @if ($planilla)
                                <td class="derecha"><div class="casilla" style="margin-left: auto;">&nbsp;</div></td>
                            @elseif ($linea->contado === null)
                                <td class="derecha tenue" colspan="4">sin contar</td>
                            @else
                                @php $diferencia = (float) $linea->diferencia; @endphp
                                <td class="derecha">{{ Config::cantidad($linea->contado) }}</td>
                                <td class="derecha tenue">{{ Config::cantidad($linea->stock_sistema) }}</td>
                                <td class="derecha {{ $diferencia < 0 ? 'falta' : ($diferencia > 0 ? 'sobra' : 'tenue') }}">
                                    {{ $diferencia === 0.0 ? 'cuadra' : ($diferencia > 0 ? '+' : '').Config::cantidad($diferencia) }}
                                </td>
                                <td class="derecha {{ $diferencia < 0 ? 'falta' : ($diferencia > 0 ? 'sobra' : 'tenue') }}">
                                    {{ $diferencia === 0.0 ? '—' : ($diferencia < 0 ? '− ' : '+ ').number_format(abs($linea->valor_diferencia), 2) }}
                                </td>
                            @endif
                        </tr>
                    @endforeach
                @endforeach
            </tbody>
        </table>

        <div class="firmas">
            <div class="firma">
                <div class="linea">{{ $planilla ? 'Contó' : 'Responsable del conteo' }}</div>
            </div>
            <div class="firma">
                <div class="linea">{{ $planilla ? 'Revisó' : 'Administración' }}</div>
            </div>
        </div>
    </div>
</body>

</html>

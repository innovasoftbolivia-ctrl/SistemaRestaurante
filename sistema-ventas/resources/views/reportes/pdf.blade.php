@php
    /**
     * Reporte en PDF. Recibe la misma estructura que arma el libro de Excel
     * (`ReporteController::documento()`), así que las dos descargas no pueden
     * decir cosas distintas.
     *
     * dompdf no entiende flexbox ni grid: la maquetación va con tablas y con
     * `float`, que es lo que sí sabe componer.
     */
    $moneda = $doc['moneda'];

    /** Da formato a un valor según lo que declare la columna. */
    $fmt = function ($valor, ?string $tipo) use ($moneda) {
        if ($valor === null || $valor === '') {
            return '';
        }

        return match ($tipo) {
            'moneda' => $moneda.' '.number_format((float) $valor, 2, '.', ','),
            'entero' => number_format((float) $valor, 0, '.', ','),
            'decimal' => rtrim(rtrim(number_format((float) $valor, 3, '.', ','), '0'), '.'),
            'porcentaje' => number_format((float) $valor * 100, 1, '.', ',').' %',
            'fecha' => \Illuminate\Support\Carbon::parse($valor)->format('d/m/Y'),
            default => $valor,
        };
    };
@endphp
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <title>{{ $doc['titulo'] }}</title>

    <style>
        @page {
            margin: 92px 32px 56px 32px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "DejaVu Sans", sans-serif;
            font-size: 9.5px;
            color: #101828;
            line-height: 1.45;
        }

        /* --- Cabecera y pie, repetidos en todas las páginas por dompdf --- */
        header {
            position: fixed;
            top: -74px;
            left: 0;
            right: 0;
            height: 62px;
            border-bottom: 2px solid #0a5cff;
            padding-bottom: 6px;
        }

        .negocio { font-size: 17px; font-weight: bold; color: #0a5cff; }
        .datos-negocio { font-size: 8.5px; color: #667085; margin-top: 2px; }

        .titulo-doc {
            float: right;
            text-align: right;
            margin-top: -30px;
        }
        .titulo-doc .nombre { font-size: 12px; font-weight: bold; color: #101828; }
        .titulo-doc .periodo { font-size: 8.5px; color: #667085; }

        footer {
            position: fixed;
            bottom: -38px;
            left: 0;
            right: 0;
            height: 28px;
            border-top: 1px solid #e4e7ec;
            padding-top: 5px;
            font-size: 8px;
            color: #98a2b3;
        }
        footer .der { float: right; }

        /* La numeración la escribe dompdf con este contador. */
        .pagina:after { content: counter(page) " / " counter(pages); }

        /* --- Indicadores --- */
        h2 {
            font-size: 10.5px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            color: #2a31d8;
            margin: 0 0 6px;
            padding-bottom: 3px;
            border-bottom: 1.5px solid #0a5cff;
        }

        table.indicadores { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        table.indicadores td {
            border: 1px solid #e4e7ec;
            padding: 6px 8px;
            vertical-align: top;
        }
        table.indicadores tr.par td { background: #f9fafb; }
        .ind-etiqueta { font-weight: bold; width: 30%; }
        .ind-valor { text-align: right; font-weight: bold; width: 22%; white-space: nowrap; }
        .ind-nota { color: #667085; font-size: 8.5px; font-style: italic; }
        .destacado .ind-valor, .destacado .ind-etiqueta { color: #027a48; }

        /* --- Tablas de datos --- */
        .bloque { margin-bottom: 20px; }
        .nota { color: #667085; font-size: 8.5px; font-style: italic; margin: 0 0 5px; }

        table.datos { width: 100%; border-collapse: collapse; }
        table.datos thead th {
            background: #0a5cff;
            color: #fff;
            font-size: 8.5px;
            text-align: left;
            padding: 6px 7px;
            border: 1px solid #0a5cff;
        }
        table.datos tbody td {
            padding: 5px 7px;
            border: 1px solid #e4e7ec;
        }
        table.datos tbody tr.par td { background: #f9fafb; }
        table.datos tfoot td {
            padding: 6px 7px;
            font-weight: bold;
            border: 1px solid #e4e7ec;
            border-top: 2px solid #0a5cff;
            background: #ecf3ff;
        }
        .der { text-align: right; }
        .centro { text-align: center; }
        .vacia {
            padding: 14px;
            text-align: center;
            color: #667085;
            font-style: italic;
            border: 1px solid #e4e7ec;
        }

        /* Que una tabla no se parta dejando la cabecera huérfana al final. */
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
    </style>
</head>

<body>

<header>
    <div class="negocio">{{ $doc['negocio']['nombre'] }}</div>
    <div class="datos-negocio">
        @php
            $datos = array_filter([
                $doc['negocio']['documento'] ? 'NIT '.$doc['negocio']['documento'] : null,
                $doc['negocio']['direccion'],
                $doc['negocio']['telefono'],
            ]);
        @endphp
        {{ implode('  ·  ', $datos) }}
    </div>
    <div class="titulo-doc">
        <div class="nombre">{{ $doc['titulo'] }}</div>
        <div class="periodo">{{ $doc['periodo'] }}</div>
    </div>
</header>

<footer>
    <span>{{ $doc['negocio']['nombre'] }} · generado el {{ $doc['generado'] }}</span>
    <span class="der">Página <span class="pagina"></span></span>
</footer>

<main>
    <h2>Indicadores del período</h2>

    <table class="indicadores">
        @foreach ($doc['indicadores'] as $i => $ind)
            <tr class="{{ $i % 2 === 1 ? 'par' : '' }} {{ ! empty($ind['destacar']) ? 'destacado' : '' }}">
                <td class="ind-etiqueta">{{ $ind['etiqueta'] }}</td>
                <td class="ind-valor">{{ $fmt($ind['valor'], $ind['formato'] ?? null) }}</td>
                <td class="ind-nota">{{ $ind['nota'] ?? '' }}</td>
            </tr>
        @endforeach
    </table>

    @foreach ($doc['tablas'] as $tabla)
        <div class="bloque">
            <h2>{{ $tabla['nombre'] }}</h2>

            @if (! empty($tabla['nota']))
                <p class="nota">{{ $tabla['nota'] }}</p>
            @endif

            @if (count($tabla['filas']) === 0)
                <p class="vacia">{{ $tabla['vacia'] ?? 'Sin datos en el período.' }}</p>
            @else
                <table class="datos">
                    <thead>
                        <tr>
                            @foreach ($tabla['cabeceras'] as $i => $texto)
                                <th class="{{ ($tabla['alineacion'][$i] ?? 'izq') === 'der' ? 'der' : '' }}">{{ $texto }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($tabla['filas'] as $n => $fila)
                            <tr class="{{ $n % 2 === 1 ? 'par' : '' }}">
                                @foreach (array_values($fila) as $i => $celda)
                                    <td class="{{ ($tabla['alineacion'][$i] ?? 'izq') === 'der' ? 'der' : '' }}">
                                        {{ $fmt($celda, $tabla['formatos'][$i] ?? null) }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                    @if (! empty($tabla['totales']))
                        <tfoot>
                            <tr>
                                @foreach (array_values($tabla['totales']) as $i => $celda)
                                    <td class="{{ ($tabla['alineacion'][$i] ?? 'izq') === 'der' ? 'der' : '' }}">
                                        {{ is_string($celda) ? $celda : $fmt($celda, $tabla['formatos'][$i] ?? null) }}
                                    </td>
                                @endforeach
                            </tr>
                        </tfoot>
                    @endif
                </table>
            @endif
        </div>
    @endforeach
</main>

</body>
</html>

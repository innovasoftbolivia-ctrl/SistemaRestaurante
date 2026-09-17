@php
    use App\Support\Config;

    // Solo se llega aquí con la sesión ya cerrada: el controlador redirige
    // si todavía está abierta (ver el comentario en CajaController::imprimir).
    $moneda = Config::moneda();
    $cajero = $sesion->usuarioApertura?->empleado?->nombre_completo ?? $sesion->usuarioApertura?->usuario;
@endphp

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cierre de caja — {{ $sesion->caja?->nombre }} {{ $sesion->fecha_apertura?->format('d/m/Y') }}</title>

    <style>
        /* Documento imprimible: hoja en blanco, sin nada del panel. Mismas
           clases y el mismo criterio que comprobantes/imprimir.blade.php. */
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 16px;
            background: #f2f4f7;
            color: #101828;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 13px;
            line-height: 1.5;
        }

        .hoja {
            margin: 0 auto;
            background: #fff;
            padding: 40px;
            width: 210mm;
            max-width: 100%;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .12);
        }

        .centro { text-align: center; }
        .derecha { text-align: right; }
        .fuerte { font-weight: 700; }
        .tenue { color: #667085; }

        .regla {
            border: 0;
            border-top: 1px solid #d0d5dd;
            margin: 16px 0;
        }

        h1 { font-size: 20px; margin: 0 0 2px; }
        h2 { font-size: 14px; margin: 0 0 10px; text-transform: uppercase; letter-spacing: .04em; color: #475467; }

        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 5px 0; vertical-align: top; }
        thead th { border-bottom: 1px solid #d0d5dd; font-size: 11px; text-align: left; color: #667085; }

        .cifras {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin: 16px 0 24px;
        }

        .cifra {
            border: 1px solid #eaecf0;
            border-radius: 8px;
            padding: 10px 12px;
        }

        .cifra .etiqueta { font-size: 10px; text-transform: uppercase; letter-spacing: .04em; color: #667085; }
        .cifra .valor { font-size: 16px; font-weight: 700; margin-top: 2px; }

        .estado {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 600;
        }

        .estado.cuadra { background: #ecfdf3; color: #067647; }
        .estado.difiere { background: #fef3f2; color: #b42318; }

        .firmas {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-top: 56px;
        }

        .firma .linea {
            border-top: 1px solid #101828;
            margin-top: 48px;
            padding-top: 6px;
            font-size: 11px;
        }

        .firma .cargo { color: #667085; }

        .acciones {
            max-width: 210mm;
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
            .hoja { box-shadow: none; padding: 16mm; width: auto; }
            .acciones { display: none; }
            @page { size: A4; margin: 12mm; }
        }
    </style>
</head>

<body>
    <div class="acciones">
        <button type="button" class="principal" onclick="window.print()">Imprimir</button>
        <a href="{{ route('caja.show', $sesion) }}">Volver al turno</a>
    </div>

    <div class="hoja">
        <div class="centro">
            <h1>{{ $negocio['nombre'] }}</h1>
            @if ($negocio['documento'])
                <div class="tenue">{{ $negocio['documento'] }}</div>
            @endif
            @if ($negocio['direccion'])
                <div class="tenue">{{ $negocio['direccion'] }}</div>
            @endif
        </div>

        <hr class="regla">

        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 20px;">
            <div>
                <div class="fuerte" style="font-size: 16px;">Resumen de cierre de caja</div>
                <div class="tenue">{{ $sesion->caja?->nombre }}@if ($sesion->caja?->ubicacion) · {{ $sesion->caja->ubicacion }}@endif</div>
            </div>
            <span class="estado {{ (float) $sesion->diferencia === 0.0 ? 'cuadra' : 'difiere' }}">
                Turno cerrado
            </span>
        </div>

        <table style="margin-bottom: 20px;">
            <tr>
                <td class="tenue" style="width: 33%;">Cajero</td>
                <td class="fuerte">{{ $cajero }}</td>
            </tr>
            <tr>
                <td class="tenue">Apertura</td>
                <td>{{ $sesion->fecha_apertura?->format('d/m/Y H:i') }}</td>
            </tr>
            <tr>
                <td class="tenue">Cierre</td>
                <td>{{ $sesion->fecha_cierre?->format('d/m/Y H:i') }} · {{ $sesion->usuarioCierre?->usuario }}</td>
            </tr>
        </table>

        <div class="cifras">
            <div class="cifra">
                <div class="etiqueta">Monto inicial</div>
                <div class="valor">{{ Config::importe($sesion->monto_inicial) }}</div>
            </div>
            <div class="cifra">
                <div class="etiqueta">Vendido</div>
                <div class="valor">{{ Config::importe($resumen['vendido']) }}</div>
            </div>
            <div class="cifra">
                <div class="etiqueta">Ingresos / egresos</div>
                <div class="valor" style="font-size: 13px;">
                    {{ Config::importe($resumen['ingresos']) }} / {{ Config::importe($resumen['egresos']) }}
                </div>
            </div>
            <div class="cifra">
                <div class="etiqueta">Esperado al cerrar</div>
                <div class="valor">{{ Config::importe($resumen['esperado']) }}</div>
            </div>
        </div>

        @if ($qrSinVenta->isNotEmpty())
            <h2>QR pagados sin venta</h2>
            <p data-qr-sin-venta>
                {{ $qrSinVenta->count() }} cobro(s) por {{ Config::importe($qrSinVenta->sum('monto')) }} que el banco recibió y no
                terminaron en una venta: hay que devolverlos al cliente. No están en ninguna cifra de este resumen.
            </p>
        @endif

        @if ($qrAMano->isNotEmpty())
            <h2>QR confirmados a mano</h2>
            <p data-qr-a-mano>
                {{ $qrAMano->count() }} cobro(s) por {{ Config::importe($qrAMano->sum('monto')) }} dados por pagados sin la
                confirmación del banco: cotejarlos con el extracto de la cuenta.
            </p>
        @endif

        <h2>Ventas por método de pago</h2>
        <table style="margin-bottom: 20px;">
            <thead>
                <tr>
                    <th>Método</th>
                    <th class="derecha">Monto</th>
                    <th class="derecha">¿Pasa por el cajón?</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($porMetodo as $fila)
                    <tr>
                        <td>{{ $fila->metodo_pago }}</td>
                        <td class="derecha">{{ Config::importe($fila->monto) }}</td>
                        <td class="derecha tenue">{{ $fila->afecta_caja ? 'Sí' : 'No' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="tenue">Sin ventas en este turno.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <h2>Movimientos de caja</h2>
        <table style="margin-bottom: 20px;">
            <thead>
                <tr>
                    <th>Hora</th>
                    <th>Concepto</th>
                    <th>Quién</th>
                    <th class="derecha">Monto</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($sesion->movimientos->sortBy('fecha') as $movimiento)
                    <tr>
                        <td class="tenue">{{ $movimiento->fecha?->format('H:i') }}</td>
                        <td>{{ $movimiento->concepto }}</td>
                        <td class="tenue">{{ $movimiento->usuario?->usuario }}</td>
                        <td class="derecha">
                            {{ $movimiento->tipo === 'INGRESO' ? '+' : '−' }}{{ Config::importe($movimiento->monto) }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="tenue">Sin movimientos en este turno.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        {{-- La cuenta completa, término a término, para poder cuadrarla a mano
             con las tablas de arriba. --}}
        <h2>Arqueo</h2>
        <table style="margin-bottom: 12px;" data-cuenta-del-esperado>
            <tr>
                <td class="tenue" style="width: 50%;">Monto inicial</td>
                <td class="derecha">{{ Config::importe($desglose['inicial']) }}</td>
            </tr>
            <tr>
                <td class="tenue">+ Ventas en efectivo</td>
                <td class="derecha">{{ Config::importe($desglose['ventas']) }}</td>
            </tr>
            <tr>
                <td class="tenue">+ Ingresos de caja</td>
                <td class="derecha">{{ Config::importe($desglose['ingresos']) }}</td>
            </tr>
            <tr>
                <td class="tenue">− Egresos de caja</td>
                <td class="derecha">{{ Config::importe($desglose['egresos']) }}</td>
            </tr>
            <tr>
                <td class="tenue">− Devoluciones en efectivo</td>
                <td class="derecha">{{ Config::importe($desglose['devuelto']) }}</td>
            </tr>
            <tr>
                <td class="tenue">= Efectivo esperado</td>
                <td class="derecha fuerte">{{ Config::importe($resumen['esperado']) }}</td>
            </tr>
            <tr>
                <td class="tenue">Efectivo contado</td>
                <td class="derecha fuerte">{{ Config::importe($sesion->monto_declarado) }}</td>
            </tr>
            <tr>
                <td class="tenue">Diferencia</td>
                <td class="derecha fuerte">
                    {{ (float) $sesion->diferencia > 0 ? '+' : '' }}{{ Config::importe($sesion->diferencia) }}
                </td>
            </tr>
            @if ($sesion->fondo_dejado !== null)
                <tr>
                    <td class="tenue">Queda en el cajón para el siguiente turno</td>
                    <td class="derecha">{{ Config::importe($sesion->fondo_dejado) }}</td>
                </tr>
                <tr>
                    <td class="tenue">Se retira del cajón</td>
                    <td class="derecha fuerte">{{ Config::importe((float) $sesion->monto_declarado - (float) $sesion->fondo_dejado) }}</td>
                </tr>
            @endif
        </table>

        @if ($sesion->observacion)
            <p class="tenue"><span class="fuerte">Observación al abrir:</span> {{ $sesion->observacion }}</p>
        @endif
        @if ($sesion->observacion_cierre)
            <p class="tenue"><span class="fuerte">Observación al cerrar:</span> {{ $sesion->observacion_cierre }}</p>
        @endif

        <div class="firmas">
            <div class="firma">
                <div class="linea">
                    {{ $cajero }}
                    <div class="cargo">Cajero — declara conforme el efectivo contado</div>
                </div>
            </div>
            <div class="firma">
                <div class="linea">
                    <div class="cargo">Supervisor — reviso este cierre junto al cajero</div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>

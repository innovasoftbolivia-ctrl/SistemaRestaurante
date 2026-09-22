<!DOCTYPE html>
<html lang="es">
{{-- El visor de errores del desarrollador (ErroresController). Una página
     suelta, sin el menú del sistema: no hace falta haber iniciado sesión. --}}

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Errores · {{ \App\Support\Config::negocio() }}</title>
    <style>
        :root { --fondo: #f6f7f9; --caja: #fff; --borde: #e3e6ea; --texto: #1d2433; --tenue: #667085;
                --rojo: #d92d20; --ambar: #b54708; --azul: #175cd3; --codigo: #f2f4f7; }
        @media (prefers-color-scheme: dark) {
            :root { --fondo: #0c111d; --caja: #161b26; --borde: #2a3140; --texto: #e6e9ef; --tenue: #98a2b3;
                    --rojo: #f97066; --ambar: #fdb022; --azul: #84adff; --codigo: #0f1420; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--fondo); color: var(--texto); font: 14px/1.5 system-ui, sans-serif; }
        main { max-width: 1100px; margin: 0 auto; padding: 24px 16px; }
        h1 { font-size: 20px; margin: 0 0 4px; }
        .tenue { color: var(--tenue); }
        form { display: flex; flex-wrap: wrap; gap: 8px; margin: 16px 0; }
        select, button { font: inherit; padding: 6px 10px; border: 1px solid var(--borde); border-radius: 8px;
                         background: var(--caja); color: var(--texto); }
        .entrada { background: var(--caja); border: 1px solid var(--borde); border-radius: 12px; margin-bottom: 10px; }
        summary { list-style: none; cursor: pointer; padding: 12px 14px; display: flex; gap: 10px; align-items: baseline; }
        summary::-webkit-details-marker { display: none; }
        .nivel { font: 600 11px/1 ui-monospace, monospace; padding: 4px 6px; border-radius: 6px; flex: none; }
        .ERROR, .CRITICAL, .ALERT, .EMERGENCY { color: var(--rojo); border: 1px solid currentColor; }
        .WARNING, .NOTICE { color: var(--ambar); border: 1px solid currentColor; }
        .INFO, .DEBUG { color: var(--azul); border: 1px solid currentColor; }
        .mensaje { flex: 1; min-width: 0; overflow-wrap: anywhere; }
        .cuando { flex: none; text-align: right; font-size: 12px; }
        pre { margin: 0; padding: 12px 14px; background: var(--codigo); border-top: 1px solid var(--borde);
              border-radius: 0 0 12px 12px; overflow-x: auto; font: 12px/1.5 ui-monospace, monospace; white-space: pre; }
        .vacio { padding: 40px; text-align: center; background: var(--caja); border: 1px solid var(--borde); border-radius: 12px; }
    </style>
</head>

<body>
    <main>
        <h1>Registro de errores</h1>
        <p class="tenue">
            Solo para el desarrollador: no aparece en el menú y nadie más puede abrirla. Lo mismo que escribe Laravel
            en storage/logs, con los errores iguales juntos. Los archivos se guardan 14 días.
        </p>

        <form method="GET" action="{{ route('errores') }}">
            <select name="archivo" aria-label="Archivo" onchange="this.form.submit()">
                @forelse ($archivos as $a)
                    <option value="{{ $a['archivo'] }}" @selected($a['archivo'] === $archivo)>
                        {{ $a['archivo'] }} · {{ number_format($a['bytes'] / 1024, 0) }} KB · {{ $a['fecha'] }}
                    </option>
                @empty
                    <option value="">Sin archivos</option>
                @endforelse
            </select>
            <select name="nivel" aria-label="Nivel" onchange="this.form.submit()">
                <option value="">Todos los niveles</option>
                @foreach (\App\Support\RegistroDeErrores::NIVELES as $n)
                    <option value="{{ $n }}" @selected($n === $nivel)>{{ $n }}</option>
                @endforeach
            </select>
            <noscript><button type="submit">Ver</button></noscript>
        </form>

        @forelse ($entradas as $e)
            <details class="entrada" data-error>
                <summary>
                    <span class="nivel {{ $e['nivel'] }}">{{ $e['nivel'] }}</span>
                    <span class="mensaje">{{ \Illuminate\Support\Str::limit($e['mensaje'], 300) }}</span>
                    <span class="cuando tenue">
                        {{ $e['ultima'] }}
                        @if ($e['veces'] > 1)
                            <br><b>{{ $e['veces'] }} veces</b> desde {{ $e['primera'] }}
                        @endif
                    </span>
                </summary>
                <pre>{{ $e['detalle'] }}</pre>
            </details>
        @empty
            <div class="vacio tenue">Nada registrado{{ $nivel ? " con nivel {$nivel}" : '' }} en este archivo.</div>
        @endforelse
    </main>
</body>

</html>

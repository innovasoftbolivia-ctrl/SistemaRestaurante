@props([
    'producto' => null,
    'url' => null,
    'nombre' => null,
    'size' => 'md',
])

@php
    // Sirve tanto con un modelo como con datos sueltos (el mostrador arma sus
    // tarjetas desde JSON, no desde Eloquent).
    $imagen = $url ?? $producto?->imagen_url;
    $titulo = $nombre ?? $producto?->nombre ?? '';

    // Sin foto: las iniciales del plato sobre el color de su categoría, el
    // mismo del mostrador. Se reconoce de un vistazo y no parece que falte algo.
    // Sin las palabras de enlace: «Empanada de queso» es EQ, no ED.
    $enlaces = ['de', 'del', 'la', 'las', 'el', 'los', 'a', 'al', 'y', 'e', 'con', 'en', 'para', 'sin'];
    $palabras = array_values(array_filter(
        preg_split('/\s+/', trim($titulo)) ?: [],
        fn (string $p) => ! in_array(mb_strtolower($p), $enlaces, true),
    ));
    $iniciales = mb_strtoupper(mb_substr($palabras[0] ?? $titulo ?: '?', 0, 1).mb_substr($palabras[1] ?? '', 0, 1));
    $tamanoLetra = ['sm' => 'text-sm', 'md' => 'text-lg', 'lg' => 'text-3xl', 'xl' => 'text-5xl'][$size] ?? 'text-lg';

    // El recuadro tiene medida fija para que la cuadrícula no se descuadre;
    // la foto se acomoda dentro, sin importar su proporción.
    $sizeMap = [
        'sm' => ['caja' => 'h-11 w-11 rounded-lg', 'aire' => 'p-0.5', 'icono' => 'h-5 w-5'],
        'md' => ['caja' => 'h-16 w-16 rounded-xl', 'aire' => 'p-1', 'icono' => 'h-7 w-7'],
        'lg' => ['caja' => 'h-28 w-28 rounded-2xl', 'aire' => 'p-2', 'icono' => 'h-10 w-10'],
        'xl' => ['caja' => 'aspect-square w-full rounded-2xl', 'aire' => 'p-3', 'icono' => 'h-12 w-12'],
    ];

    $medida = $sizeMap[$size] ?? $sizeMap['md'];

    $marco = 'flex flex-none items-center justify-center overflow-hidden border '
        .$medida['caja'].' '.$medida['aire'];
@endphp

@if ($imagen)
    {{--
        `object-scale-down` y no `object-cover`: la foto entra entera, sin
        recortes ni deformación, sea cuadrada, vertical o apaisada, y una
        imagen diminuta no se agranda hasta verse pixelada.

        Detrás, la misma foto difuminada llena el recuadro: una foto apaisada
        o chica ya no queda como una estampilla sobre un bloque blanco.
    --}}
    <span
        {{ $attributes->merge([
            'class' => str_replace($medida['aire'], '', $marco).' relative border-gray-200 bg-gray-100 dark:border-gray-700 dark:bg-white/[0.06]',
        ]) }}>
        <img src="{{ $imagen }}" alt="" aria-hidden="true" loading="lazy"
            class="absolute inset-0 h-full w-full scale-125 object-cover opacity-60 blur-md" data-foto-fondo />
        <img src="{{ $imagen }}" alt="{{ $titulo }}" loading="lazy"
            class="relative max-h-full max-w-full object-scale-down" />
    </span>
@else
    {{-- Marcador: la misma caja, para que la fila no se descuadre sin foto. --}}
    <span
        {{ $attributes->merge([
            'class' => $marco.' border-transparent font-semibold text-gray-800 ring-1 ring-inset dark:text-white/90 '
                .$tamanoLetra.' '.\App\Support\ColorCategoria::suave($producto?->categoria_id),
        ]) }}
        title="{{ $titulo ? $titulo.' — sin foto' : 'Sin foto' }}" data-sin-foto>
        <span aria-hidden="true">{{ $iniciales }}</span>
    </span>
@endif

@php
    use App\Support\Config;

    // Con una sola caja libre no hay nada que elegir: viene elegida, con el
    // monto que dejó su último turno.
    $cajaPorOmision = old('caja_id', $cajasLibres->count() === 1 ? $cajasLibres->first()->id : null);
    $fondoPorOmision = $cajaPorOmision !== null ? ($fondos[$cajaPorOmision] ?? null) : null;
@endphp

{{-- El formulario para abrir un turno de caja. Lo usan la pantalla de Caja
     (en su ventana) y el punto de venta (cuando el cajero llega sin caja
     abierta: la abre ahí mismo y vuelve a vender, sin pasar por Caja).
     $cajasLibres: las cajas sin turno abierto. $fondos: lo que dejó en el
     cajón el último turno de cada una. $volver: 'pos' para volver al
     mostrador al abrir. --}}
{{-- Al elegir la caja se propone lo que dejó en el cajón su último
     turno. Se puede cambiar, pero entonces hay que explicarlo. --}}
<form method="POST" action="{{ route('caja.abrir') }}" class="space-y-5"
    x-data="{ fondos: @js($fondos), fondo: null }"
    x-init="fondo = fondos[{{ (int) $cajaPorOmision }}] ?? null">
    @csrf
    @if ($volver)
        <input type="hidden" name="volver" value="{{ $volver }}" />
    @endif

    <x-form.campo label="Caja" for="caja_id" name="caja_id" required>
        <x-form.select id="caja_id" name="caja_id" :value="$cajaPorOmision"
            placeholder="Selecciona una caja"
            x-on:change="fondo = fondos[$event.target.value] ?? null; if (fondo !== null) $refs.monto.value = Number(fondo).toFixed(2)"
            :opciones="$cajasLibres->pluck('nombre', 'id')" required />
    </x-form.campo>

    <x-form.campo label="Monto inicial" for="monto_inicial" name="monto_inicial" required
        help="El efectivo que hay en el cajón al empezar.">
        <x-form.input id="monto_inicial" name="monto_inicial" type="number" step="0.01" min="0"
            x-ref="monto" :value="old('monto_inicial', $fondoPorOmision !== null ? number_format($fondoPorOmision, 2, '.', '') : '0.00')" required autofocus />
        <p x-show="fondo !== null" x-cloak class="mt-1.5 text-theme-xs text-gray-500 dark:text-gray-400" data-fondo-anterior>
            El último turno de esta caja dejó
            <b class="text-gray-800 dark:text-white/90" x-text="'{{ Config::moneda() }} ' + Number(fondo).toFixed(2)"></b>
            en el cajón. Si empiezas con otro monto, explica por qué en la observación.
        </p>
    </x-form.campo>

    <x-form.campo label="Observación" for="observacion" name="observacion">
        <x-form.input id="observacion" name="observacion" :value="old('observacion')"
            placeholder="Opcional" />
    </x-form.campo>

    <div class="flex justify-end gap-3">
        @unless ($volver)
            <x-ui.button type="button" variant="outline" size="sm" @click="abriendo = false">Cancelar</x-ui.button>
        @endunless
        <x-ui.button type="submit" size="sm" :class="$volver ? 'w-full' : ''">Abrir caja</x-ui.button>
    </div>
</form>

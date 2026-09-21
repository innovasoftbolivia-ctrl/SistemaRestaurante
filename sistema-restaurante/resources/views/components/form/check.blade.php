@props([
    'name' => null,
    'checked' => false,
    'label' => null,
    // Expresión Alpine del componente padre. Si se pasa, la casilla comparte
    // ese estado en vez de llevar el suyo (útil cuando el formulario reacciona
    // al valor, como el precio de estante según el impuesto).
    'model' => null,
])

@php
    $propio = $model === null;
    $estado = $model ?? 'marcado';
@endphp

{{-- El input real va oculto y Alpine pinta la casilla, como en la plantilla. --}}
<label @if ($propio) x-data="{ marcado: @js((bool) old($name, $checked)) }" @endif
    class="flex cursor-pointer items-center text-sm font-normal text-gray-700 select-none dark:text-gray-400">
    <span class="relative">
        <input type="hidden" name="{{ $name }}" :value="{{ $estado }} ? '1' : '0'" />
        <input type="checkbox" class="sr-only" :checked="{{ $estado }}"
            @change="{{ $estado }} = !{{ $estado }}" {{ $attributes }} />
        <span :class="{{ $estado }} ? 'border-brand-500 bg-brand-500' : 'bg-transparent border-gray-300 dark:border-gray-700'"
            class="mr-3 flex h-5 w-5 items-center justify-center rounded-md border-[1.25px]">
            <span :class="{{ $estado }} ? '' : 'opacity-0'">
                <svg aria-hidden="true" width="14" height="14" viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M11.6666 3.5L5.24992 9.91667L2.33325 7" stroke="white" stroke-width="1.94437"
                        stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
        </span>
    </span>
    {{ $label ?? $slot }}
</label>

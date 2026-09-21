@props(['size' => 'md'])

{{-- El ícono del negocio: cubiertos sobre el azul de la marca. El mismo del
     inicio de sesión, en el menú lateral y en la cabecera del celular. --}}
<span aria-hidden="true"
    {{ $attributes->merge(['class' => 'flex flex-none items-center justify-center bg-brand-500 text-white '.($size === 'sm' ? 'h-8 w-8 rounded-lg' : 'h-10 w-10 rounded-xl')]) }}>
    <svg width="{{ $size === 'sm' ? 18 : 22 }}" height="{{ $size === 'sm' ? 18 : 22 }}" viewBox="0 0 24 24" fill="none"
        stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
        <path d="M19 3v12h-5c-.023-3.681.184-7.406 5-12zm0 12v6h-1v-3M8 4v17M5 4v3a3 3 0 1 0 6 0V4" />
    </svg>
</span>
